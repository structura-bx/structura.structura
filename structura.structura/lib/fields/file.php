<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;
use Structura\Structura\Validation\FileExists;

/** Произвольный файл. */
class File extends Field
{
    /**
     * Подсказка браузеру для окна выбора файла. На проверку загрузки не влияет:
     * что реально можно загрузить, решает сервер по списку расширений (см.
     * Controller\Block::allowedExtensions) — accept приходит от клиента.
     */
    public function accept(string $mime): static
    {
        $this->settings['accept'] = $mime;

        return $this;
    }

    /**
     * Значение — ID существующего файла. В отличие от Image, картинка не
     * требуется: файл может быть любым.
     */
    protected function defaultValidation(): array
    {
        return [new FileExists()];
    }
}
