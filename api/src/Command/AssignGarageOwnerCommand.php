<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MaintenanceRecord;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-shot tenancy backfill: claims every still-unowned garage row (`owner: null` — the phase-1
 * shared garage) for a single Casdoor `sub`. Run once when leaving the shared-garage phase so the
 * pre-existing parc belongs to its real owner; afterwards new rows are owned at write time and this
 * is a no-op.
 *
 * Targets the raw collections directly because the repository is now owner-scoped — a normal query
 * would never see the null-owner rows it is meant to migrate. Idempotent: matches only `owner: null`,
 * so re-running never moves an already-claimed row.
 */
#[AsCommand(
    name: 'app:garage:assign-owner',
    description: 'Assign every unowned garage row (vehicles + maintenance) to a Casdoor sub.',
)]
final class AssignGarageOwnerCommand extends Command
{
    private const array COLLECTIONS = [Vehicle::class, PlannedMaintenance::class, MaintenanceRecord::class];

    public function __construct(private readonly DocumentManager $dm)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('sub', InputArgument::REQUIRED, 'The Casdoor sub to assign the unowned rows to.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sub = (string) $input->getArgument('sub');

        foreach (self::COLLECTIONS as $class) {
            $result = $this->dm->getDocumentCollection($class)
                ->updateMany(['owner' => null], ['$set' => ['owner' => $sub]]);

            $io->writeln(sprintf('%s: %d matched, %d updated.', $class, $result->getMatchedCount(), $result->getModifiedCount()));
        }

        $io->success(sprintf('Unowned garage rows assigned to "%s".', $sub));

        return Command::SUCCESS;
    }
}
