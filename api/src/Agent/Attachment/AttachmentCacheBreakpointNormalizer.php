<?php

declare(strict_types=1);

namespace App\Agent\Attachment;

use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\Message\Content\ContentInterface;
use Symfony\AI\Platform\Message\Content\File;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Makes the user message carrying turn attachments an Anthropic prompt-cache breakpoint. The bridge
 * marks only the last block of the last user message, which is a tool result from the second tool
 * round on, so without this the PDF or image is billed in full again on every round. A turn has one
 * such message, so with the bridge's tools, system and last-message markers the request stays within
 * Anthropic's 4 breakpoints.
 */
final class AttachmentCacheBreakpointNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof UserMessage
            && ($context[Contract::CONTEXT_MODEL] ?? null) instanceof Claude
            && [] !== array_filter($data->getContent(), static fn (ContentInterface $part): bool => $part instanceof File);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [UserMessage::class => false];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof UserMessage) {
            throw new \InvalidArgumentException(\sprintf('Expected a %s.', UserMessage::class));
        }

        $blocks = $this->normalizer->normalize($data->getContent(), $format, $context);
        if (!\is_array($blocks) || [] === $blocks || !array_is_list($blocks)) {
            throw new \LogicException('A user message with attachments normalises to a list of content blocks.');
        }

        $last = $blocks[\count($blocks) - 1];
        if (!\is_array($last)) {
            throw new \LogicException('A content block normalises to an array.');
        }
        $last['cache_control'] = ['type' => 'ephemeral'];
        $blocks[\count($blocks) - 1] = $last;

        return ['role' => $data->getRole()->value, 'content' => $blocks];
    }
}
