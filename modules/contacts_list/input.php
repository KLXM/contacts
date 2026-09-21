<?php
$contactsBooks = \KLXM\Contacts\Contacts::books()->all();
$contactsShow = rex_var::toArray('REX_VALUE[5]') ?: \KLXM\Contacts\Frontend\Frontend::SHOW_DEFAULT;
$contactsOption = static fn (string $value, string $label, string $current): string => '<option value="' . rex_escape($value) . '"' . ($value === $current ? ' selected' : '') . '>' . rex_escape($label) . '</option>';
?>
<div class="form-horizontal">
    <div class="form-group">
        <label class="col-sm-3 control-label" for="contacts-mod-headline"><?= \KLXM\Contacts\I18n::e('module_headline') ?></label>
        <div class="col-sm-9"><input class="form-control" type="text" id="contacts-mod-headline" name="REX_INPUT_VALUE[4]" value="REX_VALUE[4]"></div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label" for="contacts-mod-source"><?= \KLXM\Contacts\I18n::e('module_source') ?></label>
        <div class="col-sm-9">
            <select class="form-control" id="contacts-mod-source" name="REX_INPUT_VALUE[1]">
                <?= $contactsOption('', \KLXM\Contacts\I18n::t('module_source_all'), 'REX_VALUE[1]') ?>
                <?php foreach ($contactsBooks as $contactsBook): ?>
                    <?= $contactsOption('book:' . $contactsBook->id, $contactsBook->name, 'REX_VALUE[1]') ?>
                    <?php foreach (\KLXM\Contacts\Contacts::lists()->forBook((int) $contactsBook->id) as $contactsList): ?>
                        <?= $contactsOption('list:' . $contactsList->id, $contactsBook->name . ' › ' . $contactsList->name, 'REX_VALUE[1]') ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
            <p class="help-block"><?= \KLXM\Contacts\I18n::e('module_source_help') ?></p>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label" for="contacts-mod-layout"><?= \KLXM\Contacts\I18n::e('module_layout') ?></label>
        <div class="col-sm-9">
            <select class="form-control" id="contacts-mod-layout" name="REX_INPUT_VALUE[3]">
                <?php foreach (\KLXM\Contacts\Frontend\Frontend::LAYOUTS as $contactsLayout): ?>
                    <?= $contactsOption($contactsLayout, \KLXM\Contacts\I18n::t('module_layout_' . $contactsLayout), 'REX_VALUE[3]' ?: 'cards') ?>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label"><?= \KLXM\Contacts\I18n::e('module_show') ?></label>
        <div class="col-sm-9">
            <input type="hidden" name="REX_INPUT_VALUE[5][]" value="name">
            <?php foreach (\KLXM\Contacts\Frontend\Frontend::SHOW_OPTIONS as $contactsKey): ?>
                <label style="display:inline-block;min-width:220px;font-weight:400"><input type="checkbox" name="REX_INPUT_VALUE[5][]" value="<?= $contactsKey ?>"<?= in_array($contactsKey, $contactsShow, true) ? ' checked' : '' ?>> <?= \KLXM\Contacts\I18n::e('module_show_' . $contactsKey) ?></label>
            <?php endforeach; ?>
            <p class="help-block"><?= \KLXM\Contacts\I18n::e('module_show_help') ?></p>
        </div>
    </div>
    <div class="form-group">
        <div class="col-sm-offset-3 col-sm-9">
            <label style="font-weight:400"><input type="hidden" name="REX_INPUT_VALUE[6]" value="0"><input type="checkbox" name="REX_INPUT_VALUE[6]" value="1"<?= '0' === 'REX_VALUE[6]' ? '' : ' checked' ?>> <?= \KLXM\Contacts\I18n::e('module_include_css') ?></label>
        </div>
    </div>
</div>
