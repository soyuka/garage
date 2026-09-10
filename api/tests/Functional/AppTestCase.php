<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use MongoDB\Client;
use Psr\Cache\CacheItemPoolInterface;

abstract class AppTestCase extends ApiTestCase
{
    /**
     * The default tenant the whole suite runs as. Matches the OwnerContext `$fallback` bound in the
     * test env (config/services.yaml when@test), so a write seeded in-process and an anonymous read
     * resolve to the same owner. {@see authHeaders()} and {@see McpClientTrait} issue tokens for it.
     */
    public const string TEST_OWNER = 'test-user';

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

        $url = $_ENV['MONGODB_URL'] ?? $_SERVER['MONGODB_URL'];
        $db = $_ENV['MONGODB_DB'] ?? $_SERVER['MONGODB_DB'];

        // Defence in depth: never drop a database whose name is not a test database. The container
        // exports MONGODB_DB=app (the live garage), so a misconfigured bootstrap that let `app`
        // through here would wipe real data on the very first setUp. tests/bootstrap.php forces the
        // `_test` suffix; this refuses to run if that ever fails to apply.
        if (!str_ends_with($db, '_test')) {
            throw new \RuntimeException(sprintf('Refusing to drop non-test database "%s" — the test DB name must end in "_test".', $db));
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
     * The two upstream caches now live in PostgreSQL; DAMA wraps every test in a
     * rolled-back transaction, so cache rows written by one test never leak into
     * the next — no manual wipe needed.
     */
    /**
     * Authorization header for a write request against the `api` firewall (^/garage, ^/agent/turn).
     * The token is decoded by {@see \App\Tests\Security\FakeOidcTokenHandler}; the firewall,
     * ZitadelUserProvider and find-or-create all run for real. Merge into a request's headers.
     *
     * @return array{Authorization: string}
     */
    protected static function authHeaders(string $sub = self::TEST_OWNER, string $email = 'dashboard@example.com'): array
    {
        return ['Authorization' => 'Bearer '.FakeOidcToken::for($sub, $email)];
    }

    protected static function sivCache(): CacheItemPoolInterface
    {
        return static::getContainer()->get('cache.siv');
    }

    protected static function partsApiCache(): CacheItemPoolInterface
    {
        return static::getContainer()->get('cache.partsapi');
    }
}
