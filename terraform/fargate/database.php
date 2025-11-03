<?php

$connectionType = getenv('CONNECTION_TYPE') ?: 'default';

$redisConfig = [
    'client' => getenv('REDIS_CLIENT') ?: 'phpredis',
    'options' => [
        'cluster' => getenv('REDIS_CLUSTER') ?: 'redis',
        'prefix' => getenv('REDIS_PREFIX') ?: 'illuminate:',
    ],
];

if ($connectionType === 'clusters') {
    // Cluster configuration
    $host = getenv('REDIS_HOST') ?: 'redis-node-1';
    $port = getenv('REDIS_PORT') ?: '6379';
    $scheme = getenv('REDIS_SCHEME') ?: 'tcp';

    $redisConfig['clusters'] = [
        'default' => [
            [
                'host' => $host,
                'port' => (int)$port,
                'scheme' => $scheme,
            ],
        ],
        'options' => [
            'cluster' => 'redis',
        ],
    ];
} else {
    // Single node configuration
    $redisConfig['default'] = [
        'url' => getenv('REDIS_URL') ?: null,
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'username' => getenv('REDIS_USERNAME') ?: null,
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port' => getenv('REDIS_PORT') ?: '6379',
        'database' => getenv('REDIS_DB') ?: null,
        'scheme' => getenv('REDIS_SCHEME') ?: 'tcp',
    ];

    $redisConfig['cache'] = [
        'url' => getenv('REDIS_URL') ?: null,
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'username' => getenv('REDIS_USERNAME') ?: null,
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port' => getenv('REDIS_PORT') ?: '6379',
        'database' => getenv('REDIS_CACHE_DB') ?: null,
        'scheme' => getenv('REDIS_SCHEME') ?: 'tcp',
    ];
}

return [
    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    */

    'redis' => $redisConfig,
];
