<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A subset of catalog-dev.sqlite: the real DDL of the tables TecdocCatalog reads and the real rows of
 * kType 19107 / category 100259 (tests/Fixtures/tecdoc/*.sql, generated from the dev slice). `narrowing.sql` adds
 * all 116 articles of that category with their criteria, OE numbers and qualifiers, for the narrower.
 * `espace_flywheel.sql` holds the four flywheels of kType 18679 (RENAULT ESPACE IV 2.0, product group 577) with their
 * per-link qualifiers. `withSteeringJoints` derives articles for the three product groups of category 100198 (« Rotules »)
 * that the dev slice links to kType 19107 without any article: a category holding several product groups.
 */
final class TecdocFixtureDatabase
{
    public static function create(string $path, bool $withCategoryArticles = false, bool $withEspaceFlywheels = false, bool $withSteeringJoints = false, bool $withMotorbikeQualifiers = false, bool $withMotoSubtree = false): void
    {
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // Throwaway file: without this every INSERT of the fixtures is its own fsync (minutes on a bind mount).
        $pdo->exec('PRAGMA synchronous = OFF; PRAGMA journal_mode = MEMORY');

        $pdo->exec((string) file_get_contents(__DIR__.'/../Fixtures/tecdoc/schema.sql'));
        $pdo->exec((string) file_get_contents(__DIR__.'/../Fixtures/tecdoc/data.sql'));

        // The dev slice holds no CV article links nor CV engines (its CV tables are empty), so one is derived here.
        $pdo->exec(<<<'SQL'
            INSERT INTO cv_type_engine VALUES (1446, 0, 19205, NULL, NULL, NULL, NULL, NULL, NULL, 0);
            INSERT INTO cv_type_genart VALUES (1446, 7);
            INSERT INTO article_cv_type VALUES (78351, 1446);
            SQL);

        // The dev slice carries no motorbike rows either: the BMW F 800 GS Adventure (kType 101222, real mc_type rows)
        // is derived here, linked to the MANN oil filter of the fixture.
        $pdo->exec(<<<'SQL'
            INSERT INTO manufacturer VALUES (4081, 512, 'BMW MOTORC', 'BMW MOTORCYCLES', 0, 0, 1, 1, 0, 0, 0);
            INSERT INTO vehicle_model VALUES (12108, 4081, 'F', 1993, 1, NULL, NULL, 1, 0, 1, 0, 0, 0, 0, 12108);
            INSERT INTO vehicle_model VALUES (12112, 4081, 'S', 2009, 1, NULL, NULL, 1, 0, 1, 0, 0, 0, 0, 12112);
            INSERT INTO mc_type VALUES (101222, 12108, 'F 800 GS Adventure (K75)', 2012, 5, NULL, NULL, 63, 86, 1, 800, 798, 4, 80, 2, 1, 26, 8, 0, 831);
            INSERT INTO mc_type VALUES (101223, 12108, 'F 800 GS Adventure (K75)', 2012, 5, NULL, NULL, 35, 48, 1, 800, 798, 4, 80, 2, 1, 26, 8, 0, 835);
            INSERT INTO mc_type_genart VALUES (101222, 7);
            INSERT INTO article_mc_type VALUES (78351, 101222);
            SQL);

        if ($withMotoSubtree) {
            // Tree 1 carries a `Moto` root (103671): its oil filter node repeats a generic article (7) that the car nodes
            // already list, its chain kit node (genart 5478, linked to the bike below) exists only there.
            $pdo->exec(<<<'SQL'
                INSERT INTO search_tree_node VALUES (1, 103671, NULL, 9001, 0, 0);
                INSERT INTO search_tree_node VALUES (1, 103672, 103671, 9002, 1, 0);
                INSERT INTO search_tree_node VALUES (1, 103673, 103672, 9003, 1, 0);
                INSERT INTO search_tree_node VALUES (1, 103674, 103671, 9004, 1, 0);
                INSERT INTO search_tree_node_text VALUES (1, 103671, 1, 'Motorrad');
                INSERT INTO search_tree_node_text VALUES (1, 103671, 6, 'Moto');
                INSERT INTO search_tree_node_text VALUES (1, 103672, 1, 'Filter');
                INSERT INTO search_tree_node_text VALUES (1, 103672, 6, 'Filtre');
                INSERT INTO search_tree_node_text VALUES (1, 103673, 1, 'Ölfilter');
                INSERT INTO search_tree_node_text VALUES (1, 103673, 6, 'Filtre à huile');
                INSERT INTO search_tree_node_text VALUES (1, 103674, 1, 'Kettensatz');
                INSERT INTO search_tree_node_text VALUES (1, 103674, 6, 'Kit chaîne');
                INSERT INTO search_tree_node_genart VALUES (1, 103673, 0, 4, 7);
                INSERT INTO search_tree_node_genart VALUES (1, 103674, 0, 4, 5478);
                INSERT INTO mc_type_genart VALUES (101222, 5478);
                SQL);
        }

        if ($withMotorbikeQualifiers) {
            // Kept out of the default fixture: the criteria of an article are the union over every vehicle it links.
            $pdo->exec(<<<'SQL'
                INSERT INTO article_link_qualifier VALUES (78351, 'mc', 101222, 7, 4, 900101);
                INSERT INTO link_qualifier_set VALUES (900101, NULL);
                INSERT INTO link_qualifier_item VALUES (900101, 0, 20, NULL, NULL, NULL, 2012, 5, NULL);
                SQL);
        }

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

        if ($withSteeringJoints) {
            $pdo->exec(<<<'SQL'
                INSERT INTO article VALUES (9100001, 453, 'DERIVED-914', NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0);
                INSERT INTO article VALUES (9100002, 453, 'DERIVED-51-SMALL', NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0);
                INSERT INTO article VALUES (9100003, 724, 'DERIVED-51-LARGE', NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0);
                INSERT INTO article VALUES (9100004, 724, 'DERIVED-2066', NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, 0, NULL, NULL, 0, 0);
                INSERT INTO article_product VALUES (9100001, 0, 914), (9100002, 0, 51), (9100003, 0, 51), (9100004, 0, 2066);
                INSERT INTO article_pc_type VALUES (9100001, 19107), (9100002, 19107), (9100003, 19107), (9100004, 19107);
                INSERT INTO article_attribute VALUES (9100001, 0, NULL, 497, 25.0, NULL, NULL, 0, 1, NULL);
                INSERT INTO article_attribute VALUES (9100002, 0, NULL, 497, 20.0, NULL, NULL, 0, 1, NULL);
                INSERT INTO article_attribute VALUES (9100003, 0, NULL, 497, 30.0, NULL, NULL, 0, 1, NULL);
                SQL);
        }
    }
}
