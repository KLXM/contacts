<?php

declare(strict_types=1);

namespace KLXM\Contacts;

use KLXM\Contacts\Domain\Book;
use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Repository\BookRepository;
use KLXM\Contacts\Repository\ContactRepository;
use KLXM\Contacts\Repository\ListRepository;
use rex;

/**
 * Einstieg in das Addon:
 *
 *     foreach (Contacts::contacts()->query(listId: 3) as $contact) {
 *         echo $contact->displayName(), ' ', $contact->first(ItemKind::Email)?->value;
 *     }
 */
final class Contacts
{
    private static ?BookRepository $books = null;
    private static ?ListRepository $lists = null;
    private static ?ContactRepository $contacts = null;
    private static ?string $actor = null;

    public static function books(): BookRepository
    {
        return self::$books ??= new BookRepository();
    }

    public static function lists(): ListRepository
    {
        return self::$lists ??= new ListRepository(self::books());
    }

    public static function contacts(): ContactRepository
    {
        return self::$contacts ??= new ContactRepository(self::books(), self::lists());
    }

    public static function book(int|string $idOrSlug): ?Book
    {
        return is_int($idOrSlug) ? self::books()->find($idOrSlug) : self::books()->findBySlug($idOrSlug);
    }

    public static function contact(int $id): ?Contact
    {
        return self::contacts()->find($id);
    }

    /**
     * Kontakte für die Website: nur öffentliche, und je Kontakt nur die freigegebenen Angaben.
     *
     *     foreach (Contacts::published(list: 'vorstand') as $person) { … }
     *
     * @param int|string|null $book ID oder Slug eines Adressbuchs
     * @param int|string|null $list ID oder Name einer Liste
     *
     * @return list<Contact>
     */
    public static function published(int|string|null $book = null, int|string|null $list = null, string $search = '', int $limit = 0, int $offset = 0): array
    {
        $bookId = null === $book ? null : self::book($book)?->id;
        if (null !== $book && null === $bookId) {
            return [];
        }
        $listId = null;
        if (null !== $list) {
            $candidates = null === $bookId ? self::lists()->forBooks(array_keys(self::books()->all())) : self::lists()->forBook($bookId);
            $listId = is_int($list) ? ($candidates[$list]->id ?? null) : array_find($candidates, static fn ($candidate): bool => 0 === strcasecmp($candidate->name, $list))?->id;
            if (null === $listId) {
                return [];
            }
        }

        $views = [];
        foreach (self::contacts()->query(null === $bookId ? null : [$bookId], $listId, $search, $limit, $offset, true) as $contact) {
            $view = $contact->publicView();
            if (null !== $view) {
                $views[] = $view;
            }
        }

        return $views;
    }

    /** Wer gerade schreibt, für createuser und updateuser. */
    public static function actor(): string
    {
        return self::$actor ?? (class_exists(rex::class, false) ? rex::getUser()?->getLogin() : null) ?? 'system';
    }

    public static function actAs(?string $login): void
    {
        self::$actor = $login;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)));
    }
}
