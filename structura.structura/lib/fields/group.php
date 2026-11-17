<?php

namespace Structura\Structura\Fields;

use Structura\Structura\Field;

/**
 * Группа полей — один вложенный набор, а не список.
 *
 * Нужна, чтобы связанные поля переиспользовались одним классом:
 *
 *     class Button extends Group
 *     {
 *         public function __construct(string $name, string $code)
 *         {
 *             parent::__construct($name, $code);
 *             $this->setFields(
 *                 Text('Текст', 'text'),
 *                 Text('Ссылка', 'href'),
 *                 Checkbox('В новом окне', 'blank')
 *             );
 *         }
 *     }
 *
 *     Block('Баннер', 'banner')->setFields(new Button('Кнопка', 'cta'));
 *
 * Отличие от Repeater — количество. У Repeater значение это список строк
 * (`[{...}, {...}]`), у Group — один объект (`{text: ..., href: ...}`), и
 * кнопок «добавить строку» нет; min/max тут не применяются.
 *
 * Каждый обход контейнеров (валидация, клонирование, дублирование файлов)
 * различает Group явно и до общей ветки контейнера: общая ветка считает значение
 * списком строк, а у Group это один объект — как одна строка Repeater.
 */
class Group extends ContainerField
{
    /**
     * Значение по умолчанию — объект из умолчаний вложенных полей. Группа
     * существует всегда, поэтому пустой её делают пустые поля, а не отсутствие
     * строк.
     *
     * @return array<string, mixed>
     */
    public function normalizeDefault(): mixed
    {
        $defaults = [];
        foreach ($this->getFields() as $field) {
            $defaults[$field->getCode()] = $field->normalizeDefault();
        }

        return $defaults;
    }

    /**
     * Пустое значение группы — объект из пустых значений вложенных полей (не их
     * умолчаний): настроенные внутри умолчания сюда возвращать нельзя (см.
     * Field::emptyValue).
     *
     * @return array<string, mixed>
     */
    public function emptyValue(): mixed
    {
        $empty = [];
        foreach ($this->getFields() as $field) {
            $empty[$field->getCode()] = $field->emptyValue();
        }

        return $empty;
    }
}
