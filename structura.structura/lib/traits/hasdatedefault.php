<?php

namespace Structura\Structura\Traits;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Умолчание поля даты/времени в формате, который понимает браузер.
 *
 * `<input type="date|datetime-local|time">` показывает только своё значение
 * (`Y-m-d`, `Y-m-d\TH:i`, `H:i`); любая другая запись для него пустая строка.
 * Поэтому умолчание приводим к формату при задании, а не при показе — тогда
 * одно и то же значение видят и форма (Field::toArray), и подстановка умолчаний
 * на сервере (ValueService::getWithDefaults), и клиентский blankRow.
 *
 * Принимается что угодно, понятное strtotime, и объекты даты. Нераспознанное —
 * исключение (загрузчик ловит его и показывает в диагностике конфигурации, форму
 * не роняет). Относительные записи («now», «+1 day») вычисляются при загрузке
 * конфига, то есть на каждый запрос заново.
 */
trait HasDateDefault
{
    /** Формат значения, который понимает соответствующий <input>. */
    abstract protected function inputFormat(): string;

    public function default(mixed $value): static
    {
        return parent::default($this->normalizeDateValue($value));
    }

    private function normalizeDateValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($this->inputFormat());
        }

        // Bitrix\Main\Type\Date и DateTime: свой format() с тем же смыслом.
        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format($this->inputFormat());
        }

        $timestamp = is_int($value) ? $value : strtotime((string) $value);

        if ($timestamp === false) {
            throw new \RuntimeException((string) Loc::getMessage('STRUCTURA_HASDATEDEFAULT_BAD_VALUE', [
                '#NAME#'   => $this->getName(),
                '#CODE#'   => $this->getCode(),
                '#VALUE#'  => (string) $value,
                '#FORMAT#' => $this->inputFormat(),
            ]));
        }

        return date($this->inputFormat(), $timestamp);
    }
}
