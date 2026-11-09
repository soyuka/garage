<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client as ApiClient;
use App\Tests\Mock\BonnerefHttpClient;
use MongoDB\Client;
use Symfony\Component\HttpFoundation\Response;

abstract class AppTestCase extends ApiTestCase
{
    /**
     * The default tenant the whole suite runs as. Matches the OwnerContext `$fallback` bound in the
     * test env (config/services.yaml when@test), so a write seeded in-process and an anonymous read
     * resolve to the same owner. {@see authHeaders()} and {@see McpClientTrait} issue tokens for it.
     */
    public const string TEST_OWNER = 'dashboard@example.com';

    protected static ?bool $alwaysBootKernel = true;

    /**
     * The garage lives in MongoDB, which has no DAMA-style transactional rollback. Drop the test
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
        BonnerefHttpClient::reset();

        $url = $_ENV['MONGODB_URL'] ?? $_SERVER['MONGODB_URL'];
        $db = $_ENV['MONGODB_DB'] ?? $_SERVER['MONGODB_DB'];

        // Defence in depth: never drop a database whose name is not a test database. The container
        // exports MONGODB_DB=app (the live garage), so a misconfigured bootstrap that let `app`
        // through here would wipe real data on the very first setUp. tests/bootstrap.php forces the
        // `_test` suffix; this refuses to run if that ever fails to apply.
        if (!str_ends_with($db, '_test')) {
            throw new \RuntimeException(\sprintf('Refusing to drop non-test database "%s" — the test DB name must end in "_test".', $db));
        }

        (new Client($url))->dropDatabase($db);

        // The TTS disk cache (docs/voice-plan.md § Phase 2, config/services.yaml speech.caching.*)
        // has no DAMA-style rollback either: it is a real filesystem directory, shared by every test
        // in the run. Wipe it per test so a line cached by one test never masks the next test's
        // assertion on the outgoing vendor request (e.g. SpeechTest expects a vendor call on a text
        // another test already caused to be cached). Computed without booting the kernel, same
        // reason as the Mongo client above.
        //
        // The cache dir is namespaced per environment (`var/tts/%kernel.environment%`, fixed
        // 2026-09-06 — a shared path let this suite's fake voice list leak into the live cache), so
        // the actual test cache lives under `var/tts/test`. `var/tts` itself is only wiped of stray
        // FILES (never the `dev`/`prod`/`test` directories) in case something legacy still lands
        // there; it must never recurse into another environment's live cache.
        $ttsRoot = \dirname(__DIR__, 2).'/var/tts';
        foreach ([$ttsRoot, $ttsRoot.'/test'] as $ttsCache) {
            if (!is_dir($ttsCache)) {
                continue;
            }
            // scandir, not glob('*') — the voices sidecar is `.voices.<hash>.json`, a dotfile, and
            // shell-style glob('*') never matches those. That silently no-opped this whole sweep for
            // every voices cache entry since it was introduced; audio `.bin`/`.type` files (no leading
            // dot) were the only ones ever actually cleared.
            foreach (scandir($ttsCache) ?: [] as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $file = $ttsCache.'/'.$entry;
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * Authorization header for a write request against the `api` firewall (^/garage, ^/agent/turn).
     * The token is decoded by {@see \App\Tests\Security\FakeOidcTokenHandler}; the firewall,
     * CasdoorUserProvider and find-or-create all run for real. Merge into a request's headers.
     *
     * @return array{Authorization: string}
     */
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
        $defaultOptions['headers'] = ($defaultOptions['headers'] ?? []) + self::authHeaders();

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
        return parent::createClient($kernelOptions);
    }

    /**
     * @return array<string, string>
     */
    protected static function authHeaders(string $sub = 'test-user', string $email = self::TEST_OWNER): array
    {
        return ['Authorization' => 'Bearer '.FakeOidcToken::for($sub, $email)];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected static function serviceAs(string $id, string $class): object
    {
        $service = static::getContainer()->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    protected static function lastResponse(): Response
    {
        $response = static::getClient()?->getResponse();
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    protected static function bonneref(): BonnerefHttpClient
    {
        return static::getContainer()->get(BonnerefHttpClient::class);
    }

    /**
     * The Mazda 6 / Iveco Daily catalogue the suite shares, as bonneref answers it (fixtures captured from
     * the catalogue this API used to serve itself). An article route no fixture covers answers an empty
     * collection, the way a category without articles does.
     */
    protected static function catalogue(): BonnerefHttpClient
    {
        $http = static::bonneref();
        $empty = ['999999'];
        foreach (glob(\dirname(__DIR__).'/Fixtures/bonneref/categories_34247_*.json') ?: [] as $file) {
            /** @var list<array{id: int|string}> $members */
            $members = json_decode((string) file_get_contents($file), true)['hydra:member'];
            foreach ($members as $category) {
                $empty[] = (string) $category['id'];
            }
        }
        foreach (array_unique($empty) as $categoryId) {
            $http->fixture('/cars/34247/part-categories/'.$categoryId.'/articles', 'articles_34247_999999');
        }

        return $http->fixture('/makes', 'makes')
            ->fixture('/makes/72/models', 'models_72')
            ->fixture('/makes/72/models/4887/cars', 'cars_72_4887')
            ->fixture('/cars/34247/part-categories', 'categories_34247_leaf')
            ->fixture('/cars/34247/part-categories', 'categories_34247_all', ['leafOnly' => 'false'])
            ->fixture('/cars/34247/part-categories', 'categories_34247_filter', ['search' => 'filter'])
            ->fixture('/cars/34247/part-categories', 'categories_34247_filtre', ['search' => 'filtre'])
            ->fixture('/cars/34247/part-categories', 'categories_34247_filtre-a-huile', ['search' => 'filtre à huile'])
            ->fixture('/cars/34247/part-categories', 'categories_34247_oil-filter', ['search' => 'oil filter'])
            ->fixture('/cars/34247/part-categories/200158/articles', 'articles_34247_200158')
            ->fixture('/cars/34247/part-categories/200120/articles', 'articles_34247_200120')
            ->fixture('/cars/19107/part-categories/100259/narrowed', 'narrowed_19107_100259')
            ->fixture('/cars/16182/part-categories/100198/narrowed', 'narrowed_16182_100198')
            ->fixture('/cars/16182/part-categories/100198/narrowed', 'narrowed_16182_100198_pg51', ['productGroupId' => '51'])
            ->fixture('/vehicles/XC302ZC', 'vehicle_XC302ZC')
            ->route('/vehicles/ZZ999ZZ', '{}', 404);
    }
}
