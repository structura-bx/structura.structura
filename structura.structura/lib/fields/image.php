<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;
use Structura\Structura\Validation\FileExists;

/** Изображение (одиночный файл-картинка). */
class Image extends Field
{
    /**
     * Подсказка браузеру для окна выбора файла. На проверку загрузки не влияет:
     * её делает сервер (см. Controller\Block::uploadAction) — accept приходит
     * от клиента и подделывается.
     */
    public function accept(string $mime): static
    {
        $this->settings['accept'] = $mime;

        return $this;
    }

    /** Значение — ID существующей картинки. Проверяется всегда. */
    protected function defaultValidation(): array
    {
        return [new FileExists(true)];
    }
}
