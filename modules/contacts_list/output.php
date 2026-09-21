<?php
[$contactsType, $contactsId] = explode(':', 'REX_VALUE[1]', 2) + ['', ''];
$contactsPeople = \KLXM\Contacts\Contacts::published(
    book: 'book' === $contactsType ? (int) $contactsId : null,
    list: 'list' === $contactsType ? (int) $contactsId : null,
);

if ('' !== 'REX_VALUE[4]') {
    echo '<h2>' . rex_escape('REX_VALUE[4]') . '</h2>';
}
echo \KLXM\Contacts\Frontend\Frontend::renderList($contactsPeople, [
    'layout' => 'REX_VALUE[3]' ?: 'cards',
    'show' => rex_var::toArray('REX_VALUE[5]') ?: \KLXM\Contacts\Frontend\Frontend::SHOW_DEFAULT,
    'css' => '0' !== 'REX_VALUE[6]',
]);
if (rex::isBackend() && [] === $contactsPeople) {
    echo '<p class="help-block">' . \KLXM\Contacts\I18n::e('module_backend_hint') . '</p>';
}
