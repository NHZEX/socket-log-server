<?php

return [
    'prefix' => null,                       // string|null
    'finders' => [],                        // Finder[]
    'patchers' => [],                       // callable[]
    'files-whitelist' => [],                // string[]
    'whitelist' => [
        'Swoole\\*'
    ],                                      // string[]
    'whitelist-global-constants' => true,   // bool
    'whitelist-global-classes' => true,     // bool
    'whitelist-global-functions' => true,   // bool
];