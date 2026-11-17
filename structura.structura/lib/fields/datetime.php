<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;
use Structura\Structura\Traits\HasDateDefault;

/**
 * Дата и время. Значение — «Y-m-dTH:i» (формат <input type="datetime-local">).
 * Разделитель — латинская «T»: с пробелом браузер значение не принимает.
 */
class DateTime extends Field
{
    use HasDateDefault;

    protected function inputFormat(): string
    {
        return 'Y-m-d\TH:i';
    }
}
