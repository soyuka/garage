<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\OwnerContext;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class OwnerContextTest extends TestCase
{
    public function testResetForgetsTheBoundOwner(): void
    {
        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());
        $owner = new OwnerContext(new Security($container));

        $owner->set('alice@example.com');
        $owner->reset();

        $this->assertNull($owner->current());
    }
}
