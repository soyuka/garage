<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AgentTurn;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-shot cleanup after the bonneref split (2026-10-30): the finder's journal rows went to bonneref,
 * yet the copies stayed in `agent_turns`, and every garage row still carries the fields the shared
 * journal stamped on it. Unmapped fields hydrate fine, so this is hygiene, not a fix. Runs on the raw
 * collection because the repository is owner-scoped. Idempotent.
 */
#[AsCommand(
    name: 'app:agent-turns:prune-finder',
    description: 'Delete the finder rows of the agent journal and strip the finder fields from garage rows.',
)]
final class PruneFinderJournalCommand extends Command
{
    private const array FINDER_FIELDS = ['surface', 'kind', 'search', 'vehicle', 'images', 'partsApiCalls', 'partsApiCallsByMethod', 'categoryLabel', 'searchId', 'hiddenFromHistory'];

    private const array FINDER_INDEXES = ['idx_turn_owner_search', 'idx_turn_owner_surface_started'];

    public function __construct(private readonly DocumentManager $dm)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $turns = $this->dm->getDocumentCollection(AgentTurn::class);

        $deleted = $turns->deleteMany(['surface' => 'finder'])->getDeletedCount();

        $hasFinderField = array_map(static fn (string $field): array => [$field => ['$exists' => true]], self::FINDER_FIELDS);
        $cleaned = $turns->updateMany(
            ['$or' => $hasFinderField],
            ['$unset' => array_fill_keys(self::FINDER_FIELDS, '')],
        )->getModifiedCount();

        $existing = array_map(static fn ($index): string => $index->getName(), iterator_to_array($turns->listIndexes()));
        foreach (array_intersect(self::FINDER_INDEXES, $existing) as $index) {
            $turns->dropIndex($index);
        }

        (new SymfonyStyle($input, $output))->success(\sprintf('%d finder row(s) deleted, %d garage row(s) cleaned.', $deleted, $cleaned));

        return Command::SUCCESS;
    }
}
