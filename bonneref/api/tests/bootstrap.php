<?php

declare(strict_types=1);

use App\Tests\Support\TecdocFixtureDatabase;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// The compose containers export MONGODB_DB=bonneref as a REAL env var, which overrides .env.test's
// bonneref_test (Symfony Dotenv never overrides real env vars). AppTestCase drops MONGODB_DB on every
// setUp — so without this the suite would wipe the live database (it did once, in mpa). Force a
// dedicated, clearly-suffixed test database here, before the kernel boots, so BOTH the kernel under
// test and the dropDatabase target it regardless of how MONGODB_DB was provided.
if ('test' === ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null)) {
    $db = $_SERVER['MONGODB_DB'] ?? $_ENV['MONGODB_DB'] ?? 'bonneref';
    if (!str_ends_with($db, '_test')) {
        $db .= '_test';
    }
    $_ENV['MONGODB_DB'] = $db;
    $_SERVER['MONGODB_DB'] = $db;
}

// The catalogue the functional suite reads: the TecDoc fixture subset (tests/Fixtures/tecdoc), rebuilt
// per run so it can never drift from the SQL it is generated from.
if ('test' === ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null)) {
    $catalog = dirname(__DIR__).'/var/test-tecdoc.sqlite';
    if (!is_dir(dirname($catalog))) {
        mkdir(dirname($catalog), 0777, true);
    }
    if (is_file($catalog)) {
        unlink($catalog);
    }
    TecdocFixtureDatabase::create($catalog, withCategoryArticles: true, withEspaceFlywheels: true, withSteeringJoints: true);
    // The compose containers export TECDOC_CATALOG_PATH (the bind-mounted dev slice) as a real env var, which
    // .env.test cannot override: without this the functional suite would read the licensed catalogue, not the fixture.
    $_ENV['TECDOC_CATALOG_PATH'] = $catalog;
    $_SERVER['TECDOC_CATALOG_PATH'] = $catalog;
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
