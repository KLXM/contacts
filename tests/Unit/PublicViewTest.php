<?php

declare(strict_types=1);

namespace KLXM\Contacts\Tests\Unit;

use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\Item;
use KLXM\Contacts\Domain\ItemKind;
use KLXM\Contacts\Frontend\Frontend;
use KLXM\Contacts\Settings;
use KLXM\Contacts\VCard\Parser;
use KLXM\Contacts\VCard\Serializer;
use PHPUnit\Framework\TestCase;

final class PublicViewTest extends TestCase
{
    protected function tearDown(): void
    {
        Settings::overridePublicBlocked(null);
    }

    private function contact(): Contact
    {
        $contact = new Contact();
        $contact->id = 7;
        $contact->uid = 'P1';
        $contact->firstName = 'Anna';
        $contact->lastName = 'Beispiel';
        $contact->organization = 'KLXM';
        $contact->jobTitle = 'Vorsitzende';
        $contact->nickname = 'Anni';
        $contact->birthday = '1980-05-12';
        $contact->note = 'intern: ruft ungern zurück';
        $contact->photoStored = true;
        $contact->extraVcard = "X-SECRET:1\r\n";
        $contact->items = [
            new Item(ItemKind::Phone, 'mobile', '0171 1', [], true),
            new Item(ItemKind::Phone, 'work', '02821 1', [], true),
            new Item(ItemKind::Email, 'work', 'anna@example.org', [], true),
            new Item(ItemKind::Email, 'home', 'privat@example.org'),
            new Item(ItemKind::Custom, 'Kundennummer', '4711'),
        ];

        return $contact;
    }

    public function testNothingLeavesWithoutTheSwitch(): void
    {
        self::assertNull($this->contact()->publicView());
    }

    public function testOnlyReleasedDataIsInTheView(): void
    {
        $contact = $this->contact();
        $contact->isPublic = true;
        $contact->publicFields = ['organization'];
        $view = $contact->publicView();

        self::assertNotNull($view);
        self::assertSame('Anna Beispiel', $view->displayName('first_last'));
        self::assertSame('Vorsitzende · KLXM', $view->subtitle());
        self::assertSame(['0171 1', '02821 1', 'anna@example.org'], array_map(static fn (Item $item): string => $item->value, $view->items));
        self::assertNull($view->note);
        self::assertNull($view->birthday);
        self::assertNull($view->extraVcard);
        self::assertSame('', $view->nickname . $view->uid);
        self::assertFalse($view->hasPhoto);
        self::assertNull($view->custom('Kundennummer'));
    }

    public function testGlobalBlocksBeatTheRelease(): void
    {
        $contact = $this->contact();
        $contact->isPublic = true;
        $contact->publicFields = ['photo', 'birthday', 'note'];
        Settings::overridePublicBlocked(['phone:mobile', 'birthday', 'email']);
        $view = $contact->publicView();

        self::assertSame(['02821 1'], array_map(static fn (Item $item): string => $item->value, $view?->items ?? []));
        self::assertNull($view?->birthday);
        self::assertSame('intern: ruft ungern zurück', $view?->note);
        self::assertTrue($view?->hasPhoto);
    }

    public function testModuleSelectionOnlyNarrows(): void
    {
        $contact = $this->contact();
        $contact->isPublic = true;
        $contact->publicFields = ['organization', 'note', 'photo'];
        $reduced = Frontend::reduce($contact->publicView() ?? new Contact(), ['email']);

        self::assertSame(['anna@example.org'], array_map(static fn (Item $item): string => $item->value, $reduced->items));
        self::assertSame('', $reduced->subtitle());
        self::assertNull($reduced->note);
        self::assertFalse($reduced->isFieldPublic('photo'));
    }

    public function testSyncFromAnAppKeepsReleases(): void
    {
        $contact = $this->contact();
        $contact->isPublic = true;
        $data = new Serializer()->contact($contact);
        $parser = new Parser();
        $parser->apply($parser->read($data)[0], $contact);

        self::assertTrue($contact->isPublic);
        $released = array_map(static fn (Item $item): string => $item->value, array_filter($contact->items, static fn (Item $item): bool => $item->isPublic));
        sort($released);
        self::assertSame(['0171 1', '02821 1', 'anna@example.org'], $released);
    }
}
