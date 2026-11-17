<?php

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

if (class_exists('structura_structura')) {
    return;
}

/**
 * Установщик модуля Structura.
 *
 * Создаёт таблицу значений (structura_value, structura_revision), разворачивает шаблон настроек
 * и каталог блоков в /local/php_interface/structura/, регистрирует модуль.
 */
class structura_structura extends CModule
{
    public $MODULE_ID = 'structura.structura';
 
    public $MODULE_GROUP_RIGHTS = 'Y';

    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME = 'Ершов Вячеслав';
    public $PARTNER_URI = 'https://github.com/structura-bx/structura.structura';

    public function __construct()
    {
        $version = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION      = $version['VERSION'] ?? '1.0.0';
        $this->MODULE_VERSION_DATE = $version['VERSION_DATE'] ?? '';
        $this->MODULE_NAME         = Loc::getMessage('STRUCTURA_MODULE_NAME') ?: 'Structura';
        $this->MODULE_DESCRIPTION  = Loc::getMessage('STRUCTURA_MODULE_DESC') ?: '';
        $this->PARTNER_NAME        = 'Ершов Вячеслав';
        $this->PARTNER_URI         = 'https://github.com/structura-bx/structura.structura';
    }

    /**
     * Уровни доступа к модулю — с подписями, объясняющими, что уровень даёт.
     *
     * Без этого метода ядро подставит свой список (`GetDefaultRightList()`) с
     * безымянными «Чтение / Запись»: буквы те же, но по ним не понять, что
     * именно они открывают здесь.
     *
     * Уровня ровно три, и каждый различается кодом:
     *  - `D` — роут модуля закрыт совсем (умолчание для всех, кроме админа);
     *  - `R` — работа с блоками на формах элементов и разделов; это минимум для
     *    ajax-действий ({@see \Structura\Structura\Engine\ActionFilter\ModuleAccess});
     *  - `W` — плюс страница настроек модуля.
     *
     * Больше уровней не предлагаем намеренно: уровень, которого код не
     * различает, — обещание, которого модуль не выполняет.
     *
     * @return array{reference_id: array<int, string>, reference: array<int, string>}
     */
    public function GetModuleRightList(): array
    {
        return [
            'reference_id' => ['D', 'R', 'W'],
            'reference'    => [
                '[D] ' . (Loc::getMessage('STRUCTURA_RIGHT_D') ?: 'Доступ закрыт'),
                '[R] ' . (Loc::getMessage('STRUCTURA_RIGHT_R') ?: 'Работа с блоками на формах'),
                '[W] ' . (Loc::getMessage('STRUCTURA_RIGHT_W') ?: 'Полный доступ, включая настройки модуля'),
            ],
        ];
    }

    public function DoInstall(): void
    {
        global $APPLICATION;

        $this->InstallDB();
        $this->InstallFiles();
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallEvents();

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('STRUCTURA_INSTALL_TITLE') ?: 'Установка модуля Structura',
            __DIR__ . '/step_install.php'
        );
    }

    /**
     * Удаление модуля в два шага: сперва спросить про данные, потом удалять.
     * Шаг приходит из формы шага 1 (скрытое поле `step`), как у штатных модулей
     * ядра. Без подтверждения показывается форма с выбором «сохранить / удалить
     * значения».
     */
    public function DoUninstall(): void
    {
        global $APPLICATION;

        $request = Application::getInstance()->getContext()->getRequest();

        if (!self::isConfirmed($request->toArray())) {
            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('STRUCTURA_UNINSTALL_TITLE') ?: 'Удаление модуля Structura',
                __DIR__ . '/step_uninstall_ask.php'
            );

            return;
        }

        $dropData = self::shouldDropData($request->toArray());

        $this->UnInstallEvents();
        $this->UnInstallFiles();
        if ($dropData) {
            $this->UnInstallDB();
        }
        ModuleManager::unRegisterModule($this->MODULE_ID);

        // Отчёт предупредит, что загруженные через поля модуля файлы (b_file)
        // остались: при drop-data таблицы значений снесены, а файлы — нет.
        // Специально: их построчная чистка (CFile::Delete на каждый) на большом
        // каталоге вышла бы за таймаут, а DROP TABLE от числа строк не зависит.
        $GLOBALS['STRUCTURA_UNINSTALL_DROPPED'] = $dropData;

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('STRUCTURA_UNINSTALL_TITLE') ?: 'Удаление модуля Structura',
            __DIR__ . '/step_uninstall.php'
        );
    }

    /**
     * Подтвердил ли пользователь удаление (то есть пришёл ли он со шага 1).
     *
     * Отдельным методом, а не условием внутри DoUninstall: сам DoUninstall
     * необратим и печатает в админку, поэтому тестом его не погонять, а решение
     * «удалять или спросить» проверить надо.
     *
     * @param array<string, mixed> $request
     */
    public static function isConfirmed(array $request): bool
    {
        return (int) ($request['step'] ?? 0) >= 2;
    }

    /**
     * Удалять ли таблицы со значениями.
     *
     * Умолчание — сохранить: значения блоков пользователь не восстановит, а
     * модуль можно поставить обратно. Удаление только по явному «N».
     *
     * @param array<string, mixed> $request
     */
    public static function shouldDropData(array $request): bool
    {
        return ($request['savedata'] ?? null) === 'N';
    }

    /**
     * Создаёт таблицы модуля.
     *
     * Только создаёт таблицы: догонять схему существующих (ALTER) установщик не
     * умеет. Смена схемы применяется переустановкой с удалением данных.
     */
    public function InstallDB(): bool
    {
        // Модуль ещё не зарегистрирован в b_module, поэтому Loader::includeModule()
        // недоступен — подключаем автозагрузчик модуля напрямую.
        require_once __DIR__ . '/../include.php';

        $connection = Application::getConnection();
        $table      = \Structura\Structura\Storage\ValueTable::getTableName();

        if (!$connection->isTableExists($table)) {
            \Structura\Structura\Storage\ValueTable::getEntity()->createDbTable();
            // Экземпляр на сущности хранится по языкам: одна строка на
            // (экземпляр, язык), и все языки лежат в одной таблице. Уникальность —
            // (ENTITY_TYPE, ENTITY_ID, INSTANCE_ID, LANG). Уникальности по
            // (BLOCK_CODE, ENTITY_TYPE, ENTITY_ID) быть не должно: она запрещала
            // бы добавить множественный блок к сущности второй раз.
            $connection->queryExecute(
                "CREATE UNIQUE INDEX ux_structura_instance_lang ON {$table} (ENTITY_TYPE, ENTITY_ID, INSTANCE_ID, LANG)"
            );
            $connection->queryExecute(
                "CREATE INDEX ix_structura_entity_sort ON {$table} (ENTITY_TYPE, ENTITY_ID, SORT, ID)"
            );
        }

        // История значений (ревизии) — ведётся по экземпляру блока и языку.
        $revTable = \Structura\Structura\Storage\RevisionTable::getTableName();
        if (!$connection->isTableExists($revTable)) {
            \Structura\Structura\Storage\RevisionTable::getEntity()->createDbTable();

            $connection->queryExecute(
                "CREATE INDEX ix_structura_rev_instance ON {$revTable} (ENTITY_TYPE, ENTITY_ID, INSTANCE_ID, ID)"
            );
        }

        return true;
    }

    /**
     * Обработчики событий модуля.
     *
     * Вкладку Structura на формах элемента и раздела рисует CAdminTabEngine —
     * его события живут под модулем "main". Копирование значений цепляется к
     * добавлению элемента, уборка при удалении — к удалению элемента и раздела;
     * это уже модуль "iblock".
     *
     * Уборка висит на событиях после удаления: событие «до» чужой обработчик
     * вправе отменить, и значения ушли бы у живого элемента.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     *      модуль-источник, событие, класс обработчика, метод
     */
    private const EVENTS = [
        ['main', 'OnAdminIBlockElementEdit', '\\Structura\\Structura\\Integration\\AdminIblock', 'onAdminIBlockElementEdit'],
        ['main', 'OnAdminIBlockSectionEdit', '\\Structura\\Structura\\Integration\\AdminIblock', 'onAdminIBlockSectionEdit'],
        ['iblock', 'OnAfterIBlockElementAdd', '\\Structura\\Structura\\Integration\\ElementCopy', 'onAfterIBlockElementAdd'],
        ['iblock', 'OnAfterIBlockElementDelete', '\\Structura\\Structura\\Integration\\EntityDelete', 'onAfterIBlockElementDelete'],
        ['iblock', 'OnAfterIBlockSectionDelete', '\\Structura\\Structura\\Integration\\EntityDelete', 'onAfterIBlockSectionDelete'],
    ];

    /**
     * Регистрирует обработчики событий модуля.
     *
     * Идемпотентно: регистрация идёт INSERT IGNORE по уникальному ключу
     * обработчика, поэтому повторная установка дублей не плодит.
     */
    public function InstallEvents(): bool
    {
        foreach (self::EVENTS as [$fromModule, $event, $class, $method]) {
            RegisterModuleDependences($fromModule, $event, $this->MODULE_ID, $class, $method);
        }

        return true;
    }

    public function UnInstallEvents(): bool
    {
        foreach (self::EVENTS as [$fromModule, $event, $class, $method]) {
            UnRegisterModuleDependences($fromModule, $event, $this->MODULE_ID, $class, $method);
        }

        return true;
    }

    /**
     * Сносит таблицы значений и ревизий целиком (DROP TABLE).
     *
     * DROP — операция уровня метаданных: от числа строк не зависит, миллионы
     * значений сносятся за то же время, что и десяток, таймаута тут нет.
     * Загруженные через поля модуля файлы (`b_file` с `MODULE_ID` модуля и их
     * байты на диске) при этом не удаляются — построчная чистка большого
     * каталога как раз и упёрлась бы в таймаут. Об оставшихся файлах
     * предупреждает отчёт удаления (step_uninstall.php).
     */
    public function UnInstallDB(): bool
    {
        require_once __DIR__ . '/../include.php';

        $connection = Application::getConnection();
        foreach ([
            \Structura\Structura\Storage\ValueTable::getTableName(),
            \Structura\Structura\Storage\RevisionTable::getTableName(),
        ] as $table) {
            if ($connection->isTableExists($table)) {
                $connection->dropTable($table);
            }
        }

        return true;
    }

    /**
     * Разворачивает в /local/php_interface/structura/ шаблон настроек и
     * каталог блоков (только если их ещё нет — пользовательские данные не
     * трогаем) и публикует css/js в веб-доступный корень.
     *
     * Публикация ассетов обязательна: каталог модуля наружу не отдаётся (nginx
     * закрывает и /bitrix/modules, и local/modules), ссылка внутрь модуля даёт
     * 403. Подробности — в Integration\Assets.
     */
    public function InstallFiles(): bool
    {
        require_once __DIR__ . '/../include.php';

        $documentRoot = rtrim(Application::getDocumentRoot(), '/');
        $baseDir      = $documentRoot . '/local/php_interface/structura';
        $blocksDir    = $baseDir . '/blocks';
        $configFile   = $baseDir . '/configuration.php';

        CheckDirPath($blocksDir . '/');

        if (!file_exists($configFile)) {
            copy(__DIR__ . '/stub/configuration.php', $configFile);
        }

        return \Structura\Structura\Integration\Assets::publish(true);
    }

    /**
     * Убирает то, что установщик реально клал: опубликованные css/js, шаблон
     * настроек и пустой каркас каталогов.
     *
     * Блоки в `blocks_dir` не трогаются: установщик их туда не кладёт (примеры
     * живут в examples/ и попадают на сайт только руками разработчика), см.
     * Integration\ShippedFiles. configuration.php убирается, лишь пока не тронут.
     */
    public function UnInstallFiles(): bool
    {
        require_once __DIR__ . '/../include.php';

        \Structura\Structura\Integration\Assets::remove();

        // Отчёт показывает шаг 2: подтверждения у этого удаления нет, поэтому
        // администратор должен увидеть хотя бы постфактум, что уцелело и почему.
        $GLOBALS['STRUCTURA_UNINSTALL_FILES'] = \Structura\Structura\Integration\ShippedFiles::remove();

        return true;
    }
}
