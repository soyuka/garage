<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// The compose containers export MONGODB_DB=app as a REAL env var, which overrides .env.test's
// app_test (Symfony Dotenv never overrides real env vars). AppTestCase drops MONGODB_DB on every
// setUp — so without this the suite would wipe the live `app` garage (it did once). Force a
// dedicated, clearly-suffixed test database here, before the kernel boots, so BOTH the kernel under
// test and the dropDatabase target it regardless of how MONGODB_DB was provided.
if ('test' === ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null)) {
    $db = $_SERVER['MONGODB_DB'] ?? $_ENV['MONGODB_DB'] ?? 'app';
    if (!str_ends_with($db, '_test')) {
        $db .= '_test';
    }
    $_ENV['MONGODB_DB'] = $_SERVER['MONGODB_DB'] = $db;
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
