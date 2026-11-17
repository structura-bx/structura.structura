<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;

/** Выбор цвета. */
class Color extends Field
{
    /** Предустановленная палитра. @param string[] $colors */
    public function palette(array $colors): static
    {
        $this->settings['palette'] = $colors;

        return $this;
    }
}
