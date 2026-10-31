<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config', __DIR__.'/public', __DIR__.'/bin'])
    ->exclude(['var', 'vendor'])
    ->notPath('reference.php')
    ->append([__DIR__.'/bin/console', __FILE__]);

return (new Config())
    ->setRules([
        '@Symfony' => true,
    ])
    ->setFinder($finder);
