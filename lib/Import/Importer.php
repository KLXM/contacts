<?php

declare(strict_types=1);

namespace KLXM\Contacts\Import;

use KLXM\Contacts\Contacts;
use KLXM\Contacts\Domain\Book;
use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\ContactList;
use KLXM\Contacts\Schema;
use KLXM\Contacts\VCard\Parser;
use rex_sql;

/**
 * Liest eine vCard-Datei mit beliebig vielen Karten ein. Kontakte mit bekannter UID werden aktualisiert,
 * Gruppenkarten werden zu Listen.
 */
final class Importer
{
    public function import(string $data, Book $book, ?ContactList $intoList = null): ImportResult
    {
        $parser = new Parser();
        $result = new ImportResult();
        $groups = [];
        $importedIds = [];

        foreach ($parser->read($data) as $card) {
            if ($parser->isList($card)) {
                $groups[] = $parser->listData($card);
                continue;
            }
            try {
                $uid = trim((string) ($card->UID ?? ''));
                $contact = '' === $uid ? null : Contacts::contacts()->findByUid((int) $book->id, $uid);
                $isNew = null === $contact;
                $contact ??= new Contact();
                $contact->bookId = (int) $book->id;
                $parser->apply($card, $contact);
                Contacts::contacts()->save($contact);
                $importedIds[] = (int) $contact->id;
                $isNew ? ++$result->created : ++$result->updated;
            } catch (\Throwable $e) {
                $result->errors[] = trim((string) ($card->FN ?? '?')) . ': ' . $e->getMessage();
            }
        }

        // Gruppen zuletzt, damit ihre Mitglieder schon da sind.
        foreach ($groups as $group) {
            if ('' === $group['name']) {
                continue;
            }
            $list = array_find(Contacts::lists()->forBook((int) $book->id), static fn (ContactList $list): bool => $list->uid === $group['uid'] || $list->name === $group['name']) ?? new ContactList();
            $list->bookId = (int) $book->id;
            $list->name = $group['name'];
            $list->uid = '' !== $list->uid ? $list->uid : $group['uid'];
            if ([] !== $group['members']) {
                $rows = rex_sql::factory()->getArray('SELECT id FROM ' . Schema::table('contact') . ' WHERE book_id = ? AND uid IN (' . implode(',', array_fill(0, count($group['members']), '?')) . ')', [$book->id, ...$group['members']]);
                $list->memberIds = array_values(array_unique([...$list->memberIds, ...array_map(static fn (array $row): int => (int) $row['id'], $rows)]));
            }
            Contacts::lists()->save($list);
            ++$result->lists;
        }

        if (null !== $intoList && [] !== $importedIds) {
            $intoList->memberIds = array_values(array_unique([...$intoList->memberIds, ...$importedIds]));
            Contacts::lists()->save($intoList);
        }

        return $result;
    }
}
