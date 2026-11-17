<?php

namespace Structura\Structura\Access;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Реестр именованных политик доступа — точка расширения ключа `policy`.
 *
 * Групп и списка пользователей хватает не всегда: «только автор элемента»,
 * «только в рабочие часы», «только если элемент не опубликован» — это логика,
 * которую знает проект, а не модуль. Такая проверка регистрируется по имени:
 *
 *   PolicyRegistry::register('author', fn(?int $userId) => $userId === 17);
 *
 * и ставится в конфиге блока рядом с группами:
 *
 *   ->setPolicy(['groups' => [], 'users' => [], 'policy' => ['author']])
 *
 * Имя, а не замыкание в конфиге: конфигурация блока уезжает во фронтенд через
 * json_encode (Block::toArray), а замыкание не сериализуется.
 */
final class PolicyRegistry
{
    /** @var array<string, callable(?int): bool> имя => проверка */
    private static array $checks = [];

    /**
     * @param callable(?int $userId): bool $check true — доступ разрешён
     */
    public static function register(string $name, callable $check): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException((string) Loc::getMessage('STRUCTURA_POLICYREGISTRY_EMPTY_NAME'));
        }

        self::$checks[$name] = $check;
    }

    public static function has(string $name): bool
    {
        return isset(self::$checks[$name]);
    }

    /**
     * Разрешает ли именованная политика доступ этому пользователю.
     *
     * Незарегистрированное имя — исключение, а не «нельзя» и не «можно».
     * Тихо трактовать опечатку как отказ значило бы намертво запереть поле без
     * объяснений, а как разрешение — сделать вид, что защита есть. То же
     * решение, что у неизвестного ключа в visibleOn (E3).
     */
    public static function allows(string $name, ?int $userId): bool
    {
        if (!isset(self::$checks[$name])) {
            throw new \InvalidArgumentException((string) Loc::getMessage('STRUCTURA_POLICYREGISTRY_NOT_REGISTERED', ['#NAME#' => $name]));
        }

        return (bool) (self::$checks[$name])($userId);
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::$checks);
    }

    /** Только для тестов: снять все зарегистрированные политики. */
    public static function reset(): void
    {
        self::$checks = [];
    }
}
