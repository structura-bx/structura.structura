<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;

/**
 * Привязка к разделу инфоблока. Значение — один ID.
 *
 * Одно намеренно: нужно несколько — это Repeater с IBlockSection внутри.
 * Настройки множественности у поля нет (см. HasOptions).
 */
class IBlockSection extends Field
{
    /** Ограничить выбор конкретным инфоблоком (ID или CODE). */
    public function iblock(int|string $iblock): static
    {
        $this->settings['iblock'] = $iblock;

        return $this;
    }
}
