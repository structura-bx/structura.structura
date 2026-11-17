<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;
use Structura\Structura\Traits\HasOptions;

/**
 * Выпадающий список.
 *
 * Обычный режим — один выбранный вариант, значение строка (или пусто). С
 * `->multiple()` — нативный `<select multiple>`: несколько вариантов, значение —
 * массив отмеченных ключей (тот же тип, что у группы Checkbox):
 *
 *   Select('Регионы', 'regions')->options([...])->multiple()
 *
 * Множественность объявлена здесь, а не в трейте HasOptions: у Radio выбор
 * всегда один, а у Checkbox несколько выражают варианты.
 */
class Select extends Field
{
    use HasOptions;

    protected bool $multiple = false;

    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;
        $this->settings['multiple'] = $multiple;

        return $this;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /**
     * Умолчание множественного списка приводит к массиву (одиночного — как есть).
     * Правило совпадает с клиентским (defaultForField в structura.js), иначе
     * новый и сохранённый экземпляры читались бы с разными типами значения.
     */
    public function normalizeDefault(): mixed
    {
        if (!$this->multiple) {
            return $this->getDefault();
        }

        $default = $this->getDefault();
        if ($default === null) {
            return [];
        }

        return is_array($default) ? array_values($default) : [$default];
    }

    /**
     * Пустое значение: множественный список — массив, одиночный — пустая строка
     * (без настроенного умолчания, см. Field::emptyValue).
     */
    public function emptyValue(): mixed
    {
        return $this->multiple ? [] : '';
    }
}
