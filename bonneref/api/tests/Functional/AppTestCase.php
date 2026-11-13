<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client as ApiClient;
use App\Siv\SivLookupStore;
use MongoDB\Client;

abstract class AppTestCase extends ApiTestCase
{
    /**
     * The default tenant the whole suite runs as. Matches the OwnerContext `$fallback` bound in the
     * test env (config/services.yaml when@test), so a write seeded in-process and an anonymous read
     * resolve to the same owner. {@see authHeaders()} and {@see McpClientTrait} issue tokens for it.
     */
    public const string TEST_OWNER = 'dashboard@example.com';

    /** Symfony's request factory defaults a test request to `en-us`; the app's own default is French. */
    private const array DEFAULT_LANGUAGE_HEADERS = ['Accept-Language' => 'fr'];

    protected static ?bool $alwaysBootKernel = true;

    /**
     * The domain lives in MongoDB, which has no DAMA-style transactional rollback. Drop the test
     * database before each test so writes from one test never leak into the next — the Mongo
     * counterpart of the rolled-back DBAL transaction the cache pools get for free.
     *
     * Done through a standalone driver client, NOT the app's DocumentManager: booting the kernel
     * here (static::getContainer()) before the test's own createClient() corrupts the BrowserKit
     * kernel lifecycle and writes are lost. A raw client keeps isolation fully decoupled from the
     * kernel under test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $url = $_ENV['MONGODB_URL'] ?? $_SERVER['MONGODB_URL'];
        $db = $_ENV['MONGODB_DB'] ?? $_SERVER['MONGODB_DB'];

        // Defence in depth: never drop a database whose name is not a test database. The container
        // exports MONGODB_DB=bonneref (the live data), so a misconfigured bootstrap that let it
        // through here would wipe real data on the very first setUp. tests/bootstrap.php forces the
        // `_test` suffix; this refuses to run if that ever fails to apply.
        if (!str_ends_with($db, '_test')) {
            throw new \RuntimeException(sprintf('Refusing to drop non-test database "%s" — the test DB name must end in "_test".', $db));
        }

        (new Client($url))->dropDatabase($db);
    }

    /**
     * Production is deny-by-default (config/packages/security.yaml), so every client is authenticated
     * as {@see self::TEST_OWNER} unless the request carries its own Authorization header, which wins
     * over this default (an owner-scoping test keeps its own token). Use {@see createAnonymousClient()}
     * to pin anonymous behaviour.
     *
     * @param array<mixed> $kernelOptions
     * @param array<mixed> $defaultOptions
     */
    protected static function createClient(array $kernelOptions = [], array $defaultOptions = []): ApiClient
    {
        $defaultOptions['headers'] = ($defaultOptions['headers'] ?? []) + self::authHeaders() + self::DEFAULT_LANGUAGE_HEADERS;

        return parent::createClient($kernelOptions, $defaultOptions);
    }

    /**
     * A client with no default credentials: the explicit opt-out for tests that assert what an
     * unauthenticated caller gets.
     *
     * @param array<mixed> $kernelOptions
     */
    protected static function createAnonymousClient(array $kernelOptions = []): ApiClient
    {
        return parent::createClient($kernelOptions, ['headers' => self::DEFAULT_LANGUAGE_HEADERS]);
    }

    /**
     * Authorization header for a write request against the `api` firewall (^/garage, ^/agent/turn).
     * The token is decoded by {@see \App\Tests\Security\FakeOidcTokenHandler}; the firewall,
     * CasdoorUserProvider and find-or-create all run for real. Merge into a request's headers.
     *
     * @return array{Authorization: string}
     */
    protected static function authHeaders(string $sub = 'test-user', string $email = self::TEST_OWNER): array
    {
        return ['Authorization' => 'Bearer '.FakeOidcToken::for($sub, $email)];
    }

    private ?string $memoryLimitBefore = null;

    /**
     * Raises the memory limit for one test and never lowers it: CI runs the suite at 1G, and a bare
     * ini_set('memory_limit', '512M') there cut the limit for every later test until the suite ran out.
     */
    protected function raiseMemoryLimit(string $limit): void
    {
        $current = (string) ini_get('memory_limit');
        if ('-1' === $current || self::bytes($current) >= self::bytes($limit)) {
            return;
        }

        $this->memoryLimitBefore ??= $current;
        // @mago-expect lint:no-ini-set raised for one test, restored in tearDown()
        ini_set('memory_limit', $limit);
    }

    protected function tearDown(): void
    {
        if (null !== $this->memoryLimitBefore) {
            // @mago-expect lint:no-ini-set restores the limit raiseMemoryLimit() changed
            ini_set('memory_limit', $this->memoryLimitBefore);
            $this->memoryLimitBefore = null;
        }

        parent::tearDown();
    }

    private static function bytes(string $limit): int
    {
        $value = (int) $limit;

        return match (strtoupper(substr($limit, -1))) {
            'G' => $value * 1024 ** 3,
            'M' => $value * 1024 ** 2,
            'K' => $value * 1024,
            default => $value,
        };
    }

    /** Lives in the test Mongo database, so the per-test drop above isolates it too. */
    protected static function sivStore(): SivLookupStore
    {
        return static::getContainer()->get(SivLookupStore::class);
    }
}
