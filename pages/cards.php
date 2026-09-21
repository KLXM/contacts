<?php

declare(strict_types=1);

use KLXM\Contacts\Backend\Avatar;
use KLXM\Contacts\Backend\ContactForm;
use KLXM\Contacts\Backend\ContactView;
use KLXM\Contacts\Backend\Flash;
use KLXM\Contacts\Backend\Html;
use KLXM\Contacts\Contacts;
use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\ContactList;
use KLXM\Contacts\I18n;
use KLXM\Contacts\Security\Access;

$books = Access::books();
if ([] === $books) {
    echo rex_view::info(I18n::e(Access::canManageBooks() ? 'no_books_admin' : 'no_books'));
    if (Access::canManageBooks()) {
        echo '<p><a class="btn btn-save" href="' . rex_url::backendPage('contacts/books', ['func' => 'add']) . '">' . I18n::e('book_add') . '</a></p>';
    }

    return;
}

$bookId = rex_request('book', 'int');
$listId = rex_request('list', 'int');
$search = trim(rex_request('q', 'string'));
$func = rex_request('func', 'string');
$contactId = rex_request('id', 'int');

$list = $listId > 0 ? Contacts::lists()->find($listId) : null;
if (null !== $list && !isset($books[$list->bookId])) {
    $list = null;
}
if (null !== $list) {
    $bookId = $list->bookId;
}
$book = $books[$bookId] ?? null;
$scope = array_filter(['book' => $book?->id, 'list' => $list?->id, 'q' => '' === $search ? null : $search]);
$url = static fn (array $params = []): string => rex_url::currentBackendPage(array_filter([...$scope, ...$params], static fn (mixed $value): bool => null !== $value && '' !== $value));
$csrf = rex_csrf_token::factory('contacts_cards');

// ---------------------------------------------------------------- Aktionen: Listen

if ('post' === rex_request_method() && in_array($func, ['list_save', 'list_delete', 'bulk'], true)) {
    if (!$csrf->isValid()) {
        Flash::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        try {
            if ('list_save' === $func) {
                $target = rex_post('list_id', 'int') > 0 ? Contacts::lists()->find(rex_post('list_id', 'int')) : new ContactList();
                $targetBook = rex_post('list_book', 'int');
                if (null !== $target && isset($books[null === $target->id ? $targetBook : $target->bookId])) {
                    $target->bookId = null === $target->id ? $targetBook : $target->bookId;
                    $target->name = rex_post('list_name', 'string');
                    Contacts::lists()->save($target);
                    Flash::success(I18n::t('list_saved', $target->name));
                    rex_response::sendRedirect(rex_url::currentBackendPage(['list' => $target->id], false));
                }
            } elseif ('list_delete' === $func && null !== $list) {
                Contacts::lists()->delete($list);
                Flash::success(I18n::t('list_deleted', $list->name));
                rex_response::sendRedirect(rex_url::currentBackendPage(['book' => $list->bookId], false));
            } elseif ('bulk' === $func) {
                $ids = array_map(intval(...), rex_post('ids', 'array', []));
                $target = Contacts::lists()->find(rex_post('bulk_list', 'int'));
                if ([] !== $ids && null !== $target && isset($books[$target->bookId])) {
                    $remove = 'remove' === rex_post('bulk_action', 'string');
                    $target->memberIds = array_values($remove ? array_diff($target->memberIds, $ids) : array_unique([...$target->memberIds, ...$ids]));
                    Contacts::lists()->save($target);
                    Flash::success(1 === count($ids) ? I18n::t($remove ? 'bulk_removed_one' : 'bulk_added_one', $target->name) : I18n::t($remove ? 'bulk_removed' : 'bulk_added', count($ids), $target->name));
                }
                rex_response::sendRedirect(html_entity_decode($url()));
            }
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());
        }
    }
}

// ---------------------------------------------------------------- Aktionen: Kontakt

$contact = $contactId > 0 ? Contacts::contacts()->find($contactId) : null;
if (null !== $contact && !isset($books[$contact->bookId])) {
    $contact = null;
}
$formError = null;

if ('delete' === $func && null !== $contact) {
    if (rex_csrf_token::factory(ContactForm::CSRF)->isValid()) {
        Contacts::contacts()->delete($contact);
        Flash::success(I18n::t('contact_deleted', $contact->displayName()));
    } else {
        Flash::error(rex_i18n::msg('csrf_token_invalid'));
    }
    rex_response::sendRedirect(html_entity_decode($url()));
}

if (in_array($func, ['add', 'edit'], true)) {
    if ('add' === $func) {
        $contact = new Contact();
        $contact->bookId = (int) ($book->id ?? array_key_first($books));
        $contact->listIds = null === $list ? [] : [(int) $list->id];
    }
    if (null !== $contact && 'post' === rex_request_method()) {
        if (!rex_csrf_token::factory(ContactForm::CSRF)->isValid()) {
            $formError = rex_i18n::msg('csrf_token_invalid');
        } else {
            ContactForm::fill($contact);
            try {
                if (!isset($books[$contact->bookId])) {
                    throw new InvalidArgumentException(I18n::t('error_book_missing'));
                }
                Contacts::contacts()->save($contact);
                Flash::success(I18n::t('contact_saved', $contact->displayName()));
                rex_response::sendRedirect(html_entity_decode($url(['id' => $contact->id])));
            } catch (InvalidArgumentException $e) {
                $formError = $e->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------------- Seitenleiste

$bookCounts = Contacts::books()->counts();
$lists = Contacts::lists()->forBooks(array_keys($books));
$entry = static fn (string $href, string $label, int $count, bool $active, string $icon, string $class = '', string $attributes = ''): string => '<a class="contacts-nav-item ' . $class . ($active ? ' is-active' : '') . '" href="' . $href . '"' . $attributes . ($active ? ' aria-current="page"' : '') . '>' . $icon . '<span class="contacts-nav-label">' . Html::e($label) . '</span><span class="contacts-nav-count">' . $count . '</span></a>';

$sidebar = $entry(rex_url::currentBackendPage(), I18n::t('all_contacts'), array_sum(array_intersect_key($bookCounts, $books)), null === $book, '<i class="rex-icon fa-users" aria-hidden="true"></i>');
foreach ($books as $item) {
    $sidebar .= '<div class="contacts-nav-group">' . $entry(rex_url::currentBackendPage(['book' => $item->id]), $item->name, $bookCounts[(int) $item->id] ?? 0, $book?->id === $item->id && null === $list, Html::colorDot($item->color), 'contacts-nav-book');
    foreach ($lists as $candidate) {
        if ($candidate->bookId === $item->id) {
            $sidebar .= $entry(rex_url::currentBackendPage(['list' => $candidate->id]), $candidate->name, count($candidate->memberIds), $list?->id === $candidate->id, '<i class="rex-icon fa-list-ul" aria-hidden="true"></i>', 'contacts-nav-list', ' data-drop-list="' . $candidate->id . '" data-book="' . $candidate->bookId . '"');
        }
    }
    $sidebar .= '<details class="contacts-nav-new"><summary><i class="rex-icon fa-plus" aria-hidden="true"></i> ' . I18n::e('list_add') . '</summary>'
        . '<form method="post" action="' . rex_url::currentBackendPage(['func' => 'list_save']) . '">' . $csrf->getHiddenField() . '<input type="hidden" name="list_book" value="' . $item->id . '">'
        . '<input class="form-control input-sm" type="text" name="list_name" required maxlength="191" placeholder="' . I18n::e('list_name') . '" aria-label="' . I18n::e('list_name') . '">'
        . '<button class="btn btn-save btn-xs" type="submit">' . I18n::e('create') . '</button></form></details></div>';
}

// ---------------------------------------------------------------- Kontaktliste

$limit = 1000;
$bookScope = null === $book ? array_keys($books) : [(int) $book->id];
$total = Contacts::contacts()->count($bookScope, $list?->id, $search);
$contacts = Contacts::contacts()->query($bookScope, $list?->id, $search, $limit);

$index = '<form class="contacts-search" method="get" action="' . rex_url::backendController() . '"><input type="hidden" name="page" value="contacts/cards">'
    . (null !== $list ? '<input type="hidden" name="list" value="' . $list->id . '">' : (null !== $book ? '<input type="hidden" name="book" value="' . $book->id . '">' : ''))
    . '<input class="form-control" type="search" name="q" value="' . Html::e($search) . '" placeholder="' . I18n::e('search') . '" aria-label="' . I18n::e('search') . '">'
    . '<a class="btn btn-save" href="' . $url(['func' => 'add']) . '" title="' . I18n::e('contact_add') . '" aria-label="' . I18n::e('contact_add') . '"><i class="rex-icon fa-plus"></i></a></form>';

$heading = null !== $list ? $list->name : ($book->name ?? I18n::t('all_contacts'));
$index .= '<div class="contacts-index-head"><strong>' . Html::e($heading) . '</strong> <span class="contacts-muted">' . I18n::e('count_contacts', $total) . '</span>';
if (null !== $list) {
    $index .= '<details class="contacts-list-tools"><summary title="' . I18n::e('list_edit') . '" aria-label="' . I18n::e('list_edit') . '"><i class="rex-icon fa-ellipsis"></i></summary><div class="contacts-popover">'
        . '<form method="post" action="' . $url(['func' => 'list_save']) . '">' . $csrf->getHiddenField() . '<input type="hidden" name="list_id" value="' . $list->id . '">'
        . '<input class="form-control input-sm" type="text" name="list_name" value="' . Html::e($list->name) . '" required maxlength="191" aria-label="' . I18n::e('list_name') . '"> <button class="btn btn-save btn-xs" type="submit">' . I18n::e('rename') . '</button></form>'
        . '<a class="btn btn-default btn-xs" href="' . Html::e(KLXM\Contacts\Api\BackendApi::url('export', ['book' => $list->bookId, 'list' => $list->id])) . '"><i class="rex-icon fa-download"></i> ' . I18n::e('export_vcf') . '</a>'
        . '<form method="post" action="' . $url(['func' => 'list_delete']) . '" data-confirm="' . I18n::e('list_delete_confirm', $list->name) . '">' . $csrf->getHiddenField() . '<button class="btn btn-delete btn-xs" type="submit"><i class="rex-icon fa-trash"></i> ' . I18n::e('list_delete') . '</button></form>'
        . '</div></details>';
}
$index .= '</div>';

if ([] === $contacts) {
    $index .= '<p class="contacts-empty">' . I18n::e('' !== $search ? 'no_results' : 'no_contacts') . '</p>';
} else {
    $index .= '<form method="post" action="' . $url(['func' => 'bulk']) . '" class="contacts-index-form">' . $csrf->getHiddenField() . '<ul class="contacts-index-list">';
    $letter = null;
    foreach ($contacts as $item) {
        $first = mb_strtoupper(mb_substr(strtr($item->sortName(), ['ä' => 'a', 'ö' => 'o', 'ü' => 'u']), 0, 1));
        $first = 1 === preg_match('/\p{L}/u', $first) ? $first : '#';
        if ($first !== $letter) {
            $letter = $first;
            $index .= '<li class="contacts-index-letter" aria-hidden="true">' . Html::e($letter) . '</li>';
        }
        $active = $contact?->id === $item->id;
        $index .= '<li class="contacts-index-item' . ($active ? ' is-active' : '') . '" draggable="true" data-id="' . $item->id . '" data-book="' . $item->bookId . '" data-name="' . Html::e($item->displayName()) . '"><input type="checkbox" name="ids[]" value="' . $item->id . '" aria-label="' . I18n::e('select_contact', $item->displayName()) . '">'
            . '<a draggable="false" href="' . $url(['id' => $item->id]) . '"' . ($active ? ' aria-current="true"' : '') . '>' . Avatar::render($item) . '<span class="contacts-index-text"><span class="contacts-index-name">' . Html::e($item->displayName()) . ($item->isPublic ? ' <i class="rex-icon fa-globe contacts-public-mark" title="' . I18n::e('public_badge') . '" aria-label="' . I18n::e('public_badge') . '"></i>' : '') . '</span>'
            . ('' !== $item->subtitle() ? '<span class="contacts-index-sub">' . Html::e($item->subtitle()) . '</span>' : '') . '</span></a></li>';
    }
    $index .= '</ul>';
    if ([] !== $lists) {
        $index .= '<p class="contacts-muted contacts-drag-hint"><i class="rex-icon fa-hand" aria-hidden="true"></i> ' . I18n::e('drag_hint') . '</p>';
    }
    if ($total > $limit) {
        $index .= '<p class="contacts-muted contacts-index-more">' . I18n::e('index_limited', $limit) . '</p>';
    }

    $bulkLists = array_filter($lists, static fn (ContactList $candidate): bool => null === $book || $candidate->bookId === $book->id);
    if ([] !== $bulkLists) {
        $options = [];
        foreach ($bulkLists as $candidate) {
            $options[(int) $candidate->id] = (null === $book ? $books[$candidate->bookId]->name . ' › ' : '') . $candidate->name;
        }
        $index .= '<div class="contacts-bulk" data-bulk hidden>' . Html::select('bulk_list', $options, $list?->id, ['class' => 'form-control input-sm', 'aria-label' => I18n::t('list')])
            . '<button class="btn btn-default btn-xs" type="submit" name="bulk_action" value="add">' . I18n::e('bulk_add') . '</button>'
            . '<button class="btn btn-default btn-xs" type="submit" name="bulk_action" value="remove">' . I18n::e('bulk_remove') . '</button></div>';
    }
    $index .= '</form>';
}

// ---------------------------------------------------------------- Karte oder Editor

if (in_array($func, ['add', 'edit'], true) && null !== $contact) {
    $detail = ContactForm::render($contact, $books, $url(['func' => $func, 'id' => $contact->id]), $url(['id' => $contact->id]), $formError);
} elseif (null !== $contact) {
    $detail = ContactView::render($contact, [
        'edit' => $url(['func' => 'edit', 'id' => $contact->id]),
        'delete' => $url(['func' => 'delete', 'id' => $contact->id, ...rex_csrf_token::factory(ContactForm::CSRF)->getUrlParams()]),
    ]);
} else {
    $detail = '<div class="contacts-placeholder"><i class="rex-icon fa-address-card" aria-hidden="true"></i><p>' . I18n::e('select_hint') . '</p></div>';
}

echo Flash::render();
echo '<div class="contacts-app' . (null !== $contact || 'add' === $func ? ' has-detail' : '') . '" data-api="' . Html::e(KLXM\Contacts\Api\BackendApi::url('listAdd')) . '" data-token="' . Html::e(rex_csrf_token::factory(KLXM\Contacts\Api\BackendApi::CSRF)->getValue()) . '"><nav class="contacts-sidebar" aria-label="' . I18n::e('books_and_lists') . '">' . $sidebar . '</nav>'
    . '<section class="contacts-index" aria-label="' . I18n::e('cards') . '">' . $index . '</section>'
    . '<section class="contacts-detail">' . (null !== $contact || 'add' === $func ? '<a class="contacts-back btn btn-default btn-xs" href="' . $url() . '"><i class="rex-icon fa-chevron-left"></i> ' . I18n::e('back_to_list') . '</a>' : '') . $detail . '</section></div>';
