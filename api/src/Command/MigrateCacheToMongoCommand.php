<?php

declare(strict_types=1);

namespace App\Command;

use App\Agent\Finder\TurnImage;
use App\Agent\Finder\TurnImageStash;
use App\Catalog\ArticlePhoto;
use App\Catalog\MongoArticlePhotoStore;
use App\Motul\MongoMotulResponseStore;
use App\PartsApi\MongoPartsApiResponseStore;
use App\Persistence\BsonPayload;
use App\Siv\MongoSivLookupStore;
use Doctrine\DBAL\Connection;
use MongoDB\BSON\Document;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Moves the retired PostgreSQL cache pools (cache_items) into their MongoDB collections, ids unchanged so
 * every paid response stays a hit. All-or-nothing: every row is mapped and checked first, and a single row
 * it cannot map, or a namespace it does not recognise, aborts before anything is written. The table is
 * only read, never truncated.
 */
#[AsCommand(
    name: 'app:cache:migrate-to-mongo',
    description: 'Migrate the PostgreSQL cache_items pools (siv, partsapi, motul, article photos, turn images) into MongoDB.',
)]
final class MigrateCacheToMongoCommand extends Command
{
    private const array POOLS = ['cache.siv', 'cache.partsapi', 'cache.motul', 'cache.article_photos', 'cache.turn_images'];
    private const array TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection $db,
        private readonly LegacyCacheNamespaces $namespaces,
        private readonly MongoSivLookupStore $siv,
        private readonly MongoPartsApiResponseStore $partsApi,
        private readonly MongoMotulResponseStore $motul,
        private readonly MongoArticlePhotoStore $photos,
        private readonly TurnImageStash $turnImages,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Map and check every row, write nothing')
            ->addOption('ignore-namespace', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'An item_id namespace to leave behind on purpose (e.g. one written under another environment)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $ignore = array_flip((array) $input->getOption('ignore-namespace'));

        $poolOf = [];
        $stats = [];
        foreach (self::POOLS as $pool) {
            $poolOf[$this->namespaces->of($pool)] = $pool;
            $stats[$pool] = ['read' => 0, 'write' => 0, 'skipped' => []];
        }

        $marshaller = new DefaultMarshaller();
        $now = $this->clock->now();
        $writes = [];
        $errors = [];
        $unknown = [];
        $ignored = [];

        foreach ($this->db->iterateAssociative('SELECT item_id, item_data, item_lifetime, item_time FROM cache_items ORDER BY item_id') as $row) {
            [$namespace, $key] = str_contains($row['item_id'], ':') ? explode(':', $row['item_id'], 2) : ['', $row['item_id']];
            $pool = $poolOf[$namespace] ?? null;
            if (null === $pool) {
                isset($ignore[$namespace]) ? $ignored[$namespace] = ($ignored[$namespace] ?? 0) + 1 : $unknown[$namespace] = ($unknown[$namespace] ?? 0) + 1;
                continue;
            }

            ++$stats[$pool]['read'];
            $fetchedAt = new \DateTimeImmutable('@'.$row['item_time']);
            $expiresAt = null === $row['item_lifetime'] ? null : $fetchedAt->modify(sprintf('+%d seconds', $row['item_lifetime']));

            try {
                $data = \is_resource($row['item_data']) ? stream_get_contents($row['item_data']) : $row['item_data'];
                $value = $marshaller->unmarshall($data);
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s: cannot unserialize (%s)', $row['item_id'], $e->getMessage());
                continue;
            }

            if (null !== $expiresAt && $expiresAt <= $now) {
                $stats[$pool]['skipped']['expired'] = ($stats[$pool]['skipped']['expired'] ?? 0) + 1;
                continue;
            }

            $mapped = $this->map($pool, $key, $value, $fetchedAt, $expiresAt);
            if (\is_string($mapped)) {
                $errors[] = sprintf('%s (%s): %s', $row['item_id'], $pool, $mapped);
            } elseif (null === $mapped) {
                $stats[$pool]['skipped']['dead call counter'] = ($stats[$pool]['skipped']['dead call counter'] ?? 0) + 1;
            } else {
                $writes[] = $mapped;
                ++$stats[$pool]['write'];
            }
        }

        $io->table(
            ['pool', 'namespace', 'read', $dryRun ? 'would write' : 'written', 'skipped (reason: n)'],
            array_map(static fn (string $pool): array => [
                $pool,
                array_search($pool, $poolOf, true),
                $stats[$pool]['read'],
                $stats[$pool]['write'],
                implode(', ', array_map(static fn (string $reason, int $n): string => $reason.': '.$n, array_keys($stats[$pool]['skipped']), $stats[$pool]['skipped'])),
            ], self::POOLS),
        );

        foreach ($ignored as $namespace => $n) {
            $io->note(sprintf('Left behind %d row(s) under namespace "%s" (--ignore-namespace).', $n, $namespace));
        }

        if ([] !== $unknown) {
            foreach ($unknown as $namespace => $n) {
                $errors[] = sprintf('%d row(s) under unrecognised namespace "%s" — not one of this environment\'s pools; pass --ignore-namespace=%s to leave them behind', $n, $namespace, $namespace);
            }
        }

        if ([] !== $errors) {
            $io->error(array_merge(['Nothing was written. Unmapped rows:'], $errors));

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->success(sprintf('Dry run: %d document(s) would be written.', \count($writes)));

            return Command::SUCCESS;
        }

        foreach ($writes as $write) {
            $write();
        }

        $io->success(sprintf('Wrote %d document(s).', \count($writes)));

        return Command::SUCCESS;
    }

    /**
     * @return \Closure():void|string|null the write, the reason it cannot be mapped, or null for a dead counter
     */
    private function map(string $pool, string $key, mixed $value, \DateTimeImmutable $fetchedAt, ?\DateTimeImmutable $expiresAt): \Closure|string|null
    {
        if (null !== $expiresAt && !\in_array($pool, ['cache.partsapi', 'cache.turn_images'], true)) {
            return 'carries an expiry its collection does not model';
        }

        switch ($pool) {
            case 'cache.siv':
                if (!\is_array($value)) {
                    return sprintf('expected an envelope array, got %s', get_debug_type($value));
                }

                return $this->roundTrips($value) ?? fn () => $this->siv->write($key, $value, $fetchedAt);

            case 'cache.partsapi':
                // The ceiling these counted was dropped in fff1cf9; nothing reads them any more.
                if (preg_match('/^calls\.\w+$/', $key) && \is_int($value)) {
                    return null;
                }
                if (!preg_match('/^(\w+)\.[0-9a-f]{40}$/', $key, $m)) {
                    return 'key is neither <method>.<sha1> nor calls.<method>';
                }
                if (!\is_array($value)) {
                    return sprintf('expected a decoded response array, got %s', get_debug_type($value));
                }

                return $this->roundTrips($value) ?? fn () => $this->partsApi->write($key, $m[1], null, $value, $fetchedAt, $expiresAt);

            case 'cache.motul':
                if (!preg_match('/^(search|recs)\.[0-9a-f]{40}$/', $key)) {
                    return 'key is neither search.<sha1> nor recs.<sha1>';
                }
                if (!\is_array($value)) {
                    return sprintf('expected an array, got %s', get_debug_type($value));
                }

                return $this->roundTrips($value) ?? fn () => $this->motul->write($key, $value, $fetchedAt);

            case 'cache.article_photos':
                if (!preg_match('/^photo\.[0-9a-f]{40}$/', $key)) {
                    return 'key is not photo.<sha1>';
                }
                if (!$value instanceof ArticlePhoto) {
                    return sprintf('expected an %s, got %s', ArticlePhoto::class, get_debug_type($value));
                }

                return fn () => $this->photos->write($key, null, $value, $fetchedAt);

            case 'cache.turn_images':
                if (null === $expiresAt) {
                    return 'a stashed batch must expire';
                }
                if (!\is_array($value) || !array_is_list($value)) {
                    return 'expected a list of images';
                }
                $images = [];
                foreach ($value as $image) {
                    $bytes = \is_array($image) && \is_string($image['mimeType'] ?? null) && \is_string($image['bytes'] ?? null) ? base64_decode($image['bytes'], true) : false;
                    if (false === $bytes) {
                        return 'an image is not {mimeType, base64 bytes}';
                    }
                    $images[] = new TurnImage($image['mimeType'], $bytes);
                }

                return fn () => $this->turnImages->write($key, $images, $expiresAt);
        }

        return sprintf('no mapping for pool %s', $pool);
    }

    /**
     * Proves the exact BSON the store will write decodes back to the source value, so no field is lost.
     *
     * @param array<mixed> $value
     */
    private function roundTrips(array $value): ?string
    {
        $payload = BsonPayload::encode($value);
        $native = null === $payload['native'] ? null : Document::fromPHP(['v' => $payload['native']])->toPHP(self::TYPE_MAP)['v'];

        return BsonPayload::decode($native, $payload['json']) === $value ? null : 'does not round-trip through BSON unchanged';
    }
}
