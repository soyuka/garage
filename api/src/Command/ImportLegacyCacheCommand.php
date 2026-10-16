<?php

declare(strict_types=1);

namespace App\Command;

use App\PartsApi\MongoPartsApiResponseStore;
use App\Siv\MongoSivLookupStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports a legacy var/-layout cache directory (the on-disk format prototype_1 and the
 * pre-PostgreSQL version of this app produced) into the siv_lookups and partsapi_responses
 * collections. The ids written match exactly what SivClient/PartsApiClient look up, so
 * already-paid responses are served straight from the database afterwards.
 *
 * Expected layout under <dir>:
 *   siv/<PLATE>.json                       full SIV envelope  → siv_lookups[<PLATE>]
 *   partsapi/<method>_<sha1>.json          decoded response   → partsapi_responses[<method>.<sha1>]
 *   partsapi/_calls_<method>.json          {"spent": N}       → ignored: the call ceiling they fed is gone (fff1cf9)
 *
 * Idempotent: every entry is upserted with the source value.
 */
#[AsCommand(
    name: 'app:cache:import-legacy',
    description: 'Import a legacy var/-layout cache dir (siv + partsapi) into the MongoDB upstream stores.',
)]
final class ImportLegacyCacheCommand extends Command
{
    public function __construct(
        private readonly MongoSivLookupStore $sivStore,
        private readonly MongoPartsApiResponseStore $partsApiStore,
        private readonly ClockInterface $clock,
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

        $io->success(sprintf('Imported %d siv, %d partsapi response(s); ignored %d dead call counter(s).', $siv, $parts, $counters));

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
            $this->sivStore->write($plate, $envelope, $this->clock->now());
            ++$count;
        }

        return $count;
    }

    /**
     * @return array{0: int, 1: int} [responses imported, counters ignored]
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

            if (str_starts_with($name, '_calls_')) {
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

            $this->partsApiStore->write($m[1].'.'.$m[2], $m[1], null, $data, $this->clock->now(), null);
            ++$responses;
        }

        return [$responses, $counters];
    }

    private function decode(string $file): mixed
    {
        return json_decode((string) file_get_contents($file), true);
    }
}
