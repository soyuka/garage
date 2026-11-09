<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Journal\TurnJournal;
use App\Agent\QwenModelLogger;
use App\Entity\AgentTurn;
use App\Message\RunAgentTurn;
use App\Tests\Support\CountingRawResult;
use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;

final class QwenModelLoggerTest extends TestCase
{
    public function testRecordsTheLiveModelOnTheJournal(): void
    {
        // TurnJournal is final and cannot be doubled; drive a real one. begin() opens the turn without
        // touching the DocumentManager, recordModel() lands the provider/model on it.
        $journal = new TurnJournal($this->createStub(DocumentManager::class), $this->createStub(LoggerInterface::class));
        $journal->begin($this->message(), 'le contexte');

        $this->decorator(new CountingRawResult(['choices' => []]), $journal)
            ->invoke('qwen3.5-flash', 'hi', ['stream' => true]);

        $turn = $this->openTurn($journal);
        $this->assertSame('qwen', $turn->getProvider());
        $this->assertSame('qwen3.5-flash', $turn->getModel());
        $this->assertTrue($turn->isStream());
    }

    public function testNeverReadsTheRawResult(): void
    {
        // The turn always streams; the logger only reports the model and must not touch the body
        // (an eager raw read would drain the stream).
        $raw = new CountingRawResult(['search_info' => ['search_results' => [['url' => 'https://example.com']]]]);

        $journal = new TurnJournal($this->createStub(DocumentManager::class), $this->createStub(LoggerInterface::class));
        $journal->begin($this->message(), 'le contexte');

        $this->decorator($raw, $journal)->invoke('qwen3.5-flash', 'hi', ['stream' => true, 'enable_search' => true]);

        $this->assertSame(0, $raw->reads, 'the logger must not force the raw HTTP fetch');
    }

    private function message(): RunAgentTurn
    {
        return new RunAgentTurn(
            instruction: 'hi',
            sessionTopic: 'https://localhost/.well-known/mercure?topic='.rawurlencode('/agent/sessions/sess'),
            owner: 'owner',
        );
    }

    private function decorator(RawResultInterface $raw, TurnJournal $journal): QwenModelLogger
    {
        $deferred = new DeferredResult($this->createStub(ResultConverterInterface::class), $raw);

        $inner = $this->createStub(PlatformInterface::class);
        $inner->method('invoke')->willReturn($deferred);
        $inner->method('getModelCatalog')->willReturn($this->createStub(ModelCatalogInterface::class));

        return new QwenModelLogger($inner, $journal);
    }

    private function openTurn(TurnJournal $journal): AgentTurn
    {
        $current = (new \ReflectionProperty(TurnJournal::class, 'current'))->getValue($journal);
        self::assertInstanceOf(AgentTurn::class, $current);

        return $current;
    }
}
