<?php

declare(strict_types=1);

namespace App\Transcription;

use App\I18n\Locale;
use App\Repository\VehicleRepository;

/**
 * The words this owner is likely to say, handed to the transcriber as bias.
 *
 * Why it exists: the measured error class of every transcriber tested is **proper nouns and
 * identifiers**, not grammar — the local route spelled the operator's own Mazda "Masda", heard
 * « Clio » as « l'acquillot », and no model size fixed « plaquettes de frein avant »
 * (docs/dictation-plan.md § Measured). Those are precisely the words this app already knows, because
 * they are rows in the operator's garage. This is the one place a garage-specific input path can beat
 * a generic one.
 *
 * It also makes `POST /stt` an owner-SCOPED read, which it was not before: {@see VehicleRepository}
 * scopes every query to the current {@see \App\Security\OwnerContext}, so one tenant's vehicle names
 * can never ride along on another's request — which would leak their garage to the vendor AND bias
 * this operator's words toward cars they do not own.
 *
 * Not wrapped in a try/catch on purpose. Every authenticated request already reads Mongo to resolve
 * its user, so a database outage is not a thing this endpoint alone can degrade around, and swallowing
 * a real failure here would turn a broken read into a silently worse transcript.
 */
final readonly class GarageVocabulary
{
    /**
     * Most-recently-touched vehicles only ({@see VehicleRepository::findShared()} sorts that way), for
     * two reasons: the cars being worked on are the ones about to be dictated, and an unbounded read
     * on every dictation is a cost with no ceiling. Thirty leaves ample room under the 100-term cap
     * for the generic vocabulary below in any realistic parc.
     */
    private const int VEHICLES = 30;

    /**
     * The trade the operator's own words never make: these are generic, so they sit AFTER the garage
     * in priority and are the first thing the cap drops. Each entry respects the vendor's 5-word,
     * 50-character limit. Viscosity grades are locale-independent — « cinq W trente » came back as
     * `5W 30`, `5W30` and `5W-30` from three different models, and the printed form is the one the
     * catalogue uses.
     */
    private const array GRADES = ['5W-30', '5W-40', '0W-20', '10W-40', '15W-40'];

    private const array MAINTENANCE = [
        'fr' => [
            'vidange', 'filtre à huile', 'filtre à air', 'filtre à carburant', 'filtre à particules',
            'plaquettes de frein', 'disques de frein', 'liquide de frein', 'courroie de distribution',
            'bougies d’allumage', 'contrôle technique', 'révision', 'kilométrage', 'amortisseurs',
            'pneus', 'batterie', 'embrayage', 'échéance',
        ],
        'en' => [
            'oil change', 'oil filter', 'air filter', 'fuel filter', 'particulate filter',
            'brake pads', 'brake discs', 'brake fluid', 'timing belt', 'spark plugs',
            'roadworthiness test', 'service', 'mileage', 'shock absorbers',
            'tyres', 'battery', 'clutch', 'due date',
        ],
    ];

    public function __construct(
        private VehicleRepository $vehicles,
    ) {
    }

    /**
     * @return list<string> empty when the owner has no vehicles — and empty means the caller sends NO
     *                      `keyterms` field at all, because the vendor charges +20 % whenever one is
     *                      present and a garage with nothing in it has no vocabulary worth biasing
     *                      toward. The generic list alone does not earn a fifth of every bill
     */
    public function terms(Locale $locale): array
    {
        $vehicles = $this->vehicles->findShared(limit: self::VEHICLES);

        if ([] === $vehicles) {
            return [];
        }

        $own = [];
        foreach ($vehicles as $vehicle) {
            $own[] = $vehicle->getMake();
            $own[] = $vehicle->getModel();
            // A plate dictated aloud is a letter-digit sequence — the single most manglable thing an
            // operator can say, and one this app knows exactly.
            if (null !== ($plate = $vehicle->getPlate()) && '' !== trim($plate)) {
                $own[] = $plate;
            }
        }

        return Keyterms::normalize([...$own, ...self::GRADES, ...self::MAINTENANCE[$locale->value]]);
    }
}
