<?php

namespace Structura\Structura\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Structura\Structura\Access\Policy;
use Structura\Structura\Access\PolicyGuard;
use Structura\Structura\Block;
use Structura\Structura\Config\Loader;
use Structura\Structura\Event\BlockEvent;
use Structura\Structura\Event\FieldEvent;
use Structura\Structura\Field;
use Structura\Structura\Storage\ValueTable;
use Structura\Structura\Validation\Validator;

Loc::loadMessages(__FILE__);

/**
 * Сервис работы со значениями блоков: чтение, валидация, сохранение,
 * удаление и рассылка событий полей <BlockCode>:<FieldCode>:<событие>.
 *
 * Единица хранения — экземпляр блока на сущности, адрес
 * (entityType, entityId, instanceId). Блок с ->multiple() может иметь
 * несколько экземпляров; у обычного блока экземпляр один и его идентификатор
 * равен коду блока — поэтому вызовы без instanceId продолжают работать.
 */
final class ValueService
{
    public const MODULE_ID = STRUCTURA_MODULE_ID;

    /**
     * Идентификатор «основного» экземпляра для вызовов без instanceId — код блока.
     *
     * У любого блока (в том числе множественного) экземпляр с id = код блока
     * считается основным: так адресация остаётся однозначной, а данные, созданные
     * до включения ->multiple(), читаются и переписываются прежними вызовами без
     * миграции. Дополнительные копии множественного блока получают собственные
     * instanceId (их выдаёт фронтенд или передаёт вызывающий код).
     *
     * Раньше здесь бросалось исключение для множественного блока. Отменено:
     * добавление ->multiple() к блоку не должно ломать код, который уже
     * обращался к нему без instanceId — «основной» экземпляр остаётся под кодом
     * блока, как и был.
     */
    public function defaultInstanceId(Block $block): string
    {
        return $block->getCode();
    }

    /**
     * Значения экземпляра блока на языке. Если строки нет — пустой массив.
     *
     * Каждый язык экземпляра — отдельная строка structura_value (колонка `LANG`);
     * читаем ровно строку запрошенного языка. Чтение сырое, без кросс-языкового
     * фолбэка: промах даёт [], а не другой язык. Фолбэк «нет перевода → язык по
     * умолчанию» — забота публичного API ({@see \Structura\Structura\Blocks}),
     * чтобы админка отличала пустую вкладку от заполненной.
     *
     * @param string|null $instanceId null → единственный экземпляр блока
     * @param string|null $language   null → язык по умолчанию (fallback)
     *
     * @return array<string,mixed>
     */
    public function get(
        string $blockCode,
        string $entityType,
        int $entityId,
        ?string $instanceId = null,
        ?string $language = null
    ): array {
        $instanceId = $this->resolveInstanceId($blockCode, $instanceId);
        if ($instanceId === null) {
            return [];
        }

        $block = Loader::load($blockCode);
        if ($block === null) {
            return [];
        }

        $row = $this->langRow($entityType, $entityId, $instanceId, $this->readLangs($block, $language), ['VALUE']);

        return self::decodeValue($row['VALUE'] ?? null);
    }

    /**
     * Метки `LANG`, по которым читается запрошенный язык.
     *
     * Язык по умолчанию (fallback) читается и как своя метка, и как легаси-''
     * (данные со старых установок без метки). Прочие языки — ровно своя метка.
     * Непереводимый блок язык игнорирует: его единственная строка помечена
     * fallback (или легаси '').
     *
     * @return string[]
     */
    private function readLangs(Block $block, ?string $language): array
    {
        $fallback = Loader::languageFallback();

        if (!$block->isTranslatable()) {
            return $fallback !== '' ? [$fallback, ''] : [''];
        }

        $lang = ($language === null || $language === '') ? $fallback : $language;

        if ($fallback !== '' && $lang === $fallback) {
            return [$fallback, ''];
        }

        return [$lang];
    }

    /**
     * Строка значения экземпляра для набора меток языка (первая подходящая).
     *
     * При совпадении с несколькими метками (fallback и легаси '' одновременно)
     * предпочитается непустая метка. Строк на экземпляр немного (по одной на
     * язык), поэтому забираем кандидатов одним запросом и выбираем в памяти.
     *
     * @param string[] $langs
     * @param string[] $select колонки (LANG добавляется автоматически)
     * @return array<string,mixed>|null
     */
    private function langRow(string $entityType, int $entityId, string $instanceId, array $langs, array $select): ?array
    {
        if ($langs === []) {
            return null;
        }
        if (!in_array('LANG', $select, true)) {
            $select[] = 'LANG';
        }

        $rows = ValueTable::query()
            ->setSelect($select)
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->where('INSTANCE_ID', $instanceId)
            ->whereIn('LANG', $langs)
            ->fetchAll();

        if ($rows === []) {
            return null;
        }

        // Непустая метка (fallback) вперёд легаси-'' (непустая строка > пустой).
        usort($rows, static fn (array $a, array $b): int => (string) $b['LANG'] <=> (string) $a['LANG']);

        return $rows[0];
    }

    /**
     * Метка языка для строки, когда язык адресации пуст — текущий
     * `language_fallback`.
     *
     * Пустой язык адресации бывает у блока без `->translatable()`: язык в его
     * чтении не участвует, но метку строка всё равно несёт — чтобы читалась как
     * язык по умолчанию. Пустая метка остаётся лишь там, где языков нет вовсе
     * (`languages` не настроены): fallback тоже пуст.
     */
    private static function baseLang(): string
    {
        return Loader::languageFallback();
    }

    /**
     * JSON значения слоя в массив. Пусто/невалидно → [].
     */
    private static function decodeValue(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Заполнен ли слой языка (есть ли сохранённое значение).
     *
     * Отличается от `get() !== []` смыслом, а не результатом: вкладке языка
     * нужно знать, переводили ли уже этот экземпляр, — по значениям с
     * подставленными умолчаниями (getWithDefaults) это неотличимо от свежего
     * перевода, и админка не смогла бы предложить «заполнить из базы».
     *
     * @param string|null $language null → язык по умолчанию (fallback = база)
     */
    public function isFilled(
        string $blockCode,
        string $entityType,
        int $entityId,
        ?string $instanceId = null,
        ?string $language = null
    ): bool {
        return $this->get($blockCode, $entityType, $entityId, $instanceId, $language) !== [];
    }

    /**
     * Какие языки уже заполнены у экземпляров сущности.
     *
     * Один запрос на всю сущность: вкладке нужно отметить незаполненные языки
     * сразу у всех блоков формы. Каждый язык экземпляра — своя строка
     * structura_value, поэтому это просто набор меток `LANG` по экземпляру.
     * Легаси-'' (строка без метки) считается языком по умолчанию.
     *
     * @return array<string, string[]> instanceId => метки заполненных языков
     */
    public function translatedLanguages(string $entityType, int $entityId): array
    {
        $fallback = Loader::languageFallback();
        $map      = [];

        $rows = ValueTable::query()
            ->setSelect(['INSTANCE_ID', 'LANG'])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->fetchAll();

        foreach ($rows as $row) {
            $lang = (string) $row['LANG'];
            if ($lang === '') {
                $lang = $fallback; // легаси-строка без метки = язык по умолчанию
            }
            if ($lang !== '') {
                $map[(string) $row['INSTANCE_ID']][] = $lang;
            }
        }

        foreach ($map as $instanceId => $langs) {
            $map[$instanceId] = array_values(array_unique($langs));
        }

        return $map;
    }

    /**
     * Все экземпляры блоков сущности в порядке отображения.
     *
     * $activeOnly отбрасывает выключенные И вышедшие из окна дат (activeNow=false)
     * — это путь публичного чтения на сайте ({@see \Structura\Structura\Blocks}).
     * По умолчанию отдаются все (админке нужно видеть и выключенные, чтобы их
     * включить), а флаг/даты/`activeNow` едут в каждом экземпляре.
     *
     * @return array<int, array{instanceId:string, blockCode:string, sort:int,
     *         values:array, active:bool, activeFrom:?string, activeTo:?string,
     *         activeNow:bool}>
     */
    public function listInstances(
        string $entityType,
        int $entityId,
        ?string $blockCode = null,
        bool $activeOnly = false
    ): array {
        $query = ValueTable::query()
            ->setSelect([
                'ID', 'BLOCK_CODE', 'INSTANCE_ID', 'LANG', 'SORT', 'VALUE',
                'CREATED_AT', 'UPDATED_AT', 'CREATED_BY', 'UPDATED_BY',
                'ACTIVE', 'ACTIVE_FROM', 'ACTIVE_TO',
            ])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->setOrder(['SORT' => 'ASC', 'ID' => 'ASC']);

        if ($blockCode !== null) {
            $query->where('BLOCK_CODE', $blockCode);
        }

        // Экземпляр — несколько строк (по одной на язык). Группируем, сохраняя
        // порядок появления (SORT,ID). Метаданные (SORT/ACTIVE/даты/авторство)
        // зеркалятся на все языки при записи, поэтому берём их из «первичной»
        // строки экземпляра (язык по умолчанию); values/lang — тоже её, а
        // конкретный язык публичное чтение возьмёт отдельно ({@see Blocks}).
        $fallback  = Loader::languageFallback();
        $byInstance = [];
        $order      = [];
        foreach ($query->fetchAll() as $row) {
            $id = (string) $row['INSTANCE_ID'];
            if (!isset($byInstance[$id])) {
                $byInstance[$id] = [];
                $order[]         = $id;
            }
            $byInstance[$id][] = $row;
        }

        $instances = [];
        foreach ($order as $id) {
            $row = $this->primaryRow($byInstance[$id], $fallback);

            // fetchAll отдаёт ACTIVE сырой строкой 'Y'/'N', а не bool: (bool)'N'
            // было бы true (непустая строка). Сверяем со значением явно.
            $active = $row['ACTIVE'] === 'Y' || $row['ACTIVE'] === true;
            $from   = $row['ACTIVE_FROM'] instanceof DateTime ? $row['ACTIVE_FROM'] : null;
            $to     = $row['ACTIVE_TO'] instanceof DateTime ? $row['ACTIVE_TO'] : null;

            $activeNow = self::isActiveNow($active, $from, $to);
            if ($activeOnly && !$activeNow) {
                continue;
            }

            $decoded = json_decode((string) $row['VALUE'], true);
            $instances[] = [
                'instanceId' => $id,
                'blockCode'  => (string) $row['BLOCK_CODE'],
                // Язык «первичной» строки экземпляра ('' — легаси без метки).
                // Публичному чтению нужен, чтобы понять, несёт ли эта строка уже
                // запрошенный язык или его надо взять из строки другого языка.
                'lang'       => (string) $row['LANG'],
                'sort'       => (int) $row['SORT'],
                'values'     => is_array($decoded) ? $decoded : [],
                // Авторство экземпляра. null — автор неизвестен (данные со старых
                // установок либо системная запись без пользователя).
                'createdBy'  => $row['CREATED_BY'] !== null ? (int) $row['CREATED_BY'] : null,
                'updatedBy'  => $row['UPDATED_BY'] !== null ? (int) $row['UPDATED_BY'] : null,
                'createdAt'  => $row['CREATED_AT'] instanceof DateTime ? $row['CREATED_AT']->toString() : null,
                'updatedAt'  => $row['UPDATED_AT'] instanceof DateTime ? $row['UPDATED_AT']->toString() : null,
                // Активность: сырой флаг и даты — для редактирования в админке;
                // activeNow — вычисленное «активен прямо сейчас» с учётом окна дат.
                // Даты в стабильном ISO (Y-m-d H:i:s), а не в локальном формате:
                // так их однозначно и показать, и разобрать обратно при сохранении.
                'active'     => $active,
                'activeFrom' => $from?->format('Y-m-d H:i:s'),
                'activeTo'   => $to?->format('Y-m-d H:i:s'),
                'activeNow'  => $activeNow,
            ];
        }

        return $instances;
    }

    /**
     * «Первичная» строка экземпляра среди его языковых строк: язык по умолчанию
     * (fallback), иначе легаси-'' (данные без метки), иначе первая по порядку.
     * От неё берутся метаданные (они зеркалены) и значение языка по умолчанию.
     *
     * @param array<int, array<string, mixed>> $rows строки одного экземпляра
     * @return array<string, mixed>
     */
    private function primaryRow(array $rows, string $fallback): array
    {
        if ($fallback !== '') {
            foreach ($rows as $row) {
                if ((string) $row['LANG'] === $fallback) {
                    return $row;
                }
            }
        }
        foreach ($rows as $row) {
            if ((string) $row['LANG'] === '') {
                return $row;
            }
        }

        return $rows[0];
    }

    /**
     * Значения, у которых больше нет конфигурации блока.
     *
     * Код блока = имя папки, поэтому переименование или удаление папки делает
     * все сохранённые значения этого блока безадресными: `Loader::load()`
     * возвращает null, вкладка такой экземпляр пропускает, и данные лежат в БД,
     * не показываясь нигде и никогда не попадая на сайт. Со стороны это
     * выглядит как «значения пропали», хотя чинится возвратом папки, — поэтому
     * молчать нельзя.
     *
     * «Блока нет» и «блок не доступен этой сущности» — разные вещи. Второе это
     * обычная настройка `visibleOn`: конфигурация на месте, значения покажутся
     * там, где блок виден. Сиротой считается только первое.
     *
     * Считаем по коду блока, а не по экземплярам: пользователю нужно знать,
     * какую папку вернуть, а сколько внутри копий — уже подробность.
     *
     * @return array<int, array{blockCode: string, count: int}>
     */
    public function orphanInstances(string $entityType, int $entityId): array
    {
        $counts = [];

        foreach ($this->listInstances($entityType, $entityId) as $instance) {
            $code = $instance['blockCode'];

            if (!array_key_exists($code, $counts)) {
                // Загрузка кэшируется самим Loader'ом, поэтому повторные копии
                // одного блока лишних обращений к диску не дают.
                $counts[$code] = Loader::load($code) === null ? 0 : null;
            }

            if ($counts[$code] !== null) {
                $counts[$code]++;
            }
        }

        $orphans = [];
        foreach ($counts as $code => $count) {
            if ($count !== null) {
                $orphans[] = ['blockCode' => $code, 'count' => $count];
            }
        }

        return $orphans;
    }

    /**
     * Активен ли экземпляр прямо сейчас: снят ли флаг и попадает ли текущий
     * момент в окно дат. Пустой край окна — без ограничения с этой стороны.
     */
    private static function isActiveNow(bool $active, ?DateTime $from, ?DateTime $to): bool
    {
        if (!$active) {
            return false;
        }

        $now = time();
        if ($from !== null && $from->getTimestamp() > $now) {
            return false;
        }
        if ($to !== null && $to->getTimestamp() < $now) {
            return false;
        }

        return true;
    }

    /**
     * Задаёт порядок экземпляров на сущности. Позиция = место в массиве.
     * Экземпляры, не попавшие в список, сохраняют текущий порядок после указанных.
     *
     * @param array<int, string> $instanceIds id экземпляров в нужном порядке
     */
    public function reorder(string $entityType, int $entityId, array $instanceIds): Result
    {
        $result = new Result();

        $known = [];
        foreach ($this->listInstances($entityType, $entityId) as $instance) {
            $known[$instance['instanceId']] = true;
        }

        foreach ($instanceIds as $position => $instanceId) {
            if (!isset($known[$instanceId])) {
                return $result->addError(new Error(
                    (string) Loc::getMessage('STRUCTURA_VALUESERVICE_INSTANCE_NOT_OWNED', ['#INSTANCE#' => $instanceId]),
                    'instance'
                ));
            }

            // SORT общий на экземпляр — зеркалим на все его языковые строки,
            // иначе языки разъехались бы по позиции и группировка в listInstances
            // получила бы разный порядок.
            $rows = ValueTable::query()
                ->setSelect(['ID'])
                ->where('ENTITY_TYPE', $entityType)
                ->where('ENTITY_ID', $entityId)
                ->where('INSTANCE_ID', $instanceId)
                ->fetchAll();

            foreach ($rows as $row) {
                ValueTable::update($row['ID'], ['SORT' => $position]);
            }
        }

        return $result;
    }

    /**
     * Значения экземпляра с подстановкой значений по умолчанию из конфигурации.
     *
     * @return array<string,mixed>
     */
    public function getWithDefaults(
        string $blockCode,
        string $entityType,
        int $entityId,
        ?string $instanceId = null,
        ?string $language = null
    ): array {
        $stored = $this->get($blockCode, $entityType, $entityId, $instanceId, $language);
        $block  = Loader::load($blockCode);
        if ($block === null) {
            return $stored;
        }

        // Настроенный ->default() подставляем только свежему слою — тому, что
        // никогда не сохраняли (`get()` вернул []). У сохранённого слоя
        // отсутствие ключа значит «поле оставили пустым», и возвращать сюда
        // умолчание нельзя: иначе очищенное поле снова показывало бы default, а
        // следующее сохранение его бы и записало.
        // array_key_exists, а не ??: сохранённый null — это значение, не «нет ключа».
        $fresh  = $stored === [];
        $result = [];
        foreach ($block->getFields() as $field) {
            $code = $field->getCode();
            if (array_key_exists($code, $stored)) {
                $result[$code] = $stored[$code];
            } else {
                $result[$code] = $fresh ? $field->normalizeDefault() : $field->emptyValue();
            }
        }

        return $result + $stored;
    }

    /**
     * Приводит необязательный instanceId к конкретному. null — блок не найден.
     */
    private function resolveInstanceId(string $blockCode, ?string $instanceId): ?string
    {
        if ($instanceId !== null && $instanceId !== '') {
            return $instanceId;
        }

        $block = Loader::load($blockCode);

        return $block === null ? null : $this->defaultInstanceId($block);
    }

    /**
     * Рекурсивная валидация значений по конфигурации блока.
     * Ошибки пригодны для отображения: код ошибки — путь к полю (напр. "slides.0.bg").
     */
    public function validate(Block $block, array $values): Result
    {
        return (new Validator())->validateBlock($block, $values);
    }

    /**
     * Сохраняет значения блока для сущности. Выполняет валидацию, пишет JSON
     * и рассылает события add/update/delete по изменившимся полям. Если у блока
     * включены ревизии — фиксирует снапшот в истории (RevisionService).
     *
     * @param array<string,mixed> $values
     * @param int|null    $userId  автор изменения (для истории; null → текущий)
     * @param string|null $comment пометка ревизии (например, при откате)
     * @param int|null    $sourceRevisionId при откате — ID исходной ревизии
     * @param string|null $instanceId экземпляр блока; null → «основной»
     *                    экземпляр (id = код блока), в т.ч. у множественного
     * @param array{active: bool, from: ?DateTime, to: ?DateTime}|null $activity
     *        активность экземпляра; null → не менять (у нового — активен по умолчанию)
     */
    public function save(
        string $blockCode,
        string $entityType,
        int $entityId,
        array $values,
        ?int $userId = null,
        ?string $comment = null,
        ?int $sourceRevisionId = null,
        ?string $instanceId = null,
        ?array $activity = null,
        ?string $language = null
    ): Result {
        return $this->write(
            $blockCode,
            $entityType,
            $entityId,
            $values,
            $userId,
            $comment,
            $sourceRevisionId,
            $instanceId,
            true,
            $activity,
            $language
        );
    }

    /**
     * Запись от лица системы: политики полей не применяются.
     *
     * Для доверенного серверного кода — импорта, миграций, публичного API
     * ({@see \Structura\Structura\Blocks}). Политика отвечает на вопрос «кому из
     * пользователей можно править это поле», а у скрипта пользователя нет:
     * Policy смотрит на текущего, и в CLI поля под политиками молча откатились
     * бы к старому значению — импорт потерял бы часть данных, не сказав ни
     * слова.
     *
     * Всё остальное на месте: валидация, правило unique-группы, события полей
     * и запись в историю. Проверки прав здесь нет и не было — как и у чтения,
     * это доверенный серверный API.
     *
     * @param array<string,mixed> $values
     */
    public function saveSystem(
        string $blockCode,
        string $entityType,
        int $entityId,
        array $values,
        ?string $instanceId = null,
        ?int $userId = null,
        ?string $comment = null,
        ?array $activity = null,
        ?string $language = null
    ): Result {
        return $this->write(
            $blockCode,
            $entityType,
            $entityId,
            $values,
            $userId,
            $comment,
            null,
            $instanceId,
            false,
            $activity,
            $language
        );
    }

    /**
     * Общее тело сохранения.
     *
     * @param array<string,mixed> $values
     * @param bool $applyPolicies применять ли политики полей текущего
     *             пользователя (false — системная запись, см. saveSystem)
     */
    private function write(
        string $blockCode,
        string $entityType,
        int $entityId,
        array $values,
        ?int $userId,
        ?string $comment,
        ?int $sourceRevisionId,
        ?string $instanceId,
        bool $applyPolicies,
        ?array $activity = null,
        ?string $language = null
    ): Result {
        $result = new Result();

        $block = Loader::load($blockCode);
        if ($block === null) {
            return $result->addError(new Error((string) Loc::getMessage('STRUCTURA_VALUESERVICE_BLOCK_NOT_FOUND', ['#BLOCK#' => $blockCode]), 'block'));
        }

        if ($instanceId === null || $instanceId === '') {
            $instanceId = $this->defaultInstanceId($block);
        }

        // Идентификатор экземпляра приходит от клиента (фронтенд выдаёт его сам
        // новым копиям), поэтому проверяется здесь — на единственной границе
        // записи. Мимо неё не пройдут ни админский роут, ни публичное API.
        $badInstance = self::instanceIdError($instanceId);
        if ($badInstance !== null) {
            return $result->addError(new Error($badInstance, 'instance'));
        }

        // Язык тоже от клиента. Метка вне настроенного списка завела бы строку
        // перевода на язык, которого нет: прочитать её потом нечем (вкладок для
        // него не рисуется, публичный API про него не знает), зато растёт она
        // без предела — на каждую выдуманную метку своя строка.
        $badLanguage = self::languageError($block, $language);
        if ($badLanguage !== null) {
            return $result->addError(new Error($badLanguage, 'language'));
        }

        // Каждый язык экземпляра — отдельная строка structura_value. Выбираем
        // язык записи и берём текущие строки экземпляра (для проверок
        // принадлежности/размещённости и зеркаливания метаданных).
        // Всё дальше (политики, RowId, валидация, события) работает с плоской
        // картой полей одного языка — язык снимается здесь.
        $fallback  = Loader::languageFallback();
        $writeLang = $this->writeLang($block, $language);
        $rows      = $this->instanceRows($entityType, $entityId, $instanceId);
        $target    = $this->pickWriteRow($rows, $writeLang, $fallback);
        $old       = self::decodeValue($target['VALUE'] ?? null);

        // Поля, которых больше нет в конфигурации, из значения выбрасываем: код
        // убрали из config.php — его значение уходит при первом же сохранении,
        // а не лежит в БД мёртвым грузом. Обратимо: прежние ревизии значение
        // хранят. Делаем это до всего прочего, чтобы политики, RowId, валидация
        // и история видели ровно то, что ляжет в БД.
        $values = $this->keepConfiguredFields($block, $values);

        // Политики применяем до валидации: запрещённое поле берёт старое
        // значение, и валидировать надо то, что реально ляжет в БД. Фронтенду
        // тут веры нет — запрос собирается руками, решает сервер.
        $guard = new PolicyGuard();
        // Запрет на самом блоке запирает и его значения (это делает merge), и сам
        // экземпляр: активность, окно дат. Выключенный блок исчезает с сайта так
        // же, как стёртые значения, поэтому право на это — то же самое право.
        $blockLocked = false;
        if ($applyPolicies) {
            $values      = $guard->merge($block, $values, $old);
            $blockLocked = !Policy::allows($block->getPolicy());
        }

        // Запертый политикой блок нельзя создавать: правка существующей строки
        // остаётся no-op (merge вернул $old, denied[''] уже проставлен), но
        // появление новой строки — нового экземпляра или новой языковой строки —
        // это создание, а право на него — то же право на правку, которого нет.
        // Иначе запрет на блоке не мешал добавить его копию «с базовыми полями».
        // $target === null ⇒ строки для этой записи ещё нет (будет INSERT).
        if ($blockLocked && $target === null) {
            return $result->addError(new Error(
                Policy::reason($block->getPolicy()) ?? (string) Loc::getMessage('STRUCTURA_POLICY_LOCKED'),
                'block'
            ));
        }

        // Файлы здесь не разводятся. Значение, приехавшее из другого языка
        // кнопкой «Заполнить из …» или из склонированной строки, остаётся на том
        // же файле: это один контент одной сущности. Копия делается в одном месте
        // — при копировании элемента ({@see CopyService}). Общий ID файла у
        // нескольких значений — штатное состояние, и цену за него платит удаление
        // ({@see FileService::delete} проверяет, не держит ли файл ещё какое-то
        // значение этой сущности).

        // Идентификаторы строк проставляются после слияния политик: оно ищет
        // старую строку по присланному `_id`. При обратном порядке каждая
        // безымянная строка со старых данных получила бы свежий id, была бы
        // принята за новую, и её запрещённые поля обнулились бы вместо
        // сохранения старого значения.
        $values = RowId::assign($block->getFields(), $values);

        $validation = $this->validate($block, $values);
        if (!$validation->isSuccess()) {
            return $result->addErrors($validation->getErrors());
        }

        // Автор изменения: явно переданный, иначе текущий пользователь. У
        // системной записи (импорт, CLI) пользователя нет — тогда null: автор
        // неизвестен. Совпадает с тем, кого RevisionService пишет в USER_ID.
        $author = $userId ?? self::currentUserId();
        $json   = json_encode($values, JSON_UNESCAPED_UNICODE);

        // Принадлежность экземпляра блоку: любая его строка знает BLOCK_CODE.
        if ($rows !== [] && (string) $rows[0]['BLOCK_CODE'] !== $blockCode) {
            return $result->addError(new Error(
                (string) Loc::getMessage('STRUCTURA_VALUESERVICE_INSTANCE_WRONG_BLOCK', [
                    '#INSTANCE#' => $instanceId,
                    '#ACTUAL#'   => (string) $rows[0]['BLOCK_CODE'],
                    '#BLOCK#'    => $blockCode,
                ]),
                'instance'
            ));
        }

        // Язык по умолчанию (fallback) размещает экземпляр; прочие языки — перевод
        // уже размещённого. Перевод без размещения нигде не показался бы
        // (listInstances идёт по строкам). У непереводимого блока язык записи =
        // fallback, поэтому он всегда размещающий.
        $primary = ($writeLang === $fallback);
        if (!$primary && $rows === []) {
            return $result->addError(new Error(
                (string) Loc::getMessage('STRUCTURA_VALUESERVICE_INSTANCE_NOT_PLACED', ['#INSTANCE#' => $instanceId]),
                'instance'
            ));
        }

        // Правило unique-группы — при размещении (первая строка экземпляра).
        if ($rows === []) {
            $conflict = $this->uniqueGroupConflict($blockCode, $entityType, $entityId, $instanceId);
            if ($conflict !== null) {
                return $result->addError(new Error($conflict, 'group'));
            }
        }

        // Событие блока до записи: обработчик может запретить сохранение
        // (create/update). Поднимаем на уже готовом к записи значении (после
        // политик, RowId и валидации), но до самой записи — вето ничего не
        // должно оставить в БД.
        $veto = $this->fireBlockEvent(BlockEvent::BEFORE_SAVE, [
            'blockCode'  => $blockCode,
            'entityType' => $entityType,
            'entityId'   => $entityId,
            'instanceId' => $instanceId,
            'language'   => $writeLang,
            'isNew'      => $rows === [],
            'old'        => $old,
            'new'        => $values,
        ]);
        if ($veto !== null) {
            return $result->addError(new Error($veto, 'event'));
        }

        $now  = new DateTime();
        $data = [
            'VALUE'      => $json,
            'LANG'       => $writeLang,
            'UPDATED_AT' => $now,
            'UPDATED_BY' => $author,
        ];

        if ($target !== null) {
            // Строка этого языка уже есть (или легаси-'' — метку поднимаем до
            // writeLang через $data['LANG']). Метаданные строки не трогаем.
            $saveResult = ValueTable::update((int) $target['ID'], $data);
        } else {
            // Новая языковая строка. Метаданные (SORT/активность) общие на
            // экземпляр: у первой строки — nextSort и умолчания, у последующих
            // зеркалим с уже существующих строк экземпляра.
            $meta = $rows !== []
                ? [
                    'SORT'        => (int) $rows[0]['SORT'],
                    'ACTIVE'      => $rows[0]['ACTIVE'] === 'Y' || $rows[0]['ACTIVE'] === true,
                    'ACTIVE_FROM' => $rows[0]['ACTIVE_FROM'] instanceof DateTime ? $rows[0]['ACTIVE_FROM'] : null,
                    'ACTIVE_TO'   => $rows[0]['ACTIVE_TO'] instanceof DateTime ? $rows[0]['ACTIVE_TO'] : null,
                ]
                : [
                    'SORT'        => $this->nextSort($entityType, $entityId),
                    'ACTIVE'      => true,
                    'ACTIVE_FROM' => null,
                    'ACTIVE_TO'   => null,
                ];

            $saveResult = ValueTable::add([
                'BLOCK_CODE'  => $blockCode,
                'ENTITY_TYPE' => $entityType,
                'ENTITY_ID'   => $entityId,
                'INSTANCE_ID' => $instanceId,
                'CREATED_AT'  => $now,
                'CREATED_BY'  => $author,
            ] + $data + $meta);
        }

        if (!$saveResult->isSuccess()) {
            return $result->addErrors($saveResult->getErrors());
        }

        // Активность/окно дат — общие на экземпляр: если прислали, зеркалим на все
        // языковые строки (включая только что записанную). null → не трогаем:
        // обычная запись значений активность не сбивает. Блок под запретом
        // активность тоже не меняет (причина уже в denied['']).
        if ($activity !== null && !$blockLocked) {
            $this->mirrorActivity($entityType, $entityId, $instanceId, [
                'ACTIVE'      => !empty($activity['active']),
                'ACTIVE_FROM' => $activity['from'] instanceof DateTime ? $activity['from'] : null,
                'ACTIVE_TO'   => $activity['to'] instanceof DateTime ? $activity['to'] : null,
            ]);
        }

        $this->dispatchFieldEvents($block, $entityType, $entityId, $instanceId, $old, $values, $writeLang);
        $this->clearEntityCache($entityType, $entityId);

        // История значений (append-only), если у блока включены ревизии. Язык
        // снимка = язык записанной строки: у языка по умолчанию — метка fallback
        // (не ''), у перевода — своя. '' остаётся лишь при ненастроенных языках и
        // у легаси-снимков; чтение истории вкладки fallback берёт и то, и другое
        // ({@see RevisionService::langScope}).
        (new RevisionService())->record(
            $block,
            $entityType,
            $entityId,
            $instanceId,
            $values,
            $userId,
            $comment,
            $sourceRevisionId,
            $writeLang
        );

        // Событие блока после успешной записи — уведомление (запрещать нечего).
        $this->fireBlockEvent(BlockEvent::AFTER_SAVE, [
            'blockCode'  => $blockCode,
            'entityType' => $entityType,
            'entityId'   => $entityId,
            'instanceId' => $instanceId,
            'language'   => $writeLang,
            'isNew'      => $rows === [],
            'old'        => $old,
            'new'        => $values,
        ]);

        // denied — не ошибки: save успешен, просто часть полей осталась прежней.
        // Отдаём путь => причина, чтобы UI сказал, что именно не сохранилось и
        // почему, а не молча показал откатившееся значение.
        $result->setData([
            'values'     => $values,
            'instanceId' => $instanceId,
            // Язык записанной строки (метка fallback у языка по умолчанию):
            // фронтенду разложить ответ по нужной вкладке.
            'language'   => $writeLang,
            'denied'     => $guard->denied(),
        ]);

        return $result;
    }

    /**
     * Метка `LANG`, под которой пишется запрошенный язык.
     *
     * Переводимый блок: язык (пустой/ null → fallback). Непереводимый: всегда
     * fallback (язык в его адресации не участвует, строка одна). При
     * ненастроенных языках fallback пуст — метка ''.
     */
    private function writeLang(Block $block, ?string $language): string
    {
        $fallback = Loader::languageFallback();
        if (!$block->isTranslatable()) {
            return $fallback;
        }

        return ($language === null || $language === '') ? $fallback : $language;
    }

    /**
     * Все языковые строки экземпляра (по одной на язык). Порядок по ID.
     *
     * @return array<int, array<string, mixed>>
     */
    private function instanceRows(string $entityType, int $entityId, string $instanceId): array
    {
        return ValueTable::query()
            ->setSelect(['ID', 'BLOCK_CODE', 'LANG', 'SORT', 'VALUE', 'ACTIVE', 'ACTIVE_FROM', 'ACTIVE_TO'])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->where('INSTANCE_ID', $instanceId)
            ->setOrder(['ID' => 'ASC'])
            ->fetchAll();
    }

    /**
     * Строка экземпляра, в которую ляжет запись языка: точная метка, а для языка
     * по умолчанию — ещё легаси-'' (её метку запись поднимет до fallback).
     * null — такой строки нет, будет вставка.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function pickWriteRow(array $rows, string $writeLang, string $fallback): ?array
    {
        foreach ($rows as $row) {
            if ((string) $row['LANG'] === $writeLang) {
                return $row;
            }
        }
        if ($writeLang === $fallback) {
            foreach ($rows as $row) {
                if ((string) $row['LANG'] === '') {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * Зеркалит активность/окно дат на все языковые строки экземпляра.
     *
     * Активность, порядок и авторство — общие на экземпляр, а хранятся в каждой
     * языковой строке (отдельной таблицы метаданных нет). Правку активности
     * (её задают на вкладке языка по умолчанию) применяем ко всем строкам, иначе
     * языки разошлись бы по флагу «активен».
     *
     * @param array{ACTIVE: bool, ACTIVE_FROM: ?DateTime, ACTIVE_TO: ?DateTime} $activity
     */
    private function mirrorActivity(string $entityType, int $entityId, string $instanceId, array $activity): void
    {
        $rows = ValueTable::query()
            ->setSelect(['ID'])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->where('INSTANCE_ID', $instanceId)
            ->fetchAll();

        foreach ($rows as $row) {
            ValueTable::update((int) $row['ID'], $activity);
        }
    }

    /**
     * Пригоден ли идентификатор экземпляра к записи; null — пригоден.
     *
     * Два правила, и оба про реальные поломки, а не про красоту:
     *
     *  - **не длиннее колонки** (varchar(32)). MySQL вне строгого режима режет
     *    длинный id молча, и два разных экземпляра с общим 32-символьным
     *    началом становятся одним: второй перезаписывает значения первого.
     *  - **без символов разметки и служебных**. Тот же id уходит в атрибут
     *    `data-st-instance` и в селектор на клиенте
     *    (`[data-st-instance="…"]`, см. scanBadInputs): кавычка ломает разбор
     *    селектора, и форма перестаёт сохраняться вообще.
     *
     * Буквы (в том числе кириллица) не запрещаем: id обычного блока — это имя
     * его папки, а называть папки по-русски никто не мешал.
     */
    private static function instanceIdError(string $instanceId): ?string
    {
        // Считаем символы, а не байты: в utf8 столько же влезет в varchar(32).
        if (mb_strlen($instanceId) > 32) {
            return (string) Loc::getMessage('STRUCTURA_VALUESERVICE_ID_TOO_LONG');
        }

        if (preg_match('~[<>"\'\\\\\x00-\x1F]~u', $instanceId)) {
            return (string) Loc::getMessage('STRUCTURA_VALUESERVICE_ID_BAD_CHARS');
        }

        return null;
    }

    /**
     * Пригоден ли язык к записи; null — пригоден.
     *
     * Проверяем только у переводимого блока: у одноязычного язык не участвует
     * в адресации вовсе и игнорируется (пишется в строку языка по умолчанию),
     * поэтому запрещать там нечего.
     *
     * Пустой язык и null — это «по умолчанию» (fallback); они допустимы
     * всегда, даже когда языки не настроены.
     */
    private static function languageError(Block $block, ?string $language): ?string
    {
        if (!$block->isTranslatable() || $language === null || $language === '') {
            return null;
        }

        $known = Loader::languages();
        if ($known !== [] && in_array($language, $known, true)) {
            return null;
        }

        return (string) Loc::getMessage('STRUCTURA_VALUESERVICE_LANG_NOT_CONFIGURED', [
            '#LANG#'    => $language,
            '#ALLOWED#' => $known === []
                ? (string) Loc::getMessage('STRUCTURA_VALUESERVICE_LANG_DEFAULT_ONLY')
                : implode(', ', $known),
        ]);
    }

    /**
     * Оставляет в значении только поля, которые есть в конфигурации блока.
     *
     * Ключи верхнего уровня — коды полей; вложенное содержимое (строки
     * Repeater, объект Group) сохраняемых полей не трогаем. Отсекаются только
     * осиротевшие коды — поля, удалённые из config.php.
     *
     * @param array<string,mixed> $values
     *
     * @return array<string,mixed>
     */
    private function keepConfiguredFields(Block $block, array $values): array
    {
        $allowed = [];
        foreach ($block->getFields() as $field) {
            $allowed[$field->getCode()] = true;
        }

        return array_intersect_key($values, $allowed);
    }

    /**
     * Нарушает ли сохранение правило `unique` группы блока.
     *
     * Группа с `unique` — набор взаимоисключающих блоков: на сущности допустим
     * только один экземпляр из всей группы (чтобы контент-менеджер не поставил
     * на страницу два баннера). Ограничение считается по группе, а не по коду
     * блока: два разных блока одной группы — тоже нарушение, и повторная копия
     * `multiple()`-блока внутри unique-группы тоже (для группы это второй
     * экземпляр, чей бы он ни был).
     *
     * Проверка здесь, а не только в пикере: фронтенд лишь подсказывает, запрос
     * можно собрать руками — так же устроены политики.
     *
     * Свой же экземпляр (пере-сохранение) не считается: сравниваем по
     * INSTANCE_ID, иначе блок нельзя было бы отредактировать второй раз.
     *
     * @return string|null текст ошибки; null — конфликта нет
     */
    private function uniqueGroupConflict(
        string $blockCode,
        string $entityType,
        int $entityId,
        string $instanceId
    ): ?string {
        foreach (Loader::groupOf($blockCode) as $group) {
            if (!$group['unique']) {
                continue;
            }

            // Вся ветка группы, включая вложенные подгруппы: иначе ограничение
            // обходилось бы вложенной папкой.
            $codes = Loader::codesInGroup($group['path']);
            if ($codes === []) {
                continue;
            }

            $rows = ValueTable::query()
                ->setSelect(['INSTANCE_ID', 'BLOCK_CODE'])
                ->where('ENTITY_TYPE', $entityType)
                ->where('ENTITY_ID', $entityId)
                ->whereIn('BLOCK_CODE', $codes)
                ->fetchAll();

            foreach ($rows as $row) {
                if ($row['INSTANCE_ID'] === $instanceId) {
                    continue;
                }

                $occupant = Loader::load((string) $row['BLOCK_CODE']);

                return (string) Loc::getMessage('STRUCTURA_VALUESERVICE_UNIQUE_GROUP', [
                    '#GROUP#'    => $group['name'],
                    '#OCCUPANT#' => $occupant !== null ? $occupant->getName() : (string) $row['BLOCK_CODE'],
                ]);
            }
        }

        return null;
    }

    /** Позиция для нового экземпляра — в конец списка сущности. */
    private function nextSort(string $entityType, int $entityId): int
    {
        $row = ValueTable::query()
            ->setSelect(['SORT'])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->setOrder(['SORT' => 'DESC'])
            ->setLimit(1)
            ->fetch();

        return $row ? ((int) $row['SORT']) + 1 : 0;
    }

    /**
     * Удаляет экземпляр блока и шлёт события delete по его полям.
     * История ревизий экземпляра удаляется вместе с ним.
     *
     * @param string|null $instanceId  null → «основной» экземпляр (id = код блока)
     * @param bool $fireBlockEvents поднимать ли события блока onBefore/AfterDelete
     *        (before может запретить удаление). false — каскадная уборка при
     *        удалении самой сущности ({@see EntityPurge}): вето там оставило бы
     *        значения-сироты, а события полей `:delete` идут независимо от флага.
     */
    public function delete(
        string $blockCode,
        string $entityType,
        int $entityId,
        ?string $instanceId = null,
        bool $fireBlockEvents = true
    ): Result {
        $result     = new Result();
        $instanceId = $this->resolveInstanceId($blockCode, $instanceId);
        if ($instanceId === null) {
            return $result->addError(new Error((string) Loc::getMessage('STRUCTURA_VALUESERVICE_BLOCK_NOT_FOUND', ['#BLOCK#' => $blockCode]), 'block'));
        }

        $old = $this->get($blockCode, $entityType, $entityId, $instanceId);

        // Событие блока до удаления: обработчик может запретить. Мимо каскадной
        // уборки сущности (fireBlockEvents=false) — там запрещать нечего.
        if ($fireBlockEvents) {
            $veto = $this->fireBlockEvent(BlockEvent::BEFORE_DELETE, [
                'blockCode'  => $blockCode,
                'entityType' => $entityType,
                'entityId'   => $entityId,
                'instanceId' => $instanceId,
                'old'        => $old,
            ]);
            if ($veto !== null) {
                return $result->addError(new Error($veto, 'event'));
            }
        }

        // Все языковые строки экземпляра — вместе (по одной на язык в одной
        // таблице). Иначе осталась бы строка-сирота, невидимая и подхваченная
        // новым экземпляром с тем же id.
        $rows = ValueTable::query()
            ->setSelect(['ID'])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->where('INSTANCE_ID', $instanceId)
            ->fetchAll();

        foreach ($rows as $row) {
            ValueTable::delete($row['ID']);
        }

        // Иначе история удалённого экземпляра осталась бы сиротой, а новый
        // экземпляр с тем же id (у обычного блока это код блока) унаследовал бы
        // чужие ревизии.
        (new RevisionService())->deleteFor($entityType, $entityId, $instanceId);

        $block = Loader::load($blockCode);
        if ($block !== null) {
            foreach ($block->getFields() as $field) {
                if (array_key_exists($field->getCode(), $old)) {
                    $this->fire($block, $field, FieldEvent::DELETE, $entityType, $entityId, $instanceId, [
                        'old' => $old[$field->getCode()],
                        'new' => null,
                    ]);
                }
            }
        }

        $this->clearEntityCache($entityType, $entityId);

        // Событие блока после удаления — уведомление.
        if ($fireBlockEvents) {
            $this->fireBlockEvent(BlockEvent::AFTER_DELETE, [
                'blockCode'  => $blockCode,
                'entityType' => $entityType,
                'entityId'   => $entityId,
                'instanceId' => $instanceId,
                'old'        => $old,
            ]);
        }

        return $result;
    }

    /**
     * Поднимает событие уровня блока ({@see BlockEvent}).
     *
     * Для before-событий возвращает причину вето (текст сообщения обработчика или
     * запасной текст), если хоть один обработчик вернул EventResult::ERROR; null
     * — вето нет, можно продолжать. After-события причины не имеют и всегда null.
     *
     * @param array<string, mixed> $params
     */
    private function fireBlockEvent(string $eventName, array $params): ?string
    {
        if (!class_exists(Event::class)) {
            return null;
        }

        $event = new Event(self::MODULE_ID, $eventName, $params);
        $event->send();

        foreach ($event->getResults() as $eventResult) {
            if ($eventResult->getType() === EventResult::ERROR) {
                $p = $eventResult->getParameters();
                $message = is_array($p) ? (string) ($p['message'] ?? '') : '';

                return $message !== '' ? $message : (string) Loc::getMessage('STRUCTURA_VALUESERVICE_EVENT_CANCELLED');
            }
        }

        return null;
    }

    /**
     * Сбрасывает кеш инфоблока сущности после изменения её блоков.
     *
     * Значения блоков — часть контента элемента/раздела, а компоненты инфоблока
     * кешируются по тегу `iblock_id_<ID>`. Ядро сбрасывает этот тег при правке
     * самого элемента/раздела; без такого же сброса правка через вкладку
     * Structura не видна в закешированном компоненте. Повторяем механизм ядра
     * (`CIBlock::clearIblockTagCache`), а не изобретаем свой: где управляемый
     * кеш выключен, это штатно ничего не делает — ровно как у ядра.
     */
    public function clearEntityCache(string $entityType, int $entityId): void
    {
        if ($entityId <= 0) {
            return;
        }
        if (!class_exists(\Bitrix\Main\Loader::class) || !\Bitrix\Main\Loader::includeModule('iblock')) {
            return;
        }

        $iblockId = $this->iblockIdOf($entityType, $entityId);
        if ($iblockId > 0) {
            \CIBlock::clearIblockTagCache($iblockId);
        }
    }

    /**
     * ID инфоблока сущности. Берётся по ID самой сущности из ядра — тег кеша
     * должен относиться к настоящему инфоблоку, а не к присланному клиентом.
     * 0 — сущность не найдена или тип не элемент/раздел.
     */
    private function iblockIdOf(string $entityType, int $entityId): int
    {
        if ($entityType === ValueTable::ENTITY_ELEMENT) {
            $row = \Bitrix\Iblock\ElementTable::getList([
                'select' => ['IBLOCK_ID'],
                'filter' => ['=ID' => $entityId],
                'limit'  => 1,
            ])->fetch();

            return $row ? (int) $row['IBLOCK_ID'] : 0;
        }

        if ($entityType === ValueTable::ENTITY_SECTION) {
            $row = \Bitrix\Iblock\SectionTable::getList([
                'select' => ['IBLOCK_ID'],
                'filter' => ['=ID' => $entityId],
                'limit'  => 1,
            ])->fetch();

            return $row ? (int) $row['IBLOCK_ID'] : 0;
        }

        return 0;
    }

    /**
     * Определяет для каждого поля тип изменения и рассылает событие.
     */
    private function dispatchFieldEvents(
        Block $block,
        string $entityType,
        int $entityId,
        string $instanceId,
        array $old,
        array $new,
        string $language = ''
    ): void {
        foreach ($block->getFields() as $field) {
            $code    = $field->getCode();
            $hadOld  = array_key_exists($code, $old);
            $hasNew  = array_key_exists($code, $new);
            $oldVal  = $old[$code] ?? null;
            $newVal  = $new[$code] ?? null;

            $event = null;
            if (!$hadOld && $hasNew) {
                $event = FieldEvent::ADD;
            } elseif ($hadOld && !$hasNew) {
                $event = FieldEvent::DELETE;
            } elseif ($oldVal !== $newVal) {
                $event = FieldEvent::UPDATE;
            }

            if ($event !== null) {
                $this->fire($block, $field, $event, $entityType, $entityId, $instanceId, [
                    'old' => $oldVal,
                    'new' => $newVal,
                ], $language);
            }
        }
    }

    private function fire(
        Block $block,
        Field $field,
        string $event,
        string $entityType,
        int $entityId,
        string $instanceId,
        array $payload,
        string $language = ''
    ): void {
        if (!class_exists(EventManager::class)) {
            return;
        }

        $eventName = FieldEvent::name($block->getCode(), $field->getCode(), $event);

        EventManager::getInstance()->send(new Event(self::MODULE_ID, $eventName, [
            'blockCode'  => $block->getCode(),
            'fieldCode'  => $field->getCode(),
            'event'      => $event,
            'entityType' => $entityType,
            'entityId'   => $entityId,
            // Без этого обработчик не отличит, какая из копий блока изменилась.
            'instanceId' => $instanceId,
            // Язык изменившегося слоя ('' — база/одноязычный блок).
            'language'   => $language,
            'old'        => $payload['old'] ?? null,
            'new'        => $payload['new'] ?? null,
        ]));
    }

    /**
     * ID текущего пользователя или null, если его нет (CLI, системный контекст).
     * Тот же источник, что у истории ревизий, — автор экземпляра и автор его
     * последней ревизии совпадают.
     */
    private static function currentUserId(): ?int
    {
        if (class_exists(\Bitrix\Main\Engine\CurrentUser::class)) {
            $id = \Bitrix\Main\Engine\CurrentUser::get()->getId();

            return $id ? (int) $id : null;
        }

        return null;
    }
}
