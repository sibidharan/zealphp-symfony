<?php

declare(strict_types=1);

namespace ZealPHP\Symfony;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

/**
 * Symfony Runtime adapter for ZealPHP / OpenSwoole.
 *
 * Activated by setting APP_RUNTIME=ZealPHP\Symfony\Runtime in the env (.env
 * or .env.local) of any Symfony app that uses symfony/runtime. The app's
 * existing public/index.php (the one composer create-project ships) needs
 * no changes — the Runtime component dispatches to us automatically.
 *
 * Runtime options can be passed via Composer extra config:
 *
 *   {
 *       "extra": {
 *           "runtime": {
 *               "host": "0.0.0.0",
 *               "port": 8080,
 *               "settings": { "worker_num": 4, "task_worker_num": 0 }
 *           }
 *       }
 *   }
 *
 * We extend SymfonyRuntime (not GenericRuntime) so we inherit the .env file
 * loading (via symfony/dotenv) and Symfony's default error handler installed
 * before the user's public/index.php runs. Without this, $context['APP_ENV']
 * would be undefined when the user's `return function (array $context)`
 * closure runs — SymfonyRuntime is what populates that.
 *
 * We override only getRunner() to swap in the OpenSwoole-backed runner when
 * the application is a Kernel; everything else (console commands, bare
 * callables) falls through to SymfonyRuntime's behaviour.
 */
final class Runtime extends SymfonyRuntime
{
    /**
     * @param array{host?:string, port?:int, settings?:array<string,mixed>} $options
     */
    public function __construct(array $options = [])
    {
        // SymfonyRuntime accepts a free-form options array; the keys we care
        // about (host/port/settings) flow through unchanged and we read them
        // when constructing the Runner. SymfonyRuntime's own options
        // (project_dir, env_var_name, debug_var_name, dotenv_path, etc.)
        // also work normally because we don't strip them.
        parent::__construct($options);
    }

    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof HttpKernelInterface) {
            /** @var array<string,mixed> $opts */
            $opts = $this->options;
            return new HttpKernelRunner($application, [
                'host'     => is_string($opts['host'] ?? null) ? $opts['host'] : '0.0.0.0',
                'port'     => is_int($opts['port'] ?? null)    ? $opts['port'] : 8080,
                'settings' => is_array($opts['settings'] ?? null) ? $opts['settings'] : [],
            ]);
        }

        // Not a Kernel — fall back to GenericRuntime's behaviour (which
        // handles plain callables, RunnerInterface implementations, etc.).
        // This makes the Runtime safe to use in console contexts where
        // bin/console returns a different application type.
        return parent::getRunner($application);
    }
}
