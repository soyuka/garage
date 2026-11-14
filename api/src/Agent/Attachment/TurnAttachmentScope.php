<?php

declare(strict_types=1);

namespace App\Agent\Attachment;

use App\Message\TurnAttachmentRef;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The attachments of the turn in flight. `file_attachment` only accepts ids bound here, so an id from
 * another turn (or another tab of the same owner) cannot be filed by this one.
 */
final class TurnAttachmentScope implements ResetInterface
{
    /** @var array<string, TurnAttachmentRef> */
    private array $refs = [];

    /**
     * @param list<TurnAttachmentRef> $refs
     */
    public function bind(array $refs): void
    {
        $this->refs = [];
        foreach ($refs as $ref) {
            $this->refs[$ref->id] = $ref;
        }
    }

    public function has(string $id): bool
    {
        return isset($this->refs[$id]);
    }

    public function reset(): void
    {
        $this->refs = [];
    }
}
