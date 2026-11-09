<?php

declare(strict_types=1);

namespace App\Security;

use App\Agent\Journal\ModelUsage;
use App\Entity\OwnerSpend;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class MongoSpendRecorder implements SpendRecorder
{
    public function __construct(
        private DocumentManager $documents,
        private OwnerContext $owner,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function record(string $source, string $model, ModelUsage $usage): void
    {
        try {
            $this->documents->persist(new OwnerSpend($this->owner->current() ?? '', $source, strtok($model, '?') ?: $model, $usage, $this->clock->now()));
            $this->documents->flush();
        } catch (\Throwable $e) {
            // The call already happened and its answer is valid: losing one spend row must not fail the request.
            $this->logger->error('Failed to record owner spend: {error}', ['error' => $e->getMessage()]);
        }
    }
}
