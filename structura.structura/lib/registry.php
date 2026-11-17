<?php

namespace Structura\Structura;

use Structura\Structura\Fields;

/**
 * Реестр типов полей. Сопоставляет короткий строковый тип (используется во
 * фронтенде/Vue и при (де)сериализации) с PHP-классом поля.
 *
 * Сторонний разработчик может зарегистрировать собственное поле:
 *   Registry::register('signature', \Vendor\Fields\Signature::class);
 */
final class Registry
{
    /** @var array<string, class-string<Field>> type => FQCN */
    private static array $types = [];

    private static bool $defaultsLoaded = false;

    public static function register(string $type, string $class): void
    {
        if (!is_subclass_of($class, Field::class)) {
            throw new \InvalidArgumentException(
                sprintf('Field class "%s" must extend %s', $class, Field::class)
            );
        }

        self::$types[$type] = ltrim($class, '\\');
    }

    public static function has(string $type): bool
    {
        return isset(self::$types[$type]);
    }

    /** @return class-string<Field>|null */
    public static function class(string $type): ?string
    {
        return self::$types[$type] ?? null;
    }

    /**
     * Короткий тип по классу поля. Если класс не зарегистрирован — короткое
     * имя класса с маленькой буквы (например, Structura\Structura\Fields\Text => "text").
     */
    public static function typeOf(string $class): string
    {
        $class = ltrim($class, '\\');
        $type  = array_search($class, self::$types, true);
        if ($type !== false) {
            return $type;
        }

        $short = substr(strrchr('\\' . $class, '\\'), 1);

        return lcfirst($short);
    }

    /** @return array<string, class-string<Field>> */
    public static function all(): array
    {
        return self::$types;
    }

    /**
     * Регистрирует все базовые поля модуля. Вызывается один раз из include.php.
     */
    public static function registerDefaults(): void
    {
        if (self::$defaultsLoaded) {
            return;
        }
        self::$defaultsLoaded = true;

        $map = [
            'text'            => Fields\Text::class,
            'hidden'          => Fields\Hidden::class,
            'textarea'        => Fields\TextArea::class,
            'email'           => Fields\Email::class,
            'password'        => Fields\Password::class,
            'number'          => Fields\Number::class,
            'date'            => Fields\Date::class,
            'datetime'        => Fields\DateTime::class,
            'time'            => Fields\Time::class,
            'select'          => Fields\Select::class,
            'checkbox'        => Fields\Checkbox::class,
            'radio'           => Fields\Radio::class,
            'image'           => Fields\Image::class,
            'file'            => Fields\File::class,
            'gallery'         => Fields\Gallery::class,
            'medialibrary'    => Fields\MediaLibrary::class,
            'user'            => Fields\User::class,
            'iblock'          => Fields\Iblock::class,
            'iblockElement'   => Fields\IblockElement::class,
            'iblockSection'   => Fields\IBlockSection::class,
            'highload'        => Fields\Highload::class,
            'highloadElement' => Fields\HighloadElement::class,
            'group'           => Fields\Group::class,
            'repeater'        => Fields\Repeater::class,
            'flexible'        => Fields\Flexible::class,
            'color'           => Fields\Color::class,
        ];

        foreach ($map as $type => $class) {
            self::$types[$type] = $class;
        }
    }
}
