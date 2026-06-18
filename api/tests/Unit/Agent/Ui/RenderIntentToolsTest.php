<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\ActionButton;
use App\Agent\Ui\RenderIntentTools;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Contract\JsonSchema\Factory;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class RenderIntentToolsTest extends TestCase
{
    public function testPublishFailureIsBestEffortAndDoesNotBreakTheTurn(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new RuntimeException('Failed to send an update.'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $tools = new RenderIntentTools($hub, $logger);
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $this->assertSame('ok', $tools->toast('Entretien enregistré', 'ok'));
    }

    public function testPublishSerialisesTheTypedIntentToTheBoundSessionTopic(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->selectVehicle('/garage/vehicles/01J');

        $this->assertNotNull($published);
        $this->assertSame(['https://localhost/agent/sessions/abc'], $published->getTopics());
        $this->assertSame(['type' => 'selectVehicle', 'id' => '/garage/vehicles/01J'], json_decode($published->getData(), true));
    }

    /**
     * The LLM-facing schema must describe each action as an OBJECT with required label/instruction —
     * a phpstan `array{label, instruction}` shape silently degrades to "array of strings" and the
     * model can never emit the right keys (see the propose_actions tool-call failures).
     */
    public function testProposeActionsSchemaDescribesEachActionAsLabelInstructionObject(): void
    {
        $schema = (new Factory())->buildParameters(RenderIntentTools::class, 'proposeActions');

        $items = $schema['properties']['actions']['items'];
        $this->assertSame('object', $items['type']);
        $this->assertSame('string', $items['properties']['label']['type']);
        $this->assertSame('string', $items['properties']['instruction']['type']);
        $this->assertContains('label', $items['required']);
        $this->assertContains('instruction', $items['required']);
    }

    public function testProposeActionsPublishesButtonsFromTypedActions(): void
    {
        $published = null;
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        $tools->proposeActions('Entretien à 250 000 km', [
            new ActionButton('Liste de courses', 'prépare la liste de courses pour la vidange'),
        ], 'Trouvé en ligne : vidange');

        $this->assertNotNull($published);
        $this->assertSame([
            'type' => 'proposeActions',
            'headline' => 'Entretien à 250 000 km',
            'actions' => [['label' => 'Liste de courses', 'instruction' => 'prépare la liste de courses pour la vidange']],
            'finding' => 'Trouvé en ligne : vidange',
        ], json_decode($published->getData(), true));
    }
}
