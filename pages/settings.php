<?php

declare(strict_types=1);

use KLXM\Contacts\Backend\Html;
use KLXM\Contacts\I18n;
use KLXM\Contacts\Schema;

$addon = rex_addon::get('contacts');
$csrf = rex_csrf_token::factory('contacts_settings');

if ('post' === rex_request_method()) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $resort = $addon->getConfig('sort_by') !== rex_post('sort_by', 'string');
        $addon->setConfig('name_order', 'last_first' === rex_post('name_order', 'string') ? 'last_first' : 'first_last');
        $addon->setConfig('sort_by', 'first_name' === rex_post('sort_by', 'string') ? 'first_name' : 'last_name');
        $addon->setConfig('custom_fields', trim(rex_post('custom_fields', 'string')));
        $addon->setConfig('custom_labels', trim(rex_post('custom_labels', 'string')));
        $addon->setConfig('public_blocked', implode(',', array_filter(array_map(static fn (mixed $entry): string => preg_replace('/[^a-z_:]/', '', (string) $entry) ?? '', rex_post('public_blocked', 'array', [])))));
        if ($resort) {
            $expression = 'first_name' === $addon->getConfig('sort_by') ? "CONCAT(first_name, ' ', last_name)" : "CONCAT(last_name, ' ', first_name)";
            rex_sql::factory()->setQuery('UPDATE ' . Schema::table('contact') . " SET sort_name = LOWER(IF(is_company = 1 AND organization <> '', organization, IF(TRIM($expression) = '', display_name, TRIM($expression))))");
        }
        echo rex_view::success(I18n::e('settings_saved'));
    }
}

$body = $csrf->getHiddenField()
    . Html::field(I18n::t('settings_name_order'), Html::select('name_order', ['first_last' => I18n::t('settings_first_last'), 'last_first' => I18n::t('settings_last_first')], (string) $addon->getConfig('name_order'), ['id' => 'contacts-name-order']), 'contacts-name-order')
    . Html::field(I18n::t('settings_sort_by'), Html::select('sort_by', ['last_name' => I18n::t('last_name'), 'first_name' => I18n::t('first_name')], (string) $addon->getConfig('sort_by'), ['id' => 'contacts-sort-by']), 'contacts-sort-by')
    . Html::field(I18n::t('settings_custom_fields'), '<textarea class="form-control" id="contacts-custom-fields-setting" name="custom_fields" rows="5" placeholder="' . str_replace('\\n', '&#10;', I18n::e('settings_custom_fields_placeholder')) . '">' . Html::e((string) $addon->getConfig('custom_fields')) . '</textarea>', 'contacts-custom-fields-setting', I18n::t('settings_custom_fields_help'))
    . Html::field(I18n::t('settings_custom_labels'), '<textarea class="form-control" id="contacts-custom-labels-setting" name="custom_labels" rows="4" placeholder="' . str_replace('\\n', '&#10;', I18n::e('settings_custom_labels_placeholder')) . '">' . Html::e((string) $addon->getConfig('custom_labels')) . '</textarea>', 'contacts-custom-labels-setting', I18n::t('settings_custom_labels_help'));

$blocked = KLXM\Contacts\Settings::publicBlocked();
$box = static fn (string $value, string $label, string $class = ''): string => '<label class="contacts-check ' . $class . '"><input type="checkbox" name="public_blocked[]" value="' . Html::e($value) . '"' . (in_array($value, $blocked, true) ? ' checked' : '') . '> ' . Html::e($label) . '</label>';
$grid = '<fieldset><legend>' . I18n::e('settings_block_core') . '</legend>';
foreach (KLXM\Contacts\Domain\Contact::PUBLIC_FIELDS as $field) {
    $grid .= $box($field, I18n::t('public_field_' . $field));
}
$grid .= '</fieldset>';
foreach (KLXM\Contacts\Domain\ItemKind::cases() as $kind) {
    $grid .= '<fieldset><legend>' . Html::e($kind->title()) . '</legend>' . $box($kind->value, I18n::t('settings_block_all', $kind->title()));
    foreach (in_array($kind, [KLXM\Contacts\Domain\ItemKind::Phone, KLXM\Contacts\Domain\ItemKind::Email, KLXM\Contacts\Domain\ItemKind::Address], true) ? $kind->labels() : [] as $label) {
        $grid .= $box($kind->value . ':' . $label, I18n::t('settings_block_label', $kind->labelText($label)), 'contacts-check-sub');
    }
    $grid .= '</fieldset>';
}
$publicBody = '<p>' . I18n::e('settings_block_intro') . '</p><div class="contacts-block-grid">' . $grid . '</div>';

echo '<form action="' . rex_url::currentBackendPage() . '" method="post">' . Html::section(I18n::e('settings'), $body) . Html::section(I18n::e('settings_block_title'), $publicBody, '<button class="btn btn-save" type="submit">' . I18n::e('save') . '</button>') . '</form>';
