<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;

/**
 * Registers the `fill_vehicle_info` MCP prompt — a user-invoked "default action" the host
 * surfaces as a quick-action/slash-command. It is NOT server-side Q&A: prompts/get returns a
 * seed user-message that steers the model to audit one vehicle through the existing tools
 * (get_vehicle_facts, list_due_maintenance) and fill every gap it finds (add_vehicle_fact,
 * log_maintenance), then surface the assumed service intervals for the user to confirm.
 *
 * A LoaderInterface (not an attribute) because the seed text is templated from the call
 * arguments at prompts/get time — there is no static resource to attach.
 */
final class FillVehicleInfoPromptLoader implements LoaderInterface
{
    public const NAME = 'fill_vehicle_info';

    public function load(RegistryInterface $registry): void
    {
        $registry->registerPrompt(
            new Prompt(
                name: self::NAME,
                title: 'Fill in a vehicle',
                description: 'Audit one vehicle and fill in everything we are missing: maintenance specs (oil type/capacity, filter refs…) and service history, then confirm the assumed maintenance intervals.',
                arguments: [
                    new PromptArgument('make', 'Vehicle make, e.g. "TORO", "MAZDA".', required: true),
                    new PromptArgument('model', 'Vehicle model, e.g. "246-H", "6".', required: true),
                    new PromptArgument('vehicleType', 'Segment: car | moto | cv | groundscare. Defaults to "car".', required: false),
                ],
            ),
            self::seed(...),
        );
    }

    /**
     * @return list<PromptMessage>
     */
    private static function seed(string $make, string $model, ?string $vehicleType = null): array
    {
        $type = $vehicleType ?? 'car';
        $vehicle = sprintf('%s %s (%s)', $make, $model, $type);

        $text = <<<PROMPT
            You are completing the garage record for {$vehicle}. Work through these steps with me, asking one focused question at a time and writing each answer back to the store as you go.

            1. Check what is already known. Call get_vehicle_facts (make="{$make}", model="{$model}", vehicleType="{$type}") for its specs, and list_due_maintenance (make="{$make}", model="{$model}", vehicleType="{$type}") for its service forecast and gaps.

            2. Fill the missing specs. For each spec we do not yet have (oil type, oil capacity, coolant, brake fluid, spark plug reference, filter references…), ask me for it, then store it with add_vehicle_fact. If I give a manufacturer/manual source, pass it as the source with its url.

            3. Fill the missing history. The "gaps" / unknownBaseline in list_due_maintenance are recurring services with no logged event, so their next-due is unknown. For each, ask me when it was last done (and the odometer/hour reading then) and record it with log_maintenance. Use usageUnit="hours" for engine-hour machinery.

            4. Confirm the intervals. The forecast uses general-knowledge default intervals, not the manufacturer schedule. Show me the assumed interval (months, and any km/hours figure) behind each due item and ask me to confirm or correct it.

            5. Refine the usage axis. For any service that also recurs by distance/hours, ask me the vehicle's current km/hours so the next-due can account for usage, not just elapsed time.

            Do not invent values — if I do not know something, leave it unset and move on.
            PROMPT;

        return [new PromptMessage(Role::User, new TextContent($text))];
    }
}
