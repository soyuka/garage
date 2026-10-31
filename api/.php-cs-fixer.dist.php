<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config', __DIR__.'/public', __DIR__.'/bin'])
    ->exclude(['bundles'])
    ->notPath([
        'bundles.php',
        'reference.php',
    ])
    ->append([__DIR__.'/bin/console'])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        // @Symfony:risky defaults to `remove` (Symfony itself does not use strict types); this codebase does.
        'declare_strict_types' => ['strategy' => 'add_when_missing'],
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
;
