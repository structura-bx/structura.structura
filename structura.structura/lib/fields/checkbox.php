<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;
use Structura\Structura\Traits\HasOptions;

/**
 * Флажок. Режим задают варианты (`->options()`).
 *
 * Без вариантов — один переключатель «да/нет» со значением `'Y'` или `'N'`:
 *
 *   Checkbox('Показывать', 'visible')->default('Y')
 *
 * С вариантами — группа флажков, значение — массив отмеченных ключей:
 *
 *   Checkbox('Рассылки', 'subs')->options(['news' => 'Новости', 'promo' => 'Акции'])
 *
 * Тип значения зависит от режима: строка у одиночного флажка, массив у группы.
 * Множественности (`->multiple()`) у поля нет — в режиме группы значение уже
 * массив, а в остальных случаях несколько значений даёт контейнер (Repeater).
 */
class Checkbox extends Field
{
    use HasOptions;

    /** У флажка варианты необязательны — в отличие от Radio и Select. */
    public function optionsRequired(): bool
    {
        return false;
    }

    /**
     * Умолчание одиночного флажка приводит к 'Y'/'N'. Правило совпадает с
     * клиентским (defaultForField в structura.js), иначе новый и сохранённый
     * экземпляры читались бы с разными значениями.
     */
    public function normalizeDefault(): mixed
    {
        if ($this->getOptions() !== []) {
            return $this->getDefault();
        }

        return self::isOn($this->getDefault()) ? 'Y' : 'N';
    }

    /**
     * Пустое значение: группа — пустой массив, одиночный флажок — 'N'. Совпадает
     * с normalizeDefault без настроенного умолчания (см. Field::emptyValue).
     */
    public function emptyValue(): mixed
    {
        return $this->getOptions() !== [] ? [] : 'N';
    }

    /** Значение одиночного флажка как булево — для читающего кода. */
    public static function isOn(mixed $value): bool
    {
        return $value === 'Y' || $value === true || $value === 1 || $value === '1';
    }
}
