<?php

declare(strict_types=1);

namespace KLXM\Contacts\Tests\Unit;

use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\ContactList;
use KLXM\Contacts\Domain\Item;
use KLXM\Contacts\Domain\ItemKind;
use KLXM\Contacts\VCard\Parser;
use KLXM\Contacts\VCard\Serializer;
use PHPUnit\Framework\TestCase;

final class VCardTest extends TestCase
{
    private function sample(): Contact
    {
        $contact = new Contact();
        $contact->uid = 'ABC-123';
        $contact->prefix = 'Dr.';
        $contact->firstName = 'Anna';
        $contact->lastName = 'Beispiel';
        $contact->organization = 'KLXM';
        $contact->department = 'Web';
        $contact->jobTitle = 'Entwicklerin';
        $contact->birthday = '--05-12';
        $contact->note = "Zeile 1\nZeile 2; mit, Zeichen";
        $contact->items = [
            new Item(ItemKind::Phone, 'mobile', '+49 171 1234567'),
            new Item(ItemKind::Phone, 'Durchwahl Lager', '02821 123-45'),
            new Item(ItemKind::Email, 'work', 'anna@example.org'),
            new Item(ItemKind::Email, 'other', 'privat@example.org'),
            Item::address('work', 'Hauptstr. 1', '47533', 'Kleve', 'NRW', 'Deutschland'),
            new Item(ItemKind::Url, 'homepage', 'https://example.org'),
            new Item(ItemKind::Date, 'anniversary', '2015-06-20'),
            new Item(ItemKind::Related, 'assistant', 'Ben Beispiel'),
            new Item(ItemKind::Social, 'linkedin', 'https://www.linkedin.com/in/anna'),
            new Item(ItemKind::Messenger, 'signal', '+491711234567'),
            new Item(ItemKind::Custom, 'Kundennummer', '4711'),
        ];

        return $contact;
    }

    public function testRoundTripKeepsEverything(): void
    {
        $data = new Serializer()->contact($this->sample());
        $parser = new Parser();
        $restored = new Contact();
        $parser->apply($parser->read($data)[0], $restored);

        self::assertSame('ABC-123', $restored->uid);
        self::assertSame(['Dr.', 'Anna', 'Beispiel', 'KLXM', 'Web', 'Entwicklerin'], [$restored->prefix, $restored->firstName, $restored->lastName, $restored->organization, $restored->department, $restored->jobTitle]);
        self::assertSame('--05-12', $restored->birthday);
        self::assertSame("Zeile 1\nZeile 2; mit, Zeichen", $restored->note);
        // Die Reihenfolge zählt je Feldart; zwischen den Arten ordnet vCard selbst.
        $flat = static function (Contact $contact): array {
            $rows = [];
            foreach (ItemKind::cases() as $kind) {
                foreach ($contact->items($kind) as $item) {
                    $rows[] = [$item->kind->value, $item->label, $item->value];
                }
            }

            return $rows;
        };
        self::assertSame($flat($this->sample()), $flat($restored));
        self::assertSame('Kleve', $restored->first(ItemKind::Address)?->data['city']);
        self::assertSame('4711', $restored->custom('kundennummer'));
    }

    public function testAppleCardWithCustomLabelsAndUnknownProperties(): void
    {
        $data = "BEGIN:VCARD\r\nVERSION:3.0\r\nPRODID:-//Apple Inc.//macOS 14//EN\r\nN:Muster;Max;;;\r\nFN:Max Muster\r\nX-ABShowAs:COMPANY\r\nORG:Muster GmbH;\r\n"
            . "BDAY;VALUE=date:1980-02-29\r\nitem1.TEL;type=pref:0211 123\r\nitem1.X-ABLabel:Zentrale\r\nTEL;type=CELL;type=VOICE:0171 1\r\n"
            . "item2.EMAIL;type=INTERNET:max@example.org\r\nitem2.X-ABLabel:_\$!<Other>!\$_\r\nX-PHONETIC-FIRST-NAME:Maks\r\nitem3.X-FOO:bar\r\nitem3.X-ABLabel:Eigenes\r\n"
            . "UID:F1\r\nEND:VCARD\r\n";
        $parser = new Parser();
        $contact = new Contact();
        $parser->apply($parser->read($data)[0], $contact);

        self::assertTrue($contact->isCompany);
        self::assertSame('Muster GmbH', $contact->displayName());
        self::assertSame('1980-02-29', $contact->birthday);
        self::assertSame(['Zentrale', 'mobile'], array_map(static fn (Item $item): string => $item->label, $contact->items(ItemKind::Phone)));
        self::assertSame('other', $contact->first(ItemKind::Email)?->label);
        self::assertStringContainsString('X-PHONETIC-FIRST-NAME:Maks', (string) $contact->extraVcard);

        $again = new Serializer()->contact($contact);
        self::assertStringContainsString('X-PHONETIC-FIRST-NAME:Maks', $again);
        self::assertStringContainsString('X-FOO:bar', $again);
        self::assertStringContainsStringIgnoringCase('X-ABLabel:Eigenes', $again);
        self::assertStringContainsStringIgnoringCase('X-ABLabel:Zentrale', $again);
    }

    public function testListIsAGroupCard(): void
    {
        $list = new ContactList();
        $list->uid = 'L1';
        $list->name = 'Vorstand';
        $data = new Serializer()->contactList($list, ['A', 'B']);
        $parser = new Parser();
        $card = $parser->read($data)[0];

        self::assertTrue($parser->isList($card));
        self::assertSame(['name' => 'Vorstand', 'uid' => 'L1', 'members' => ['A', 'B']], $parser->listData($card));
    }

    public function testDisplayAndSortNames(): void
    {
        $contact = $this->sample();
        self::assertSame('Dr. Anna Beispiel', $contact->displayName('first_last'));
        self::assertSame('Beispiel, Anna', $contact->displayName('last_first'));
        self::assertSame('beispiel anna', $contact->sortName('last_name'));
        self::assertSame('AB', $contact->initials());
    }
}
