<?php

namespace Structura\Structura\Service;

use Structura\Structura\Config\Loader;
use Structura\Structura\Storage\RevisionTable;
use Structura\Structura\Storage\ValueTable;

/**
 * Уборка значений Structura, когда удаляют саму сущность (элемент, раздел).
 *
 * Значения адресуются парой (ENTITY_TYPE, ENTITY_ID) и внешним ключом к
 * `b_iblock_element` не связаны: удалив элемент, ядро о них не знает и знать не
 * может. Без этой уборки после удаления элемента в БД навсегда оставались бы
 * значения, переводы, вся история ревизий и загруженные файлы — невидимые
 * (сущности, на которой их показать, больше нет) и неудаляемые из интерфейса.
 *
 * Идентификаторы у элементов сквозные и не переиспользуются, поэтому чужой
 * элемент такие остатки не унаследует. Это утечка места и мусор в выборках,
 * а не порча данных.
 *
 * Экземпляры сносятся штатным {@see ValueService::delete}, по одному: он же
 * рассылает события полей `<блок>:<поле>:delete`, чистит переводы и историю.
 * Обработчик, слушающий поле, обязан узнать об удалении элемента так же, как
 * узнаёт об очистке блока руками.
 *
 * Файлы удаляются, но только свои — записи `b_file` с `MODULE_ID` модуля.
 * Значение файлового поля можно вписать руками (у поля есть ввод ID), и по
 * такому ID легко указать на картинку элемента из другого инфоблока или на
 * файл соседнего модуля: удалять чужое при удалении элемента нельзя.
 */
final class EntityPurge
{
    /**
     * Сносит всё, что модуль хранит для сущности.
     *
     * @return array{instances: int, files: int} сколько экземпляров и файлов убрано
     */
    public function purge(string $entityType, int $entityId): array
    {
        if ($entityId <= 0) {
            return ['instances' => 0, 'files' => 0];
        }

        // Файлы собираем до удаления строк: после них ходить не по чему —
        // какие поля файловые, знает конфиг, а какие ID в них лежат, знало
        // только удалённое значение.
        $files = $this->collectFiles($entityType, $entityId);

        $values    = new ValueService();
        $instances = 0;

        foreach ($values->listInstances($entityType, $entityId) as $instance) {
            // fireBlockEvents=false: сущность уже удаляется, и вето обработчика
            // onBlockBeforeDelete здесь оставило бы значения-сиротами. События
            // полей `:delete` идут независимо — обработчик поля об удалении узнает.
            $values->delete($instance['blockCode'], $entityType, $entityId, $instance['instanceId'], false);
            $instances++;
        }

        // Ревизии, до которых listInstances не дотягивается: история экземпляра,
        // чьи значения пропали при прерванном удалении. Значения удалены выше по
        // экземплярам; чистим осиротевшую историю.
        $this->sweep(RevisionTable::class, $entityType, $entityId);

        return ['instances' => $instances, 'files' => $this->deleteFiles($files)];
    }

    /**
     * ID файлов, на которые ссылаются значения сущности — живые и в снапшотах
     * истории.
     *
     * Ревизии тоже: файл, заменённый в поле, живёт дальше только в снапшотах
     * (кнопка «×» отвязывает ссылку, но байты не трогает — их сносит лишь явное
     * удаление файла). Вместе с сущностью уходит и её история, поэтому после
     * неё такой файл не нужен уже никому.
     *
     * Перебор значений одной сущности по индексу — то же, что делает
     * {@see FileService::isUsedBy}; поиска по содержимому JSON здесь нет.
     *
     * @return array<int, int>
     */
    private function collectFiles(string $entityType, int $entityId): array
    {
        $copier = new CopyService();
        $ids    = [];

        foreach ([ValueTable::class, RevisionTable::class] as $table) {
            $rows = $table::query()
                ->setSelect(['BLOCK_CODE', 'VALUE'])
                ->where('ENTITY_TYPE', $entityType)
                ->where('ENTITY_ID', $entityId)
                ->fetchAll();

            foreach ($rows as $row) {
                $block = Loader::load((string) $row['BLOCK_CODE']);
                if ($block === null) {
                    // Конфига блока нет — какие поля файловые, неизвестно, а
                    // числа из JSON могли быть чем угодно. Строку всё равно
                    // удалим, файлы её оставим: сносить по догадке нельзя.
                    continue;
                }

                $values = json_decode((string) $row['VALUE'], true);
                if (!is_array($values)) {
                    continue;
                }

                foreach ($copier->collectFileIds($block->getFields(), $values) as $id) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Удаляет файлы, загруженные модулем. Чужие записи `b_file` не трогает.
     *
     * @param array<int, int> $ids
     * @return int сколько файлов отдано ядру на удаление
     */
    private function deleteFiles(array $ids): int
    {
        if ($ids === [] || !class_exists(\CFile::class)) {
            return 0;
        }

        $rows = \Bitrix\Main\FileTable::query()
            ->setSelect(['ID'])
            ->whereIn('ID', $ids)
            ->where('MODULE_ID', STRUCTURA_MODULE_ID)
            ->fetchAll();

        foreach ($rows as $row) {
            \CFile::Delete((int) $row['ID']);
        }

        return count($rows);
    }

    /**
     * Сносит остаточные строки таблицы по сущности.
     *
     * @param class-string $table
     */
    private function sweep(string $table, string $entityType, int $entityId): void
    {
        $rows = $table::query()
            ->setSelect(['ID'])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->fetchAll();

        foreach ($rows as $row) {
            $table::delete($row['ID']);
        }
    }
}
