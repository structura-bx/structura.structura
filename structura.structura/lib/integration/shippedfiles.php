<?php

namespace Structura\Structura\Integration;

use Bitrix\Main\Application;
use Structura\Structura\Config\Loader;

/**
 * Уборка того, что установщик кладёт на сайт: шаблона настроек
 * (configuration.php из stub) и пустого каталога блоков.
 *
 * Блоки в `blocks_dir` не трогаем: установщик их туда не копирует (примеры живут
 * в `examples/` модуля), а стирать чужие папки по догадке — риск снести нужное.
 *
 * Шаблон настроек установщик кладёт сам, поэтому и убирает — но лишь пока он не
 * тронут. Отличие от stub означает, что configuration.php правил разработчик
 * (сменил `blocks_dir` и т.п.); такие настройки переживают удаление.
 */
final class ShippedFiles
{
    /** Настройки модуля на сайте — относительно корня. */
    private const CONFIG_RELATIVE = '/local/php_interface/structura/configuration.php';

    /**
     * Убирает шаблон настроек и пустые каркасные каталоги.
     *
     * @return array{removed: string[], kept: string[]} пути относительно корня
     *         сайта: что удалено и что оставлено как правленое
     */
    public static function remove(): array
    {
        // Путь к каталогу блоков спрашиваем до удаления configuration.php: его
        // задаёт этот файл (`blocks_dir`), после удаления загрузчик вернул бы
        // умолчание — чужой каталог.
        $blocksDir  = Loader::blocksDir();
        $configFile = self::documentRoot() . self::CONFIG_RELATIVE;

        $report = self::purgeConfiguration($configFile);

        // Пустой каркас, созданный установщиком, убираем; каталог с блоками
        // разработчика — нет: у непустого removeIfEmpty ничего не делает.
        self::removeIfEmpty($blocksDir);
        self::removeIfEmpty(dirname($configFile));

        return $report;
    }

    /**
     * Шаблон настроек: удаляем, только если его не правили. Отличие от stub
     * означает, что configuration.php правил разработчик, — такие настройки
     * переживают удаление.
     *
     * @return array{removed: string[], kept: string[]}
     */
    public static function purgeConfiguration(string $file): array
    {
        $report = ['removed' => [], 'kept' => []];

        if (!is_file($file)) {
            return $report;
        }

        $stub = STRUCTURA_MODULE_DIR . '/install/stub/configuration.php';

        if (!is_file($stub) || !self::sameFile($file, $stub)) {
            $report['kept'][] = self::relative($file);

            return $report;
        }

        if (@unlink($file)) {
            $report['removed'][] = self::relative($file);
        }

        return $report;
    }

    /** Одинаковы ли файлы по содержимому. */
    private static function sameFile(string $a, string $b): bool
    {
        if (!is_file($a) || !is_file($b)) {
            return false;
        }

        // Размер отсекает почти всё за один stat; хэш нужен там, где длина
        // совпала.
        if (filesize($a) !== filesize($b)) {
            return false;
        }

        return hash_file('sha256', $a) === hash_file('sha256', $b);
    }

    /**
     * Содержимое каталога без «.» и «..» (скрытые файлы не пропускаем).
     *
     * @return string[]
     */
    private static function entries(string $dir): array
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }

        return array_values(array_diff($entries, ['.', '..']));
    }

    private static function removeIfEmpty(string $dir): void
    {
        if (is_dir($dir) && self::entries($dir) === []) {
            @rmdir($dir);
        }
    }

    /** Путь от корня сайта — для отчёта администратору. */
    private static function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = self::documentRoot();

        return $root !== '' && str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private static function documentRoot(): string
    {
        return rtrim(str_replace('\\', '/', Application::getDocumentRoot()), '/');
    }
}
