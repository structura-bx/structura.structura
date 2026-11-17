<?php

namespace Structura\Structura\Integration;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Общая обёртка для обработчиков событий ядра.
 *
 * Ядро зовёт обработчики событий последовательным циклом без try/catch
 * (`ExecuteModuleEventEx`). Непойманный Throwable из нашего обработчика оборвал
 * бы этот цикл: не отработали бы обработчики других модулей, зарегистрированные
 * на то же событие после нашего, — и та операция ядра, в контексте которой шло
 * событие (удаление или добавление элемента), завершилась бы фаталом. Модуль не
 * вправе ронять чужой код своей ошибкой, поэтому его граница глушит сбой в
 * журнал и отдаёт управление дальше.
 *
 * Обёртка стоит только на точке входа, зарегистрированной в ядре. Внутренние
 * сервисы не перехватывают исключения, чтобы вызывающий код мог их обработать.
 * Для вывода вкладки отдельную границу создаёт {@see AdminIblock::renderTab}.
 */
final class EventGuard
{
    /**
     * Выполняет обработчик, не давая его сбою всплыть в ядро.
     *
     * @param string        $what    что выполнялось — для сообщения в журнале
     * @param callable():void $handler тело обработчика
     */
    public static function run(string $what, callable $handler): void
    {
        try {
            $handler();
        } catch (\Throwable $e) {
            // Журнал ядра есть только под поднятым порталом; в kernel-less
            // контексте (доменные тесты, CLI без пролога) молча глушим.
            if (function_exists('AddMessage2Log')) {
                \AddMessage2Log(
                    (string) Loc::getMessage('STRUCTURA_EVENTGUARD_HANDLER_ERROR', [
                        '#EVENT#' => $what,
                        '#ERROR#' => $e->getMessage() . "\n" . $e->getTraceAsString(),
                    ]),
                    STRUCTURA_MODULE_ID
                );
            }
        }
    }
}
