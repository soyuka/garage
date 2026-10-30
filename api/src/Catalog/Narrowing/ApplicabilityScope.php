<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

enum ApplicabilityScope: string
{
    /** Cluster-level breakpoints, the union over every vehicle each member fits: all partsapi can tell. */
    case Article = 'article';

    /** Per-variant conditions, read from that article's own link to the narrowed car. */
    case Vehicle = 'vehicle';
}
