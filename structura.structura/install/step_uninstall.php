<?php

/**
 * Шаг 2 удаления: модуль уже удалён, отчитываемся и уводим обратно в список.
 *
 * Здесь только кнопка «Назад»: спрашивать что-либо после необратимого действия
 * поздно (выбор про данные делается на шаге 1).
 */

use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

/** @var CMain $APPLICATION */
?>
<?= CAdminMessage::ShowNote(
    Loc::getMessage('STRUCTURA_UNINSTALL_OK') ?: 'Модуль Structura удалён.'
) ?>
<?php
// Данные удалили (savedata=N) — но загруженные файлы остались: DROP TABLE их не
// уносит, а построчно чистить большой каталог значило бы упереться в таймаут.
// Честно сообщаем, иначе выбравший «удалить данные» решит, что и файлы исчезли.
if (!empty($GLOBALS['STRUCTURA_UNINSTALL_DROPPED'])):
    ?>
    <?= CAdminMessage::ShowMessage([
        'TYPE'    => 'PROGRESS',
        'MESSAGE' => Loc::getMessage('STRUCTURA_UNINSTALL_FILES_KEPT')
            ?: 'Значения и история удалены. Файлы, загруженные через поля модуля '
                . '(записи b_file модуля structura.structura и их байты), не удалены — '
                . 'их построчная чистка на большом каталоге вышла бы за таймаут. '
                . 'Удалите их вручную, если нужно освободить место.',
    ]) ?>
    <?php
endif;
?>
<?php
// Что модуль убрал за собой и что намеренно оставил. Оставленным может
// оказаться правленый configuration.php: его установщик клал сам, но со своими
// настройками (`blocks_dir` и т.п.) удалять его молча нельзя. Блоки модуль не
// трогает вовсе, поэтому среди «оставленного» их не будет.
$files   = $GLOBALS['STRUCTURA_UNINSTALL_FILES'] ?? ['removed' => [], 'kept' => []];
$removed = $files['removed'] ?? [];
$kept    = $files['kept'] ?? [];
?>
<?php if ($removed !== []): ?>
    <p><?= Loc::getMessage('STRUCTURA_UNINSTALL_REMOVED') ?: 'Удалены копии файлов модуля:' ?></p>
    <ul>
        <?php foreach ($removed as $path): ?>
            <li><code><?= htmlspecialcharsbx($path) ?></code></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
<?php if ($kept !== []): ?>
    <p><?= Loc::getMessage('STRUCTURA_UNINSTALL_KEPT')
        ?: 'Оставлены — их правили, поэтому они считаются вашими:' ?></p>
    <ul>
        <?php foreach ($kept as $path): ?>
            <li><code><?= htmlspecialcharsbx($path) ?></code></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" name="" value="<?= Loc::getMessage('MOD_BACK') ?: 'Назад' ?>">
</form>
