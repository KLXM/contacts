<?php

declare(strict_types=1);

namespace KLXM\Contacts\Security;

use KLXM\Contacts\Contacts;
use KLXM\Contacts\I18n;
use rex_complex_perm;

/**
 * Rollenrecht "Adressbücher": legt fest, in welchen Adressbüchern eine Rolle Kontakte pflegen darf.
 */
final class BookPerm extends rex_complex_perm
{
    public const string KEY = 'contacts_books';

    public function hasBook(int $bookId): bool
    {
        return $this->hasAll() || in_array($bookId, array_map(intval(...), $this->perms), true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getFieldParams(): array
    {
        $options = [];
        foreach (Contacts::books()->all() as $book) {
            $options[(int) $book->id] = $book->name;
        }

        return ['label' => I18n::t('perm_books'), 'all_label' => I18n::t('perm_books_all'), 'options' => $options];
    }
}
