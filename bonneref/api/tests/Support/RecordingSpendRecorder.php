<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Agent\Journal\ModelUsage;
use App\Security\SpendRecorder;

final class RecordingSpendRecorder implements SpendRecorder
{
    /** @var list<array{model: string, promptTokens: int, completionTokens: int}> */
    public array $records = [];

    public function record(string $source, string $model, ModelUsage $usage): void
    {
        $this->records[] = ['model' => $model, 'promptTokens' => $usage->promptTokens, 'completionTokens' => $usage->completionTokens];
    }
}
