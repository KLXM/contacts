<?php

declare(strict_types=1);

namespace KLXM\Contacts;

use rex_addon;

final class Settings
{
    /** @var list<string>|null */
    private static ?array $blockedOverride = null;

    public static function nameOrder(): string
    {
        return 'last_first' === self::get('name_order') ? 'last_first' : 'first_last';
    }

    public static function sortBy(): string
    {
        return 'first_name' === self::get('sort_by') ? 'first_name' : 'last_name';
    }

    /**
     * Eigene Felder, die allen Kontakten im Menü "Feld hinzufügen" angeboten werden.
     *
     * @return list<string>
     */
    public static function customFields(): array
    {
        return self::lines('custom_fields');
    }

    /**
     * Zusätzliche Beschriftungen für Telefon, E-Mail und die anderen Mehrfachfelder.
     *
     * @return list<string>
     */
    public static function customLabels(): array
    {
        return self::lines('custom_labels');
    }

    /**
     * Was nie auf der Website erscheint, egal wie der einzelne Kontakt freigegeben ist.
     * Einträge: Stammdaten ("birthday"), ganze Feldarten ("date") oder Feldart mit Beschriftung ("phone:mobile").
     *
     * @return list<string>
     */
    public static function publicBlocked(): array
    {
        return self::$blockedOverride ?? array_values(array_filter(explode(',', self::get('public_blocked'))));
    }

    /**
     * Für Tests und Sonderfälle: Sperren ohne Konfiguration setzen, null stellt die Einstellung wieder her.
     *
     * @param list<string>|null $blocked
     */
    public static function overridePublicBlocked(?array $blocked): void
    {
        self::$blockedOverride = $blocked;
    }

    public static function isFieldBlocked(string $field): bool
    {
        return in_array($field, self::publicBlocked(), true);
    }

    public static function isItemBlocked(Domain\Item $item): bool
    {
        $blocked = self::publicBlocked();

        return in_array($item->kind->value, $blocked, true) || in_array($item->kind->value . ':' . $item->label, $blocked, true);
    }

    /**
     * @return list<string>
     */
    private static function lines(string $key): array
    {
        $lines = preg_split('/\R/', self::get($key)) ?: [];

        return array_values(array_unique(array_filter(array_map(trim(...), $lines), static fn (string $line): bool => '' !== $line)));
    }

    private static function get(string $key): string
    {
        return class_exists(rex_addon::class, false) ? (string) rex_addon::get('contacts')->getConfig($key, '') : '';
    }
}
