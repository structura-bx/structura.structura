<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;
use Structura\Structura\Validation\PathExists;

/**
 * Файл из файловой структуры сайта (диалог выбора файла Битрикса).
 *
 * Значение — один относительный путь от корня сайта ("/upload/gallery/1.jpg"),
 * а не ID: в галерее файлы лежат просто файлами, записи в b_file у них нет.
 * Этим поле отличается от Image/File, где значение — ID файла (CFile). Нужно
 * несколько путей — Repeater с Gallery внутри.
 */
class Gallery extends Field
{
    /** Папка, с которой открывается диалог выбора, например '/upload'. */
    public function path(string $path): static
    {
        $this->settings['path'] = $path;

        return $this;
    }

    /** Значение — путь к существующему файлу внутри сайта. Проверяется всегда. */
    protected function defaultValidation(): array
    {
        return [new PathExists()];
    }
}
