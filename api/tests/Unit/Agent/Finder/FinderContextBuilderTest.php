<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Finder;

use App\Agent\Finder\BuildLabelHints;
use App\Agent\Finder\FinderContextBuilder;
use App\Agent\Finder\FinderVehicle;
use App\Agent\TurnSurface;
use App\Message\RunAgentTurn;
use PHPUnit\Framework\TestCase;

final class FinderContextBuilderTest extends TestCase
{
    public function testAFollowUpCarriesTheQuestionTheOprAndTheDecodeInstruction(): void
    {
        $context = (new FinderContextBuilder())->build($this->turn(
            instruction: 'mon OPR est 10512',
            pendingContext: 'rotule axiale 307 : deux montages selon le n° d\'organisation',
            buildLabel: BuildLabelHints::fromPayload(['opr' => '10512', 'printedCodes' => ['DAM 10512', '1051288']]),
        ));

        $this->assertStringContainsString('rotule axiale 307 : deux montages selon le n° d\'organisation', $context);
        $this->assertStringContainsString('OPR 10512', $context);
        $this->assertStringContainsString('DAM 10512 ; 1051288', $context);
        $this->assertStringContainsString('decode_build_code', $context);
        $this->assertStringEndsWith('Demande : mon OPR est 10512', $context);
    }

    public function testABuildLabelWithoutOprStillListsTheCodes(): void
    {
        $context = (new FinderContextBuilder())->build($this->turn(buildLabel: new BuildLabelHints(null, ['EWPA'])));

        $this->assertStringContainsString('codes : EWPA', $context);
        $this->assertStringNotContainsString('OPR', $context);
    }

    public function testAnEmptyBuildLabelIsNotRendered(): void
    {
        $context = (new FinderContextBuilder())->build($this->turn(buildLabel: new BuildLabelHints()));

        $this->assertStringNotContainsString('Vignette constructeur', $context);
    }

    public function testTheOnScreenResultIsRenderedAndCapped(): void
    {
        $context = (new FinderContextBuilder())->build($this->turn(summary: 'Rotule axiale (100123) — groupe A : TRW JAR123 '.str_repeat('x', 3000)));

        $this->assertStringContainsString("Résultat affiché à l'écran (la demande le poursuit) : Rotule axiale (100123) — groupe A : TRW JAR123", $context);
        $line = explode("\n", $context)[array_search(true, array_map(static fn (string $l): bool => str_starts_with($l, 'Résultat affiché'), explode("\n", $context)), true)];
        $this->assertLessThanOrEqual(2100, mb_strlen($line));
    }

    public function testNoSummaryNoResultLine(): void
    {
        $this->assertStringNotContainsString('Résultat affiché', (new FinderContextBuilder())->build($this->turn()));
    }

    public function testThePlateIsKeptOnTheVehicleLine(): void
    {
        $vehicle = FinderVehicle::fromPayload(['carId' => 19107, 'carType' => 'PC', 'make' => 'PEUGEOT', 'model' => '307', 'plate' => '  XB-301-ZB ']);

        $this->assertSame('XB-301-ZB', $vehicle?->plate);
        $this->assertStringContainsString('plaque XB-301-ZB', (new FinderContextBuilder())->build($this->turn(vehicle: $vehicle)));
    }

    public function testAnOverlongPlateIsDropped(): void
    {
        $vehicle = FinderVehicle::fromPayload(['carId' => 19107, 'plate' => str_repeat('A', 21)]);

        $this->assertNotNull($vehicle);
        $this->assertNull($vehicle->plate);
        $this->assertNull(FinderVehicle::fromPayload(['carId' => 19107, 'plate' => 42])?->plate);
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
