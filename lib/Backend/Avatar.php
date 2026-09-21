<?php

declare(strict_types=1);

namespace KLXM\Contacts\Backend;

use KLXM\Contacts\Api\BackendApi;
use KLXM\Contacts\Domain\Contact;

/**
 * Rundes Kontaktbild: das Foto, sonst die Initialen auf einer Farbe, die sich aus dem Namen ergibt.
 *
 * @internal
 */
final class Avatar
{
    public static function render(Contact $contact, string $size = 'small'): string
    {
        $class = 'contacts-avatar contacts-avatar-' . $size;
        if ($contact->hasPhoto && null !== $contact->id) {
            return '<span class="' . $class . '"><img src="' . Html::e(BackendApi::url('photo', ['id' => $contact->id, 'v' => $contact->etag])) . '" alt="" loading="lazy" draggable="false"></span>';
        }
        $hue = crc32($contact->displayName()) % 360;
        $icon = $contact->isCompany ? '<i class="rex-icon fa-building" aria-hidden="true"></i>' : Html::e($contact->initials() ?: '?');

        return '<span class="' . $class . '" style="--contacts-hue:' . $hue . '" aria-hidden="true">' . $icon . '</span>';
    }
}
