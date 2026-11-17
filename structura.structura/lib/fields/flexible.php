<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;

/**
 * Гибкое поле — набор из нескольких возможных макетов (layout'ов).
 * Каждый макет — вложенный контейнер полей; в значении хранится
 * последовательность выбранных макетов с их данными.
 */
class Flexible extends ContainerField
{
    /**
     * Регистрирует макеты гибкого поля. Каждый макет — контейнер (например,
     * Repeater или иной ContainerField), выступающий одним из вариантов.
     */
    public function layouts(Field ...$layouts): static
    {
        return $this->setFields(...$layouts);
    }

    /** @return Field[] */
    public function getLayouts(): array
    {
        return $this->getFields();
    }
}
