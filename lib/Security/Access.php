<?php

declare(strict_types=1);

namespace KLXM\Contacts\Security;

use KLXM\Contacts\Contacts;
use KLXM\Contacts\Domain\Book;
use rex;
use rex_user;

/**
 * Wer darf was. Dieselben Regeln gelten im Backend, beim Im- und Export und über CardDAV.
 */
final class Access
{
    public const string PERM = 'contacts[]';
    public const string PERM_BOOKS = 'contacts[books]';

    public static function canUse(?rex_user $user = null): bool
    {
        $user ??= rex::getUser();

        return null !== $user && ($user->isAdmin() || $user->hasPerm(self::PERM));
    }

    public static function canManageBooks(?rex_user $user = null): bool
    {
        $user ??= rex::getUser();

        return null !== $user && ($user->isAdmin() || $user->hasPerm(self::PERM_BOOKS));
    }

    public static function canEditBook(int $bookId, ?rex_user $user = null): bool
    {
        $user ??= rex::getUser();
        if (null === $user || !self::canUse($user)) {
            return false;
        }
        if ($user->isAdmin() || self::canManageBooks($user)) {
            return true;
        }
        $perm = $user->getComplexPerm(BookPerm::KEY);

        return $perm instanceof BookPerm && $perm->hasBook($bookId);
    }

    /**
     * @return array<int, Book>
     */
    public static function books(?rex_user $user = null): array
    {
        return array_filter(Contacts::books()->all(), static fn (Book $book): bool => self::canEditBook((int) $book->id, $user));
    }

    /**
     * @return list<int>
     */
    public static function bookIds(?rex_user $user = null): array
    {
        return array_keys(self::books($user));
    }

    /**
     * Adressbücher, die der Benutzer in Kontakte-Apps sieht.
     *
     * @return array<int, Book>
     */
    public static function davBooks(?rex_user $user = null): array
    {
        return array_filter(self::books($user), static fn (Book $book): bool => $book->davEnabled);
    }
}
