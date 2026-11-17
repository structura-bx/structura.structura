<?php

namespace Structura\Structura;

use Structura\Structura\Service\ValueService;
use Structura\Structura\Storage\ValueTable;

/**
 * Публичное API значений блоков: чтение на сайте и запись из своего кода.
 *
 * Точка входа для шаблонов, компонентов, импорта и миграций: по элементу или
 * разделу инфоблока вернуть значения всех прикреплённых к нему блоков. Тонкая
 * обёртка над {@see ValueService}; отдаёт {@see BlockValues}, от которого идёт
 * и запись:
 *
 *   Blocks::element(42)->save('banner', ['title' => 'Лето']);
 *   Blocks::element(42)->delete('banner');
 *
 * Адресация:
 *   Blocks::element(42)                  — элемент по ID;
 *   Blocks::element('news', 'summer')    — элемент по CODE в инфоблоке (ID/CODE);
 *   Blocks::element('news', 42)          — элемент по ID со сверкой инфоблока;
 *   Blocks::section(15)                  — раздел по ID;
 *   Blocks::section('news', 'shoes')     — раздел по CODE в инфоблоке.
 * Один аргумент = ID сущности. Два аргумента = инфоблок (ID/CODE) + сущность
 * (ID или CODE): CODE уникален лишь в пределах инфоблока, поэтому для него
 * инфоблок обязателен, а числовой второй аргумент трактуется как ID и
 * проверяется на принадлежность этому инфоблоку («сущность этого инфоблока»).
 *
 * Неактивные экземпляры на сайт не отдаются: выключенные вручную и вышедшие из
 * окна дат активности отфильтрованы (см. ValueService::listInstances с
 * activeOnly). Полный список, включая выключенные, с флагами и датами — прямой
 * вызов ValueService::listInstances($type, $id).
 *
 * без кэша и без проверки прав намеренно: это доверенный серверный API. Каждый
 * вызов — свежее чтение из БД; кэширование выдачи и решение «что показывать» —
 * забота вызывающего кода (как у CIBlockElement::GetList без CHECK_PERMISSIONS).
 * Права пользователя проверяет AJAX-контроллер админки, там это другой контекст.
 *
 * По той же причине запись не применяет политики полей: они отвечают на вопрос
 * «кому из пользователей можно править поле», а у скрипта пользователя нет.
 * Валидация при этом работает — записать мусор нельзя.
 */
final class Blocks
{
    /**
     * Значения блоков элемента инфоблока.
     *
     * @param int|string      $iblockOrId инфоблок (ID/CODE) при двух аргументах,
     *                                    иначе — ID самого элемента
     * @param int|string|null $element    ID или CODE элемента внутри инфоблока
     */
    public static function element(int|string $iblockOrId, int|string|null $element = null): BlockValues
    {
        return self::of(
            ValueTable::ENTITY_ELEMENT,
            self::resolveId(ValueTable::ENTITY_ELEMENT, $iblockOrId, $element)
        );
    }

    /**
     * Значения блоков раздела (категории) инфоблока.
     *
     * @param int|string      $iblockOrId инфоблок (ID/CODE) при двух аргументах,
     *                                    иначе — ID самого раздела
     * @param int|string|null $section    ID или CODE раздела внутри инфоблока
     */
    public static function section(int|string $iblockOrId, int|string|null $section = null): BlockValues
    {
        return self::of(
            ValueTable::ENTITY_SECTION,
            self::resolveId(ValueTable::ENTITY_SECTION, $iblockOrId, $section)
        );
    }

    /**
     * Читает все экземпляры блоков сущности и собирает {@see BlockValues}.
     *
     * Один запрос на сущность (listInstances) в порядке SORT. Значения сырые, из
     * базы: config блоков здесь не читается — чтение сайта не должно зависеть от
     * того, цела ли конфигурация и грузится ли она без ошибок. Блок с битым или
     * удалённым config.php больше не прячется, а список полей и значения по
     * умолчанию — забота вызывающего кода.
     */
    private static function of(string $entityType, int $entityId): BlockValues
    {
        if ($entityId <= 0) {
            // Адрес не передаём: сущности нет, и запись от такого объекта
            // должна честно отказать, а не уйти в «сущность 0».
            return new BlockValues([]);
        }

        // activeOnly: выключенные и вышедшие из окна дат экземпляры на сайт не
        // отдаём — в этом смысл «выключить блок на время, не удаляя». Полный
        // список с флагами (для админки/отладки) — ValueService::listInstances().
        //
        // Кладём сырые значения базы в порядке SORT (его задаёт listInstances).
        // Выбор языка (->language()) происходит в BlockValues при чтении и тоже
        // без обращения к config: переводы тянутся из БД по запросу языка.
        $instances = [];
        foreach ((new ValueService())->listInstances($entityType, $entityId, null, true) as $instance) {
            $instances[] = [
                'blockCode'  => $instance['blockCode'],
                'instanceId' => $instance['instanceId'],
                'lang'       => $instance['lang'],
                'values'     => $instance['values'],
            ];
        }

        return new BlockValues($instances, $entityType, $entityId);
    }

    /**
     * Приводит адрес сущности к её ID. 0 — не нашлось/не резолвится.
     */
    private static function resolveId(string $entityType, int|string $iblockOrId, int|string|null $entity): int
    {
        // Один аргумент — это уже ID сущности. CODE без инфоблока не резолвится.
        if ($entity === null) {
            return self::asId($iblockOrId);
        }

        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return 0;
        }

        $iblockId = self::iblockId($iblockOrId);
        if ($iblockId <= 0) {
            return 0;
        }

        // Числовой второй аргумент — ID, но проверяем принадлежность инфоблоку.
        if (self::looksLikeId($entity)) {
            return self::idInIblock($entityType, $iblockId, (int) $entity);
        }

        return self::idByCode($entityType, $iblockId, (string) $entity);
    }

    /** Инфоблок по ID (число/числовая строка) или CODE. 0 — не найден. */
    private static function iblockId(int|string $iblock): int
    {
        if (self::looksLikeId($iblock)) {
            return (int) $iblock;
        }

        $row = \Bitrix\Iblock\IblockTable::getList([
            'select' => ['ID'],
            'filter' => ['=CODE' => (string) $iblock],
            'order'  => ['ID' => 'ASC'],
            'limit'  => 1,
        ])->fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    /** Возвращает $id, если сущность с ним есть в этом инфоблоке; иначе 0. */
    private static function idInIblock(string $entityType, int $iblockId, int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        $row = self::table($entityType)::getList([
            'select' => ['ID'],
            'filter' => ['=ID' => $id, '=IBLOCK_ID' => $iblockId],
            'limit'  => 1,
        ])->fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    /** ID сущности по CODE внутри инфоблока (первая по ID при неуникальном CODE). */
    private static function idByCode(string $entityType, int $iblockId, string $code): int
    {
        if ($code === '') {
            return 0;
        }

        $row = self::table($entityType)::getList([
            'select' => ['ID'],
            'filter' => ['=IBLOCK_ID' => $iblockId, '=CODE' => $code],
            'order'  => ['ID' => 'ASC'],
            'limit'  => 1,
        ])->fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    /**
     * Существует ли сущность.
     *
     * Адресация по одному аргументу (`Blocks::element(42)`) существование не
     * проверяет: для чтения это безобидно — значений просто не найдётся. А вот
     * запись по выдуманному ID завела бы значения-сироты, которые никому не
     * принадлежат и никогда не покажутся, поэтому перед записью адрес
     * проверяется (см. BlockValues::save).
     */
    public static function exists(string $entityType, int $entityId): bool
    {
        if ($entityId <= 0 || !\Bitrix\Main\Loader::includeModule('iblock')) {
            return false;
        }

        return (bool) self::table($entityType)::getList([
            'select' => ['ID'],
            'filter' => ['=ID' => $entityId],
            'limit'  => 1,
        ])->fetch();
    }

    /** ORM-таблица сущности: у элемента и раздела поля ID/IBLOCK_ID/CODE общие. */
    private static function table(string $entityType): string
    {
        return $entityType === ValueTable::ENTITY_SECTION
            ? \Bitrix\Iblock\SectionTable::class
            : \Bitrix\Iblock\ElementTable::class;
    }

    /** Значение выглядит как ID: целое или строка из одних цифр. */
    private static function looksLikeId(int|string $value): bool
    {
        return is_int($value) || ctype_digit($value);
    }

    /** Значение как положительный ID, иначе 0. */
    private static function asId(int|string $value): int
    {
        return self::looksLikeId($value) && (int) $value > 0 ? (int) $value : 0;
    }
}
