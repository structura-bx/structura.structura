<?php

/**
 * Точка входа модуля structura.
 *
 * Регистрирует автозагрузчик пространства имён Structura\Structura\ (каталог lib/,
 * имена файлов в нижнем регистре — как в стандартной автозагрузке D7),
 * подключает фабричные функции и регистрирует базовые типы полей.
 */

use Structura\Structura\Registry;

if (defined('STRUCTURA_MODULE_INCLUDED')) {
    return;
}
define('STRUCTURA_MODULE_INCLUDED', true);
define('STRUCTURA_MODULE_ID', 'structura.structura');
define('STRUCTURA_MODULE_DIR', __DIR__);

// Автозагрузчик Structura\Structura\ → lib/. В обычном рантайме он избыточен:
// Loader::includeModule сам регистрирует этот неймспейс на lib/ до исполнения
// этого файла. Но include.php — точка входа и в обход includeModule, где завести
// неймспейс больше некому:
//   • установка/удаление — install/index.php подключает include.php напрямую,
//     когда модуль ещё не в b_module, и сразу использует классы lib/; 
// Классы lib/ нужны сразу (их дёргает и Registry ниже), поэтому автозагрузчик
// обязан жить здесь.
spl_autoload_register(static function (string $class): void {
    $prefix  = 'Structura\\Structura\\';
    $baseDir = __DIR__ . '/lib/';

    // Регистронезависимо: ajax-роутер Bitrix (Engine\Resolver) строит имя класса
    // контроллера в нижнем регистре ("structura\controller\block"), а PHP матчит
    // классы без учёта регистра. str_starts_with был бы регистрозависим и не
    // распознал бы такой запрос → контроллер не грузился, экшен падал в
    // DefaultController.
    if (strncasecmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path     = $baseDir . str_replace('\\', '/', strtolower($relative)) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

require_once __DIR__ . '/lib/functions.php';

Registry::registerDefaults();
