<?php

declare(strict_types=1);

namespace App\Agent\Finder;

/**
 * Why the page handed a finder input to the agent rather than resolving it by rule: free text, a
 * string shaped like a part reference, or a part photo.
 */
enum FinderIntent: string
{
    case Text = 'text';
    case Reference = 'reference';
    case Photo = 'photo';
}
