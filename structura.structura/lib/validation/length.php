<?php

namespace Structura\Structura\Validation;

use Bitrix\Main\Localization\Loc;
use Structura\Structura\Validation;

Loc::loadMessages(__FILE__);

/**
 * Длина строки: от `min` до `max` символов.
 *
 *     Text('Заголовок', 'title')->setValidation(Length(3, 60))
 *
 * Граница может быть одна: `Length(3)` — «не короче трёх», `Length(null, 60)` —
 * «не длиннее шестидесяти». Длина считается в символах (`mb_strlen`), а не в
 * байтах. Пустое значение пропускается — обязательность выражает `Required`.
 */
class Length extends Validation
{
    public function __construct(
        private ?int $min = null,
        private ?int $max = null
    ) {
    }

    public function passes(mixed $value, array $context = []): bool
    {
        if ($value === null || $value === '' || is_array($value)) {
            return true;
        }

        $length = mb_strlen((string) $value);

        if ($this->min !== null && $length < $this->min) {
            return false;
        }

        return !($this->max !== null && $length > $this->max);
    }

    public function message(): string
    {
        if ($this->min !== null && $this->max !== null) {
            return (string) Loc::getMessage('STRUCTURA_LENGTH_BETWEEN', [
                '#MIN#' => $this->min,
                '#MAX#' => $this->max,
            ]);
        }

        if ($this->min !== null) {
            return (string) Loc::getMessage('STRUCTURA_LENGTH_MIN', ['#MIN#' => $this->min]);
        }

        return (string) Loc::getMessage('STRUCTURA_LENGTH_MAX', ['#MAX#' => (int) $this->max]);
    }

    public function params(): array
    {
        return ['min' => $this->min, 'max' => $this->max];
    }
}
