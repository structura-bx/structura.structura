<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;

/**
 * Привязка к элементу инфоблока. Значение — один ID. Нужно несколько —
 * Repeater с IblockElement внутри.
 */
class IblockElement extends Field
{
    /** Ограничить выбор конкретным инфоблоком (ID или CODE). */
    public function iblock(int|string $iblock): static
    {
        $this->settings['iblock'] = $iblock;

        return $this;
    }
}
