<?php

namespace Structura\Structura\Event;

/**
 * События уровня блока (в отличие от событий полей, {@see FieldEvent}).
 *
 * Поднимаются модулем `structura.structura` при сохранении и удалении экземпляра
 * блока. Имя события фиксированное (не зависит от кода блока), а сам блок и
 * сущность лежат в параметрах — так один обработчик может слушать все блоки или
 * отфильтровать нужный по `blockCode`.
 *
 * Пары before/after:
 *   - before-событие поднимается до записи/удаления и может запретить операцию:
 *     обработчик возвращает `new EventResult(EventResult::ERROR, ['message' => …])`,
 *     и сохранение/удаление отклоняется с этим текстом (как ядровое
 *     `OnBeforeIBlockElementAdd`, где `return false` блокирует). Несколько
 *     обработчиков — вето от первого же.
 *   - after-событие — уведомление после успешной операции, запретить уже нечего.
 *
 * Параметры before/after-Save: `blockCode`, `entityType`, `entityId`,
 * `instanceId`, `language` (метка записанного языка), `isNew` (размещается ли
 * новый экземпляр — true у создания, false у правки), `old`, `new`.
 * Параметры before/after-Delete: `blockCode`, `entityType`, `entityId`,
 * `instanceId`, `old`.
 *
 * Каскадная уборка при удалении самой сущности ({@see \Structura\Structura\Service\EntityPurge})
 * эти события не поднимает: элемента уже нет, вето там оставило бы значения-сироты.
 */
final class BlockEvent
{
    public const BEFORE_SAVE   = 'OnBlockBeforeSave';
    public const AFTER_SAVE    = 'OnBlockAfterSave';
    public const BEFORE_DELETE = 'OnBlockBeforeDelete';
    public const AFTER_DELETE  = 'OnBlockAfterDelete';
}
