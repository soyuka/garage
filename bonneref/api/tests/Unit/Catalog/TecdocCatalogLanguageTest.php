<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\ApiResource\Article;
use App\ApiResource\Car;
use App\ApiResource\Model;
use App\ApiResource\PartCategory;
use App\Catalog\ArticleCriterion;
use App\Catalog\CarType;
use App\Catalog\TecdocCatalog;
use App\I18n\Locale;
use App\I18n\LocaleContext;
use App\Tests\Support\TecdocFixtureDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class TecdocCatalogLanguageTest extends TestCase
{
    private string $dir;
    private RequestStack $requests;
    private LocaleContext $locale;
    private TecdocCatalog $catalog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tecdoc-lang-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        TecdocFixtureDatabase::create($this->dir.'/catalog.sqlite');

        $this->requests = new RequestStack();
        $this->locale = new LocaleContext($this->requests);
        $this->catalog = new TecdocCatalog($this->dir.'/catalog.sqlite', locale: $this->locale);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testFrenchIsTheDefaultWithoutARequestOrABoundLocale(): void
    {
        $this->assertSame('Filtre à huile', $this->catalog->categoryName(19107, 100259, CarType::PassengerCar));
    }

    public function testCategoriesAnswerInEnglish(): void
    {
        $this->locale->set(Locale::En);

        $this->assertEquals(
            [
                new PartCategory(100005, null, 1, 'Filters', 'Filters', false),
                new PartCategory(100012, null, 1, 'Steering', 'Steering', false),
                new PartCategory(100259, 100005, 2, 'Oil Filter', 'Filters > Oil Filter', true),
                new PartCategory(100198, 100012, 2, 'Steering Joints', 'Steering > Steering Joints', true),
            ],
            $this->catalog->partCategories(19107, CarType::PassengerCar, false),
        );
        $this->assertSame('Oil Filter', $this->catalog->categoryName(19107, 100259, CarType::PassengerCar));
    }

    public function testTheRequestLocaleDecidesAndWinsOverTheBoundOne(): void
    {
        $this->locale->set(Locale::Fr);
        $request = new Request();
        $request->setLocale('en');
        $this->requests->push($request);

        $this->assertSame('Oil Filter', $this->catalog->categoryName(19107, 100259, CarType::PassengerCar));
    }

    public function testArticlesCarryTheEnglishProductGroup(): void
    {
        $this->locale->set(Locale::En);

        $this->assertContainsOnlyInstancesOf(Article::class, $articles = $this->catalog->articles(19107, 100259, CarType::PassengerCar));
        $this->assertSame(['Oil Filter'], array_values(array_unique(array_map(static fn (Article $a): string => $a->productGroup, $articles))));
    }

    public function testCriteriaAnswerInEnglishWithADecimalPoint(): void
    {
        $this->locale->set(Locale::En);

        $criteria = array_column(array_map(static fn (ArticleCriterion $c): array => ['n' => $c->name, 'v' => $c->value], $this->catalog->articleCriteria(78351)), 'v', 'n');
        $this->assertSame('1', $criteria['Bypass Valve Opening Pressure [bar]']);
        $this->assertSame('93', $criteria['Height [mm]']);

        $fractional = array_column(array_map(static fn (ArticleCriterion $c): array => ['n' => $c->name, 'v' => $c->value], $this->catalog->articleCriteria(407986)), 'v', 'n');
        $this->assertSame('93.5', $fractional['Height [mm]'], 'a decimal point, not the French comma');
    }

    public function testModelsAndCarsAnswerInEnglishAndFallBackPerRowToFrenchThenTheBaseName(): void
    {
        $this->locale->set(Locale::En);

        $models = array_column(array_map(static fn (Model $m): array => ['id' => $m->id, 'name' => $m->name], $this->catalog->models(72, CarType::PassengerCar)), 'name', 'id');
        $this->assertSame('6 Saloon (GH)', $models[6987]);
        $this->assertSame('6 Hatchback (GG)', $models[4887], 'no text in any language: the model\'s own name');

        $cars = array_column(array_map(static fn (Car $c): array => ['id' => $c->id, 'car' => $c], $this->catalog->cars(72, 4887, CarType::PassengerCar)), 'car', 'id');
        $this->assertSame('Hatchback', $cars[19107]->body);
        $this->assertSame('Petrol Engine', $cars[19107]->engineType);
    }

    public function testATextMissingInTheRequestedLanguageFallsBackToFrenchThenEnglishThenGerman(): void
    {
        TecdocFixtureDatabase::create($this->dir.'/moto.sqlite', withMotoSubtree: true);
        $catalog = new TecdocCatalog($this->dir.'/moto.sqlite', locale: $this->locale);
        $this->locale->set(Locale::En);

        $this->assertSame(
            ['Filters', 'Filters > Oil Filter', 'Moto', 'Moto > Kit chaîne'],
            array_map(static fn (PartCategory $c): string => $c->path, $catalog->partCategories(101222, CarType::Motorbike, false)),
            'the Moto nodes have no English: the French name, never blank',
        );

        $pdo = new \PDO('sqlite:'.$this->dir.'/moto.sqlite');
        $pdo->exec('DELETE FROM search_tree_node_text WHERE tree_id = 1 AND node_id = 103671 AND language_id IN (4, 6)');
        $this->assertSame(
            ['Filters', 'Filters > Oil Filter', 'Motorrad', 'Motorrad > Kit chaîne'],
            array_map(static fn (PartCategory $c): string => $c->path, (new TecdocCatalog($this->dir.'/moto.sqlite', locale: $this->locale))->partCategories(101222, CarType::Motorbike, false)),
            'German is the data fallback of last resort',
        );
    }

    public function testFrenchStaysUnchangedForAFrenchCallerEvenWhereEnglishExists(): void
    {
        $this->locale->set(Locale::Fr);

        $this->assertSame('Filtre à huile', $this->catalog->articles(19107, 100259, CarType::PassengerCar)[0]->productGroup);
        $cars = array_column(array_map(static fn (Car $c): array => ['id' => $c->id, 'car' => $c], $this->catalog->cars(72, 4887, CarType::PassengerCar)), 'car', 'id');
        $this->assertSame('Essence', $cars[19107]->engineType);
        $this->assertSame('93,5', array_column(array_map(static fn (ArticleCriterion $c): array => ['n' => $c->name, 'v' => $c->value], $this->catalog->articleCriteria(407986)), 'v', 'n')['Hauteur [mm]']);
    }
}
