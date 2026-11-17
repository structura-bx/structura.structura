<?php

namespace Structura\Structura\Event;

/**
 * Константы событий поля и построение имени события.
 * Формат имени: <BlockCode>:<FieldCode>:<событие>.
 */
final class FieldEvent
{
    public const ADD    = 'add';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    public const ALL = [self::ADD, self::UPDATE, self::DELETE];

    public static function name(string $blockCode, string $fieldCode, string $event): string
    {
        return sprintf('%s:%s:%s', $blockCode, $fieldCode, $event);
    }
}
