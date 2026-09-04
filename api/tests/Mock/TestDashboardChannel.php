<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Agent\Ui\DashboardChannel;
use App\I18n\LocaleContext;
use App\Security\OwnerContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * A {@see DashboardChannel} for unit tests: no container, no request, no token — just the owner
 * binding the worker would carry, so {@see \App\Agent\Ui\RenderIntentTools} has somewhere to publish.
 */
final class TestDashboardChannel
{
    public static function create(?string $sessionTopic = null, string $owner = 'test-user'): DashboardChannel
    {
        $context = new OwnerContext(new Security(new ServiceLocator(['security.token_storage' => static fn (): TokenStorage => new TokenStorage()])));
        $context->set($owner);

        $channel = new DashboardChannel($context, 'https://localhost', 'test-secret');
        $channel->bindSession($sessionTopic);

        return $channel;
    }

    /**
     * A {@see LocaleContext} for unit tests: no request in flight, so it reads as {@see
     * \App\I18n\Locale::DEFAULT} unless a test explicitly calls {@see LocaleContext::set()} on it —
     * exactly the shape {@see \App\Agent\Ui\RenderIntentTools}'s speech prewarm reads locale from.
     */
    public static function locale(): LocaleContext
    {
        return new LocaleContext(new RequestStack());
    }
}
