# contacts

Kontakte für REDAXO, aufgebaut wie eine Kontakte-App: Adressbücher, Listen, beschriftete Mehrfachfelder, eigene Felder und Fotos. Über CardDAV erscheinen die Adressbücher direkt in Apple Kontakte, auf iPhone und iPad, in Thunderbird und auf Android.

- **Adressbücher** trennen Bestände, etwa „Verein“ und „Presse“. Rollen legen fest, wer welches pflegt.
- **Listen** ordnen Kontakte innerhalb eines Adressbuchs. Ein Kontakt kann in beliebig vielen Listen stehen. In Apps erscheinen Listen als Gruppen.
- **Mehrfachfelder** für Telefon, E-Mail, Adresse, Website, Datum, zugehörige Personen, soziale Profile und Messenger. Jeder Eintrag trägt eine Beschriftung: eine vorgegebene („Mobil“, „Arbeit“) oder eine eigene („Geschäftsstelle“).
- **Eigene Felder** wie „Kundennummer“ oder „Mitglied seit“: spontan je Kontakt oder als Vorgabe für alle.
- **Fotos** aus dem Medienpool oder aus der App. Sie gleichen sich in beide Richtungen ab.
- **vCard** zum Importieren und Exportieren, auch ganzer Adressbücher.

## Voraussetzungen

- REDAXO ab 5.18, PHP ab 8.4
- Addon `dav`. Es stellt den DAV-Server, die Anmeldung, die App-Passwörter und die sabre-Bibliotheken. contacts bringt selbst keine Laufzeit-Abhängigkeiten mit.
- Optional `a11y_datetime_addon` für den Datumswähler.

## Installation aus dem Repository

Zuerst das Addon [dav](https://github.com/KLXM/dav) installieren, dann contacts nach `redaxo/src/addons/contacts` klonen. contacts hat keine eigenen Laufzeit-Abhängigkeiten, ein `composer install` ist nur für die Tests nötig.

## Erste Schritte

1. Addon installieren.
2. Unter *Kontakte › Adressbücher* ein Adressbuch anlegen.
3. Den Rollen das Recht **Kontakte: Kontakte pflegen** (`contacts[]`) geben und unter *Adressbücher* auswählen, welche sie pflegen dürfen. **Kontakte: Adressbücher verwalten** (`contacts[books]`) erlaubt zusätzlich das Anlegen von Adressbüchern und den Zugriff auf alle.
4. Unter *Kontakte* Listen in der Seitenleiste anlegen und Kontakte erfassen.

## Kontakte pflegen

Die Seite *Kontakte* hat drei Spalten: links Adressbücher und Listen, in der Mitte die Kontakte mit Suche, rechts die Karte.

| Aufgabe | Weg |
|---|---|
| Kontakt anlegen | grüner Plus-Knopf neben der Suche. Steht links eine Liste, landet der Kontakt gleich darin. |
| Feld ergänzen | im Editor *Feld hinzufügen*: Telefon, E-Mail, Adresse, Website, Datum, zugehörige Person, soziales Profil, Messenger, Geburtstag, Notiz, eigenes Feld |
| Eigene Beschriftung | in der Auswahl vor dem Feld *Eigene Beschriftung …* wählen und Text eingeben |
| Eigenes Feld | *Feld hinzufügen › Eigenes Feld …*, dann Feldname und Wert. Vorgaben aus den Einstellungen stehen direkt im Menü. |
| Firma | *Als Firma anzeigen*: Die Firma wird zum Anzeigenamen und bestimmt die Sortierung. |
| Geburtstag ohne Jahr | Jahr einfach leer lassen |
| Listen zuordnen | Kontakt mit der Maus auf eine Liste in der Seitenleiste ziehen. Sind mehrere angehakt, wandern alle gemeinsam. Ohne Maus: im Editor unter *Adressbuch und Listen*, oder Kontakte anhaken und *Zur Liste hinzufügen*. Listen nehmen nur Kontakte aus ihrem eigenen Adressbuch an. |
| Liste umbenennen, exportieren, löschen | Liste wählen, dann das Menü mit den drei Punkten. Beim Löschen bleiben die Kontakte erhalten. |
| Suchen | findet Namen, Firma, Notiz und alle Feldwerte, etwa Telefonnummern oder die Kundennummer |

Die Suche arbeitet im gewählten Adressbuch oder in der gewählten Liste, unter *Alle Kontakte* über alles, was du pflegen darfst.

## Website: was öffentlich ist

Kontaktdaten sind personenbezogen. Deshalb gilt: Ohne ausdrückliche Freigabe verlässt nichts das Backend. Drei Ebenen greifen ineinander, jede kann nur einschränken.

| Ebene | Wo | Wirkung |
|---|---|---|
| Kontakt | Editor, Abschnitt *Website*: **Im Frontend zeigen** | Ohne diesen Schalter erscheint der Kontakt nirgends. Mit ihm ist zunächst nur der Name öffentlich. |
| Angabe | Weltkugel-Knopf an jedem Eintrag, Häkchen für Foto, Firma und Position, Geburtstag, Notiz | gibt genau diese Angabe frei |
| Global | *Einstellungen › Nie auf der Website zeigen* | sperrt Stammdaten, ganze Feldarten oder einzelne Beschriftungen für alle Kontakte, etwa „Telefon: nur Mobil“. Geht jeder Freigabe vor. |

In der Kontaktliste und auf der Karte markiert eine grüne Weltkugel, was öffentlich ist. Die Freigabe kennt nur das Backend: Kontakte-Apps sehen sie nicht, und ein Abgleich per CardDAV setzt sie nicht zurück. Ändert sich in der App die Nummer selbst, gilt der Eintrag als neu und ist zunächst nicht freigegeben.

### Ausgabe ohne Code

Unter *Kontakte › Module* lässt sich das Modul **contacts: Kontaktliste** installieren. Im Artikel wählt die Redaktion:

| Auswahl | Möglichkeiten |
|---|---|
| Kontakte aus | alle Adressbücher, ein Adressbuch oder eine Liste |
| Darstellung | Karten nebeneinander, Liste untereinander, Tabelle |
| Anzeigen | Foto, Firma und Position, Telefon, E-Mail, Adresse, Website, weitere Angaben, Notiz |

Die Auswahl im Modul schränkt nur weiter ein. Was am Kontakt nicht freigegeben oder global gesperrt ist, erscheint nie. Typisch: eine Liste „Vorstand“ als Karten mit Foto und E-Mail, dieselbe Liste an anderer Stelle als Tabelle mit Telefon.

### Ausgabe im eigenen Template

```php
use KLXM\Contacts\Contacts;
use KLXM\Contacts\Frontend\Frontend;

$people = Contacts::published(book: 'verein', list: 'Vorstand');   // nur öffentliche Kontakte, nur freigegebene Angaben
echo Frontend::renderList($people, ['layout' => 'cards', 'show' => ['photo', 'organization', 'email']]);

// oder ganz frei:
foreach ($people as $person) {
    echo $person->displayName();
    $photo = Frontend::photoUrl($person);          // null, wenn das Foto nicht freigegeben ist
    foreach ($person->items as $item) { … }        // enthält nur freigegebene Einträge
}
```

`Contacts::published()` liefert eine bereinigte Kopie jedes Kontakts. Nicht freigegebene Daten stecken darin nicht, ein Template kann sie also nicht versehentlich ausgeben. Auch die Suche (`search:`) durchsucht nur Freigegebenes. Das Fragment `fragments/contacts/list.php` lässt sich im Projekt überschreiben. Wer dagegen `Contacts::contacts()->query()` im Frontend nutzt, bekommt die vollständigen Daten und ist selbst verantwortlich.

## Einstellungen

| Einstellung | Wirkung |
|---|---|
| Namen anzeigen als | „Vorname Nachname“ oder „Nachname, Vorname“ |
| Sortieren nach | Nachname oder Vorname. Firmenkontakte sortieren nach der Firma. |
| Eigene Felder | ein Feldname je Zeile. Erscheinen bei jedem Kontakt im Menü *Feld hinzufügen*. |
| Eigene Beschriftungen | eine je Zeile. Stehen bei allen Mehrfachfeldern zusätzlich zur Wahl. |
| Nie auf der Website zeigen | globale Sperren, siehe oben |

## Kontakte-Apps (CardDAV)

1. Beim Adressbuch *In Kontakte-Apps anbieten* eingeschaltet lassen. Ausschalten für Bestände, die nur im Backend leben sollen.
2. *Kontakte › Kontakte-Apps* öffnen. Die Seite zeigt, welche Adressbücher dein Konto in der App sieht, und führt in drei Schritten zum App-Passwort und zu den Zugangsdaten.
3. In der App ein CardDAV-Konto anlegen:

| Angabe | Wert |
|---|---|
| Server | `https://example.org/dav/` |
| Benutzername | der REDAXO-Login |
| Passwort | das App-Passwort, nicht das REDAXO-Passwort |

| App | Weg |
|---|---|
| iPhone, iPad | Einstellungen › Kontakte › Accounts › Account hinzufügen › Andere › CardDAV-Account |
| Apple Kontakte (Mac) | Einstellungen › Accounts › Anderer Kontakte-Account › CardDAV, Accounttyp „Manuell“ |
| Thunderbird | Adressbuch › Neues Adressbuch › CardDAV-Adressbuch hinzufügen |
| Android | DAVx⁵ mit „URL und Benutzername“ |

### Was abgeglichen wird

| Inhalt | Verhalten |
|---|---|
| Namen, Firma, Abteilung, Position, Spitzname, Geburtstag (auch ohne Jahr), Notiz | vollständig in beide Richtungen |
| Telefon, E-Mail, Adresse, Website, Datum, zugehörige Personen, Profile, Messenger | mit Beschriftung, auch mit eigener |
| Eigene Felder | reisen als beschriftetes Textfeld. Apple zeigt sie unter „Zugehöriger Name“ mit dem Feldnamen als Beschriftung an und lässt sie dort bearbeiten. |
| Listen | erscheinen als Gruppen. Mitgliedschaften lassen sich in der App ändern. |
| Fotos | aus der App ins Backend und zurück. Ein Bild aus dem Medienpool hat Vorrang. Ein neues Foto aus der App ersetzt es. Bilder über 1,5 MB gehen nicht an Apps. |
| Angaben, die contacts nicht kennt (etwa phonetische Namen) | bleiben erhalten und gehen unverändert zurück |

Adressbücher lassen sich über CardDAV weder anlegen noch löschen oder umbenennen, das gehört dem Backend. Es gelten dieselben Rechte wie im Backend. Ein App-Passwort „nur lesen“ verhindert jede Änderung.

### Besonderheiten der Apps

| App | Hinweis |
|---|---|
| Apple Kontakte auf dem Mac | zeigt je Account nur **ein** Adressbuch, das erste. Für den Mac eignet sich ein Adressbuch mit mehreren Listen. iPhone, iPad, Thunderbird und DAVx⁵ zeigen alle Adressbücher. |
| iPhone, iPad | zeigen Listen als Gruppen. Gruppen anlegen geht ab iOS 16 auch in der App. |
| Thunderbird | kennt keine Gruppen über CardDAV. Die Kontakte erscheinen vollständig, Listen nicht. |
| Alle | Apple verlangt HTTPS mit gültigem Zertifikat. |

Fehlersuche zur Verbindung (404 unter `/dav/`, 401 trotz richtigem Passwort) steht in der Hilfe des Addons `dav`.

## Import und Export

*Kontakte › Import und Export* liest vCard-Dateien (`.vcf`) mit beliebig vielen Karten, etwa den Export aus Apple Kontakte, Google oder Outlook. Kontakte mit bekannter UID werden aktualisiert statt verdoppelt, Gruppenkarten werden zu Listen. Der Export liefert ein ganzes Adressbuch oder eine Liste als eine Datei, inklusive Fotos.

## Für Entwickler

Namespace `KLXM\Contacts`, Einstieg über die Fassade `Contacts`.

```php
use KLXM\Contacts\Contacts;
use KLXM\Contacts\Domain\ItemKind;

$book = Contacts::book('verein');                      // per Slug oder ID
$lists = Contacts::lists()->forBook($book->id);        // array<int, ContactList>

// Kontakte einer Liste, nach Name sortiert
foreach (Contacts::contacts()->query(listId: 3) as $contact) {
    echo $contact->displayName();                      // "Anna Beispiel" oder die Firma
    echo $contact->first(ItemKind::Email)?->value;     // erste E-Mail-Adresse
    echo $contact->custom('Kundennummer');             // eigenes Feld
    foreach ($contact->items(ItemKind::Phone) as $phone) {
        echo $phone->labelText(), ': ', $phone->value; // "Mobil: +49 …"
    }
}

// Suchen und blättern
$found = Contacts::contacts()->query(bookIds: [$book->id], search: 'kleve', limit: 20, offset: 0);
$total = Contacts::contacts()->count(bookIds: [$book->id], search: 'kleve');
```

### Schreiben

Es gibt einen Schreibweg: `ContactRepository::save()`. Er prüft, vergibt UID und Version, pflegt die Listen und hält die Änderung für den CardDAV-Abgleich fest. Backend, Import und CardDAV nutzen alle diesen Weg.

```php
use KLXM\Contacts\Domain\Contact;
use KLXM\Contacts\Domain\Item;

$contact = new Contact();
$contact->bookId = $book->id;
$contact->firstName = 'Anna';
$contact->lastName = 'Beispiel';
$contact->items = [
    new Item(ItemKind::Phone, 'mobile', '+49 171 1234567'),
    new Item(ItemKind::Email, 'Geschäftsstelle', 'anna@example.org'),   // eigene Beschriftung
    Item::address('work', 'Hauptstr. 1', '47533', 'Kleve', country: 'Deutschland'),
    new Item(ItemKind::Custom, 'Kundennummer', '4711'),                 // eigenes Feld
];
$contact->listIds = [3, 5];
Contacts::contacts()->save($contact);
```

### Klassen

| Klasse | Aufgabe |
|---|---|
| `Contacts` | Fassade: `books()`, `lists()`, `contacts()`, `book()`, `contact()` |
| `Domain\Contact` | `displayName()`, `personName()`, `subtitle()`, `sortName()`, `initials()`, `items(ItemKind)`, `first(ItemKind)`, `custom(string)`, `birthdayDate()`, Eigenschaften `hasPhoto`, `birthdayHasYear` |
| `Domain\Item` | `kind`, `label`, `value`, `data` (Adressbestandteile), `labelText()`, `addressText()`, `href()` |
| `Domain\ItemKind` | `Phone`, `Email`, `Address`, `Url`, `Date`, `Related`, `Social`, `Messenger`, `Custom`, jeweils mit `labels()` |
| `Domain\Book`, `Domain\ContactList` | Adressbuch und Liste |
| `Repository\ContactRepository` | `find()`, `findByUid()`, `query()`, `count()`, `save()`, `delete()` |
| `Repository\ListRepository` | `forBook()`, `find()`, `save()`, `delete()`, `setListsOfContact()` |
| `Repository\BookRepository` | `all()`, `find()`, `findBySlug()`, `save()`, `delete()`, `counts()` |
| `VCard\Serializer`, `VCard\Parser` | vCard 3.0 schreiben und lesen |
| `Import\Importer`, `Import\Exporter` | vCard-Dateien |
| `Frontend\Frontend` | `renderList()`, `photoUrl()`, `reduce()`; Konstanten `LAYOUTS`, `SHOW_OPTIONS` |
| `Settings` | `publicBlocked()`, `isItemBlocked()`, `isFieldBlocked()`, eigene Felder und Beschriftungen |
| `Security\Access` | `canUse()`, `canEditBook()`, `books()`, `davBooks()` |
| `Dav\AddressBookProvider`, `Dav\AddressBookBackend` | Anbindung an das Addon `dav` |

`query()` lädt keine Fotodaten. Das vollständige Objekt mit Foto liefert `find()`. Im Backend kommt das Bild über `index.php?rex-api-call=contacts&action=photo&id=…`.

### Tabellen

| Tabelle | Inhalt |
|---|---|
| `rex_contacts_book` | Adressbücher mit Sync-Token |
| `rex_contacts_contact` | Kontakte mit Namensfeldern, Foto, UID, Version und unbekannten vCard-Angaben |
| `rex_contacts_item` | Mehrfachfelder und eigene Felder: Art, Beschriftung, Wert |
| `rex_contacts_list`, `rex_contacts_list_member` | Listen und ihre Mitglieder |
| `rex_contacts_change` | Änderungsprotokoll für den CardDAV-Abgleich |

### Sprachen

Alle Texte stehen in `lang/de_de.lang` und `lang/en_gb.lang` mit dem Präfix `contacts_`. Im Code: `I18n::t('key')`, für HTML `I18n::e('key')`. Texte der Web Components tragen das Präfix `contacts_js_`.

### Tests

```bash
vendor/bin/phpunit --testsuite unit
CONTACTS_REDAXO_BOOT=/pfad/zur/boot-datei.php vendor/bin/phpunit
```

Die REDAXO-Tests arbeiten in einem eigenen Adressbuch, das sie danach wieder löschen.

## Lizenz

MIT, siehe [LICENSE](LICENSE). Technische Grundlage für vCard und CardDAV ist [sabre/dav](https://sabre.io/).
