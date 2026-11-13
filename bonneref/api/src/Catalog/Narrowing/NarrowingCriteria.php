<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

/**
 * The TecDoc criteria the narrowing decides on, by criterion id: a name is translated, an id is not. Every id was
 * read off `criterion_text` and counted against the full catalogue, see docs/tecdoc-sqlite.md § "Narrowing criterion ids".
 */
final class NarrowingCriteria
{
    /** Year, VIN/chassis, organisation (RPO/OPR) and serial number breakpoints: where an article applies, never what it is. */
    public const array BREAKPOINTS = [
        20, 21, 22, 1320, 1321,
        25, 26, 27,
        45, 46, 710,
        144, 145, 146, 749,
    ];

    /** Breakpoints plus the other qualifiers of an application, which can never tell two parts apart. */
    public const array APPLICATION_ONLY = [
        ...self::BREAKPOINTS,
        33,
        14,
        1361,
    ];

    /** Unit-less criteria whose value is a physical key of the part. */
    public const array DISCRETE = [
        826,
        1059,
        100,
        453, 544, 741,
    ];

    /** Thread criteria, by the index of the thread they describe. */
    public const array THREADS = [
        289 => 1,
        991 => 1,
        992 => 2,
        1933 => 3,
    ];

    /**
     * The measures that tell two parts apart, by the key the fingerprint spells them with. The keys are the French
     * names the front has always read (src/variants.ts and src/question.ts match on them): identifiers, not labels.
     */
    public const array MEASURES = [
        209 => 'hauteur', 210 => 'hauteur', 211 => 'hauteur',
        497 => 'diametre_exterieur', 200 => 'diametre_exterieur', 201 => 'diametre_exterieur', 202 => 'diametre_exterieur',
        979 => 'diametre_exterieur_de_la_bague_d_etancheite',
        996 => 'diametre_interieur_de_la_bague_d_etancheite', 297 => 'diametre_interieur_de_la_bague_d_etancheite',
    ];
}
