<?php

declare(strict_types=1);

namespace KLXM\Contacts\Import;

use KLXM\Contacts\Contacts;
use KLXM\Contacts\Domain\Book;
use KLXM\Contacts\Domain\ContactList;
use KLXM\Contacts\VCard\Serializer;

final class Exporter
{
    /** Alle Kontakte eines Adressbuchs oder einer Liste als eine vCard-Datei. */
    public function book(Book $book, ?ContactList $list = null): string
    {
        $serializer = new Serializer();
        $data = '';
        foreach (Contacts::contacts()->query([(int) $book->id], $list?->id) as $entry) {
            // Listen enthalten keine Fotodaten, deshalb jeden Kontakt einzeln vollständig laden.
            $contact = Contacts::contacts()->find((int) $entry->id);
            if (null !== $contact) {
                $data .= $serializer->contact($contact);
            }
        }

        return $data;
    }
}
