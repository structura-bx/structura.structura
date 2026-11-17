<?php

namespace Structura\Structura\Validation;

use Bitrix\Main\Localization\Loc;
use Structura\Structura\Validation;

Loc::loadMessages(__FILE__);

/**
 * Значение — ID существующего файла (CFile); при $mustBeImage — ещё и картинки.
 *
 * Правило встроенное: поля Image и File вешают его на себя сами
 * (Field::defaultValidation), потому что ID несуществующего файла — это не
 * «настройка на усмотрение разработчика», а всегда ошибка.
 *
 * Пустое значение считается валидным: обязательность — дело Required(), иначе
 * необязательное поле нельзя было бы оставить пустым.
 *
 * Во фронтенд правило уезжает, но там игнорируется (неизвестное правило в
 * passesRule возвращает true) — существование файла проверяется только на
 * сервере, и это нормально: клиент про базу файлов ничего не знает.
 */
class FileExists extends Validation
{
    public function __construct(private bool $mustBeImage = false)
    {
    }

    public function passes(mixed $value, array $context = []): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (!is_scalar($value)) {
            return false;
        }

        $raw = trim((string) $value);
        $id  = (int) $raw;

        // Сравнение с исходной строкой отсекает «12abc» и «1.5»: (int) молча
        // возьмёт из них 12 и 1, а это не ID файла.
        if ($id <= 0 || (string) $id !== $raw) {
            return false;
        }

        // Доменная модель живёт и без ядра Bitrix (на этом стоит cli_smoke):
        // без CFile проверить существование нечем, и придумывать ответ хуже,
        // чем пропустить — на бою ядро есть всегда.
        if (!class_exists(\CFile::class)) {
            return true;
        }

        $file = \CFile::GetFileArray($id);
        if (!$file) {
            return false;
        }

        // У картинки CFile заполняет размеры; у произвольного файла они нулевые.
        return !$this->mustBeImage || ((int) $file['WIDTH'] > 0 && (int) $file['HEIGHT'] > 0);
    }

    public function message(): string
    {
        return (string) Loc::getMessage($this->mustBeImage
            ? 'STRUCTURA_FILEEXISTS_IMAGE'
            : 'STRUCTURA_FILEEXISTS_FILE');
    }

    public function code(): string
    {
        return $this->mustBeImage ? 'imageExists' : 'fileExists';
    }
}
