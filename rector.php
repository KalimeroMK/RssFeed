<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Tests',
        __DIR__ . '/config',
        __DIR__ . '/src',
    ])
    // uncomment to reach your current PHP version
    // ->withPhpSets()
    ->withTypeCoverageLevel(99)
    ->withDeadCodeLevel(99)
    ->withCodeQualityLevel(99);
