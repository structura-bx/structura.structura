<?php

namespace Structura\Structura\Storage;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

/**
 * История значений блока (ревизии).
 *
 * Append-only снапшоты: на каждое сохранение значений блока (если у блока
 * включены ревизии) добавляется строка со снимком VALUE, автором и временем.
 * Живое значение лежит в structura_value; здесь — журнал версий.
 *
 * @see \Structura\Structura\Service\RevisionService
 */
class RevisionTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'structura_revision';
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
            // История ведётся по экземпляру: у двух копий одного блока на одной
            // сущности свои независимые истории и свои лимиты.
            (new StringField('INSTANCE_ID', ['size' => 32]))
                ->configureRequired(),
            // Язык снимка: метка языка записанной строки (у языка по умолчанию —
            // fallback; '' — легаси/ненастроенные языки). Откат пишет значения в
            // строку этого языка. История, таким образом, по-языковая.
            (new StringField('LANG', ['size' => 50]))
                ->configureDefaultValue(''),
            new TextField('VALUE'),
            (new IntegerField('USER_ID'))
                ->configureNullable(),
            (new StringField('COMMENT', ['size' => 255]))
                ->configureNullable(),
            // Для ревизии-отката — ID исходной ревизии (для аудита и UI).
            // Это запись о факте («откатили к тому, что было ревизией #12»), а не
            // живая ссылка: исходную ревизию может вытеснить prune по лимиту/TTL.
            (new IntegerField('SOURCE_REVISION_ID'))
                ->configureNullable(),
            (new DatetimeField('CREATED_AT'))
                ->configureDefaultValue(static fn () => new DateTime()),
        ];
    }
}
