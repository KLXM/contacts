<?php

declare(strict_types=1);

namespace KLXM\Contacts\Repository;

use KLXM\Contacts\Contacts;
use KLXM\Contacts\Domain\ContactList;
use KLXM\Contacts\I18n;
use KLXM\Contacts\Schema;
use rex_sql;

final class ListRepository
{
    public function __construct(private readonly BookRepository $books) {}

    /**
     * @return array<int, ContactList> nach ID, mit Mitgliedern
     */
    public function forBook(int $bookId): array
    {
        return $this->load('book_id = ?', [$bookId]);
    }

    /**
     * @param list<int> $bookIds
     *
     * @return array<int, ContactList>
     */
    public function forBooks(array $bookIds): array
    {
        return [] === $bookIds ? [] : $this->load('book_id IN (' . implode(',', array_map(intval(...), $bookIds)) . ')', []);
    }

    public function find(int $id): ?ContactList
    {
        return $this->load('id = ?', [$id])[$id] ?? null;
    }

    public function findByUri(int $bookId, string $uri): ?ContactList
    {
        $lists = $this->load('book_id = ? AND uri = ?', [$bookId, $uri]);

        return [] === $lists ? null : reset($lists);
    }

    /** Speichert Name und Mitglieder. Mitglieder aus anderen Adressbüchern fallen heraus. */
    public function save(ContactList $list): void
    {
        $list->name = trim($list->name);
        if ('' === $list->name) {
            throw new \InvalidArgumentException(I18n::t('error_list_name'));
        }
        $isNew = null === $list->id;
        $list->uid = '' !== $list->uid ? $list->uid : Contacts::uuid();
        $list->uri = '' !== $list->uri ? $list->uri : $list->uid . '.vcf';
        $list->etag = bin2hex(random_bytes(8));

        $sql = rex_sql::factory()->setTable(Schema::table('list'));
        $sql->setValues([
            'book_id' => $list->bookId,
            'uid' => $list->uid,
            'uri' => $list->uri,
            'etag' => $list->etag,
            'name' => $list->name,
            'pending_members' => [] === $list->pendingMembers ? null : json_encode(array_values(array_unique($list->pendingMembers))),
        ]);
        $sql->addGlobalUpdateFields(Contacts::actor());
        if ($isNew) {
            $sql->addGlobalCreateFields(Contacts::actor())->insert();
            $list->id = (int) $sql->getLastId();
        } else {
            $sql->setWhere(['id' => $list->id])->update();
        }

        rex_sql::factory()->setQuery('DELETE FROM ' . Schema::table('list_member') . ' WHERE list_id = ?', [$list->id]);
        if ([] !== $list->memberIds) {
            $ids = implode(',', array_map(intval(...), $list->memberIds));
            rex_sql::factory()->setQuery(
                'INSERT IGNORE INTO ' . Schema::table('list_member') . ' (list_id, contact_id) SELECT ?, id FROM ' . Schema::table('contact') . ' WHERE book_id = ? AND id IN (' . $ids . ')',
                [$list->id, $list->bookId],
            );
        }
        $this->books->logChange($list->bookId, $list->uri, $isNew ? BookRepository::ADDED : BookRepository::MODIFIED);
    }

    public function delete(ContactList $list): void
    {
        rex_sql::factory()->setQuery('DELETE FROM ' . Schema::table('list_member') . ' WHERE list_id = ?', [$list->id]);
        rex_sql::factory()->setQuery('DELETE FROM ' . Schema::table('list') . ' WHERE id = ?', [$list->id]);
        $this->books->logChange($list->bookId, $list->uri, BookRepository::DELETED);
    }

    /**
     * Setzt die Listen eines Kontakts. Geänderte Listen bekommen eine neue Version, damit Apps sie neu laden.
     *
     * @param list<int> $listIds
     */
    public function setListsOfContact(int $contactId, int $bookId, array $listIds): void
    {
        $valid = array_keys($this->forBook($bookId));
        $wanted = array_values(array_intersect(array_map(intval(...), $listIds), $valid));
        $current = array_map(static fn (array $row): int => (int) $row['list_id'], rex_sql::factory()->getArray('SELECT list_id FROM ' . Schema::table('list_member') . ' WHERE contact_id = ?', [$contactId]));

        foreach (array_diff($current, $wanted) as $listId) {
            rex_sql::factory()->setQuery('DELETE FROM ' . Schema::table('list_member') . ' WHERE list_id = ? AND contact_id = ?', [$listId, $contactId]);
            $this->touch($listId);
        }
        foreach (array_diff($wanted, $current) as $listId) {
            rex_sql::factory()->setQuery('INSERT IGNORE INTO ' . Schema::table('list_member') . ' (list_id, contact_id) VALUES (?, ?)', [$listId, $contactId]);
            $this->touch($listId);
        }
    }

    /** Eine App hat eine Liste vor ihren Kontakten geschickt: Mitglied nachtragen, sobald der Kontakt da ist. */
    public function resolvePending(int $bookId, string $uid, int $contactId): void
    {
        foreach ($this->forBook($bookId) as $list) {
            if (in_array($uid, $list->pendingMembers, true)) {
                $list->pendingMembers = array_values(array_diff($list->pendingMembers, [$uid]));
                $list->memberIds[] = $contactId;
                $this->save($list);
            }
        }
    }

    private function touch(int $listId): void
    {
        $row = rex_sql::factory()->getArray('SELECT book_id, uri FROM ' . Schema::table('list') . ' WHERE id = ?', [$listId])[0] ?? null;
        if (null !== $row) {
            rex_sql::factory()->setQuery('UPDATE ' . Schema::table('list') . ' SET etag = ?, updatedate = NOW() WHERE id = ?', [bin2hex(random_bytes(8)), $listId]);
            $this->books->logChange((int) $row['book_id'], (string) $row['uri'], BookRepository::MODIFIED);
        }
    }

    /**
     * @param list<int|string> $params
     *
     * @return array<int, ContactList>
     */
    private function load(string $where, array $params): array
    {
        $lists = [];
        foreach (rex_sql::factory()->getArray('SELECT * FROM ' . Schema::table('list') . ' WHERE ' . $where . ' ORDER BY name', $params) as $row) {
            $list = new ContactList();
            $list->id = (int) $row['id'];
            $list->bookId = (int) $row['book_id'];
            $list->uid = (string) $row['uid'];
            $list->uri = (string) $row['uri'];
            $list->etag = (string) $row['etag'];
            $list->name = (string) $row['name'];
            $list->updatedAt = new \DateTimeImmutable((string) $row['updatedate']);
            $pending = json_decode((string) ($row['pending_members'] ?? ''), true);
            $list->pendingMembers = is_array($pending) ? array_values(array_map(strval(...), $pending)) : [];
            $lists[$list->id] = $list;
        }
        if ([] !== $lists) {
            foreach (rex_sql::factory()->getArray('SELECT list_id, contact_id FROM ' . Schema::table('list_member') . ' WHERE list_id IN (' . implode(',', array_keys($lists)) . ')') as $row) {
                $lists[(int) $row['list_id']]->memberIds[] = (int) $row['contact_id'];
            }
        }

        return $lists;
    }
}
