<?php

namespace Structura\Structura\Integration;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\UI\Extension;
use Structura\Structura\Config\Loader as ConfigLoader;
use Structura\Structura\Service\ValueService;
use Structura\Structura\Storage\ValueTable;

Loc::loadMessages(__FILE__);

/**
 * Интеграция с формами инфоблока: элемент и раздел.
 *
 * Добавляет вкладку «Structura» через события main:OnAdminIBlockElementEdit и
 * main:OnAdminIBlockSectionEdit (оба вызывает CAdminTabEngine — из
 * iblock_element_edit.php и iblock_section_edit.php соответственно) и монтирует
 * в неё Vue-приложение модуля.
 *
 * Формы разные, но событиям ядро передаёт одинаковые аргументы
 * (ID, IBLOCK, IBLOCK_TYPE), поэтому вывод вкладки общий — различается только
 * тип сущности. Он и решает всё остальное: по нему фильтруются блоки
 * (visibleOn → sections/elements), адресуются значения и проверяются права
 * (section_edit против element_edit).
 *
 * Вкладки на форме самого инфоблока нет: значения ему писать некому.
 */
class AdminIblock
{
    /**
     * Имена ajax-действий, которые фронтенд зовёт через BX.ajax.runAction.
     *
     * Формат — `<vendor>:<module>.<controller>.<action>`. Двоеточие обязательно
     * и отделяет вендора: `Engine\Router::resolveVendor` режет строку по нему,
     * а `refineModuleName` склеивает модуль обратно как `vendor.module`. Без
     * двоеточия вендором станет `bitrix`, модулем — `structura`, и роутер уйдёт
     * искать несуществующий модуль.
     *
     * Вынесено из payload отдельным списком, чтобы имена можно было проверить
     * тестом: остальные тесты зовут действия напрямую и опечатку в имени не
     * увидят.
     *
     * @return array<string, string> ключ для фронтенда => имя действия
     */
    public static function actionEndpoints(): array
    {
        $actions = [
            'save', 'remove', 'reorder', 'revisions', 'revision', 'rollback',
            // Языковые вкладки переводимого блока: значения другого языка и
            // заполнение пустого перевода из базы.
            'values', 'prefill',
            // Справочники для ссылочных полей.
            'iblocks', 'highloads', 'sections',
            // Подпись уже выбранного значения: из-за пагинации оно может не
            // попасть в текущую страницу выдачи.
            'section', 'hlrow', 'hlrows', 'user', 'element', 'iblock',
            'upload', 'file', 'cloneRow', 'deleteFile',
        ];

        $endpoints = [];
        foreach ($actions as $action) {
            $endpoints[$action] = 'structura:structura.block.' . $action;
        }

        return $endpoints;
    }

    /**
     * Имена глобальных функций диалога выбора файла. Имена чистятся ядром по
     * /[^a-zA-Z0-9_]/, поэтому только буквы, цифры и подчёркивание.
     * OPEN — открывашка от ядра; RESULT — наш колбэк, который ядро зовёт.
     */
    private const FILE_DIALOG_OPEN   = 'structuraFileDialog';
    private const FILE_DIALOG_RESULT = 'structuraFileDialogPick';

    /** То же для диалога медиабиблиотеки (поле MediaLibrary). */
    private const ML_DIALOG_OPEN   = 'structuraMediaLib';
    private const ML_DIALOG_RESULT = 'structuraMediaLibPick';

    /**
     * Обработчик события формы элемента.
     *
     * ShowTab и GetTabs у элемента и раздела разные: аргументы у их событий
     * одинаковые, поэтому тип сущности определяет то, чей колбэк позвали.
     */
    public static function onAdminIBlockElementEdit(array $args): array
    {
        return [
            'TABSET'  => 'structura',
            'GetTabs' => [self::class, 'getElementTabs'],
            'ShowTab' => [self::class, 'showElementTab'],
        ];
    }

    /** Обработчик события формы раздела. */
    public static function onAdminIBlockSectionEdit(array $args): array
    {
        return [
            'TABSET'  => 'structura',
            'GetTabs' => [self::class, 'getSectionTabs'],
            'ShowTab' => [self::class, 'showSectionTab'],
        ];
    }

    public static function showElementTab(string $div, array $args, bool $bVarsFromForm): void
    {
        self::renderTab(ValueTable::ENTITY_ELEMENT, $div, $args, $bVarsFromForm);
    }

    public static function showSectionTab(string $div, array $args, bool $bVarsFromForm): void
    {
        self::renderTab(ValueTable::ENTITY_SECTION, $div, $args, $bVarsFromForm);
    }

    public static function getElementTabs(array $args): array
    {
        return self::tabsFor(ValueTable::ENTITY_ELEMENT, $args);
    }

    public static function getSectionTabs(array $args): array
    {
        return self::tabsFor(ValueTable::ENTITY_SECTION, $args);
    }

    /**
     * Описание вкладки для формы — или пустой список, если её показывать незачем.
     *
     * @return array<int, array{DIV: string, TAB: string, ICON: string, TITLE: string}>
     */
    private static function tabsFor(string $entityType, array $args): array
    {
        if (!self::shouldShowTab($entityType, $args)) {
            return [];
        }

        return [[
            'DIV'   => 'edit',
            'TAB'   => 'Structura',
            'ICON'  => 'main_page',
            'TITLE' => (string) Loc::getMessage('STRUCTURA_ADMINIBLOCK_TAB_TITLE'),
        ]];
    }

    /**
     * Показывать ли вкладку на этой форме.
     *
     * Вкладка нужна, если сущности доступен хотя бы один блок, либо у неё
     * остались значения блока, чью конфигурацию удалили (их нельзя прятать —
     * со стороны это выглядит потерей данных). У инфоблока без блоков Structura
     * вкладки быть не должно.
     *
     * Проверка отказоустойчива: при сбое в чтении конфигурации вкладку
     * показываем, а её обработчик ({@see renderTab}) сообщит о проблеме.
     */
    private static function shouldShowTab(string $entityType, array $args): bool
    {
        if (!Loader::includeModule('structura.structura')) {
            return false;
        }

        try {
            $iblock     = is_array($args['IBLOCK'] ?? null) ? $args['IBLOCK'] : [];
            $iblockId   = (int) ($iblock['ID'] ?? 0);
            $iblockCode = (string) ($iblock['CODE'] ?? '') ?: null;
            $entityId   = (int) ($args['ID'] ?? 0);

            if (ConfigLoader::forEntity($entityType, $entityId, $iblockId, $iblockCode) !== []) {
                return true;
            }

            // Осиротевшие значения проверяем последними и только у сохранённой
            // сущности: это одна выборка по индексу (ENTITY_TYPE, ENTITY_ID).
            return $entityId > 0
                && (new ValueService())->orphanInstances($entityType, $entityId) !== [];
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Выводит содержимое вкладки: точку монтирования Vue и начальные данные.
     *
     * Общий код для элемента и раздела — различает их только $entityType.
     */
    private static function renderTab(string $entityType, string $div, array $args, bool $bVarsFromForm): void
    {
        if (!Loader::includeModule('structura.structura')) {
            echo '<tr><td>' . htmlspecialcharsbx((string) Loc::getMessage('STRUCTURA_ADMINIBLOCK_MODULE_OFF')) . '</td></tr>';
            return;
        }

        // ShowTab ядро зовёт синхронно во время рендера формы: непойманный
        // Throwable уронил бы всю форму элемента/раздела, а не только вкладку.
        // Поэтому сбой (в т.ч. в чтении битой конфигурации) логируется и
        // заменяется сообщением. Буфер делает отказ атомарным: иначе частично
        // напечатанный HTML вкладки остался бы поверх сообщения об ошибке.
        $obLevel = ob_get_level();
        try {
            ob_start();
            self::renderTabBody($entityType, $args);
            echo ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }

            \AddMessage2Log(
                (string) Loc::getMessage('STRUCTURA_ADMINIBLOCK_TAB_ERROR_LOG', [
                    '#ERROR#' => $e->getMessage() . "\n" . $e->getTraceAsString(),
                ]),
                STRUCTURA_MODULE_ID
            );

            echo '<tr><td colspan="2" style="padding:16px;color:#a94442">'
                . htmlspecialcharsbx((string) Loc::getMessage('STRUCTURA_ADMINIBLOCK_TAB_UNAVAILABLE'))
                . '</td></tr>';
        }
    }

    /**
     * Тело вкладки. Вынесено из renderTab, чтобы вокруг него держать буфер и
     * перехват сбоев: см. renderTab().
     */
    private static function renderTabBody(string $entityType, array $args): void
    {
        $entityId   = (int) ($args['ID'] ?? 0);
        $iblock     = is_array($args['IBLOCK'] ?? null) ? $args['IBLOCK'] : [];
        $iblockId   = (int) ($iblock['ID'] ?? 0);
        $iblockCode = (string) ($iblock['CODE'] ?? '') ?: null;

        $what = (string) Loc::getMessage($entityType === ValueTable::ENTITY_SECTION
            ? 'STRUCTURA_ADMINIBLOCK_ENTITY_SECTION'
            : 'STRUCTURA_ADMINIBLOCK_ENTITY_ELEMENT');

        // На форме копирования ядро передаёт вкладке ID исходного элемента (при
        // action=copy $ID остаётся исходным). Показать здесь поля значило бы
        // править оригинал: вкладка сохраняется отдельным ajax-запросом по этому
        // ID. Своего ID у копии ещё нет; её значения приедут при сохранении
        // (см. Integration\ElementCopy).
        if (self::isCopyForm()) {
            echo '<tr><td colspan="2" style="padding:16px;color:#6a737d">'
                . htmlspecialcharsbx((string) Loc::getMessage('STRUCTURA_ADMINIBLOCK_COPY_HINT'))
                . '</td></tr>';
            return;
        }

        if ($entityId <= 0) {
            echo '<tr><td colspan="2" style="padding:16px;color:#6a737d">'
                . htmlspecialcharsbx((string) Loc::getMessage('STRUCTURA_ADMINIBLOCK_SAVE_FIRST', ['#WHAT#' => $what]))
                . '</td></tr>';
            return;
        }

        // forEntity → all() грузит все блоки, попутно наполняя errors().
        // Показываем диагностику до early-return: если битой оказалась вся
        // конфигурация, $blocks будет пуст, и без этого причина не попала бы
        // на глаза.
        $blocks = ConfigLoader::forEntity($entityType, $entityId, $iblockId, $iblockCode);
        self::showLoaderErrors();
        // Два блока с одинаковым кодом (именем папки): второй в индекс не попал
        // и на форме отсутствует — молчаливая пропажа выглядит как потеря данных.
        self::showLoaderConflicts();
        self::showLoaderWarnings();

        if (!$blocks) {
            $audience = (string) Loc::getMessage($entityType === ValueTable::ENTITY_SECTION
                ? 'STRUCTURA_ADMINIBLOCK_AUDIENCE_SECTION'
                : 'STRUCTURA_ADMINIBLOCK_AUDIENCE_ELEMENT');
            echo '<tr><td colspan="2" style="padding:16px;color:#6a737d">'
                . htmlspecialcharsbx((string) Loc::getMessage('STRUCTURA_ADMINIBLOCK_NO_BLOCKS', ['#AUDIENCE#' => $audience]))
                . '</td></tr>';
            return;
        }

        $service    = new ValueService();
        $blocksData = [];
        foreach ($blocks as $block) {
            // describe, а не toArray: пикер группирует блоки по группам и
            // учитывает unique.
            $blocksData[] = ConfigLoader::describe($block);
        }

        // Значения блоков без конфигурации: папку блока удалили или
        // переименовали (код блока = имя папки). Нигде больше не показываются —
        // предупреждаем, иначе это выглядит потерей данных.
        $orphans = $service->orphanInstances($entityType, $entityId);

        // Что уже добавлено к сущности: экземпляры в порядке SORT. Блок с
        // ->multiple() может встречаться здесь несколько раз, поэтому список,
        // а не карта по коду блока.
        $available = [];
        foreach ($blocks as $block) {
            $available[$block->getCode()] = $block;
        }

        // Какие языки уже переведены — одним запросом на всю сущность. Вкладке
        // это нужно, чтобы пометить непереведённые языки, не спрашивая сервер
        // про каждый блок отдельно.
        $translated = $service->translatedLanguages($entityType, $entityId);

        $instancesData = [];
        foreach ($service->listInstances($entityType, $entityId) as $instance) {
            $block = $available[$instance['blockCode']] ?? null;
            if ($block === null) {
                // Блок больше не доступен этой сущности (изменили visibleOn или
                // удалили config.php) — молча не показываем, данные не трогаем.
                continue;
            }

            $instancesData[] = [
                'instanceId' => $instance['instanceId'],
                'blockCode'  => $instance['blockCode'],
                // Активность экземпляра — редактируемая, поэтому на верхнем уровне
                // (не в meta): переключатель и даты в шапке карточки, activeNow —
                // подсказка «виден ли сейчас на сайте».
                'active'     => $instance['active'],
                'activeFrom' => $instance['activeFrom'],
                'activeTo'   => $instance['activeTo'],
                'activeNow'  => $instance['activeNow'],
                // Значения языка по умолчанию: форма открывается на fallback-
                // вкладке, остальные языки подтягиваются по клику (values).
                'values'     => $service->getWithDefaults(
                    $instance['blockCode'],
                    $entityType,
                    $entityId,
                    $instance['instanceId']
                ),
                // Языки, перевод которых уже сохранён (fallback сюда не входит:
                // он есть у экземпляра по определению).
                'translated' => $translated[$instance['instanceId']] ?? [],
                // Авторство: кто завёл экземпляр и кто менял последним. Имена
                // резолвим сервером (клиент групп/логинов не знает), id оставляем
                // для ссылки на карточку пользователя.
                'meta' => [
                    'createdBy'   => $instance['createdBy'],
                    'updatedBy'   => $instance['updatedBy'],
                    'createdAt'   => $instance['createdAt'],
                    'updatedAt'   => $instance['updatedAt'],
                    'createdName' => self::userName($instance['createdBy']),
                    'updatedName' => self::userName($instance['updatedBy']),
                ],
            ];
        }

        // Ссылаться на файлы внутри каталога модуля нельзя: nginx закрывает и
        // /bitrix/modules, и local/modules (403). Ассеты публикуются в
        // веб-доступный корень (см. Assets); здесь же — на случай, если после
        // обновления модуля забыли переустановку (копия обновляется по mtime).
        Assets::publish();
        ['css' => $cssUrl, 'js' => $jsUrl] = Assets::urls();

        // Vue + Vuex из ядра Bitrix. Extension ui.vue.vuex тянет ui.vue и отдаёт
        // BX.Vuex, связанный с BX.VueVendor — глобальный window.Vue не нужен.
        Extension::load(['ui.vue', 'ui.vue.vuex']);
        $asset = Asset::getInstance();
        $asset->addJs($jsUrl);

        $mountId  = 'structura-app';
        $payload  = [
            'entity' => [
                'type'   => $entityType,
                'id'     => $entityId,
                'iblock' => $iblockId,
            ],
            'blocks'    => $blocksData,
            'instances' => $instancesData,
            'orphans'   => $orphans,
            // Языки контента — глобальные (configuration.php). Вкладки рисуются
            // только у ->translatable()-блоков; fallback — первая вкладка, его
            // значения и приехали в instances.values.
            'languages'        => ConfigLoader::languages(),
            'languageFallback' => ConfigLoader::languageFallback(),
            // Адрес стандартного попапа выбора пользователя. Через
            // SELF_FOLDER_URL, а не хардкодом: админка может жить не в
            // /bitrix/admin/ (так же делает CMain::FindUserID).
            'userSearchUrl' => (defined('SELF_FOLDER_URL') ? SELF_FOLDER_URL : '/bitrix/admin/')
                . 'user_search.php?lang=' . LANGUAGE_ID,
            // Диалог выбора файла (поле Gallery). Открывашка/колбэк — глобальные
            // и одни на страницу, см. showFileDialogScript().
            'fileDialog'       => self::FILE_DIALOG_OPEN,
            'fileDialogResult' => self::FILE_DIALOG_RESULT,
            // Диалог медиабиблиотеки (поле MediaLibrary), тоже один на страницу.
            'mlDialog'         => self::ML_DIALOG_OPEN,
            'mlDialogResult'   => self::ML_DIALOG_RESULT,
            'elementSearchUrl' => (defined('SELF_FOLDER_URL') ? SELF_FOLDER_URL : '/bitrix/admin/')
                . 'iblock_element_search.php?lang=' . LANGUAGE_ID,
        ] + self::actionEndpoints();
        $json = \Bitrix\Main\Web\Json::encode($payload);

        echo '<tr><td colspan="2">';
        // CSS подключаем инлайновым <link>: Asset::addCss из тела вкладки (после
        // вывода <head>) в админке не выводится, а <link> в body браузер применяет.
        echo '<link rel="stylesheet" href="' . htmlspecialcharsbx($cssUrl) . '">';
        self::showFileDialogScript();
        self::showMediaLibraryScript();
        echo '<div id="' . $mountId . '" class="structura-root"></div>';
        // Строки JS-компонентов — из lang стандартным механизмом: печатаем их в
        // собственный словарь BX.structura.messages (его читает помощник t() в
        // structura.js), а не в глобальный BX.message. Строки берём из зеркального
        // языкового файла admin/assets/js/structura.js.
        $jsMessages = Loc::loadLanguageFile(STRUCTURA_MODULE_DIR . '/admin/assets/js/structura.js');
        if ($jsMessages !== []) {
            echo '<script>window.BX=window.BX||{};BX.structura=BX.structura||{};'
                . 'BX.structura.messages=Object.assign(BX.structura.messages||{},'
                . \CUtil::PhpToJSObject($jsMessages) . ');</script>';
        }
        echo '<script>BX.ready(function(){'
            . 'if(window.Structura&&window.Structura.mount){'
            . 'window.Structura.mount("#' . $mountId . '",' . $json . ');'
            . '}});</script>';
        echo '</td></tr>';
    }

    /**
     * Отображаемое имя пользователя по ID — для подписи «создал/обновил».
     *
     * Без права смотреть пользователей (как у попапа userAction) имя не отдаём:
     * иначе перебором ID выгружалась бы база мимо попапа. Результаты кэшируем —
     * один автор обычно завёл несколько экземпляров.
     *
     * @var array<int, ?string>
     */
    private static array $userNameCache = [];

    private static function userName(?int $id): ?string
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        if (array_key_exists($id, self::$userNameCache)) {
            return self::$userNameCache[$id];
        }

        global $USER;
        $canViewUsers = is_object($USER)
            && ($USER->CanDoOperation('view_subordinate_users') || $USER->CanDoOperation('view_all_users'));
        if (!$canViewUsers) {
            return self::$userNameCache[$id] = null;
        }

        $row = \Bitrix\Main\UserTable::getList([
            'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
            'filter' => ['=ID' => $id],
            'limit'  => 1,
        ])->fetch();

        if (!$row) {
            return self::$userNameCache[$id] = null;
        }

        $name = trim((string) \CUser::FormatName(\CSite::GetNameFormat(), $row, true, false));

        return self::$userNameCache[$id] = ($name !== '' ? $name : (string) $row['LOGIN']);
    }

    /**
     * Предупреждение о блоках/группах, которые не загрузились из-за ошибки
     * конфигурации. Их загрузчик пропустил (форма не падает), но контент-менеджер
     * должен видеть, что часть свойств недоступна, и по какой причине.
     */
    private static function showLoaderErrors(): void
    {
        $errors = ConfigLoader::errors();
        if (!$errors) {
            return;
        }

        echo '<tr><td colspan="2" style="padding:12px 16px;color:#8a6d3b;'
            . 'background:#fcf8e3;border:1px solid #faebcc">'
            . '<b>Structura:</b> ' . htmlspecialcharsbx((string) Loc::getMessage('STRUCTURA_ADMINIBLOCK_ERRORS_INTRO'))
            . '<ul style="margin:6px 0 0;padding-left:20px">';
        foreach ($errors as $error) {
            echo '<li>' . htmlspecialcharsbx($error['message']) . '</li>';
        }
        echo '</ul></td></tr>';
    }

    /**
     * Блоки, пропущенные из-за одинакового кода (имени папки).
     *
     * Отдельной плашкой от errors(): там конфигурация не загрузилась, здесь файл
     * в порядке, но код занят и чинится только переименованием папки. Тоном как
     * ошибка: блок отсутствует, и его значения не видны.
     */
    private static function showLoaderConflicts(): void
    {
        $conflicts = ConfigLoader::conflicts();
        if (!$conflicts) {
            return;
        }

        echo '<tr><td colspan="2" style="padding:12px 16px;color:#8a6d3b;'
            . 'background:#fcf8e3;border:1px solid #faebcc">'
            . '<b>Structura:</b> ' . htmlspecialcharsbx((string) Loc::getMessage('STRUCTURA_ADMINIBLOCK_CONFLICTS_INTRO'))
            . '<ul style="margin:6px 0 0;padding-left:20px">';
        foreach ($conflicts as $conflict) {
            echo '<li>' . htmlspecialcharsbx($conflict['message']) . '</li>';
        }
        echo '</ul></td></tr>';
    }

    /**
     * Замечания по конфигурации, которая загрузилась и работает. Отдельным
     * блоком от ошибок: там часть блоков не показана, здесь всё на месте, но
     * написано не так, как думает автор.
     */
    private static function showLoaderWarnings(): void
    {
        $warnings = ConfigLoader::warnings();
        if (!$warnings) {
            return;
        }

        echo '<tr><td colspan="2" style="padding:12px 16px;color:#31708f;'
            . 'background:#eaf4fb;border:1px solid #bce8f1">'
            . '<b>Structura:</b> ' . htmlspecialcharsbx((string) Loc::getMessage('STRUCTURA_ADMINIBLOCK_WARNINGS_INTRO'))
            . '<ul style="margin:6px 0 0;padding-left:20px">';
        foreach ($warnings as $warning) {
            echo '<li>' . htmlspecialcharsbx($warning['message']) . '</li>';
        }
        echo '</ul></td></tr>';
    }

    /**
     * Открыта ли форма копирования элемента.
     *
     * Два признака: `action=copy` есть при переходе по кнопке «Копировать», а
     * `copyID` форма кладёт скрытым полем и возвращает при перерисовке (например,
     * после ошибки валидации), когда `action` в запросе уже нет.
     */
    private static function isCopyForm(): bool
    {
        return ($_REQUEST['action'] ?? '') === 'copy'
            || (int) ($_REQUEST['copyID'] ?? 0) > 0;
    }

    /**
     * Скрипт диалога выбора файла для поля Gallery — один на страницу.
     *
     * CAdminFileDialog::ShowScript печатает разметку сервером и заводит
     * window.<event>() (открыть) и window.<event>Result() (результат). На каждое
     * поле его не отрендерить: строки Repeater создаются на клиенте в заранее
     * неизвестном количестве. Поэтому диалог один, а результат отдаёт в наш
     * колбэк (режим FUNCTION_NAME) — фронтенд сам знает, какое поле его открыло.
     *
     * Диалог живёт в модуле fileman. Нет модуля — window.<event> не появится, и
     * фронтенд прячет кнопку выбора (см. gallery в structura.js).
     */
    private static function showFileDialogScript(): void
    {
        if (!Loader::includeModule('fileman')) {
            return;
        }

        \CAdminFileDialog::ShowScript([
            'event'        => self::FILE_DIALOG_OPEN,
            'arResultDest' => ['FUNCTION_NAME' => self::FILE_DIALOG_RESULT],
            'arPath'       => ['PATH' => '/upload'],
            'select'       => 'F',      // только файл, не папка
            'operation'    => 'O',      // открыть (а не «сохранить как»)
            'showUploadTab'    => true, // из диалога можно и залить новый файл
            'showAddToMenuTab' => false,
            'allowAllFiles'    => true,
            'SaveConfig'       => true,
        ]);
    }

    /**
     * Скрипт диалога медиабиблиотеки для поля MediaLibrary — один на страницу.
     *
     * CMedialib::ShowDialogScript печатает сервером window.<event>() (открыть) и
     * в режиме arResultDest.FUNCTION_NAME отдаёт выбор в наш колбэк объектом
     * {src, name, width, ...}. Приём тот же, что у диалога файла (Gallery):
     * диалог один, а какое поле его открыло — помнит фронтенд.
     *
     * `types` не передаём — библиотека покажет все свои типы. Скрипт печатаем
     * только при наличии модуля fileman и права на просмотр коллекций: без права
     * window.<event> не появится, и фронтенд прячет кнопку (см. medialibrary в
     * structura.js), путь остаётся вписать руками.
     */
    private static function showMediaLibraryScript(): void
    {
        if (!Loader::includeModule('fileman')) {
            return;
        }
        if (!\CMedialib::CanDoOperation('medialib_view_collection', 0)) {
            return;
        }

        \CMedialib::ShowDialogScript([
            'event'        => self::ML_DIALOG_OPEN,
            'arResultDest' => ['FUNCTION_NAME' => self::ML_DIALOG_RESULT],
        ]);
    }
}
