<?php

namespace Structura\Structura\Storage;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

/**
 * Хранилище значений блоков.
 *
 * В БД хранятся только значения: символьный код блока, привязка к сущности и
 * значения полей блока в формате JSON. Конфигурация блока в БД не хранится.
 *
 * Одна строка = экземпляр блока на сущности на одном языке. У переводимого блока
 * экземпляр — несколько строк (по одной на язык, колонка LANG); метаданные
 * (SORT/ACTIVE/окно дат/авторство) зеркалятся на все языковые строки экземпляра.
 * Блок с ->multiple() можно добавить к сущности несколько раз: у каждой копии
 * свой INSTANCE_ID. Адрес — (ENTITY_TYPE, ENTITY_ID, INSTANCE_ID, LANG), а не
 * код блока.
 *
 * @see \Structura\Structura\Config\Loader — конфигурация блоков (файлы).
 */
class ValueTable extends DataManager
{
    public const ENTITY_IBLOCK  = 'iblock';
    public const ENTITY_SECTION = 'section';
    public const ENTITY_ELEMENT = 'element';

    public static function getTableName(): string
    {
        return 'structura_value';
    }

    public static function getMap(): array
    {
        return [
            new IntegerField('ID', [
                'primary'      => true,
                'autocomplete' => true,
            ]),
            (new StringField('BLOCK_CODE', ['size' => 255]))
                ->configureRequired(),
            (new StringField('ENTITY_TYPE', ['size' => 32]))
                ->configureRequired(),
            (new IntegerField('ENTITY_ID'))
                ->configureRequired(),
            // Идентификатор экземпляра, уникальный в пределах сущности.
            // Для множественных блоков — uuid, сгенерированный клиентом при
            // добавлении копии (экземпляр имеет идентичность ещё до сохранения).
            // Для обычных блоков равен коду блока: экземпляр всегда один.
            (new StringField('INSTANCE_ID', ['size' => 32]))
                ->configureRequired(),
            // Язык строки. У переводимого блока — метка языка (fallback у языка
            // по умолчанию), у непереводимого — fallback (одна строка). Чтение
            // выбирает строку по этой метке, поэтому смена language_fallback не
            // требует миграции: язык лежит там, где помечен. '' — данные со
            // старых установок и записи при ненастроенных языках (читаются как
            // язык по умолчанию).
            (new StringField('LANG', ['size' => 50]))
                ->configureDefaultValue(''),
            // Порядок экземпляров на сущности (перетаскивание в админке).
            (new IntegerField('SORT'))
                ->configureDefaultValue(0),
            new TextField('VALUE'),
            (new DatetimeField('CREATED_AT'))
                ->configureDefaultValue(static fn () => new DateTime()),
            (new DatetimeField('UPDATED_AT'))
                ->configureDefaultValue(static fn () => new DateTime()),
            // Кто создал экземпляр и кто менял его последним (ID пользователя).
            // Nullable: системная запись (импорт, CLI, публичное API) идёт без
            // пользователя — там автор неизвестен, а не «нулевой».
            //
            // configureNullable() обязателен: у ScalarField ядра nullable по
            // умолчанию выключен, и без него колонка создаётся NOT NULL, а
            // запись null роняет сохранение ошибкой MySQL 1048.
            (new IntegerField('CREATED_BY'))
                ->configureNullable(),
            (new IntegerField('UPDATED_BY'))
                ->configureNullable(),
            // Активность экземпляра: можно выключить блок на время, не удаляя его
            // (значения и история сохраняются). По умолчанию активен. Даты задают
            // окно активности; пустые — без ограничения с этого/до этого края.
            // «Активен сейчас» = ACTIVE=Y И (FROM пуст или наступил) И (TO пуст или
            // не прошёл); считается при чтении, см. ValueService.
            (new BooleanField('ACTIVE', ['values' => ['N', 'Y']]))
                ->configureDefaultValue('Y'),
            // Пустая граница окна = «без ограничения с этого края», поэтому обе
            // колонки обязаны принимать null (см. предупреждение у CREATED_BY).
            (new DatetimeField('ACTIVE_FROM'))
                ->configureNullable(),
            (new DatetimeField('ACTIVE_TO'))
                ->configureNullable(),
        ];
    }
}
