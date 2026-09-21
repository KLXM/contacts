<?php

declare(strict_types=1);

namespace KLXM\Contacts\Api;

use KLXM\Contacts\Contacts;
use KLXM\Contacts\I18n;
use KLXM\Contacts\Import\Exporter;
use KLXM\Contacts\Security\Access;
use rex_api_function;
use rex_path;
use rex_response;

/**
 * Backend-Schnittstelle: liefert Fotos und den vCard-Export. Nur für angemeldete Benutzer mit Zugriff auf das Adressbuch.
 *
 * @internal
 */
final class BackendApi extends rex_api_function
{
    public const string NAME = 'contacts';
    public const string CSRF = 'contacts_api';

    protected $published = false;

    public function execute(): never
    {
        rex_response::cleanOutputBuffers();
        if (!Access::canUse()) {
            $this->fail(403);
        }

        match (rex_request('action', 'string')) {
            'photo' => $this->photo(),
            'export' => $this->export(),
            'listAdd' => $this->listAdd(),
            default => $this->fail(400),
        };
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public static function url(string $action, array $params = []): string
    {
        return \rex_url::backendController(['rex-api-call' => self::NAME, 'action' => $action, ...$params], false);
    }

    private function photo(): never
    {
        $contact = Contacts::contacts()->find(rex_request('id', 'int'));
        if (null === $contact || !Access::canEditBook($contact->bookId)) {
            $this->fail(404);
        }
        if (null !== $contact->photoMedia && is_file(rex_path::media($contact->photoMedia))) {
            rex_response::sendFile(rex_path::media($contact->photoMedia), (string) (mime_content_type(rex_path::media($contact->photoMedia)) ?: 'image/jpeg'));
            exit;
        }
        if (null === $contact->photoData) {
            $this->fail(404);
        }
        rex_response::sendCacheControl('private, max-age=86400');
        rex_response::sendContent($contact->photoData, $contact->photoType ?? 'image/jpeg', null, $contact->etag);
        exit;
    }

    private function export(): never
    {
        $book = Contacts::books()->find(rex_request('book', 'int'));
        if (null === $book || !Access::canEditBook((int) $book->id)) {
            $this->fail(404);
        }
        $listId = rex_request('list', 'int') ?: null;
        $list = null === $listId ? null : Contacts::lists()->find($listId);
        if (null !== $list && $list->bookId !== $book->id) {
            $this->fail(404);
        }

        $name = $book->slug . (null === $list ? '' : '-' . preg_replace('/[^a-z0-9]+/i', '-', $list->name)) . '.vcf';
        header('Content-Disposition: attachment; filename="' . $name . '"');
        rex_response::sendContent(new Exporter()->book($book, $list), 'text/vcard; charset=utf-8');
        exit;
    }

    /** Kontakte per Drag & Drop in eine Liste aufnehmen. Kontakte aus anderen Adressbüchern bleiben außen vor. */
    private function listAdd(): never
    {
        if ('post' !== rex_request_method() || !\rex_csrf_token::factory(self::CSRF)->isValid()) {
            $this->fail(403);
        }
        $list = Contacts::lists()->find(rex_post('list', 'int'));
        if (null === $list || !Access::canEditBook($list->bookId)) {
            $this->fail(404);
        }
        $ids = array_values(array_unique(array_filter(array_map(intval(...), rex_post('ids', 'array', [])))));
        $inBook = array_map(static fn ($contact): int => (int) $contact->id, array_filter(
            array_map(static fn (int $id) => Contacts::contacts()->find($id), $ids),
            static fn ($contact): bool => null !== $contact && $contact->bookId === $list->bookId,
        ));
        $new = array_values(array_diff($inBook, $list->memberIds));
        if ([] !== $new) {
            $list->memberIds = [...$list->memberIds, ...$new];
            Contacts::lists()->save($list);
        }

        $message = match (true) {
            [] === $inBook => I18n::t('drop_other_book', $list->name),
            [] === $new => I18n::t('drop_already', $list->name),
            1 === count($new) => I18n::t('bulk_added_one', $list->name),
            default => I18n::t('bulk_added', count($new), $list->name),
        };
        rex_response::sendJson(['ok' => [] !== $inBook, 'added' => count($new), 'count' => count($list->memberIds), 'message' => $message]);
        exit;
    }

    private function fail(int $status): never
    {
        rex_response::setStatus((string) $status);
        rex_response::sendContent('', 'text/plain');
        exit;
    }
}
