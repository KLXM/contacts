<?php

declare(strict_types=1);

namespace KLXM\Contacts\Domain;

/**
 * Ein beschrifteter Eintrag eines Kontakts, etwa "Mobil: 0171 …" oder das eigene Feld "Kundennummer: 4711".
 */
final class Item
{
    public const array ADDRESS_PARTS = ['street', 'postal_code', 'city', 'region', 'country'];

    /**
     * @param array<string, string> $data Bestandteile einer Adresse (ADDRESS_PARTS)
     */
    public function __construct(
        public ItemKind $kind,
        public string $label,
        public string $value,
        public array $data = [],
        /** Für das Frontend freigegeben? Wirkt nur, wenn auch der Kontakt öffentlich ist. */
        public bool $isPublic = false,
    ) {}

    public static function address(string $label, string $street = '', string $postalCode = '', string $city = '', string $region = '', string $country = ''): self
    {
        $item = new self(ItemKind::Address, $label, '', ['street' => $street, 'postal_code' => $postalCode, 'city' => $city, 'region' => $region, 'country' => $country]);
        $item->value = $item->addressText(', ');

        return $item;
    }

    public function labelText(): string
    {
        return $this->kind->labelText($this->label);
    }

    /** Adresse in Schreibweise "Straße / PLZ Ort / Region / Land". */
    public function addressText(string $separator = "\n"): string
    {
        $lines = [
            $this->data['street'] ?? '',
            trim(($this->data['postal_code'] ?? '') . ' ' . ($this->data['city'] ?? '')),
            $this->data['region'] ?? '',
            $this->data['country'] ?? '',
        ];

        return implode($separator, array_filter(array_map(trim(...), $lines), static fn (string $line): bool => '' !== $line));
    }

    public function isEmpty(): bool
    {
        return '' === trim($this->value) && '' === $this->addressText();
    }

    /** Adresse, unter der sich der Eintrag öffnen lässt: tel:, mailto:, Website oder Profil. */
    public function href(): ?string
    {
        $value = trim($this->value);

        return match (true) {
            '' === $value => null,
            ItemKind::Phone === $this->kind => 'tel:' . preg_replace('/[^0-9+]/', '', $value),
            ItemKind::Email === $this->kind => 'mailto:' . $value,
            ItemKind::Url === $this->kind, ItemKind::Social === $this->kind => preg_match('#^https?://#i', $value) ? $value : (ItemKind::Url === $this->kind ? 'https://' . $value : null),
            default => null,
        };
    }
}
