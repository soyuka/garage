<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\CasdoorUserProvider;
use App\Tests\Functional\AppTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Events;

final class CasdoorUserProviderTest extends AppTestCase
{
    private int $flushes = 0;

    public function testAKnownUserWithUnchangedClaimsIsNotWrittenAgain(): void
    {
        $provider = $this->provider();

        $provider->loadUserByIdentifier('alice@example.com', ['email' => 'alice@example.com']);
        $this->assertSame(1, $this->flushes, 'the first sight provisions the user');

        $provider->loadUserByIdentifier('alice@example.com', ['email' => 'alice@example.com']);
        $this->assertSame(1, $this->flushes, 'an authenticated request must not write when nothing changed');
    }

    public function testAChangedEmailIsWritten(): void
    {
        $provider = $this->provider();
        $provider->loadUserByIdentifier('bob-sub', ['email' => 'bob@example.com']);

        $user = $provider->loadUserByIdentifier('bob-sub', ['email' => 'bob@new.example.com']);

        $this->assertSame(2, $this->flushes);
        $this->assertSame('bob@new.example.com', $user->getEmail());
    }

    public function testRolesDoNotComeFromTokenClaims(): void
    {
        $user = $this->provider()->loadUserByIdentifier('carol-sub', [
            'email' => 'carol@example.com',
            'urn:zitadel:iam:org:project:roles' => ['Admin' => ['org' => 'x']],
            'roles' => ['ROLE_ADMIN'],
        ]);

        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    private function provider(): CasdoorUserProvider
    {
        self::bootKernel();
        $container = static::getContainer();
        $container->get(DocumentManager::class)->getEventManager()->addEventListener(Events::postFlush, $this);

        return $container->get(CasdoorUserProvider::class);
    }

    public function postFlush(): void
    {
        ++$this->flushes;
    }
}
