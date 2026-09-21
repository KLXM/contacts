<?php

declare(strict_types=1);

/**
 * Kontakte für die Website als Karten, Liste oder Tabelle.
 *
 * Variablen:
 *   contacts  list<Contact> aus Contacts::published()
 *   layout    "cards" (Standard), "list" oder "table"
 *   show      Auswahl aus Frontend::SHOW_OPTIONS; schränkt die Freigaben weiter ein
 *   heading   Überschriftenebene der Namen, Standard "h3"
 *   css       mitgeliefertes Stylesheet einbinden, Standard true
 *   empty     Text ohne Kontakte
 *
 * Das Fragment bekommt nur die öffentliche Sicht: Was nicht freigegeben ist, steckt nicht in den Objekten.
 */

use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\Item;
use KLXM\Contacts\Domain\ItemKind;
use KLXM\Contacts\Frontend\Frontend;
use KLXM\Contacts\I18n;

/** @var rex_fragment $this */
$show = array_values(array_intersect(Frontend::SHOW_OPTIONS, (array) $this->getVar('show', Frontend::SHOW_OPTIONS)));
$contacts = array_map(static fn (Contact $contact): Contact => Frontend::reduce($contact, $show), (array) $this->getVar('contacts', []));
$layout = in_array($this->getVar('layout', 'cards'), Frontend::LAYOUTS, true) ? (string) $this->getVar('layout', 'cards') : 'cards';
$heading = in_array($this->getVar('heading', 'h3'), ['h2', 'h3', 'h4', 'h5', 'div'], true) ? (string) $this->getVar('heading', 'h3') : 'h3';

if (false !== $this->getVar('css', true)) {
    echo '<link rel="stylesheet" href="' . rex_escape(rex_addon::get('contacts')->getAssetsUrl('contacts-frontend.css')) . '">';
}
if ([] === $contacts) {
    echo '<p class="contacts-fe-empty">' . rex_escape((string) $this->getVar('empty', I18n::front('front_empty'))) . '</p>';

    return;
}

$value = static function (Item $item): string {
    if (ItemKind::Address === $item->kind) {
        return nl2br(rex_escape($item->addressText()));
    }
    $href = $item->href();

    return null === $href ? rex_escape($item->value) : '<a href="' . rex_escape($href) . '"' . (str_starts_with($href, 'http') ? ' rel="noopener"' : '') . '>' . rex_escape($item->value) . '</a>';
};
$photo = static function (Contact $contact): string {
    $url = Frontend::photoUrl($contact);

    return null !== $url
        ? '<img class="contacts-fe-photo" src="' . rex_escape($url) . '" alt="" loading="lazy" width="96" height="96">'
        : '<span class="contacts-fe-photo contacts-fe-initials" aria-hidden="true">' . rex_escape($contact->initials()) . '</span>';
};

if ('table' === $layout):
    $columns = array_values(array_filter([ItemKind::Phone, ItemKind::Email, ItemKind::Address, ItemKind::Url], static fn (ItemKind $kind): bool => in_array($kind->value, $show, true)));
    ?>
<div class="contacts-fe-table-wrap"><table class="contacts-fe-table">
    <thead><tr><th><?= rex_escape(I18n::front('name')) ?></th><?php if (in_array('organization', $show, true)): ?><th><?= rex_escape(I18n::front('front_role')) ?></th><?php endif; ?>
        <?php foreach ($columns as $kind): ?><th><?= rex_escape(I18n::front('kind_' . $kind->value)) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($contacts as $contact): ?>
        <tr><th scope="row"><?= rex_escape($contact->displayName('first_last')) ?></th>
            <?php if (in_array('organization', $show, true)): ?><td><?= rex_escape($contact->subtitle()) ?></td><?php endif; ?>
            <?php foreach ($columns as $kind): ?><td><?= implode('<br>', array_map($value, $contact->items($kind))) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php
    return;
endif;
?>
<ul class="contacts-fe-list contacts-fe-layout-<?= $layout ?>">
<?php foreach ($contacts as $contact): ?>
    <li class="contacts-fe-card">
        <?php if (in_array('photo', $show, true)): ?><?= $photo($contact) ?><?php endif; ?>
        <div class="contacts-fe-body">
            <<?= $heading ?> class="contacts-fe-name"><?= rex_escape($contact->displayName('first_last')) ?></<?= $heading ?>>
            <?php if ('' !== $contact->subtitle()): ?><p class="contacts-fe-subtitle"><?= rex_escape($contact->subtitle()) ?></p><?php endif; ?>
            <?php if ([] !== $contact->items): ?>
            <dl class="contacts-fe-items">
                <?php foreach (ItemKind::cases() as $kind): foreach ($contact->items($kind) as $item): ?>
                    <div><dt><?= rex_escape($item->kind->labelTextIn($item->label, I18n::frontLocale())) ?></dt><dd><?= $value($item) ?></dd></div>
                <?php endforeach; endforeach; ?>
            </dl>
            <?php endif; ?>
            <?php if (null !== $contact->note && '' !== $contact->note): ?><p class="contacts-fe-note"><?= nl2br(rex_escape($contact->note)) ?></p><?php endif; ?>
        </div>
    </li>
<?php endforeach; ?>
</ul>
