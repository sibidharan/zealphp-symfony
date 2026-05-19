<?php

declare(strict_types=1);

namespace ZealPHP\Symfony\Session;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpFoundation\Session\Storage\Proxy\AbstractProxy;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Factory that produces a {@see CoroutineSessionStorage} per request, mirroring
 * Symfony's built-in NativeSessionStorageFactory but with the bag backing array
 * switched from $_SESSION to ZealPHP's per-coroutine $g->session.
 *
 * Wiring (host Symfony app's config/packages/framework.yaml):
 *
 *     framework:
 *         session:
 *             storage_factory_id: ZealPHP\Symfony\Session\CoroutineSessionStorageFactory
 *
 * And the matching service in config/services.yaml:
 *
 *     services:
 *         ZealPHP\Symfony\Session\CoroutineSessionStorageFactory:
 *             arguments:
 *                 $options: '%session.storage.options%'
 *
 * (Symfony's framework bundle registers `session.storage.options` as a
 * container parameter from your `framework.session` config.)
 *
 * Symfony itself instantiates storages via `new` inside the factory — there's
 * no container alias path that would replace the class. The factory IS the
 * extension point, which is why this class exists.
 */
class CoroutineSessionStorageFactory implements SessionStorageFactoryInterface
{
    /**
     * @param array<string, mixed>                                   $options
     * @param AbstractProxy|\SessionHandlerInterface|null            $handler
     * @param MetadataBag|null                                       $metaBag
     * @param bool                                                   $secure
     */
    public function __construct(
        private array $options = [],
        private AbstractProxy|\SessionHandlerInterface|null $handler = null,
        private ?MetadataBag $metaBag = null,
        private bool $secure = false,
    ) {
    }

    public function createStorage(?Request $request): SessionStorageInterface
    {
        $storage = new CoroutineSessionStorage($this->options, $this->handler, $this->metaBag);
        if ($request !== null) {
            // Capture the request cookies so the storage can restore the
            // PHPSESSID if Symfony's services_resetter wipes it mid-boot.
            $storage->setIncomingCookies($request->cookies->all());
        }
        if ($this->secure && $request?->isSecure()) {
            $storage->setOptions(['cookie_secure' => true]);
        }

        return $storage;
    }
}
