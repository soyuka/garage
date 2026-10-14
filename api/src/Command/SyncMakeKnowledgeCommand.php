<?php

declare(strict_types=1);

namespace App\Command;

use App\Knowledge\MakeKnowledgeSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:make-knowledge:sync',
    description: 'Upsert the make knowledge entries from config/knowledge/*.yaml and remove the ones that disappeared.',
)]
final class SyncMakeKnowledgeCommand extends Command
{
    public function __construct(
        private readonly MakeKnowledgeSynchronizer $synchronizer,
        #[Autowire('%kernel.project_dir%/config/knowledge')]
        private readonly string $directory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Directory holding the *.yaml knowledge files', $this->directory);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->synchronizer->sync((string) $input->getOption('path'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success($result->summary());

        return Command::SUCCESS;
    }
}
