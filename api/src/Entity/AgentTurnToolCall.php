<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

/**
 * One tool the agent invoked during a turn, in execution order. Embedded in {@see AgentTurn}:
 * a tool call has no identity or life of its own outside the turn that made it.
 *
 * `result` is the stringified tool output (the toolbox returns mixed — a string ack, a JSON-encoded
 * payload, a render-intent "ok"); kept as text so the journal renders any of them verbatim without a
 * per-tool schema. `serverSide` flags Anthropic's in-response tools (web_search): they are not
 * toolbox calls and carry a synthetic name, so the panel can mark them apart from domain tools.
 */
#[ODM\EmbeddedDocument]
class AgentTurnToolCall
{
    public function __construct(
        #[ODM\Field(type: 'int')]
        private int $seq,
        #[ODM\Field(type: 'string')]
        private string $name,
        /** @var array<string, mixed> */
        #[ODM\Field(type: 'hash')]
        private array $arguments,
        #[ODM\Field(type: 'string')]
        private string $result,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $at,
        #[ODM\Field(type: 'bool')]
        private bool $serverSide = false,
    ) {
    }

    public function getSeq(): int
    {
        return $this->seq;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function isServerSide(): bool
    {
        return $this->serverSide;
    }
}
