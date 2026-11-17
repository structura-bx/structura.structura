<?php

namespace Structura\Structura\Integration;

use Bitrix\Main\Application;

/**
 * Публикация css/js модуля в веб-доступный корень.
 *
 * Каталог модуля наружу не отдаётся: штатный конфиг nginx закрывает и
 * `/bitrix/modules`, и `local/modules` (`internal;`/`deny all`). Ссылка на
 * `/…/modules/structura.structura/admin/assets/…` даёт 403, `window.Structura`
 * не появляется, вкладка остаётся пустой.
 *
 * Поэтому файлы копируются в публичный корень (`js/` и `css/`), как у модулей
 * ядра. Половину зеркалим по самому модулю: он в `/local/modules` — публикуем в
 * `/local/js`, в `/bitrix/modules` — в `/bitrix/js`. Источник правды — в
 * `admin/assets/`.
 */
final class Assets
{
    /** Имя подкаталога в публичном корне — совпадает с id модуля. */
    private const PUBLIC_SUBDIR = STRUCTURA_MODULE_ID;

    /**
     * Копирует ассеты в публичный корень, если их там нет или они устарели.
     *
     * Сверяем по mtime, а не копируем всегда: метод зовётся на каждом показе
     * вкладки. Это подхватывает правку js и обновление модуля без переустановки.
     *
     * @return bool удалось ли положить оба файла
     */
    public static function publish(bool $force = false): bool
    {
        $ok = true;

        foreach (self::map() as [$source, $target]) {
            if (!is_file($source)) {
                $ok = false;
                continue;
            }

            $fresh = is_file($target) && filemtime($target) >= filemtime($source);
            if (!$force && $fresh) {
                continue;
            }

            \CheckDirPath($target);
            $ok = copy($source, $target) && $ok;
        }

        return $ok;
    }

    /**
     * Адреса ассетов от корня сайта, с версией по времени изменения.
     *
     * Версия снимается с опубликованного файла: именно его отдаёт браузер, и
     * его смена должна сбрасывать кэш.
     *
     * @return array{css: string, js: string}
     */
    public static function urls(): array
    {
        $root = self::documentRoot();
        $base = self::publicBase();

        $urls = [];
        foreach (['css' => 'structura.css', 'js' => 'structura.js'] as $kind => $file) {
            $path = $base . '/' . $kind . '/' . self::PUBLIC_SUBDIR . '/' . $file;
            $urls[$kind] = $path . '?v=' . (int) @filemtime($root . $path);
        }

        return $urls;
    }

    /** Убирает опубликованные копии — они наши, в отличие от конфигураций. */
    public static function remove(): void
    {
        foreach (self::map() as [, $target]) {
            if (is_file($target)) {
                unlink($target);
            }

            $dir = dirname($target);
            if (is_dir($dir) && !array_diff((array) @scandir($dir), ['.', '..'])) {
                rmdir($dir);
            }
        }
    }

    /**
     * Пары «откуда → куда» абсолютными путями файловой системы.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function map(): array
    {
        $root   = self::documentRoot();
        $source = STRUCTURA_MODULE_DIR . '/admin/assets';
        $base   = $root . self::publicBase();

        return [
            [$source . '/css/structura.css', $base . '/css/' . self::PUBLIC_SUBDIR . '/structura.css'],
            [$source . '/js/structura.js',   $base . '/js/' . self::PUBLIC_SUBDIR . '/structura.js'],
        ];
    }

    /**
     * `/local` или `/bitrix` — та половина, в которой лежит сам модуль.
     *
     * Считаем от STRUCTURA_MODULE_DIR, а не перебором каталогов: перебор
     * ошибается на пустой папке-тени от переезда модуля.
     */
    private static function publicBase(): string
    {
        $dir = str_replace('\\', '/', STRUCTURA_MODULE_DIR);

        return str_contains($dir, '/local/modules/') ? '/local' : '/bitrix';
    }

    private static function documentRoot(): string
    {
        return rtrim(str_replace('\\', '/', Application::getDocumentRoot()), '/');
    }
}
