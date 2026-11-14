<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Attachment\AttachmentCapabilityInputProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\Content\Document;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;

final class AttachmentCapabilityInputProcessorTest extends TestCase
{
    public function testAClaudeModelKeepsTheBinaryParts(): void
    {
        $bag = $this->bag();
        $input = new Input('claude-sonnet-5-5', $bag);

        new AttachmentCapabilityInputProcessor()->processInput($input);

        $this->assertSame($bag, $input->getMessageBag());
    }

    public function testAModelThatCannotTakeThemGetsATextNoteInstead(): void
    {
        $input = new Input('qwen3-coder-plus', $this->bag());

        new AttachmentCapabilityInputProcessor()->processInput($input);

        $message = $input->getMessageBag()->getMessages()[0];
        $this->assertInstanceOf(UserMessage::class, $message);
        $content = $message->getContent();
        $this->assertCount(3, $content);
        $this->assertContainsOnlyInstancesOf(Text::class, $content);
        $this->assertSame('Bonjour', $content[0]->getText());
        $this->assertSame('The user attached "facture.png" (image/png), not viewable by this model.', $content[1]->getText());
        $this->assertSame('The user attached "notice.pdf" (application/pdf), not viewable by this model.', $content[2]->getText());
    }

    private function bag(): MessageBag
    {
        return new MessageBag(Message::ofUser(
            new Text('Bonjour'),
            new Image('png-bytes', 'image/png', 'facture.png'),
            new Document('pdf-bytes', 'application/pdf', 'notice.pdf'),
        ));
    }
}
