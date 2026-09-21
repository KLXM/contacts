<?php

declare(strict_types=1);

namespace KLXM\Contacts\Backend;

use KLXM\Contacts\Contacts;
use KLXM\Contacts\Domain\Book;
use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\Item;
use KLXM\Contacts\Domain\ItemKind;
use KLXM\Contacts\I18n;
use KLXM\Contacts\Settings;
use rex_addon;
use rex_csrf_token;
use rex_var_media;

/**
 * Editor eines Kontakts. Mehrfachfelder tragen je Eintrag eine Beschriftung: eine vorgegebene oder eine eigene.
 * Über "Feld hinzufügen" kommen weitere Feldarten und eigene Felder dazu.
 *
 * @internal
 */
final class ContactForm
{
    public const string CSRF = 'contacts_contact';
    private const string CUSTOM = '__custom__';

    /**
     * @param array<int, Book> $books
     */
    public static function render(Contact $contact, array $books, string $action, string $cancelUrl, ?string $error = null): string
    {
        $html = null === $error ? '' : \rex_view::error(Html::e($error));
        $html .= '<form class="contacts-form" action="' . $action . '" method="post"><contacts-editor>' . rex_csrf_token::factory(self::CSRF)->getHiddenField();

        // Kopf: Bild, Name, Firma
        $html .= '<div class="contacts-form-head">' . Avatar::render($contact, 'large') . '<div class="contacts-form-names">'
            . '<div class="contacts-grid">'
            . self::text('first_name', $contact->firstName, I18n::t('first_name'), 6, ['autofocus' => null === $contact->id])
            . self::text('last_name', $contact->lastName, I18n::t('last_name'), 6)
            . self::text('organization', $contact->organization, I18n::t('organization'), 6)
            . self::text('job_title', $contact->jobTitle, I18n::t('job_title'), 6)
            . '</div>'
            . Html::toggle('is_company', $contact->isCompany, I18n::t('is_company'))
            . '<details class="contacts-more"' . ('' !== $contact->prefix . $contact->middleName . $contact->suffix . $contact->nickname . $contact->department ? ' open' : '') . '><summary>' . I18n::e('more_name_fields') . '</summary><div class="contacts-grid">'
            . self::text('prefix', $contact->prefix, I18n::t('prefix'), 4)
            . self::text('middle_name', $contact->middleName, I18n::t('middle_name'), 4)
            . self::text('suffix', $contact->suffix, I18n::t('suffix'), 4)
            . self::text('nickname', $contact->nickname, I18n::t('nickname'), 6)
            . self::text('department', $contact->department, I18n::t('department'), 6)
            . '</div></details></div></div>';

        // Freigabe für die Website
        $fields = '';
        foreach (Contact::PUBLIC_FIELDS as $field) {
            $fields .= '<label class="contacts-check"><input type="checkbox" name="public_fields[]" value="' . $field . '"' . (in_array($field, $contact->publicFields, true) ? ' checked' : '') . '> ' . I18n::e('public_field_' . $field) . '</label>';
        }
        $html .= '<div class="contacts-section contacts-public" data-public-section><h3 class="contacts-section-title"><i class="rex-icon fa-globe" aria-hidden="true"></i> ' . I18n::e('public_title') . '</h3>'
            . Html::toggle('is_public', $contact->isPublic, I18n::t('public_toggle'), null, ['data-public-toggle' => true])
            . '<p class="help-block">' . I18n::e('public_help') . '</p>'
            . '<div class="contacts-public-fields" data-public-fields><p class="contacts-public-always"><i class="rex-icon fa-check" aria-hidden="true"></i> ' . I18n::e('public_always_name') . '</p>'
            . '<div class="contacts-lists-choice">' . $fields . '</div><p class="help-block">' . I18n::e('public_items_help') . '</p>'
            . ([] === Settings::publicBlocked() ? '' : '<p class="contacts-public-blocked"><i class="rex-icon fa-shield-halved" aria-hidden="true"></i> ' . I18n::e('public_blocked_hint', self::blockedText()) . '</p>') . '</div></div>';

        // Mehrfachfelder
        foreach (ItemKind::cases() as $kind) {
            $items = $contact->items($kind);
            $rows = '';
            foreach ($items as $index => $item) {
                $rows .= self::row($kind, (string) $index, $item);
            }
            $html .= '<contacts-items class="contacts-section" data-kind="' . $kind->value . '" data-next="' . count($items) . '"' . ([] === $items ? ' hidden' : '') . '>'
                . '<h3 class="contacts-section-title"><i class="rex-icon ' . $kind->icon() . '" aria-hidden="true"></i> ' . Html::e($kind->title()) . '</h3>'
                . '<div data-rows>' . $rows . '</div><template>' . self::row($kind, '__i__', null) . '</template>'
                . '<button type="button" class="btn btn-default btn-xs" data-add><i class="rex-icon fa-plus"></i> ' . I18n::e('add_kind', $kind->title()) . '</button></contacts-items>';
        }

        // Geburtstag und Notiz
        [$year, $month, $day] = null === $contact->birthday ? ['', '', ''] : [$contact->birthdayHasYear ? substr($contact->birthday, 0, 4) : '', substr($contact->birthday, -5, 2), substr($contact->birthday, -2)];
        $months = [];
        for ($m = 1; $m <= 12; ++$m) {
            $months[sprintf('%02d', $m)] = (string) \rex_formatter::intlDate(mktime(12, 0, 0, $m, 1, 2024) ?: 0, 'MMMM');
        }
        $days = array_combine(array_map(static fn (int $d): string => sprintf('%02d', $d), range(1, 31)), array_map(strval(...), range(1, 31)));
        $html .= '<div class="contacts-section" data-section="birthday"' . (null === $contact->birthday ? ' hidden' : '') . '><h3 class="contacts-section-title"><i class="rex-icon fa-cake-candles" aria-hidden="true"></i> ' . I18n::e('birthday') . '</h3>'
            . '<div class="contacts-birthday">' . Html::select('birthday_day', $days, $day, ['class' => 'form-control', 'aria-label' => I18n::t('day')], I18n::t('day'))
            . Html::select('birthday_month', $months, $month, ['class' => 'form-control', 'aria-label' => I18n::t('month')], I18n::t('month'))
            . Html::input('number', 'birthday_year', $year, ['class' => 'form-control', 'min' => 1850, 'max' => (int) date('Y'), 'placeholder' => I18n::t('year_optional'), 'aria-label' => I18n::t('year_optional')]) . '</div></div>';
        $html .= '<div class="contacts-section" data-section="note"' . (null === $contact->note || '' === $contact->note ? ' hidden' : '') . '><h3 class="contacts-section-title"><i class="rex-icon fa-note-sticky" aria-hidden="true"></i> ' . I18n::e('note') . '</h3>'
            . '<textarea class="form-control" name="note" rows="4" aria-label="' . I18n::e('note') . '">' . Html::e($contact->note) . '</textarea></div>';

        $html .= self::addFieldMenu();

        // Foto
        $photo = rex_var_media::getWidget(1, 'photo_media', (string) $contact->photoMedia, ['types' => 'jpg,jpeg,png,gif,webp', 'preview' => 1]);
        $html .= '<div class="contacts-section"><h3 class="contacts-section-title"><i class="rex-icon fa-camera" aria-hidden="true"></i> ' . I18n::e('photo') . '</h3>' . $photo
            . '<p class="help-block">' . I18n::e($contact->photoStored ? 'photo_help_stored' : 'photo_help') . '</p>'
            . ($contact->photoStored ? '<label class="contacts-check"><input type="checkbox" name="photo_remove" value="1"> ' . I18n::e('photo_remove') . '</label>' : '') . '</div>';

        // Adressbuch und Listen
        $bookOptions = array_map(static fn (Book $book): string => $book->name, $books);
        $lists = '';
        foreach (Contacts::lists()->forBooks(array_keys($books)) as $list) {
            $lists .= '<label class="contacts-check" data-book="' . $list->bookId . '"' . ($list->bookId === $contact->bookId ? '' : ' hidden') . '><input type="checkbox" name="lists[]" value="' . $list->id . '"'
                . (in_array($list->id, $contact->listIds, true) ? ' checked' : '') . ($list->bookId === $contact->bookId ? '' : ' disabled') . '> ' . Html::e($list->name) . '</label>';
        }
        $html .= '<div class="contacts-section"><h3 class="contacts-section-title"><i class="rex-icon fa-address-book" aria-hidden="true"></i> ' . I18n::e('book_and_lists') . '</h3>'
            . Html::select('book_id', $bookOptions, $contact->bookId, ['class' => 'form-control', 'data-book-select' => true, 'aria-label' => I18n::t('book')])
            . '<div class="contacts-lists-choice">' . ('' === $lists ? '' : $lists) . '<p class="help-block" data-no-lists>' . I18n::e('lists_help') . '</p></div></div>';

        return $html . Html::formActions($cancelUrl) . '</contacts-editor></form>';
    }

    /** Überträgt das abgeschickte Formular auf den Kontakt. */
    public static function fill(Contact $contact): void
    {
        foreach (['prefix' => 'prefix', 'first_name' => 'firstName', 'middle_name' => 'middleName', 'last_name' => 'lastName', 'suffix' => 'suffix', 'nickname' => 'nickname', 'organization' => 'organization', 'department' => 'department', 'job_title' => 'jobTitle'] as $field => $property) {
            $contact->{$property} = trim(rex_post($field, 'string'));
        }
        $contact->isCompany = rex_post('is_company', 'bool');
        $contact->bookId = rex_post('book_id', 'int');
        $contact->listIds = array_values(array_map(intval(...), rex_post('lists', 'array', [])));
        $contact->note = rex_post('note', 'string');
        $contact->isPublic = rex_post('is_public', 'bool');
        $contact->publicFields = array_values(array_intersect(Contact::PUBLIC_FIELDS, array_map(strval(...), rex_post('public_fields', 'array', []))));

        $day = rex_post('birthday_day', 'string');
        $month = rex_post('birthday_month', 'string');
        $year = rex_post('birthday_year', 'int');
        $contact->birthday = 1 === preg_match('/^\d{2}$/', $day) && 1 === preg_match('/^\d{2}$/', $month) ? ($year > 0 ? sprintf('%04d', $year) : '-') . '-' . $month . '-' . $day : null;

        $media = rex_post('photo_media', 'string');
        if ('' !== $media) {
            $contact->photoMedia = $media;
        } else {
            $contact->photoMedia = null;
        }
        if (rex_post('photo_remove', 'bool')) {
            $contact->photoLoaded = true;
            $contact->photoData = null;
            $contact->photoStored = false;
        }

        $contact->items = [];
        foreach (rex_post('items', 'array', []) as $kindValue => $rows) {
            $kind = ItemKind::tryFrom((string) $kindValue);
            if (null === $kind || !is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = trim((string) ($row['label'] ?? ''));
                if (self::CUSTOM === $label) {
                    $label = trim((string) ($row['custom_label'] ?? ''));
                }
                if ('' === $label) {
                    $label = ItemKind::Custom === $kind ? I18n::t('kind_custom') : $kind->defaultLabel();
                }
                $item = ItemKind::Address === $kind
                    ? Item::address($label, ...array_map(static fn (string $part): string => trim((string) ($row[$part] ?? '')), Item::ADDRESS_PARTS))
                    : new Item($kind, $label, trim((string) ($row['value'] ?? '')));
                $item->isPublic = !empty($row['public']);
                $contact->items[] = $item;
            }
        }
    }

    /** Die globalen Sperren in Worten, etwa "Telefon (Mobil), Geburtstag". */
    private static function blockedText(): string
    {
        $parts = [];
        foreach (Settings::publicBlocked() as $entry) {
            [$key, $label] = explode(':', $entry, 2) + [1 => null];
            $kind = ItemKind::tryFrom($key);
            $parts[] = match (true) {
                null === $kind => I18n::t('public_field_' . $key),
                null === $label => $kind->title(),
                default => $kind->title() . ' (' . $kind->labelText($label) . ')',
            };
        }

        return implode(', ', $parts);
    }

    private static function row(ItemKind $kind, string $index, ?Item $item): string
    {
        $name = 'items[' . $kind->value . '][' . $index . ']';
        $label = $item->label ?? $kind->defaultLabel();

        if (ItemKind::Custom === $kind) {
            $labelControl = Html::input('text', $name . '[label]', $item?->label, ['class' => 'form-control', 'list' => 'contacts-custom-fields', 'placeholder' => I18n::t('custom_field_name'), 'aria-label' => I18n::t('custom_field_name'), 'data-label' => true]);
        } else {
            $options = [];
            foreach ($kind->labels() as $preset) {
                $options[$preset] = $kind->labelText($preset);
            }
            foreach (in_array($kind, [ItemKind::Social, ItemKind::Messenger], true) ? [] : Settings::customLabels() as $custom) {
                $options[$custom] = $custom;
            }
            if ('' !== $label && !isset($options[$label])) {
                $options[$label] = $label;
            }
            $options[self::CUSTOM] = I18n::t('custom_label');
            $labelControl = Html::select($name . '[label]', $options, $label, ['class' => 'form-control', 'aria-label' => I18n::t('label'), 'data-label-select' => true])
                . Html::input('text', $name . '[custom_label]', '', ['class' => 'form-control', 'placeholder' => I18n::t('custom_label_placeholder'), 'aria-label' => I18n::t('custom_label_placeholder'), 'hidden' => true, 'data-custom-label' => true]);
        }

        if (ItemKind::Address === $kind) {
            $part = static fn (string $key, string $class): string => Html::input('text', $name . '[' . $key . ']', $item?->data[$key] ?? '', ['class' => 'form-control ' . $class, 'placeholder' => I18n::t('address_' . $key), 'aria-label' => I18n::t('address_' . $key)]);
            $valueControl = '<div class="contacts-address">' . $part('street', 'contacts-address-wide') . $part('postal_code', 'contacts-address-zip') . $part('city', 'contacts-address-city') . $part('region', 'contacts-address-half') . $part('country', 'contacts-address-half') . '</div>';
        } elseif (ItemKind::Date === $kind && rex_addon::get('a11y_datetime_addon')->isAvailable()) {
            $valueControl = Html::input('text', $name . '[value]', $item?->value, ['class' => 'form-control a11y_datetime', 'data-locale' => I18n::language(), 'data-dateFormat' => 'Y-m-d', 'data-altFormat' => 'j. F Y', 'data-allowInput' => 'true', 'autocomplete' => 'off', 'aria-label' => $kind->title()]);
        } else {
            $valueControl = Html::input($kind->inputType(), $name . '[value]', $item?->value, ['class' => 'form-control', 'aria-label' => $kind->title(), 'placeholder' => I18n::t('placeholder_' . $kind->value)]);
        }

        return '<div class="contacts-item-row" data-row><div class="contacts-item-label">' . $labelControl . '</div><div class="contacts-item-value">' . $valueControl . '</div>'
            . '<label class="contacts-item-public" title="' . I18n::e('public_item') . '"><input type="checkbox" name="' . Html::e($name) . '[public]" value="1"' . (true === $item?->isPublic ? ' checked' : '') . ' aria-label="' . I18n::e('public_item') . '"><i class="rex-icon fa-globe" aria-hidden="true"></i></label>'
            . '<button type="button" class="btn btn-default contacts-item-remove" data-remove title="' . I18n::e('remove') . '" aria-label="' . I18n::e('remove') . '"><i class="rex-icon fa-minus"></i></button></div>';
    }

    private static function addFieldMenu(): string
    {
        $entries = '';
        foreach (ItemKind::cases() as $kind) {
            if (ItemKind::Custom !== $kind) {
                $entries .= '<li><button type="button" data-add-kind="' . $kind->value . '"><i class="rex-icon ' . $kind->icon() . '" aria-hidden="true"></i> ' . Html::e($kind->title()) . '</button></li>';
            }
        }
        $entries .= '<li><button type="button" data-show-section="birthday"><i class="rex-icon fa-cake-candles" aria-hidden="true"></i> ' . I18n::e('birthday') . '</button></li>'
            . '<li><button type="button" data-show-section="note"><i class="rex-icon fa-note-sticky" aria-hidden="true"></i> ' . I18n::e('note') . '</button></li><li class="divider" role="separator"></li>';
        $datalist = '';
        foreach (Settings::customFields() as $field) {
            $entries .= '<li><button type="button" data-add-kind="custom" data-label="' . Html::e($field) . '"><i class="rex-icon fa-pen" aria-hidden="true"></i> ' . Html::e($field) . '</button></li>';
            $datalist .= '<option value="' . Html::e($field) . '">';
        }
        $entries .= '<li><button type="button" data-add-kind="custom"><i class="rex-icon fa-plus" aria-hidden="true"></i> ' . I18n::e('custom_field_new') . '</button></li>';

        return '<div class="contacts-add-field dropup"><button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="rex-icon fa-plus"></i> '
            . I18n::e('add_field') . ' <span class="caret"></span></button><ul class="dropdown-menu contacts-add-field-menu">' . $entries . '</ul><datalist id="contacts-custom-fields">' . $datalist . '</datalist></div>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function text(string $name, string $value, string $label, int $span, array $attributes = []): string
    {
        return '<label class="contacts-grid-cell" style="--contacts-span:' . $span . '"><span>' . Html::e($label) . '</span>' . Html::input('text', $name, $value, ['class' => 'form-control', ...$attributes]) . '</label>';
    }
}
