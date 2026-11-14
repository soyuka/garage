<?php

declare(strict_types=1);

namespace App\I18n;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Carries the interface language across the invocation boundaries that do NOT thread it explicitly —
 * the render-intent tools, {@see \App\Agent\Ui\StepNarrator} and the async agent worker.
 *
 * Deliberately the same shape as {@see \App\Security\OwnerContext}, and for the same reason: a value
 * that belongs to the caller has to reach services constructed once, and the two answers come from
 * two different places. On an HTTP request the request wins — Symfony has already resolved it from
 * `Accept-Language` (framework.yaml `set_locale_from_accept_language`), so the dashboard's choice
 * arrives without any payload field. The agent worker has no request, so {@see self::set()} carries
 * the locale from the turn message; it is read only when no request is in flight.
 *
 * Total, never throwing: an unsupported language folds onto {@see Locale::DEFAULT} rather than
 * failing a turn over a header.
 */
final class LocaleContext implements ResetInterface
{
    private ?Locale $override = null;

    public function __construct(private readonly RequestStack $requests)
    {
    }

    /** Bind the locale explicitly (the worker, from the turn message). Ignored while a request is live. */
    public function set(?Locale $locale): void
    {
        $this->override = $locale;
    }

    public function reset(): void
    {
        $this->override = null;
    }

    public function current(): Locale
    {
        $request = $this->requests->getMainRequest();

        return null !== $request ? Locale::resolve($request->getLocale()) : ($this->override ?? Locale::DEFAULT);
    }
}
