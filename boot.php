<?php

declare(strict_types=1);

use KLXM\Contacts\Api\BackendApi;
use KLXM\Contacts\Security\Access;
use KLXM\Contacts\Security\BookPerm;

$addon = rex_addon::get('contacts');

rex_api_function::register(BackendApi::NAME, BackendApi::class);
rex_api_function::register(KLXM\Contacts\Api\PhotoApi::NAME, KLXM\Contacts\Api\PhotoApi::class);

// CardDAV: Server, Anmeldung, App-Passwörter und die sabre-Bibliotheken stellt das Addon dav.
KLXM\Dav\Dav::register(new KLXM\Contacts\Dav\AddressBookProvider());

if (rex::isBackend()) {
    rex_complex_perm::register(BookPerm::KEY, BookPerm::class);
    rex_perm::register(Access::PERM_BOOKS, null, rex_perm::OPTIONS);

    if (null !== rex::getUser() && 'contacts' === rex_be_controller::getCurrentPagePart(1)) {
        $version = $addon->getVersion();
        rex_view::addCssFile($addon->getAssetsUrl('contacts.css?v=' . $version));
        rex_view::setJsProperty('contacts_i18n', KLXM\Contacts\I18n::forJavaScript());

        // Die Komponenten sind ein ES-Modul; rex_view kennt dafür keinen Schalter.
        rex_extension::register('PAGE_HEADER', static function (rex_extension_point $ep) use ($addon, $version): string {
            return $ep->getSubject() . '<script type="module" src="' . rex_escape($addon->getAssetsUrl('contacts.js?v=' . $version)) . '"></script>';
        });
    }
}
