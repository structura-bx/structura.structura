<?php

namespace Structura\Structura\Integration;

use Bitrix\Main\Loader;
use Structura\Structura\Service\EntityPurge;
use Structura\Structura\Storage\ValueTable;

/**
 * Уборка значений Structura при удалении элемента и раздела инфоблока.
 *
 * Цепляемся к событиям после удаления (`OnAfterIBlockElementDelete`,
 * `OnAfterIBlockSectionDelete`), а не к «до»: событие «до» умеет отменить
 * удаление, и вычистив значения там, мы потеряли бы их у выжившего элемента.
 * «После» ядро зовёт только на удавшемся удалении.
 *
 * Каскад ядро разбирает само: удаление раздела и всего инфоблока идёт через те
 * же `CIBlockSection::Delete`/`CIBlockElement::Delete`, поэтому события приходят
 * на каждую сущность и отдельного обработчика для инфоблока не нужно.
 *
 * Обработчики надо зарегистрировать: на уже установленном модуле это
 * `(new structura_structura())->InstallEvents()` (идемпотентно). Без регистрации
 * значения удалённых элементов остаются в БД.
 */
class EntityDelete
{
    /**
     * Обработчик iblock:OnAfterIBlockElementDelete.
     *
     * Ядро передаёт строку удалённого элемента (`ID`, `IBLOCK_ID` и ещё
     * несколько полей), а не один лишь идентификатор.
     *
     * @param array<string, mixed> $fields поля удалённого элемента
     */
    public static function onAfterIBlockElementDelete(array $fields): void
    {
        self::purge(ValueTable::ENTITY_ELEMENT, (int) ($fields['ID'] ?? 0));
    }

    /**
     * Обработчик iblock:OnAfterIBlockSectionDelete.
     *
     * @param array<string, mixed> $fields поля удалённого раздела
     */
    public static function onAfterIBlockSectionDelete(array $fields): void
    {
        self::purge(ValueTable::ENTITY_SECTION, (int) ($fields['ID'] ?? 0));
    }

    private static function purge(string $entityType, int $entityId): void
    {
        if ($entityId <= 0) {
            return;
        }

        if (!Loader::includeModule(STRUCTURA_MODULE_ID)) {
            return;
        }

        // Уборка идёт по одной строке на экземпляр и поднимает события полей
        // `:delete` — чужой обработчик такого события волен бросить. Пробившись
        // наружу, его исключение оборвало бы удаление элемента и остальные
        // обработчики ядра; глушим на границе (см. EventGuard).
        EventGuard::run('EntityDelete::purge', static function () use ($entityType, $entityId): void {
            (new EntityPurge())->purge($entityType, $entityId);
        });
    }
}
