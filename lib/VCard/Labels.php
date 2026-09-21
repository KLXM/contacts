<?php

declare(strict_types=1);

namespace KLXM\Contacts\VCard;

use KLXM\Contacts\Domain\ItemKind;

/**
 * Übersetzt Beschriftungen zwischen dem Addon und vCard. Standardbeschriftungen reisen als TYPE-Parameter,
 * alles andere als Apple-Beschriftung (itemN.X-ABLabel), die auch andere Apps verstehen.
 *
 * @internal
 */
final class Labels
{
    /** TYPE-Parameter je Beschriftung */
    private const array TYPES = [
        'phone' => [
            'mobile' => ['CELL', 'VOICE'], 'home' => ['HOME', 'VOICE'], 'work' => ['WORK', 'VOICE'], 'main' => ['MAIN'],
            'iphone' => ['IPHONE', 'CELL', 'VOICE'], 'fax_home' => ['HOME', 'FAX'], 'fax_work' => ['WORK', 'FAX'], 'pager' => ['PAGER'], 'other' => ['OTHER', 'VOICE'],
        ],
        'email' => ['home' => ['INTERNET', 'HOME'], 'work' => ['INTERNET', 'WORK']],
        'address' => ['home' => ['HOME'], 'work' => ['WORK']],
        'url' => ['home' => ['HOME'], 'work' => ['WORK']],
        'im' => [],
    ];

    /** Apples eingebaute Beschriftungen in der Schreibweise _$!<Name>!$_ */
    private const array APPLE = [
        'other' => 'Other', 'homepage' => 'HomePage', 'anniversary' => 'Anniversary', 'spouse' => 'Spouse', 'partner' => 'Partner',
        'child' => 'Child', 'parent' => 'Parent', 'mother' => 'Mother', 'father' => 'Father', 'brother' => 'Brother', 'sister' => 'Sister',
        'friend' => 'Friend', 'assistant' => 'Assistant', 'manager' => 'Manager', 'home' => 'Home', 'work' => 'Work', 'mobile' => 'Mobile', 'main' => 'Main',
    ];

    /**
     * @return list<string>|null TYPE-Werte, oder null, wenn die Beschriftung als X-ABLabel reisen muss
     */
    public static function types(ItemKind $kind, string $label): ?array
    {
        return self::TYPES[$kind->value][$label] ?? null;
    }

    /** Text für X-ABLabel. */
    public static function appleLabel(ItemKind $kind, string $label): string
    {
        return in_array($label, $kind->labels(), true) && isset(self::APPLE[$label]) ? '_$!<' . self::APPLE[$label] . '>!$_' : $label;
    }

    /**
     * Beschriftung aus TYPE-Werten und X-ABLabel einer Eigenschaft.
     *
     * @param list<string> $types
     */
    public static function fromVcard(ItemKind $kind, array $types, ?string $abLabel): string
    {
        if (null !== $abLabel && '' !== trim($abLabel)) {
            if (1 === preg_match('/^_\$!<(.+)>!\$_$/', trim($abLabel), $match)) {
                $key = array_search($match[1], self::APPLE, true);

                return is_string($key) && in_array($key, $kind->labels(), true) ? $key : $match[1];
            }

            return trim($abLabel);
        }

        $types = array_values(array_diff(array_map(strtoupper(...), $types), ['PREF', 'INTERNET']));
        if (ItemKind::Phone === $kind) {
            $has = static fn (string $type): bool => in_array($type, $types, true);

            return match (true) {
                $has('FAX') => $has('HOME') ? 'fax_home' : 'fax_work',
                $has('IPHONE') => 'iphone',
                $has('CELL') => 'mobile',
                $has('MAIN') => 'main',
                $has('PAGER') => 'pager',
                $has('HOME') => 'home',
                $has('WORK') => 'work',
                default => 'other',
            };
        }

        return match (true) {
            in_array('HOME', $types, true) => 'home',
            in_array('WORK', $types, true) => 'work',
            default => $kind->labels() === [] ? '' : (in_array('other', $kind->labels(), true) ? 'other' : $kind->defaultLabel()),
        };
    }
}
