<?php

namespace Structura\Structura\Access;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Проверка политики доступа блока/поля (`setPolicy`).
 *
 * Политика — это право править. Контроллер отдельно проверяет право видеть
 * сущность через `element_read`: заблокированное поле показывается со
 * значением, но недоступно для изменения.
 *
 * Семантика — белый список, объединение по «или»:
 *   - все три списка пусты → можно всем (по умолчанию);
 *   - иначе можно тому, кто попал хотя бы в один: своя группа в `groups`, свой
 *     ID в `users` или именованная политика из `policy` вернула true.
 *
 * Группа и пользователь задаются числовым ID или символьным кодом: группа —
 * `STRING_ID`, пользователь — логин. Коды переносимы между стендами, числовые
 * ID — нет. Код, который не резолвится, — исключение, а не пропуск: иначе
 * политика из одних кодов стала бы пустой, а пустая доступна всем.
 *
 * Пользователь берётся текущий, из сессии, и не принимается аргументом: userId
 * в сервисах — это автор изменения для истории, решать по нему вопросы доступа
 * нельзя. Администратор обхода политики не получает: «только группа 5» значит
 * только группа 5.
 */
final class Policy
{
    /** @var array<int, string> ID группы => название (кэш на запрос) */
    private static array $groupNameCache = [];

    /** @var array<string, int|null> STRING_ID группы => ID; null — такой нет */
    private static array $groupIdCache = [];

    /** @var array<string, int|null> логин => ID пользователя; null — такого нет */
    private static array $userIdCache = [];

    /**
     * Сбрасывает кэш резолва кодов. Нужен тестам: они заводят группы и
     * пользователей на ходу, а кэш помнит и отрицательный ответ.
     */
    public static function resetResolved(): void
    {
        self::$groupIdCache   = [];
        self::$userIdCache    = [];
        self::$groupNameCache = [];
    }

    /** Разрешает ли политика правку текущему пользователю. */
    public static function allows(array $policy): bool
    {
        return self::allowsFor($policy, self::currentUserId(), self::currentUserGroups());
    }

    /**
     * Причина запрета — человеку, а не в лог. null, если разрешено. Не «нет
     * прав», а из-за чего именно: перечисляем группы (по названиям, а не по ID),
     * которым поле доступно.
     */
    public static function reason(array $policy): ?string
    {
        if (self::allows($policy)) {
            return null;
        }

        // С пустой политикой сюда не попасть: она разрешает всем, и allows()
        // вернул бы true. Запасной текст — на всякий случай.
        return self::describe($policy) ?? (string) Loc::getMessage('STRUCTURA_POLICY_LOCKED');
    }

    /**
     * Описание самой политики, без оглядки на текущего пользователя.
     * null — ограничений нет (доступно всем).
     *
     * Отдельно от reason(): тот отвечает «почему я не могу» и для того, кому
     * доступ разрешён, возвращает null. Экрану аудита нужно «кому вообще открыто
     * поле», независимо от того, кто смотрит.
     */
    public static function describe(array $policy): ?string
    {
        $parts  = [];
        $groups = self::groupIds($policy['groups'] ?? []);
        $users  = self::userIds($policy['users'] ?? []);
        // array_values обязателен: array_filter сохраняет ключи, и после отсева
        // пустых имён $named[0] мог бы не существовать.
        $named = array_values(array_filter(
            array_map('strval', (array) ($policy['policy'] ?? [])),
            static fn ($n) => $n !== ''
        ));

        if ($groups !== []) {
            $names = self::groupNames($groups);
            $parts[] = (string) Loc::getMessage('STRUCTURA_POLICY_TO_GROUPS', ['#NAMES#' => implode(', ', $names)]);
        }

        if ($users !== []) {
            $parts[] = count($users) === 1
                ? (string) Loc::getMessage('STRUCTURA_POLICY_USER_ONE')
                : (string) Loc::getMessage('STRUCTURA_POLICY_USER_MANY', ['#COUNT#' => count($users)]);
        }

        if ($named !== []) {
            $parts[] = count($named) === 1
                ? (string) Loc::getMessage('STRUCTURA_POLICY_COND_ONE', ['#NAME#' => $named[0]])
                : (string) Loc::getMessage('STRUCTURA_POLICY_COND_MANY', ['#NAMES#' => implode(', ', $named)]);
        }

        if ($parts === []) {
            return null;   // ограничений нет
        }

        return (string) Loc::getMessage('STRUCTURA_POLICY_AVAILABLE_ONLY', ['#LIST#' => implode('; ', $parts)]);
    }

    /**
     * Чистая проверка без обращения к сессии — точка входа для тестов.
     *
     * @param array<int, int> $userGroups
     */
    public static function allowsFor(array $policy, ?int $userId, array $userGroups): bool
    {
        $groups = self::groupIds($policy['groups'] ?? []);
        $users  = self::userIds($policy['users'] ?? []);
        $named  = array_values(array_filter(
            array_map('strval', (array) ($policy['policy'] ?? [])),
            static fn ($n) => $n !== ''
        ));

        // Ничего не задано — ограничений нет.
        if ($groups === [] && $users === [] && $named === []) {
            return true;
        }

        if ($userId !== null && in_array($userId, $users, true)) {
            return true;
        }

        if (array_intersect($groups, self::ids($userGroups)) !== []) {
            return true;
        }

        foreach ($named as $name) {
            if (PolicyRegistry::allows($name, $userId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Числовые ID «как есть». Служит для списков, где кодов не бывает по
     * определению: группы текущего пользователя приходят из сессии уже числами.
     *
     * @param mixed $raw
     * @return array<int, int>
     */
    private static function ids($raw): array
    {
        $out = [];
        foreach ((array) $raw as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $id = (int) $id;
                if ($id > 0) {
                    $out[] = $id;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Группы политики: числовой ID — как есть, строка — код `STRING_ID`.
     *
     * @param mixed $raw
     * @return array<int, int>
     */
    private static function groupIds($raw): array
    {
        return self::resolveList(
            $raw,
            static fn (string $code): ?int => self::lookupGroup($code),
            self::$groupIdCache,
            'STRUCTURA_POLICY_KIND_GROUP',
            'STRUCTURA_POLICY_BY_GROUP'
        );
    }

    /**
     * Пользователи политики: числовой ID — как есть, строка — логин.
     *
     * @param mixed $raw
     * @return array<int, int>
     */
    private static function userIds($raw): array
    {
        return self::resolveList(
            $raw,
            static fn (string $login): ?int => self::lookupUser($login),
            self::$userIdCache,
            'STRUCTURA_POLICY_KIND_USER',
            'STRUCTURA_POLICY_BY_USER'
        );
    }

    /**
     * Общая часть резолва: числа пропускаем, строки ищем через $lookup и
     * запоминаем результат (в том числе отрицательный — иначе опечатка стоила бы
     * запроса к БД на каждое поле формы). Числовое значение — это ID, а не код:
     * '5' значит ID 5, кодов из одних цифр не бывает.
     *
     * @param mixed                       $raw
     * @param callable(string): ?int      $lookup
     * @param array<string, int|null>     $cache
     * @return array<int, int>
     */
    private static function resolveList($raw, callable $lookup, array &$cache, string $what, string $by): array
    {
        $out = [];

        foreach ((array) $raw as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $id = (int) $value;
                if ($id > 0) {
                    $out[] = $id;
                }

                continue;
            }

            if (!is_string($value) || trim($value) === '') {
                continue;   // не код и не ID — сказать про такое нечего
            }

            $code = trim($value);

            if (!array_key_exists($code, $cache)) {
                $cache[$code] = $lookup($code);
            }

            if ($cache[$code] === null) {
                throw new \InvalidArgumentException((string) Loc::getMessage('STRUCTURA_POLICY_UNKNOWN_REF', [
                    '#KIND#' => (string) Loc::getMessage($what),
                    '#NAME#' => $code,
                    '#BY#'   => (string) Loc::getMessage($by),
                ]));
            }

            $out[] = $cache[$code];
        }

        return array_values(array_unique($out));
    }

    private static function lookupGroup(string $code): ?int
    {
        if (!class_exists(\Bitrix\Main\GroupTable::class)) {
            return null;
        }

        $row = \Bitrix\Main\GroupTable::getList([
            'select' => ['ID'],
            'filter' => ['=STRING_ID' => $code],
            'limit'  => 1,
        ])->fetch();

        return $row ? (int) $row['ID'] : null;
    }

    private static function lookupUser(string $login): ?int
    {
        if (!class_exists(\Bitrix\Main\UserTable::class)) {
            return null;
        }

        $row = \Bitrix\Main\UserTable::getList([
            'select' => ['ID'],
            'filter' => ['=LOGIN' => $login],
            'limit'  => 1,
        ])->fetch();

        return $row ? (int) $row['ID'] : null;
    }

    /**
     * Названия групп по ID. Группа могла быть удалена — тогда показываем ID:
     * «доступно группе 7» хуже, чем «Контент-менеджеры», но лучше, чем пусто.
     *
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    private static function groupNames(array $ids): array
    {
        // Кэш на запрос: reason() зовётся на каждое заблокированное поле, а
        // группы у них обычно одни — без кэша это N запросов к БД на одну форму.
        $missing = array_values(array_diff($ids, array_keys(self::$groupNameCache)));

        if ($missing !== [] && class_exists(\Bitrix\Main\GroupTable::class)) {
            $rows = \Bitrix\Main\GroupTable::getList([
                'select' => ['ID', 'NAME'],
                'filter' => ['=ID' => $missing],
            ])->fetchAll();

            foreach ($rows as $row) {
                self::$groupNameCache[(int) $row['ID']] = (string) $row['NAME'];
            }
        }

        return array_map(
            static fn (int $id) => self::$groupNameCache[$id] ?? ('#' . $id),
            $ids
        );
    }

    private static function currentUserId(): ?int
    {
        if (!class_exists(\Bitrix\Main\Engine\CurrentUser::class)) {
            return null;
        }

        $id = \Bitrix\Main\Engine\CurrentUser::get()->getId();

        return $id ? (int) $id : null;
    }

    /** @return array<int, int> */
    private static function currentUserGroups(): array
    {
        if (!class_exists(\Bitrix\Main\Engine\CurrentUser::class)) {
            return [];
        }

        return self::ids(\Bitrix\Main\Engine\CurrentUser::get()->getUserGroups() ?: []);
    }
}
