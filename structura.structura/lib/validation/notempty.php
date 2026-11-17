<?php

namespace Structura\Structura\Validation;

use Bitrix\Main\Localization\Loc;
use Structura\Structura\Validation;

Loc::loadMessages(__FILE__);

/**
 * Значение не должно быть пустым в смысле PHP empty():
 * пустая строка, 0, "0", null, пустой массив и false считаются невалидными.
 */
class NotEmpty extends Validation
{
    public function passes(mixed $value, array $context = []): bool
    {
        $check = is_string($value) ? trim($value) : $value;

        return !empty($check);
    }

    public function message(): string
    {
        return (string) Loc::getMessage('STRUCTURA_NOTEMPTY_MESSAGE');
    }

    public function code(): string
    {
        return 'notEmpty';
    }
}
