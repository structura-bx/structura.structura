<?php

namespace Structura\Structura\Validation;

use Bitrix\Main\Localization\Loc;
use Structura\Structura\Validation;

Loc::loadMessages(__FILE__);

/**
 * Числовой диапазон: от `min` до `max` с шагом `step`.
 *
 *     Number('Скидка, %', 'discount')->setValidation(Range(0, 100, 5))
 *
 * Три ограничения в одном правиле: у `<input type="number">` они идут вместе, а
 * шаг отсчитывается от минимума (см. matchesStep). Нечисловое значение — ошибка
 * правила (до сервера оно доедет и мимо формы). Пустое значение пропускается:
 * обязательность — дело `Required`.
 */
class Range extends Validation
{
    public function __construct(
        private int|float|null $min = null,
        private int|float|null $max = null,
        private int|float|null $step = null
    ) {
    }

    public function passes(mixed $value, array $context = []): bool
    {
        if ($value === null || $value === '' || is_array($value)) {
            return true;
        }

        if (!is_numeric($value)) {
            return false;
        }

        $number = (float) $value;

        if ($this->min !== null && $number < $this->min) {
            return false;
        }

        if ($this->max !== null && $number > $this->max) {
            return false;
        }

        return $this->matchesStep($number);
    }

    public function message(): string
    {
        $parts = [];

        if ($this->min !== null && $this->max !== null) {
            $parts[] = Loc::getMessage('STRUCTURA_RANGE_BETWEEN', [
                '#MIN#' => $this->num($this->min),
                '#MAX#' => $this->num($this->max),
            ]);
        } elseif ($this->min !== null) {
            $parts[] = Loc::getMessage('STRUCTURA_RANGE_MIN', ['#MIN#' => $this->num($this->min)]);
        } elseif ($this->max !== null) {
            $parts[] = Loc::getMessage('STRUCTURA_RANGE_MAX', ['#MAX#' => $this->num($this->max)]);
        } else {
            $parts[] = Loc::getMessage('STRUCTURA_RANGE_ANY');
        }

        if ($this->step !== null) {
            $parts[] = Loc::getMessage('STRUCTURA_RANGE_STEP', ['#STEP#' => $this->num($this->step)]);
        }

        return Loc::getMessage('STRUCTURA_RANGE_REQUIRED') . ' ' . implode(' ', $parts);
    }

    public function params(): array
    {
        return ['min' => $this->min, 'max' => $this->max, 'step' => $this->step];
    }

    /**
     * Шаг отсчитывается от минимума, как у `<input type="number">`: при
     * `min=1, step=5` допустимы 1, 6, 11, а не 5, 10, 15. Сравнение с допуском,
     * а не точное: дробный шаг в double даёт погрешность (0.3 / 0.1 =
     * 2.9999999999999996), и точная проверка остатка отвергала бы верные числа.
     */
    private function matchesStep(float $number): bool
    {
        if ($this->step === null || (float) $this->step <= 0.0) {
            return true;
        }

        $step  = (float) $this->step;
        $base  = (float) ($this->min ?? 0);
        $steps = ($number - $base) / $step;

        return abs($steps - round($steps)) < 1e-9;
    }

    /** Число в текст без хвоста «5.0» у целых. */
    private function num(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0';
    }
}
