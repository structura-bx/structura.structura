<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/**
 * Пример конфигурации блока «Баннер».
 *
 * Разместите такой файл в каталоге блоков:
 *   /local/php_interface/structura/blocks/banner/config.php
 *
 * Стиль записи адаптирован под PHP >= 8.0: вместо `new Block()->...`
 * (доступно только с 8.4) используются фабричные функции Structura\Structura\*.
 */

use function Structura\Structura\Block;
use function Structura\Structura\Color;
use function Structura\Structura\Image;
use function Structura\Structura\NotEmpty;
use function Structura\Structura\Repeater;
use function Structura\Structura\Required;
use function Structura\Structura\Text;

return Block('Баннер')
    ->setFields(
        Text('Заголовок', 'title')
            ->default('Заголовок по умолчанию')
            ->setValidation(Required()),
        Repeater('Слайды', 'slides')
            ->min(1)
            ->setFields(
                Text('Подпись', 'caption'),
                Image('Картинка', 'picture')
                    ->setValidation(Required()),
                Color('Цвет фона', 'bg')
                    ->setValidation(Required(), NotEmpty())
                    ->setPolicy(['groups' => [1], 'users' => [], 'policy' => []])
            )
    )
    ->version('1.0.0')
    ->setPolicy(['groups' => [], 'users' => [], 'policy' => []])
    ->visibleOn(['iblocks' => [], 'sections' => [], 'elements' => []]);
