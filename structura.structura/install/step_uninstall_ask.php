<?php

/**
 * Шаг 1 удаления: спрашиваем про сохранённые значения и ждём подтверждения.
 *
 * Скрытые поля обязательны все пять: без `id` и `uninstall=Y` страница
 * partner_modules.php не поймёт, что от неё хотят, без `step=2` DoUninstall
 * снова покажет эту же форму, а без `sessid` ядро не дойдёт до DoUninstall
 * (ветка удаления идёт только под `check_bitrix_sessid()`). Токен печатает
 * bitrix_sessid_post().
 */

use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

/** @var CMain $APPLICATION */
?>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="structura.structura">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">
    <?= bitrix_sessid_post() ?>

    <?= CAdminMessage::ShowMessage([
        'TYPE'    => 'PROGRESS',
        'MESSAGE' => Loc::getMessage('STRUCTURA_UNINSTALL_WARNING')
            ?: 'Модуль будет удалён. Конфигурации блоков в /local/php_interface/structura/ остаются на месте.',
    ]) ?>

    <p>
        <label>
            <?= Loc::getMessage('STRUCTURA_UNINSTALL_SAVEDATA') ?: 'Сохранённые значения блоков:' ?>
            <select name="savedata">
                <option value="Y"><?= Loc::getMessage('STRUCTURA_KEEP') ?: 'сохранить' ?></option>
                <option value="N"><?= Loc::getMessage('STRUCTURA_DROP') ?: 'удалить (drop table)' ?></option>
            </select>
        </label>
    </p>

    <input type="submit" name="inst" value="<?= Loc::getMessage('STRUCTURA_UNINSTALL_DEL') ?: 'Удалить' ?>">
</form>
