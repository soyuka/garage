<?php

declare(strict_types=1);

namespace App\State\Dashboard;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Ui\RenderIntentTools;
use App\Agent\Ui\SpecNode;
use App\Dto\Dashboard\ShowFactInput;
use App\Dto\Dashboard\ShowFactNodeInput;
use App\Garage\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `show_fact`: raise the focused spec panel for the SELECTED vehicle — call select_vehicle first
 * when the answer is about another one. Either it filters the vehicle's stored facts (`keys`) or it
 * projects lines the caller fetched (`nodes`), which no key could select.
 *
 * The nested input is read as arrays for the same reason {@see ProposeActionsProcessor} does: the
 * schema is generated from {@see ShowFactNodeInput} so the host sees every key, but MCP hydrates only
 * the TOP-level input object, so a nested list arrives as plain arrays. Both shapes are accepted.
 *
 * @implements ProcessorInterface<ShowFactInput, CallToolResult>
 */
final readonly class ShowFactProcessor implements ProcessorInterface
{
    public function __construct(private RenderIntentTools $render)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $keys = array_values(array_filter(array_map(trim(...), $data->keys), static fn (string $k): bool => '' !== $k));
        $nodes = self::nodes($data->nodes);

        if ([] === $keys && [] === $nodes) {
            return ToolResult::of(['status' => 'invalid_input', 'hint' => 'pass either keys (stored vehicle facts, e.g. ["oil_capacity"]) or nodes (lines you fetched, e.g. [{"key":"oil_capacity","label":"Huile moteur","value":"4,3 l"}]).']);
        }

        $this->render->showFact($keys, $data->title, $nodes);

        return ToolResult::of(['status' => 'rendered', 'intent' => 'showFact']);
    }

    /**
     * @param list<ShowFactNodeInput|array<string, mixed>> $nodes
     *
     * @return list<SpecNode>
     */
    private static function nodes(array $nodes): array
    {
        $projected = [];
        foreach ($nodes as $node) {
            $key = self::text($node, 'key');
            $value = self::text($node, 'value');
            // A line with no key or no value has nothing to say: rendering it would put a blank row in
            // front of the operator, which reads as data we hold and do not.
            if (null === $key || null === $value) {
                continue;
            }

            $projected[] = new SpecNode(
                $key,
                self::text($node, 'label') ?? $key,
                $value,
                (bool) self::field($node, 'verified'),
                self::text($node, 'info'),
            );
        }

        return $projected;
    }

    /** @param ShowFactNodeInput|array<string, mixed> $node */
    private static function text(ShowFactNodeInput|array $node, string $field): ?string
    {
        $value = self::field($node, $field);

        return null === $value || '' === trim((string) $value) ? null : trim((string) $value);
    }

    /** @param ShowFactNodeInput|array<string, mixed> $node */
    private static function field(ShowFactNodeInput|array $node, string $field): mixed
    {
        return \is_array($node) ? ($node[$field] ?? null) : ($node->{$field} ?? null);
    }
}
