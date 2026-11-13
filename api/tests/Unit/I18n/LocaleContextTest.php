<?php

declare(strict_types=1);

namespace App\Tests\Unit\I18n;

use App\I18n\Locale;
use App\I18n\LocaleContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The locale seam, shaped exactly like {@see \App\Security\OwnerContext}: an HTTP request answers for
 * itself, and the async worker — which has no request — carries the value on the turn message.
 */
final class LocaleContextTest extends TestCase
{
    public function testFallsBackToEnglishWithNeitherRequestNorBinding(): void
    {
        $this->assertSame(Locale::En, (new LocaleContext(new RequestStack()))->current());
    }

    public function testAnHttpRequestAnswersForItself(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/agent/turn');
        $request->setLocale('en');
        $stack->push($request);

        $this->assertSame(Locale::En, (new LocaleContext($stack))->current());
    }

    public function testTheWorkerBindingIsUsedWhenThereIsNoRequest(): void
    {
        $context = new LocaleContext(new RequestStack());
        $context->set(Locale::En);

        $this->assertSame(Locale::En, $context->current());
    }

    /**
     * Precedence mirrors OwnerContext: the live request wins. A worker binding left over from an
     * earlier turn must never colour a real request.
     */
    public function testARequestOutranksABinding(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/garage/vehicles');
        $request->setLocale('fr');
        $stack->push($request);

        $context = new LocaleContext($stack);
        $context->set(Locale::En);

        $this->assertSame(Locale::Fr, $context->current());
    }

    public function testAnUnsupportedRequestLocaleFoldsOntoTheDefault(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/garage/vehicles');
        $request->setLocale('de');
        $stack->push($request);

        $this->assertSame(Locale::En, (new LocaleContext($stack))->current());
    }
}
