<?php

declare(strict_types=1);

namespace App\Command;

use App\Agent\TurnSurface;
use App\Entity\AgentTurn;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\Regex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Finder rows journaled before only user submits were: their instruction is a request path or a bare
 * category id, never what the user typed. Hides them from the home history; the journal keeps them.
 */
#[AsCommand(
    name: 'app:journal:hide-noise',
    description: 'Hide finder journal rows whose instruction is a path or a bare number from the home history (dry run unless --apply).',
)]
final class HideJournalNoiseCommand extends Command
{
    public function __construct(private readonly DocumentManager $dm)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Flag the rows instead of only counting them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $collection = $this->dm->getDocumentCollection(AgentTurn::class);
        $filter = [
            'surface' => TurnSurface::Finder->value,
            'hiddenFromHistory' => ['$ne' => true],
            '$or' => [
                ['instruction' => new Regex('^\s*/')],
                ['instruction' => new Regex('^\s*\d+\s*$')],
            ],
        ];

        $matched = $collection->countDocuments($filter);
        if (!$input->getOption('apply')) {
            $io->success(\sprintf('%d noise row(s) would be hidden. Re-run with --apply.', $matched));

            return Command::SUCCESS;
        }

        $result = $collection->updateMany($filter, ['$set' => ['hiddenFromHistory' => true]]);
        $io->success(\sprintf('%d noise row(s) hidden (%d matched).', $result->getModifiedCount(), $matched));

        return Command::SUCCESS;
    }
}
