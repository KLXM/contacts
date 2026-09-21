<?php

declare(strict_types=1);

namespace KLXM\Contacts\Domain;

use KLXM\Contacts\I18n;

/**
 * Arten von Mehrfachfeldern eines Kontakts. Jeder Eintrag trägt eine Beschriftung: eine der
 * vorgegebenen (Schlüssel aus labels()) oder einen frei gewählten Text.
 */
enum ItemKind: string
{
    case Phone = 'phone';
    case Email = 'email';
    case Address = 'address';
    case Url = 'url';
    case Date = 'date';
    case Related = 'related';
    case Social = 'social';
    case Messenger = 'im';
    case Custom = 'custom';

    /**
     * Vorgegebene Beschriftungen. Eigene Felder haben keine: Ihre Beschriftung ist der Feldname.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return match ($this) {
            self::Phone => ['mobile', 'home', 'work', 'main', 'iphone', 'fax_home', 'fax_work', 'pager', 'other'],
            self::Email, self::Address => ['home', 'work', 'other'],
            self::Url => ['homepage', 'home', 'work', 'other'],
            self::Date => ['anniversary', 'other'],
            self::Related => ['spouse', 'partner', 'child', 'parent', 'mother', 'father', 'brother', 'sister', 'friend', 'assistant', 'manager', 'other'],
            self::Social => ['linkedin', 'xing', 'instagram', 'facebook', 'mastodon', 'bluesky', 'x', 'youtube', 'github'],
            self::Messenger => ['signal', 'threema', 'whatsapp', 'telegram', 'teams', 'skype', 'matrix'],
            self::Custom => [],
        };
    }

    public function defaultLabel(): string
    {
        return $this->labels()[0] ?? '';
    }

    public function title(): string
    {
        return I18n::t('kind_' . $this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Phone => 'fa-phone',
            self::Email => 'fa-envelope',
            self::Address => 'fa-location-dot',
            self::Url => 'fa-globe',
            self::Date => 'fa-cake-candles',
            self::Related => 'fa-people-arrows',
            self::Social => 'fa-share-nodes',
            self::Messenger => 'fa-comment',
            self::Custom => 'fa-pen',
        };
    }

    /** HTML-Eingabetyp des Wertes. */
    public function inputType(): string
    {
        return match ($this) {
            self::Phone => 'tel',
            self::Email => 'email',
            self::Url => 'url',
            self::Date => 'date',
            default => 'text',
        };
    }

    /** Beschriftung in einer bestimmten Sprache, für die Website. */
    public function labelTextIn(string $label, string $locale): string
    {
        return in_array($label, $this->labels(), true) && class_exists(\rex_i18n::class, false) ? \rex_i18n::rawMsgInLocale('contacts_label_' . $label, $locale) : $this->labelText($label);
    }

    /** Beschriftung in der Sprache der Oberfläche; freie Beschriftungen bleiben, wie sie sind. */
    public function labelText(string $label): string
    {
        return in_array($label, $this->labels(), true) ? I18n::t('label_' . $label) : $label;
    }
}
