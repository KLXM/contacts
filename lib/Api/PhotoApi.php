<?php

declare(strict_types=1);

namespace KLXM\Contacts\Api;

use KLXM\Contacts\Contacts;
use rex_api_function;
use rex_path;
use rex_response;

/**
 * Öffentliche Fotos: liefert ein Bild nur, wenn der Kontakt öffentlich und sein Foto freigegeben ist.
 *
 * @internal
 */
final class PhotoApi extends rex_api_function
{
    public const string NAME = 'contacts_photo';

    protected $published = true;

    public function execute(): never
    {
        rex_response::cleanOutputBuffers();
        $contact = Contacts::contacts()->find(rex_request('id', 'int'));
        if (null === $contact || !$contact->isFieldPublic('photo') || !$contact->hasPhoto) {
            rex_response::setStatus(rex_response::HTTP_NOT_FOUND);
            rex_response::sendContent('', 'text/plain');
            exit;
        }

        // yrewrite setzt für Aufrufe über index.php vorab den Status 404.
        rex_response::setStatus(rex_response::HTTP_OK);
        rex_response::sendCacheControl('public, max-age=86400');
        if (null !== $contact->photoMedia && is_file(rex_path::media($contact->photoMedia))) {
            rex_response::sendFile(rex_path::media($contact->photoMedia), (string) (mime_content_type(rex_path::media($contact->photoMedia)) ?: 'image/jpeg'));
            exit;
        }
        rex_response::sendContent((string) $contact->photoData, $contact->photoType ?? 'image/jpeg', null, $contact->etag);
        exit;
    }
}
