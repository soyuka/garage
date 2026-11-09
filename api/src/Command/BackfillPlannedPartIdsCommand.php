<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PlannedMaintenance;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Ulid;

/**
 * One-shot backfill: stamps a stable ULID onto every embedded `parts` line persisted before parts
 * carried an identity (when they were a plain `collection` of {ref,...} maps). After this, the parts
 * subresource can address a legacy line by id for edit/remove. Runs on the raw collection because the
 * repository is owner-scoped — every owner's rows must be migrated, including the shared-garage ones.
 *
 * Idempotent: a line that already has a non-empty `id` is left untouched, so re-running is a no-op.
 */
#[AsCommand(
    name: 'app:planned-tasks:backfill-part-ids',
    description: 'Stamp a stable ULID onto every legacy planned-task part line that lacks one.',
)]
final class BackfillPlannedPartIdsCommand extends Command
{
    private const array ARRAY_TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    public function __construct(private readonly DocumentManager $dm)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $collection = $this->dm->getDocumentCollection(PlannedMaintenance::class);

        $tasksTouched = 0;
        $linesStamped = 0;
        foreach ($collection->find([], ['typeMap' => self::ARRAY_TYPE_MAP]) as $doc) {
            if (!\is_array($doc)) {
                continue;
            }
            $parts = $doc['parts'] ?? [];
            if (!\is_array($parts) || [] === $parts) {
                continue;
            }

            $changed = false;
            foreach ($parts as $i => $part) {
                if (!\is_array($part) || '' !== (string) ($part['id'] ?? '')) {
                    continue;
                }
                $parts[$i]['id'] = (string) new Ulid();
                $changed = true;
                ++$linesStamped;
            }

            if ($changed) {
                $collection->updateOne(['_id' => $doc['_id']], ['$set' => ['parts' => $parts]]);
                ++$tasksTouched;
            }
        }

        $io->success(\sprintf('%d part line(s) stamped across %d task(s).', $linesStamped, $tasksTouched));

        return Command::SUCCESS;
    }
}
