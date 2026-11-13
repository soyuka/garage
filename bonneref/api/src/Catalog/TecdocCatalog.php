<?php

declare(strict_types=1);

namespace App\Catalog;

use App\ApiResource\Article;
use App\ApiResource\Car;
use App\ApiResource\Make;
use App\ApiResource\Model;
use App\ApiResource\PartCategory;
use App\I18n\Locale;
use App\I18n\LocaleContext;
use App\Vehicle\VehicleIndex;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The whole catalogue from the derived read-only TecDoc extract (docs/tecdoc-sqlite.md): vehicles, the
 * part-category tree and articles, for passenger cars (tree 1, pc_* tables), commercial vehicles (tree 2,
 * cv_* tables) and motorbikes (tree 1 too, mc_* tables). Article ids are the extract's own, never partsapi's ART_ID.
 */
final class TecdocCatalog implements PartsCatalog
{
    /** TecDoc language ids, in the extract's own id space. */
    private const int LANGUAGE_DE = 1;
    private const int LANGUAGE_EN = 4;
    private const int LANGUAGE_FR = 6;
    private const int BODY_TYPE_TABLE = 86;
    private const int ENGINE_TYPE_TABLE = 80;
    /** Tree 1 root of the motorbike nodes. */
    private const int MOTO_ROOT = 103671;

    /**
     * Motorbikes have no engine table; their makes/models are flagged in manufacturer and vehicle_model. They use the
     * passenger tree (1): tree 3 is engine-only (13 of kType 101222's 88 generic articles, no brakes, no battery),
     * tree 1 reaches 55 of them, brake pads and starter battery included (verified 2026-11-13 on the dev slice).
     * Its `Moto` subtree repeats generic articles the car nodes already list, so a bike hides those entries ('hidden'
     * root) and keeps the Moto nodes carrying a generic article found nowhere else (chain kit, clutch, fork...).
     */
    private const array PROFILES = [
        'PC' => ['tree' => 1, 'type' => 'pc_type', 'engines' => 'pc_type_engine', 'genarts' => 'pc_type_genart', 'links' => 'article_pc_type', 'kind' => 'pc', 'hidden' => 0, 'make' => 'has_passenger_car', 'model' => 'is_passenger_car'],
        'CV' => ['tree' => 2, 'type' => 'cv_type', 'engines' => 'cv_type_engine', 'genarts' => 'cv_type_genart', 'links' => 'article_cv_type', 'kind' => 'cv', 'hidden' => 0, 'make' => 'has_commercial_vehicle', 'model' => 'is_commercial_vehicle'],
        'MC' => ['tree' => 1, 'type' => 'mc_type', 'engines' => null, 'genarts' => 'mc_type_genart', 'links' => 'article_mc_type', 'kind' => 'mc', 'hidden' => self::MOTO_ROOT, 'make' => 'has_motorbike', 'model' => 'is_motorbike'],
    ];

    private ?\PDO $pdo = null;

    private ?Locale $pinned = null;

    /** @var array<string, list<int>>|null normalised supplier name => DVD slots */
    private ?array $slotsByName = null;

    public function __construct(
        private readonly string $path,
        private readonly ?VehicleIndex $vehicles = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?LocaleContext $locale = null,
    ) {
    }

    public function makes(CarType $carType, ?string $search = null): array
    {
        $column = self::profile($carType)['make'];
        $makes = array_map(
            Make::fromCatalogRow(...),
            $this->rows(\sprintf('SELECT manufacturer_id AS makeId, name AS makeName FROM manufacturer WHERE %s = 1 ORDER BY name', $column)),
        );

        if (null === $search || '' === $search) {
            return $makes;
        }

        return array_values(array_filter($makes, static fn (Make $make): bool => false !== mb_stripos($make->name, $search)));
    }

    public function models(int $makeId, CarType $carType): array
    {
        $column = self::profile($carType)['model'];
        // The extract flags every bike model as a passenger model too.
        $notBike = CarType::Motorbike === $carType ? '' : 'AND m.is_motorbike = 0';
        $rows = $this->rows(
            <<<SQL
                SELECT m.model_id AS modelId, {$this->first('mt', 'name', 'm.name')} AS modelName,
                       m.year_from, m.month_from, m.year_to, m.month_to
                FROM vehicle_model m
                {$this->joins('vehicle_model_text', 'mt', '{a}.model_id = m.model_id')}
                WHERE m.manufacturer_id = :makeId AND m.{$column} = 1 {$notBike}
                ORDER BY m.model_id
                SQL,
            ['makeId' => $makeId],
        );

        return array_map(
            static fn (array $row): Model => Model::fromCatalogRow($row + [
                'yearsFrom' => self::rfc1123($row['year_from'], $row['month_from']),
                'yearsTo' => self::rfc1123($row['year_to'], $row['month_to']),
            ]),
            $rows,
        );
    }

    public function cars(int $makeId, int $modelId, CarType $carType): array
    {
        $rows = match ($carType) {
            CarType::PassengerCar => $this->passengerCarRows($makeId, $modelId),
            CarType::CommercialVehicle => $this->commercialVehicleRows($makeId, $modelId),
            CarType::Motorbike => $this->motorbikeRows($makeId, $modelId),
        };
        $profile = self::profile($carType);

        $engines = null === $profile['engines'] ? [] : $this->engineCodes($profile['engines'], array_map(intval(...), array_column($rows, 'CAR_ID')));
        $rows = array_map(static fn (array $row): array => self::carRow($row, $engines[(int) $row['CAR_ID']] ?? [], CarType::Motorbike === $carType), $rows);
        $this->vehicles?->recordCatalogRows($rows, $carType);

        return array_map(Car::fromCatalogRow(...), $rows);
    }

    public function carMake(int $carId, CarType $carType): ?string
    {
        $table = self::profile($carType)['type'];
        $make = $this->rows(
            "SELECT mf.name FROM {$table} t JOIN vehicle_model m ON m.model_id = t.model_id JOIN manufacturer mf ON mf.manufacturer_id = m.manufacturer_id WHERE t.type_id = :carId",
            ['carId' => $carId],
        )[0]['name'] ?? null;

        return \is_string($make) && '' !== $make ? $make : null;
    }

    public function carIdentity(int $carId, CarType $carType): ?array
    {
        $engine = CarType::PassengerCar === $carType
            ? "{$this->first('tt', 'description', 't.description')} AS engine FROM pc_type t {$this->joins('pc_type_text', 'tt', '{a}.type_id = t.type_id')}"
            : \sprintf('t.description AS engine FROM %s t', self::profile($carType)['type']);
        $row = $this->rows(
            "SELECT mf.name AS make, {$this->first('mt', 'name', 'm.name')} AS model, {$engine}
                JOIN vehicle_model m ON m.model_id = t.model_id
                JOIN manufacturer mf ON mf.manufacturer_id = m.manufacturer_id
                {$this->joins('vehicle_model_text', 'mt', '{a}.model_id = m.model_id')}
                WHERE t.type_id = :carId",
            ['carId' => $carId],
        )[0] ?? null;

        if (null === $row || !\is_string($row['make']) || '' === $row['make']) {
            return null;
        }

        return ['make' => $row['make'], 'model' => trim((string) $row['model']), 'engine' => trim((string) $row['engine'])];
    }

    public function partCategories(int $carId, CarType $carType, bool $leafOnly = true, ?string $search = null): array
    {
        $profile = self::profile($carType);
        $nodes = $this->rows(
            <<<SQL
                WITH RECURSIVE
                hidden(node_id) AS (
                  SELECT node_id FROM search_tree_node WHERE tree_id = :tree AND node_id = :hiddenRoot
                  UNION
                  SELECT n.node_id FROM search_tree_node n JOIN hidden h ON n.parent_node_id = h.node_id WHERE n.tree_id = :tree
                ),
                named(node_id, parent_id, sort_idx, name) AS (
                  SELECT n.node_id, n.parent_node_id, n.sort_idx, {$this->first('nt', 'text')}
                  FROM search_tree_node n
                  {$this->joins('search_tree_node_text', 'nt', '{a}.tree_id = n.tree_id AND {a}.node_id = n.node_id')}
                  WHERE n.tree_id = :tree
                ),
                up(node_id) AS (
                  SELECT DISTINCT g.node_id
                  FROM search_tree_node_genart g
                  JOIN {$profile['genarts']} pg ON pg.genart_id = g.genart_id AND pg.type_id = :carId
                  WHERE g.tree_id = :tree
                    AND NOT (
                      g.node_id IN (SELECT node_id FROM hidden)
                      AND EXISTS (
                        SELECT 1 FROM search_tree_node_genart o
                        WHERE o.tree_id = :tree AND o.genart_id = g.genart_id AND o.node_id NOT IN (SELECT node_id FROM hidden)
                      )
                    )
                  UNION
                  SELECT named.parent_id FROM named JOIN up USING (node_id) WHERE named.parent_id IS NOT NULL
                ),
                path(node_id, lvl, path) AS (
                  SELECT node_id, 1, name FROM named WHERE parent_id IS NULL
                  UNION ALL
                  SELECT named.node_id, path.lvl + 1, path.path || ' > ' || named.name
                  FROM named JOIN path ON named.parent_id = path.node_id
                )
                SELECT named.node_id AS STR_ID, named.parent_id AS STR_ID_PARENT, path.lvl AS STR_LEVEL,
                       named.name AS STR_NODE_NAME, path.path AS STR_PATH
                FROM up JOIN named USING (node_id) JOIN path USING (node_id)
                ORDER BY named.sort_idx
                SQL,
            ['tree' => $profile['tree'], 'hiddenRoot' => $profile['hidden'], 'carId' => $carId],
        );

        return PartCategory::fromCatalogTree($nodes, $leafOnly, $search);
    }

    public function categoryName(int $carId, int $categoryId, CarType $carType): ?string
    {
        $profile = self::profile($carType);
        // The node is the car's when it, or a node below it, carries a generic article linked to the car.
        $row = $this->rows(
            <<<SQL
                WITH RECURSIVE down(node_id) AS (
                  SELECT node_id FROM search_tree_node WHERE tree_id = :tree AND node_id = :strId
                  UNION
                  SELECT n.node_id FROM search_tree_node n JOIN down ON n.parent_node_id = down.node_id WHERE n.tree_id = :tree
                )
                SELECT {$this->first('nt', 'text')} AS name
                FROM search_tree_node n
                {$this->joins('search_tree_node_text', 'nt', '{a}.tree_id = n.tree_id AND {a}.node_id = n.node_id')}
                WHERE n.tree_id = :tree AND n.node_id = :strId
                  AND EXISTS (
                    SELECT 1 FROM search_tree_node_genart g
                    JOIN {$profile['genarts']} pg ON pg.genart_id = g.genart_id AND pg.type_id = :carId
                    WHERE g.tree_id = :tree AND g.node_id IN (SELECT node_id FROM down)
                  )
                SQL,
            ['tree' => $profile['tree'], 'carId' => $carId, 'strId' => $categoryId],
        )[0] ?? null;
        $name = trim((string) ($row['name'] ?? ''));

        return '' === $name ? null : $name;
    }

    public function articles(int $carId, int $categoryId, CarType $carType): array
    {
        $profile = self::profile($carType);

        // CROSS JOIN pins the car-first order: the candidates are the car's links, never every article of the generic article.
        $rows = $this->rows(
            <<<SQL
                SELECT a.article_id AS ART_ID, a.article_number AS ART_ARTICLE_NR,
                       coalesce(d.dlnr, 0) AS SUP_ID, coalesce(b.name, s.name, '') AS ART_SUP_BRAND,
                       ap.genart_id AS PT_ID, coalesce({$this->first('gat', 'name')}, '') AS "PRODUCT GROUP"
                FROM {$profile['links']} l
                CROSS JOIN article_product ap ON ap.article_id = l.article_id
                CROSS JOIN article a ON a.article_id = l.article_id
                LEFT JOIN supplier s ON s.supplier_id = a.supplier_id
                LEFT JOIN supplier_dlnr d ON d.supplier_id = a.supplier_id
                LEFT JOIN brand b ON b.dlnr = d.dlnr
                {$this->joins('generic_article_text', 'gat', '{a}.genart_id = ap.genart_id')}
                WHERE l.type_id = :carId
                  AND ap.genart_id IN (SELECT genart_id FROM search_tree_node_genart WHERE tree_id = :tree AND node_id = :strId)
                GROUP BY a.article_id, ap.genart_id
                ORDER BY ART_SUP_BRAND COLLATE NOCASE, a.article_number, a.article_id
                SQL,
            ['tree' => $profile['tree'], 'carId' => $carId, 'strId' => $categoryId],
        );

        return array_map(Article::fromCatalogRow(...), $rows);
    }

    public function articleCriteria(int $artId, ?Locale $language = null): array
    {
        return $this->inLanguage($language, fn (): array => $this->criteria([$artId])[$artId] ?? []);
    }

    public function articleCriteriaByIds(array $artIds, ?Locale $language = null): array
    {
        return $this->inLanguage($language, fn (): array => $this->criteria($artIds));
    }

    public function vehicleLinkCriteria(int $carId, CarType $carType, array $artIds, ?int $productGroupId = null, ?Locale $language = null): array
    {
        return $this->inLanguage($language, fn (): array => $this->linkCriteria($carId, $carType, $artIds, $productGroupId));
    }

    /**
     * @param list<int> $artIds
     *
     * @return array<int, VehicleLinkCriteria>
     */
    private function linkCriteria(int $carId, CarType $carType, array $artIds, ?int $productGroupId): array
    {
        $artIds = array_values(array_unique(array_map(intval(...), $artIds)));
        if ([] === $artIds) {
            return [];
        }

        $params = ['kind' => self::profile($carType)['kind'], 'carId' => $carId];
        $genart = '';
        if (null !== $productGroupId) {
            $genart = 'AND genart_id = :genart';
            $params['genart'] = $productGroupId;
        }

        // The unary + keeps SQLite on the vehicle-first primary key: through i_alq_article it would walk every vehicle of each article.
        $rows = $this->qualifierRows(
            \sprintf('SELECT DISTINCT article_id, qual_set_id FROM article_link_qualifier WHERE vehicle_kind = :kind AND vehicle_id = :carId AND +article_id IN (%s) %s', implode(',', $artIds), $genart),
            'q.article_id, q.qual_set_id, i.seq',
            $params,
        );

        $sets = [];
        foreach ($rows as $row) {
            $value = $this->value($row);
            if (null !== $row['name'] && '' !== $value) {
                $sets[(int) $row['article_id']][(string) $row['qual_set_id']][] = new ArticleCriterion(trim((string) $row['name']), $value, (int) $row['criterion_id']);
            }
        }

        $attributes = $this->attributes($artIds);
        $links = [];
        foreach ($artIds as $artId) {
            $links[$artId] = new VehicleLinkCriteria($attributes[$artId], array_values($sets[$artId] ?? []));
        }

        return $links;
    }

    public function articleCrosses(int $artId): array
    {
        $rows = $this->rows(
            'SELECT m.name AS brand, m.short_name AS shortName, o.oe_number AS number FROM article_oe_number o JOIN manufacturer m ON m.manufacturer_id = o.manufacturer_id WHERE o.article_id = :artId ORDER BY o.seq',
            ['artId' => $artId],
        );
        if ([] === $rows) {
            return [];
        }

        $resolved = [];
        $lookup = $this->statement('SELECT article_id FROM article WHERE supplier_id = :slot AND article_number = :number ORDER BY article_id LIMIT 1');
        foreach ($rows as $i => $row) {
            foreach ($this->slots((string) $row['brand'], (string) $row['shortName']) as $slot) {
                $lookup->execute(['slot' => $slot, 'number' => $row['number']]);
                /** @var int|string|false $found article_id, or false past the last row */
                $found = $lookup->fetchColumn();
                if (false !== $found) {
                    $resolved[$i] = (int) $found;
                    break;
                }
            }
        }

        $citing = $this->citingArticles($artId);
        $names = $this->productNames(array_values(array_unique([...$resolved, ...array_column($citing, 'artId')])));

        $crosses = array_map(
            static fn (array $row, int $i): ArticleCross => ArticleCross::fromCatalogRow([
                'ART_BRAND' => $row['brand'],
                'ART_DISPLAY_NR' => $row['number'],
                'ART_ID' => $resolved[$i] ?? null,
                'ART_PRODUCT_NAME' => isset($resolved[$i]) ? ($names[$resolved[$i]] ?? '') : '',
            ]),
            $rows,
            array_keys($rows),
        );

        $known = array_flip($resolved);
        foreach ($citing as $article) {
            if (!isset($known[$article['artId']])) {
                $crosses[] = ArticleCross::fromCatalogRow([
                    'ART_BRAND' => $article['brand'],
                    'ART_DISPLAY_NR' => $article['number'],
                    'ART_ID' => $article['artId'],
                    'ART_PRODUCT_NAME' => $names[$article['artId']] ?? '',
                ]);
            }
        }

        return $crosses;
    }

    /**
     * @return array{tree: int, type: string, engines: string|null, genarts: string, links: string, kind: string, hidden: int, make: string, model: string}
     */
    private static function profile(CarType $carType): array
    {
        return self::PROFILES[$carType->value];
    }

    /**
     * @return list<array<string, int|float|string|null>>
     */
    private function passengerCarRows(int $makeId, int $modelId): array
    {
        return $this->rows(
            <<<SQL
                SELECT t.type_id AS CAR_ID,
                       {$this->first('tt', 'description', 't.description')} AS description,
                       mf.name AS MAKE_NAME, {$this->first('mt', 'name', 'm.name')} AS MODEL_NAME,
                       {$this->first('body', 'text')} AS BODY,
                       {$this->first('eng', 'text')} AS ENGINE_TYPE,
                       t.capacity_tech_ccm, t.capacity_litre100,
                       t.power_kw AS POWER_KW, t.power_ps AS POWER_PS,
                       t.year_from, t.month_from, t.year_to, t.month_to
                FROM pc_type t
                JOIN vehicle_model m ON m.model_id = t.model_id
                JOIN manufacturer mf ON mf.manufacturer_id = m.manufacturer_id
                {$this->joins('vehicle_model_text', 'mt', '{a}.model_id = m.model_id')}
                {$this->joins('pc_type_text', 'tt', '{a}.type_id = t.type_id')}
                {$this->joins('key_value_text', 'body', '{a}.tab_nr = :bodyTable AND {a}.key_code = CAST(t.body_type AS TEXT)')}
                {$this->joins('key_value_text', 'eng', '{a}.tab_nr = :engineTable AND {a}.key_code = CAST(t.engine_type AS TEXT)')}
                WHERE t.model_id = :modelId AND m.manufacturer_id = :makeId
                ORDER BY t.type_id
                SQL,
            [
                'bodyTable' => self::BODY_TYPE_TABLE,
                'engineTable' => self::ENGINE_TYPE_TABLE,
                'modelId' => $modelId,
                'makeId' => $makeId,
            ],
        );
    }

    /**
     * cv_type has no text table and no body, fuel or litre columns, and carries power as a from-to range.
     *
     * @return list<array<string, int|float|string|null>>
     */
    private function commercialVehicleRows(int $makeId, int $modelId): array
    {
        return $this->rows(
            <<<SQL
                SELECT t.type_id AS CAR_ID, t.description,
                       mf.name AS MAKE_NAME, {$this->first('mt', 'name', 'm.name')} AS MODEL_NAME,
                       NULL AS BODY, NULL AS ENGINE_TYPE,
                       t.capacity_tech_ccm, NULL AS capacity_litre100,
                       t.power_kw_from AS POWER_KW, t.power_ps_from AS POWER_PS,
                       t.year_from, t.month_from, t.year_to, t.month_to
                FROM cv_type t
                JOIN vehicle_model m ON m.model_id = t.model_id
                JOIN manufacturer mf ON mf.manufacturer_id = m.manufacturer_id
                {$this->joins('vehicle_model_text', 'mt', '{a}.model_id = m.model_id')}
                WHERE t.model_id = :modelId AND m.manufacturer_id = :makeId
                ORDER BY t.type_id
                SQL,
            ['modelId' => $modelId, 'makeId' => $makeId],
        );
    }

    /**
     * mc_type has no text table and no body column; the bike's own name is its description.
     *
     * @return list<array<string, int|float|string|null>>
     */
    private function motorbikeRows(int $makeId, int $modelId): array
    {
        return $this->rows(
            <<<SQL
                SELECT t.type_id AS CAR_ID, t.description,
                       mf.name AS MAKE_NAME, {$this->first('mt', 'name', 'm.name')} AS MODEL_NAME,
                       NULL AS BODY, NULL AS ENGINE_TYPE,
                       t.capacity_tech_ccm, t.capacity_litre100,
                       t.power_kw AS POWER_KW, t.power_ps AS POWER_PS,
                       t.year_from, t.month_from, t.year_to, t.month_to
                FROM mc_type t
                JOIN vehicle_model m ON m.model_id = t.model_id
                JOIN manufacturer mf ON mf.manufacturer_id = m.manufacturer_id
                {$this->joins('vehicle_model_text', 'mt', '{a}.model_id = m.model_id')}
                WHERE t.model_id = :modelId AND m.manufacturer_id = :makeId
                ORDER BY t.type_id
                SQL,
            ['modelId' => $modelId, 'makeId' => $makeId],
        );
    }

    /**
     * @param list<int> $artIds
     *
     * @return array<int, list<ArticleCriterion>> every asked id, criteria-less articles included
     */
    private function criteria(array $artIds): array
    {
        $artIds = array_values(array_unique(array_map(intval(...), $artIds)));
        if ([] === $artIds) {
            return [];
        }

        $qualifiers = $this->qualifierRows(
            \sprintf('SELECT DISTINCT article_id, qual_set_id FROM article_link_qualifier WHERE article_id IN (%s)', implode(',', $artIds)),
            'q.article_id, name, c.criterion_id',
        );

        $criteria = $this->attributes($artIds);
        $names = [];
        $unions = [];
        foreach ($qualifiers as $row) {
            $value = $this->value($row);
            if (null !== $row['name'] && '' !== $value) {
                $names[(int) $row['article_id']][(int) $row['criterion_id']] = trim((string) $row['name']);
                $unions[(int) $row['article_id']][(int) $row['criterion_id']][$value] = true;
            }
        }
        foreach ($unions as $artId => $byCriterion) {
            foreach ($byCriterion as $criterionId => $union) {
                $values = array_map('strval', array_keys($union));
                sort($values, \SORT_NATURAL);
                $criteria[$artId][] = new ArticleCriterion($names[$artId][$criterionId], implode('; ', $values), $criterionId);
            }
        }

        return $criteria;
    }

    /**
     * @param non-empty-list<int> $artIds
     *
     * @return array<int, list<ArticleCriterion>> every asked id
     */
    private function attributes(array $artIds): array
    {
        $rows = $this->rows(
            \sprintf(
                <<<'SQL'
                    SELECT aa.article_id, c.criterion_id, %s AS name,
                           aa.value_num, aa.value_text, aa.key_code, %s AS key_text,
                           NULL AS date_year, NULL AS date_month
                    FROM article_attribute aa
                    JOIN criterion c ON c.criterion_id = aa.criterion_id
                    %s
                    WHERE aa.article_id IN (%s)
                    ORDER BY aa.article_id, aa.seq
                    SQL,
                $this->first('ct', 'name'),
                $this->first('kv', 'text'),
                $this->criterionTexts('aa'),
                implode(',', $artIds),
            ),
        );

        $attributes = array_fill_keys($artIds, []);
        foreach ($rows as $row) {
            $value = $this->value($row);
            if (null !== $row['name'] && '' !== $value) {
                $attributes[(int) $row['article_id']][] = new ArticleCriterion(trim((string) $row['name']), $value, (int) $row['criterion_id']);
            }
        }

        return $attributes;
    }

    /**
     * @param string                    $links  a SELECT of (article_id, qual_set_id) pairs
     * @param array<string, int|string> $params the parameters of $links
     *
     * @return list<array<string, int|float|string|null>>
     */
    private function qualifierRows(string $links, string $orderBy, array $params = []): array
    {
        return $this->rows(
            \sprintf(
                <<<'SQL'
                    SELECT q.article_id, q.qual_set_id, c.criterion_id, %s AS name,
                           i.value_num, i.value_text, i.key_code, %s AS key_text,
                           i.date_year, i.date_month
                    FROM (%s) q
                    JOIN link_qualifier_item i ON i.qual_set_id = q.qual_set_id
                    JOIN criterion c ON c.criterion_id = i.criterion_id
                    %s
                    ORDER BY %s
                    SQL,
                $this->first('ct', 'name'),
                $this->first('kv', 'text'),
                $links,
                $this->criterionTexts('i'),
                $orderBy,
            ),
            $params,
        );
    }

    private function criterionTexts(string $alias): string
    {
        return $this->joins('criterion_text', 'ct', '{a}.criterion_id = c.criterion_id')
            .' '.$this->joins('key_value_text', 'kv', \sprintf('{a}.tab_nr = c.key_table_nr AND {a}.key_code = %s.key_code', $alias));
    }

    /**
     * @param array<string, int|float|string|null> $row
     */
    private function value(array $row): string
    {
        if (null !== $row['key_text']) {
            return trim((string) $row['key_text']);
        }
        if (null !== $row['value_text'] && '' !== trim((string) $row['value_text'])) {
            return trim((string) preg_replace('/^\*\s*|\s*\*$/', '', trim((string) $row['value_text'])));
        }
        if (null !== $row['date_year']) {
            return \sprintf('%04d%02d', $row['date_year'], $row['date_month'] ?? 0);
        }
        if (null !== $row['value_num']) {
            $separator = Locale::En === $this->language() ? '.' : ',';

            return rtrim(rtrim(number_format((float) $row['value_num'], 4, $separator, ''), '0'), $separator);
        }

        return null === $row['key_code'] ? '' : trim((string) $row['key_code']);
    }

    /**
     * The other articles listing one of this article's OE numbers under the same manufacturer: what partsapi
     * answers with as the articles that cross to it, and the only way a cluster claims the rest of its category
     * (the forward list holds one article's numbers, never its siblings). Needs `i_article_oe_number`
     * (oe_number, manufacturer_id, article_id): without it this is a scan of article_oe_number per call.
     *
     * @return list<array{artId: int, brand: string, number: string}>
     */
    private function citingArticles(int $artId): array
    {
        return array_map(
            static fn (array $row): array => ['artId' => (int) $row['artId'], 'brand' => (string) $row['brand'], 'number' => (string) $row['number']],
            $this->rows(
                <<<'SQL'
                    SELECT DISTINCT a.article_id AS artId, a.article_number AS number, coalesce(b.name, s.name, '') AS brand
                    FROM article_oe_number o
                    JOIN article_oe_number r ON r.oe_number = o.oe_number AND r.manufacturer_id = o.manufacturer_id AND r.article_id <> o.article_id
                    JOIN article a ON a.article_id = r.article_id
                    LEFT JOIN supplier s ON s.supplier_id = a.supplier_id
                    LEFT JOIN supplier_dlnr d ON d.supplier_id = a.supplier_id
                    LEFT JOIN brand b ON b.dlnr = d.dlnr
                    WHERE o.article_id = :artId
                    ORDER BY a.article_id
                    SQL,
                ['artId' => $artId],
            ),
        );
    }

    /**
     * The DVD slots whose supplier name or short name is this brand once punctuation is ignored. A cross lists a
     * number by a manufacturer name ("MAHLE FILTER", short name "MAHLE"); the extract has no manufacturer to slot
     * link, so the names are the only bridge.
     *
     * @return list<int>
     */
    private function slots(string $brand, string $shortName = ''): array
    {
        if (null === $this->slotsByName) {
            $this->slotsByName = [];
            foreach ($this->rows('SELECT supplier_id, name, short_name FROM supplier ORDER BY supplier_id') as $row) {
                foreach (array_unique([self::normalize((string) $row['name']), self::normalize((string) $row['short_name'])]) as $key) {
                    if ('' !== $key) {
                        $this->slotsByName[$key][] = (int) $row['supplier_id'];
                    }
                }
            }
        }

        return array_values(array_unique([...$this->slotsByName[self::normalize($brand)] ?? [], ...$this->slotsByName[self::normalize($shortName)] ?? []]));
    }

    private static function normalize(string $name): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
    }

    /**
     * @param list<int> $artIds
     *
     * @return array<int, string> the first product group name of each article
     */
    private function productNames(array $artIds): array
    {
        if ([] === $artIds) {
            return [];
        }

        $rows = $this->rows(
            \sprintf(
                <<<'SQL'
                    SELECT ap.article_id, %s AS name
                    FROM article_product ap
                    %s
                    WHERE ap.article_id IN (%s)
                    ORDER BY ap.article_id, ap.pos
                    SQL,
                $this->first('gat', 'name'),
                $this->joins('generic_article_text', 'gat', '{a}.genart_id = ap.genart_id'),
                implode(',', $artIds),
            ),
        );

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['article_id']] ??= (string) $row['name'];
        }

        return $names;
    }

    /**
     * @param array<string, int|float|string|null> $row
     * @param list<string>                         $engines
     *
     * @return array<string, mixed> a getCars row, as partsapi serialises it
     */
    private static function carRow(array $row, array $engines, bool $nameStartsWithModel = false): array
    {
        $name = trim((string) $row['description']);
        if ([] !== $engines) {
            $name .= ' ('.implode('/', $engines).')';
        }
        $ccm = $row['capacity_tech_ccm'];
        $litres = $row['capacity_litre100'];
        $make = (string) $row['MAKE_NAME'];
        $model = (string) $row['MODEL_NAME'];
        $fullName = $nameStartsWithModel && str_starts_with(mb_strtolower($name), mb_strtolower($model)) ? $make.' '.$name : $make.' '.$model.' '.$name;

        return [
            'CAR_ID' => (int) $row['CAR_ID'],
            'CAR_NAME' => $name,
            'FULL_MODEL_NAME' => $fullName,
            'MAKE_NAME' => $row['MAKE_NAME'],
            'MODEL_NAME' => $row['MODEL_NAME'],
            'BODY' => $row['BODY'],
            'ENGINE_TYPE' => $row['ENGINE_TYPE'],
            'CAPACITY' => null === $ccm ? null : (string) $ccm.(null === $litres ? '' : \sprintf('/%.1f l', (float) $litres / 100)),
            'POWER_KW' => $row['POWER_KW'],
            'POWER_PS' => $row['POWER_PS'],
            'YEAR_START' => self::rfc1123($row['year_from'], $row['month_from']),
            'YEAR_END' => self::rfc1123($row['year_to'], $row['month_to']),
        ];
    }

    /**
     * @param array<int> $typeIds
     *
     * @return array<int, list<string>>
     */
    private function engineCodes(string $table, array $typeIds): array
    {
        if ([] === $typeIds) {
            return [];
        }

        $rows = $this->rows(\sprintf(
            'SELECT pe.type_id, e.engine_code FROM %s pe JOIN engine e ON e.engine_id = pe.engine_id WHERE pe.type_id IN (%s) ORDER BY pe.type_id, e.engine_code',
            $table,
            implode(',', $typeIds),
        ));

        $codes = [];
        foreach ($rows as $row) {
            if (null !== $row['engine_code'] && '' !== $row['engine_code']) {
                $codes[(int) $row['type_id']][] = (string) $row['engine_code'];
            }
        }

        return $codes;
    }

    private static function rfc1123(int|float|string|null $year, int|float|string|null $month): ?string
    {
        if (null === $year || '' === $year) {
            return null;
        }

        $timestamp = gmmktime(0, 0, 0, max(1, (int) $month), 1, (int) $year);

        return false === $timestamp ? null : gmdate('D, d M Y H:i:s \G\M\T', $timestamp);
    }

    private function language(): Locale
    {
        return $this->pinned ?? $this->locale?->current() ?? Locale::DEFAULT;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    private function inLanguage(?Locale $language, \Closure $read): mixed
    {
        $previous = $this->pinned;
        $this->pinned = $language ?? $previous;
        try {
            return $read();
        } finally {
            $this->pinned = $previous;
        }
    }

    /**
     * The language ids to read a text in, best first: the caller's, then French, English and German, whatever the
     * extract holds for the row (vehicle_model_text and pc_type_text have no German, English vehicle names are partial).
     *
     * @return non-empty-list<int>
     */
    private function chain(): array
    {
        $requested = Locale::En === $this->language() ? self::LANGUAGE_EN : self::LANGUAGE_FR;

        return array_values(array_unique([$requested, self::LANGUAGE_FR, self::LANGUAGE_EN, self::LANGUAGE_DE]));
    }

    /**
     * One LEFT JOIN of a text table per language of the chain, aliased `{alias}0`, `{alias}1`... ; `{a}` stands for
     * the alias in $on. Language ids are integers from {@see self::chain()}, never caller input.
     */
    private function joins(string $table, string $alias, string $on): string
    {
        $joins = [];
        foreach ($this->chain() as $i => $language) {
            $a = $alias.$i;
            $joins[] = \sprintf('LEFT JOIN %s %s ON %s AND %s.language_id = %d', $table, $a, str_replace('{a}', $a, $on), $a, $language);
        }

        return implode(' ', $joins);
    }

    /** The first non-null text of the joins {@see self::joins()} made under $alias, then the optional base column. */
    private function first(string $alias, string $column, ?string $base = null): string
    {
        $columns = array_map(static fn (int $i): string => \sprintf('%s%d.%s', $alias, $i, $column), array_keys($this->chain()));
        if (null !== $base) {
            $columns[] = $base;
        }

        return 'coalesce('.implode(', ', $columns).')';
    }

    /**
     * @param array<string, int|string> $params
     *
     * @return list<array<string, int|float|string|null>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->statement($sql);
        $statement->execute($params);
        /** @var list<array<string, int|float|string|null>> $rows SQLite only yields scalars and NULL */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    private function statement(string $sql): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        if (false === $statement) {
            throw new \RuntimeException('The parts catalogue rejected a query.');
        }

        return $statement;
    }

    private function pdo(): \PDO
    {
        if (null !== $this->pdo) {
            return $this->pdo;
        }

        if ('' === $this->path || !is_file($this->path) || !is_readable($this->path)) {
            $this->logger->critical('TECDOC_CATALOG_PATH "{path}" is not a readable file.', ['path' => $this->path]);

            throw new \RuntimeException('The parts catalogue is unavailable on this server.');
        }

        return $this->pdo = new \PDO(
            'sqlite:file:'.$this->path.'?mode=ro&immutable=1',
            options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }
}
