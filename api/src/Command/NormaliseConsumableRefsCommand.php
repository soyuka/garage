<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MaintenanceRecord;
use App\Entity\PlannedMaintenance;
use App\Maintenance\PartRefRewriter;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rewrites stored part refs through {@see PartRefRewriter} so legacy lines
 * ("dot4", "5w30") match the canonical spelling. Raw collections: the repositories are owner-scoped
 * and every owner's rows must be migrated. Idempotent. A document whose rewrite would make two lines share a ref is skipped and reported, never merged.
 */
#[AsCommand(
    name: 'app:parts:normalise-consumable-refs',
    description: 'Rewrite stored planned-task and maintenance-record part refs to their canonical consumable standard.',
)]
final class NormaliseConsumableRefsCommand extends Command
{
    private const array ARRAY_TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    public function __construct(private readonly DocumentManager $dm)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the changes without writing them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $rewritten = 0;
        $collisions = 0;
        foreach ([[PlannedMaintenance::class, 'planned task'], [MaintenanceRecord::class, 'maintenance record']] as [$class, $label]) {
            $collection = $this->dm->getDocumentCollection($class);
            foreach ($collection->find([], ['typeMap' => self::ARRAY_TYPE_MAP]) as $doc) {
                $id = (string) $doc['_id'];
                $update = [];
                $docChanges = [];
                $docCollisions = [];

                if (\is_array($doc['parts'] ?? null)) {
                    $result = PartRefRewriter::rewrite($doc['parts']);
                    if ([] !== $result->changes) {
                        $update['parts'] = $result->parts;
                        array_push($docChanges, ...$result->changes);
                        array_push($docCollisions, ...$result->collisions);
                    }
                }
                if (\is_array($doc['items'] ?? null)) {
                    $items = $doc['items'];
                    foreach ($items as $i => $item) {
                        if (!\is_array($item) || !\is_array($item['parts'] ?? null)) {
                            continue;
                        }
                        $result = PartRefRewriter::rewrite($item['parts']);
                        if ([] !== $result->changes) {
                            $items[$i]['parts'] = $result->parts;
                            $update['items'] = $items;
                            array_push($docChanges, ...$result->changes);
                            array_push($docCollisions, ...$result->collisions);
                        }
                    }
                }

                foreach ($docChanges as [$from, $to]) {
                    $io->writeln(\sprintf('%s %s: "%s" -> "%s"', $label, $id, $from, $to));
                }
                if ([] !== $docCollisions) {
                    $collisions += \count($docCollisions);
                    $io->writeln(\sprintf('COLLISION %s %s: %s would share a ref — %s', $label, $id, implode(', ', array_map(static fn (string $r): string => '"'.$r.'"', $docCollisions)), $dryRun ? 'would be skipped' : 'skipped'));
                    continue;
                }

                $rewritten += \count($docChanges);
                if ([] !== $update && !$dryRun) {
                    $collection->updateOne(['_id' => $doc['_id']], ['$set' => $update]);
                }
            }
        }

        $io->success(\sprintf('%d ref(s) %s; %d collision(s) %s.', $rewritten, $dryRun ? 'would be rewritten (dry run, nothing written)' : 'rewritten', $collisions, $dryRun ? 'would skip their documents' : 'skipped, documents untouched'));

        return Command::SUCCESS;
    }
}
