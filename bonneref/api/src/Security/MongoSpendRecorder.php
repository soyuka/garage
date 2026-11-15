<?php

declare(strict_types=1);

namespace App\Security;

use App\Agent\Journal\ModelUsage;
use App\Entity\OwnerSpend;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A vision intake reserves its estimated cost before the model call ({@see reserve()}), so concurrent intakes
 * see each other in the budget; the reader's {@see record()} then settles that row to the actual usage. A call
 * that never answers leaves the estimate charged.
 */
final class MongoSpendRecorder implements SpendRecorder, ResetInterface
{
    public const string RESERVED = 'intake_reserved';

    private ?string $reservation = null;

    public function __construct(
        private readonly DocumentManager $documents,
        private readonly OwnerContext $owner,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function reserve(string $model, ModelUsage $estimate): void
    {
        $reservation = new OwnerSpend($this->owner->current() ?? '', self::RESERVED, self::modelId($model), $estimate, $this->clock->now());
        $this->documents->persist($reservation);
        $this->documents->flush();
        $this->reservation = $reservation->id;
    }

    public function record(string $source, string $model, ModelUsage $usage): void
    {
        try {
            $reservation = $this->reservation;
            $this->reservation = null;
            if (null === $reservation) {
                $this->documents->persist(new OwnerSpend($this->owner->current() ?? '', $source, self::modelId($model), $usage, $this->clock->now()));
                $this->documents->flush();

                return;
            }

            $this->documents->createQueryBuilder(OwnerSpend::class)
                ->updateOne()
                ->field('id')->equals($reservation)
                ->field('source')->set($source)
                ->field('model')->set(self::modelId($model))
                ->field('promptTokens')->set($usage->promptTokens)
                ->field('completionTokens')->set($usage->completionTokens)
                ->field('cacheReadTokens')->set($usage->cacheReadTokens)
                ->field('cacheCreationTokens')->set($usage->cacheCreationTokens)
                ->getQuery()
                ->execute();
        } catch (\Throwable $e) {
            // The call already happened and its answer is valid: losing one spend row must not fail the request.
            $this->logger->error('Failed to record owner spend: {error}', ['error' => $e->getMessage()]);
        }
    }

    public function reset(): void
    {
        $this->reservation = null;
    }

    private static function modelId(string $model): string
    {
        return strtok($model, '?') ?: $model;
    }
}
