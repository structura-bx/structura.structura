<?php

namespace Structura\Structura\Traits;

/**
 * Общие настройки текстовых полей ввода.
 *
 * Здесь остаётся только то, что реально доезжает до `<input>`. Ограничения
 * длины жили тут же (`->minLength()`, `->maxLength()`), но их не проверял никто
 * — теперь это правило {@see \Structura\Structura\Validation\Length}:
 *
 *     Text('Заголовок', 'title')->setValidation(Length(3, 60))
 */
trait HasInputText
{
    public function placeholder(string $text): static
    {
        $this->settings['placeholder'] = $text;

        return $this;
    }
}
