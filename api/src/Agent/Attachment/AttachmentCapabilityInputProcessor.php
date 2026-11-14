<?php

declare(strict_types=1);

namespace App\Agent\Attachment;

use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\Content\ContentInterface;
use Symfony\AI\Platform\Message\Content\File;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * Only the Anthropic bridge normalises `Document` and `Image` parts. The generic bridge (Qwen) has no
 * document normaliser at all (the call would fail) and the configured Qwen model has no vision, so for
 * any non-Claude model each attached file is replaced by a text note and the turn still runs. Gated on
 * the model name, like {@see \App\Agent\QwenThinkingInputProcessor}: the platform is a compile-time
 * choice in ai.yaml, the model is what this seam can see.
 */
#[AsInputProcessor(agent: 'ai.agent.garage')]
final readonly class AttachmentCapabilityInputProcessor implements InputProcessorInterface
{
    public function processInput(Input $input): void
    {
        if (str_starts_with(strtolower($input->getModel()), 'claude')) {
            return;
        }

        $bag = $input->getMessageBag();
        $changed = false;
        $messages = [];
        foreach ($bag->getMessages() as $message) {
            if ($message instanceof UserMessage && [] !== array_filter($message->getContent(), static fn (ContentInterface $part): bool => $part instanceof File)) {
                $changed = true;
                $message = Message::ofUser(...array_map(
                    static fn (ContentInterface $part): ContentInterface => $part instanceof File
                        ? new Text(\sprintf('The user attached "%s" (%s), not viewable by this model.', $part->asPath() ?? 'file', $part->getFormat()))
                        : $part,
                    $message->getContent(),
                ));
            }
            $messages[] = $message;
        }

        if ($changed) {
            $input->setMessageBag(MessageBag::forId($bag->getId(), ...$messages));
        }
    }
}
