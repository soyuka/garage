<?php

declare(strict_types=1);

namespace App\Agent\Attachment;

use App\Message\TurnAttachmentRef;
use Symfony\AI\Platform\Message\Content\ContentInterface;
use Symfony\AI\Platform\Message\Content\Document;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * The user message of a turn: the context text, then, when files were attached, a line naming each
 * (id, name, type) so the agent can file one with `file_attachment`, followed by the file itself as a
 * vision image (JPEG/PNG) or a document block (PDF). A part whose bytes are gone (expired) degrades to
 * a note in the text, never to a failed turn.
 */
final readonly class AttachmentUserMessage
{
    public function __construct(private TurnAttachments $attachments)
    {
    }

    /**
     * @param list<TurnAttachmentRef> $refs
     */
    public function build(string $context, array $refs, string $owner): UserMessage
    {
        if ([] === $refs) {
            return Message::ofUser(new Text($context));
        }

        $lines = [];
        $parts = [];
        foreach ($refs as $ref) {
            $attachment = $this->attachments->find($owner, $ref->id);
            if (null === $attachment) {
                $lines[] = \sprintf('- %s (%s): no longer available.', self::quote($ref->name), $ref->mime);

                continue;
            }
            $lines[] = \sprintf('- id %s: %s (%s, %d bytes)', $ref->id, self::quote($ref->name), $ref->mime, $ref->size);
            $bytes = $this->attachments->bytes($attachment);
            // The original name rides in the `path` slot: the bridges only read the bytes and the format,
            // and the capability processor needs a name to write its note.
            $parts[] = 'application/pdf' === $ref->mime ? new Document($bytes, $ref->mime, $ref->name) : new Image($bytes, $ref->mime, $ref->name);
        }

        /** @var list<ContentInterface> $content */
        $content = [new Text($context."\n\nFichiers joints à ce message (visibles ci-dessous pour ce tour seulement ; rien n'est conservé sauf si tu le classes avec file_attachment) :\n".implode("\n", $lines)), ...$parts];

        return Message::ofUser(...$content);
    }

    private static function quote(string $name): string
    {
        return json_encode(TurnAttachments::displayName($name), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
