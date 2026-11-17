<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;
use Structura\Structura\Traits\HasInputText;

/** Многострочное текстовое поле. */
class TextArea extends Field
{
    use HasInputText;

    public function rows(int $rows): static
    {
        $this->settings['rows'] = $rows;

        return $this;
    }
}
