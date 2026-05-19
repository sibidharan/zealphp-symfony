#!/usr/bin/env bash
# examples/setup-symfony-demo.sh — materialise the Symfony Demo app + wire it
# to the zealphp-symfony bridge via composer's path repository.
#
# Run this from the repo root:   bash examples/setup-symfony-demo.sh
#
# After it finishes:
#   cd examples/symfony-demo && php public/index.php
# brings up the Symfony Demo on http://127.0.0.1:9090

set -euo pipefail

cd "$(dirname "$0")"

if [[ -d symfony-demo ]]; then
    echo "examples/symfony-demo/ already exists; remove it first if you want a fresh install."
    exit 1
fi

echo "==> Installing Symfony Demo (this may take a few minutes)..."
composer create-project symfony/symfony-demo symfony-demo --no-interaction

cd symfony-demo

# The Symfony Demo pins composer.platform.php to 8.2 for max compatibility.
# ZealPHP requires PHP 8.3+; bump platform to the actual installed version.
PHP_VERSION="$(php -r 'echo phpversion();')"
echo "==> Bumping composer.platform.php to ${PHP_VERSION} (actual installed version)..."
composer config platform.php "${PHP_VERSION}"

echo "==> Adding zealphp-symfony as a path repository pointing at the bridge..."
composer config repositories.zealphp-symfony path ../../

echo "==> Installing the bridge..."
# -W (--with-all-dependencies) lets composer downgrade psr/http-message from
# 2.0 → 1.1 to satisfy openswoole/core's PSR-7 implementations.
composer require sibidharan/zealphp-symfony:@dev --no-interaction -W

echo "==> Wiring composer.json extra.runtime so APP_RUNTIME resolves to our class..."
# Use jq if available (clean JSON edit); fall back to sed otherwise.
if command -v jq >/dev/null; then
    tmp="$(mktemp)"
    jq '.extra.runtime = {
        "class": "ZealPHP\\Symfony\\Runtime",
        "host": "0.0.0.0",
        "port": 9090,
        "settings": {"worker_num": 2, "task_worker_num": 0}
    }' composer.json > "${tmp}" && mv "${tmp}" composer.json
else
    echo "    jq not installed — please add this manually to examples/symfony-demo/composer.json:"
    cat <<'JSON'
    "extra": {
        "runtime": {
            "class": "ZealPHP\\Symfony\\Runtime",
            "host": "0.0.0.0",
            "port": 9090,
            "settings": {"worker_num": 2, "task_worker_num": 0}
        }
    }
JSON
fi

# Wire the coroutine-safe session storage so $_SESSION actually persists under
# the long-running worker (otherwise Symfony emits PHPSESSID=deleted every
# request). See README "Sessions (required wiring)".
echo "==> Wiring session storage factory (framework.yaml + services.yaml)..."
if ! grep -q 'CoroutineSessionStorageFactory' config/packages/framework.yaml; then
    # framework.session: -> storage_factory_id
    python3 - <<'PY'
import re
p = 'config/packages/framework.yaml'
s = open(p).read()
# Replace a bare "session: true" with the factory block; else append under framework:
if re.search(r'^\s*session:\s*true\s*$', s, re.M):
    s = re.sub(r'^(\s*)session:\s*true\s*$',
               r'\1session:\n\1    storage_factory_id: ZealPHP\\Symfony\\Session\\CoroutineSessionStorageFactory',
               s, count=1, flags=re.M)
else:
    s = s.replace('framework:\n',
                  'framework:\n    session:\n        storage_factory_id: ZealPHP\\Symfony\\Session\\CoroutineSessionStorageFactory\n', 1)
open(p, 'w').write(s)
PY
fi
if ! grep -q 'CoroutineSessionStorageFactory' config/services.yaml; then
    cat >> config/services.yaml <<'YAML'

    # zealphp-symfony: route Symfony sessions through ZealPHP's session store
    ZealPHP\Symfony\Session\CoroutineSessionStorageFactory:
        arguments:
            $options: '%session.storage.options%'
YAML
fi

# Re-dump autoload so vendor/autoload_runtime.php picks up the extra.runtime.class.
composer dump-autoload

cat <<EOF

==> Done.

To run the demo on ZealPHP:
    cd examples/symfony-demo
    php public/index.php

Then visit http://127.0.0.1:9090/en/ in your browser.

To run the demo on stock Symfony (php-fpm baseline for benchmark comparison):
    cd examples/symfony-demo
    symfony server:start --port=9091    # or: php -S 127.0.0.1:9091 -t public/
EOF
