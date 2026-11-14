<?php

declare(strict_types=1);

namespace App\Command;

use App\Agent\Attachment\TurnAttachments;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Frees the files attached to agent turns that were never filed and have passed their 24 h life.
 * Uploads already prune a bounded batch, so this is the backstop for a quiet period: run it from cron.
 */
#[AsCommand(
    name: 'app:turn-attachments:prune',
    description: 'Delete expired, unfiled turn attachments (rows and stored files).',
)]
final class PruneTurnAttachmentsCommand extends Command
{
    public function __construct(private readonly TurnAttachments $attachments)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = 0;
        while (0 < ($batch = $this->attachments->pruneExpired(500))) {
            $deleted += $batch;
        }

        (new SymfonyStyle($input, $output))->success(\sprintf('%d expired attachment(s) deleted.', $deleted));

        return Command::SUCCESS;
    }
}
