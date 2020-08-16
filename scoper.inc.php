<?php

return [
    'prefix' => null,                       // string|null
    'finders' => [],                        // Finder[]
    'patchers' => [],                       // callable[]
    'files-whitelist' => [],                // string[]
    'whitelist' => [
        'Swoole\\*',
        'Workerman\\*'
    ],                                      // string[]
    'whitelist-global-constants' => false,   // bool
    'whitelist-global-classes' => true,     // bool
    'whitelist-global-functions' => true,   // bool
];