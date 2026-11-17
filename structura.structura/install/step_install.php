<?php

use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);
?>
<p><?= Loc::getMessage('STRUCTURA_INSTALL_OK') ?: 'Модуль Structura установлен.' ?></p>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" name="" value="<?= Loc::getMessage('MOD_BACK') ?: 'Назад' ?>">
</form>
