<?php

namespace Structura\Structura\Traits;

use Structura\Structura\Validation;

/**
 * Привязка правил валидации к полю.
 */
trait HasValidation
{
    /** @var Validation[] */
    protected array $validation = [];

    public function setValidation(Validation ...$rules): static
    {
        array_push($this->validation, ...$rules);

        return $this;
    }

    /**
     * Правила поля: встроенные (от типа) + заданные в конфиге.
     *
     * @return Validation[]
     */
    public function getValidation(): array
    {
        return array_merge($this->defaultValidation(), $this->validation);
    }

    /**
     * Встроенные правила типа поля — то, что верно всегда и не зависит от
     * конфига (например, у Image значение обязано быть ID картинки).
     * Переопределяется в классе поля.
     *
     * @return Validation[]
     */
    protected function defaultValidation(): array
    {
        return [];
    }
}
