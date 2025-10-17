<?php

return [
    'except' => ['debugbar.*', 'telescope.*', 'horizon.*'],
    'groups' => [
        'admin' => ['admin.*'],
        'api' => ['api.*'],
    ],
    'url' => null,
    'port' => null,
    'domain' => null,
    'only' => [],
    'except' => [],
    'groups' => [],
];
