<?php

declare(strict_types=1);

namespace App\Security;

use App\Agent\Journal\ModelUsage;

/**
 * Charges a model call made outside an agent turn to the current owner's daily budget ({@see OwnerDailyBudget}).
 */
interface SpendRecorder
{
    public function record(string $source, string $model, ModelUsage $usage): void;
}
