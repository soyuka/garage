<?php

declare(strict_types=1);

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    // @mago-expect analysis:mixed-argument, analysis:mixed-operand the runtime resolves APP_ENV and APP_DEBUG before calling this
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
