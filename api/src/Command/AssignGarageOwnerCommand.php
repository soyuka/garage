<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AgentTurn;
use App\Entity\MaintenanceRecord;
use App\Entity\PlannedMaintenance;
use App\Entity\Vehicle;
use App\Entity\VehicleDocument;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tenancy backfill: claims garage rows for one owner. Without `--from` it takes the still-unowned
 * rows (`owner: null` — the phase-1 shared garage); with `--from` it moves the rows held by another
 * owner, which is how a parc follows its user when the identity key changes (2026-09-19: the owner
 * moved off the Casdoor `sub` onto the email claim, because Casdoor re-mints the `sub` on restart).
 *
 * Targets the raw collections directly because the repository is owner-scoped — a normal query
 * would never see the rows it is meant to migrate. Idempotent: the match is always an exact owner
 * value, so re-running never moves a row twice.
 */
#[AsCommand(
    name: 'app:garage:assign-owner',
    description: 'Assign garage rows (vehicles, maintenance, documents, journal) to an owner.',
)]
final class AssignGarageOwnerCommand extends Command
{
    private const array COLLECTIONS = [
        Vehicle::class,
        PlannedMaintenance::class,
        MaintenanceRecord::class,
        VehicleDocument::class,
        AgentTurn::class,
    ];

    public function __construct(private readonly DocumentManager $dm)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('owner', InputArgument::REQUIRED, 'The owner claim value to assign the rows to.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Move rows held by this owner instead of the unowned ones.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $owner = (string) $input->getArgument('owner');
        $from = $input->getOption('from');
        $match = ['owner' => null === $from ? null : (string) $from];

        foreach (self::COLLECTIONS as $class) {
            $result = $this->dm->getDocumentCollection($class)
                ->updateMany($match, ['$set' => ['owner' => $owner]]);

            $io->writeln(sprintf('%s: %d matched, %d updated.', $class, $result->getMatchedCount(), $result->getModifiedCount()));
        }

        $io->success(sprintf('Garage rows %s assigned to "%s".', null === $from ? 'with no owner' : sprintf('owned by "%s"', $from), $owner));

        return Command::SUCCESS;
    }
}
