<?php

declare(strict_types=1);

namespace KLXM\Contacts\VCard;

use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\ContactList;
use KLXM\Contacts\Domain\Item;
use KLXM\Contacts\Domain\ItemKind;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

/**
 * Schreibt Kontakte und Listen als vCard 3.0 in der Form, die Apple Kontakte, iOS, Thunderbird und DAVx⁵ verstehen.
 */
final class Serializer
{
    public const string PRODID = '-//KLXM//contacts//DE';

    /** Fotos über dieser Größe bleiben im Backend, gehen aber nicht an Apps. */
    private const int MAX_PHOTO_BYTES = 1_500_000;

    public function contact(Contact $contact): string
    {
        $card = new VCard(['VERSION' => '3.0', 'PRODID' => self::PRODID, 'UID' => $contact->uid]);
        $card->add('N', [$contact->lastName, $contact->firstName, $contact->middleName, $contact->prefix, $contact->suffix]);
        $card->add('FN', $contact->displayName('first_last') ?: '?');
        if ('' !== $contact->nickname) {
            $card->add('NICKNAME', $contact->nickname);
        }
        if ('' !== $contact->organization || '' !== $contact->department) {
            $card->add('ORG', [$contact->organization, $contact->department]);
        }
        if ('' !== $contact->jobTitle) {
            $card->add('TITLE', $contact->jobTitle);
        }
        if ($contact->isCompany) {
            $card->add('X-ABShowAs', 'COMPANY');
        }
        if (null !== $contact->birthday) {
            // Apple kennt Geburtstage ohne Jahr nur mit dem Platzhalterjahr 1604.
            $contact->birthdayHasYear
                ? $card->add('BDAY', $contact->birthday, ['VALUE' => 'DATE'])
                : $card->add('BDAY', '1604' . substr($contact->birthday, 1), ['VALUE' => 'DATE', 'X-APPLE-OMIT-YEAR' => '1604']);
        }
        if (null !== $contact->note && '' !== $contact->note) {
            $card->add('NOTE', $contact->note);
        }

        $group = 0;
        foreach ($contact->items as $item) {
            $this->addItem($card, $item, $group);
        }
        $this->addPhoto($card, $contact);
        $this->addExtra($card, $contact->extraVcard);
        $card->add('REV', ($contact->updatedAt ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));

        return $card->serialize();
    }

    /**
     * Eine Liste ist in CardDAV eine Gruppenkarte mit den UIDs ihrer Mitglieder.
     *
     * @param list<string> $memberUids
     */
    public function contactList(ContactList $list, array $memberUids): string
    {
        $card = new VCard(['VERSION' => '3.0', 'PRODID' => self::PRODID, 'UID' => $list->uid]);
        $card->add('N', [$list->name, '', '', '', '']);
        $card->add('FN', $list->name);
        $card->add('X-ADDRESSBOOKSERVER-KIND', 'group');
        foreach (array_unique([...$memberUids, ...$list->pendingMembers]) as $uid) {
            $card->add('X-ADDRESSBOOKSERVER-MEMBER', 'urn:uuid:' . $uid);
        }
        $card->add('REV', ($list->updatedAt ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));

        return $card->serialize();
    }

    private function addItem(VCard $card, Item $item, int &$group): void
    {
        [$name, $value, $params] = match ($item->kind) {
            ItemKind::Phone => ['TEL', $item->value, []],
            ItemKind::Email => ['EMAIL', $item->value, []],
            ItemKind::Url => ['URL', $item->value, []],
            ItemKind::Address => ['ADR', ['', '', $item->data['street'] ?? '', $item->data['city'] ?? '', $item->data['region'] ?? '', $item->data['postal_code'] ?? '', $item->data['country'] ?? ''], []],
            ItemKind::Date => ['X-ABDATE', $item->value, ['VALUE' => 'DATE']],
            // Apple zeigt freie Textfelder mit eigener Beschriftung nur in dieser Eigenschaft an.
            ItemKind::Related, ItemKind::Custom => ['X-ABRELATEDNAMES', $item->value, []],
            ItemKind::Social => ['X-SOCIALPROFILE', $item->value, ['TYPE' => $item->label, 'X-USER' => $item->value]],
            ItemKind::Messenger => ['IMPP', 'x-apple:' . rawurlencode($item->value), ['X-SERVICE-TYPE' => $item->label]],
        };

        if (in_array($item->kind, [ItemKind::Social, ItemKind::Messenger], true)) {
            $card->add($name, $value, $params);

            return;
        }

        $types = Labels::types($item->kind, $item->label);
        if (null !== $types) {
            $card->add($name, $value, $params + ['TYPE' => $types]);

            return;
        }

        $prefix = 'item' . ++$group;
        $card->add($prefix . '.' . $name, $value, $params + (ItemKind::Email === $item->kind ? ['TYPE' => ['INTERNET']] : []));
        $card->add($prefix . '.X-ABLabel', Labels::appleLabel($item->kind, $item->label));
    }

    private function addPhoto(VCard $card, Contact $contact): void
    {
        $data = null;
        $type = null;
        if (null !== $contact->photoMedia && '' !== $contact->photoMedia && class_exists(\rex_path::class, false)) {
            $file = \rex_path::media($contact->photoMedia);
            if (is_file($file) && filesize($file) <= self::MAX_PHOTO_BYTES) {
                $data = (string) file_get_contents($file);
                $type = strtoupper(pathinfo($file, PATHINFO_EXTENSION));
            }
        } elseif (null !== $contact->photoData && '' !== $contact->photoData && strlen($contact->photoData) <= self::MAX_PHOTO_BYTES) {
            $data = $contact->photoData;
            $type = strtoupper((string) preg_replace('#^image/#', '', $contact->photoType ?? 'jpeg'));
        }
        if (null !== $data) {
            $card->add('PHOTO', $data, ['ENCODING' => 'b', 'TYPE' => 'JPG' === $type ? 'JPEG' : $type]);
        }
    }

    private function addExtra(VCard $card, ?string $extra): void
    {
        if (null === $extra || '' === trim($extra)) {
            return;
        }
        try {
            $source = Reader::read("BEGIN:VCARD\r\nVERSION:3.0\r\n" . trim($extra) . "\r\nEND:VCARD\r\n", Reader::OPTION_FORGIVING);
        } catch (\Throwable) {
            return;
        }
        foreach ($source->children() as $property) {
            if ('VERSION' !== $property->name) {
                $card->add(clone $property);
            }
        }
    }
}
