<?php

declare(strict_types=1);

namespace App\Demo;

use App\Documentation\InvalidVehicleDocumentException;
use App\Documentation\VehicleDocumentKind;
use App\Documentation\VehicleDocumentRecorder;
use App\Documentation\VehicleDocumentSource;
use App\Entity\MaintenanceRecord;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleDocument;
use App\Security\OwnerContext;
use App\Storage\VehicleDocumentStorage;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\Document;

/**
 * Replaces one owner's garage with a fixture written by {@see DemoGarageExporter}. Garage documents go
 * back in their stored shape; vehicle documents go through {@see VehicleDocumentRecorder}, the write
 * path uploads use, so their pages are re-extracted and the binaries land in the configured storage.
 */
final readonly class DemoGarageLoader
{
    private const array COLLECTIONS = [
        'vehicles' => Vehicle::class,
        'maintenanceRecords' => MaintenanceRecord::class,
        'plannedMaintenance' => PlannedMaintenance::class,
    ];

    public function __construct(
        private DocumentManager $dm,
        private VehicleDocumentRecorder $recorder,
        private VehicleDocumentStorage $storage,
        private OwnerContext $owner,
    ) {
    }

    /**
     * @return array{vehicles: int, maintenanceRecords: int, plannedMaintenance: int, documents: int}
     */
    public function load(string $path, string $owner): array
    {
        $fixture = self::decode($path);
        $this->purge($owner);

        $counts = [];
        $inserted = [];
        foreach (self::COLLECTIONS as $list => $class) {
            $documents = self::documents($fixture, $list);
            foreach ($documents as $document) {
                $document->owner = $owner;
                $inserted[$class][] = $document;
            }
            if ([] !== $documents) {
                $this->dm->getDocumentCollection($class)->insertMany(array_map(self::bson(...), $documents));
            }
            $counts[$list] = \count($documents);
        }

        $this->dm->clear();
        $this->owner->set($owner);
        $renamed = [];
        foreach (self::documents($fixture, 'documents') as $entry) {
            $renamed[self::id($entry, 'id')] = $this->record($entry, \dirname($path));
        }
        $counts['documents'] = \count($renamed);

        $renamed = array_filter($renamed, static fn (string $new, string $old): bool => $new !== $old, \ARRAY_FILTER_USE_BOTH);
        foreach ($inserted as $class => $documents) {
            foreach ($documents as $document) {
                $json = json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
                $rewritten = strtr($json, $renamed);
                if ($rewritten !== $json) {
                    $this->dm->getDocumentCollection($class)->replaceOne(['_id' => self::id($document)], Document::fromJSON($rewritten));
                }
            }
        }

        return $counts;
    }

    public static function decode(string $path): \stdClass
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if (false === $json) {
            throw new DemoFixtureException(\sprintf('Cannot read "%s".', $path));
        }

        try {
            $fixture = json_decode($json, false, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DemoFixtureException(\sprintf('"%s" is not valid JSON: %s', $path, $e->getMessage()), previous: $e);
        }
        if (!$fixture instanceof \stdClass) {
            throw new DemoFixtureException(\sprintf('"%s" must hold a JSON object.', $path));
        }

        return $fixture;
    }

    /**
     * @return list<\stdClass>
     */
    public static function documents(\stdClass $fixture, string $list): array
    {
        $documents = get_object_vars($fixture)[$list] ?? [];
        if (!\is_array($documents)) {
            throw new DemoFixtureException(\sprintf('"%s" must be a list.', $list));
        }

        return array_values(array_map(static fn (mixed $document): \stdClass => $document instanceof \stdClass ? $document : throw new DemoFixtureException(\sprintf('"%s" must hold objects.', $list)), $documents));
    }

    public static function id(\stdClass $document, string $key = '_id'): string
    {
        $id = get_object_vars($document)[$key] ?? null;

        return \is_string($id) && '' !== $id ? $id : throw new DemoFixtureException(\sprintf('A fixture document has no string "%s".', $key));
    }

    private function purge(string $owner): void
    {
        $documents = $this->dm->getDocumentCollection(VehicleDocument::class);
        foreach ($documents->find(['owner' => $owner], ['projection' => ['storageKey' => 1], 'typeMap' => ['root' => 'array']]) as $document) {
            $key = \is_array($document) ? ($document['storageKey'] ?? null) : null;
            if (\is_string($key)) {
                $this->storage->delete($key);
            }
        }
        $documents->deleteMany(['owner' => $owner]);

        foreach (self::COLLECTIONS as $class) {
            $this->dm->getDocumentCollection($class)->deleteMany(['owner' => $owner]);
        }
    }

    private function record(\stdClass $entry, string $baseDir): string
    {
        $vehicle = $this->dm->find(Vehicle::class, self::id($entry, 'vehicle'));
        if (!$vehicle instanceof Vehicle) {
            throw new DemoFixtureException(\sprintf('Document %s belongs to no loaded vehicle.', self::id($entry, 'id')));
        }

        $kind = VehicleDocumentKind::tryFrom(self::string($entry, 'kind') ?? '');
        $source = VehicleDocumentSource::tryFrom(self::string($entry, 'source') ?? '');
        if (null === $kind || null === $source) {
            throw new DemoFixtureException(\sprintf('Document %s has an unknown kind or source.', self::id($entry, 'id')));
        }

        $file = $baseDir.'/'.self::id($entry, 'file');
        if (!is_file($file)) {
            throw new DemoFixtureException(\sprintf('Document %s: "%s" is missing.', self::id($entry, 'id'), $file));
        }
        try {
            return $this->recorder->record(
                $vehicle,
                $file,
                self::string($entry, 'filename') ?? basename($file),
                $kind,
                $source,
                self::string($entry, 'title'),
                self::string($entry, 'sourceRef'),
                self::string($entry, 'plannedTaskId'),
            )->getId();
        } catch (InvalidVehicleDocumentException $e) {
            throw new DemoFixtureException(\sprintf('Document %s: %s', self::id($entry, 'id'), $e->getMessage()), previous: $e);
        }
    }

    private static function string(\stdClass $entry, string $key): ?string
    {
        $value = get_object_vars($entry)[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    private static function bson(\stdClass $document): Document
    {
        return Document::fromJSON(json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
    }
}
