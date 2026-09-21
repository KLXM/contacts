<?php

declare(strict_types=1);

namespace KLXM\Contacts\VCard;

use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\Item;
use KLXM\Contacts\Domain\ItemKind;
use KLXM\Contacts\I18n;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;
use Sabre\VObject\Splitter\VCard as VCardSplitter;

/**
 * Liest vCards aus Apps und Dateien. Angaben, die das Addon nicht kennt, wandern unverändert in
 * Contact::$extraVcard und gehen beim nächsten Abgleich zurück.
 */
final class Parser
{
    /** Eigenschaften, die das Addon selbst abbildet oder neu erzeugt */
    private const array KNOWN = [
        'VERSION', 'PRODID', 'UID', 'N', 'FN', 'NICKNAME', 'ORG', 'TITLE', 'BDAY', 'NOTE', 'REV', 'PHOTO', 'X-ABSHOWAS', 'KIND',
        'TEL', 'EMAIL', 'ADR', 'URL', 'IMPP', 'X-SOCIALPROFILE', 'X-ABDATE', 'X-ABRELATEDNAMES', 'X-ABLABEL',
        'X-ADDRESSBOOKSERVER-KIND', 'X-ADDRESSBOOKSERVER-MEMBER',
    ];

    /**
     * Alle Karten einer Datei oder Anfrage.
     *
     * @return list<VCard>
     */
    public function read(string $data): array
    {
        $cards = [];
        try {
            $stream = fopen('php://temp', 'r+') ?: throw new \RuntimeException('php://temp');
            fwrite($stream, $data);
            rewind($stream);
            $splitter = new VCardSplitter($stream, \Sabre\VObject\Reader::OPTION_FORGIVING);
            while (($card = $splitter->getNext()) instanceof VCard) {
                $cards[] = $card;
            }
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(I18n::t('vcard_unreadable', $e->getMessage()), 0, $e);
        }
        if ([] === $cards) {
            throw new \InvalidArgumentException(I18n::t('vcard_empty'));
        }

        return $cards;
    }

    public function isList(VCard $card): bool
    {
        return 'group' === strtolower((string) ($card->{'X-ADDRESSBOOKSERVER-KIND'} ?? $card->KIND ?? ''));
    }

    /**
     * @return array{name: string, uid: string, members: list<string>}
     */
    public function listData(VCard $card): array
    {
        $members = [];
        foreach ([...$card->select('X-ADDRESSBOOKSERVER-MEMBER'), ...$card->select('MEMBER')] as $member) {
            $members[] = (string) preg_replace('/^urn:uuid:/i', '', trim((string) $member));
        }

        return ['name' => trim((string) ($card->FN ?? $card->N ?? '')), 'uid' => trim((string) ($card->UID ?? '')), 'members' => array_values(array_unique(array_filter($members)))];
    }

    /** Überträgt die Karte auf den Kontakt. Adressbuch, Listen und ein Foto aus dem Medienpool bleiben unberührt. */
    public function apply(VCard $card, Contact $contact): void
    {
        $name = isset($card->N) ? $card->N->getParts() + ['', '', '', '', ''] : ['', '', '', '', ''];
        [$contact->lastName, $contact->firstName, $contact->middleName, $contact->prefix, $contact->suffix] = array_map(static fn (mixed $part): string => trim((string) $part), array_slice($name, 0, 5));
        $org = isset($card->ORG) ? $card->ORG->getParts() + ['', ''] : ['', ''];
        $contact->organization = trim((string) $org[0]);
        $contact->department = trim((string) $org[1]);
        $contact->nickname = trim((string) ($card->NICKNAME ?? ''));
        $contact->jobTitle = trim((string) ($card->TITLE ?? ''));
        $contact->note = isset($card->NOTE) ? (string) $card->NOTE : null;
        $contact->isCompany = 'COMPANY' === strtoupper((string) ($card->{'X-ABSHOWAS'} ?? '')) || 'org' === strtolower((string) ($card->KIND ?? ''));
        if ('' === $contact->uid && isset($card->UID)) {
            $contact->uid = trim((string) $card->UID);
        }
        if ('' === $contact->firstName . $contact->lastName . $contact->organization && isset($card->FN)) {
            $contact->lastName = trim((string) $card->FN);
        }
        $contact->birthday = $this->birthday($card);

        $labels = [];
        foreach ($card->select('X-ABLABEL') as $label) {
            $labels[strtolower((string) $label->group)] = (string) $label;
        }
        // Die Freigabe für das Frontend kennt nur das Backend. Einträge, die es schon gab, behalten sie.
        $released = [];
        foreach ($contact->items as $previous) {
            if ($previous->isPublic) {
                $released[self::itemKey($previous)] = true;
            }
        }
        $contact->items = [];
        foreach ($card->children() as $property) {
            $item = $property instanceof Property ? $this->item($property, $labels[strtolower((string) $property->group)] ?? null) : null;
            if (null !== $item && !$item->isEmpty()) {
                $item->isPublic = isset($released[self::itemKey($item)]);
                $contact->items[] = $item;
            }
        }

        $this->photo($card, $contact);
        $contact->extraVcard = $this->extra($card, $labels);
    }

    private static function itemKey(Item $item): string
    {
        return $item->kind->value . '|' . mb_strtolower((string) preg_replace('/\s+/', '', ItemKind::Address === $item->kind ? $item->addressText(' ') : $item->value));
    }

    private function item(Property $property, ?string $abLabel): ?Item
    {
        $kind = match ($property->name) {
            'TEL' => ItemKind::Phone,
            'EMAIL' => ItemKind::Email,
            'ADR' => ItemKind::Address,
            'URL' => ItemKind::Url,
            'X-ABDATE' => ItemKind::Date,
            'X-ABRELATEDNAMES' => ItemKind::Related,
            'X-SOCIALPROFILE' => ItemKind::Social,
            'IMPP' => ItemKind::Messenger,
            default => null,
        };
        if (null === $kind) {
            return null;
        }
        $type = $property['TYPE'];
        $types = $type instanceof Parameter ? array_map(strval(...), $type->getParts()) : [];

        if (ItemKind::Address === $kind) {
            $parts = array_map(static fn (mixed $part): string => trim(is_array($part) ? implode(' ', $part) : (string) $part), $property->getParts()) + array_fill(0, 7, '');

            return Item::address(Labels::fromVcard($kind, $types, $abLabel), trim($parts[1] . ' ' . $parts[2]), $parts[5], $parts[3], $parts[4], $parts[6]);
        }
        if (ItemKind::Social === $kind) {
            $service = strtolower(array_find($types, static fn (string $type): bool => 'PREF' !== strtoupper($type)) ?? '');

            return new Item($kind, $service, trim((string) $property) ?: trim((string) ($property['X-USER'] ?? '')));
        }
        if (ItemKind::Messenger === $kind) {
            $value = rawurldecode((string) preg_replace('/^[a-z][a-z0-9+.-]*:/i', '', trim((string) $property)));
            $service = (string) ($property['X-SERVICE-TYPE'] ?? (strstr((string) $property, ':', true) ?: ''));

            return new Item($kind, strtolower($service), $value);
        }

        $label = Labels::fromVcard($kind, $types, $abLabel);
        // Freitext mit einer Beschriftung, die keine Beziehung ist, ist ein eigenes Feld.
        if (ItemKind::Related === $kind && !in_array($label, $kind->labels(), true)) {
            $kind = ItemKind::Custom;
        }
        $value = trim((string) $property);
        if (ItemKind::Date === $kind) {
            $value = 1 === preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $value, $match) ? $match[1] . '-' . $match[2] . '-' . $match[3] : '';
        }

        return new Item($kind, $label, $value);
    }

    private function birthday(VCard $card): ?string
    {
        if (!isset($card->BDAY) || 1 !== preg_match('/^(\d{4}|--)-?(\d{2})-?(\d{2})/', trim((string) $card->BDAY), $match)) {
            return null;
        }
        $omitYear = '--' === $match[1] || isset($card->BDAY['X-APPLE-OMIT-YEAR']) && (string) $card->BDAY['X-APPLE-OMIT-YEAR'] === $match[1];

        return ($omitYear ? '-' : $match[1]) . '-' . $match[2] . '-' . $match[3];
    }

    private function photo(VCard $card, Contact $contact): void
    {
        $contact->photoLoaded = true;
        if (!isset($card->PHOTO)) {
            $contact->photoData = null;
            $contact->photoType = null;
            $contact->photoStored = false;

            return;
        }
        $raw = (string) $card->PHOTO->getValue();
        $type = strtolower((string) ($card->PHOTO['TYPE'] ?? 'jpeg'));
        if (1 === preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $raw, $match)) {
            $raw = (string) base64_decode($match[2], true);
            $type = strtolower($match[1]);
        } elseif (1 === preg_match('#^https?://#i', $raw)) {
            return;
        }
        if ('' === $raw || false === @getimagesizefromstring($raw)) {
            return;
        }
        $contact->photoData = $raw;
        $contact->photoType = str_contains($type, '/') ? $type : 'image/' . ('jpg' === $type ? 'jpeg' : $type);
        $contact->photoStored = true;
        // Ein neues Foto aus der App ersetzt das Bild aus dem Medienpool.
        $contact->photoMedia = null;
    }

    /**
     * @param array<string, string> $labels X-ABLabel je Gruppe
     */
    private function extra(VCard $card, array $labels): ?string
    {
        $lines = '';
        $counter = 0;
        foreach ($card->children() as $property) {
            if (!$property instanceof Property || in_array($property->name, self::KNOWN, true)) {
                continue;
            }
            $copy = clone $property;
            if (null !== $copy->group && '' !== $copy->group) {
                $label = $labels[strtolower($copy->group)] ?? null;
                $copy->group = 'ext' . ++$counter;
                $lines .= $copy->serialize();
                if (null !== $label) {
                    $lines .= $copy->group . '.X-ABLabel:' . addcslashes($label, ",;\\") . "\r\n";
                }
            } else {
                $lines .= $copy->serialize();
            }
        }

        return '' === $lines ? null : $lines;
    }
}
