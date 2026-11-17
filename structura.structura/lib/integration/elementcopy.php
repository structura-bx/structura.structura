<?php

namespace Structura\Structura\Integration;

use Bitrix\Main\Loader;
use Structura\Structura\Service\CopyService;

/**
 * Копирование значений Structura при копировании элемента инфоблока.
 *
 * Копирование в админке идёт через форму редактирования: кнопка «Копировать»
 * ведёт на неё с action=copy, форма не отправляет ID (создаётся новый элемент)
 * и кладёт исходный элемент в скрытое поле `copyID`. Отдельного события «элемент
 * скопирован» у ядра нет, поэтому цепляемся к добавлению и узнаём источник по
 * этому полю.
 *
 * Значения копируются вместе с файлами (см. CopyService): копия получает свои ID
 * файлов, иначе удаление файла у копии убило бы картинку у оригинала.
 */
class ElementCopy
{
    /**
     * Обработчик iblock:OnAfterIBlockElementAdd.
     *
     * Ядро вызывает это событие и при неудачном добавлении (событие идёт после
     * установки $arFields["RESULT"] без проверки успеха). Настоящий ID берём из
     * RESULT: при провале там false, и копировать некуда.
     *
     * @param array<string,mixed> $fields поля добавленного элемента
     */
    public static function onAfterIBlockElementAdd(array $fields): void
    {
        $targetId = (int) ($fields['RESULT'] ?? 0);
        if ($targetId <= 0) {
            return;
        }

        $sourceId = self::sourceElementId();
        if ($sourceId <= 0) {
            return;
        }

        if (!Loader::includeModule('structura.structura') || !Loader::includeModule('iblock')) {
            return;
        }

        if (!self::canReadSourceElement($sourceId)) {
            return;
        }

        // Копирование пишет строки и дублирует файлы (CFile::CopyFile) — под
        // сбоем БД или диска это может бросить. Исключение здесь оборвало бы
        // добавление элемента и обработчики других модулей на том же событии;
        // глушим на границе (см. EventGuard).
        EventGuard::run('ElementCopy::copyElement', static function () use ($sourceId, $targetId): void {
            (new CopyService())->copyElement($sourceId, $targetId);
        });
    }

    /**
     * Исходный элемент копирования.
     *
     * Читаем из $_REQUEST, а не из объекта запроса Bitrix: `copyID` — обычное
     * скрытое поле формы, и брать его надо ровно так же, как это делает сама
     * форма элемента.
     */
    private static function sourceElementId(): int
    {
        return (int) ($_REQUEST['copyID'] ?? 0);
    }

    /**
     * Доступен ли исходный элемент текущему пользователю.
     *
     * copyID приходит из скрытого поля формы и не доказывает, что пользователь
     * вправе читать источник. Инфоблок берём по ID элемента с сервера: подмена
     * идентификатора инфоблока в запросе не должна влиять на проверку права.
     */
    private static function canReadSourceElement(int $sourceId): bool
    {
        $row = \Bitrix\Iblock\ElementTable::getList([
            'select' => ['IBLOCK_ID'],
            'filter' => ['=ID' => $sourceId],
            'limit'  => 1,
        ])->fetch();

        return $row
            && \CIBlockElementRights::UserHasRightTo(
                (int) $row['IBLOCK_ID'],
                $sourceId,
                'element_read'
            );
    }
}
