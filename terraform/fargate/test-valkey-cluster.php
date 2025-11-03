<?php
/**
 * Test Cache::flexible() on Valkey Serverless with all 4 patterns
 *
 * Patterns:
 * 1. phpredis + default (single node connection)
 * 2. phpredis + clusters (cluster mode connection)
 * 3. predis + default (single node connection)
 * 4. predis + clusters (cluster mode connection)
 */

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Cache\CacheManager;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;

require __DIR__ . '/vendor/autoload.php';

$valkeyEndpoint = getenv('VALKEY_ENDPOINT');
$valkeyPort = getenv('VALKEY_PORT') ?: '6379';

echo "=== Valkey Serverless Cache::flexible() - 4 Pattern Test ===\n";
echo "Endpoint: {$valkeyEndpoint}\n";
echo "Port: {$valkeyPort}\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo "\n";

$results = [];

// Pattern definitions
$patterns = [
    [
        'name' => 'Pattern 1: phpredis + default (single node)',
        'client' => 'phpredis',
        'connection_type' => 'default',
    ],
    [
        'name' => 'Pattern 2: phpredis + clusters (cluster mode)',
        'client' => 'phpredis',
        'connection_type' => 'clusters',
    ],
    [
        'name' => 'Pattern 3: predis + default (single node)',
        'client' => 'predis',
        'connection_type' => 'default',
    ],
    [
        'name' => 'Pattern 4: predis + clusters (cluster mode)',
        'client' => 'predis',
        'connection_type' => 'clusters',
    ],
];

foreach ($patterns as $index => $pattern) {
    echo str_repeat('=', 70) . "\n";
    echo $pattern['name'] . "\n";
    echo str_repeat('=', 70) . "\n\n";

    try {
        // Set environment variables
        putenv('REDIS_HOST=' . $valkeyEndpoint);
        putenv('REDIS_PORT=' . $valkeyPort);
        putenv('REDIS_SCHEME=tls');
        putenv('REDIS_CLIENT=' . $pattern['client']);
        putenv('CONNECTION_TYPE=' . $pattern['connection_type']);

        // Create new Container (independent for each pattern)
        $app = new Container();
        Container::setInstance($app);

        // Configure Config
        $app->singleton('config', function () {
            $config = new ConfigRepository();
            $databaseConfig = require __DIR__ . '/database.php';
            $config->set('database', $databaseConfig);

            $config->set('cache', [
                'default' => 'redis',
                'stores' => [
                    'redis' => [
                        'driver' => 'redis',
                        'connection' => 'default',
                    ],
                ],
            ]);

            return $config;
        });

        // Configure Redis Manager
        $app->singleton('redis', function ($app) {
            $config = $app['config']['database.redis'];
            return new RedisManager($app, $config['client'], $config);
        });

        // Configure Cache Manager
        $app->singleton('cache', function ($app) {
            return new CacheManager($app);
        });

        $app->singleton('cache.store', function ($app) {
            return $app['cache']->driver();
        });

        // Configure Facade
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        // Verify configuration
        $config = $app['config']['database.redis'];
        echo "Configuration:\n";
        echo "  client: " . $config['client'] . "\n";
        if ($pattern['connection_type'] === 'default') {
            echo "  connection: default\n";
            echo "  scheme: " . $config['default']['scheme'] . "\n";
            echo "  host: " . $config['default']['host'] . "\n";
        } else {
            echo "  connection: clusters\n";
            echo "  nodes: " . count($config['clusters']['default']) . "\n";
        }
        echo "\n";

        // Add connection test for Pattern 2
        if ($index === 1) {
            echo "[Connection Test] Testing RedisCluster object before commands\n";
            try {
                $redis = $app['redis']->connection()->client();

                // Get RedisCluster object information
                echo "  RedisCluster class: " . get_class($redis) . "\n";

                // Get option information
                if (method_exists($redis, 'getOption')) {
                    try {
                        $timeout = $redis->getOption(\RedisCluster::OPT_READ_TIMEOUT);
                        echo "  READ_TIMEOUT: {$timeout}\n";
                    } catch (\Throwable $e) {
                        echo "  READ_TIMEOUT: (error)\n";
                    }
                }

                // Verify connection information with _masters() method
                if (method_exists($redis, '_masters')) {
                    try {
                        $masters = $redis->_masters();
                        echo "  Masters: " . json_encode($masters) . "\n";
                    } catch (\Throwable $e) {
                        echo "  Masters: (error: " . $e->getMessage() . ")\n";
                    }
                }

                echo "\n[Connection Test] Testing basic Redis commands\n";

                // SET test
                echo "  Executing SET...\n";
                $setResult = $redis->set('test:connection:key', 'test-value');
                echo "  SET: " . ($setResult ? 'SUCCESS' : 'FAILED') . "\n";

                // GET test
                $getValue = $redis->get('test:connection:key');
                echo "  GET: " . ($getValue === 'test-value' ? 'SUCCESS' : 'FAILED') . " (value: {$getValue})\n";

                // DEL test
                $delResult = $redis->del('test:connection:key');
                echo "  DEL: " . ($delResult ? 'SUCCESS' : 'FAILED') . "\n";

                echo "  Basic commands: ALL PASSED\n\n";
            } catch (\Throwable $connErr) {
                echo "  FAILED\n";
                echo "  Error class: " . get_class($connErr) . "\n";
                echo "  Error message: " . $connErr->getMessage() . "\n";
                echo "  Error code: " . $connErr->getCode() . "\n";
                echo "  File: " . $connErr->getFile() . ":" . $connErr->getLine() . "\n";

                // Display first few lines of stack trace
                $trace = $connErr->getTrace();
                if (!empty($trace)) {
                    echo "  First trace entry:\n";
                    $first = $trace[0];
                    echo "    Function: " . ($first['function'] ?? 'unknown') . "\n";
                    echo "    Class: " . ($first['class'] ?? 'none') . "\n";
                    echo "    File: " . ($first['file'] ?? 'unknown') . ":" . ($first['line'] ?? '?') . "\n";
                }
                echo "\n";
            }
        }

        // Cache::flexible() test
        echo "[Test] Cache::flexible()\n";
        $counter = 0;
        $startTime = microtime(true);

        $result = Cache::flexible(
            "test:flexible:pattern{$index}",
            [30, 60],
            function () use (&$counter) {
                $counter++;
                return "Generated value #{$counter}";
            },
            ['seconds' => 5]
        );

        $elapsed = microtime(true) - $startTime;

        echo "✓ SUCCESS\n";
        echo "  Result: {$result}\n";
        echo "  Callback called: {$counter} time(s)\n";
        echo "  Time: " . round($elapsed, 3) . "s\n\n";

        $results[] = [
            'pattern' => $pattern['name'],
            'status' => 'SUCCESS',
            'result' => $result,
            'time' => round($elapsed, 3),
        ];

    } catch (\Predis\Response\ServerException $e) {
        echo "✗ FAILED (Predis ServerException)\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";

        // Get Predis error type
        if (method_exists($e, 'getErrorType')) {
            echo "  Error type: " . $e->getErrorType() . "\n";
        }

        echo "  Stack trace:\n";
        foreach (explode("\n", $e->getTraceAsString()) as $line) {
            echo "    " . $line . "\n";
        }
        echo "\n";

        $results[] = [
            'pattern' => $pattern['name'],
            'status' => 'FAILED',
            'error_type' => 'Predis\Response\ServerException',
            'error_message' => $e->getMessage(),
        ];

    } catch (\Predis\NotSupportedException $e) {
        echo "✗ FAILED (Predis NotSupportedException)\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "  Stack trace:\n";
        foreach (explode("\n", $e->getTraceAsString()) as $line) {
            echo "    " . $line . "\n";
        }
        echo "\n";

        $results[] = [
            'pattern' => $pattern['name'],
            'status' => 'FAILED',
            'error_type' => 'Predis\NotSupportedException',
            'error_message' => $e->getMessage(),
        ];

    } catch (\RedisClusterException $e) {
        echo "✗ FAILED (RedisClusterException)\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";

        // Get Redis last error
        try {
            $redis = $app['redis']->connection()->client();
            if (method_exists($redis, 'getLastError')) {
                $lastError = $redis->getLastError();
                echo "  Redis last error: " . ($lastError ?: 'none') . "\n";
            }
        } catch (\Throwable $err) {
            // Ignore
        }

        echo "  Stack trace:\n";
        foreach (explode("\n", $e->getTraceAsString()) as $line) {
            echo "    " . $line . "\n";
        }
        echo "\n";

        $results[] = [
            'pattern' => $pattern['name'],
            'status' => 'FAILED',
            'error_type' => 'RedisClusterException',
            'error_message' => $e->getMessage(),
        ];

    } catch (\RedisException $e) {
        echo "✗ FAILED (RedisException)\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";

        // Get Redis last error
        try {
            $redis = $app['redis']->connection()->client();
            if (method_exists($redis, 'getLastError')) {
                $lastError = $redis->getLastError();
                echo "  Redis last error: " . ($lastError ?: 'none') . "\n";
            }
        } catch (\Throwable $err) {
            // Ignore
        }

        echo "  Stack trace:\n";
        foreach (explode("\n", $e->getTraceAsString()) as $line) {
            echo "    " . $line . "\n";
        }
        echo "\n";

        $results[] = [
            'pattern' => $pattern['name'],
            'status' => 'FAILED',
            'error_type' => 'RedisException',
            'error_message' => $e->getMessage(),
        ];

    } catch (\Throwable $e) {
        echo "✗ FAILED (" . get_class($e) . ")\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";

        // Get Redis last error (for phpredis)
        try {
            $redis = $app['redis']->connection()->client();
            if (method_exists($redis, 'getLastError')) {
                $lastError = $redis->getLastError();
                echo "  Redis last error: " . ($lastError ?: 'none') . "\n";
            }
        } catch (\Throwable $err) {
            // Ignore
        }

        echo "  Stack trace:\n";
        foreach (explode("\n", $e->getTraceAsString()) as $line) {
            echo "    " . $line . "\n";
        }
        echo "\n";

        $results[] = [
            'pattern' => $pattern['name'],
            'status' => 'FAILED',
            'error_type' => get_class($e),
            'error_message' => $e->getMessage(),
        ];
    }
}

// Results summary
echo str_repeat('=', 70) . "\n";
echo "SUMMARY\n";
echo str_repeat('=', 70) . "\n\n";

foreach ($results as $result) {
    $status = $result['status'] === 'SUCCESS' ? '✓' : '✗';
    echo "{$status} {$result['pattern']}: {$result['status']}\n";
    if ($result['status'] === 'SUCCESS') {
        echo "   Time: {$result['time']}s\n";
    } else {
        echo "   Error: {$result['error_type']}\n";
        echo "   Message: {$result['error_message']}\n";
    }
    echo "\n";
}

$successCount = count(array_filter($results, fn($r) => $r['status'] === 'SUCCESS'));
$totalCount = count($results);

echo "Total: {$successCount}/{$totalCount} patterns succeeded\n";
