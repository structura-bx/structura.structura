<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Structura\Structura\Access\PolicyAudit;
use Structura\Structura\Access\PolicyRegistry;
use Structura\Structura\Config\Loader as ConfigLoader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

/** @global CMain $APPLICATION */
global $APPLICATION;

// Право сравнивается, а не проверяется на истинность: GetGroupRight() возвращает
// букву уровня, и при запрете это 'D' — истинная строка. Настройки модуля —
// уровень W, работа с блоками на формах — R.
if ($APPLICATION->GetGroupRight('structura.structura') < 'W') {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}

Loader::includeModule('structura.structura');

/**
 * includeModule() возвращает true, даже когда не подключил ничего.
 *
 * Ядро выбирает каталог модуля по существованию папки, а `include.php`
 * подключает лишь «если есть»: нет файла — `$res` остаётся true. Поэтому пустая
 * папка /local/modules/structura.structura затеняет рабочий модуль в
 * /bitrix/modules и регистрирует PSR-4 на несуществующий lib/.
 *
 * Классов после этого нет, но все проверки говорят «модуль подключён». Диагноз
 * печатаем до первого обращения к классам модуля: в таком состоянии их не
 * загрузить.
 */
if (!defined('STRUCTURA_MODULE_INCLUDED')) {
    $root   = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $copies = [];

    foreach (['/local/modules/structura.structura', '/bitrix/modules/structura.structura'] as $candidate) {
        if (!is_dir($root . $candidate)) {
            continue;
        }

        $copies[] = htmlspecialcharsbx($candidate) . (is_file($root . $candidate . '/include.php')
            ? ' — рабочая копия'
            : ' — <b>без include.php, она и затеняет модуль</b>');
    }

    // Разметкой, а не CAdminMessage: класс приезжает с прологом админки, и в
    // сломанном состоянии полагаться на него нельзя — гард, который сам падает
    // фаталом, не нужен никому.
    echo '<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message">'
        . '<b>' . (Loc::getMessage('STRUCTURA_SHADOWED_TITLE')
            ?: 'Модуль Structura не подключён, хотя числится установленным.') . '</b><br><br>'
        . 'Ядро ищет модуль сначала в <code>/local/modules</code> и, если такая папка есть, '
        . 'в <code>/bitrix/modules</code> уже не заглядывает — даже когда в первой нет '
        . '<code>include.php</code>. Найденные папки:<br><br>' . implode('<br>', $copies)
        . '<br><br>Уберите лишнюю папку (или доложите в неё файлы модуля) и обновите страницу.'
        . '</div></div>';

    return;
}

// Страница настроек модуля только читает: список блоков, языки, политики. Смена
// language_fallback миграции не требует (каждый язык — своя строка
// structura_value, читается по метке), поэтому действий-кнопок здесь нет.
$configuration = ConfigLoader::configuration();
$blocks        = ConfigLoader::all();
$loaderErrors  = ConfigLoader::errors();
// После all() выше: замечания копятся при загрузке блоков, до неё список пуст.
$loaderWarns   = ConfigLoader::warnings();
// Одинаковый код (имя папки) у двух блоков: второй в индекс не попал, и в
// списке ниже его нет. Без этой плашки экран врал бы, что блока не существует.
$loaderConflicts = ConfigLoader::conflicts();
$policyRows    = PolicyAudit::rows($blocks);
$namedPolicies = PolicyRegistry::names();

/**
 * Таблицы — в разметке штатных списков админки (adm-list-table и спутники,
 * bitrix/panel/main/admin.css): тот же вид, что у списка элементов инфоблока,
 * и ни строчки своего CSS. Разметка повторяет ту, что печатает ядро в
 * main/interface/admin_list.php: обёртка adm-list-table-wrap (без шапки списка
 * и футера — их у статичной таблицы нет), в шапке ячейки с -inner, пустое
 * состояние — adm-list-table-empty.
 *
 * @param string[]                $headers
 * @param array<int, string[]>    $rows    ячейки уже в HTML (экранировать до)
 */
$renderTable = static function (array $headers, array $rows, string $emptyText): void {
    ?>
    <div class="adm-list-table-wrap adm-list-table-without-header adm-list-table-without-footer">
        <table class="adm-list-table">
            <thead>
            <tr class="adm-list-table-header">
                <?php foreach ($headers as $header): ?>
                    <td class="adm-list-table-cell">
                        <div class="adm-list-table-cell-inner"><?= $header ?></div>
                    </td>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="<?= count($headers) ?>" class="adm-list-table-cell adm-list-table-empty">
                        <?= $emptyText ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $cells): ?>
                    <tr class="adm-list-table-row">
                        <?php foreach (array_values($cells) as $i => $cell): ?>
                            <td class="adm-list-table-cell<?= $i === count($cells) - 1 ? ' adm-list-table-cell-last' : '' ?>">
                                <?= $cell ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
};

$muted = static fn (string $text): string => '<span style="color:#a8adb4">' . $text . '</span>';

$blockRows = [];
foreach ($blocks as $code => $block) {
    $blockRows[] = [
        htmlspecialcharsbx((string) $code),
        htmlspecialcharsbx($block->getName()),
        htmlspecialcharsbx($block->getVersion()),
        (string) count($block->getFields()),
    ];
}

$languages = ConfigLoader::languages();
$fallback  = ConfigLoader::languageFallback();

$auditRows = [];
foreach ($policyRows as $row) {
    $auditRows[] = [
        htmlspecialcharsbx($row['blockName']) . ' ' . $muted('(' . htmlspecialcharsbx($row['block']) . ')'),
        htmlspecialcharsbx($row['name']) . ' ' . $muted(htmlspecialcharsbx($row['kind'])),
        $row['path'] === '' ? '—' : '<code>' . htmlspecialcharsbx($row['path']) . '</code>',
        htmlspecialcharsbx($row['description']),
    ];
}
?>
<?php if ($loaderErrors): ?>
    <?php // Блоки/группы, которые не загрузились: причина названа, чтобы автор
          // сразу видел, что и почему отлетело, а не гадал по пустому списку. ?>
    <div class="adm-info-message-wrap adm-info-message-red">
        <div class="adm-info-message">
            <b><?= Loc::getMessage('STRUCTURA_OPT_ERRORS_TITLE')
                ?: 'Часть конфигурации не загрузилась и пропущена' ?>:</b>
            <ul style="margin:8px 0 0">
                <?php foreach ($loaderErrors as $error): ?>
                    <li><?= htmlspecialcharsbx($error['message']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php if ($loaderConflicts): ?>
    <?php // Блоки с общим кодом: в индекс попал первый, остальные пропущены и
          // на формах их нет вовсе. Отдельно от «не загрузилось»: файл цел,
          // чинится только переименованием папки. ?>
    <div class="adm-info-message-wrap adm-info-message-red">
        <div class="adm-info-message">
            <b><?= Loc::getMessage('STRUCTURA_OPT_CONFLICTS_TITLE')
                ?: 'Блоки с одинаковым кодом — часть из них пропущена' ?>:</b>
            <ul style="margin:8px 0 0">
                <?php foreach ($loaderConflicts as $conflict): ?>
                    <li><?= htmlspecialcharsbx($conflict['message']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php if ($loaderWarns): ?>
    <?php // Конфигурация, которая работает, но написана не так, как думает
          // автор. Отдельной плашкой и другим цветом: смешать с «не
          // загрузилось» значило бы отправить искать пропавшие блоки. ?>
    <div class="adm-info-message-wrap">
        <div class="adm-info-message">
            <b><?= Loc::getMessage('STRUCTURA_OPT_WARNS_TITLE')
                ?: 'Замечания по конфигурации (блоки работают)' ?>:</b>
            <ul style="margin:8px 0 0">
                <?php foreach ($loaderWarns as $warning): ?>
                    <li><?= htmlspecialcharsbx($warning['message']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<p>
    <b><?= Loc::getMessage('STRUCTURA_OPT_BLOCKS_DIR') ?: 'Каталог блоков' ?>:</b>
    <?= htmlspecialcharsbx(ConfigLoader::blocksDir()) ?>
</p>

<?php $renderTable(
    [
        Loc::getMessage('STRUCTURA_OPT_CODE') ?: 'Код',
        Loc::getMessage('STRUCTURA_OPT_NAME') ?: 'Название',
        Loc::getMessage('STRUCTURA_OPT_VERSION') ?: 'Версия',
        Loc::getMessage('STRUCTURA_OPT_FIELDS') ?: 'Полей',
    ],
    $blockRows,
    Loc::getMessage('STRUCTURA_OPT_EMPTY') ?: 'Блоки не найдены.'
); ?>

<h3 style="margin-top:24px"><?= Loc::getMessage('STRUCTURA_OPT_LANG_TITLE') ?: 'Языки контента' ?></h3>

<p style="color:#6a737d;max-width:760px">
    Список языков и язык по умолчанию — глобальные, задаются в
    <code>configuration.php</code> (<code>languages</code>,
    <code>language_fallback</code>); у блока только флаг
    <code>-&gt;translatable()</code>. Каждый язык экземпляра хранится отдельной
    строкой <code>structura_value</code> (колонка <code>LANG</code>), и читается
    по своей метке.
</p>

<p>
    <b>Языки:</b>
    <?php if (!$languages): ?>
        <?= $muted('не настроены — блоки работают одноязычно') ?>
    <?php else: ?>
        <?php foreach ($languages as $i => $language): ?>
            <code><?= htmlspecialcharsbx($language) ?></code><?= $i === count($languages) - 1 ? '' : ', ' ?>
        <?php endforeach; ?>
        &nbsp;·&nbsp; <b>по умолчанию:</b> <code><?= htmlspecialcharsbx($fallback) ?></code>
    <?php endif; ?>
</p>

<p style="color:#6a737d;max-width:760px">
    Смена языка по умолчанию <b>безопасна и не требует миграции</b>: каждый язык
    уже лежит в своей строке и читается по метке. Прежний язык по умолчанию
    остаётся доступен на своей вкладке, новый — на своей; ничего перекладывать
    не нужно.
</p>

<h3 style="margin-top:24px"><?= Loc::getMessage('STRUCTURA_OPT_POLICY_TITLE') ?: 'Политики доступа' ?></h3>

<?php /* Один абзац подсказки вместо нескольких. Оставлено
         ровно то, без чего таблицу можно понять неправильно: что показаны
         только ограничения и что экран ничего не настраивает. Остальное —
         в docs/validation.md. */ ?>
<p style="color:#6a737d;max-width:760px">
    Только ограничения на <b>правку</b>: поля, которых нет в списке, правит
    любой, у кого есть права на сам элемент или раздел. Экран — для чтения;
    политики задаются в <code>config.php</code> блока (<code>setPolicy</code>).
</p>

<?php $renderTable(
    ['Блок', 'Что', 'Путь', 'Кому доступно'],
    $auditRows,
    'Ограничений нет: ни у одного блока и поля не задан <code>setPolicy</code>.'
); ?>

<?php /* Когда именованных политик нет, строка не выводится вовсе: «не
         зарегистрировано ни одной» — это шум, а не сведения. */ ?>
<?php if ($namedPolicies): ?>
    <p style="color:#6a737d">
        <b>Именованные политики</b> (регистрируются кодом,
        <code>PolicyRegistry::register()</code>):
        <?php foreach ($namedPolicies as $i => $name): ?>
            <code><?= htmlspecialcharsbx($name) ?></code><?= $i === count($namedPolicies) - 1 ? '.' : ', ' ?>
        <?php endforeach; ?>
    </p>
<?php endif; ?>
