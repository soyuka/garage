<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PDO;

/**
 * A subset of catalog-dev.sqlite: the real DDL of the tables TecdocCatalog reads and the real rows of
 * kType 19107 / category 100259 (tests/Fixtures/tecdoc/*.sql, generated from the dev slice). `narrowing.sql` adds
 * all 116 articles of that category with their criteria, OE numbers and qualifiers, for the narrower.
 * `espace_flywheel.sql` holds the four flywheels of kType 18679 (RENAULT ESPACE IV 2.0, product group 577) with their
 * per-link qualifiers.
 */
final class TecdocFixtureDatabase
{
    public static function create(string $path, bool $withCategoryArticles = false, bool $withEspaceFlywheels = false): void
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

        if ($withEspaceFlywheels) {
            $pdo->exec((string) file_get_contents(__DIR__.'/../Fixtures/tecdoc/espace_flywheel.sql'));
            // The dev slice links these flywheels to 18679 only. A sibling Espace (16869, 2.0 Turbo) with its own date
            // qualifiers is derived here, so the union over every vehicle differs from the 18679 link.
            $pdo->exec(<<<'SQL'
                INSERT INTO article_pc_type VALUES (95481, 16869), (635192, 16869);
                INSERT INTO link_qualifier_set VALUES (900001, NULL), (900002, NULL);
                INSERT INTO link_qualifier_item VALUES (900001, 0, 20, NULL, NULL, NULL, 2006, 3, NULL);
                INSERT INTO link_qualifier_item VALUES (900002, 0, 20, NULL, NULL, NULL, 2002, 11, NULL);
                INSERT INTO article_link_qualifier VALUES (95481, 'pc', 16869, 577, 443, 900001), (635192, 'pc', 16869, 577, 610, 900002);
                SQL);
        }
    }
}
