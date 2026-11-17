<?php

namespace Structura\Structura;

/**
 * Абстрактное правило валидации.
 *
 * Базовые реализации: Structura\Structura\Validation\Required, Structura\Structura\Validation\NotEmpty.
 * Сторонний разработчик наследуется от этого класса для собственных правил.
 */
abstract class Validation
{
    /**
     * Проверяет значение. Возвращает true, если значение валидно.
     *
     * @param mixed $value   значение поля
     * @param array $context произвольный контекст (весь блок, сущность и т.п.)
     */
    abstract public function passes(mixed $value, array $context = []): bool;

    /**
     * Сообщение об ошибке для невалидного значения.
     */
    abstract public function message(): string;

    /**
     * Короткий код правила (используется во фронтенде). По умолчанию —
     * короткое имя класса с маленькой буквы (Required => "required").
     */
    public function code(): string
    {
        $short = substr(strrchr('\\' . static::class, '\\'), 1);

        return lcfirst($short);
    }

    /**
     * Параметры правила для передачи во фронтенд (переопределяется при наличии
     * настроек, например min/max длины).
     */
    public function params(): array
    {
        return [];
    }

    public function toArray(): array
    {
        return [
            'rule'    => $this->code(),
            'message' => $this->message(),
            'params'  => $this->params(),
        ];
    }
}
