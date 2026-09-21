<?php

declare(strict_types=1);

namespace KLXM\Contacts\Frontend;

use KLXM\Contacts\Api\PhotoApi;
use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\ItemKind;
use rex_fragment;
use rex_url;

/**
 * Ausgabe auf der Website. Arbeitet ausschließlich mit der öffentlichen Sicht eines Kontakts.
 */
final class Frontend
{
    public const array LAYOUTS = ['cards', 'list', 'table'];

    /** Was ein Modul oder Template aus den freigegebenen Angaben zeigen kann */
    public const array SHOW_OPTIONS = ['photo', 'organization', 'phone', 'email', 'address', 'url', 'more', 'note'];
    public const array SHOW_DEFAULT = ['photo', 'organization', 'phone', 'email'];

    /**
     * Schränkt die öffentliche Sicht weiter ein: etwa nur Name und E-Mail. Erweitern kann das nichts.
     *
     * @param list<string> $show Werte aus SHOW_OPTIONS
     */
    public static function reduce(Contact $contact, array $show): Contact
    {
        $view = clone $contact;
        $kinds = ['phone' => [ItemKind::Phone], 'email' => [ItemKind::Email], 'address' => [ItemKind::Address], 'url' => [ItemKind::Url],
            'more' => [ItemKind::Date, ItemKind::Related, ItemKind::Social, ItemKind::Messenger, ItemKind::Custom]];
        $allowed = [];
        foreach ($kinds as $key => $group) {
            if (in_array($key, $show, true)) {
                $allowed = [...$allowed, ...$group];
            }
        }
        $view->items = array_values(array_filter($view->items, static fn ($item): bool => in_array($item->kind, $allowed, true)));
        if (!in_array('organization', $show, true)) {
            $view->department = '';
            $view->jobTitle = '';
            if (!$view->isCompany) {
                $view->organization = '';
            }
        }
        if (!in_array('note', $show, true)) {
            $view->note = null;
        }
        if (!in_array('more', $show, true)) {
            $view->birthday = null;
        }
        if (!in_array('photo', $show, true)) {
            $view->publicFields = array_values(array_diff($view->publicFields, ['photo']));
        }

        return $view;
    }

    /**
     * Fertige Kartenliste aus dem Fragment contacts/list.php.
     *
     * @param list<Contact> $contacts aus Contacts::published()
     * @param array<string, mixed> $options layout (cards, list, table), show (Werte aus SHOW_OPTIONS), heading, css, empty
     */
    public static function renderList(array $contacts, array $options = []): string
    {
        $fragment = new rex_fragment(['contacts' => $contacts, ...$options]);

        return $fragment->parse('contacts/list.php');
    }

    /** Adresse des freigegebenen Fotos, sonst null. */
    public static function photoUrl(Contact $contact): ?string
    {
        if (null === $contact->id || !$contact->isFieldPublic('photo') || !$contact->hasPhoto) {
            return null;
        }

        return rex_url::frontendController(['rex-api-call' => PhotoApi::NAME, 'id' => $contact->id, 'v' => $contact->etag], false);
    }
}
