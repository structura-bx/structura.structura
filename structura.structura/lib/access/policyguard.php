<?php

namespace Structura\Structura\Access;

use Bitrix\Main\Localization\Loc;
use Structura\Structura\Block;
use Structura\Structura\Field;
use Structura\Structura\Fields\ContainerField;
use Structura\Structura\Fields\Flexible;
use Structura\Structura\Fields\Group;
use Structura\Structura\Service\RowId;

Loc::loadMessages(__FILE__);

/**
 * Применение политик к значениям при сохранении.
 *
 * Фронтенд блокирует запрещённые поля, но верить ему нельзя: ajax-запрос
 * несложно собрать руками. Поэтому решает сервер: запрещённое поле берёт старое
 * значение из БД, разрешённые — новые. Save при этом успешен, а про отклонённые
 * поля уходит предупреждение.
 *
 * Слияние идёт до валидации: иначе пользователь, которому запрещено обязательное
 * поле, не смог бы сохранить блок вообще.
 *
 * Обход повторяет Validator: Repeater — массив строк, Flexible — массив
 * элементов с `_layout` и `data`, Group — один объект, контейнеры вкладываются
 * друг в друга. Запрет на контейнере запирает всё поддерево.
 *
 * Строки контейнеров сопоставляются по устойчивому идентификатору `_id`
 * ({@see \Structura\Structura\Service\RowId}), а не по позиции: перестановка и
 * вставка строк не сдвигают соответствие значений политикам. Строка с незнакомым
 * id считается новой и получает пустое старое значение. Строка без `_id` (данные
 * со старых установок) сопоставляется по индексу — единственно возможный способ,
 * пока идентификаторы не проставлены при первом сохранении.
 */
final class PolicyGuard
{
    /** @var array<string, string> путь поля => человекочитаемая причина */
    private array $denied = [];

    /**
     * Значения, которые можно писать: запрещённые заменены старыми.
     *
     * @param array<string, mixed> $incoming что прислал клиент
     * @param array<string, mixed> $old      что лежит в БД сейчас
     * @return array<string, mixed>
     */
    public function merge(Block $block, array $incoming, array $old): array
    {
        $this->denied = [];

        // Запрет на самом блоке — запрет на всё его содержимое разом.
        if (!Policy::allows($block->getPolicy())) {
            $this->denied[''] = Policy::reason($block->getPolicy()) ?? (string) Loc::getMessage('STRUCTURA_POLICY_LOCKED');

            return $old;
        }

        return $this->mergeFields($block->getFields(), $incoming, $old, '');
    }

    /** @return array<string, string> путь => причина */
    public function denied(): array
    {
        return $this->denied;
    }

    /**
     * @param Field[]             $fields
     * @param array<string,mixed> $incoming
     * @param array<string,mixed> $old
     * @return array<string,mixed>
     */
    private function mergeFields(array $fields, array $incoming, array $old, string $prefix): array
    {
        // Отталкиваемся от присланного и подменяем только запрещённое: ключи,
        // которых нет в конфиге (старые значения, служебное), сохраняем без
        // изменений.
        $out = $incoming;

        foreach ($fields as $field) {
            $code = $field->getCode();
            $path = $prefix === '' ? $code : $prefix . '.' . $code;

            if (!Policy::allows($field->getPolicy())) {
                $this->denied[$path] = Policy::reason($field->getPolicy()) ?? (string) Loc::getMessage('STRUCTURA_POLICY_LOCKED');

                if (array_key_exists($code, $old)) {
                    $out[$code] = $old[$code];
                } else {
                    unset($out[$code]);
                }

                continue;   // поддерево заперто целиком, внутрь не идём
            }

            // Group — до общей ветки контейнера: та считает значение списком
            // строк, а у группы это один объект (см. Fields\Group).
            if ($field instanceof Flexible) {
                $out[$code] = $this->mergeFlexible($field, $incoming[$code] ?? null, $old[$code] ?? null, $path);
            } elseif ($field instanceof Group) {
                $out[$code] = $this->mergeGroup($field, $incoming[$code] ?? null, $old[$code] ?? null, $path);
            } elseif ($field instanceof ContainerField) {
                $out[$code] = $this->mergeRepeater($field, $incoming[$code] ?? null, $old[$code] ?? null, $path);
            }
        }

        return $out;
    }

    /**
     * Group: значение — один объект вложенных полей, а не список строк. Как одна
     * строка Repeater, но без индекса в пути («cta.href») и без сопоставления по
     * `_id`: группа на своём месте ровно одна.
     *
     * @param mixed $incoming
     * @param mixed $old
     * @return array<string, mixed>
     */
    private function mergeGroup(Group $field, $incoming, $old, string $path): array
    {
        return $this->mergeFields(
            $field->getFields(),
            is_array($incoming) ? $incoming : [],
            is_array($old) ? $old : [],
            $path
        );
    }

    /**
     * @param mixed $incoming
     * @param mixed $old
     * @return array<int, mixed>
     */
    private function mergeRepeater(ContainerField $field, $incoming, $old, string $path): array
    {
        $rows    = is_array($incoming) ? $incoming : [];
        $oldRows = is_array($old) ? $old : [];
        $byId    = self::indexById($oldRows);

        $out = [];
        foreach ($rows as $index => $row) {
            $row = is_array($row) ? $row : [];

            $out[$index] = $this->mergeFields(
                $field->getFields(),
                $row,
                self::oldRowFor($row, $byId, $oldRows, $index),
                $path . '.' . $index
            );
        }

        return $out;
    }

    /**
     * @param mixed $incoming
     * @param mixed $old
     * @return array<int, mixed>
     */
    private function mergeFlexible(Flexible $field, $incoming, $old, string $path): array
    {
        $items    = is_array($incoming) ? $incoming : [];
        $oldItems = is_array($old) ? $old : [];
        $byId     = self::indexById($oldItems);

        $layouts = [];
        foreach ($field->getLayouts() as $layout) {
            $layouts[$layout->getCode()] = $layout;
        }

        $out = [];
        foreach ($items as $index => $item) {
            $item       = is_array($item) ? $item : [];
            $layoutCode = $item['_layout'] ?? null;
            $out[$index] = $item;

            // Неизвестный макет — забота валидатора, он же и отругается.
            if ($layoutCode === null || !isset($layouts[$layoutCode])) {
                continue;
            }

            $layout  = $layouts[$layoutCode];
            $oldItem = self::oldRowFor($item, $byId, $oldItems, $index);

            // Старые данные берём, только если в старой строке был тот же макет:
            // иначе «старое значение» — это значения чужих полей.
            $oldData = ($oldItem['_layout'] ?? null) === $layoutCode ? ($oldItem['data'] ?? null) : null;

            $out[$index]['data'] = $this->mergeLayout($layout, $item['data'] ?? null, $oldData, $path . '.' . $index . '.data');
        }

        return $out;
    }

    /**
     * Макет Flexible — обычное поле (обычно контейнер), поэтому та же развилка,
     * что и в mergeFields, но для одного поля и без подмены по коду.
     *
     * @param mixed $incoming
     * @param mixed $old
     * @return mixed
     */
    private function mergeLayout(Field $layout, $incoming, $old, string $path)
    {
        if (!Policy::allows($layout->getPolicy())) {
            $this->denied[$path] = Policy::reason($layout->getPolicy()) ?? (string) Loc::getMessage('STRUCTURA_POLICY_LOCKED');

            return $old;
        }

        if ($layout instanceof Flexible) {
            return $this->mergeFlexible($layout, $incoming, $old, $path);
        }

        if ($layout instanceof Group) {
            return $this->mergeGroup($layout, $incoming, $old, $path);
        }

        if ($layout instanceof ContainerField) {
            return $this->mergeRepeater($layout, $incoming, $old, $path);
        }

        return $incoming;
    }

    /**
     * Старые строки по их `_id`. Безымянные (данные со старых установок) в
     * индекс не попадают — их сопоставляет oldRowFor() по позиции.
     *
     * @param array<int,mixed> $rows
     * @return array<string, array<string,mixed>>
     */
    private static function indexById(array $rows): array
    {
        $byId = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = $row[RowId::KEY] ?? null;
            // Первое вхождение выигрывает: дубль id — испорченные данные.
            if (is_string($id) && $id !== '' && !isset($byId[$id])) {
                $byId[$id] = $row;
            }
        }

        return $byId;
    }

    /**
     * Старое значение строки: по `_id`, иначе по позиции.
     *
     * Строка с идентификатором, которого в старых значениях нет, — новая, и
     * старого значения у неё нет. Откат на индекс здесь был бы багой: вставленная
     * в середину строка получила бы значение соседа в запрещённых полях.
     *
     * @param array<string,mixed>                    $row
     * @param array<string, array<string,mixed>>     $byId
     * @param array<int,mixed>                       $oldRows
     * @return array<string,mixed>
     */
    private static function oldRowFor(array $row, array $byId, array $oldRows, int $index): array
    {
        $id = $row[RowId::KEY] ?? null;

        if (is_string($id) && $id !== '') {
            return $byId[$id] ?? [];
        }

        return is_array($oldRows[$index] ?? null) ? $oldRows[$index] : [];
    }
}
