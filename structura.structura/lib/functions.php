<?php

/**
 * Фабричные функции модуля Structura.
 *
 * Позволяют описывать конфигурацию блока цепочкой вызовов на PHP >= 8.0,
 * где синтаксис `new Foo()->method()` (доступный только с 8.4) недопустим:
 *
 *   Block('Баннер')
 *       ->setFields(
 *           Text('Заголовок', 'title')->default('...'),
 *           Repeater('Слайды', 'slides')->setFields(
 *               Color('Фон', 'bg')->setValidation(Required())
 *           )
 *       );
 *
 * Файл подключается из include.php (функции не автозагружаются).
 */

namespace Structura\Structura;

use Structura\Structura\Fields;
use Structura\Structura\Validation\Length;
use Structura\Structura\Validation\NotEmpty;
use Structura\Structura\Validation\Range;
use Structura\Structura\Validation\Required;

if (!function_exists(__NAMESPACE__ . '\\Block')) {
    /**
     * Блок описывается одним аргументом — названием для админки. Идентичность
     * блока задаёт имя папки, его проставляет загрузчик (`Loader::load()` →
     * `setCode()`). Блоку, созданному мимо загрузчика (тесты, серверный код),
     * код задаётся явно: `Block('Имя')->setCode('code')`.
     */
    function Block(string $name = ''): Block
    {
        return new Block($name);
    }

    function Text(string $name, string $code): Fields\Text
    {
        return new Fields\Text($name, $code);
    }

    function Hidden(string $name, string $code): Fields\Hidden
    {
        return new Fields\Hidden($name, $code);
    }

    function TextArea(string $name, string $code): Fields\TextArea
    {
        return new Fields\TextArea($name, $code);
    }

    function Email(string $name, string $code): Fields\Email
    {
        return new Fields\Email($name, $code);
    }

    function Password(string $name, string $code): Fields\Password
    {
        return new Fields\Password($name, $code);
    }

    function Number(string $name, string $code): Fields\Number
    {
        return new Fields\Number($name, $code);
    }

    function Date(string $name, string $code): Fields\Date
    {
        return new Fields\Date($name, $code);
    }

    function DateTime(string $name, string $code): Fields\DateTime
    {
        return new Fields\DateTime($name, $code);
    }

    function Time(string $name, string $code): Fields\Time
    {
        return new Fields\Time($name, $code);
    }

    function Select(string $name, string $code): Fields\Select
    {
        return new Fields\Select($name, $code);
    }

    function Checkbox(string $name, string $code): Fields\Checkbox
    {
        return new Fields\Checkbox($name, $code);
    }

    function Radio(string $name, string $code): Fields\Radio
    {
        return new Fields\Radio($name, $code);
    }

    function Image(string $name, string $code): Fields\Image
    {
        return new Fields\Image($name, $code);
    }

    function File(string $name, string $code): Fields\File
    {
        return new Fields\File($name, $code);
    }

    function Gallery(string $name, string $code): Fields\Gallery
    {
        return new Fields\Gallery($name, $code);
    }

    function MediaLibrary(string $name, string $code): Fields\MediaLibrary
    {
        return new Fields\MediaLibrary($name, $code);
    }

    function User(string $name, string $code): Fields\User
    {
        return new Fields\User($name, $code);
    }

    function Iblock(string $name, string $code): Fields\Iblock
    {
        return new Fields\Iblock($name, $code);
    }

    function IblockElement(string $name, string $code): Fields\IblockElement
    {
        return new Fields\IblockElement($name, $code);
    }

    function IBlockSection(string $name, string $code): Fields\IBlockSection
    {
        return new Fields\IBlockSection($name, $code);
    }

    function Highload(string $name, string $code): Fields\Highload
    {
        return new Fields\Highload($name, $code);
    }

    function HighloadElement(string $name, string $code): Fields\HighloadElement
    {
        return new Fields\HighloadElement($name, $code);
    }

    /**
     * Группа полей — один вложенный набор, а не список.
     *
     * Для переиспользования удобнее наследовать: `class Button extends Group`
     * со своими полями в конструкторе кладётся в setFields() как обычное поле.
     * Фабрика — для разовых групп, которые никуда не переиспользуются.
     */
    function Group(string $name, string $code): Fields\Group
    {
        return new Fields\Group($name, $code);
    }

    function Repeater(string $name, string $code): Fields\Repeater
    {
        return new Fields\Repeater($name, $code);
    }

    function Flexible(string $name, string $code): Fields\Flexible
    {
        return new Fields\Flexible($name, $code);
    }

    function Color(string $name, string $code): Fields\Color
    {
        return new Fields\Color($name, $code);
    }

    function Required(): Required
    {
        return new Required();
    }

    function NotEmpty(): NotEmpty
    {
        return new NotEmpty();
    }

    /** Длина строки: Length(3, 60), Length(3), Length(null, 60). */
    function Length(?int $min = null, ?int $max = null): Length
    {
        return new Length($min, $max);
    }

    /** Числовой диапазон и шаг: Range(0, 100, 5). */
    function Range(int|float|null $min = null, int|float|null $max = null, int|float|null $step = null): Range
    {
        return new Range($min, $max, $step);
    }
}
