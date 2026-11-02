<?php

declare(strict_types=1);

namespace App\PhotoIntake;

use App\Agent\Finder\BuildLabelHints;
use App\Agent\Finder\InvalidTurnInput;
use App\Agent\Finder\PartPhotoHints;
use App\Entity\IntakeReading;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Owner-scoped, short-lived store of what {@see PhotoIntake} read, so a finder turn names the reading by
 * id and the server, not the page, puts it in the prompt.
 */
final readonly class IntakeReadings
{
    private const int TTL = 3600;

    public function __construct(
        private DocumentManager $dm,
        private ClockInterface $clock,
    ) {
    }

    public function keep(string $owner, PhotoIntakeResult $result): ?string
    {
        if (null === $result->part && null === $result->buildLabel) {
            return null;
        }
        $reading = new IntakeReading($owner, $result->part?->toArray(), $result->buildLabel?->toArray(), $this->clock->now()->modify(\sprintf('+%d seconds', self::TTL)));
        $this->dm->persist($reading);
        $this->dm->flush();

        return $reading->id;
    }

    /**
     * @return array{part: ?PartPhotoHints, buildLabel: ?BuildLabelHints}
     *
     * @throws InvalidTurnInput
     */
    public function find(string $owner, mixed $id): array
    {
        $reading = \is_string($id) && Ulid::isValid($id)
            ? $this->dm->createQueryBuilder(IntakeReading::class)
                ->readOnly()
                ->field('id')->equals($id)
                ->field('owner')->equals($owner)
                ->field('expiresAt')->gt($this->clock->now())
                ->getQuery()
                ->getSingleResult()
            : null;
        if (!$reading instanceof IntakeReading) {
            throw new InvalidTurnInput('intakeId is not one of your recent photo readings');
        }

        return [
            'part' => null === $reading->part ? null : PartPhotoHints::fromPayload($reading->part),
            'buildLabel' => null === $reading->buildLabel ? null : BuildLabelHints::fromPayload($reading->buildLabel),
        ];
    }
}
