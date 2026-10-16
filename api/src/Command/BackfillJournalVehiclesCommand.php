<?php

declare(strict_types=1);

namespace App\Command;

use App\Agent\Journal\TurnRoute;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnKind;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\Vehicle\VehicleIndex;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Journal rows written before the vehicle index existed (and kType routes, which never carried names)
 * hold a carId and no make/model. Seeds the index from what the app already knows — journal rows that
 * do name their car, garage vehicles with a kType — then writes the resolved names into the stored
 * rows. A carId no source knows stays unresolved: partsapi has no carId → name method, and a guess
 * would be worse than "kType N".
 */
#[AsCommand(
    name: 'app:journal:backfill-vehicles',
    description: 'Name journal rows that only carry a carId, from the vehicle index (dry run unless --apply).',
)]
final class BackfillJournalVehiclesCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $dm,
        private readonly VehicleIndex $index,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Write the index entries and the resolved names instead of only counting them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        /** @var array<string, array{carId: int, carType: string, make: string, model: string, engine: ?string, source: string}> $seeds */
        $seeds = [];
        /** @var list<array{turn: AgentTurn, vehicle: array<string, mixed>}> $lacking */
        $lacking = [];

        foreach ($this->dm->getRepository(AgentTurn::class)->createQueryBuilder()->getQuery()->execute() as $turn) {
            \assert($turn instanceof AgentTurn);
            $vehicle = self::vehicleOf($turn);
            if (null === $vehicle) {
                continue;
            }
            if (VehicleIndex::lacksNames($vehicle)) {
                $lacking[] = ['turn' => $turn, 'vehicle' => $vehicle];
            } elseif (\is_int($vehicle['carId'] ?? null) && null !== ($vehicle['make'] ?? null) && null !== ($vehicle['model'] ?? null)) {
                $seeds[VehicleIndex::id($vehicle['carId'], $vehicle['carType'] ?? 'PC')] ??= $vehicle + ['source' => 'journal'];
            }
        }

        foreach ($this->dm->getRepository(Vehicle::class)->createQueryBuilder()->field('kType')->notEqual(null)->getQuery()->execute() as $garage) {
            \assert($garage instanceof Vehicle);
            $carType = match ($garage->getVehicleType()) {
                VehicleType::Car => 'PC',
                VehicleType::CommercialVehicle => 'CV',
                default => null,
            };
            if (null !== $carType && null !== $garage->getKType()) {
                $seeds[VehicleIndex::id($garage->getKType(), $carType)] ??= ['carId' => $garage->getKType(), 'carType' => $carType, 'make' => $garage->getMake(), 'model' => $garage->getModel(), 'engine' => $garage->getEngineVariant(), 'source' => 'garage'];
            }
        }

        $known = $this->index->findMany(array_map(static fn (array $l): array => ['carId' => $l['vehicle']['carId'], 'carType' => $l['vehicle']['carType'] ?? null], $lacking));
        $resolvable = 0;
        $unresolved = [];
        foreach ($lacking as $row) {
            $key = VehicleIndex::id($row['vehicle']['carId'], $row['vehicle']['carType'] ?? 'PC');
            $name = $known[$key] ?? $seeds[$key] ?? null;
            if (null === $name) {
                $unresolved[$row['vehicle']['carId']] = ($unresolved[$row['vehicle']['carId']] ?? 0) + 1;
                continue;
            }
            ++$resolvable;
            if ($apply) {
                self::write($row['turn'], $name);
            }
        }

        if ($apply) {
            foreach ($seeds as $seed) {
                $this->index->record($seed['carId'], $seed['carType'], $seed['make'], $seed['model'], $seed['engine'] ?? null, $seed['source']);
            }
            $this->dm->flush();
        }

        ksort($unresolved);
        $io->success(\sprintf(
            '%s: %d index seed(s), %d row(s) lacking names: %d row(s) resolvable, %d unresolved (carId: %s).',
            $apply ? 'Applied' : 'Dry run (re-run with --apply)',
            \count($seeds),
            \count($lacking),
            $resolvable,
            array_sum($unresolved),
            [] === $unresolved ? 'none' : implode(', ', array_map(static fn (int $id, int $n): string => $id.' x'.$n, array_keys($unresolved), $unresolved)),
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function vehicleOf(AgentTurn $turn): ?array
    {
        $route = AgentTurnKind::Search === $turn->getKind()
            ? TurnRoute::forSearch($turn->getSearch())
            : TurnRoute::forAgent($turn->getVehicle(), array_map(static fn ($call): array => ['name' => $call->getName(), 'arguments' => $call->getArguments(), 'result' => $call->getResult()], $turn->getToolCalls()));

        return $route->vehicle;
    }

    /**
     * @param array<string, mixed> $name
     */
    private static function write(AgentTurn $turn, array $name): void
    {
        $names = array_filter(['make' => $name['make'], 'model' => $name['model'], 'engine' => $name['engine'] ?? null], static fn (?string $v): bool => null !== $v);
        if (AgentTurnKind::Search === $turn->getKind()) {
            $turn->setSearch($names + $turn->getSearch());
        } elseif (null !== $turn->getVehicle()) {
            $turn->setVehicle($names + $turn->getVehicle());
        }
    }
}
