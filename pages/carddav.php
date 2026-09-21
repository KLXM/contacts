<?php

declare(strict_types=1);

use KLXM\Contacts\Backend\Html;
use KLXM\Contacts\I18n;
use KLXM\Contacts\Security\Access;
use KLXM\Dav\Backend\ConnectPanel;

$intro = '<p class="lead">' . I18n::e('carddav_intro') . '</p><ul><li>' . I18n::e('carddav_point_sync') . '</li><li>' . I18n::e('carddav_point_lists') . '</li><li>' . I18n::e('carddav_point_photos') . '</li><li>' . I18n::e('carddav_point_password') . '</li></ul>';
echo Html::section(I18n::e('carddav_title'), $intro, '', 'default');

$rows = '';
foreach (Access::books() as $book) {
    $rows .= '<tr><td class="rex-table-icon">' . Html::colorDot($book->color) . '</td><td>' . Html::e($book->name) . '</td><td>'
        . ($book->davEnabled ? '<span class="text-success"><i class="rex-icon fa-check"></i> ' . I18n::e('dav_offered') . '</span>' : '<span class="contacts-muted"><i class="rex-icon fa-ban"></i> ' . I18n::e('dav_not_offered') . '</span>') . '</td></tr>';
}
$body = '<p>' . I18n::e('carddav_books_intro') . '</p>'
    . ('' === $rows ? '<p class="contacts-empty">' . I18n::e('no_books') . '</p>' : '<table class="table"><thead><tr><th class="rex-table-icon"></th><th>' . I18n::e('book') . '</th><th>' . I18n::e('book_dav') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>')
    . '<p class="help-block"><i class="rex-icon fa-circle-info"></i> ' . I18n::e('carddav_apple_note') . '</p>';
echo Html::section(I18n::e('carddav_books_title'), $body, '', 'default');

if (KLXM\Dav\Dav::canUse(rex::requireUser())) {
    echo new ConnectPanel('carddav')->render();
} else {
    echo rex_view::warning(I18n::e('carddav_no_perm'));
}
