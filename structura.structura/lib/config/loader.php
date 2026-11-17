<?php

namespace Structura\Structura\Config;

use Bitrix\Main\Localization\Loc;
use Structura\Structura\Block;
use Structura\Structura\BlockGroup;

Loc::loadMessages(__FILE__);

/**
 * Загрузчик конфигурации блоков.
 *
 * Настройки модуля лежат в /local/php_interface/structura/configuration.php,
 * сами блоки — в подпапках каталога blocks (по умолчанию
 * /local/php_interface/structura/blocks/<CODE>/config.php).
 *
 * Конфигурация не хранится в БД — она полностью описывается файлами.
 */
final class Loader
{
    private const CONFIG_RELATIVE  = '/local/php_interface/structura/configuration.php';
    private const DEFAULT_BLOCKS    = '/local/php_interface/structura/blocks/';

    /**
     * Режимы `copy_files` — что делать с файлами при копировании элемента.
     *
     * `reference` (умолчание): копия ссылается на те же файлы, что и оригинал
     * (общий ID, байты не дублируются). `duplicate`: копия получает свои файлы
     * (CFile::CopyFile), независимые от оригинала. Подробнее — в
     * {@see \Structura\Structura\Service\CopyService}.
     */
    public const COPY_FILES_REFERENCE = 'reference';
    public const COPY_FILES_DUPLICATE = 'duplicate';

    private static ?string $documentRoot = null;

    /** @var array<string,mixed>|null */
    private static ?array $configuration = null;

    /** @var array<string, Block> кэш загруженных блоков по коду */
    private static array $cache = [];

    /**
     * Коды блоков, которые не удалось загрузить. Нужны, чтобы не пытаться
     * загрузить (и не записывать ошибку) один и тот же битый блок повторно:
     * load() возвращает по нему null сразу, не исполняя config.php снова.
     *
     * @var array<string, true>
     */
    private static array $failed = [];

    /**
     * Диагностика битой конфигурации: блок/группа, которые не загрузились.
     * Загрузчик их пропускает (одна опечатка в одном config.php не должна
     * прятать остальные блоки и ронять админ-форму), а причину складывает сюда —
     * её показывают настройки модуля и вкладка.
     *
     * @var array<int, array{scope: string, id: string, message: string}>
     */
    private static array $errors = [];

    /**
     * Диагностика конфигурации, которая загрузилась, но написана не так, как
     * думает автор. Отдельно от errors() намеренно: там «не загрузилось и
     * пропущено», и одна общая куча сделала бы заголовок в настройках модуля
     * враньём — а по смыслу это разные новости для разных решений.
     *
     * @var array<int, array{scope: string, id: string, message: string}>
     */
    private static array $warnings = [];

    public static function setDocumentRoot(string $root): void
    {
        // Сброс идёт первым: reset() обнуляет и запомненный корень, поэтому
        // после присваивания он стёр бы только что заданное значение.
        self::reset();
        self::$documentRoot = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * Корень сайта: заданный явно, иначе выведенный из окружения.
     *
     * Результат запоминается, поэтому вывод из окружения происходит один раз.
     * Запомнить его до появления ядра — значит запомнить пустую строку и потом
     * искать блоки не там, так что reset() сбрасывает и корень тоже.
     */
    public static function getDocumentRoot(): string
    {
        if (self::$documentRoot !== null) {
            return self::$documentRoot;
        }

        if (class_exists(\Bitrix\Main\Application::class)) {
            self::$documentRoot = rtrim(\Bitrix\Main\Application::getDocumentRoot(), '/');
        } elseif (!empty($_SERVER['DOCUMENT_ROOT'])) {
            self::$documentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
        } else {
            self::$documentRoot = '';
        }

        return self::$documentRoot;
    }

    /**
     * Явно задать конфигурацию (в обход файла) — удобно для тестов.
     *
     * @param array<string,mixed> $configuration
     */
    public static function setConfiguration(array $configuration): void
    {
        self::$configuration = $configuration;
        self::$cache      = [];
        self::$failed     = [];
        self::$index      = null;
        self::$groupsMap  = [];
        self::$conflicts  = [];
        self::$errors     = [];
        self::$warnings   = [];
    }

    /** @return array<string,mixed> */
    public static function configuration(): array
    {
        if (self::$configuration !== null) {
            return self::$configuration;
        }

        $defaults = ['blocks_dir' => self::DEFAULT_BLOCKS];
        $file     = self::getDocumentRoot() . self::CONFIG_RELATIVE;

        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $defaults = array_replace($defaults, $loaded);
            }
        }

        return self::$configuration = $defaults;
    }

    /**
     * Абсолютный путь к каталогу блоков.
     *
     * По соглашению Bitrix путь вида "/local/..." считается относительным
     * корню сайта (DOCUMENT_ROOT), а не абсолютным путём файловой системы.
     * Абсолютным считается только Windows-путь (C:\...) либо путь, уже
     * начинающийся с корня сайта.
     */
    public static function blocksDir(): string
    {
        $dir     = str_replace('\\', '/', (string) (self::configuration()['blocks_dir'] ?? self::DEFAULT_BLOCKS));
        $docRoot = self::getDocumentRoot();

        if (preg_match('~^[A-Za-z]:/~', $dir)) {
            $abs = $dir;
        } elseif ($docRoot !== '' && str_starts_with($dir, $docRoot)) {
            $abs = $dir;
        } else {
            $abs = $docRoot . '/' . ltrim($dir, '/');
        }

        return rtrim($abs, '/');
    }

    /**
     * Языки контента (метки из configuration.php). Произвольные строки, с
     * языками Bitrix не сверяются. Пустой список = многоязычность выключена
     * глобально: даже ->translatable()-блок ведёт себя одноязычно (только база).
     *
     * @return string[]
     */
    public static function languages(): array
    {
        $raw = self::configuration()['languages'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        // Оставляем только непустые строки, порядок сохраняем, дубли убираем.
        $list = [];
        foreach ($raw as $lang) {
            if (is_string($lang) && $lang !== '' && !in_array($lang, $list, true)) {
                $list[] = $lang;
            }
        }

        return $list;
    }

    /**
     * Язык по умолчанию (fallback): его значения лежат в базовой таблице.
     *
     * Явно заданный в configuration.php, иначе первый из languages(), иначе ''
     * (многоязычность не настроена — база остаётся без языковой метки, как у
     * данных со старых установок).
     */
    public static function languageFallback(): string
    {
        $raw = self::configuration()['language_fallback'] ?? null;
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        return self::languages()[0] ?? '';
    }

    /**
     * Что делать с файлами при копировании элемента инфоблока (`copy_files`).
     *
     * `duplicate` — только если задан явно; любое другое значение (и отсутствие
     * ключа на старых установках) даёт безопасное умолчание `reference`: копия
     * ссылается на те же файлы, лишних байт на диске нет. Мигрировать
     * configuration.php не нужно.
     */
    public static function copyFilesMode(): string
    {
        $raw = self::configuration()['copy_files'] ?? null;

        return $raw === self::COPY_FILES_DUPLICATE
            ? self::COPY_FILES_DUPLICATE
            : self::COPY_FILES_REFERENCE;
    }

    /**
     * Индекс блоков: код (имя листовой папки) → расположение.
     * Каталог блоков может быть вложенным на любую глубину; блоком считается
     * любая папка с config.php. Код блока — плоский (имя папки), не зависит от
     * пути: перемещение/переименование группы не меняет идентичность блока в БД.
     *
     * @var array<string, array{path: string, groups: string[]}>|null
     */
    private static ?array $index = null;

    /**
     * Группы: относительный путь папки-группы → описание. Папка является
     * группой, если содержит файл `.group.php` (возвращает имя/метаданные).
     *
     * @var array<string, array{path: string, code: string, name: string, sort: int, unique: bool, parent: ?string}>
     */
    private static array $groupsMap = [];

    /**
     * Коллизии кодов: один и тот же код в разных папках дерева. Первое (в
     * лексикографическом порядке обхода) вхождение выигрывает, остальные сюда.
     *
     * @var array<int, array{code: string, path: string, existing: string}>
     */
    private static array $conflicts = [];

    /**
     * Строит индекс блоков и карту групп рекурсивным обходом каталога блоков.
     * Дешёвый: читает только структуру каталогов и `.group.php` (config.php
     * блоков не исполняется — код берётся из имени папки).
     */
    private static function buildIndex(): void
    {
        if (self::$index !== null) {
            return;
        }

        self::$index     = [];
        self::$groupsMap  = [];
        self::$conflicts  = [];
        self::$errors     = [];
        self::$warnings   = [];

        $root = self::blocksDir();
        if (is_dir($root)) {
            self::walk($root, '', []);
        }

        ksort(self::$index);
    }

    /**
     * @param string   $dir        абсолютный путь текущей папки
     * @param string   $rel        путь относительно каталога блоков ('' — корень)
     * @param string[] $groupTrail пути папок-групп (`.group.php`) над текущей
     */
    private static function walk(string $dir, string $rel, array $groupTrail): void
    {
        // Папка-группа: есть .group.php. Добавляем её в цепочку для потомков.
        // Битую группу пропускаем (readGroup вернёт null и запишет причину):
        // блоки внутри такой папки всё равно найдутся, просто без этой группы в
        // родословной.
        if ($rel !== '' && is_file($dir . '/.group.php')) {
            $group = self::readGroup($dir . '/.group.php', $rel, $groupTrail);
            if ($group !== null) {
                self::$groupsMap[$rel] = $group;
                $groupTrail            = array_merge($groupTrail, [$rel]);
            }
        }

        // Папка-блок: есть config.php. Код = имя листовой папки (плоский).
        if ($rel !== '' && is_file($dir . '/config.php')) {
            $code = basename($rel);
            if (isset(self::$index[$code])) {
                // Готовый текст кладём рядом со структурой: конфликт показывают
                // Два экрана (вкладка формы и настройки модуля), и формулировать
                // его дважды — верный способ разойтись в объяснениях.
                self::$conflicts[] = [
                    'code'     => $code,
                    'path'     => $rel,
                    'existing' => self::$index[$code]['path'],
                    'message'  => Loc::getMessage('STRUCTURA_LOADER_BLOCK_CODE_CONFLICT', [
                        '#FOLDER#'   => $rel,
                        '#CODE#'     => $code,
                        '#EXISTING#' => self::$index[$code]['path'],
                    ]),
                ];
            } else {
                self::$index[$code] = ['path' => $rel, 'groups' => $groupTrail];
            }
        }

        // Рекурсия вглубь (пропускаем скрытые/служебные каталоги: .git и т.п.).
        $entries = scandir($dir) ?: [];
        sort($entries);
        foreach ($entries as $entry) {
            if ($entry === '' || $entry[0] === '.') {
                continue;
            }
            $sub = $dir . '/' . $entry;
            if (is_dir($sub)) {
                self::walk($sub, $rel === '' ? $entry : $rel . '/' . $entry, $groupTrail);
            }
        }
    }

    /**
     * Читает `.group.php`. Файл возвращает `BlockGroup`:
     *
     *   return (new BlockGroup('Баннеры'))->sort(60)->unique();
     *
     * Строка и массив больше не принимаются. У массива не было ни списка
     * допустимых ключей на виду, ни подсказок IDE, а опечатка в ключе значила
     * «настройки нет»; у класса имя метода проверяет сам PHP. Ошибка явная, а
     * не «взяли имя папки и поехали»: молча проигнорированная настройка
     * `unique` — это снятое ограничение, которого автор не заметит.
     *
     * Битый файл (исключение при require или неверный тип) не роняет обход:
     * причина уходит в errors(), а метод возвращает null — группа пропускается.
     *
     * @param string[] $groupTrail папки-группы над этой (для вычисления parent)
     * @return array{path: string, code: string, name: string, sort: int, unique: bool, parent: ?string}|null
     */
    private static function readGroup(string $file, string $rel, array $groupTrail): ?array
    {
        try {
            $group = require $file;
        } catch (\Throwable $e) {
            self::$errors[] = [
                'scope'   => 'group',
                'id'      => $rel,
                'message' => sprintf('%s/.group.php: %s', $rel, $e->getMessage()),
            ];

            return null;
        }

        if (!$group instanceof BlockGroup) {
            self::$errors[] = [
                'scope'   => 'group',
                'id'      => $rel,
                'message' => Loc::getMessage('STRUCTURA_LOADER_GROUP_WRONG_TYPE', ['#GROUP#' => $rel]),
            ];

            return null;
        }

        // Имя не задано — берём имя папки: у группы всегда есть, как показаться
        // в пикере.
        $name = $group->getName() !== '' ? $group->getName() : basename($rel);

        return [
            'path'   => $rel,
            'code'   => basename($rel),
            'name'   => $name,
            'sort'   => $group->getSort(),
            'unique' => $group->isUnique(),
            'parent' => $groupTrail === [] ? null : end($groupTrail),
        ];
    }

    /**
     * Коды блоков, лежащих в группе (включая вложенные группы).
     *
     * Нужно для проверки `unique`: «один блок из группы» — это про всю ветку
     * дерева, иначе ограничение обходилось бы вложенной подпапкой.
     *
     * @return string[]
     */
    public static function codesInGroup(string $groupPath): array
    {
        self::buildIndex();

        $codes = [];
        foreach (self::$index as $code => $loc) {
            if (in_array($groupPath, $loc['groups'], true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Описание блока для фронтенда: конфигурация плюс группы, в которых он
     * лежит (от внешней к внутренней).
     *
     * Группы живут в дереве каталогов и известны только загрузчику, поэтому
     * подмешиваются здесь, а не в Block::toArray(): блок про своё расположение
     * не знает и знать не должен — код у него плоский.
     *
     * @return array<string, mixed>
     */
    public static function describe(Block $block): array
    {
        return $block->toArray() + ['groups' => self::groupOf($block->getCode())];
    }

    /**
     * Символьные коды всех доступных блоков (по всему дереву каталога блоков).
     *
     * @return string[]
     */
    public static function codes(): array
    {
        self::buildIndex();

        return array_keys(self::$index);
    }

    /**
     * Загружает блок по плоскому коду. Расположение (в т.ч. вложенное) берётся
     * из индекса. Опциональный class.php с наследником Block подключается первым.
     * Код блока всегда нормализуется к имени папки (плоская идентичность).
     */
    public static function load(string $code): ?Block
    {
        if (isset(self::$cache[$code])) {
            return self::$cache[$code];
        }

        // Уже пытались и не смогли — не исполняем config.php снова и не плодим
        // дубль ошибки. Возврат null тот же, что при первой неудаче.
        if (isset(self::$failed[$code])) {
            return null;
        }

        self::buildIndex();

        $rel = self::$index[$code]['path'] ?? null;
        if ($rel === null) {
            return null;
        }

        $dir  = self::blocksDir() . '/' . $rel;
        $file = $dir . '/config.php';
        if (!is_file($file)) {
            return null;
        }

        // Любой сбой авторского config.php/class.php изолируется: причина уходит
        // в errors(), блок помечается сбойным и пропускается — иначе исключение
        // всплыло бы в CAdminTabEngine и уронило всю форму элемента.
        try {
            // Необязательный пользовательский класс блока.
            if (is_file($dir . '/class.php')) {
                require_once $dir . '/class.php';
            }

            $block = require $file;
            if (!$block instanceof Block) {
                throw new \RuntimeException(
                    (string) Loc::getMessage('STRUCTURA_LOADER_CONFIG_WRONG_TYPE')
                );
            }
        } catch (\Throwable $e) {
            self::$failed[$code] = true;
            self::$errors[]      = [
                'scope'   => 'block',
                'id'      => $code,
                'message' => Loc::getMessage('STRUCTURA_LOADER_BLOCK_ERROR', [
                    '#CODE#'  => $code,
                    '#REL#'   => $rel,
                    '#ERROR#' => $e->getMessage(),
                ]),
            ];

            return null;
        }

        // Плоский код = имя папки, авторитетно: идентичность не зависит от того,
        // что указано в config.php, и от расположения в дереве групп.
        //
        // Расхождение с кодом из config.php перезаписывается, но не молча:
        // предупреждение, а не отказ. Папка всегда побеждает и блок работает, а
        // снятый с формы блок унёс бы у контент-менеджера рабочий инструмент.
        // Совпавший код молчит: у старых config.php второй аргумент Block() —
        // легаси, и ругаться на каждый из них значило бы сыпать плашками зря.
        $declared = $block->getCode();
        if ($declared !== '' && $declared !== $code) {
            self::$warnings[] = [
                'scope'   => 'block',
                'id'      => $code,
                'message' => Loc::getMessage('STRUCTURA_LOADER_BLOCK_CODE_MISMATCH', [
                    '#CODE#'     => $code,
                    '#REL#'      => $rel,
                    '#DECLARED#' => $declared,
                ]),
            ];
        }

        $block->setCode($code);

        return self::$cache[$code] = $block;
    }

    /**
     * Все группы (папки с `.group.php`), отсортированные по sort, затем имени.
     *
     * @return array<string, array{path: string, code: string, name: string, sort: int, unique: bool, parent: ?string}>
     */
    public static function groups(): array
    {
        self::buildIndex();

        $groups = self::$groupsMap;
        uasort($groups, static fn (array $a, array $b) => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);

        return $groups;
    }

    /**
     * Хлебные крошки групп для блока — от внешней к внутренней.
     *
     * @return array<int, array{path: string, code: string, name: string, sort: int, unique: bool, parent: ?string}>
     */
    public static function groupOf(string $code): array
    {
        self::buildIndex();

        $trail = self::$index[$code]['groups'] ?? [];

        return array_values(array_map(
            static fn (string $path) => self::$groupsMap[$path],
            array_filter($trail, static fn (string $path) => isset(self::$groupsMap[$path]))
        ));
    }

    /**
     * Коллизии кодов блоков: один код (имя папки) в разных ветках дерева.
     *
     * Второй такой блок в индекс не попадает — его как будто нет: он не
     * показывается на формах, не добавляется и его значения не видны. Молчать
     * об этом нельзя, поэтому конфликты показывают и вкладка формы, и настройки
     * модуля (`message` — готовый текст для обоих).
     *
     * @return array<int, array{code: string, path: string, existing: string, message: string}>
     */
    public static function conflicts(): array
    {
        self::buildIndex();

        return self::$conflicts;
    }

    /**
     * Диагностика битой конфигурации: блоки и группы, которые не загрузились.
     * Их загрузчик пропустил, а причину показывают настройки модуля и вкладка.
     *
     * Ошибки групп собираются при построении индекса; ошибки блоков — по мере
     * загрузки (load()/all()), поэтому для полной картины сперва зовут all().
     *
     * @return array<int, array{scope: string, id: string, message: string}>
     */
    public static function errors(): array
    {
        self::buildIndex();

        return self::$errors;
    }

    /**
     * Диагностика конфигурации, которая загрузилась и работает, но написана не
     * так, как думает автор (например, код блока в config.php не совпал с
     * именем папки и был перезаписан).
     *
     * Отдельно от errors(): там «пропущено», здесь «работает, но обратите
     * внимание» — путать нельзя, решения по ним разные.
     *
     * Наполняется по мере загрузки блоков, поэтому для полной картины сперва
     * зовут all() — как и с ошибками.
     *
     * @return array<int, array{scope: string, id: string, message: string}>
     */
    public static function warnings(): array
    {
        self::buildIndex();

        return self::$warnings;
    }

    /**
     * Иерархическое дерево «группы → вложенные группы → блоки» для UI.
     * Блоки/группы без родителя-группы попадают в корень.
     *
     * @return array{groups: array<int, array>, blocks: string[]}
     */
    public static function tree(): array
    {
        self::buildIndex();

        // Узлы групп с пустыми списками детей.
        $nodes = [];
        foreach (self::$groupsMap as $path => $g) {
            $nodes[$path] = $g + ['groups' => [], 'blocks' => []];
        }

        $rootGroups = [];
        foreach ($nodes as $path => &$node) {
            $parent = $node['parent'];
            if ($parent !== null && isset($nodes[$parent])) {
                $nodes[$parent]['groups'][] = &$node;
            } else {
                $rootGroups[] = &$node;
            }
        }
        unset($node);

        // Блоки раскладываем по внутренней (последней) группе цепочки.
        $rootBlocks = [];
        foreach (self::$index as $code => $loc) {
            $trail  = $loc['groups'];
            $parent = $trail === [] ? null : end($trail);
            if ($parent !== null && isset($nodes[$parent])) {
                $nodes[$parent]['blocks'][] = $code;
            } else {
                $rootBlocks[] = $code;
            }
        }

        self::sortNodes($rootGroups);
        sort($rootBlocks);

        return ['groups' => $rootGroups, 'blocks' => $rootBlocks];
    }

    /**
     * Рекурсивно сортирует узлы групп (по sort, затем имени) и их содержимое.
     *
     * @param array<int, array> $nodes
     */
    private static function sortNodes(array &$nodes): void
    {
        usort($nodes, static fn (array $a, array $b) => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);
        foreach ($nodes as &$node) {
            sort($node['blocks']);
            self::sortNodes($node['groups']);
        }
        unset($node);
    }

    /** @return Block[] */
    public static function all(): array
    {
        $blocks = [];
        foreach (self::codes() as $code) {
            $block = self::load($code);
            if ($block !== null) {
                $blocks[$code] = $block;
            }
        }

        return $blocks;
    }

    /**
     * Блоки, доступные для конкретной сущности (учёт правил visibleOn).
     *
     * @return Block[]
     */
    public static function forEntity(
        string $entityType,
        int $entityId,
        ?int $iblockId = null,
        ?string $iblockCode = null
    ): array {
        return array_values(array_filter(
            self::all(),
            static fn (Block $block) => $block->isVisibleFor($entityType, $entityId, $iblockId, $iblockCode)
        ));
    }

    /**
     * Полный сброс состояния загрузчика.
     *
     * Корень сайта сбрасывается вместе с остальным: он тоже запомнен, и без
     * этого выведенное однажды значение пережило бы сброс — а именно от корня
     * зависит, где искать каталог блоков.
     */
    public static function reset(): void
    {
        self::$documentRoot  = null;
        self::$configuration = null;
        self::$cache      = [];
        self::$failed     = [];
        self::$index      = null;
        self::$groupsMap  = [];
        self::$conflicts  = [];
        self::$errors     = [];
        self::$warnings   = [];
    }
}
