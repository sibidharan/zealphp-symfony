<?php

declare(strict_types=1);

/**
 * Mixed-mode example: a single OpenSwoole process that serves
 *   - native ZealPHP routes for the lean stuff (health, metrics)
 *   - a WebSocket endpoint (chat broadcast)
 *   - the full Symfony Demo Kernel as the fallback for everything else
 *
 * No Mercure. No Node sidecar. No separate WebSocket server. One process,
 * one port, one deploy. Memory shared across the three layers via
 * OpenSwoole's Store (cross-worker hash map) and Counter (atomic int).
 *
 * Run:
 *   cd examples/symfony-demo
 *   php ../mixed-app.php          # serves on 0.0.0.0:9090
 *
 * Then:
 *   curl http://127.0.0.1:9090/health      -> native (~1ms)
 *   curl http://127.0.0.1:9090/metrics     -> native (~1ms)
 *   curl http://127.0.0.1:9090/en/blog/    -> Symfony (Doctrine + Twig)
 *   wscat -c ws://127.0.0.1:9090/ws/chat   -> WebSocket broadcast
 */

require_once __DIR__ . '/symfony-demo/vendor/autoload_runtime.php';

use App\Kernel;
use OpenSwoole\Table;
use ZealPHP\App;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\Symfony\KernelRunner;

// ---------------------------------------------------------------------
//  Boot Symfony's dotenv + container BEFORE we hand control to ZealPHP.
//  This is what SymfonyRuntime does internally; we replicate the relevant
//  bits because we're going to construct App ourselves rather than letting
//  the Runtime adapter do it.
// ---------------------------------------------------------------------

// Load .env files so DATABASE_URL / APP_SECRET / APP_ENV are populated.
$_SERVER['APP_RUNTIME_OPTIONS'] ??= [];
$dotenvPath = __DIR__ . '/symfony-demo/.env';
if (is_file($dotenvPath) && class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
    (new \Symfony\Component\Dotenv\Dotenv())->loadEnv($dotenvPath);
}
$appEnv   = $_SERVER['APP_ENV']   ?? $_ENV['APP_ENV']   ?? 'dev';
$appDebug = (bool) ($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? '1');

// ---------------------------------------------------------------------
//  Configure ZealPHP and register the cross-layer shared state BEFORE
//  $app->run() — Store and Counter must exist at the master-process
//  level so every worker inherits the same shared memory after fork.
// ---------------------------------------------------------------------

App::superglobals(false);                              // coroutine mode
$app = App::init('0.0.0.0', 9090);

Store::make('chat_clients', 256, [
    'fd'         => [Table::TYPE_INT, 4],
    'joined_at'  => [Table::TYPE_INT, 4],
]);
$requestCounter = new Counter();                       // global request counter

// ---------------------------------------------------------------------
//  Layer 1 — native ZealPHP routes (sub-millisecond, no Symfony involvement)
// ---------------------------------------------------------------------

$app->route('/health', function () use ($requestCounter) {
    $requestCounter->increment();
    return ['status' => 'ok', 'served' => $requestCounter->get()];
});

$app->route('/metrics', function () use ($requestCounter) {
    return [
        'requests_total'       => $requestCounter->get(),
        'ws_clients_connected' => Store::table('chat_clients')->count(),
        'workers'              => (int) (getenv('ZEALPHP_WORKERS') ?: 2),
    ];
});

// ---------------------------------------------------------------------
//  Layer 2 — WebSocket: broadcast chat. Every message a client sends is
//  pushed to every other connected client. Connection map lives in the
//  shared Store so any worker can find any client.
// ---------------------------------------------------------------------

$app->ws('/ws/chat',
    onOpen: function ($server, $request) {
        Store::set('chat_clients', (string) $request->fd, [
            'fd'        => $request->fd,
            'joined_at' => time(),
        ]);
        $server->push($request->fd, json_encode(['type' => 'welcome', 'fd' => $request->fd]));
    },
    onMessage: function ($server, $frame) {
        $payload = json_encode([
            'type' => 'message',
            'from' => $frame->fd,
            'text' => (string) $frame->data,
            'at'   => time(),
        ]);
        foreach (Store::table('chat_clients') as $row) {
            if ($server->isEstablished((int) $row['fd'])) {
                $server->push((int) $row['fd'], $payload);
            }
        }
    },
    onClose: function ($server, $fd) {
        Store::del('chat_clients', (string) $fd);
    },
);

// ---------------------------------------------------------------------
//  Layer 3 — fallback: hand everything else to Symfony. The Kernel boots
//  once per worker (App::onWorkerStart) and is reused across requests.
//  Doctrine, Twig, Profiler, security firewall — all work normally.
// ---------------------------------------------------------------------

$kernel = new Kernel($appEnv, $appDebug);
App::onWorkerStart(static function () use ($kernel) {
    $kernel->boot();
});
$app->setFallback(KernelRunner::asHandler($kernel));

$app->run(['worker_num' => 2, 'task_worker_num' => 0]);
