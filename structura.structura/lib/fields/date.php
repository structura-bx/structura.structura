<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;
use Structura\Structura\Traits\HasDateDefault;

/**
 * Дата. Значение — «Y-m-d» (формат <input type="date">).
 *
 *   Date('Дата начала', 'starts')->default('now')
 */
class Date extends Field
{
    use HasDateDefault;

    protected function inputFormat(): string
    {
        return 'Y-m-d';
    }
}
