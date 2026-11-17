<?php

namespace Structura\Structura\Validation;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Structura\Structura\Block;
use Structura\Structura\Field;
use Structura\Structura\Fields\ContainerField;
use Structura\Structura\Fields\Flexible;
use Structura\Structura\Fields\Group;

Loc::loadMessages(__FILE__);

/**
 * Рекурсивный валидатор значений блока.
 *
 * Проходит дерево полей параллельно дереву значений на любую глубину:
 *  - Repeater  → значение = массив «строк», каждая строка валидируется по
 *                набору дочерних полей;
 *  - Flexible  → значение = массив «элементов», каждый выбирает один из
 *                макетов (layout) и хранит его данные в ключе data;
 *  - контейнеры могут содержать контейнеры (Repeater ↔ Flexible) без ограничений.
 *
 * Возвращает Bitrix\Main\Result. Код каждой ошибки — путь к полю
 * ("slides.0.bg"), в customData — код правила и код поля.
 */
final class Validator
{
    public function validateBlock(Block $block, array $values): Result
    {
        $result = new Result();
        $this->validateFields($block->getFields(), $values, '', $result);

        return $result;
    }

    /**
     * @param Field[]             $fields
     * @param array<string,mixed> $values
     */
    private function validateFields(array $fields, array $values, string $prefix, Result $result): void
    {
        foreach ($fields as $field) {
            $code  = $field->getCode();
            $path  = $prefix === '' ? $code : $prefix . '.' . $code;
            $value = $values[$code] ?? null;

            $this->validateField($field, $value, $path, $result);
        }
    }

    private function validateField(Field $field, mixed $value, string $path, Result $result): void
    {
        // Собственные правила валидации поля.
        foreach ($field->getValidation() as $rule) {
            if (!$rule->passes($value, ['path' => $path])) {
                $result->addError(new Error($rule->message(), $path, [
                    'field' => $field->getCode(),
                    'rule'  => $rule->code(),
                ]));
            }
        }

        // Рекурсия в контейнеры. Group — до общей ветки: та считает значение
        // списком строк, а у группы это один объект.
        if ($field instanceof Flexible) {
            $this->validateFlexible($field, $value, $path, $result);
        } elseif ($field instanceof Group) {
            $this->validateGroup($field, $value, $path, $result);
        } elseif ($field instanceof ContainerField) {
            $this->validateRepeater($field, $value, $path, $result);
        }
    }

    /**
     * Group: значение — один объект вложенных полей, а не список строк.
     *
     * То же, что одна строка Repeater, но без индекса в пути: путь к полю
     * внутри группы выглядит как «cta.href», а не «cta.0.href».
     */
    private function validateGroup(Group $field, mixed $value, string $path, Result $result): void
    {
        $this->validateFields(
            $field->getFields(),
            is_array($value) ? $value : [],
            $path,
            $result
        );
    }

    /**
     * Repeater и прочие однотипные контейнеры: значение — массив строк.
     */
    private function validateRepeater(ContainerField $field, mixed $value, string $path, Result $result): void
    {
        $rows = is_array($value) ? $value : [];
        $this->validateItemsCount($field, count($rows), $path, $result);

        foreach ($rows as $index => $row) {
            $this->validateFields(
                $field->getFields(),
                is_array($row) ? $row : [],
                $path . '.' . $index,
                $result
            );
        }
    }

    /**
     * Flexible: значение — массив элементов вида
     * ['_layout' => '<код макета>', 'data' => <значение макета>].
     *
     * Макет — любое поле (обычно контейнер). Его значение проверяется
     * рекурсивно через validateField, поэтому макет может быть Repeater,
     * который, в свою очередь, содержит другой Flexible, и так далее.
     */
    private function validateFlexible(Flexible $field, mixed $value, string $path, Result $result): void
    {
        $items = is_array($value) ? $value : [];
        $this->validateItemsCount($field, count($items), $path, $result);

        $layouts = [];
        foreach ($field->getLayouts() as $layout) {
            $layouts[$layout->getCode()] = $layout;
        }

        foreach ($items as $index => $item) {
            $item       = is_array($item) ? $item : [];
            $itemPath   = $path . '.' . $index;
            $layoutCode = $item['_layout'] ?? null;

            if ($layoutCode === null || !isset($layouts[$layoutCode])) {
                $result->addError(new Error(
                    (string) Loc::getMessage('STRUCTURA_VALIDATOR_UNKNOWN_LAYOUT', ['#LAYOUT#' => (string) $layoutCode]),
                    $itemPath,
                    ['field' => $field->getCode(), 'rule' => 'layout']
                ));
                continue;
            }

            $this->validateField(
                $layouts[$layoutCode],
                $item['data'] ?? null,
                $itemPath . '.data',
                $result
            );
        }
    }

    private function validateItemsCount(ContainerField $field, int $count, string $path, Result $result): void
    {
        $min = $field->getSetting('min');
        $max = $field->getSetting('max');

        if ($min !== null && $count < $min) {
            $result->addError(new Error(
                (string) Loc::getMessage('STRUCTURA_VALIDATOR_MIN_ITEMS', ['#MIN#' => $min]),
                $path,
                ['field' => $field->getCode(), 'rule' => 'minItems']
            ));
        }

        if ($max !== null && $count > $max) {
            $result->addError(new Error(
                (string) Loc::getMessage('STRUCTURA_VALIDATOR_MAX_ITEMS', ['#MAX#' => $max]),
                $path,
                ['field' => $field->getCode(), 'rule' => 'maxItems']
            ));
        }
    }
}
