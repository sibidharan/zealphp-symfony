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

// Symfony reads/writes $_SESSION directly (not just via session_*()).
// Coroutine mode keeps $_SESSION decoupled from $g->session, so Symfony's
// writes never persist — sessions appear empty on save and the cookie gets
// invalidated. Superglobals mode bridges $g->session ↔ $_SESSION via
// $GLOBALS, so Symfony sees the data. The coroutine HOOK_ALL benefit is
// a no-op for Symfony+Doctrine anyway (no HOOK_PDO in OpenSwoole 26.2),
// so this isn't a real trade-off for Symfony workloads.
App::superglobals(true);

// Hand session lifecycle to Symfony. Without this, ZealPHP's SessionManager
// also mints a PHPSESSID + emits its own Set-Cookie at request entry,
// producing two competing session cookies on the wire. With this flag,
// ZealPHP still does request-context setup (openswoole_request,
// zealphp_response, error-stack reset) but skips session_start / cookie
// emission. Symfony's NativeSessionStorage owns sessions entirely.
//
// Native ZealPHP routes that want session access can still call session_*()
// directly (those uopz overrides remain installed). For session data shared
// with Symfony, prefer the Symfony Request->getSession() API; for native
// routes outside the Symfony fallback, the underlying $g->session is
// available via session_start() + $_SESSION as usual.
App::sessionLifecycle(false);
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

// /session/dump — native ZealPHP route that runs the full native session
// lifecycle itself. Under App::sessionLifecycle(false), SessionManager
// no longer auto-starts / cookies / writes sessions for any request, so
// any native route that wants $_SESSION must drive it manually:
//   1. session_start()          — read cookie or mint new id, load $_SESSION
//   2. mutate $_SESSION
//   3. Set-Cookie back to client (so the same id arrives next request)
//   4. session_write_close()    — persist $_SESSION to /var/lib/php/sessions
// Hit this once with -c jar, then again with -b jar -c jar — the second
// hit shows the same session_id, the same probe_at, and probe_hits=2.
// Then hit Symfony's /en/login with the same jar; come back here and the
// session payload includes whatever Symfony wrote to the same PHPSESSID.
$app->route('/session/dump', function ($request, $response) {
    session_start();
    $_SESSION['probe_at']   ??= time();
    $_SESSION['probe_hits']  = ($_SESSION['probe_hits'] ?? 0) + 1;

    $sid    = session_id();
    $params = session_get_cookie_params();
    $response->cookie(
        session_name() ?: 'PHPSESSID',
        $sid,
        $params['lifetime'] ? time() + $params['lifetime'] : 0,
        $params['path']    ?? '/',
        $params['domain']  ?? '',
        $params['secure']  ?? false,
        $params['httponly'] ?? true,
    );

    $save = ini_get('session.save_path') ?: '/var/lib/php/sessions';
    $body = [
        'session_id'          => $sid,
        'cookie_received'     => $request->cookie['PHPSESSID'] ?? null,
        'session_data'        => $_SESSION ?? [],
        'save_path'           => $save,
        'session_file_exists' => file_exists("$save/sess_$sid"),
    ];

    session_write_close();
    return $body;
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

// Symfony-side session probe — proves Symfony's Session API writes flow into
// the same physical PHPSESSID that the native /session/dump route reads.
// Symfony's Session class wraps PHP's native session_*() functions; with the
// $_SESSION ↔ ZealPHP bridge in place, Symfony's session->set() lands in the
// same file native session_*() reads, byte-compatible (a:N:{...} format).
$app->route('/sf-session/poke', function ($request, $response) {
    $session = new \Symfony\Component\HttpFoundation\Session\Session(
        new \Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage(),
    );
    $session->start();
    $session->set('symfony_wrote_at', time());
    $session->set('symfony_hits', ((int) $session->get('symfony_hits', 0)) + 1);
    $sid  = $session->getId();
    $hits = $session->get('symfony_hits');
    $session->save();
    $params = session_get_cookie_params();
    $response->cookie('PHPSESSID', $sid,
        0, $params['path'] ?? '/', $params['domain'] ?? '',
        $params['secure'] ?? false, $params['httponly'] ?? true);
    return [
        'from'         => 'symfony',
        'session_id'   => $sid,
        'symfony_hits' => $hits,
    ];
});

$app->setFallback(KernelRunner::asHandler($kernel));

$app->run(['worker_num' => 2, 'task_worker_num' => 0]);
