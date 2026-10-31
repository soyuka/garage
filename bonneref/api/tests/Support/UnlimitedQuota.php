<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\OwnerContext;
use App\Security\OwnerRateLimiter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class UnlimitedQuota
{
    public static function create(): OwnerRateLimiter
    {
        return new OwnerRateLimiter(
            new RateLimiterFactory(['id' => 'unlimited', 'policy' => 'no_limit'], new InMemoryStorage()),
            new OwnerContext(new Security(self::securityContainer())),
        );
    }

    private static function securityContainer(): Container
    {
        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());

        return $container;
    }
}
