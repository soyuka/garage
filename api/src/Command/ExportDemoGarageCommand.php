<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\DemoFixtureException;
use App\Demo\DemoGarageExporter;
use App\Demo\RealValueMapping;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:demo:export',
    description: 'Write the demo garage fixture from an owner\'s live garage, real plates/VINs rewritten by a private mapping file.',
)]
final class ExportDemoGarageCommand extends Command
{
    public function __construct(
        private readonly DemoGarageExporter $exporter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Email of the garage to export.')
            ->addOption('mapping', null, InputOption::VALUE_REQUIRED, 'Real → fake mapping file (plate:/regex: lines). Holds real values: keep it out of the repository.')
            ->addOption('vehicle', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Vehicle ULID to export (repeat).')
            ->addOption('extras', null, InputOption::VALUE_REQUIRED, 'JSON file of extra fixture documents (vehicles, maintenanceRecords, plannedMaintenance) to merge in.', $this->projectDir.'/fixtures/demo/extras.json')
            ->addOption('skip-document', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Vehicle document ULID to leave out (repeat).')
            ->addOption('max-documents-bytes', null, InputOption::VALUE_REQUIRED, 'Refuse to copy more document bytes than this.', (string) DemoGarageExporter::DEFAULT_MAX_DOCUMENT_BYTES)
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Fixture path; binaries go to documents/ next to it.', $this->projectDir.'/fixtures/demo/garage.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $owner = $input->getOption('owner');
        $mapping = $input->getOption('mapping');
        $out = $input->getOption('out');
        $extras = $input->getOption('extras');
        $max = filter_var($input->getOption('max-documents-bytes'), \FILTER_VALIDATE_INT);
        if (!\is_string($owner) || !\is_string($mapping) || !\is_string($out) || false === $max) {
            $io->error('--owner, --mapping and --out are required; --max-documents-bytes is an integer.');

            return Command::INVALID;
        }

        try {
            $report = $this->exporter->export(
                $owner,
                self::strings($input->getOption('vehicle')),
                RealValueMapping::fromFile($mapping),
                \is_string($extras) && is_file($extras) ? $extras : null,
                $out,
                $max,
                self::strings($input->getOption('skip-document')),
            );
        } catch (DemoFixtureException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%s: %d vehicles, %d visits, %d planned tasks, %d documents (%.1f MB).',
            $out,
            $report['vehicles'],
            $report['maintenanceRecords'],
            $report['plannedMaintenance'],
            $report['documents'],
            $report['documentBytes'] / 1_000_000,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        $strings = [];
        foreach (\is_array($values) ? $values : [] as $value) {
            if (\is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }
}
