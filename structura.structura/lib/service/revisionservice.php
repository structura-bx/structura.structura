<?php

namespace Structura\Structura\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Structura\Structura\Block;
use Structura\Structura\Config\Loader;
use Structura\Structura\Storage\RevisionTable;

Loc::loadMessages(__FILE__);

/**
 * Ревизии значений блока: запись снапшотов, список, просмотр и откат.
 *
 * Ревизии по умолчанию выключены и включаются на уровне блока
 * (Block::revisions()). История — append-only: откат не переписывает прошлое,
 * а добавляет новую ревизию с пометкой (аудируемая линейная история).
 *
 * Число ревизий сущности при этом жёстко ограничено лимитом блока: откат, как и
 * обычное сохранение, вытесняет самую старую запись. Поэтому «Откат к ревизии
 * #N» — это запись о факте на момент отката, а не гарантированно живая ссылка:
 * саму #N может к тому времени вытеснить prune. Значения при этом не теряются —
 * они лежат в самой ревизии-откате.
 *
 * @see \Structura\Structura\Storage\RevisionTable
 * @see \Structura\Structura\Service\ValueService
 */
final class RevisionService
{
    /** Именованные периоды TTL → дни. */
    private const TTL_DAYS = [
        'week'    => 7,
        'month'   => 30,
        'quarter' => 90,
        'year'    => 365,
    ];

    /**
     * Записывает снапшот значений блока в историю (если у блока включены
     * ревизии) и подрезает старые по лимиту/TTL.
     *
     * @param array<string,mixed> $values итоговые значения после сохранения
     */
    public function record(
        Block $block,
        string $entityType,
        int $entityId,
        string $instanceId,
        array $values,
        ?int $userId = null,
        ?string $comment = null,
        ?int $sourceRevisionId = null,
        string $lang = ''
    ): void {
        if (!$block->revisionsEnabled()) {
            return;
        }

        RevisionTable::add([
            'BLOCK_CODE'         => $block->getCode(),
            'ENTITY_TYPE'        => $entityType,
            'ENTITY_ID'          => $entityId,
            'INSTANCE_ID'        => $instanceId,
            // '' — снимок базы (fallback), иначе метка языка перевода. По нему
            // откат выбирает таблицу; история, таким образом, по-языковая.
            'LANG'               => $lang,
            'VALUE'              => json_encode($values, JSON_UNESCAPED_UNICODE),
            'USER_ID'            => $userId ?? self::currentUserId(),
            'COMMENT'            => $comment !== null ? mb_substr($comment, 0, 255) : null,
            'SOURCE_REVISION_ID' => $sourceRevisionId,
            'CREATED_AT'         => new DateTime(),
        ]);

        $this->prune($block, $entityType, $entityId, $instanceId, $lang);
    }

    /** Удаляет историю экземпляра целиком (вызывается при удалении экземпляра). */
    public function deleteFor(string $entityType, int $entityId, string $instanceId): void
    {
        $rows = RevisionTable::query()
            ->setSelect(['ID'])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->where('INSTANCE_ID', $instanceId)
            ->fetchAll();

        foreach ($rows as $row) {
            RevisionTable::delete($row['ID']);
        }
    }

    /**
     * Список ревизий экземпляра блока (новые сверху), без тела значения.
     *
     * История может быть длинной, поэтому выбирается постранично:
     * $limit = 0 — без ограничения, иначе $limit записей начиная с $offset.
     *
     * @param string|null $instanceId null → единственный экземпляр блока
     *
     * @return array<int, array{id:int, userId:?int, comment:?string,
     *                          sourceRevisionId:?int, createdAt:string}>
     */
    public function listFor(
        string $blockCode,
        string $entityType,
        int $entityId,
        int $limit = 0,
        int $offset = 0,
        ?string $instanceId = null,
        ?string $lang = null
    ): array {
        $query = RevisionTable::query()
            ->setSelect(['ID', 'LANG', 'USER_ID', 'COMMENT', 'SOURCE_REVISION_ID', 'CREATED_AT'])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->where('INSTANCE_ID', self::instanceIdFor($blockCode, $instanceId))
            ->setOrder(['ID' => 'DESC']);

        // История по-языковая: при указанном языке показываем только его
        // ревизии. Вкладка языка по умолчанию собирает и метку fallback, и
        // легаси-'' (см. langScope). null — все языки (общий список).
        $scope = self::langScope($lang);
        if ($scope !== null) {
            $query->whereIn('LANG', $scope);
        }

        if ($limit > 0) {
            $query->setLimit($limit);
            if ($offset > 0) {
                $query->setOffset($offset);
            }
        }

        $rows = [];
        foreach ($query->fetchAll() as $row) {
            $rows[] = [
                'id'               => (int) $row['ID'],
                'lang'             => (string) $row['LANG'],
                'userId'           => $row['USER_ID'] !== null ? (int) $row['USER_ID'] : null,
                'comment'          => $row['COMMENT'] ?? null,
                'sourceRevisionId' => $row['SOURCE_REVISION_ID'] !== null ? (int) $row['SOURCE_REVISION_ID'] : null,
                'createdAt'        => $row['CREATED_AT'] instanceof DateTime
                    ? $row['CREATED_AT']->format('Y-m-d H:i:s')
                    : (string) $row['CREATED_AT'],
            ];
        }

        return $rows;
    }

    /** Сколько всего ревизий у экземпляра — чтобы UI знал, есть ли ещё страницы. */
    public function countFor(
        string $blockCode,
        string $entityType,
        int $entityId,
        ?string $instanceId = null,
        ?string $lang = null
    ): int {
        $filter = [
            '=ENTITY_TYPE' => $entityType,
            '=ENTITY_ID'   => $entityId,
            '=INSTANCE_ID' => self::instanceIdFor($blockCode, $instanceId),
        ];
        $scope = self::langScope($lang);
        if ($scope !== null) {
            $filter['=LANG'] = $scope;
        }

        return (int) RevisionTable::getCount($filter);
    }

    /**
     * Экземпляр по умолчанию для вызовов без instanceId: у обычного блока
     * экземпляр один и его id равен коду блока.
     */
    private static function instanceIdFor(string $blockCode, ?string $instanceId): string
    {
        return ($instanceId !== null && $instanceId !== '') ? $instanceId : $blockCode;
    }

    /**
     * Набор меток `LANG`, составляющих историю запрошенной вкладки.
     *
     * null — все языки (фильтра нет). Вкладка языка по умолчанию (запрос '' или
     * самого fallback) собирает две метки: снимки, помеченные fallback (так их
     * пишет запись переводимого блока), и легаси-'' — снимки без метки
     * (одноязычный блок и данные до появления пометки; язык их базы неизвестен и
     * читается как fallback). Прочие языки — ровно своя метка.
     *
     * @return string[]|null
     */
    private static function langScope(?string $lang): ?array
    {
        if ($lang === null) {
            return null;
        }

        $fallback = Loader::languageFallback();
        if ($lang === '' || ($fallback !== '' && $lang === $fallback)) {
            return $fallback !== '' ? ['', $fallback] : [''];
        }

        return [$lang];
    }

    /**
     * Одна ревизия с декодированными значениями.
     *
     * @return array{id:int, blockCode:string, entityType:string, entityId:int,
     *               values:array, userId:?int, comment:?string,
     *               sourceRevisionId:?int, createdAt:string}|null
     */
    public function get(int $revisionId): ?array
    {
        $row = RevisionTable::query()
            ->setSelect(['*'])
            ->where('ID', $revisionId)
            ->setLimit(1)
            ->fetch();

        if (!$row) {
            return null;
        }

        $decoded = json_decode((string) $row['VALUE'], true);

        return [
            'id'         => (int) $row['ID'],
            'blockCode'  => (string) $row['BLOCK_CODE'],
            'entityType' => (string) $row['ENTITY_TYPE'],
            'entityId'   => (int) $row['ENTITY_ID'],
            'instanceId' => (string) $row['INSTANCE_ID'],
            // Язык снимка: '' — база (fallback), иначе метка перевода. По нему
            // откат выбирает слой.
            'lang'       => (string) $row['LANG'],
            'values'     => is_array($decoded) ? $decoded : [],
            'userId'     => $row['USER_ID'] !== null ? (int) $row['USER_ID'] : null,
            'comment'    => $row['COMMENT'] ?? null,
            'sourceRevisionId' => $row['SOURCE_REVISION_ID'] !== null ? (int) $row['SOURCE_REVISION_ID'] : null,
            'createdAt'  => $row['CREATED_AT'] instanceof DateTime
                ? $row['CREATED_AT']->format('Y-m-d H:i:s')
                : (string) $row['CREATED_AT'],
        ];
    }

    /**
     * Откат значений экземпляра к ревизии. Значения ревизии проходят обычный
     * путь сохранения (валидация + события), создавая новую ревизию с пометкой.
     * Ревизия должна принадлежать тому же экземпляру.
     *
     * @param string|null $instanceId null → единственный экземпляр блока
     */
    public function rollback(
        string $blockCode,
        string $entityType,
        int $entityId,
        int $revisionId,
        ?int $userId = null,
        ?string $instanceId = null
    ): Result {
        $result     = new Result();
        $instanceId = self::instanceIdFor($blockCode, $instanceId);
        $revision   = $this->get($revisionId);

        if ($revision === null) {
            return $result->addError(new Error((string) Loc::getMessage('STRUCTURA_REVISIONSERVICE_NOT_FOUND', ['#REVISION#' => '#' . $revisionId]), 'revision'));
        }

        if (
            $revision['blockCode'] !== $blockCode
            || $revision['entityType'] !== $entityType
            || $revision['entityId'] !== $entityId
            // Иначе копию блока можно было бы откатить к истории соседней копии.
            || $revision['instanceId'] !== $instanceId
        ) {
            return $result->addError(new Error((string) Loc::getMessage('STRUCTURA_REVISIONSERVICE_WRONG_INSTANCE'), 'revision'));
        }

        return (new ValueService())->save(
            $blockCode,
            $entityType,
            $entityId,
            $revision['values'],
            $userId,
            (string) Loc::getMessage('STRUCTURA_REVISIONSERVICE_ROLLBACK_COMMENT', ['#REVISION#' => '#' . $revisionId]),
            $revisionId,
            $instanceId,
            null,
            // Откатываем в тот же слой, что снимала ревизия: '' → база, иначе
            // перевод. Иначе откат перевода уехал бы в базу (и наоборот).
            $revision['lang']
        );
    }

    /**
     * Подрезает историю экземпляра по TTL и лимиту числа ревизий.
     * Лимит блока действует на каждую копию отдельно.
     *
     * История ведётся по языкам: при указанном $lang лимит/TTL считаются в
     * пределах этого языка, чтобы болтливый язык не вытеснял историю другого.
     * $lang === null — по всему экземпляру (совместимость со старым вызовом).
     *
     * @param string|null $instanceId null → единственный экземпляр блока
     * @param string|null $lang       null → все языки; иначе только этот ('' — база)
     */
    public function prune(
        Block $block,
        string $entityType,
        int $entityId,
        ?string $instanceId = null,
        ?string $lang = null
    ): void {
        $config     = $block->getRevisionsConfig();
        $instanceId = self::instanceIdFor($block->getCode(), $instanceId);
        $toDelete   = [];

        $scope  = self::langScope($lang);
        $scoped = static function ($query) use ($entityType, $entityId, $instanceId, $scope) {
            $query->where('ENTITY_TYPE', $entityType)
                ->where('ENTITY_ID', $entityId)
                ->where('INSTANCE_ID', $instanceId);
            if ($scope !== null) {
                $query->whereIn('LANG', $scope);
            }

            return $query;
        };

        // 1. По возрасту (TTL).
        $ttlSeconds = self::ttlSeconds($config['ttl'] ?? null);
        if ($ttlSeconds !== null) {
            $threshold = (new DateTime())->add('-' . $ttlSeconds . ' seconds');
            $old = $scoped(RevisionTable::query()->setSelect(['ID']))
                ->where('CREATED_AT', '<', $threshold)
                ->fetchAll();
            foreach ($old as $row) {
                $toDelete[(int) $row['ID']] = true;
            }
        }

        // 2. По количеству (оставить новейшие N).
        $limit = (int) ($config['limit'] ?? 0);
        if ($limit > 0) {
            $ids = array_map(
                static fn (array $r) => (int) $r['ID'],
                $scoped(RevisionTable::query()->setSelect(['ID']))
                    ->setOrder(['ID' => 'DESC'])
                    ->fetchAll()
            );
            foreach (array_slice($ids, $limit) as $id) {
                $toDelete[$id] = true;
            }
        }

        foreach (array_keys($toDelete) as $id) {
            RevisionTable::delete($id);
        }
    }

    private static function ttlSeconds(string|int|null $ttl): ?int
    {
        if ($ttl === null || $ttl === '') {
            return null;
        }
        if (is_int($ttl)) {
            return $ttl > 0 ? $ttl * 86400 : null; // int трактуется как дни
        }
        if (isset(self::TTL_DAYS[$ttl])) {
            return self::TTL_DAYS[$ttl] * 86400;
        }

        return null;
    }

    private static function currentUserId(): ?int
    {
        if (class_exists(\Bitrix\Main\Engine\CurrentUser::class)) {
            $id = \Bitrix\Main\Engine\CurrentUser::get()->getId();

            return $id ? (int) $id : null;
        }

        return null;
    }
}
