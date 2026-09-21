<?php

declare(strict_types=1);

namespace KLXM\Contacts\Backend;

use KLXM\Contacts\Contacts;
use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\ItemKind;
use KLXM\Contacts\I18n;

/**
 * Karte eines Kontakts zum Lesen, im Aufbau wie in einer Kontakte-App: Bild und Name oben, darunter die beschrifteten Einträge.
 *
 * @internal
 */
final class ContactView
{
    /**
     * @param array<string, string> $urls edit, delete
     */
    public static function render(Contact $contact, array $urls): string
    {
        $book = Contacts::books()->find($contact->bookId);
        $html = '<header class="contacts-card-head">' . Avatar::render($contact, 'large')
            . '<div><h2 class="contacts-card-name">' . Html::e($contact->displayName()) . '</h2>'
            . ('' !== $contact->subtitle() ? '<p class="contacts-card-subtitle">' . Html::e($contact->subtitle()) . '</p>' : '')
            . ($contact->isPublic ? '<p class="contacts-card-public"><i class="rex-icon fa-globe" aria-hidden="true"></i> ' . I18n::e('public_badge') . '</p>' : '')
            . ('' !== $contact->nickname ? '<p class="contacts-card-subtitle">„' . Html::e($contact->nickname) . '“</p>' : '')
            . '</div></header>';

        $html .= '<div class="contacts-quick">';
        foreach ([ItemKind::Phone, ItemKind::Email, ItemKind::Url] as $kind) {
            $item = $contact->first($kind);
            if (null !== $item && null !== $item->href()) {
                $html .= '<a class="contacts-quick-action" href="' . Html::e($item->href()) . '"' . (ItemKind::Url === $kind ? ' target="_blank" rel="noopener"' : '') . '><i class="rex-icon ' . $kind->icon() . '" aria-hidden="true"></i><span>' . Html::e($kind->title()) . '</span></a>';
            }
        }
        $html .= '</div><dl class="contacts-card-rows">';

        foreach (ItemKind::cases() as $kind) {
            foreach ($contact->items($kind) as $item) {
                $text = ItemKind::Address === $kind ? nl2br(Html::e($item->addressText())) : Html::e(ItemKind::Date === $kind ? self::date($item->value) : $item->value);
                $href = $item->href();
                $html .= '<div class="contacts-card-row"><dt>' . Html::e($item->labelText()) . '</dt><dd>' . ($contact->isPublic && $item->isPublic ? self::globe() : '')
                    . (null === $href ? $text : '<a href="' . Html::e($href) . '"' . (str_starts_with($href, 'http') ? ' target="_blank" rel="noopener"' : '') . '>' . $text . '</a>') . '</dd></div>';
            }
        }
        if (null !== $contact->birthday) {
            $date = $contact->birthdayDate();
            $formatted = null === $date ? '' : \rex_formatter::intlDate($date->getTimestamp(), $contact->birthdayHasYear ? \IntlDateFormatter::LONG : 'd. MMMM');
            $html .= '<div class="contacts-card-row"><dt>' . I18n::e('birthday') . '</dt><dd>' . ($contact->isFieldPublic('birthday') ? self::globe() : '') . Html::e($formatted) . '</dd></div>';
        }
        if (null !== $contact->note && '' !== $contact->note) {
            $html .= '<div class="contacts-card-row"><dt>' . I18n::e('note') . '</dt><dd>' . ($contact->isFieldPublic('note') ? self::globe() : '') . nl2br(Html::e($contact->note)) . '</dd></div>';
        }

        $lists = array_intersect_key(Contacts::lists()->forBook($contact->bookId), array_flip($contact->listIds));
        $chips = Html::colorDot($book?->color) . ' ' . Html::e($book->name ?? '');
        foreach ($lists as $list) {
            $chips .= ' <span class="contacts-chip">' . Html::e($list->name) . '</span>';
        }
        $html .= '<div class="contacts-card-row"><dt>' . I18n::e('book') . '</dt><dd>' . $chips . '</dd></div></dl>';

        $html .= '<footer class="contacts-card-actions"><a class="btn btn-primary" href="' . $urls['edit'] . '"><i class="rex-icon fa-pen"></i> ' . I18n::e('edit') . '</a> '
            . '<a class="btn btn-delete" href="' . $urls['delete'] . '" data-confirm="' . I18n::e('contact_delete_confirm', $contact->displayName()) . '"><i class="rex-icon fa-trash"></i> ' . I18n::e('delete') . '</a>'
            . '<span class="contacts-card-meta">' . I18n::e('updated_at', \rex_formatter::intlDateTime($contact->updatedAt?->getTimestamp() ?? time())) . '</span></footer>';

        return '<article class="contacts-card">' . $html . '</article>';
    }

    private static function globe(): string
    {
        return '<i class="rex-icon fa-globe contacts-public-mark" title="' . I18n::e('public_item') . '" aria-label="' . I18n::e('public_item') . '"></i> ';
    }

    private static function date(string $value): string
    {
        $time = strtotime($value);

        return false === $time ? $value : \rex_formatter::intlDate($time, \IntlDateFormatter::LONG);
    }
}
