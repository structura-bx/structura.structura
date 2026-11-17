<?php

namespace Structura\Structura\Service;

use Structura\Structura\Field;
use Structura\Structura\Fields\ContainerField;
use Structura\Structura\Fields\Flexible;
use Structura\Structura\Fields\Group;

/**
 * Устойчивые идентификаторы строк контейнеров.
 *
 * Строка Repeater и элемент Flexible иначе адресовались бы только позицией в
 * массиве, и слияние политик сопоставляло бы старое значение с новым по индексу
 * — при перестановке строк запрещённое политикой поле получило бы значение
 * соседа. Ключ `_id` даёт строке имя, которое переживает перестановку.
 *
 * Идентификатор ставится сервером при сохранении, даже если клиент его прислал:
 * фронтенд генерирует id новым строкам сам, но пустой или потерянный id
 * восполняется здесь.
 *
 * Ветка Group стоит явно и до общей: Group — один объект, а не список строк, и
 * общая ветка проставила бы `_id` вложенным полям вместо строк (та же засада,
 * что у обхода контейнеров в Validator, CopyService, FileService, PolicyAudit).
 *
 * Значения со старых установок идентификаторов не имеют: id появляется у строки
 * при первом сохранении, а пока его нет, слияние политик сопоставляет по индексу
 * (см. PolicyGuard).
 */
final class RowId
{
    /** Ключ идентификатора внутри строки. */
    public const KEY = '_id';

    /**
     * Новый идентификатор строки.
     *
     * Случайный, а не счётчик: строки живут в JSON-значении, счётчик пришлось
     * бы где-то хранить и синхронизировать с клиентом, который тоже создаёт
     * строки. 8 байт — вероятность коллизии в пределах одного блока
     * пренебрежимо мала.
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Проставляет идентификаторы строкам, у которых их ещё нет.
     * Существующие id сохраняются — на них завязано слияние политик.
     *
     * @param Field[]             $fields поля блока (или контейнера)
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function assign(array $fields, array $values): array
    {
        return self::walkFields($fields, $values, false);
    }

    /**
     * Свежие идентификаторы для строки-копии и всего, что внутри неё.
     *
     * Копия строки обязана получить свой id: с идентификатором оригинала две
     * строки стали бы для слияния политик одной и той же, и правка одной
     * подставлялась бы в другую.
     *
     * @param Field[]             $fields поля контейнера, которому принадлежит строка
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function refreshRow(array $fields, array $row): array
    {
        return self::stamp(self::walkFields($fields, $row, true), true);
    }

    /**
     * @param Field[]             $fields
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function walkFields(array $fields, array $values, bool $force): array
    {
        foreach ($fields as $field) {
            $code = $field->getCode();
            if (!array_key_exists($code, $values)) {
                continue;
            }

            $value = $values[$code];

            if ($field instanceof Flexible) {
                $values[$code] = self::walkFlexible($field, $value, $force);
            } elseif ($field instanceof Group) {
                // Один объект, а не строка: своего id у него нет — адресуется
                // кодом поля, как обычное поле.
                $values[$code] = self::walkFields(
                    $field->getFields(),
                    is_array($value) ? $value : [],
                    $force
                );
            } elseif ($field instanceof ContainerField) {
                $values[$code] = self::walkRows($field->getFields(), $value, $force);
            }
        }

        return $values;
    }

    /**
     * @param Field[] $fields
     * @return array<int,mixed>
     */
    private static function walkRows(array $fields, mixed $value, bool $force): array
    {
        $rows = is_array($value) ? $value : [];

        foreach ($rows as $i => $row) {
            $rows[$i] = self::stamp(
                self::walkFields($fields, is_array($row) ? $row : [], $force),
                $force
            );
        }

        return $rows;
    }

    /**
     * Flexible: значение — список ['_layout' => код, 'data' => значение макета].
     * Идентификатор получает сам элемент списка, его `data` — по типу макета.
     *
     * @return array<int,mixed>
     */
    private static function walkFlexible(Flexible $field, mixed $value, bool $force): array
    {
        $layouts = [];
        foreach ($field->getLayouts() as $layout) {
            $layouts[$layout->getCode()] = $layout;
        }

        $items = is_array($value) ? $value : [];
        foreach ($items as $i => $item) {
            $item   = is_array($item) ? $item : [];
            $layout = $layouts[$item['_layout'] ?? ''] ?? null;

            if ($layout instanceof Group) {
                $item['data'] = self::walkFields(
                    $layout->getFields(),
                    is_array($item['data'] ?? null) ? $item['data'] : [],
                    $force
                );
            } elseif ($layout instanceof ContainerField) {
                $item['data'] = self::walkRows($layout->getFields(), $item['data'] ?? null, $force);
            }

            // Неизвестный макет оставляем как есть: это забота валидатора. Но id
            // элементу ставим всё равно — иначе строка с опечаткой в макете
            // навсегда осталась бы безымянной.
            $items[$i] = self::stamp($item, $force);
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function stamp(array $row, bool $force): array
    {
        $current = $row[self::KEY] ?? null;

        if ($force || !is_string($current) || $current === '') {
            $row[self::KEY] = self::generate();
        }

        return $row;
    }
}
