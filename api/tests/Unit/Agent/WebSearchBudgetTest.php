<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\WebSearchBudget;
use PHPUnit\Framework\TestCase;

final class WebSearchBudgetTest extends TestCase
{
    public function testNotExhaustedUntilTheLimitIsRecorded(): void
    {
        $budget = new WebSearchBudget(1);

        $this->assertFalse($budget->exhausted());

        $budget->record(1);

        $this->assertTrue($budget->exhausted());
    }

    public function testResetClearsTheCount(): void
    {
        $budget = new WebSearchBudget(1);
        $budget->record(1);

        $budget->reset();

        $this->assertFalse($budget->exhausted());
    }

    public function testRecordingZeroDoesNotConsumeBudget(): void
    {
        $budget = new WebSearchBudget(1);

        $budget->record(0);

        $this->assertFalse($budget->exhausted());
    }

    public function testDefaultsToTwoSearchesPerTurn(): void
    {
        $budget = new WebSearchBudget();

        $budget->record(1);
        $this->assertFalse($budget->exhausted(), 'one search of two must leave room for a retry');

        $budget->record(1);
        $this->assertTrue($budget->exhausted());
    }
}
