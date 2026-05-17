# zealphp-symfony

Run Symfony applications on [ZealPHP](https://github.com/sibidharan/zealphp) — Symfony Kernel boots **once per worker**, requests reuse the booted container, no `php-fpm` overhead between requests.

## Why

Stock Symfony on `php-fpm`: bootstrap the Kernel, compile the container, build routes, instantiate every service — **every request**. Even with OPcache that's tens of milliseconds of pure overhead before your handler runs.

This bridge boots the Kernel once when an OpenSwoole worker starts, then serves thousands of requests against the warm container. Doctrine connections stay open. Twig templates stay compiled. Routes stay loaded. Per-request state (the `Request`, the `EntityManager`'s unit-of-work, session, logger buffers) is properly reset between requests via Symfony's official `services_resetter` — so no cross-request state leakage.

The bridge is layered:

- **`ZealPHP\Symfony\Runtime`** — implements `Symfony\Component\Runtime\RuntimeInterface`. Drop-in Symfony Runtime adapter, activated via `APP_RUNTIME` env var. User keeps their normal `public/index.php`.
- **`ZealPHP\Symfony\KernelRunner`** — the underlying engine. Boots the Kernel, runs the OpenSwoole HTTP server, dispatches each request through `Kernel::handle()` + `terminate()` + reset. Can be used standalone from a ZealPHP-native `app.php` if you want to mount Symfony under a route.

## Status

Experimental. Compatible with Symfony 7.1+, PHP 8.2+, ZealPHP 0.2.19+. The Symfony Demo app is the conformance fixture (see `examples/symfony-demo/`).

## Quick start (Symfony Runtime mode)

```bash
composer create-project symfony/skeleton my-app
cd my-app
composer require sibidharan/zealphp-symfony
echo 'APP_RUNTIME=ZealPHP\Symfony\Runtime' >> .env.local
php public/index.php
```

## Quick start (ZealPHP-native mode)

```php
<?php
require __DIR__ . '/vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\Symfony\KernelRunner;

$app = App::init();
$app->setFallback(KernelRunner::asHandler(new App\Kernel('prod', false)));
$app->run();
```

## Per-request state reset

Symfony's `services_resetter` is called after every `Kernel::terminate()`. This handles:

- `Doctrine\ORM\EntityManager` — identity map cleared, dirty entities discarded
- `Symfony\Component\HttpFoundation\Session\Session` — saved + new instance
- `Monolog\Logger` — handler buffers flushed
- `Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage` — token cleared
- Any service implementing `Symfony\Contracts\Service\ResetInterface`

If your custom service holds request-scoped state, implement `ResetInterface` and the bridge will reset it automatically.

## Doctrine connection caveat

Doctrine connections are pooled across requests, **not** opened fresh per request. The `services_resetter` clears the `EntityManager`'s unit-of-work but the underlying `Connection` survives. If your app uses `SET SESSION` statements, savepoints, or temporary tables, you must reset them explicitly — typically via an `onConnect` handler or by listening to `KernelEvents::FINISH_REQUEST`. The roadmap includes a `PDOPool`-style abstraction with declarative reset SQL.

## Benchmarks

See `bench/` for the comparison harness. Quick numbers (Symfony Demo `/` route, 4 workers, c=100):

| Runtime | Req/s | p50 | p95 | p99 |
|---------|-------|-----|-----|-----|
| php-fpm + nginx | (TBD) | (TBD) | (TBD) | (TBD) |
| zealphp-symfony | (TBD) | (TBD) | (TBD) | (TBD) |

## License

MIT
