<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/**
 * Демонстрационный блок со всеми базовыми полями модуля (26 типов) и основными
 * возможностями: настройки полей, валидация, политики доступа, вложенные
 * контейнеры, правила видимости.
 *
 * Назначение — живой справочник: открыв этот блок в админке, видно, как каждый
 * тип поля выглядит и какие у него настройки. Он же ловит регрессии в реестре
 * полей: если тип не зарегистрирован или сломан рендер, это видно сразу.
 *
 * Порядок полей повторяет справочник docs/fields.md.
 *
 * Внимание: ссылочные поля (IblockElement, IBlockSection, HighloadElement)
 * настроены на ID 1 — на конкретном сайте их нужно поправить под свои данные.
 */

use function Structura\Structura\Block;
use function Structura\Structura\Checkbox;
use function Structura\Structura\Color;
use function Structura\Structura\Date;
use function Structura\Structura\DateTime;
use function Structura\Structura\Email;
use function Structura\Structura\File;
use function Structura\Structura\Flexible;
use function Structura\Structura\Gallery;
use function Structura\Structura\Group;
use function Structura\Structura\Hidden;
use function Structura\Structura\Highload;
use function Structura\Structura\HighloadElement;
use function Structura\Structura\Iblock;
use function Structura\Structura\IBlockSection;
use function Structura\Structura\IblockElement;
use function Structura\Structura\Image;
use function Structura\Structura\Length;
use function Structura\Structura\MediaLibrary;
use function Structura\Structura\NotEmpty;
use function Structura\Structura\Number;
use function Structura\Structura\Password;
use function Structura\Structura\Radio;
use function Structura\Structura\Range;
use function Structura\Structura\Repeater;
use function Structura\Structura\Required;
use function Structura\Structura\Select;
use function Structura\Structura\Text;
use function Structura\Structura\TextArea;
use function Structura\Structura\Time;
use function Structura\Structura\User;

return Block('Все поля')
    ->setFields(
        // --- Текстовые -----------------------------------------------------
        // Длина — тоже правило (Length), а не настройка поля: у настройки не
        // было проверки ни на сервере, ни в форме.
        Text('Строка', 'f_text')
            ->default('Значение по умолчанию')
            ->help('Подсказка разработчика: раскрывается по клику на «?» рядом с названием поля.')
            ->setValidation(Required(), Length(3, 60)),
        Hidden('Скрытое поле', 'f_hidden')
            ->default('служебное значение'),
        TextArea('Многострочный текст', 'f_textarea')
            ->rows(5),
        Email('E-mail', 'f_email')
            ->setValidation(NotEmpty()),
        Password('Пароль', 'f_password'),

        // --- Числа, дата и время -------------------------------------------
        // Диапазон и шаг — правило, а не настройка поля: своих настроек у
        // Number нет. Правило проверяет и сервер, и форма.
        Number('Число', 'f_number')
            ->setValidation(Range(0, 100, 5))
            ->default(10)
            ->help('От 0 до 100 с шагом 5.'),
        Date('Дата', 'f_date'),
        DateTime('Дата и время', 'f_datetime'),
        Time('Время', 'f_time'),

        // --- Выбор из вариантов --------------------------------------------
        Select('Выпадающий список', 'f_select')
            ->options(['a' => 'Вариант А', 'b' => 'Вариант Б', 'c' => 'Вариант В'])
            ->default('a'),
        // Несколько значений — это контейнер, а не настройка поля: ->multiple()
        // у полей нет (у Block он про копии блока — другой уровень).
        Repeater('Несколько вариантов', 'f_select_multi')
            ->setFields(
                Select('Вариант', 'choice')
                    ->options(['x' => 'Икс', 'y' => 'Игрек', 'z' => 'Зет'])
            ),
        // Одиночный флажок: без ->options() значение — 'Y'/'N'.
        Checkbox('Показывать', 'f_flag')
            ->default('Y'),
        // С вариантами то же поле становится группой флажков, и значение у неё
        // массив отмеченных ключей. Нужен ровно один вариант — Radio или Select.
        Checkbox('Флажки', 'f_checkbox')
            ->options(['news' => 'Новости', 'promo' => 'Акции']),
        Radio('Переключатель', 'f_radio')
            ->options(['one' => 'Один', 'two' => 'Два'])
            ->default('one'),

        // --- Файлы ----------------------------------------------------------
        Image('Картинка', 'f_image')
            ->accept('image/*'),
        File('Файл', 'f_file')
            ->accept('application/pdf'),
        // Gallery — один файл из файловой структуры сайта (значение — путь, а
        // не ID: у файлов галереи записи в b_file нет). Нужно несколько —
        // заворачивается в Repeater, как ниже в «Слайдах».
        Gallery('Файл из галереи', 'f_gallery')
            ->path('/upload'),
        // MediaLibrary — файл из медиабиблиотеки (курируемых коллекций).
        // Значение тоже путь; отличие от Gallery — источник выбора. Диалог
        // покажет все типы медиа. Несколько — тоже через Repeater.
        MediaLibrary('Файл из медиабиблиотеки', 'f_medialibrary'),

        // --- Ссылочные ------------------------------------------------------
        User('Пользователь', 'f_user'),
        // ->type() фильтрует список по типу инфоблока. Тип 'news' есть на
        // демо-стенде; на другом сайте поставьте свой — с несуществующим типом
        // список будет пустым (это не ошибка поля).
        Iblock('Инфоблок', 'f_iblock')
            ->type('news'),
        IblockElement('Элемент инфоблока', 'f_iblock_element')
            ->iblock(1),
        IBlockSection('Раздел инфоблока', 'f_iblock_section')
            ->iblock(1),
        Highload('Highload-блок', 'f_highload'),
        // ->entity() принимает ID, имя сущности или имя таблицы. Здесь по имени:
        // HL-блок с таким ID может не существовать, а имя — договорённость.
        // Поле подписи сервер выберет сам (UF_NAME → UF_XML_ID → ID, как в
        // ядре); ->titleField('UF_KIND') — если нужно другое поле явно.
        HighloadElement('Элемент Highload-блока', 'f_highload_element')
            ->entity('StructuraDemo'),

        // --- Цвет: с палитрой и политикой доступа ---------------------------
        Color('Цвет', 'f_color')
            ->palette(['#635bff', '#2e9e63', '#e5484d'])
            ->setValidation(Required(), NotEmpty())
            // Поле доступно только администраторам — демонстрация политики.
            ->setPolicy(['groups' => [1], 'users' => [], 'policy' => []]),

        // --- Контейнеры -----------------------------------------------------
        // Group — один вложенный набор, а не список: ни кнопки «добавить», ни
        // счётчика строк. Здесь показана разовая группа через фабрику; ради
        // переиспользования группу заводят классом-наследником
        // (class Button extends Group) — см. docs/fields.md.
        Group('Кнопка', 'f_group')
            ->help('Значение группы — один объект: f_group.text, f_group.href.')
            ->setFields(
                Text('Текст', 'text')->default('Подробнее'),
                Text('Ссылка', 'href')->default('/'),
                Checkbox('В новом окне', 'blank')
            ),
        Repeater('Повторитель', 'f_repeater')
            ->min(1)->max(5)
            ->help('Подсказка работает и у контейнеров: от 1 до 5 строк.')
            ->setFields(
                Text('Подпись', 'caption')
                    ->setValidation(Required()),
                Color('Цвет строки', 'tint')
            ),
        Flexible('Гибкое поле', 'f_flexible')
            ->layouts(
                Repeater('Текстовый блок', 'text_layout')
                    ->setFields(
                        Text('Заголовок', 'heading'),
                        TextArea('Текст', 'body')->rows(3)
                    ),
                Repeater('Блок с картинками', 'media_layout')
                    ->setFields(
                        Image('Картинка', 'pic'),
                        Text('Подпись', 'alt')
                    )
            )
    )
    ->version('1.0.0')
    ->revisions(['limit' => 20, 'ttl' => 'quarter'])
    ->setPolicy(['groups' => [], 'users' => [], 'policy' => []])
    ->visibleOn(['iblocks' => [], 'sections' => [], 'elements' => []]);