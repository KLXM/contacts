<?php

declare(strict_types=1);

// sabre/vobject kommt aus dem Addon dav, contacts bringt selbst keine Laufzeit-Abhängigkeiten mit.
require dirname(__DIR__, 2) . '/dav/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$boot = getenv('CONTACTS_REDAXO_BOOT');
if (is_string($boot) && '' !== $boot) {
    require $boot;
}
