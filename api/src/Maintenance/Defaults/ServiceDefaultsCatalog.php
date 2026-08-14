<?php

declare(strict_types=1);

namespace App\Maintenance\Defaults;

use App\Garage\VehicleType;
use App\Maintenance\MaintenanceAction;
use App\Maintenance\UsageUnit;

/**
 * The reviewed catalogue of GENERIC service cadences — the "general info" a mechanic knows before
 * ever opening a manual. It exists so the agent stops web-searching a generic interval and writing
 * the answer onto a vehicle as if it were that vehicle's rule (a `timing_belt every 120 000 km`
 * rule was once stored for a chain-driven Mazda 6 — see {@see ServiceBaseline}).
 *
 * Precedence, highest first — a baseline is the LAST resort and never outranks anything:
 *   1. what the user stated;
 *   2. a per-vehicle rule from the manual / catalog / a web lookup ({@see \App\Entity\ServiceInterval});
 *   3. these baselines.
 *
 * It is REFERENCE DATA IN CODE, not a Mongo collection: every figure here is a claim about the
 * physical world that must go through code review with its source and review date, and be diffable
 * over time. PHP rather than a YAML file read at runtime because the entries are typed — the
 * segments, the action and the usage unit are enums, so a typo is a load-time fatal caught by any
 * test run instead of a runtime TypeError surfacing to the model mid tool-call, and the constructor
 * is the schema (no separate validator to keep in sync).
 *
 * MOST ENTRIES ARE UNSOURCED general knowledge ({@see self::GENERAL}): the figure is the widely
 * published trade consensus but carries no citable URL, so the tool result flags it and the model
 * must caveat it. Never pad this file with a number you cannot attribute or defend.
 */
final class ServiceDefaultsCatalog
{
    /** Trade consensus with no citable URL — advisory, always caveat it. */
    public const string GENERAL = 'general_knowledge';

    /** A figure taken from a manufacturer's own published schedule; {@see ServiceBaseline::$sourceUrl} cites it. */
    public const string MANUFACTURER = 'manufacturer_schedule';

    private const string REVIEWED_ON = '2026-08-14';

    /** @var list<ServiceBaseline>|null */
    private ?array $baselines = null;

    /**
     * @return list<ServiceBaseline>
     */
    public function all(): array
    {
        return $this->baselines ??= $this->build();
    }

    /**
     * Every baseline registered for a service type, in declaration order. The type is the canonical
     * snake_case vocabulary the rest of the app keys on ({@see \App\Maintenance\MaintenanceTypeNormalizer}).
     *
     * @return list<ServiceBaseline>
     */
    public function forType(string $type): array
    {
        return array_values(array_filter($this->all(), static fn (ServiceBaseline $b): bool => $b->type === $type));
    }

    /**
     * @return list<string> the service types this catalogue covers
     */
    public function types(): array
    {
        return array_values(array_unique(array_map(static fn (ServiceBaseline $b): string => $b->type, $this->all())));
    }

    /**
     * Built in a method, not a class constant: constant initializers cannot contain `new`, and the
     * entries are typed objects on purpose (see the class docblock).
     *
     * @return list<ServiceBaseline>
     */
    private function build(): array
    {
        $road = [VehicleType::Car, VehicleType::CommercialVehicle];
        $engined = [VehicleType::Car, VehicleType::Moto, VehicleType::CommercialVehicle];

        return [
            // --- distribution ---------------------------------------------------------------
            // Spelled `timing_belt` because that is the canonical type the normalizer folds
            // "distribution"/"courroie de distribution" onto and the forecast keys on — but the pair
            // below covers the whole distribution system, chain included. That naming tension is
            // exactly the trap: asking for "timing_belt" must NOT imply the vehicle has a belt.
            new ServiceBaseline(
                type: 'timing_belt',
                action: MaintenanceAction::Replace,
                segments: $road,
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                discriminantKey: 'distribution_type',
                discriminantValues: ['belt', 'courroie'],
                everyUsageValue: 120000,
                everyUsageUnit: UsageUnit::Kilometers,
                everyMonths: 60,
                note: 'Whichever comes first — a belt ages out on a garage queen. Manufacturer figures spread very wide (60 000 to 240 000 km) and some engines pair the belt with the water pump, so the owner-manual rule always outranks this.',
            ),
            new ServiceBaseline(
                type: 'timing_belt',
                action: MaintenanceAction::Replace,
                segments: $road,
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                discriminantKey: 'distribution_type',
                discriminantValues: ['chain', 'chaine'],
                note: 'A timing CHAIN has NO replacement interval: it is a lifetime part, inspected for stretch/rattle and replaced on symptom, never on a schedule. Writing a km or month rule for a chain is the exact mistake this catalogue exists to prevent.',
            ),

            // --- ignition -------------------------------------------------------------------
            new ServiceBaseline(
                type: 'spark_plug',
                action: MaintenanceAction::Replace,
                segments: $engined,
                source: self::MANUFACTURER,
                reviewedOn: self::REVIEWED_ON,
                discriminantKey: 'spark_plug_material',
                discriminantValues: ['iridium', 'platinum'],
                everyUsageValue: 100000,
                everyUsageUnit: UsageUnit::Kilometers,
                sourceUrl: 'https://owners-manual.mazda.com/gen/en/mazda6/mazda6_8gk3ee18c/contents/07020102.html',
                note: 'Long-life electrode. NO month axis: a plug wears by combustion cycles, not by the calendar. The cited Mazda schedule publishes 120 000 km / 6 years for its naturally-aspirated petrol engines (the 2.5 turbo is the exception at 60 000 km); 100 000 km is the conservative generic figure across makes.',
            ),
            new ServiceBaseline(
                type: 'spark_plug',
                action: MaintenanceAction::Replace,
                segments: $engined,
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                discriminantKey: 'spark_plug_material',
                discriminantValues: ['nickel', 'copper', 'cuivre'],
                everyUsageValue: 40000,
                everyUsageUnit: UsageUnit::Kilometers,
                note: 'Conventional electrode: 40 000 to 60 000 km depending on the engine — the conservative end is encoded. NO month axis, same reason as the long-life entry. Fitting nickel plugs where the maker specified iridium shortens the interval by more than half, so the material must be known before quoting a number.',
            ),

            // --- fluids ---------------------------------------------------------------------
            new ServiceBaseline(
                type: 'brake_fluid',
                action: MaintenanceAction::Replace,
                segments: $engined,
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                everyMonths: 24,
                note: 'Genuinely time-based, with NO usage axis: DOT 3/4/5.1 fluid is hygroscopic and absorbs atmospheric moisture through the hoses and reservoir, so its wet boiling point falls whether the vehicle is driven or parked.',
            ),
            new ServiceBaseline(
                type: 'coolant',
                action: MaintenanceAction::Replace,
                segments: $engined,
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                discriminantKey: 'coolant_type',
                discriminantValues: ['long-life', 'longlife', 'oat', 'hoat', 'g12', 'g13'],
                everyMonths: 60,
                note: 'Organic-acid (OAT/HOAT) coolant. Time axis only — the inhibitor package depletes chemically, not by distance.',
            ),
            new ServiceBaseline(
                type: 'coolant',
                action: MaintenanceAction::Replace,
                segments: $engined,
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                discriminantKey: 'coolant_type',
                discriminantValues: ['conventional', 'iat', 'g11'],
                everyMonths: 24,
                note: 'Conventional (silicate/IAT) coolant. Topping a long-life system up with conventional coolant drops it to this interval, so the type in the system is what counts, not the type on the invoice.',
            ),
            new ServiceBaseline(
                type: 'oil_change',
                action: MaintenanceAction::Change,
                segments: $road,
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                everyUsageValue: 15000,
                everyUsageUnit: UsageUnit::Kilometers,
                everyMonths: 12,
                note: 'Modern petrol/diesel car on a long-life service oil: 15 000 to 20 000 km or 12 months, whichever first — the conservative end is encoded. Severe use (short cold trips, towing, dust, taxi work) roughly halves it, and the engine\'s own oil specification is a separate question (get_vehicle_facts / get_motul_oil).',
            ),

            // --- filters --------------------------------------------------------------------
            new ServiceBaseline(
                type: 'fuel_filter',
                action: MaintenanceAction::Replace,
                segments: $road,
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                discriminantKey: 'fuel_type',
                discriminantValues: ['diesel', 'gazole', 'hdi', 'tdi'],
                everyUsageValue: 40000,
                everyUsageUnit: UsageUnit::Kilometers,
                note: 'Diesel: 40 000 to 60 000 km — the conservative end is encoded. Not optional; a clogged filter starves the high-pressure pump, which is the expensive part.',
            ),
            new ServiceBaseline(
                type: 'fuel_filter',
                action: MaintenanceAction::Replace,
                segments: $road,
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                discriminantKey: 'fuel_type',
                discriminantValues: ['petrol', 'gasoline', 'essence'],
                note: 'Most modern petrol cars carry the filter INSIDE the tank, integrated with the pump module: it is a lifetime part with NO scheduled replacement interval. Quoting the diesel figure here invents work that does not exist — a real trap, because the service type is the same word.',
            ),
            new ServiceBaseline(
                type: 'air_filter',
                action: MaintenanceAction::Replace,
                segments: $road,
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                everyUsageValue: 40000,
                everyUsageUnit: UsageUnit::Kilometers,
                everyMonths: 24,
                note: 'Whichever comes first. Dusty or unsealed-road use shortens it sharply; inspection is cheap and beats the calendar.',
            ),
            new ServiceBaseline(
                type: 'cabin_filter',
                action: MaintenanceAction::Replace,
                segments: $road,
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                everyUsageValue: 40000,
                everyUsageUnit: UsageUnit::Kilometers,
                everyMonths: 24,
                note: 'Whichever comes first. Heavy pollen or urban traffic halves it in practice; a smell at the vents is the real signal.',
            ),

            // --- groundscare ----------------------------------------------------------------
            // Hour-metered machinery: a distance cadence is meaningless here, which is why the
            // segment scoping is part of the catalogue and not an afterthought.
            new ServiceBaseline(
                type: 'oil_change',
                action: MaintenanceAction::Change,
                segments: [VehicleType::GroundsCare],
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                everyUsageValue: 50,
                everyUsageUnit: UsageUnit::Hours,
                note: 'Small air-cooled engine, engine-hour axis. Makers also want a FIRST change after roughly 5 hours of break-in — that belongs on the vehicle\'s own rule (set_maintenance_schedule firstUsageValue), not in a generic baseline.',
            ),
            new ServiceBaseline(
                type: 'spark_plug',
                action: MaintenanceAction::Replace,
                segments: [VehicleType::GroundsCare],
                source: self::GENERAL,
                reviewedOn: self::REVIEWED_ON,
                everyUsageValue: 100,
                everyUsageUnit: UsageUnit::Hours,
                note: 'Small air-cooled engines run conventional plugs universally, so no material discriminant applies at this scale — the electrode question is a car question.',
            ),
        ];
    }
}
