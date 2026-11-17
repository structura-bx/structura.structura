<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;

/**
 * Числовое поле.
 *
 * Своих настроек у поля нет намеренно. Диапазон и шаг были здесь
 * (`->min()`, `->max()`, `->step()`), но не проверялись ни сервером, ни
 * браузером — теперь это правило {@see \Structura\Structura\Validation\Range}:
 *
 *     Number('Скидка, %', 'discount')->setValidation(Range(0, 100, 5))
 */
class Number extends Field
{
}
