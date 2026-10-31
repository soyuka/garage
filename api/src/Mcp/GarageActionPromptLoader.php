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
 * The garage "default actions": user-invoked MCP prompts a host surfaces as quick-action
 * buttons. Each returns a seed user-message that steers the model to one tool with the right
 * arguments — the chatbot equivalent of a canned command. Richer multi-step onboarding lives
 * in {@see FillVehicleInfoPromptLoader}; these are the one-shot shortcuts.
 */
final class GarageActionPromptLoader implements LoaderInterface
{
    public function load(RegistryInterface $registry): void
    {
        $registry->registerPrompt(
            new Prompt(
                name: 'list_vehicles',
                title: 'List my vehicles',
                description: 'Show the vehicles held in the garage, optionally filtered by make/model/type.',
                arguments: [
                    new PromptArgument('make', 'Restrict to a make, e.g. "IVECO". Omit for the whole garage.', required: false),
                    new PromptArgument('model', 'Restrict to a model, e.g. "DAILY". Omit for all models.', required: false),
                    new PromptArgument('vehicleType', 'Segment: car | moto | cv | groundscare. Omit for all segments.', required: false),
                ],
            ),
            self::listVehicles(...),
        );

        $registry->registerPrompt(
            new Prompt(
                name: 'whats_due',
                title: 'What is due?',
                description: 'Show the services that are overdue or coming up across the garage.',
                arguments: [
                    new PromptArgument('withinDays', 'Only services due within this many days (overdue always shown). Omit for all upcoming.', required: false),
                    new PromptArgument('make', 'Restrict to a make, e.g. "IVECO". Omit for the whole garage.', required: false),
                ],
            ),
            self::whatsDue(...),
        );

        $registry->registerPrompt(
            new Prompt(
                name: 'next_to_maintain',
                title: 'Next vehicle to maintain',
                description: 'Find the single most urgent service across the garage.',
            ),
            self::nextToMaintain(...),
        );

        $registry->registerPrompt(
            new Prompt(
                name: 'identify_plate',
                title: 'Identify a plate',
                description: 'Look up a French registration plate and offer to save the vehicle to the garage.',
                arguments: [
                    new PromptArgument('plate', 'French registration plate, e.g. "XC-302-ZC".', required: true),
                ],
            ),
            self::identifyPlate(...),
        );

        $registry->registerPrompt(
            new Prompt(
                name: 'log_service',
                title: 'Log a service',
                description: 'Record a maintenance/service event you just performed on a vehicle.',
                arguments: [
                    new PromptArgument('make', 'Vehicle make, e.g. "IVECO".', required: true),
                    new PromptArgument('model', 'Vehicle model, e.g. "DAILY".', required: true),
                    new PromptArgument('vehicleType', 'Segment: car | moto | cv | groundscare. Defaults to "car".', required: false),
                    new PromptArgument('type', 'What was done, e.g. "oil_change". Ask me if unset.', required: false),
                ],
            ),
            self::logService(...),
        );

        $registry->registerPrompt(
            new Prompt(
                name: 'parts_for_service',
                title: 'Parts for a service',
                description: 'List the parts and fluids needed for a service (e.g. an oil change) on a vehicle, with TecDoc references.',
                arguments: [
                    new PromptArgument('plate', 'French registration plate, e.g. "XB-301-ZB". Resolves the carId. Omit if you pass make+model.', required: false),
                    new PromptArgument('make', 'Vehicle make, e.g. "MAZDA". Use with model when no plate.', required: false),
                    new PromptArgument('model', 'Vehicle model, e.g. "MAZDA6". Use with make when no plate.', required: false),
                    new PromptArgument('service', 'Service to prepare, e.g. "oil_change". Defaults to an oil change.', required: false),
                ],
            ),
            self::partsForService(...),
        );
    }

    /**
     * @return list<PromptMessage>
     */
    private static function partsForService(?string $plate = null, ?string $make = null, ?string $model = null, ?string $service = null): array
    {
        $svc = null !== $service && '' !== $service ? $service : 'oil_change';
        $idLine = null !== $plate && '' !== $plate
            ? \sprintf('Resolve the vehicle from plate "%s" with lookup_plate to get its carId.', $plate)
            : (null !== $make && '' !== $make
                ? \sprintf('The vehicle is %s %s; get its carId via lookup_plate (if you have a plate) or list_cars.', $make, $model ?? '')
                : 'First ask me for the vehicle (plate, or make+model).');

        return self::user(<<<PROMPT
            Prepare the parts and fluids for a {$svc} on my vehicle. Run every step:
            1. Get the carId. {$idLine}
            2. get_vehicle_facts ⇒ oil_capacity; get_motul_oil ⇒ the oil grade (SAE viscosity).
            3. list_part_categories(carId) then list_articles ⇒ the oil filter ref.
            4. list_articles again ⇒ the drain-plug gasket ref.
            A parts question is NOT answered until steps 3 and 4 have run — never reply with fluids alone. Then present a single shopping list (part references with brand + number, and the oil type + quantity). Do not invent references the catalog did not return.
            PROMPT);
    }

    /**
     * @return list<PromptMessage>
     */
    private static function listVehicles(?string $make = null, ?string $model = null, ?string $vehicleType = null): array
    {
        $args = [];
        if (null !== $make && '' !== $make) {
            $args[] = \sprintf('make="%s"', $make);
        }
        if (null !== $model && '' !== $model) {
            $args[] = \sprintf('model="%s"', $model);
        }
        if (null !== $vehicleType && '' !== $vehicleType) {
            $args[] = \sprintf('vehicleType="%s"', $vehicleType);
        }
        $argLine = [] === $args ? 'no arguments (the whole garage)' : implode(', ', $args);

        return self::user(<<<PROMPT
            Show me the vehicles in my garage. Run every step:
            1. Call list_vehicles with {$argLine}.
            2. List each vehicle with its make, model, year and type, plus its factCount.
            3. If the result is empty, say the garage has no matching vehicles yet and offer to add one with identify_plate or fill_vehicle_info.
            PROMPT);
    }

    /**
     * @return list<PromptMessage>
     */
    private static function whatsDue(?string $withinDays = null, ?string $make = null): array
    {
        $args = [];
        if (null !== $withinDays && '' !== $withinDays) {
            $args[] = \sprintf('withinDays=%s', $withinDays);
        }
        if (null !== $make && '' !== $make) {
            $args[] = \sprintf('make="%s"', $make);
        }
        $argLine = [] === $args ? 'no arguments (whole garage, all upcoming)' : implode(', ', $args);

        return self::user(<<<PROMPT
            Tell me what maintenance is due. Run every step:
            1. Call list_due_maintenance with {$argLine}.
            2. List the overdue services first (most overdue at the top), then the upcoming ones — each with the vehicle, the service and its due date.
            3. Mention any "gaps" (recurring services with no logged history) so I can fill them.
            4. For any service that also recurs by distance/hours, ask me the vehicle's current km/hours before concluding it is or is not due.
            PROMPT);
    }

    /**
     * @return list<PromptMessage>
     */
    private static function nextToMaintain(): array
    {
        return self::user(<<<PROMPT
            What is the next vehicle I should maintain? Run every step:
            1. Call list_due_maintenance with limit=1 to get the single soonest-due (or most overdue) service across the garage.
            2. Tell me the vehicle, the service, its due date and how overdue it is.
            3. If the result is empty, say nothing is forecast yet and that I may need to log past services first.
            4. Offer to log the work with log_maintenance once I have done it.
            PROMPT);
    }

    /**
     * @return list<PromptMessage>
     */
    private static function identifyPlate(string $plate): array
    {
        return self::user(<<<PROMPT
            Identify the vehicle with French plate "{$plate}". Run every step:
            1. Call lookup_plate (plate="{$plate}").
            2. Present its identity and technical data (make, model, engine code, fuel, power, the TecDoc carId/carType).
            3. Identifying a plate saves the vehicle to my garage so I can track it: record its useful, stable specs (engine code, fuel type) with set_vehicle_facts (vehicle = its @id from list_vehicles, facts:[{key, value, source}]).
            Do not invent values the lookup did not return.
            PROMPT);
    }

    /**
     * @return list<PromptMessage>
     */
    private static function logService(string $make, string $model, ?string $vehicleType = null, ?string $type = null): array
    {
        $segment = $vehicleType ?? 'car';
        $vehicle = \sprintf('%s %s (%s)', $make, $model, $segment);
        $typeLine = null !== $type && '' !== $type
            ? \sprintf('The service was: %s.', $type)
            : 'First ask me what service was performed (oil_change, oil_filter, tires, inspection, repair…; use "other" with a label for anything else).';

        return self::user(<<<PROMPT
            I just performed a service on {$vehicle}. {$typeLine}
            Run every step:
            1. Ask me for the details: the date it was performed (ISO 8601), the odometer or hour reading then (with unit — use "hours" for engine-hour machinery), the cost and currency, who did it, and any parts used.
            2. Record it with log_maintenance (make="{$make}", model="{$model}", vehicleType="{$segment}").
            3. Confirm afterwards with get_maintenance_history.
            Do not invent details I do not provide.
            PROMPT);
    }

    /**
     * @return list<PromptMessage>
     */
    private static function user(string $text): array
    {
        return [new PromptMessage(Role::User, new TextContent($text))];
    }
}
