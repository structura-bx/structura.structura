<?php

namespace Structura\Structura\Validation;

use Bitrix\Main\Localization\Loc;
use Structura\Structura\Validation;

Loc::loadMessages(__FILE__);

/**
 * Значение — путь к существующему файлу внутри сайта ("/upload/gallery/1.jpg").
 *
 * Встроенное правило поля Gallery: там значение — путь, а не ID, и проверить
 * его больше нечем. Поле редактируемое руками, поэтому проверяем и форму пути:
 *  - путь только от корня сайта (с "/"), не "http://..." и не "C:\...";
 *  - никаких ".." — иначе значением можно увести на файл вне сайта;
 *  - файл должен существовать.
 *
 * Пустое значение валидно: обязательность — дело Required().
 */
class PathExists extends Validation
{
    public function passes(mixed $value, array $context = []): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        $path = trim($value);
        if ($path === '') {
            return true;
        }

        // NUL обрывает строку в файловых функциях — путь с ним не проверить.
        if (str_contains($path, "\0")) {
            return false;
        }

        if (!str_starts_with($path, '/') || str_contains($path, '..')) {
            return false;
        }

        // Корня сайта нет — например, в автономном cli-тесте доменной модели.
        // Проверить существование нечем, а выдумывать ответ хуже, чем пропустить:
        // на бою корень есть всегда.
        $root = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        if ($root === '') {
            return true;
        }

        return is_file($root . $path);
    }

    public function message(): string
    {
        return (string) Loc::getMessage('STRUCTURA_PATHEXISTS_MESSAGE');
    }

    public function code(): string
    {
        return 'pathExists';
    }
}
