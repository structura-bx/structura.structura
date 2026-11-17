<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;
use Structura\Structura\Traits\HasDateDefault;

/** Время. Значение — «H:i» (формат <input type="time">). */
class Time extends Field
{
    use HasDateDefault;

    protected function inputFormat(): string
    {
        return 'H:i';
    }
}
