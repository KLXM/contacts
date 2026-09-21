<?php

declare(strict_types=1);

namespace KLXM\Contacts\Dav;

use KLXM\Contacts\Contacts;
use KLXM\Contacts\Domain\Book;
use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\ContactList;
use KLXM\Contacts\I18n;
use KLXM\Contacts\Repository\BookRepository;
use KLXM\Contacts\Schema;
use KLXM\Contacts\Security\Access;
use KLXM\Contacts\VCard\Parser;
use KLXM\Contacts\VCard\Serializer;
use KLXM\Dav\Context;
use rex_sql;
use Sabre\CardDAV\Backend\AbstractBackend;
use Sabre\CardDAV\Backend\SyncSupport;
use Sabre\CardDAV\Plugin as CardDavPlugin;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\PropPatch;

/**
 * CardDAV auf den Tabellen des Addons. Kontakte sind Karten, Listen sind Gruppenkarten.
 * Adressbücher selbst legt nur das Backend an, ändert und löscht sie.
 */
final class AddressBookBackend extends AbstractBackend implements SyncSupport
{
    private readonly Serializer $serializer;
    private readonly Parser $parser;

    public function __construct(private readonly Context $context)
    {
        $this->serializer = new Serializer();
        $this->parser = new Parser();
    }

    public function getAddressBooksForUser($principalUri): array
    {
        if (null === $this->context->user || $principalUri !== $this->context->principalUri) {
            return [];
        }

        return array_values(array_map(static fn (Book $book): array => [
            'id' => (int) $book->id,
            'uri' => $book->slug,
            'principaluri' => $principalUri,
            '{DAV:}displayname' => $book->name,
            '{' . CardDavPlugin::NS_CARDDAV . '}addressbook-description' => $book->description ?? '',
            '{http://calendarserver.org/ns/}getctag' => (string) $book->syncToken,
            '{http://sabredav.org/ns}sync-token' => (string) $book->syncToken,
        ], Access::davBooks($this->context->user)));
    }

    public function updateAddressBook($addressBookId, PropPatch $propPatch): void
    {
        // Name und Beschreibung gehören dem Backend; die Änderung bleibt unbeantwortet und scheitert damit sauber.
    }

    public function createAddressBook($principalUri, $url, array $properties): never
    {
        throw new Forbidden(I18n::t('dav_book_create_denied'));
    }

    public function deleteAddressBook($addressBookId): never
    {
        throw new Forbidden(I18n::t('dav_book_delete_denied'));
    }

    public function getCards($addressbookId): array
    {
        $book = $this->book($addressbookId);
        $cards = [];
        foreach (['contact', 'list'] as $table) {
            foreach (rex_sql::factory()->getArray('SELECT uri, etag, updatedate FROM ' . Schema::table($table) . ' WHERE book_id = ?', [$book->id]) as $row) {
                $cards[] = ['uri' => (string) $row['uri'], 'etag' => '"' . $row['etag'] . '"', 'lastmodified' => strtotime((string) $row['updatedate']) ?: null];
            }
        }

        return $cards;
    }

    public function getCard($addressBookId, $cardUri): array|false
    {
        $book = $this->book($addressBookId);
        $contact = Contacts::contacts()->findByUri((int) $book->id, (string) $cardUri);
        if (null !== $contact) {
            return $this->describe($contact->uri, $contact->etag, $contact->updatedAt, $this->serializer->contact($contact));
        }
        $list = Contacts::lists()->findByUri((int) $book->id, (string) $cardUri);
        if (null !== $list) {
            return $this->describe($list->uri, $list->etag, $list->updatedAt, $this->serializer->contactList($list, $this->memberUids($list)));
        }

        return false;
    }

    public function createCard($addressBookId, $cardUri, $cardData): ?string
    {
        $book = $this->book($addressBookId, true);
        $this->store($book, (string) $cardUri, (string) $cardData, null, null);

        return null; // Der Server normalisiert die Karte; die App lädt sie danach neu.
    }

    public function updateCard($addressBookId, $cardUri, $cardData): ?string
    {
        $book = $this->book($addressBookId, true);
        $contact = Contacts::contacts()->findByUri((int) $book->id, (string) $cardUri);
        $list = null === $contact ? Contacts::lists()->findByUri((int) $book->id, (string) $cardUri) : null;
        if (null === $contact && null === $list) {
            throw new NotFound(I18n::t('dav_card_not_found'));
        }
        $this->store($book, (string) $cardUri, (string) $cardData, $contact, $list);

        return null;
    }

    public function deleteCard($addressBookId, $cardUri): bool
    {
        $book = $this->book($addressBookId, true);
        $this->actAsUser();
        if (null !== ($contact = Contacts::contacts()->findByUri((int) $book->id, (string) $cardUri))) {
            Contacts::contacts()->delete($contact);

            return true;
        }
        if (null !== ($list = Contacts::lists()->findByUri((int) $book->id, (string) $cardUri))) {
            Contacts::lists()->delete($list);

            return true;
        }

        return false;
    }

    public function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = null): ?array
    {
        $book = $this->book($addressBookId);
        $result = ['syncToken' => (string) $book->syncToken, 'added' => [], 'modified' => [], 'deleted' => []];

        if ('' === (string) $syncToken) {
            $result['added'] = array_column($this->getCards($addressBookId), 'uri');

            return $result;
        }
        if (!ctype_digit((string) $syncToken) || (int) $syncToken > $book->syncToken) {
            return null; // unbekannter Stand: die App gleicht vollständig neu ab
        }

        // Je Karte zählt der letzte Stand; angelegt und wieder gelöscht heißt: nie gesehen.
        $state = [];
        foreach (rex_sql::factory()->getArray('SELECT uri, operation FROM ' . Schema::table('change') . ' WHERE book_id = ? AND sync_token >= ? ORDER BY id', [$book->id, (int) $syncToken]) as $row) {
            $state[(string) $row['uri']] = ['first' => $state[(string) $row['uri']]['first'] ?? (int) $row['operation'], 'last' => (int) $row['operation']];
        }
        foreach ($state as $uri => $operations) {
            if (BookRepository::DELETED === $operations['last']) {
                if (BookRepository::ADDED !== $operations['first']) {
                    $result['deleted'][] = (string) $uri;
                }
            } else {
                $result[BookRepository::ADDED === $operations['first'] ? 'added' : 'modified'][] = (string) $uri;
            }
        }

        return $result;
    }

    private function store(Book $book, string $uri, string $data, ?Contact $contact, ?ContactList $list): void
    {
        try {
            $cards = $this->parser->read($data);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }
        if (1 !== count($cards)) {
            throw new BadRequest(I18n::t('dav_one_card'));
        }
        $card = $cards[0];
        $this->actAsUser();

        try {
            if ($this->parser->isList($card)) {
                if (null !== $contact) {
                    throw new BadRequest(I18n::t('dav_kind_change'));
                }
                $parsed = $this->parser->listData($card);
                $list ??= new ContactList();
                $list->bookId = (int) $book->id;
                $list->uri = $uri;
                $list->uid = '' !== $list->uid ? $list->uid : $parsed['uid'];
                $list->name = $parsed['name'];
                [$list->memberIds, $list->pendingMembers] = $this->resolveMembers((int) $book->id, $parsed['members']);
                Contacts::lists()->save($list);

                return;
            }
            if (null !== $list) {
                throw new BadRequest(I18n::t('dav_kind_change'));
            }
            $contact ??= new Contact();
            $contact->bookId = (int) $book->id;
            $contact->uri = $uri;
            $this->parser->apply($card, $contact);
            Contacts::contacts()->save($contact);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }
    }

    /**
     * @param list<string> $uids
     *
     * @return array{list<int>, list<string>} gefundene Kontakt-IDs und noch unbekannte UIDs
     */
    private function resolveMembers(int $bookId, array $uids): array
    {
        if ([] === $uids) {
            return [[], []];
        }
        $found = [];
        $rows = rex_sql::factory()->getArray('SELECT id, uid FROM ' . Schema::table('contact') . ' WHERE book_id = ? AND uid IN (' . implode(',', array_fill(0, count($uids), '?')) . ')', [$bookId, ...$uids]);
        foreach ($rows as $row) {
            $found[(string) $row['uid']] = (int) $row['id'];
        }

        return [array_values($found), array_values(array_diff($uids, array_keys($found)))];
    }

    /**
     * @return list<string>
     */
    private function memberUids(ContactList $list): array
    {
        if ([] === $list->memberIds) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['uid'],
            rex_sql::factory()->getArray('SELECT uid FROM ' . Schema::table('contact') . ' WHERE id IN (' . implode(',', array_map(intval(...), $list->memberIds)) . ') ORDER BY sort_name'),
        );
    }

    /**
     * @return array{uri: string, etag: string, lastmodified: int|null, carddata: string, size: int}
     */
    private function describe(string $uri, string $etag, ?\DateTimeImmutable $updatedAt, string $data): array
    {
        return ['uri' => $uri, 'etag' => '"' . $etag . '"', 'lastmodified' => $updatedAt?->getTimestamp(), 'carddata' => $data, 'size' => strlen($data)];
    }

    private function book(mixed $id, bool $write = false): Book
    {
        $book = Contacts::books()->find((int) $id);
        if (null === $book || !$book->davEnabled || !Access::canEditBook((int) $book->id, $this->context->user)) {
            throw new Forbidden(I18n::t('dav_book_denied'));
        }
        if ($write && !$this->context->canWrite) {
            throw new Forbidden(I18n::t('dav_read_only'));
        }

        return $book;
    }

    private function actAsUser(): void
    {
        Contacts::actAs($this->context->user?->getLogin());
    }
}
