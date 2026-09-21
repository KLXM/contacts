<?php

declare(strict_types=1);

use KLXM\Contacts\Api\BackendApi;
use KLXM\Contacts\Backend\Flash;
use KLXM\Contacts\Backend\Html;
use KLXM\Contacts\Contacts;
use KLXM\Contacts\Domain\Book;
use KLXM\Contacts\I18n;

$func = rex_request('func', 'string');
$csrf = rex_csrf_token::factory('contacts_books');
$book = rex_request('id', 'int') > 0 ? Contacts::books()->find(rex_request('id', 'int')) : null;
$error = null;

if ('delete' === $func && null !== $book) {
    try {
        if (!$csrf->isValid()) {
            throw new RuntimeException(rex_i18n::msg('csrf_token_invalid'));
        }
        Contacts::books()->delete($book);
        Flash::success(I18n::t('book_deleted', $book->name));
    } catch (RuntimeException $e) {
        Flash::error($e->getMessage());
    }
    rex_response::sendRedirect(rex_url::currentBackendPage([], false));
}

if (in_array($func, ['add', 'edit'], true)) {
    $book = 'add' === $func ? new Book() : $book;
    if (null === $book) {
        rex_response::sendRedirect(rex_url::currentBackendPage([], false));
    }
    if ('post' === rex_request_method()) {
        if (!$csrf->isValid()) {
            $error = rex_i18n::msg('csrf_token_invalid');
        } else {
            $book->name = rex_post('name', 'string');
            $book->color = rex_post('color', 'string');
            $book->description = trim(rex_post('description', 'string')) ?: null;
            $book->davEnabled = rex_post('dav_enabled', 'bool');
            $book->priority = rex_post('priority', 'int');
            try {
                Contacts::books()->save($book);
                Flash::success(I18n::t('book_saved', $book->name));
                rex_response::sendRedirect(rex_url::currentBackendPage([], false));
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
    }

    $body = (null === $error ? '' : rex_view::error(Html::e($error))) . $csrf->getHiddenField()
        . Html::field(I18n::t('name'), Html::input('text', 'name', $book->name, ['id' => 'contacts-book-name', 'required' => true, 'maxlength' => 191, 'autofocus' => true]), 'contacts-book-name', null, null, true)
        . Html::field(I18n::t('color'), Html::input('color', 'color', $book->color, ['id' => 'contacts-book-color', 'class' => 'contacts-color']), 'contacts-book-color', I18n::t('book_color_help'))
        . Html::field(I18n::t('description'), '<textarea class="form-control" id="contacts-book-description" name="description" rows="2">' . Html::e($book->description) . '</textarea>', 'contacts-book-description')
        . Html::field(I18n::t('book_dav'), Html::toggle('dav_enabled', $book->davEnabled, I18n::t('book_dav_label'), 'contacts-book-dav'), 'contacts-book-dav', I18n::t('book_dav_help'))
        . Html::field(I18n::t('priority'), Html::input('number', 'priority', (string) $book->priority, ['id' => 'contacts-book-priority', 'class' => 'form-control contacts-narrow']), 'contacts-book-priority', I18n::t('priority_help'));

    echo '<form action="' . rex_url::currentBackendPage(['func' => $func, 'id' => $book->id]) . '" method="post">'
        . Html::section(I18n::e(null === $book->id ? 'book_add' : 'book_edit'), $body, Html::formActions(rex_url::currentBackendPage())) . '</form>';

    return;
}

echo Flash::render();

$counts = Contacts::books()->counts();
$rows = '';
foreach (Contacts::books()->all() as $item) {
    $lists = Contacts::lists()->forBook((int) $item->id);
    $rows .= '<tr><td class="rex-table-icon">' . Html::colorDot($item->color) . '</td>'
        . '<td data-title="' . I18n::e('name') . '"><a href="' . rex_url::currentBackendPage(['func' => 'edit', 'id' => $item->id]) . '">' . Html::e($item->name) . '</a>'
        . (null !== $item->description ? '<br><small class="contacts-muted">' . Html::e($item->description) . '</small>' : '') . '</td>'
        . '<td data-title="' . I18n::e('cards') . '">' . ($counts[(int) $item->id] ?? 0) . '</td>'
        . '<td data-title="' . I18n::e('lists') . '">' . Html::e(implode(', ', array_map(static fn ($list): string => $list->name, $lists)) ?: '–') . '</td>'
        . '<td data-title="' . I18n::e('book_dav') . '">' . ($item->davEnabled ? '<span class="text-success"><i class="rex-icon fa-check"></i> ' . I18n::e('dav_offered') . '</span>' : '<span class="contacts-muted">' . I18n::e('dav_not_offered') . '</span>') . '</td>'
        . '<td class="rex-table-action"><a href="' . rex_url::backendPage('contacts/cards', ['book' => $item->id]) . '"><i class="rex-icon fa-address-card"></i> ' . I18n::e('cards') . '</a></td>'
        . '<td class="rex-table-action"><a href="' . Html::e(BackendApi::url('export', ['book' => $item->id])) . '"><i class="rex-icon fa-download"></i> ' . I18n::e('export_vcf') . '</a></td>'
        . '<td class="rex-table-action"><a href="' . rex_url::currentBackendPage(['func' => 'edit', 'id' => $item->id]) . '"><i class="rex-icon fa-pen"></i> ' . I18n::e('edit') . '</a></td>'
        . '<td class="rex-table-action"><a class="rex-link-expanded" href="' . rex_url::currentBackendPage(['func' => 'delete', 'id' => $item->id, ...$csrf->getUrlParams()]) . '" data-confirm="' . I18n::e('book_delete_confirm', $item->name) . '"><i class="rex-icon fa-trash"></i> ' . I18n::e('delete') . '</a></td></tr>';
}

$table = '' === $rows
    ? '<p class="contacts-empty">' . I18n::e('no_books_admin') . '</p>'
    : '<table class="table table-hover"><thead><tr><th class="rex-table-icon"></th><th>' . I18n::e('name') . '</th><th>' . I18n::e('cards') . '</th><th>' . I18n::e('lists') . '</th><th>' . I18n::e('book_dav') . '</th><th class="rex-table-action" colspan="4">' . I18n::e('functions') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';

echo Html::section(I18n::e('books'), '<p>' . I18n::e('books_intro') . '</p>' . $table, '', 'default',
    '<a class="btn btn-save btn-xs" href="' . rex_url::currentBackendPage(['func' => 'add']) . '"><i class="rex-icon fa-plus"></i> ' . I18n::e('book_add') . '</a>');
