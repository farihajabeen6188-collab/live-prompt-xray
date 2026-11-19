<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/app/src', __DIR__.'/app/tests', __DIR__.'/app/config', __DIR__.'/lib/xray-bundle/src', __DIR__.'/lib/xray-bundle/tests', __DIR__.'/lib/xray-bundle/config'])
    ->notPath(['bundles.php', 'reference.php', 'preload.php'])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        'final_class' => true,
    ])
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__.'/app/var/.php-cs-fixer.cache')
    ->setFinder($finder)
;
