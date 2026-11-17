<?php

namespace Structura\Structura\Validation;

use Bitrix\Main\Localization\Loc;
use Structura\Structura\Validation;

Loc::loadMessages(__FILE__);

/**
 * Значение обязано присутствовать (не null и не отсутствующий ключ).
 * В отличие от NotEmpty, строка "0" или число 0 считаются заполненными.
 */
class Required extends Validation
{
    public function passes(mixed $value, array $context = []): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    public function message(): string
    {
        return (string) Loc::getMessage('STRUCTURA_REQUIRED_MESSAGE');
    }

    public function code(): string
    {
        return 'required';
    }
}
