<?php

declare(strict_types=1);

namespace ZealPHP\Symfony;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * RunnerInterface implementation that hands off to KernelRunner::serve().
 *
 * This is the bridge between Symfony Runtime's "give me an int exit code"
 * contract and the long-running OpenSwoole HTTP server. The Runtime
 * component constructs us once at boot; we never return until the server
 * stops (which means SIGTERM in practice).
 *
 * @internal Constructed by ZealPHP\Symfony\Runtime — not for direct user use.
 */
final class HttpKernelRunner implements RunnerInterface
{
    /**
     * @param array{host:string, port:int, settings:array<string,mixed>} $options
     */
    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly array $options,
    ) {
    }

    public function run(): int
    {
        return KernelRunner::serve($this->kernel, $this->options);
    }
}
