<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;

/** Привязка к инфоблоку. */
class Iblock extends Field
{
    public function type(string $iblockType): static
    {
        $this->settings['iblockType'] = $iblockType;

        return $this;
    }
}
