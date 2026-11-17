<?php

namespace Structura\Structura\Service;

use Bitrix\Main\Type\DateTime;
use Structura\Structura\Block;
use Structura\Structura\Config\Loader;
use Structura\Structura\Field;
use Structura\Structura\Fields\ContainerField;
use Structura\Structura\Fields\File as FileField;
use Structura\Structura\Fields\Flexible;
use Structura\Structura\Fields\Group;
use Structura\Structura\Fields\Image as ImageField;
use Structura\Structura\Storage\ValueTable;

/**
 * Копирование значений элемента инфоблока.
 *
 * Что делать с файлами — глобальная настройка `copy_files` в configuration.php
 * ({@see \Structura\Structura\Config\Loader::copyFilesMode}):
 *
 *  - `reference` (умолчание) — копия ссылается на те же файлы, что и оригинал:
 *    значение переносится как есть, байты не дублируются. Экономит диск, но файл
 *    общий на два элемента. Удаление такого файла на копии (или оригинале)
 *    сносит байты для обоих: {@see FileService::delete} проверяет использование
 *    файла лишь в пределах одной сущности (перебор по всей таблице запрещён), а
 *    ядро счётчиком ссылок разные значения с одним ID не связывает.
 *  - `duplicate` — копия получает свои файлы (CFile::CopyFile), независимые от
 *    оригинала: удаление файла на копии оригинал не трогает. Дороже по диску.
 *    Внутри одного элемента файл, общий у нескольких языков/полей, дублируется
 *    ровно раз (кэш $fileMap) — копия сохраняет общий файл между своими языками.
 *
 * Заполнение перевода из другого языка и клонирование строки Repeater/Flexible
 * файлы не копируют никогда (независимо от режима): значения остаются на том же
 * файле одной сущности.
 *
 * Какие поля файловые, определяется по конфигу блока: значения обходятся
 * рекурсивно вместе с деревом полей. Gallery не трогаем — там значение это путь
 * к общему файлу сайта, дублировать нечего.
 */
class CopyService
{
    /**
     * Копирует значения Structura с одного элемента инфоблока на другой.
     *
     * История ревизий не переносится: копия — новый элемент, её журнал
     * начинается с нуля. Иначе в снапшотах копии остались бы ID файлов
     * оригинала, и откат привязал бы копию к чужим файлам.
     *
     * Значения переносятся как есть, без валидации и политик: это системное
     * копирование, а не правка. Иначе копия элемента, который стал невалидным
     * после смены конфига, молча потеряла бы часть значений.
     *
     * @return int сколько экземпляров блоков скопировано
     */
    public function copyElement(int $sourceId, int $targetId): int
    {
        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
            return 0;
        }

        $rows = ValueTable::getList([
            'filter' => [
                '=ENTITY_TYPE' => ValueTable::ENTITY_ELEMENT,
                '=ENTITY_ID'   => $sourceId,
            ],
            'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
        ])->fetchAll();

        // Уже имеющиеся у цели строки (instanceId|LANG) — чтобы не падать на
        // уникальном индексе при повторном вызове обработчика.
        $existing = [];
        foreach (ValueTable::getList([
            'filter' => ['=ENTITY_TYPE' => ValueTable::ENTITY_ELEMENT, '=ENTITY_ID' => $targetId],
            'select' => ['INSTANCE_ID', 'LANG'],
        ])->fetchAll() as $has) {
            $existing[(string) $has['INSTANCE_ID'] . "\0" . (string) $has['LANG']] = true;
        }

        // Режим обращения с файлами глобальный (configuration.php → copy_files).
        // duplicate — копия получает свои файлы; reference (умолчание) — те же.
        $duplicate = Loader::copyFilesMode() === Loader::COPY_FILES_DUPLICATE;

        // Карта sourceFileId → newFileId на весь элемент, общая для всех
        // языковых строк. Без неё файл, общий у базы и перевода (prefill его не
        // разводит), копировался бы по разу на язык — N копий вместо одной, и у
        // копии языки перестали бы делить файл. С картой один исходный файл даёт
        // одну новую копию, разделяемую всеми языками копии, как у оригинала.
        $fileMap = [];

        $copiedInstances = [];
        foreach ($rows as $row) {
            $block = Loader::load((string) $row['BLOCK_CODE']);
            if ($block === null) {
                // Конфига блока больше нет: какие поля файловые — неизвестно,
                // а копировать ID файлов вслепую значит завести двух владельцев
                // одного файла. Пропускаем.
                continue;
            }

            $instanceId = (string) $row['INSTANCE_ID'];
            $lang       = (string) $row['LANG'];
            if (isset($existing[$instanceId . "\0" . $lang])) {
                continue;
            }

            $values = json_decode((string) $row['VALUE'], true);
            $values = is_array($values) ? $values : [];

            // reference: значение как есть, копия ссылается на те же файлы.
            // duplicate: файлы дублируются (с дедупом по $fileMap между языками).
            // Метаданные (SORT) переносим — порядок и зеркаление сохраняются;
            // активность у копии — по умолчанию (Y), одинаково для всех языков.
            $encoded = $duplicate
                ? $this->cloneFieldValues($block->getFields(), $values, $fileMap)
                : $values;

            $now = new DateTime();
            ValueTable::add([
                'BLOCK_CODE'  => (string) $row['BLOCK_CODE'],
                'ENTITY_TYPE' => ValueTable::ENTITY_ELEMENT,
                'ENTITY_ID'   => $targetId,
                'INSTANCE_ID' => $instanceId,
                'LANG'        => $lang,
                'SORT'        => (int) $row['SORT'],
                'VALUE'       => json_encode($encoded, JSON_UNESCAPED_UNICODE),
                'CREATED_AT'  => $now,
                'UPDATED_AT'  => $now,
            ]);

            $copiedInstances[$instanceId] = true;
        }

        return count($copiedInstances);
    }

    /**
     * Копия значений набора полей с дублированием всех файлов.
     *
     * $fileMap (по ссылке) — общий на весь копируемый элемент кэш
     * sourceFileId → newFileId: один исходный файл даёт одну новую копию, даже
     * если встречается в нескольких языковых строках или полях. Без общего кэша
     * общий файл размножался бы по копии на каждое вхождение.
     *
     * @param Field[]             $fields
     * @param array<string,mixed> $values
     * @param array<int,int>      $fileMap
     * @return array<string,mixed>
     */
    public function cloneFieldValues(array $fields, array $values, array &$fileMap = []): array
    {
        // Длинное замыкание с use(&$fileMap): стрелочная функция захватила бы
        // $fileMap по значению, и дедуп между языковыми строками не работал бы —
        // каждая строка копировала бы файл заново (N копий вместо одной).
        return $this->walkFiles($fields, $values, function (mixed $value) use (&$fileMap): mixed {
            return $this->copyFile($value, $fileMap);
        });
    }

    /**
     * ID файлов, на которые ссылается значение.
     *
     * Тем же обходом, что и копирование: «какие поля файловые» знает конфиг, а
     * не JSON — число 12 могло лежать в тексте или в поле User.
     *
     * @param Field[]             $fields
     * @param array<string,mixed> $values
     * @return array<int, int>
     */
    public function collectFileIds(array $fields, array $values): array
    {
        $ids = [];

        $this->walkFiles($fields, $values, static function (mixed $value) use (&$ids): mixed {
            $id = (int) $value;
            if ($id > 0 && (string) $id === trim((string) $value)) {
                $ids[$id] = true;
            }

            return $value;
        });

        return array_keys($ids);
    }

    /**
     * Обход дерева полей с обработкой файловых значений.
     *
     * Единый обход контейнеров используют и копирование элемента, и сбор ID
     * файлов. Это сохраняет одинаковую обработку `Group` в обеих операциях.
     *
     * @param Field[]             $fields
     * @param array<string,mixed> $values
     * @param callable(mixed):mixed $handle что делать со значением файлового поля
     * @return array<string,mixed>
     */
    private function walkFiles(array $fields, array $values, callable $handle): array
    {
        $out = $values;

        foreach ($fields as $field) {
            $code = $field->getCode();
            if (!array_key_exists($code, $out)) {
                continue;
            }

            $value = $out[$code];

            if ($field instanceof Flexible) {
                $out[$code] = $this->walkFlexible($field, $value, $handle);
            } elseif ($field instanceof Group) {
                // Group хранит один объект, а не список строк, поэтому
                // обрабатывается до общей ветки контейнеров.
                $out[$code] = $this->walkFiles(
                    $field->getFields(),
                    is_array($value) ? $value : [],
                    $handle
                );
            } elseif ($field instanceof ContainerField) {
                $rows = is_array($value) ? $value : [];
                foreach ($rows as $i => $row) {
                    $rows[$i] = $this->walkFiles($field->getFields(), is_array($row) ? $row : [], $handle);
                }
                $out[$code] = $rows;
            } elseif ($field instanceof ImageField || $field instanceof FileField) {
                $out[$code] = $handle($value);
            }
        }

        return $out;
    }

    /**
     * Flexible: значение — список ['_layout' => код, 'data' => значение макета].
     *
     * @param callable(mixed):mixed $handle
     * @return array<int, mixed>
     */
    private function walkFlexible(Flexible $field, mixed $value, callable $handle): array
    {
        $layouts = [];
        foreach ($field->getLayouts() as $layout) {
            $layouts[$layout->getCode()] = $layout;
        }

        $items = is_array($value) ? $value : [];
        foreach ($items as $i => $item) {
            $item   = is_array($item) ? $item : [];
            $layout = $layouts[$item['_layout'] ?? ''] ?? null;

            // Макет — это контейнер, поэтому его данные обходятся его полями.
            if ($layout instanceof ContainerField) {
                $rows = is_array($item['data'] ?? null) ? $item['data'] : [];
                foreach ($rows as $j => $row) {
                    $rows[$j] = $this->walkFiles($layout->getFields(), is_array($row) ? $row : [], $handle);
                }
                $item['data'] = $rows;
            }

            $items[$i] = $item;
        }

        return $items;
    }

    /**
     * Дубль файла по ID с дедупом через общий кэш $fileMap. Тот же исходный файл
     * (в другом языке или поле того же элемента) отдаёт ту же новую копию, а не
     * копируется заново. Пустое/мусорное значение отдаём как есть: копия не место
     * чинить чужие данные — их отвергнет валидация при сохранении.
     *
     * @param array<int,int> $fileMap sourceFileId → newFileId (по ссылке)
     */
    private function copyFile(mixed $value, array &$fileMap): mixed
    {
        $id = (int) $value;
        if ($id <= 0 || (string) $id !== trim((string) $value)) {
            return $value;
        }

        if (isset($fileMap[$id])) {
            return $fileMap[$id];
        }

        $copy = \CFile::CopyFile($id);
        if (!$copy) {
            return $value;
        }

        return $fileMap[$id] = (int) $copy;
    }
}
