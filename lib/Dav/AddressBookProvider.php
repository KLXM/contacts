<?php

declare(strict_types=1);

namespace KLXM\Contacts\Dav;

use KLXM\Contacts\Domain\Book;
use KLXM\Contacts\I18n;
use KLXM\Contacts\Security\Access;
use KLXM\Dav\Context;
use KLXM\Dav\Provider;
use rex_user;
use Sabre\CardDAV;
use Sabre\DAVACL\PrincipalBackend\BackendInterface as PrincipalBackendInterface;

/**
 * Hängt die Adressbücher als CardDAV in den Server des Addons dav ein.
 */
final class AddressBookProvider implements Provider
{
    public function key(): string
    {
        return 'contacts';
    }

    public function label(): string
    {
        return I18n::t('dav_provider_label');
    }

    public function protocol(): string
    {
        return 'carddav';
    }

    public function isAvailableFor(rex_user $user): bool
    {
        return Access::canUse($user);
    }

    public function describe(rex_user $user): array
    {
        return array_values(array_map(static fn (Book $book): string => $book->name, Access::davBooks($user)));
    }

    public function nodes(Context $context, PrincipalBackendInterface $principals): array
    {
        return [new CardDAV\AddressBookRoot($principals, new AddressBookBackend($context))];
    }

    public function plugins(Context $context): array
    {
        return [new CardDAV\Plugin()];
    }
}
