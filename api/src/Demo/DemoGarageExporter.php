<?php

declare(strict_types=1);

namespace App\Demo;

use App\Documentation\InvalidVehicleDocumentException;
use App\Documentation\PdfTextExtractor;
use App\Entity\MaintenanceRecord;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleDocument;
use App\Storage\VehicleDocumentStorage;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\Document;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Raw documents, not entities: the fixture is the stored shape, so the loader can insert it back
 * as-is and a re-export of an unchanged garage is byte-identical (keys sorted, lists by id).
 */
final readonly class DemoGarageExporter
{
    public const int DEFAULT_MAX_DOCUMENT_BYTES = 50_000_000;

    private const string BILL = '/factur|invoice|\bbill\b|devis|receipt/i';
    private const string DOCUMENT_URL = '#/documents/([0-9A-Za-z]{26})(?:/|$)#';
    private const array EXTENSIONS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(
        private DocumentManager $dm,
        private VehicleDocumentStorage $storage,
        private PdfTextExtractor $extractor,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param list<string> $vehicleIds
     * @param list<string> $skipDocumentIds
     *
     * @return array{vehicles: int, maintenanceRecords: int, plannedMaintenance: int, documents: int, documentBytes: int}
     */
    public function export(string $owner, array $vehicleIds, RealValueMapping $mapping, ?string $extrasPath, string $outPath, int $maxDocumentBytes = self::DEFAULT_MAX_DOCUMENT_BYTES, array $skipDocumentIds = []): array
    {
        if ([] === $vehicleIds) {
            throw new DemoFixtureException('Select at least one vehicle.');
        }

        $vehicles = $this->find(Vehicle::class, ['_id' => ['$in' => $vehicleIds], 'owner' => $owner]);
        $forbidden = ['the owner email' => '/'.preg_quote($owner, '/').'/i'];
        foreach ($vehicleIds as $id) {
            $vehicle = $vehicles[$id] ?? null;
            if (null === $vehicle || null !== ($vehicle->deletedAt ?? null)) {
                throw new DemoFixtureException(\sprintf('Vehicle %s is not a live vehicle of %s.', $id, $owner));
            }
            if (\is_string($vehicle->plate ?? null) && '' !== trim($vehicle->plate)) {
                $forbidden['the plate of vehicle '.$id] = RealValueMapping::platePattern($vehicle->plate);
            }
            if (\is_string($vehicle->vin ?? null) && '' !== trim($vehicle->vin)) {
                $forbidden['the vin of vehicle '.$id] = '/'.preg_quote(trim($vehicle->vin), '/').'/i';
            }
        }

        $scope = ['vehicle' => ['$in' => $vehicleIds], 'owner' => $owner];
        $records = $this->find(MaintenanceRecord::class, $scope);
        foreach ($records as $record) {
            if (property_exists($record, 'attachments')) {
                $record->attachments = [];
            }
        }
        $tasks = $this->find(PlannedMaintenance::class, $scope);
        $documents = $this->selectDocuments($this->find(VehicleDocument::class, $scope, ['pages' => 0]), $skipDocumentIds, $maxDocumentBytes);

        $fixture = [
            'vehicles' => array_values($vehicles),
            'maintenanceRecords' => array_values($records),
            'plannedMaintenance' => array_values($tasks),
        ];
        foreach ($fixture as $list) {
            foreach ($list as $document) {
                unset($document->owner);
                self::nullForeignDocumentUrls($document, $documents);
            }
        }
        $fixture = $this->mergeExtras($fixture, $extrasPath);
        $fixture['documents'] = array_values(array_map(self::documentEntry(...), $documents));

        $json = $mapping->apply(self::encode($fixture));
        $this->assertNoLeak($json, 'the fixture', $mapping, $forbidden);

        $staging = $this->filesystem->tempnam(sys_get_temp_dir(), 'demo-documents-');
        $this->filesystem->remove($staging);
        $this->filesystem->mkdir($staging);
        try {
            $bytes = 0;
            foreach ($documents as $id => $source) {
                $staged = $staging.'/'.basename(self::file($source));
                $this->filesystem->dumpFile($staged, $this->storage->readStream(\is_string($source->storageKey ?? null) ? $source->storageKey : ''));
                $bytes += (int) filesize($staged);
                if ('application/pdf' === ($source->contentType ?? null)) {
                    $this->assertNoLeak($this->text($staged), \sprintf('document %s', $id), $mapping, $forbidden);
                }
            }

            $documentsDir = \dirname($outPath).'/documents';
            $this->filesystem->remove($documentsDir);
            $this->filesystem->mirror($staging, $documentsDir);
            $this->filesystem->dumpFile($outPath, $json);
        } finally {
            $this->filesystem->remove($staging);
        }

        return [
            'vehicles' => \count($fixture['vehicles']),
            'maintenanceRecords' => \count($fixture['maintenanceRecords']),
            'plannedMaintenance' => \count($fixture['plannedMaintenance']),
            'documents' => \count($fixture['documents']),
            'documentBytes' => $bytes,
        ];
    }

    /**
     * @param array<string, mixed>|object $fixture
     */
    public static function encode(array|object $fixture): string
    {
        return json_encode(self::sorted($fixture), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $filter
     * @param array<string, int>   $projection
     *
     * @return array<string, \stdClass> by _id, sorted
     */
    private function find(string $class, array $filter, array $projection = []): array
    {
        $cursor = $this->dm->getDocumentCollection($class)->find($filter, [
            'projection' => $projection,
            'typeMap' => ['root' => 'bson', 'document' => 'bson', 'array' => 'bson'],
        ]);

        $documents = [];
        foreach ($cursor as $raw) {
            if (!$raw instanceof Document) {
                continue;
            }
            $document = json_decode($raw->toRelaxedExtendedJSON(), false, flags: \JSON_THROW_ON_ERROR);
            if ($document instanceof \stdClass && \is_string($document->_id ?? null)) {
                $documents[$document->_id] = $document;
            }
        }
        ksort($documents, \SORT_STRING);

        return $documents;
    }

    /**
     * @param array<string, \stdClass> $documents
     * @param list<string>             $skip
     *
     * @return array<string, \stdClass>
     */
    private function selectDocuments(array $documents, array $skip, int $maxBytes): array
    {
        $selected = array_filter(
            $documents,
            static fn (\stdClass $document, string $id): bool => 'quote' !== ($document->kind ?? null)
                && !\in_array($id, $skip, true)
                && 0 === preg_match(self::BILL, \sprintf('%s %s', \is_string($document->title ?? null) ? $document->title : '', \is_string($document->filename ?? null) ? $document->filename : ''))
                && \array_key_exists(\is_string($document->contentType ?? null) ? $document->contentType : '', self::EXTENSIONS),
            \ARRAY_FILTER_USE_BOTH,
        );

        $total = array_sum(array_map(static fn (\stdClass $document): int => \is_int($document->size ?? null) ? $document->size : 0, $selected));
        if ($total > $maxBytes) {
            $lines = array_map(
                static fn (\stdClass $document, string $id): string => \sprintf('  %s  %12d  %s', $id, \is_int($document->size ?? null) ? $document->size : 0, \is_string($document->title ?? null) ? $document->title : ''),
                $selected,
                array_keys($selected),
            );

            throw new DemoFixtureException(\sprintf("The documents weigh %d bytes, over the %d-byte cap. Skip some with --skip-document=<id>:\n%s", $total, $maxBytes, implode("\n", $lines)));
        }

        return $selected;
    }

    /**
     * @param array<string, \stdClass> $exported
     */
    private static function nullForeignDocumentUrls(\stdClass $node, array $exported): void
    {
        foreach (get_object_vars($node) as $key => $value) {
            if ('url' === $key && \is_string($value) && self::isForeignDocumentUrl($value, $exported)) {
                $node->url = null;
            } elseif ($value instanceof \stdClass) {
                self::nullForeignDocumentUrls($value, $exported);
            } elseif (\is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof \stdClass) {
                        self::nullForeignDocumentUrls($item, $exported);
                    }
                }
            }
        }
    }

    /**
     * @param array<string, \stdClass> $exported
     */
    private static function isForeignDocumentUrl(string $url, array $exported): bool
    {
        $match = [];

        return 1 === preg_match(self::DOCUMENT_URL, $url, $match) && !isset($exported[$match[1]]);
    }

    /**
     * @param array{vehicles: list<\stdClass>, maintenanceRecords: list<\stdClass>, plannedMaintenance: list<\stdClass>} $fixture
     *
     * @return array{vehicles: list<\stdClass>, maintenanceRecords: list<\stdClass>, plannedMaintenance: list<\stdClass>}
     */
    private function mergeExtras(array $fixture, ?string $extrasPath): array
    {
        if (null !== $extrasPath) {
            $extras = DemoGarageLoader::decode($extrasPath);
            foreach (array_keys($fixture) as $list) {
                foreach (DemoGarageLoader::documents($extras, $list) as $document) {
                    $fixture[$list][] = $document;
                }
            }
        }

        foreach ($fixture as $list => $documents) {
            usort($documents, static fn (\stdClass $a, \stdClass $b): int => strcmp(DemoGarageLoader::id($a), DemoGarageLoader::id($b)));
            $ids = array_map(DemoGarageLoader::id(...), $documents);
            if (\count($ids) !== \count(array_unique($ids))) {
                throw new DemoFixtureException(\sprintf('Duplicate _id in %s (the extras file repeats an exported document).', $list));
            }
            $fixture[$list] = $documents;
        }

        return $fixture;
    }

    private static function documentEntry(\stdClass $document): \stdClass
    {
        return (object) [
            'id' => DemoGarageLoader::id($document),
            'vehicle' => $document->vehicle ?? null,
            'file' => self::file($document),
            'filename' => $document->filename ?? null,
            'kind' => $document->kind ?? null,
            'source' => $document->source ?? null,
            'sourceRef' => $document->sourceRef ?? null,
            'title' => $document->title ?? null,
            'plannedTaskId' => $document->plannedTaskId ?? null,
        ];
    }

    private static function file(\stdClass $document): string
    {
        $contentType = \is_string($document->contentType ?? null) ? $document->contentType : '';

        return \sprintf('documents/%s.%s', DemoGarageLoader::id($document), self::EXTENSIONS[$contentType] ?? 'bin');
    }

    /**
     * @param array<string, non-empty-string> $forbidden
     */
    private function assertNoLeak(string $text, string $where, RealValueMapping $mapping, array $forbidden): void
    {
        $leaks = array_map(static fn (int $line): string => 'mapping line '.$line, $mapping->leaks($text));
        foreach ($forbidden as $label => $pattern) {
            if (1 === preg_match($pattern, $text)) {
                $leaks[] = $label;
            }
        }

        if ([] !== $leaks) {
            throw new DemoFixtureException(\sprintf('Refusing to write: %s still holds real values (%s). Extend the mapping file.', $where, implode(', ', $leaks)));
        }
    }

    private function text(string $path): string
    {
        try {
            return implode("\f", array_column($this->extractor->extract($path), 'text'));
        } catch (InvalidVehicleDocumentException $e) {
            throw new DemoFixtureException(\sprintf('Cannot check %s for real values: %s', basename($path), $e->getMessage()), previous: $e);
        }
    }

    private static function sorted(mixed $value): mixed
    {
        if (\is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, \SORT_STRING);
            }

            return array_map(self::sorted(...), $value);
        }
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, \SORT_STRING);

            return (object) array_map(self::sorted(...), $properties);
        }

        return $value;
    }
}
