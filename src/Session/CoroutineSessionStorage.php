<?php

declare(strict_types=1);

namespace ZealPHP\Symfony\Session;

use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use ZealPHP\RequestContext;

use function ZealPHP\Session\zeal_session_start;

/**
 * Symfony session storage that reads/writes ZealPHP's per-coroutine
 * `$g->session` array instead of the process-wide `$_SESSION` superglobal.
 *
 * Why this exists
 * ---------------
 * The bridge boots ZealPHP in `App::superglobals(false)` so each request
 * gets its own coroutine context. `$_SESSION` stays unmirrored on purpose
 * — populating it would let one coroutine's writes leak into another's
 * read when handlers yield mid-request (HOOK_ALL turns curl / fopen /
 * mysqli into yielding I/O).
 *
 * Symfony's `NativeSessionStorage` reads from and writes to `$_SESSION`
 * directly. With `$_SESSION` un-mirrored, every Symfony request sees an
 * empty session bag, persists empty data, and emits
 * `Set-Cookie: PHPSESSID=deleted` on the way out — the session never
 * actually survives between requests.
 *
 * This adapter swaps the storage bag's backing array from `$_SESSION` to
 * `$g->session`. The uopz overrides of `session_start()` /
 * `session_write_close()` already route through `$g->session`, so the
 * load and persist halves of the session lifecycle line up.
 *
 * Wiring (config/services.yaml in the host Symfony app):
 *
 *     services:
 *         Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage:
 *             class: ZealPHP\Symfony\Session\CoroutineSessionStorage
 *
 * No other config changes needed — Symfony's session.factory continues to
 * resolve through `NativeSessionStorage`'s registration, but instantiates
 * this subclass thanks to the alias above.
 */
class CoroutineSessionStorage extends NativeSessionStorage
{
    /**
     * Cookies from the incoming Symfony Request, captured by the factory at
     * createStorage() time. Used to re-seed the PHPSESSID into $g->cookie if
     * Symfony's services_resetter wiped it (see loadSession()).
     *
     * @var array<string, mixed>
     */
    private array $incomingCookies = [];

    /**
     * Per-instance "have we hydrated from the store yet" guard. A fresh
     * storage is created per request (SessionFactory → createStorage), so an
     * instance flag is request-scoped in EVERY lifecycle mode — unlike
     * $g->_session_started, which leaks across requests when $g is a
     * process-wide singleton (superglobals(true) + enableCoroutine(false)).
     */
    private bool $zealHydrated = false;

    /**
     * @param array<string, mixed> $cookies
     */
    public function setIncomingCookies(array $cookies): void
    {
        $this->incomingCookies = $cookies;
    }

    /**
     * Drive the real disk-load, then point Symfony's bags at $g->session.
     *
     * This is the load entry point in BOTH paths Symfony takes:
     *   - getBag() calls start() → parent::start() → session_start() →
     *     parent::loadSession() when no PHP session is active, OR
     *   - getBag() calls loadSession() DIRECTLY when
     *     `$saveHandler->isActive()` is true.
     *
     * Under ZealPHP coroutine mode the second path always wins:
     * `zeal_session_status()` reports PHP_SESSION_ACTIVE because $g->session
     * is always a set [] (CoSessionManager initialises it per request). So
     * `start()` is never invoked and the disk read inside zeal_session_start()
     * would never run — bags would attach to an empty array and the session
     * would appear empty on every request.
     *
     * Fix: drive zeal_session_start() HERE, exactly once per request (guarded
     * by $g->_session_started), before delegating to the parent's bag wiring.
     * zeal_session_start() reads the PHPSESSID from $g->cookie, loads the
     * session file/handler data into $g->session, and defaults save_path.
     *
     * @param array<string, mixed>|null $session
     */
    protected function loadSession(?array &$session = null): void
    {
        if ($session === null) {
            $g = RequestContext::instance();

            // Authoritatively set the session id from THIS request's cookie.
            // Two failure modes this guards against:
            //   1. Symfony's services_resetter → AbstractSessionListener::reset()
            //      calls session_id('') which (uopz) blanks $g->cookie[PHPSESSID].
            //   2. In superglobals(true) + enableCoroutine(false), $g is a
            //      process-wide singleton, so a PREVIOUS request's session id
            //      lingers in $g->cookie[PHPSESSID]. A "only re-seed when empty"
            //      check would reuse the stale id and every request would read
            //      the first/last session (observed: all sessions read the last
            //      setUp value). The factory captured this request's cookies at
            //      createStorage() time — they are the single source of truth.
            $name = $this->getName();
            $incoming = $this->incomingCookies[$name] ?? null;
            if (is_string($incoming) && $incoming !== '') {
                // Returning visitor — force the request's own id.
                $g->cookie[$name] = $incoming;
            } else {
                // New visitor (no session cookie) — clear any lingering id so
                // zeal_session_start() mints a fresh one instead of reusing a
                // prior request's session on this worker.
                unset($g->cookie[$name]);
            }

            // Defense-in-depth: zeal_session_start defaults save_path only
            // when `!isset`; force a writable absolute path if it's missing
            // or blank so zeal_session_write_close() never targets `/sess_*`.
            $savePath = $g->session_params['save_path'] ?? null;
            if (!is_string($savePath) || $savePath === '') {
                $g->session_params['save_path'] = sys_get_temp_dir() . '/php_sessions';
            }

            // Hydrate the session store exactly once per request, guarded by a
            // per-INSTANCE flag (a fresh storage is built per request). Do NOT
            // use $g->_session_started here: in superglobals(true) +
            // enableCoroutine(false), $g is a process-wide singleton and that
            // flag would leak true into the next request on the same worker,
            // skipping the reload and serving stale session data.
            // zeal_session_start() reads fresh from the store into $g->session
            // AND (superglobals(true)) $GLOBALS['_SESSION'].
            if (!$this->zealHydrated) {
                zeal_session_start();
                $this->zealHydrated = true;
                $g->_session_started = true;
            }

            // Attach Symfony's bags to whichever array zeal_session_write_close()
            // will persist: in superglobals(true) mode it reads $GLOBALS['_SESSION'];
            // in superglobals(false) mode it reads $g->session. Attaching to the
            // wrong one means bag writes never reach disk (session appears empty
            // on the next request even though the cookie round-trips).
            if (\ZealPHP\App::$superglobals) {
                if (!isset($GLOBALS['_SESSION']) || !is_array($GLOBALS['_SESSION'])) {
                    $GLOBALS['_SESSION'] = [];
                }
                $session = &$GLOBALS['_SESSION'];
            } else {
                $session = &$g->session;
            }
        }
        parent::loadSession($session);
    }

    /**
     * Persist Symfony's bags via $g->session and zeal_session_write_close.
     *
     * Differs from Symfony's NativeSessionStorage::save() in two ways:
     *
     *  1. The pre-write cleanup targets $g->session instead of $_SESSION.
     *  2. There is NO post-write restore of bags. Symfony's parent restores
     *     $_SESSION after session_write_close() so the in-memory Session
     *     object stays usable for the remainder of the request. We can't
     *     mirror that — `zeal_session_write_close()` does `unset($g->session)`
     *     per PHP-native session-close semantics, and the typed `array`
     *     property cannot hold a re-read after that. Per Symfony docs,
     *     reading session data after save() requires a fresh `start()`
     *     call anyway; the bridge's per-request lifecycle ends shortly
     *     after save() so this is benign.
     */
    public function save(): void
    {
        $g = RequestContext::instance();
        $superglobals = \ZealPHP\App::$superglobals;

        // Snapshot the live session BEFORE write-close. zeal_session_write_close
        // clears its source array after persisting ($GLOBALS['_SESSION'] = [] in
        // superglobals mode, unset($g->session) in coroutine mode). Symfony's
        // AbstractSessionListener checks `$session->isEmpty() && empty($_SESSION)`
        // AFTER save() to decide whether to emit a real cookie or a deletion;
        // if $_SESSION is empty at that point it sends `PHPSESSID=deleted` and
        // the session never sticks. So we restore the snapshot post-close —
        // mirroring Symfony's own NativeSessionStorage::save() restore step.
        $snapshot = ($superglobals && isset($GLOBALS['_SESSION']) && is_array($GLOBALS['_SESSION']))
            ? $GLOBALS['_SESSION']
            : null;

        // Strip empty bag entries before persist (matches parent behavior).
        // Operate on the same array loadSession() attached the bags to:
        // $GLOBALS['_SESSION'] in superglobals(true), $g->session otherwise.
        if ($superglobals) {
            if (isset($GLOBALS['_SESSION']) && is_array($GLOBALS['_SESSION'])) {
                foreach ($this->bags as $bag) {
                    if (empty($GLOBALS['_SESSION'][$key = $bag->getStorageKey()])) {
                        unset($GLOBALS['_SESSION'][$key]);
                    }
                }
                if ($GLOBALS['_SESSION'] !== []
                    && [$metaKey = $this->getMetadataBag()->getStorageKey()] === array_keys($GLOBALS['_SESSION'])) {
                    unset($GLOBALS['_SESSION'][$metaKey]);
                }
            }
        } elseif ($g->session !== []) {
            foreach ($this->bags as $bag) {
                if (empty($g->session[$key = $bag->getStorageKey()])) {
                    unset($g->session[$key]);
                }
            }
            if ($g->session !== []
                && [$metaKey = $this->getMetadataBag()->getStorageKey()] === array_keys($g->session)) {
                unset($g->session[$metaKey]);
            }
        }

        // uopz override → zeal_session_write_close: persists the live session
        // array ($GLOBALS['_SESSION'] in superglobals mode, $g->session in
        // coroutine mode) via the configured SessionHandlerInterface (file by
        // default; Redis / DB if the host app calls session_set_save_handler).
        session_write_close();

        // Restore the snapshot so Symfony's post-save isEmpty()/cookie logic
        // (and any later read in the same request) sees the data instead of the
        // empty array zeal_session_write_close left behind. Without this the
        // session listener emits `PHPSESSID=deleted`.
        if ($snapshot !== null) {
            $GLOBALS['_SESSION'] = $snapshot;
        }

        $this->closed = true;
        $this->started = false;
    }

    /**
     * In-request clear (Session::clear()) — wipe bags, wipe $g->session,
     * then rehydrate bags against the now-empty array so subsequent
     * writes within the same request still land somewhere.
     */
    public function clear(): void
    {
        $g = RequestContext::instance();

        foreach ($this->bags as $bag) {
            $bag->clear();
        }
        $g->session = [];

        // Re-attach bags against the cleared $g->session.
        $this->loadSession();
    }
}
