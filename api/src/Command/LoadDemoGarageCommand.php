<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\DemoFixtureException;
use App\Demo\DemoGarageLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:demo:load',
    description: 'Replace an owner\'s garage with the demo fixture (vehicles, visits, planned tasks, facts, schedules, documents).',
)]
final class LoadDemoGarageCommand extends Command
{
    public function __construct(
        private readonly DemoGarageLoader $loader,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fixture', InputArgument::OPTIONAL, 'Fixture written by app:demo:export.', $this->projectDir.'/fixtures/demo/garage.json')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Email the garage is loaded for; their current garage is replaced.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fixture = $input->getArgument('fixture');
        $owner = $input->getOption('owner');
        if (!\is_string($fixture) || !\is_string($owner) || '' === $owner) {
            $io->error('--owner is required.');

            return Command::INVALID;
        }

        try {
            $counts = $this->loader->load($fixture, $owner);
        } catch (DemoFixtureException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('%s: %d vehicles, %d visits, %d planned tasks, %d documents.', $owner, $counts['vehicles'], $counts['maintenanceRecords'], $counts['plannedMaintenance'], $counts['documents']));

        return Command::SUCCESS;
    }
}
