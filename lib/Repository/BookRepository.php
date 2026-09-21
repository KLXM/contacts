<?php

declare(strict_types=1);

namespace KLXM\Contacts\Repository;

use KLXM\Contacts\Contacts;
use KLXM\Contacts\Domain\Book;
use KLXM\Contacts\I18n;
use KLXM\Contacts\Schema;
use rex_sql;

final class BookRepository
{
    public const int ADDED = 1;
    public const int MODIFIED = 2;
    public const int DELETED = 3;

    /** @var array<int, Book>|null */
    private ?array $cache = null;

    /**
     * @return array<int, Book> nach ID
     */
    public function all(): array
    {
        if (null === $this->cache) {
            $this->cache = [];
            foreach (rex_sql::factory()->getArray('SELECT * FROM ' . Schema::table('book') . ' ORDER BY priority, name') as $row) {
                $book = self::hydrate($row);
                $this->cache[(int) $book->id] = $book;
            }
        }

        return $this->cache;
    }

    public function find(int $id): ?Book
    {
        return $this->all()[$id] ?? null;
    }

    public function findBySlug(string $slug): ?Book
    {
        return array_find($this->all(), static fn (Book $book): bool => $book->slug === $slug);
    }

    public function save(Book $book): void
    {
        $book->name = trim($book->name);
        if ('' === $book->name) {
            throw new \InvalidArgumentException(I18n::t('error_book_name'));
        }
        if (1 !== preg_match('/^#[0-9a-fA-F]{6}$/', $book->color)) {
            $book->color = '#3788d8';
        }
        if ('' === $book->slug) {
            $book->slug = $this->uniqueSlug($book->name, $book->id);
        }

        $sql = rex_sql::factory()->setTable(Schema::table('book'));
        $sql->setValues([
            'name' => $book->name,
            'slug' => $book->slug,
            'color' => $book->color,
            'description' => $book->description,
            'dav_enabled' => (int) $book->davEnabled,
            'priority' => $book->priority,
        ]);
        $sql->addGlobalUpdateFields(Contacts::actor());
        if (null === $book->id) {
            $sql->setValue('sync_token', 1)->addGlobalCreateFields(Contacts::actor())->insert();
            $book->id = (int) $sql->getLastId();
        } else {
            $sql->setWhere(['id' => $book->id])->update();
        }
        $this->cache = null;
    }

    public function delete(Book $book): void
    {
        $count = (int) rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . Schema::table('contact') . ' WHERE book_id = ?', [$book->id])[0]['c'];
        if ($count > 0) {
            throw new \RuntimeException(I18n::t('book_delete_blocked'));
        }
        foreach (['list' => 'book_id', 'change' => 'book_id', 'book' => 'id'] as $table => $column) {
            rex_sql::factory()->setQuery('DELETE FROM ' . Schema::table($table) . ' WHERE ' . $column . ' = ?', [$book->id]);
        }
        $this->cache = null;
    }

    /**
     * Hält fest, dass sich eine Karte geändert hat. Kontakte-Apps fragen mit dem Sync-Token nur das Neue ab.
     */
    public function logChange(int $bookId, string $uri, int $operation): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery('UPDATE ' . Schema::table('book') . ' SET sync_token = sync_token + 1 WHERE id = ?', [$bookId]);
        $token = (int) $sql->getArray('SELECT sync_token FROM ' . Schema::table('book') . ' WHERE id = ?', [$bookId])[0]['sync_token'];
        rex_sql::factory()->setTable(Schema::table('change'))->setValues(['book_id' => $bookId, 'uri' => $uri, 'operation' => $operation, 'sync_token' => $token - 1])->insert();
        $this->cache = null;
    }

    /**
     * @return array<int, int> Anzahl Kontakte je Adressbuch
     */
    public function counts(): array
    {
        $counts = [];
        foreach (rex_sql::factory()->getArray('SELECT book_id, COUNT(*) AS c FROM ' . Schema::table('contact') . ' GROUP BY book_id') as $row) {
            $counts[(int) $row['book_id']] = (int) $row['c'];
        }

        return $counts;
    }

    private function uniqueSlug(string $name, ?int $ownId): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']))), '-') ?: 'book';
        $slug = $base;
        for ($i = 2; null !== ($other = $this->findBySlug($slug)) && $other->id !== $ownId; ++$i) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Book
    {
        $book = new Book();
        $book->id = (int) $row['id'];
        $book->name = (string) $row['name'];
        $book->slug = (string) $row['slug'];
        $book->color = (string) $row['color'];
        $book->description = null === $row['description'] || '' === $row['description'] ? null : (string) $row['description'];
        $book->davEnabled = (bool) $row['dav_enabled'];
        $book->priority = (int) $row['priority'];
        $book->syncToken = (int) $row['sync_token'];

        return $book;
    }
}
