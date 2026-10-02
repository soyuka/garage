<?php

declare(strict_types=1);

namespace App\Command;

use App\Documentation\InvalidVehicleDocumentException;
use App\Documentation\VehicleDocumentKind;
use App\Documentation\VehicleDocumentRecorder;
use App\Documentation\VehicleDocumentSource;
use App\Entity\Vehicle;
use App\Security\OwnerContext;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotent on (vehicle, --source-ref, filename): re-importing replaces the stored document. Binds
 * the vehicle's owner on OwnerContext because the repositories are owner-scoped and a CLI run has no
 * token.
 */
#[AsCommand(
    name: 'app:documentation:import',
    description: 'Attach PDF/image documentation (manuals, notes, quotes) to a vehicle.',
)]
final class ImportVehicleDocumentationCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $dm,
        private readonly OwnerContext $owner,
        private readonly VehicleDocumentRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('vehicle-id', InputArgument::REQUIRED, 'The vehicle ULID.')
            ->addArgument('path', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'One or more files to import.')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, implode('|', array_column(VehicleDocumentKind::cases(), 'value')), VehicleDocumentKind::Manual->value)
            ->addOption('source', null, InputOption::VALUE_REQUIRED, implode('|', array_column(VehicleDocumentSource::cases(), 'value')), VehicleDocumentSource::Dialogys->value)
            ->addOption('source-ref', null, InputOption::VALUE_REQUIRED, 'Citation label, e.g. "MR 361 — Transmission".')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Defaults to the filename.')
            ->addOption('planned-task', null, InputOption::VALUE_REQUIRED, 'Planned task id (same vehicle) a quote belongs to.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $kind = VehicleDocumentKind::tryFrom((string) $input->getOption('kind'));
        $source = VehicleDocumentSource::tryFrom((string) $input->getOption('source'));
        if (null === $kind || null === $source) {
            $io->error('Invalid --kind or --source.');

            return Command::INVALID;
        }

        $vehicle = $this->dm->find(Vehicle::class, (string) $input->getArgument('vehicle-id'));
        if (!$vehicle instanceof Vehicle || $vehicle->isDeleted()) {
            $io->error('No such vehicle.');

            return Command::FAILURE;
        }

        $this->owner->set($vehicle->getOwner());

        $status = Command::SUCCESS;
        foreach ((array) $input->getArgument('path') as $path) {
            if (!is_file($path)) {
                $io->error(sprintf('%s: not a file.', $path));
                $status = Command::FAILURE;
                continue;
            }

            try {
                $document = $this->recorder->record(
                    $vehicle,
                    $path,
                    basename($path),
                    $kind,
                    $source,
                    $input->getOption('title'),
                    $input->getOption('source-ref'),
                    $input->getOption('planned-task'),
                );
            } catch (InvalidVehicleDocumentException $e) {
                $io->error(sprintf('%s: %s', $path, $e->getMessage()));
                $status = Command::FAILURE;
                continue;
            }

            $io->writeln(sprintf('%s -> %s (%d pages)', $path, $document->getId(), $document->getPages()->count()));
        }

        return $status;
    }
}
