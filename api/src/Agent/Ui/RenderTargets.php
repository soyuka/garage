<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use App\Garage\ToolError;

/**
 * Turns a model-supplied vehicle or task handle into the canonical relative IRI a frame may carry, or
 * refuses it. The dashboard acts on these with the operator's bearer (selects the vehicle, PATCHes the
 * CTA's task), so only a well-formed handle to one of the CURRENT owner's records gets through.
 */
interface RenderTargets
{
    public function vehicle(string $handle): string|ToolError;

    public function task(string $handle): string|ToolError;
}
