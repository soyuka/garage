<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports a legacy var/-layout cache directory (the on-disk format prototype_1 and the
 * pre-PostgreSQL version of this app produced) into the cache.siv and cache.partsapi
 * PSR-6 pools. The keys written match exactly what SivClient/PartsApiClient look up, so
 * already-paid responses are served straight from the database afterwards.
 *
 * Expected layout under <dir>:
 *   siv/<PLATE>.json                       full SIV envelope  → cache.siv[<PLATE>]
 *   partsapi/<method>_<sha1>.json          decoded response   → cache.partsapi[<method>.<sha1>]
 *   partsapi/_calls_<method>.json          {"spent": N}       → cache.partsapi[calls.<method>] = N
 *
 * Idempotent: every item is overwritten with the source value, so re-running never
 * double-counts the budget counters.
 */
#[AsCommand(
    name: 'app:cache:import-legacy',
    description: 'Import a legacy var/-layout cache dir (siv + partsapi) into the PostgreSQL cache pools.',
)]
final class ImportLegacyCacheCommand extends Command
{
    public function __construct(
        private readonly CacheItemPoolInterface $sivCache,
        private readonly CacheItemPoolInterface $partsApiCache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('dir', InputArgument::REQUIRED, 'Legacy cache directory holding siv/ and partsapi/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = rtrim((string) $input->getArgument('dir'), '/');

        if (!is_dir($dir)) {
            $io->error(sprintf('Directory not found: %s', $dir));

            return Command::FAILURE;
        }

        $siv = $this->importSiv($dir.'/siv');
        [$parts, $counters] = $this->importPartsApi($dir.'/partsapi');

        $io->success(sprintf('Imported %d siv, %d partsapi response(s), %d budget counter(s).', $siv, $parts, $counters));

        return Command::SUCCESS;
    }

    private function importSiv(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $envelope = $this->decode($file);
            if (!\is_array($envelope)) {
                continue;
            }

            $plate = basename($file, '.json');
            $this->sivCache->save($this->sivCache->getItem($plate)->set($envelope));
            ++$count;
        }

        return $count;
    }

    /**
     * @return array{0: int, 1: int} [responses imported, counters imported]
     */
    private function importPartsApi(string $dir): array
    {
        if (!is_dir($dir)) {
            return [0, 0];
        }

        $responses = 0;
        $counters = 0;
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $name = basename($file, '.json');

            if (preg_match('/^_calls_(.+)$/', $name, $m)) {
                $payload = $this->decode($file);
                $spent = \is_array($payload) ? (int) ($payload['spent'] ?? 0) : 0;
                $key = 'calls.'.$m[1];
                $this->partsApiCache->save($this->partsApiCache->getItem($key)->set($spent));
                ++$counters;
                continue;
            }

            // <method>_<40-hex sha1>
            if (!preg_match('/^(.+)_([0-9a-f]{40})$/', $name, $m)) {
                continue;
            }

            $data = $this->decode($file);
            if (!\is_array($data)) {
                continue;
            }

            $key = $m[1].'.'.$m[2];
            $this->partsApiCache->save($this->partsApiCache->getItem($key)->set($data));
            ++$responses;
        }

        return [$responses, $counters];
    }

    private function decode(string $file): mixed
    {
        return json_decode((string) file_get_contents($file), true);
    }
}
