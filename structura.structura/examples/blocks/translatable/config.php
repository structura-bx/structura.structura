<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/**
 * Пример переводимого блока.
 *
 * Флаг ->translatable() включает перевод контента по языкам. Список языков и
 * язык-fallback — глобальные, в /local/php_interface/structura/configuration.php:
 *
 *   'languages'         => ['ru', 'en'],
 *   'language_fallback' => 'ru',
 *
 * Каждый язык — отдельная строка structura_value (колонка LANG); значения
 * fallback-языка отдаются старым API как прежде. Переводятся все поля.
 *
 * Чтение на сайте:
 *   Blocks::element(42)->block('translatable');                 // язык по умолчанию
 *   Blocks::element(42)->language('en')->block('translatable'); // перевод (промах → база)
 */

use function Structura\Structura\Block;
use function Structura\Structura\Required;
use function Structura\Structura\Text;
use function Structura\Structura\Textarea;

return Block('Переводимый блок')
    ->translatable()
    ->revisions(['limit' => 30])
    ->setFields(
        Text('Заголовок', 'title')
            ->setValidation(Required()),
        Textarea('Описание', 'description')
    );
