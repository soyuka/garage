<?php

declare(strict_types=1);

namespace App\Message;

/**
 * A file the user attached to a turn, by reference: the bytes sit in the turn-attachment store, the
 * queued message carries only this.
 */
final readonly class TurnAttachmentRef
{
    public function __construct(
        public string $id,
        public string $name,
        public string $mime,
        public int $size,
    ) {
    }
}
