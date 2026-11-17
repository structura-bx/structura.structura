<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;
use Structura\Structura\Validation\HighloadRowExists;

/**
 * Привязка к записи Highload-блока. Значение — один ID. Нужно несколько —
 * Repeater с HighloadElement внутри.
 */
class HighloadElement extends Field
{
    /** Highload-блок (ID или имя таблицы/сущности). */
    public function entity(int|string $hlblock): static
    {
        $this->settings['hlblock'] = $hlblock;

        return $this;
    }

    /**
     * Явное поле подписи в списке выбора, например 'UF_KIND'. Необязательно:
     * без него сервер берёт UF_NAME, затем UF_XML_ID, иначе — голые ID (та же
     * конвенция, что у свойства-справочника ядра). Поиск идёт по полю подписи.
     */
    public function titleField(string $field): static
    {
        $this->settings['titleField'] = $field;

        return $this;
    }

    /**
     * Значение — ID существующей строки заданного HL-блока. Проверяется всегда:
     * до ajax-действия save можно дойти запросом мимо формы, где пикер отдаёт
     * только валидные ID.
     */
    protected function defaultValidation(): array
    {
        return [new HighloadRowExists($this->settings['hlblock'] ?? null)];
    }

    /**
     * Highload-блок по значению ->entity(): ID, имя сущности или имя таблицы.
     * Семантику ->entity() задаёт поле, поэтому резолв здесь — им пользуются и
     * справочник пикера, и правило валидации.
     *
     * @return array{ID:int, NAME:string, TABLE_NAME:string}|null
     */
    public static function resolveBlock(int|string $hlblock): ?array
    {
        if ($hlblock === '') {
            return null;
        }

        // Числовая строка — ID: настройка приезжает через JSON, где ID часто
        // строка (как в resolveIblockId).
        if (is_int($hlblock) || ctype_digit((string) $hlblock)) {
            $id = (int) $hlblock;

            return $id > 0
                ? \Bitrix\Highloadblock\HighloadBlockTable::getById($id)->fetch() ?: null
                : null;
        }

        $row = \Bitrix\Highloadblock\HighloadBlockTable::getList([
            'filter' => ['LOGIC' => 'OR', '=NAME' => $hlblock, '=TABLE_NAME' => $hlblock],
            'limit'  => 1,
        ])->fetch();

        return $row ?: null;
    }
}
