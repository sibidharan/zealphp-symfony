# zealphp-symfony

**Run your Symfony app exactly as-is, and add a WebSocket endpoint, or a sub-millisecond health route, or a CRON-driven background task — all in the same PHP process. No Mercure. No Node sidecar. No separate Ratchet/Centrifugo binary.**

This isn't "Symfony but faster." Your Symfony app keeps doing what Symfony already does well — routing, security, forms, Doctrine, Twig, profiler. We just mount it as the fallback inside [ZealPHP](https://github.com/sibidharan/zealphp) (a PHP framework on OpenSwoole) and unlock the things Symfony can't natively ship: long-lived processes, native WebSockets, atomic in-memory state shared across handlers.

## The 30-line argument

```php
<?php
// one process, one port, three layers
require_once __DIR__ . '/symfony/vendor/autoload_runtime.php';

use App\Kernel;
use OpenSwoole\Table;
use ZealPHP\{App, Counter, Store};
use ZealPHP\Symfony\KernelRunner;

App::superglobals(false);                    // coroutine mode
$app = App::init('0.0.0.0', 8080);

Store::make('chat_clients', 256, ['fd' => [Table::TYPE_INT, 4]]);
$requests = new Counter();

// Layer 1: native ZealPHP routes — bypass Symfony entirely for hot paths
$app->route('/health',  fn() => ['ok' => true, 'served' => $requests->increment()]);
$app->route('/metrics', fn() => ['requests' => $requests->get(), 'ws' => Store::table('chat_clients')->count()]);

// Layer 2: WebSocket broadcast — no Mercure, no Centrifugo, no Node
$app->ws('/ws/chat',
    onOpen:    fn($s, $r) => Store::set('chat_clients', (string) $r->fd, ['fd' => $r->fd]),
    onMessage: function ($s, $f) {
        foreach (Store::table('chat_clients') as $c) {
            $s->isEstablished((int) $c['fd']) and $s->push((int) $c['fd'], $f->data);
        }
    },
    onClose:   fn($s, $fd) => Store::del('chat_clients', (string) $fd),
);

// Layer 3: your existing Symfony app, unchanged, as the catch-all
$kernel = new Kernel('prod', false);
App::onWorkerStart(fn() => $kernel->boot());
$app->setFallback(KernelRunner::asHandler($kernel));

$app->run(['worker_num' => 4]);
```

That's it. `/health` answers in <1ms. `/ws/chat` does real-time broadcast. **Everything else flows into your existing Symfony Kernel** — routes, controllers, twig templates, doctrine entities, security firewalls, profiler — exactly as it does today on php-fpm. Same `App\Kernel`, same `config/`, same `src/`.

## What this is — and what it isn't

**Is**:
- A Symfony Runtime adapter so `php public/index.php` runs your Symfony app on OpenSwoole (kernel boots once per worker, reused across requests)
- A ZealPHP-native handler (`KernelRunner::asHandler($kernel)`) so you can mount Symfony under a fallback and write native routes / WebSocket endpoints next to it
- A way to share state between the two via OpenSwoole's `Store` (cross-worker hash map) and `Counter` (atomic int)

**Is not**:
- A "Symfony on steroids" rewrite. Your existing Symfony perf characteristics carry over almost exactly.
- A replacement for Symfony Messenger, Mercure, or any other Symfony-ecosystem tool. They keep working. We just give you a place to put the things they're not the right answer for.
- A coroutine-aware Doctrine driver. OpenSwoole 26.2 doesn't ship PDO hooks, so Doctrine queries are still synchronous from the worker's perspective. See "Honest perf" below.

## Why mix Symfony with native routes / WebSocket at all?

Real situations from real apps:

- **You have a Symfony app and want a `/health` endpoint your load balancer hits 50× a second.** Going through the full Symfony Kernel adds ~20ms of overhead for a route that should be <1ms. Native ZealPHP route returns `['ok' => true]` in ~0.5ms.

- **You want real-time notifications (chat, presence, live updates).** Without us: install Mercure (separate Go binary, reverse-proxy config), or run Ratchet in a second PHP process (can't share memory with Symfony, need Redis for cross-process state). With us: `$app->ws('/notifications', ...)` next to your Symfony routes. `Store` lets your Symfony controllers `push` to connected clients via the WS handler in the same process.

- **You want a metrics endpoint that exposes Symfony's request counter, your queue depth, and your WebSocket connection count.** With us: all three pieces of state live in `Store` / `Counter`. Native route reads them, returns JSON in 0.5ms.

- **You're migrating from a legacy `index.php` monolith to Symfony.** Mount Symfony at the fallback, point native routes at `App::include()` for the legacy paths you haven't ported yet. Migrate gradually instead of big-bang.

## Two ways to use it

### A. Drop-in via Symfony Runtime (no code changes)

Add to your Symfony app's `composer.json`:

```json
{
    "require": {
        "sibidharan/zealphp-symfony": "^0.1"
    },
    "extra": {
        "runtime": {
            "class": "ZealPHP\\Symfony\\Runtime",
            "host": "0.0.0.0",
            "port": 9090,
            "settings": {"worker_num": 4}
        }
    }
}
```

Then `composer dump-autoload` and `php public/index.php`. Your existing Symfony app now runs on OpenSwoole with the Kernel cached per worker. Use this when you have a working Symfony app and just want the long-running runtime — no native routes, no WebSocket, no extras.

### Sessions (required wiring)

Symfony's `NativeSessionStorage` reads and writes the `$_SESSION` superglobal directly. Under a long-running OpenSwoole worker that does NOT behave the way the SAPI does — `session_status()` always reports active, the per-request `$_SERVER`/`$_SESSION` reset is the framework's job, and PHP never re-populates `$_SESSION` from the cookie. Left alone, every Symfony request sees an empty session and emits `Set-Cookie: PHPSESSID=deleted` — sessions never persist.

The bridge ships a drop-in storage that fixes this. Wire it in two files:

```yaml
# config/packages/framework.yaml
framework:
    session:
        storage_factory_id: ZealPHP\Symfony\Session\CoroutineSessionStorageFactory
```

```yaml
# config/services.yaml
services:
    ZealPHP\Symfony\Session\CoroutineSessionStorageFactory:
        arguments:
            $options: '%session.storage.options%'
```

That's it — register the factory and point `framework.session.storage_factory_id` at it. `KernelRunner` runs the app in the session-safe lifecycle (`superglobals(true) + enableCoroutine(false) + processIsolation(false) + sessionLifecycle(false)`): one request at a time per worker, native `$_SESSION` populated per request, scale via `worker_num` (FPM-style).

> **Why the coroutine scheduler is off.** Symfony's container services (session listener, security token storage, …) are per-worker singletons that are not coroutine-aware. With the scheduler ON, concurrent coroutines on one worker race that shared state and bleed sessions across users. The bridge therefore serialises requests per worker. If you need parallel I/O inside a request, push it to an OpenSwoole task worker. Coroutine-per-request concurrency for stateful Symfony apps needs a coroutine-aware container and is tracked for a future release.

### B. ZealPHP-native with Symfony mounted (mixed mode)

Write a custom `app.php` (see `examples/mixed-app.php` for the full thing). Use this when you want native ZealPHP routes / WebSocket alongside Symfony — the actual unique-value path.

## Honest perf

Three measurements against the official `symfony/symfony-demo` app, 2 workers:

| Path | Throughput (c=25) | p50 | Why |
|------|-------------------|-----|-----|
| `/health` (native ZealPHP) | **~14,500 req/s** | <1ms | No Symfony involvement |
| `/en/blog/` (full Symfony, 96KB Twig) | 32 req/s | 23ms warm / 137ms cold | Kernel cached after first request |
| `/en/blog/` on **stock php-fpm + nginx** (estimated baseline) | ~25-40 req/s | ~50ms | Re-bootstrap per request |

For Symfony alone, the win is modest: kernel-warmth saves the ~20-40ms per-request bootstrap cost, no more. **There is no "10× faster" claim here.** OpenSwoole 26.2 doesn't ship `HOOK_PDO_*` constants, so Doctrine PDO queries block the worker even in coroutine mode. Per-request throughput stays CPU-bound.

What scales differently is the **architecture**, not the per-request perf:

- Native routes serve at OpenSwoole-native speeds — orders of magnitude faster than Symfony for trivial endpoints, because they skip the entire framework.
- WebSocket clients stay connected through one process — no IPC, no Redis, no separate worker pool.
- Memory shared in-process via `Store` / `Counter` — atomic cross-handler state without an external dependency.

If you need coroutine-yielding DB queries, you'll need a coroutine-aware DB client (Hyperf's `hyperf/database` is the precedent in the OpenSwoole ecosystem). Roadmap item; not shipped today.

## Try the Symfony Demo locally

```bash
git clone https://github.com/sibidharan/zealphp-symfony
cd zealphp-symfony
composer install
bash examples/setup-symfony-demo.sh    # ~3 min, downloads + wires the official Symfony Demo
cd examples/symfony-demo && php public/index.php
```

Browser → `http://127.0.0.1:9090/en/` — full Symfony Demo, Twig, Doctrine, profiler, all working.

Then back in the parent dir:

```bash
php examples/mixed-app.php    # the 3-layer demo
```

Same port, but now:
- `curl http://127.0.0.1:9090/health` → `{"ok":true,...}` in <1ms
- `wscat -c ws://127.0.0.1:9090/ws/chat` → real WebSocket
- `curl http://127.0.0.1:9090/en/blog/` → still the full Symfony Demo

All from one PHP file, one process.

## Per-request hygiene

Symfony's `services_resetter` is called after every `Kernel::terminate()`. This is the official Symfony mechanism for clearing per-request state on long-lived workers; the bridge wires it correctly. It handles:

- `Doctrine\ORM\EntityManager` — identity map cleared, dirty entities discarded
- `Symfony\Component\HttpFoundation\Session\Session` — saved + new instance
- `Monolog\Logger` — handler buffers flushed
- `Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage` — token cleared
- Any service implementing `Symfony\Contracts\Service\ResetInterface`

If your custom service holds request-scoped state, implement `ResetInterface` and the bridge resets it automatically — same as RoadRunner and Octane.

**Doctrine connection caveat**: connections are pooled across requests, not opened fresh per request. The `services_resetter` clears the `EntityManager`'s unit-of-work but the underlying `Connection` survives. If your app uses `SET SESSION` statements, savepoints, or temp tables, reset them explicitly via a `kernel.finish_request` listener.

## Caveats — what to know before running this in prod

Honest list of gaps you'll hit, organised by what will bite you first. None of these are dealbreakers — they're things to think about, same as you'd think about with RoadRunner, FrankenPHP, or Octane.

### Will hit you on day one

- **Workers don't auto-recycle.** ZealPHP defaults `max_request` to 100,000 globally but the bridge doesn't enforce a Symfony-aware recycle policy. Symfony has known small leaks (Twig template cache growth, Doctrine metadata accumulation, lazy-proxied container references). A busy worker hits multi-GB RSS over days. Pass `'max_request' => 1000` (or whatever fits your churn) in your `app.php` settings, and pair it with `OnWorkerStart` warmup so the recycle cost is amortised. RoadRunner / Octane both default to similar values.

- **Trusted proxies and `X-Forwarded-For` are not auto-wired.** Behind Caddy/nginx/Traefik, `$request->getClientIp()` will return the proxy IP, `$request->isSecure()` will return `false` for HTTPS traffic terminated at the proxy, and rate-limiters / IP allow-lists / `getRealIp()` will all be wrong. Call `Request::setTrustedProxies([...your proxy CIDRs...], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO)` from a `kernel.request` listener (or use Symfony's [`framework.trusted_proxies`](https://symfony.com/doc/current/deployment/proxies.html) config). The bridge can't do this for you because trust depends on your deployment topology.

- **Cold-boot tax per worker on the first request.** Symfony compiles the container on the first request to each fresh worker (~200-500ms). With 4 workers, that's 4 cold-boot taxes after every deploy. Run `php bin/console cache:warmup` (and `--env=prod --no-debug` for prod) in your deploy pipeline before starting the server.

- **WebSocket handlers bypass the Symfony security firewall.** `$app->ws()` is native ZealPHP — Symfony's firewall config doesn't apply because the WS handler never touches the Kernel. If you want session-authenticated WS, read `$request->cookie['PHPSESSID']` in `onOpen`, look up the session in your storage (Symfony's session save handler is fine — `session_id($cookieValue); session_start(); $userId = $_SESSION['user_id'] ?? null;`), and close the connection with a 1008 status if auth fails. Same pattern as Centrifugo / Mercure — security is your call, not the framework's.

- **Long-running PHP discipline contract still applies.** `static $cache = []` inside a method, singletons created in a service constructor, globals you mutate, `define()` calls — all persist across requests on the same worker. Symfony itself is well-behaved here (it has `services_resetter` for exactly this); third-party bundles and your own code are not always. If a value should reset per request, register your service with [`Symfony\Contracts\Service\ResetInterface`](https://github.com/symfony/contracts/blob/main/Service/ResetInterface.php) — the bridge calls `services_resetter` after every `Kernel::terminate()`.

### Developer experience

- **Edit a Twig template, refresh — still serves the old version.** The kernel + Twig cache live in the worker's memory until the worker recycles. RoadRunner has a `--debug` mode that watches files; we don't. Workaround: in dev mode, run with `worker_num=1` and pass `max_request=1` so each request gets a fresh worker. Slower, but file edits propagate immediately.

- **Profiler caches accumulate on disk.** Symfony's profiler writes one file per request to `var/cache/dev/profiler/{token}`. Per-request, every request. Disk fills. Same problem on stock Symfony dev — just more visible at long-running throughput. Cron a `cache:clear` or trim `var/cache/dev/profiler/` manually.

- **Symfony Messenger needs separate consumer processes.** Messenger expects you to run `bin/console messenger:consume` in a separate worker pool. OpenSwoole's task workers (`'task_worker_num' => N`) are conceptually the right fit, but the bridge doesn't wire them to Messenger transports yet. For now, run Messenger consumers as separate `php bin/console messenger:consume` processes alongside the ZealPHP HTTP workers — same as you would with php-fpm.

### Edge cases worth knowing

- **`StreamedResponse` is buffer-collected, not actually streamed.** Sending Server-Sent Events with the default `$response = new StreamedResponse(...)` will collect the entire response in memory before flushing. SSE works but doesn't stream incrementally. True per-chunk streaming needs an OpenSwoole-aware emitter; on the roadmap.

- **`$_GET`/`$_POST`/`$_FILES` are empty** because the bridge calls `App::superglobals(false)` for coroutine isolation. Symfony itself reads the request via the `Request` object (which we build correctly), so it doesn't notice. But legacy bundles or your own old code that read `$_GET` directly will see nothing. Either rewrite to use `$request->query->get(...)` or flip the bridge to `App::superglobals(true)` and accept the request-serialisation cost.

- **File uploads pass through, lightly tested.** `$swoole->files` is mapped to Symfony's `Request->files` via the standard format (`tmp_name`, `size`, `error`, `name`, `type`). Should work for the common case; if you hit weirdness with multipart parsing report a repro.

- **Doctrine connection state survives across requests.** This is the same `SET SESSION` / temporary tables / `GET_LOCK` / user-variables family of issues that the v0.3 `PDOPool` design has to solve. Today the bridge does not pool connections itself — Doctrine's own connection survives per worker, which means state leaks across requests on the same worker. The v0.3 `MysqlPool` / `PgsqlPool` work (driver-aware reset via `COM_RESET_CONNECTION` / `DISCARD ALL`) is the structural fix. Until then: avoid `SET SESSION`, `CREATE TEMPORARY TABLE`, `GET_LOCK`, and user-variables in request-path code, or wrap them in `try/finally` that resets them.

- **Symfony's signal handlers won't propagate cleanly through OpenSwoole.** Symfony 6.4+ has a `SignalRegistry` for things like `Console::handleSignals()`. OpenSwoole installs its own signal handlers for `SIGTERM` / `SIGUSR1` (graceful restart). If your code installs a signal handler via `pcntl_signal()`, it'll be overwritten. Use OpenSwoole's `$server->on('Shutdown', ...)` instead.

## Compatibility

- PHP 8.2+ (Symfony Runtime requirement) / 8.3+ recommended (ZealPHP target)
- Symfony 7.1+
- ZealPHP 0.2.19+
- OpenSwoole 22.0+ (with PSR-7 1.x — see "psr/http-message version pin" in docs)

## License

MIT.

## See also

- [ZealPHP](https://github.com/sibidharan/zealphp) — the underlying framework
- [Symfony Runtime component](https://symfony.com/doc/current/components/runtime.html) — the official extension point this plugs into
- [RoadRunner](https://roadrunner.dev) / [FrankenPHP](https://frankenphp.dev) / [Laravel Octane](https://laravel.com/docs/octane) — the same "long-lived worker" idea in adjacent ecosystems
