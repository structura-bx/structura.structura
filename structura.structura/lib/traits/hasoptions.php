<?php

namespace Structura\Structura\Traits;

/**
 * Поведение полей с перечислением значений (Select, Checkbox, Radio).
 *
 * Множественности здесь нет: несколько значений в модуле выражает контейнер
 * (Repeater с нужным полем внутри). Множественность есть только у Select
 * (`<select multiple>`) и объявлена в самом поле. У Block ->multiple() —
 * про копии блока на сущности, это другой уровень.
 */
trait HasOptions
{
    /** @var array<int|string, string> value => label */
    protected array $options = [];

    /**
     * @param array<int|string, string> $options value => label
     */
    public function options(array $options): static
    {
        $this->options = $options;
        $this->settings['options'] = $options;

        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Обязаны ли быть варианты, чтобы поле имело смысл. Да — для Select и Radio
     * (без вариантов это ошибка конфигурации, проверяет Traits\HasFields).
     * Checkbox переопределяет: без вариантов он одиночный переключатель.
     */
    public function optionsRequired(): bool
    {
        return true;
    }
}
