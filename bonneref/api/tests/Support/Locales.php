<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\I18n\Locale;
use App\I18n\LocaleContext;
use Symfony\Component\HttpFoundation\RequestStack;

final class Locales
{
    /** A locale seam bound to one language, for tests about that language's content. */
    public static function context(Locale $locale = Locale::Fr): LocaleContext
    {
        $context = new LocaleContext(new RequestStack());
        $context->set($locale);

        return $context;
    }
}
