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
