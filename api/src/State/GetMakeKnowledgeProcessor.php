<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\GetMakeKnowledgeInput;
use App\Entity\MakeKnowledge;
use App\Entity\MakeKnowledgeSource;
use App\Garage\ToolResult;
use App\Knowledge\MakeName;
use App\Repository\MakeKnowledgeRepository;
use Mcp\Schema\Result\CallToolResult;

/**
 * @implements ProcessorInterface<GetMakeKnowledgeInput, CallToolResult>
 */
final class GetMakeKnowledgeProcessor implements ProcessorInterface
{
    public function __construct(private readonly MakeKnowledgeRepository $repository)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $make = MakeName::normalize($data->make);
        if ('' === $make) {
            return ToolResult::of(['status' => 'invalid_input', 'reason' => 'Provide the vehicle make (e.g. "Peugeot").']);
        }

        $entries = $this->repository->findForMake($make);
        $topic = trim((string) $data->topic);

        if ('' === $topic) {
            return ToolResult::of(['status' => [] === $entries ? 'not_found' : 'ok', 'make' => $make, 'topics' => self::topics($entries)]);
        }

        foreach ($entries as $entry) {
            if ($entry->getTopic() === $topic) {
                return ToolResult::of([
                    'status' => 'ok',
                    'make' => $make,
                    'topic' => $entry->getTopic(),
                    'title' => $entry->getTitle(),
                    'body' => $entry->getBody(),
                    'sources' => array_map(static fn (MakeKnowledgeSource $s): array => $s->toArray(), $entry->getSources()),
                    'verified' => $entry->isVerified(),
                    'updatedAt' => $entry->getUpdatedAt()->format(\DATE_ATOM),
                ]);
            }
        }

        return ToolResult::of(['status' => 'not_found', 'make' => $make, 'topic' => $topic, 'topics' => self::topics($entries)]);
    }

    /**
     * @param list<MakeKnowledge> $entries
     *
     * @return list<array{topic: string, title: string}>
     */
    private static function topics(array $entries): array
    {
        return array_map(static fn (MakeKnowledge $e): array => ['topic' => $e->getTopic(), 'title' => $e->getTitle()], $entries);
    }
}
