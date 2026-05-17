<?php

declare(strict_types=1);

namespace ZealPHP\Symfony;

use OpenSwoole\Http\Request as SwooleRequest;
use OpenSwoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use ZealPHP\App;

/**
 * Bridges a booted Symfony Kernel to ZealPHP / OpenSwoole's HTTP server.
 *
 * Owns the per-request lifecycle:
 *   1. Convert raw OpenSwoole request → Symfony Request (no superglobal reads)
 *   2. Kernel::handle()  — runs the Symfony pipeline against the warm container
 *   3. Emit the Symfony Response → OpenSwoole response (headers, cookies, body)
 *   4. Kernel::terminate() — fires kernel.terminate event, flushes loggers/spool
 *   5. services_resetter — clears EntityManager, Session, TokenStorage, Monolog
 *      buffers, and any service implementing Symfony\Contracts\Service\ResetInterface
 *
 * The Kernel itself is booted ONCE per worker (in App::onWorkerStart) and reused
 * across every request that worker serves. This is where the perf comes from.
 *
 * Two entry points:
 *
 *   1. Standalone HTTP server — boot a Kernel, bind a port, serve nothing else:
 *        KernelRunner::serve($kernel, ['host' => '0.0.0.0', 'port' => 8080]);
 *      (used internally by ZealPHP\Symfony\Runtime when APP_RUNTIME is set)
 *
 *   2. As a ZealPHP fallback handler — mount Symfony under a ZealPHP app:
 *        $app->setFallback(KernelRunner::asHandler($kernel));
 *      (lets you mix native ZealPHP routes with a Symfony Kernel for the rest)
 */
final class KernelRunner
{
    /**
     * Standalone server. Boots the Kernel inside an OpenSwoole onWorkerStart
     * hook (one boot per worker), then dispatches each onRequest through the
     * Kernel. Blocks until the server stops.
     *
     * @param array{host?:string, port?:int, settings?:array<string,mixed>} $options
     */
    public static function serve(HttpKernelInterface $kernel, array $options = []): int
    {
        $host     = $options['host']     ?? '0.0.0.0';
        $port     = $options['port']     ?? 8080;
        $settings = $options['settings'] ?? [];

        // ZealPHP gives us the OnRequest hook + worker lifecycle for free.
        // We don't want ZealPHP's route table or middleware stack — Symfony
        // is the entire router. So we register a single catch-all fallback
        // and let Symfony handle everything.
        //
        // Coroutine mode (App::superglobals(false)) is the whole point of
        // running on OpenSwoole: HOOK_ALL converts Doctrine PDO queries,
        // Symfony HttpClient curl calls, and file I/O into coroutine-yielding
        // operations. One worker can serve dozens of concurrent requests
        // because they all yield on I/O instead of blocking. Symfony itself
        // is synchronous PHP; it doesn't need to be coroutine-aware — the
        // hooks happen transparently below it.
        App::superglobals(false);

        // Symfony's NativeSessionStorage owns the session lifecycle
        // (PHPSESSID cookie minting, $_SESSION populate/save, session_id
        // generation). Without this opt-out, ZealPHP's SessionManager also
        // mints a session ID + emits its own Set-Cookie at request entry,
        // producing TWO conflicting PHPSESSID headers on the wire. The
        // sessionLifecycle(false) toggle skips ZealPHP's session-specific
        // work but keeps request-context init ($g->openswoole_request,
        // $g->zealphp_response, error-stack reset). Native ZealPHP routes
        // that want session access can still call session_*() — those uopz
        // overrides remain installed and read/write $g->session as usual.
        App::sessionLifecycle(false);

        $app = App::init($host, $port);

        // The boot must run inside the worker process (after fork), not in
        // the master — bundles spawn timers, open connections, etc., that
        // belong to the worker. ZealPHP's onWorkerStart() guarantees that.
        App::onWorkerStart(static function () use ($kernel) {
            $kernel->boot();
        });

        $app->setFallback(self::asHandler($kernel));

        // $app->run() returns void and blocks forever in normal operation.
        // The `return 0` is unreachable under SIGTERM but satisfies the
        // RuntimeInterface contract that requires an int exit code.
        $app->run($settings);
        return 0;
    }

    /**
     * Returns a callable suitable for $app->setFallback() or $app->route().
     * Each invocation dispatches the request through the Kernel and emits
     * the Symfony Response directly via the raw OpenSwoole response —
     * bypassing ZealPHP's PSR-15 stack so Symfony's own response shape
     * (Set-Cookie, StreamedResponse, BinaryFileResponse) is preserved.
     *
     * Assumes the Kernel is already booted (via onWorkerStart when used
     * with serve(), or by the caller when used standalone).
     */
    public static function asHandler(HttpKernelInterface $kernel): \Closure
    {
        return static function () use ($kernel): void {
            $g = \ZealPHP\RequestContext::instance();
            /** @var SwooleRequest $swooleReq */
            $swooleReq = $g->openswoole_request;
            /** @var SwooleResponse $swooleResp */
            $swooleResp = $g->openswoole_response;

            $symfonyReq = self::buildSymfonyRequest($swooleReq);

            try {
                $response = $kernel->handle($symfonyReq);
            } catch (\Throwable $e) {
                // Symfony's exception listener should catch most errors and
                // return a Response. If something escapes (e.g. boot failure
                // mid-request), surface a 500 ourselves rather than letting
                // OpenSwoole emit an empty response.
                $response = new SymfonyResponse(
                    $kernel instanceof \Symfony\Component\HttpKernel\Kernel && $kernel->isDebug()
                        ? (string) $e
                        : 'Internal Server Error',
                    SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR,
                    ['Content-Type' => 'text/plain; charset=utf-8'],
                );
            }

            self::emit($response, $swooleResp);

            if ($kernel instanceof TerminableInterface) {
                $kernel->terminate($symfonyReq, $response);
            }

            self::resetServices($kernel);

            // Tell ZealPHP we handled the response ourselves so its
            // ResponseMiddleware doesn't try to wrap a Symfony Response in
            // a PSR-7 Response and double-send.
            $g->_streaming = true;
        };
    }

    /**
     * Convert OpenSwoole's request to a Symfony Request WITHOUT touching
     * superglobals. The standard Request::createFromGlobals() reads
     * $_GET / $_POST / $_SERVER / $_FILES / $_COOKIE / php://input — all
     * of which are either empty or process-wide-shared in coroutine mode.
     * We construct the Request from the per-request Swoole arrays instead.
     */
    private static function buildSymfonyRequest(SwooleRequest $swoole): SymfonyRequest
    {
        // Swoole's $request->header is lowercase-keyed. Symfony's $_SERVER
        // expects HTTP_ uppercase-underscore form. Translate.
        $server = [];
        foreach (($swoole->server ?? []) as $key => $value) {
            $server[strtoupper((string) $key)] = $value;
        }
        foreach (($swoole->header ?? []) as $name => $value) {
            $server['HTTP_' . strtoupper(strtr((string) $name, '-', '_'))] = $value;
        }
        // Symfony reads CONTENT_TYPE / CONTENT_LENGTH without the HTTP_ prefix.
        if (isset($swoole->header['content-type'])) {
            $server['CONTENT_TYPE'] = $swoole->header['content-type'];
        }
        if (isset($swoole->header['content-length'])) {
            $server['CONTENT_LENGTH'] = $swoole->header['content-length'];
        }
        // Sensible defaults that Symfony's Request expects.
        $server['REQUEST_METHOD']  ??= $swoole->server['request_method'] ?? 'GET';
        $server['REQUEST_URI']     ??= $swoole->server['request_uri']    ?? '/';
        $server['QUERY_STRING']    ??= $swoole->server['query_string']   ?? '';
        $server['SERVER_PROTOCOL'] ??= $swoole->server['server_protocol'] ?? 'HTTP/1.1';

        $content = $swoole->getContent();
        if ($content === false || $content === '') {
            $content = null;
        }

        return new SymfonyRequest(
            $swoole->get ?? [],
            $swoole->post ?? [],
            [],                       // $attributes — Symfony populates this
            $swoole->cookie ?? [],
            $swoole->files ?? [],
            $server,
            $content,
        );
    }

    /**
     * Emit a Symfony Response onto OpenSwoole's raw response handle.
     * Skips Response::send() entirely — that calls native header() / echo,
     * which would either no-op or duplicate output under OpenSwoole.
     */
    private static function emit(SymfonyResponse $response, SwooleResponse $swoole): void
    {
        // Status + reason phrase.
        $swoole->status($response->getStatusCode(), SymfonyResponse::$statusTexts[$response->getStatusCode()] ?? '');

        // Headers (excluding cookies — those go through Set-Cookie below).
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ($values as $value) {
                $swoole->header($name, (string) $value);
            }
        }
        foreach ($response->headers->getCookies() as $cookie) {
            self::emitCookie($swoole, $cookie);
        }

        if ($response instanceof BinaryFileResponse) {
            $file = $response->getFile()->getPathname();
            $swoole->sendfile($file);
            return;
        }

        if ($response instanceof StreamedResponse) {
            // Symfony streamed responses call ob_flush() / flush(). With
            // OpenSwoole we buffer-collect then write — simpler than
            // wiring the per-chunk flush. (Real streaming for SSE etc.
            // is a follow-up.)
            ob_start();
            $response->sendContent();
            $body = (string) ob_get_clean();
            $swoole->end($body);
            return;
        }

        $swoole->end((string) $response->getContent());
    }

    private static function emitCookie(SwooleResponse $swoole, Cookie $cookie): void
    {
        $swoole->cookie(
            $cookie->getName(),
            (string) ($cookie->getValue() ?? ''),
            $cookie->getExpiresTime(),
            $cookie->getPath() ?? '/',
            $cookie->getDomain() ?? '',
            $cookie->isSecure(),
            $cookie->isHttpOnly(),
            $cookie->getSameSite() ?? '',
        );
    }

    /**
     * Symfony auto-registers services that implement ResetInterface in
     * the 'services_resetter' service. One call clears them all —
     * EntityManager, Session, TokenStorage, Monolog buffers, etc.
     *
     * Wrapped in try/catch because a buggy resetter shouldn't crash the
     * worker; surface to error_log and continue.
     */
    private static function resetServices(HttpKernelInterface $kernel): void
    {
        if (!method_exists($kernel, 'getContainer')) {
            return;
        }
        try {
            $container = $kernel->getContainer();
            if ($container->has('services_resetter')) {
                $resetter = $container->get('services_resetter');
                if (is_object($resetter) && method_exists($resetter, 'reset')) {
                    $resetter->reset();
                }
            }
        } catch (\Throwable $e) {
            error_log('[zealphp-symfony] services_resetter failed: ' . $e->getMessage());
        }
    }
}
