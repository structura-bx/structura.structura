<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;
use Structura\Structura\Validation\PathExists;

/**
 * Файл из медиабиблиотеки Bitrix (диалог CMedialib, модуль fileman).
 *
 * Значение — один относительный путь к файлу ("/upload/medialibrary/..."):
 * именно путь отдаёт диалог медиабиблиотеки (id элемента библиотеки он не
 * возвращает), и путь — то, что нужно компонентам для показа. От Image/File
 * поле отличается тем, что там значение — ID файла в b_file; от Gallery —
 * только источником: Gallery берёт файл из файловой структуры сайта, а
 * MediaLibrary — из курируемых коллекций медиабиблиотеки.
 *
 * Значение одно намеренно: диалог ядра одиночный, а «несколько» выражается
 * контейнером — Repeater с MediaLibrary внутри. Диалог показывает все типы
 * медиа (изображения, видео, звук) — тип определяется коллекцией в библиотеке.
 */
class MediaLibrary extends Field
{
    /** Значение — путь к существующему файлу внутри сайта. Проверяется всегда. */
    protected function defaultValidation(): array
    {
        return [new PathExists()];
    }
}
