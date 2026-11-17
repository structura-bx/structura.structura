<?php

namespace Structura\Structura\Engine\ActionFilter;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Пускает к действиям модуля только тех, кто имеет право на сам модуль.
 *
 * Модуль административный: он живёт на формах админки. Ajax-роут по умолчанию
 * закрыт лишь префильтром Authentication (требует только авторизации), поэтому
 * на сайте с открытой регистрацией его мог бы дёргать любой посетитель. Данные
 * закрыты проверками внутри действий, но доступный роут — лишняя поверхность.
 *
 * Право — штатное право модуля (`GetGroupRight`), то же, которым закрыта
 * страница настроек (options.php). Фильтр вешается на все действия разом
 * (getDefaultPreFilters контроллера): точечную проверку легко забыть в новом
 * действии, а забытый префильтр — нельзя.
 *
 * Это не заменяет проверки внутри действий: право модуля отвечает «пускать ли
 * вообще», а canEditEntity/canReadEntity и политики — «чей это элемент».
 */
final class ModuleAccess extends Base
{
    public const ERROR_ACCESS_DENIED = 'structura_access_denied';

    /** Право доступа не ниже этого: 'D' — запрещено. */
    private const MIN_RIGHT = 'R';

    public function onBeforeAction(Event $event)
    {
        global $APPLICATION;

        $right = is_object($APPLICATION) ? (string) $APPLICATION->GetGroupRight(STRUCTURA_MODULE_ID) : 'D';

        // Права идут буквами по возрастанию: D < R < W < X — сравнение строк,
        // как в самом ядре.
        if ($right < self::MIN_RIGHT) {
            Context::getCurrent()->getResponse()->setStatus(403);
            $this->addError(new Error(
                (string) Loc::getMessage('STRUCTURA_MODULEACCESS_DENIED'),
                self::ERROR_ACCESS_DENIED
            ));

            return new EventResult(EventResult::ERROR, null, null, $this);
        }

        return null;
    }
}
