<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PDO;

/**
 * A subset of catalog-dev.sqlite: the real DDL of the tables TecdocCatalog reads and the real rows of
 * kType 19107 / category 100259 (tests/Fixtures/tecdoc/*.sql, generated from the dev slice). `narrowing.sql` adds
 * all 116 articles of that category with their criteria, OE numbers and qualifiers, for the narrower.
 */
final class TecdocFixtureDatabase
{
    public static function create(string $path, bool $withCategoryArticles = false): void
    {
        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec((string) file_get_contents(__DIR__.'/../Fixtures/tecdoc/schema.sql'));
        $pdo->exec((string) file_get_contents(__DIR__.'/../Fixtures/tecdoc/data.sql'));

        // The dev slice holds no CV article links nor CV engines (its CV tables are empty), so one is derived here.
        $pdo->exec(<<<'SQL'
            INSERT INTO cv_type_engine VALUES (1446, 0, 19205, NULL, NULL, NULL, NULL, NULL, NULL, 0);
            INSERT INTO cv_type_genart VALUES (1446, 7);
            INSERT INTO article_cv_type VALUES (78351, 1446);
            SQL);

        if ($withCategoryArticles) {
            $pdo->exec((string) file_get_contents(__DIR__.'/../Fixtures/tecdoc/narrowing.sql'));
        }
    }
}
