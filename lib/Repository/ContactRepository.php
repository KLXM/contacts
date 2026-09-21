<?php

declare(strict_types=1);

namespace KLXM\Contacts\Repository;

use KLXM\Contacts\Contacts;
use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\Item;
use KLXM\Contacts\Domain\ItemKind;
use KLXM\Contacts\I18n;
use KLXM\Contacts\Schema;
use rex_sql;

/**
 * Einziger Schreibweg für Kontakte: Backend, Import und CardDAV speichern alle über save().
 */
final class ContactRepository
{
    /** Spalten ohne das Foto selbst; Listen laden es nicht mit. */
    private const string LIST_COLUMNS = 'c.id, c.book_id, c.uid, c.uri, c.etag, c.is_company, c.prefix, c.first_name, c.middle_name, c.last_name, c.suffix, c.nickname, c.organization, c.department, c.job_title, c.birthday, c.note, c.photo_media, c.photo_type, c.extra_vcard, c.is_public, c.public_fields, c.updatedate, c.createuser, (c.photo_data IS NOT NULL) AS has_photo_data';

    public function __construct(
        private readonly BookRepository $books,
        private readonly ListRepository $lists,
    ) {}

    public function find(int $id): ?Contact
    {
        return $this->one('c.id = ?', [$id]);
    }

    public function findByUri(int $bookId, string $uri): ?Contact
    {
        return $this->one('c.book_id = ? AND c.uri = ?', [$bookId, $uri]);
    }

    public function findByUid(int $bookId, string $uid): ?Contact
    {
        return $this->one('c.book_id = ? AND c.uid = ?', [$bookId, $uid]);
    }

    /**
     * Kontakte nach Name sortiert, ohne Fotodaten.
     *
     * @param list<int>|null $bookIds auf diese Adressbücher beschränken; null für alle
     *
     * @return list<Contact>
     */
    public function query(?array $bookIds = null, ?int $listId = null, string $search = '', int $limit = 0, int $offset = 0, bool $publicOnly = false): array
    {
        [$where, $params] = $this->conditions($bookIds, $listId, $search, $publicOnly);
        $sql = 'SELECT ' . self::LIST_COLUMNS . ' FROM ' . Schema::table('contact') . ' c WHERE ' . $where . ' ORDER BY c.sort_name, c.id';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . max(0, $offset) . ', ' . $limit;
        }

        return $this->hydrateAll(rex_sql::factory()->getArray($sql, $params), false);
    }

    /**
     * @param list<int>|null $bookIds
     */
    public function count(?array $bookIds = null, ?int $listId = null, string $search = '', bool $publicOnly = false): int
    {
        [$where, $params] = $this->conditions($bookIds, $listId, $search, $publicOnly);

        return (int) rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . Schema::table('contact') . ' c WHERE ' . $where, $params)[0]['c'];
    }

    public function save(Contact $contact): void
    {
        if (null === $this->books->find($contact->bookId)) {
            throw new \InvalidArgumentException(I18n::t('error_book_missing'));
        }
        $contact->items = array_values(array_filter($contact->items, static fn (Item $item): bool => !$item->isEmpty()));
        if ('' === $contact->displayName()) {
            throw new \InvalidArgumentException(I18n::t('error_contact_empty'));
        }

        $isNew = null === $contact->id;
        $previous = $isNew ? null : rex_sql::factory()->getArray('SELECT book_id, uri FROM ' . Schema::table('contact') . ' WHERE id = ?', [$contact->id])[0] ?? null;
        $contact->uid = '' !== $contact->uid ? $contact->uid : Contacts::uuid();
        $contact->uri = '' !== $contact->uri ? $contact->uri : $contact->uid . '.vcf';
        $contact->etag = bin2hex(random_bytes(8));
        if (null !== $contact->birthday && 1 !== preg_match('/^(\d{4}|-)-\d{2}-\d{2}$/', $contact->birthday)) {
            $contact->birthday = null;
        }

        $sql = rex_sql::factory()->setTable(Schema::table('contact'));
        $sql->setValues([
            'book_id' => $contact->bookId,
            'uid' => $contact->uid,
            'uri' => $contact->uri,
            'etag' => $contact->etag,
            'is_company' => (int) $contact->isCompany,
            'prefix' => trim($contact->prefix),
            'first_name' => trim($contact->firstName),
            'middle_name' => trim($contact->middleName),
            'last_name' => trim($contact->lastName),
            'suffix' => trim($contact->suffix),
            'nickname' => trim($contact->nickname),
            'organization' => trim($contact->organization),
            'department' => trim($contact->department),
            'job_title' => trim($contact->jobTitle),
            'birthday' => $contact->birthday,
            'note' => null === $contact->note || '' === trim($contact->note) ? null : $contact->note,
            'photo_media' => '' === (string) $contact->photoMedia ? null : $contact->photoMedia,
            'display_name' => mb_substr($contact->displayName(), 0, 191),
            'sort_name' => mb_substr($contact->sortName(), 0, 191),
            'extra_vcard' => $contact->extraVcard,
            'is_public' => (int) $contact->isPublic,
            'public_fields' => implode(',', array_values(array_intersect(Contact::PUBLIC_FIELDS, $contact->publicFields))),
        ]);
        if ($contact->photoLoaded) {
            $sql->setValue('photo_data', $contact->photoData);
            $sql->setValue('photo_type', null === $contact->photoData ? null : $contact->photoType);
        }
        $sql->addGlobalUpdateFields(Contacts::actor());
        if ($isNew) {
            $sql->addGlobalCreateFields(Contacts::actor())->insert();
            $contact->id = (int) $sql->getLastId();
        } else {
            $sql->setWhere(['id' => $contact->id])->update();
        }

        rex_sql::factory()->setQuery('DELETE FROM ' . Schema::table('item') . ' WHERE contact_id = ?', [$contact->id]);
        foreach ($contact->items as $priority => $item) {
            rex_sql::factory()->setTable(Schema::table('item'))->setValues([
                'contact_id' => $contact->id,
                'kind' => $item->kind->value,
                'label' => mb_substr(trim($item->label), 0, 191),
                'value' => ItemKind::Address === $item->kind ? $item->addressText(', ') : trim($item->value),
                'data' => [] === $item->data ? null : json_encode($item->data, JSON_UNESCAPED_UNICODE),
                'priority' => $priority,
                'is_public' => (int) $item->isPublic,
            ])->insert();
        }

        // Beim Wechsel des Adressbuchs verschwindet die Karte im alten und entsteht im neuen.
        if (null !== $previous && (int) $previous['book_id'] !== $contact->bookId) {
            $this->books->logChange((int) $previous['book_id'], (string) $previous['uri'], BookRepository::DELETED);
            $this->lists->setListsOfContact((int) $contact->id, (int) $previous['book_id'], []);
            $isNew = true;
        }
        $this->lists->setListsOfContact((int) $contact->id, $contact->bookId, $contact->listIds);
        $this->books->logChange($contact->bookId, $contact->uri, $isNew ? BookRepository::ADDED : BookRepository::MODIFIED);
        if ($isNew) {
            $this->lists->resolvePending($contact->bookId, $contact->uid, (int) $contact->id);
        }
    }

    public function delete(Contact $contact): void
    {
        $this->lists->setListsOfContact((int) $contact->id, $contact->bookId, []);
        rex_sql::factory()->setQuery('DELETE FROM ' . Schema::table('item') . ' WHERE contact_id = ?', [$contact->id]);
        rex_sql::factory()->setQuery('DELETE FROM ' . Schema::table('contact') . ' WHERE id = ?', [$contact->id]);
        $this->books->logChange($contact->bookId, $contact->uri, BookRepository::DELETED);
    }

    /**
     * @param list<int>|null $bookIds
     *
     * @return array{string, list<int|string>}
     */
    private function conditions(?array $bookIds, ?int $listId, string $search, bool $publicOnly = false): array
    {
        $where = [$publicOnly ? 'c.is_public = 1' : '1 = 1'];
        $params = [];
        if (null !== $bookIds) {
            $where[] = [] === $bookIds ? '1 = 0' : 'c.book_id IN (' . implode(',', array_map(intval(...), $bookIds)) . ')';
        }
        if (null !== $listId) {
            $where[] = 'c.id IN (SELECT contact_id FROM ' . Schema::table('list_member') . ' WHERE list_id = ?)';
            $params[] = $listId;
        }
        foreach (preg_split('/\s+/', trim($search)) ?: [] as $word) {
            if ('' === $word) {
                continue;
            }
            $like = '%' . addcslashes($word, '%_\\') . '%';
            if ($publicOnly) {
                // Die öffentliche Suche sieht nur, was auch öffentlich ist: sonst verrät ein Treffer verborgene Angaben.
                $where[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR (FIND_IN_SET('organization', c.public_fields) > 0 AND c.organization LIKE ?)"
                    . ' OR c.id IN (SELECT contact_id FROM ' . Schema::table('item') . ' WHERE is_public = 1 AND value LIKE ?))';
                array_push($params, $like, $like, $like, $like);
                continue;
            }
            $where[] = '(c.display_name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.organization LIKE ? OR c.nickname LIKE ? OR c.note LIKE ?'
                . ' OR c.id IN (SELECT contact_id FROM ' . Schema::table('item') . ' WHERE value LIKE ?))';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param list<int|string> $params
     */
    private function one(string $where, array $params): ?Contact
    {
        $rows = rex_sql::factory()->getArray('SELECT c.*, (c.photo_data IS NOT NULL) AS has_photo_data FROM ' . Schema::table('contact') . ' c WHERE ' . $where . ' LIMIT 1', $params);

        return $this->hydrateAll($rows, true)[0] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Contact>
     */
    private function hydrateAll(array $rows, bool $withPhoto): array
    {
        $contacts = [];
        foreach ($rows as $row) {
            $contact = new Contact();
            $contact->id = (int) $row['id'];
            $contact->bookId = (int) $row['book_id'];
            $contact->uid = (string) $row['uid'];
            $contact->uri = (string) $row['uri'];
            $contact->etag = (string) $row['etag'];
            $contact->isCompany = (bool) $row['is_company'];
            $contact->prefix = (string) $row['prefix'];
            $contact->firstName = (string) $row['first_name'];
            $contact->middleName = (string) $row['middle_name'];
            $contact->lastName = (string) $row['last_name'];
            $contact->suffix = (string) $row['suffix'];
            $contact->nickname = (string) $row['nickname'];
            $contact->organization = (string) $row['organization'];
            $contact->department = (string) $row['department'];
            $contact->jobTitle = (string) $row['job_title'];
            $contact->birthday = null === $row['birthday'] ? null : (string) $row['birthday'];
            $contact->note = null === $row['note'] ? null : (string) $row['note'];
            $contact->photoMedia = null === $row['photo_media'] ? null : (string) $row['photo_media'];
            $contact->photoType = null === $row['photo_type'] ? null : (string) $row['photo_type'];
            $contact->photoStored = (bool) $row['has_photo_data'];
            $contact->photoLoaded = $withPhoto;
            $contact->photoData = $withPhoto && null !== $row['photo_data'] ? (string) $row['photo_data'] : null;
            $contact->extraVcard = null === $row['extra_vcard'] ? null : (string) $row['extra_vcard'];
            $contact->isPublic = (bool) $row['is_public'];
            $contact->publicFields = array_values(array_filter(explode(',', (string) $row['public_fields'])));
            $contact->updatedAt = new \DateTimeImmutable((string) $row['updatedate']);
            $contact->createUser = (string) $row['createuser'];
            $contacts[$contact->id] = $contact;
        }
        if ([] === $contacts) {
            return [];
        }

        $ids = implode(',', array_keys($contacts));
        foreach (rex_sql::factory()->getArray('SELECT * FROM ' . Schema::table('item') . ' WHERE contact_id IN (' . $ids . ') ORDER BY contact_id, priority, id') as $row) {
            $kind = ItemKind::tryFrom((string) $row['kind']);
            if (null !== $kind) {
                $data = json_decode((string) ($row['data'] ?? ''), true);
                $contacts[(int) $row['contact_id']]->items[] = new Item($kind, (string) $row['label'], (string) $row['value'], is_array($data) ? array_map(strval(...), $data) : [], (bool) $row['is_public']);
            }
        }
        foreach (rex_sql::factory()->getArray('SELECT list_id, contact_id FROM ' . Schema::table('list_member') . ' WHERE contact_id IN (' . $ids . ')') as $row) {
            $contacts[(int) $row['contact_id']]->listIds[] = (int) $row['list_id'];
        }

        return array_values($contacts);
    }
}
