<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\AgentTurnRepository;
use Doctrine\ODM\MongoDB\DocumentManager;

final class AnonymousCatalogTokenTest extends AppTestCase
{
    private const string VIN = '/vins/VF33CKFWB83471925';

    public function testAnInvalidTokenOnACatalogReadGets401(): void
    {
        static::createClient()->request('GET', self::VIN, [
            'headers' => ['Authorization' => 'Bearer not-a-valid-token', 'Search-Rule' => 'vin', 'Search-Input' => 'VF33CKFWB83471925'],
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->journal());
    }

    public function testAValidTokenOnACatalogReadIsJournaled(): void
    {
        static::createClient()->request('GET', self::VIN, [
            'headers' => self::authHeaders() + ['Search-Rule' => 'vin', 'Search-Input' => 'VF33CKFWB83471925'],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->journal());
    }

    public function testAnInvalidTokenOnAnOwnerScopedRouteStillGets401(): void
    {
        static::createClient()->request('POST', '/agent/turn', [
            'headers' => ['Authorization' => 'Bearer not-a-valid-token'],
            'json' => ['instruction' => 'hello'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return list<object>
     */
    private function journal(): array
    {
        static::getContainer()->get(DocumentManager::class)->clear();

        return static::getContainer()->get(AgentTurnRepository::class)->findForOwner(self::TEST_OWNER, 10);
    }
}
