<?php

declare(strict_types=1);

namespace KLXM\Contacts\Domain;

use KLXM\Contacts\Settings;

final class Contact
{
    public ?int $id = null;
    public int $bookId = 0;
    public string $uid = '';
    public string $uri = '';
    public string $etag = '';
    public bool $isCompany = false;
    public string $prefix = '';
    public string $firstName = '';
    public string $middleName = '';
    public string $lastName = '';
    public string $suffix = '';
    public string $nickname = '';
    public string $organization = '';
    public string $department = '';
    public string $jobTitle = '';

    /** "YYYY-MM-DD" oder ohne Jahr "--MM-DD" */
    public ?string $birthday = null;
    public ?string $note = null;

    /** Foto aus dem Medienpool; hat Vorrang vor dem Foto aus einer App. */
    public ?string $photoMedia = null;
    public ?string $photoData = null;
    public ?string $photoType = null;

    /** Liegt ein Foto aus einer App in der Datenbank? Listen laden die Daten selbst nicht. */
    public bool $photoStored = false;

    /** Nur geladene Fotodaten schreibt save() zurück. Neue Kontakte gelten als geladen. */
    public bool $photoLoaded = true;

    /** Angaben aus Kontakte-Apps, die das Addon nicht kennt; sie gehen unverändert zurück. */
    public ?string $extraVcard = null;
    public ?\DateTimeImmutable $updatedAt = null;
    public ?string $createUser = null;

    /** Stammdaten, die sich einzeln für das Frontend freigeben lassen. Der Name gehört immer dazu. */
    public const array PUBLIC_FIELDS = ['photo', 'organization', 'birthday', 'note'];

    /** Im Frontend zeigen? Ohne diesen Schalter verlässt nichts das Backend. */
    public bool $isPublic = false;

    /** @var list<string> freigegebene Stammdaten aus PUBLIC_FIELDS */
    public array $publicFields = [];

    /** @var list<Item> */
    public array $items = [];

    /** @var list<int> */
    public array $listIds = [];

    public bool $hasPhoto {
        get => (null !== $this->photoMedia && '' !== $this->photoMedia) || $this->photoStored || (null !== $this->photoData && '' !== $this->photoData);
    }

    public function isFieldPublic(string $field): bool
    {
        return $this->isPublic && in_array($field, $this->publicFields, true) && !Settings::isFieldBlocked($field);
    }

    /**
     * Der Kontakt, wie ihn die Website sehen darf: nur der Name und ausdrücklich freigegebene Angaben.
     * Alles andere ist in der Kopie nicht enthalten. Null, wenn der Kontakt nicht öffentlich ist.
     */
    public function publicView(): ?self
    {
        if (!$this->isPublic) {
            return null;
        }
        $view = new self();
        foreach (['id', 'bookId', 'etag', 'isCompany', 'prefix', 'firstName', 'middleName', 'lastName', 'suffix', 'updatedAt', 'listIds', 'isPublic', 'publicFields'] as $property) {
            $view->{$property} = $this->{$property};
        }
        if ($this->isFieldPublic('organization') || $this->isCompany) {
            $view->organization = $this->organization;
        }
        if ($this->isFieldPublic('organization')) {
            $view->department = $this->department;
            $view->jobTitle = $this->jobTitle;
        }
        if ($this->isFieldPublic('birthday')) {
            $view->birthday = $this->birthday;
        }
        if ($this->isFieldPublic('note')) {
            $view->note = $this->note;
        }
        if ($this->isFieldPublic('photo')) {
            $view->photoMedia = $this->photoMedia;
            $view->photoStored = $this->photoStored;
            $view->photoType = $this->photoType;
        }
        $view->photoLoaded = false;
        $view->items = array_values(array_filter($this->items, static fn (Item $item): bool => $item->isPublic && !Settings::isItemBlocked($item)));

        return $view;
    }

    /** Name zur Anzeige: Firma bei Firmenkontakten, sonst der Personenname in der eingestellten Reihenfolge. */
    public function displayName(?string $order = null): string
    {
        $person = $this->personName($order);
        if ($this->isCompany && '' !== $this->organization) {
            return $this->organization;
        }

        return match (true) {
            '' !== $person => $person,
            '' !== $this->organization => $this->organization,
            '' !== $this->nickname => $this->nickname,
            default => $this->first(ItemKind::Email)->value ?? $this->first(ItemKind::Phone)->value ?? '',
        };
    }

    public function personName(?string $order = null): string
    {
        $order ??= Settings::nameOrder();
        $parts = 'last_first' === $order
            ? [trim($this->lastName . ('' !== $this->lastName && '' !== $this->firstName ? ',' : '')), $this->firstName, $this->middleName]
            : [$this->prefix, $this->firstName, $this->middleName, $this->lastName, $this->suffix];

        return trim((string) preg_replace('/\s+/', ' ', implode(' ', $parts)));
    }

    /** Zeile unter dem Namen: Position, Abteilung und Firma, soweit nicht schon der Name. */
    public function subtitle(): string
    {
        $parts = [$this->jobTitle, $this->department, $this->isCompany ? $this->personName() : $this->organization];

        return implode(' · ', array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    public function sortName(?string $sortBy = null): string
    {
        $sortBy ??= Settings::sortBy();
        if ($this->isCompany && '' !== $this->organization) {
            return mb_strtolower($this->organization);
        }
        $name = 'first_name' === $sortBy ? $this->firstName . ' ' . $this->lastName : $this->lastName . ' ' . $this->firstName;

        return mb_strtolower(trim($name) ?: $this->displayName());
    }

    /** Anfangsbuchstaben für das Bild ohne Foto. */
    public function initials(): string
    {
        $source = $this->isCompany || ('' === $this->firstName && '' === $this->lastName)
            ? [$this->displayName()]
            : [$this->firstName, $this->lastName];

        return mb_strtoupper(implode('', array_map(static fn (string $part): string => mb_substr(trim($part), 0, 1), $source)));
    }

    /**
     * @return list<Item>
     */
    public function items(ItemKind $kind): array
    {
        return array_values(array_filter($this->items, static fn (Item $item): bool => $item->kind === $kind));
    }

    public function first(ItemKind $kind): ?Item
    {
        return array_find($this->items, static fn (Item $item): bool => $item->kind === $kind);
    }

    /** Wert eines eigenen Feldes, etwa custom('Kundennummer'). */
    public function custom(string $label): ?string
    {
        return array_find($this->items, static fn (Item $item): bool => ItemKind::Custom === $item->kind && 0 === strcasecmp($item->label, $label))?->value;
    }

    /** Geburtstag als Datum; ohne Jahr gilt das übergebene. */
    public function birthdayDate(?int $fallbackYear = null): ?\DateTimeImmutable
    {
        if (null === $this->birthday || 1 !== preg_match('/^(\d{4}|-)-(\d{2})-(\d{2})$/', $this->birthday, $match)) {
            return null;
        }
        $year = '-' === $match[1] ? ($fallbackYear ?? (int) date('Y')) : (int) $match[1];

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, (int) $match[2], (int) $match[3]));
    }

    public bool $birthdayHasYear {
        get => null !== $this->birthday && !str_starts_with($this->birthday, '--');
    }
}
