<?php

declare(strict_types=1);

use KLXM\Contacts\Api\BackendApi;
use KLXM\Contacts\Backend\Html;
use KLXM\Contacts\Contacts;
use KLXM\Contacts\I18n;
use KLXM\Contacts\Import\Importer;
use KLXM\Contacts\Security\Access;

$books = Access::books();
if ([] === $books) {
    echo rex_view::info(I18n::e('no_books'));

    return;
}
$csrf = rex_csrf_token::factory('contacts_exchange');
$bookOptions = array_map(static fn ($book): string => $book->name, $books);

if ('post' === rex_request_method()) {
    $book = $books[rex_post('book_id', 'int')] ?? null;
    $file = $_FILES['vcf'] ?? null;
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } elseif (null === $book || !is_array($file) || UPLOAD_ERR_OK !== ($file['error'] ?? UPLOAD_ERR_NO_FILE) || !is_uploaded_file((string) $file['tmp_name'])) {
        echo rex_view::error(I18n::e('import_no_file'));
    } else {
        try {
            $list = rex_post('list_id', 'int') > 0 ? Contacts::lists()->find(rex_post('list_id', 'int')) : null;
            $result = new Importer()->import((string) file_get_contents((string) $file['tmp_name']), $book, null !== $list && $list->bookId === $book->id ? $list : null);
            echo rex_view::success(I18n::e('import_done', $result->created, $result->updated, $result->lists));
            if ([] !== $result->errors) {
                echo rex_view::warning(I18n::e('import_errors') . '<ul><li>' . implode('</li><li>', array_map(Html::e(...), $result->errors)) . '</li></ul>');
            }
        } catch (InvalidArgumentException $e) {
            echo rex_view::error(Html::e($e->getMessage()));
        }
    }
}

$listOptions = [];
foreach (Contacts::lists()->forBooks(array_keys($books)) as $list) {
    $listOptions[(int) $list->id] = $books[$list->bookId]->name . ' › ' . $list->name;
}
$body = '<p>' . I18n::e('import_intro') . '</p>' . $csrf->getHiddenField()
    . Html::field(I18n::t('book'), Html::select('book_id', $bookOptions, array_key_first($books), ['id' => 'contacts-import-book']), 'contacts-import-book', null, null, true)
    . Html::field(I18n::t('import_file'), '<input class="form-control" type="file" id="contacts-import-file" name="vcf" accept=".vcf,.vcard,text/vcard" required>', 'contacts-import-file', I18n::t('import_file_help'), null, true)
    . ([] === $listOptions ? '' : Html::field(I18n::t('import_into_list'), Html::select('list_id', $listOptions, null, ['id' => 'contacts-import-list'], I18n::t('import_no_list')), 'contacts-import-list', I18n::t('import_into_list_help')));
echo '<form action="' . rex_url::currentBackendPage() . '" method="post" enctype="multipart/form-data">'
    . Html::section(I18n::e('import_title'), $body, '<button class="btn btn-save" type="submit"><i class="rex-icon fa-file-import"></i> ' . I18n::e('import_start') . '</button>') . '</form>';

$rows = '';
foreach ($books as $book) {
    $links = '<a class="btn btn-default btn-xs" href="' . Html::e(BackendApi::url('export', ['book' => $book->id])) . '"><i class="rex-icon fa-download"></i> ' . I18n::e('export_all') . '</a>';
    foreach (Contacts::lists()->forBook((int) $book->id) as $list) {
        $links .= ' <a class="btn btn-default btn-xs" href="' . Html::e(BackendApi::url('export', ['book' => $book->id, 'list' => $list->id])) . '">' . Html::e($list->name) . '</a>';
    }
    $rows .= '<tr><td class="rex-table-icon">' . Html::colorDot($book->color) . '</td><td>' . Html::e($book->name) . '</td><td>' . $links . '</td></tr>';
}
echo Html::section(I18n::e('export_title'), '<p>' . I18n::e('export_intro') . '</p><table class="table"><tbody>' . $rows . '</tbody></table>', '', 'default');
