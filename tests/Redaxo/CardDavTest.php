<?php

declare(strict_types=1);

namespace KLXM\Contacts\Tests\Redaxo;

use KLXM\Contacts\Contacts;
use KLXM\Contacts\Dav\AddressBookBackend;
use KLXM\Contacts\Domain\Book;
use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\ContactList;
use KLXM\Contacts\Domain\Item;
use KLXM\Contacts\Domain\ItemKind;
use KLXM\Contacts\Import\Exporter;
use KLXM\Contacts\Import\Importer;
use KLXM\Dav\Context;
use KLXM\Dav\Scope;
use KLXM\Dav\Token;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\Forbidden;

/**
 * Arbeitet in einem eigenen Adressbuch, das nach jedem Test samt Inhalt verschwindet.
 */
final class CardDavTest extends TestCase
{
    private Book $book;

    protected function setUp(): void
    {
        if (!class_exists(\rex::class, false)) {
            self::markTestSkipped('Braucht ein laufendes REDAXO (CONTACTS_REDAXO_BOOT).');
        }
        $this->book = new Book();
        $this->book->name = 'phpunit ' . bin2hex(random_bytes(4));
        Contacts::books()->save($this->book);
    }

    protected function tearDown(): void
    {
        if (!isset($this->book)) {
            return;
        }
        foreach (Contacts::contacts()->query([(int) $this->book->id]) as $contact) {
            Contacts::contacts()->delete($contact);
        }
        Contacts::books()->delete($this->book);
    }

    private function backend(bool $write = true): AddressBookBackend
    {
        $context = new Context();
        $context->user = \rex::requireUser();
        $reflection = new \ReflectionClass(Token::class);
        $token = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('scope')->setValue($token, $write ? Scope::ReadWrite : Scope::Read);
        $context->token = $token;

        return new AddressBookBackend($context);
    }

    private function contact(string $first, string $last): Contact
    {
        $contact = new Contact();
        $contact->bookId = (int) $this->book->id;
        $contact->firstName = $first;
        $contact->lastName = $last;
        $contact->items = [new Item(ItemKind::Email, 'work', strtolower($first) . '@example.org'), new Item(ItemKind::Custom, 'Kundennummer', '4711')];
        Contacts::contacts()->save($contact);

        return $contact;
    }

    #[Test]
    public function contactsLiveInSeveralListsAndSearchFindsItems(): void
    {
        $anna = $this->contact('Anna', 'Zeta');
        $ben = $this->contact('Ben', 'Alpha');
        foreach (['Vorstand' => [$anna, $ben], 'Presse' => [$anna]] as $name => $members) {
            $list = new ContactList();
            $list->bookId = (int) $this->book->id;
            $list->name = $name;
            $list->memberIds = array_map(static fn (Contact $contact): int => (int) $contact->id, $members);
            Contacts::lists()->save($list);
        }

        $lists = Contacts::lists()->forBook((int) $this->book->id);
        self::assertCount(2, Contacts::contacts()->find((int) $anna->id)->listIds ?? []);
        self::assertSame(['Alpha', 'Zeta'], array_map(static fn (Contact $contact): string => $contact->lastName, Contacts::contacts()->query([(int) $this->book->id])));
        $presse = array_find($lists, static fn (ContactList $list): bool => 'Presse' === $list->name);
        self::assertSame(1, Contacts::contacts()->count([(int) $this->book->id], $presse?->id));
        self::assertSame(1, Contacts::contacts()->count([(int) $this->book->id], null, 'ben@example'));
        self::assertSame('4711', Contacts::contacts()->find((int) $ben->id)?->custom('Kundennummer'));
    }

    #[Test]
    public function appCreatesContactThenGroupAndSyncReportsChanges(): void
    {
        $backend = $this->backend();
        $id = (int) $this->book->id;
        $token = $backend->getChangesForAddressBook($id, '', 1)['syncToken'];

        // Apple schickt Gruppen mitunter vor den Kontakten: Mitglied bleibt vorgemerkt.
        $backend->createCard($id, 'G1.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:G1\r\nN:Team;;;;\r\nFN:Team\r\nX-ADDRESSBOOKSERVER-KIND:group\r\nX-ADDRESSBOOKSERVER-MEMBER:urn:uuid:C1\r\nEND:VCARD\r\n");
        $backend->createCard($id, 'C1.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:C1\r\nN:Muster;Max;;;\r\nFN:Max Muster\r\nitem1.TEL:0211 1\r\nitem1.X-ABLabel:Zentrale\r\nEND:VCARD\r\n");

        $contact = Contacts::contacts()->findByUri($id, 'C1.vcf');
        self::assertSame('Zentrale', $contact?->first(ItemKind::Phone)?->label);
        $list = Contacts::lists()->findByUri($id, 'G1.vcf');
        self::assertSame([(int) $contact?->id], $list?->memberIds);
        self::assertSame([], $list?->pendingMembers);
        self::assertStringContainsString('X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:C1', (string) $backend->getCard($id, 'G1.vcf')['carddata']);

        $changes = $backend->getChangesForAddressBook($id, $token, 1);
        sort($changes['added']);
        self::assertSame(['C1.vcf', 'G1.vcf'], $changes['added']);

        $token = $changes['syncToken'];
        $backend->deleteCard($id, 'C1.vcf');
        $changes = $backend->getChangesForAddressBook($id, $token, 1);
        self::assertSame(['C1.vcf'], $changes['deleted']);
        self::assertSame(['G1.vcf'], $changes['modified']);
        Contacts::lists()->delete(Contacts::lists()->findByUri($id, 'G1.vcf'));
    }

    #[Test]
    public function readOnlyPasswordCannotWrite(): void
    {
        $this->expectException(Forbidden::class);
        $this->backend(false)->createCard((int) $this->book->id, 'X.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:X\r\nN:X;;;;\r\nEND:VCARD\r\n");
    }

    #[Test]
    public function hiddenBookIsNotOffered(): void
    {
        $this->book->davEnabled = false;
        Contacts::books()->save($this->book);
        $books = $this->backend()->getAddressBooksForUser('principals/' . \rex::requireUser()->getLogin());

        self::assertNotContains($this->book->slug, array_column($books, 'uri'));
    }

    #[Test]
    public function exportAndImportRoundTripUpdatesInsteadOfDuplicating(): void
    {
        $this->contact('Anna', 'Zeta');
        $data = new Exporter()->book($this->book);
        $result = new Importer()->import($data, $this->book);

        self::assertSame([0, 1], [$result->created, $result->updated]);
        self::assertSame(1, Contacts::contacts()->count([(int) $this->book->id]));
    }
}
