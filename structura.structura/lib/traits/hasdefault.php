<?php

namespace Structura\Structura\Traits;

/**
 * Значение поля по умолчанию.
 */
trait HasDefault
{
    protected mixed $default = null;

    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function hasDefault(): bool
    {
        return $this->default !== null;
    }
}
