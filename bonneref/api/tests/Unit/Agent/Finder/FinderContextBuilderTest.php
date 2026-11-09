<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Finder;

use App\Agent\Finder\BuildLabelHints;
use App\Agent\Finder\FinderCategoryList;
use App\Agent\Finder\FinderContextBuilder;
use App\Agent\Finder\FinderVehicle;
use App\Agent\TurnSurface;
use App\Catalog\TecdocCatalog;
use App\Knowledge\BuildCode\BuildCodeDecoders;
use App\Knowledge\BuildCode\PsaBuildCodeDecoder;
use App\Message\RunAgentTurn;
use App\Tests\Support\TecdocFixtureDatabase;
use PHPUnit\Framework\TestCase;

final class FinderContextBuilderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/finder-context-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        TecdocFixtureDatabase::create($this->dir.'/catalog.sqlite');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testAFollowUpCarriesTheQuestionTheOprAndTheDecodeInstruction(): void
    {
        $context = $this->builder()->build($this->turn(
            instruction: 'mon OPR est 10512',
            pendingContext: 'rotule axiale 307 : deux montages selon le n° d\'organisation',
            buildLabel: BuildLabelHints::fromPayload(['opr' => '10512', 'printedCodes' => ['DAM 10512', '1051288']]),
        ));

        $this->assertStringContainsString('rotule axiale 307 : deux montages selon le n° d\'organisation', $context);
        $this->assertStringContainsString('OPR 10512', $context);
        $this->assertStringContainsString('DAM 10512 ; 1051288', $context);
        $this->assertStringContainsString('set_build_code', $context);
        $this->assertStringEndsWith('Demande : mon OPR est 10512', $context);
    }

    public function testABuildLabelWithoutOprStillListsTheCodes(): void
    {
        $context = $this->builder()->build($this->turn(buildLabel: new BuildLabelHints(null, ['EWPA'])));

        $this->assertStringContainsString('codes : EWPA', $context);
        $this->assertStringNotContainsString('OPR', $context);
    }

    public function testAnEmptyBuildLabelIsNotRendered(): void
    {
        $context = $this->builder()->build($this->turn(buildLabel: new BuildLabelHints()));

        $this->assertStringNotContainsString('Vignette constructeur', $context);
    }

    public function testTheOnScreenResultIsRenderedAndCapped(): void
    {
        $context = $this->builder()->build($this->turn(summary: 'Rotule axiale (100123) — groupe A : TRW JAR123 '.str_repeat('x', 3000)));

        $this->assertStringContainsString("Résultat affiché à l'écran (la demande le poursuit) : Rotule axiale (100123) — groupe A : TRW JAR123", $context);
        $lines = explode("\n", $context);
        $index = array_search(true, array_map(static fn (string $l): bool => str_starts_with($l, 'Résultat affiché'), $lines), true);
        $this->assertIsInt($index);
        $line = $lines[$index];
        $this->assertLessThanOrEqual(2100, mb_strlen($line));
    }

    public function testNoSummaryNoResultLine(): void
    {
        $this->assertStringNotContainsString('Résultat affiché', $this->builder()->build($this->turn()));
    }

    public function testThePlateIsKeptOnTheVehicleLine(): void
    {
        $vehicle = FinderVehicle::fromPayload(['carId' => 19107, 'carType' => 'PC', 'make' => 'PEUGEOT', 'model' => '307', 'plate' => '  XB-301-ZB ']);

        $this->assertSame('XB-301-ZB', $vehicle?->plate);
        $this->assertStringContainsString('plaque XB-301-ZB', $this->builder()->build($this->turn(vehicle: $vehicle)));
    }

    public function testAnOverlongPlateIsDropped(): void
    {
        $vehicle = FinderVehicle::fromPayload(['carId' => 19107, 'plate' => str_repeat('A', 21)]);

        $this->assertNotNull($vehicle);
        $this->assertNull($vehicle->plate);
        $this->assertNull(FinderVehicle::fromPayload(['carId' => 19107, 'plate' => 42])?->plate);
    }

    public function testAKnownVehicleCarriesItsLeafCategoriesBeforeTheRequest(): void
    {
        $context = $this->builder()->build($this->turn(vehicle: new FinderVehicle(19107)));

        $this->assertStringContainsString("[Direction]\n", $context);
        $this->assertStringContainsString("\n100198 Rotules\n", $context);
        $this->assertStringNotContainsString('Outils', $context);
        $this->assertLessThan(strpos($context, 'Demande : '), strpos($context, '100198 Rotules'));
    }

    public function testTheCarsDecodedCodeIsCarriedWithItsDate(): void
    {
        $vehicle = FinderVehicle::fromPayload(['carId' => 16182, 'make' => 'PEUGEOT', 'buildCode' => ['organisationNumber' => '09824', 'date' => '2003-10-02', 'codeKind' => 'dam']]);

        $this->assertSame('09824', $vehicle?->organisationNumber);
        $this->assertStringContainsString(
            'Code de fabrication du véhicule (déjà décodé, ne le redemande pas) : OPR 09824, fabriqué le 2003-10-02. Compare-le directement aux ruptures OPR des pièces : quand il tranche, sélectionne la variante (show_parts clusterId) sans poser la question.',
            $this->builder()->build($this->turn(vehicle: $vehicle)),
        );
    }

    public function testTheCodeWithoutADecoderIsPrintedWithoutADate(): void
    {
        $vehicle = FinderVehicle::fromPayload(['carId' => 19107, 'make' => 'MAZDA', 'buildCode' => ['organisationNumber' => '09824']]);

        $context = $this->builder()->build($this->turn(vehicle: $vehicle));

        $this->assertStringContainsString('OPR 09824.', $context);
        $this->assertStringNotContainsString('fabriqué le', $context);
    }

    public function testAMalformedBuildCodeNeverRejectsTheVehicle(): void
    {
        foreach ([['organisationNumber' => 'abc'], ['organisationNumber' => 982], 'x', []] as $buildCode) {
            $vehicle = FinderVehicle::fromPayload(['carId' => 16182, 'buildCode' => $buildCode]);
            $this->assertNotNull($vehicle);
            $this->assertNull($vehicle->organisationNumber);
        }
        $this->assertStringNotContainsString('Code de fabrication du véhicule', $this->builder()->build($this->turn(vehicle: new FinderVehicle(16182))));
    }

    public function testNoVehicleNoCategoryList(): void
    {
        $context = $this->builder()->build($this->turn(vehicle: null));

        $this->assertStringNotContainsString('Catégories du catalogue', $context);
        $this->assertStringNotContainsString('Rotules', $context);
    }

    private function builder(): FinderContextBuilder
    {
        return new FinderContextBuilder(new FinderCategoryList(new TecdocCatalog($this->dir.'/catalog.sqlite')), new BuildCodeDecoders([new PsaBuildCodeDecoder()]));
    }

    private function turn(
        string $instruction = 'rotule axiale',
        string $pendingContext = '',
        string $summary = '',
        ?BuildLabelHints $buildLabel = null,
        ?FinderVehicle $vehicle = new FinderVehicle(19107),
    ): RunAgentTurn {
        return new RunAgentTurn(
            instruction: $instruction,
            sessionTopic: 'https://localhost/agent/sessions/s',
            owner: 'o',
            summary: $summary,
            pendingContext: $pendingContext,
            surface: TurnSurface::Finder,
            vehicle: $vehicle,
            buildLabel: $buildLabel,
        );
    }
}
