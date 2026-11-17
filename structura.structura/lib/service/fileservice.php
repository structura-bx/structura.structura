<?php

namespace Structura\Structura\Service;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Structura\Structura\Config\Loader;
use Structura\Structura\Field;
use Structura\Structura\Fields\ContainerField;
use Structura\Structura\Fields\File as FileField;
use Structura\Structura\Fields\Flexible;
use Structura\Structura\Fields\Group;
use Structura\Structura\Fields\Image as ImageField;
use Structura\Structura\Block;

Loc::loadMessages(__FILE__);
use Structura\Structura\Storage\RevisionTable;
use Structura\Structura\Storage\ValueTable;

/**
 * Настоящее удаление файла поля File/Image.
 *
 * «×» у поля только отвязывает файл от значения — сам файл остаётся в b_file и
 * на диске. Здесь он удаляется физически (CFile::Delete).
 *
 * Операция точечная. Адрес — экземпляр блока и путь поля («pic», «rows.0.inner»,
 * «flex.0.data.0.pic»): чистится ровно то поле, над которым идёт операция. Тот
 * же ID в соседнем поле или в другом блоке — чужое значение, его не трогаем.
 *
 * Поиска «где ещё используется файл» по содержимому JSON здесь нет: `VALUE LIKE
 * '%<id>%'` — полный перебор без индекса на каждое нажатие. Вместо него «файл
 * ещё кому-то нужен» решается двумя дешёвыми проверками:
 *
 *  - **этой сущностью** — {@see isUsedBy}: после чистки поля значения элемента
 *    просматриваются по индексу (ENTITY_TYPE, ENTITY_ID), и если ID остался хоть
 *    в одном, байты не трогаем. Один файл штатно принадлежит нескольким
 *    значениям сущности (заполнение перевода и клон строки файл не копируют),
 *    и без этой проверки удаление картинки на вкладке «en» уносило бы её из «ru»;
 *  - **ядром** — по таблице копий (b_file_duplicate): одинаковые байты,
 *    загруженные дважды, получают разные ID и один файл на диске со счётчиком
 *    ссылок; CFile::Delete удаляет байты только с последней ссылкой.
 *
 * Цена: ID файла, вписанный руками в поле другой сущности, после удаления
 * останется ссылкой на несуществующий файл — проверить это без перебора нечем.
 * Ревизии перестают быть строго append-only: снапшот правится (иначе файл
 * нельзя удалить, пока жива ревизия с ним, — у File/Image встроена валидация
 * fileExists). Правится тот же путь того же экземпляра.
 *
 * `purged` = false означает, что запись b_file осталась, и `reason` говорит
 * почему: `shared_value` — тот же ID лежит в другом значении этой сущности,
 * `shared_bytes` — те же байты держит другая запись b_file.
 */
final class FileService
{
    /**
     * Удаляет файл и ссылку на него в одном поле — том, над которым идёт
     * операция, — в живом значении и в снапшотах ревизий этого экземпляра.
     *
     * Порядок важен: сначала ссылки, потом файл. Если упасть посередине,
     * останется «файл без ссылок» (мусор, безвредно), а не «ссылка на удалённый
     * файл» (сломанная валидация и откат).
     *
     * @param string $path путь поля в значениях экземпляра («rows.0.inner»)
     */
    public function delete(
        int $fileId,
        string $entityType,
        int $entityId,
        string $instanceId,
        string $path,
        ?string $language = null
    ): Result {
        $result = new Result();

        if ($fileId <= 0) {
            return $result->addError(new Error((string) Loc::getMessage('STRUCTURA_FILESERVICE_BAD_ID'), 'file'));
        }

        // Каждый язык экземпляра — своя строка structura_value; файл лежит в
        // строке запрошенного языка (у языка по умолчанию — ещё и легаси-'').
        // Путь остаётся без языкового префикса. Тем же набором меток чистим и
        // ревизии этого языка, чтобы не тронуть снапшоты другого языка.
        $langs = $this->fileLangs($this->blockOf($entityType, $entityId, $instanceId), $language);

        $values    = $this->clearIn(ValueTable::class, $fileId, $entityType, $entityId, $instanceId, $path, $langs);
        $revisions = $this->clearIn(RevisionTable::class, $fileId, $entityType, $entityId, $instanceId, $path, $langs);

        // Смотрим после чистки: иначе файл всегда «занят» тем полем, которое
        // сейчас и очистили. Держит ли ID кто-то ещё в этой сущности (другой
        // язык, соседнее поле, клон строки) — снос байтов тут означал бы битую
        // картинку на соседней вкладке. Ссылку из своего поля мы убрали,
        // `purged` честно говорит, что байты остались.
        if ($this->isUsedBy($entityType, $entityId, $fileId)) {
            return $result->setData([
                'values'    => $values,
                'revisions' => $revisions,
                'purged'    => false,
                'reason'    => 'shared_value',
            ]);
        }

        \CFile::Delete($fileId);
        $purged = !$this->existsInDb($fileId);

        return $result->setData([
            'values'    => $values,
            'revisions' => $revisions,
            'purged'    => $purged,
            // Ядро запись не удалило — значит те же байты держит другая запись
            // b_file (дедупликация по хэшу, см. existsInDb).
            'reason'    => $purged ? null : 'shared_bytes',
        ]);
    }

    /**
     * Убирает ID файла по одному пути во всех строках таблицы, относящихся к
     * этому экземпляру. Возвращает число переписанных строк.
     *
     * Отбор — по ключу (ENTITY_TYPE, ENTITY_ID, INSTANCE_ID), без единого
     * взгляда на содержимое JSON: у живого значения такая строка одна, у
     * ревизий — история этого экземпляра.
     *
     * Снапшот, где по этому пути лежит что-то другое (строки переставляли,
     * поле было пустым), не трогается вовсе.
     *
     * @param class-string $table
     */
    private function clearIn(
        string $table,
        int $fileId,
        string $entityType,
        int $entityId,
        string $instanceId,
        string $path,
        ?array $langs = null
    ): int {
        $query = $table::query()
            ->setSelect(['ID', 'VALUE'])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->where('INSTANCE_ID', $instanceId);

        // Слой языка: у переводов — конкретная строка (LANG), у ревизий базы —
        // её язык плюс легаси ''. У базовой таблицы строка одна, фильтр не нужен
        // ($langs === null).
        if ($langs !== null) {
            $query->whereIn('LANG', $langs);
        }

        $rows = $query->fetchAll();

        $touched = 0;

        foreach ($rows as $row) {
            $values = json_decode((string) $row['VALUE'], true);
            if (!is_array($values)) {
                continue;
            }

            $cleaned = self::clearAt($values, $path, $fileId);
            if ($cleaned === null) {
                continue;   // по этому пути другого файла (или пусто) — не наше дело
            }

            $table::update($row['ID'], ['VALUE' => json_encode($cleaned, JSON_UNESCAPED_UNICODE)]);
            $touched++;
        }

        return $touched;
    }

    /**
     * Значения с очищенным полем по пути. null — менять нечего: пути нет либо
     * там лежит не этот файл.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>|null
     */
    private static function clearAt(array $values, string $path, int $fileId): ?array
    {
        $segments = explode('.', $path);
        $last     = array_pop($segments);

        $node = &$values;
        foreach ($segments as $segment) {
            $key = ctype_digit($segment) ? (int) $segment : $segment;
            if (!is_array($node) || !array_key_exists($key, $node)) {
                unset($node);

                return null;
            }
            $node = &$node[$key];
        }

        $key = ctype_digit((string) $last) ? (int) $last : $last;
        if (!is_array($node) || !array_key_exists($key, $node) || !self::isSame($node[$key], $fileId)) {
            unset($node);

            return null;
        }

        $node[$key] = '';
        unset($node);

        return $values;
    }

    /**
     * Исчезла ли запись файла на самом деле.
     *
     * CFile::Delete не гарантирует удаления: у Bitrix дедупликация по хэшу со
     * счётчиком ссылок (b_file_duplicate), одинаковые байты получают разные ID,
     * но лежат на диске одним файлом. Тогда Delete лишь уменьшает счётчик, а
     * запись и файл убирает удаление последней ссылки. Рапортовать «удалено»,
     * когда файл на диске, нельзя.
     *
     * Читаем не через CFile::GetFileArray: у него статический кэш.
     */
    private function existsInDb(int $fileId): bool
    {
        return (bool) \Bitrix\Main\FileTable::query()
            ->setSelect(['ID'])
            ->where('ID', $fileId)
            ->setLimit(1)
            ->fetch();
    }

    /** Блок экземпляра по любой его строке (BLOCK_CODE един на все языки). */
    private function blockOf(string $entityType, int $entityId, string $instanceId): ?Block
    {
        $row = ValueTable::query()
            ->setSelect(['BLOCK_CODE'])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->where('INSTANCE_ID', $instanceId)
            ->setLimit(1)
            ->fetch();

        return $row ? Loader::load((string) $row['BLOCK_CODE']) : null;
    }

    /**
     * Метки `LANG`, в строках которых лежит файл запрошенного языка (и снимки
     * той же вкладки истории). Язык по умолчанию (fallback) — своя метка и
     * легаси-'' (данные без метки). Непереводимый блок язык игнорирует — его
     * единственная строка помечена fallback или ''.
     *
     * Совпадает с логикой чтения в ValueService (одна модель у чтения и у
     * удаления файла).
     *
     * @return string[]
     */
    private function fileLangs(?Block $block, ?string $language): array
    {
        $fallback = Loader::languageFallback();

        if ($block === null || !$block->isTranslatable()) {
            return $fallback !== '' ? [$fallback, ''] : [''];
        }

        $lang = ($language === null || $language === '') ? $fallback : $language;

        if ($fallback !== '' && $lang === $fallback) {
            return [$fallback, ''];
        }

        return [$lang];
    }

    /**
     * Лежит ли этот файл именно в этом поле этого экземпляра и можно ли его
     * править.
     *
     * Служит двум целям сразу:
     *  - доказательство «файл наш»: без него по перебору ID можно было бы
     *    сносить чужие файлы, в том числе не имеющие к модулю отношения;
     *  - политика: файл в поле (или в блоке), которое пользователю править
     *    нельзя, удалять тоже нельзя.
     *
     * Одно чтение по ключу экземпляра и разбор одного значения — ни перебора
     * значений сущности, ни тем более поиска по всей таблице.
     *
     * @return array{found: bool, allowed: bool}
     */
    public function checkFieldOwnership(
        string $entityType,
        int $entityId,
        string $instanceId,
        string $path,
        int $fileId,
        ?string $language = null
    ): array {
        $miss = ['found' => false, 'allowed' => false];

        // Код блока един на все языки — берём из любой строки экземпляра.
        $block = $this->blockOf($entityType, $entityId, $instanceId);
        if ($block === null) {
            return $miss;
        }

        // Значение — из строки именно запрошенного языка (у языка по умолчанию —
        // ещё и легаси-''). Файл переведённого поля лежит в своей языковой строке.
        $langs = $this->fileLangs($block, $language);
        $rows  = ValueTable::query()
            ->setSelect(['LANG', 'VALUE'])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->where('INSTANCE_ID', $instanceId)
            ->whereIn('LANG', $langs)
            ->fetchAll();

        if ($rows === []) {
            return $miss;
        }

        // Непустая метка (fallback) вперёд легаси-''.
        usort($rows, static fn (array $a, array $b): int => (string) $b['LANG'] <=> (string) $a['LANG']);

        $values = json_decode((string) $rows[0]['VALUE'], true);
        if (!is_array($values)) {
            return $miss;
        }

        // Запрет на самом блоке запирает всё его содержимое разом.
        $locked = !\Structura\Structura\Access\Policy::allows($block->getPolicy());
        $field  = self::fieldAt($block->getFields(), $values, explode('.', $path), $locked);

        // Полем должно быть именно файловое, и в нём должен лежать именно этот
        // файл: иначе путь — просто подсказка клиента, по которой можно было бы
        // снести любой файл сайта.
        if (!$field instanceof ImageField && !$field instanceof FileField) {
            return $miss;
        }

        if (!self::isSame(self::valueAt($values, $path), $fileId)) {
            return $miss;
        }

        return ['found' => true, 'allowed' => !$locked];
    }

    /**
     * Поле по пути значения. Путь идёт по значениям, а не по одной лишь
     * конфигурации: через Flexible иначе не пройти — какой макет выбран в
     * элементе, записано только в самом значении (`_layout`).
     *
     * $locked накапливается по дороге: запрет на контейнере запирает всё
     * поддерево, как и при слиянии политик.
     *
     * @param Field[]  $fields
     * @param mixed    $values значения текущего уровня
     * @param string[] $segments
     */
    private static function fieldAt(array $fields, mixed $values, array $segments, bool &$locked): ?Field
    {
        if ($segments === []) {
            return null;
        }

        $code  = array_shift($segments);
        $field = null;
        foreach ($fields as $candidate) {
            if ($candidate->getCode() === $code) {
                $field = $candidate;
                break;
            }
        }

        if ($field === null) {
            return null;
        }

        $locked = $locked || !\Structura\Structura\Access\Policy::allows($field->getPolicy());
        $value  = is_array($values) ? ($values[$code] ?? null) : null;

        return self::descend($field, $value, $segments, $locked);
    }

    /**
     * Спуск внутрь поля-контейнера по остатку пути.
     *
     * Group — один объект, а не список строк, поэтому её ветка стоит до общей и
     * без индекса в пути («cta.pic», а не «cta.0.pic»).
     *
     * @param string[] $segments
     */
    private static function descend(Field $field, mixed $value, array $segments, bool &$locked): ?Field
    {
        if ($segments === []) {
            return $field;
        }

        if ($field instanceof Flexible) {
            $index = array_shift($segments);
            $item  = (is_array($value) && ctype_digit((string) $index)) ? ($value[(int) $index] ?? null) : null;
            if (!is_array($item) || array_shift($segments) !== 'data') {
                return null;
            }

            $layout = null;
            foreach ($field->getLayouts() as $candidate) {
                if ($candidate->getCode() === ($item['_layout'] ?? null)) {
                    $layout = $candidate;
                    break;
                }
            }

            if ($layout === null) {
                return null;
            }

            $locked = $locked || !\Structura\Structura\Access\Policy::allows($layout->getPolicy());

            return self::descend($layout, $item['data'] ?? null, $segments, $locked);
        }

        if ($field instanceof Group) {
            return self::fieldAt($field->getFields(), $value, $segments, $locked);
        }

        if ($field instanceof ContainerField) {
            $index = array_shift($segments);
            $row   = (is_array($value) && ctype_digit((string) $index)) ? ($value[(int) $index] ?? null) : null;

            return self::fieldAt($field->getFields(), $row, $segments, $locked);
        }

        // Листовое поле, а путь продолжается — такого адреса не существует.
        return null;
    }

    /**
     * Значение по пути (без оглядки на конфигурацию — тип поля уже проверен).
     *
     * @param array<string, mixed> $values
     */
    private static function valueAt(array $values, string $path): mixed
    {
        $node = $values;

        foreach (explode('.', $path) as $segment) {
            $key = ctype_digit($segment) ? (int) $segment : $segment;
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * Есть ли на файл ссылка хоть где-то в значениях этой сущности.
     *
     * Нужно чтению метаданных файла (имя, превью): их отдают, только если файл
     * и правда лежит в значениях сущности, которую пользователю можно читать, —
     * иначе перебором ID вычитывались бы имена и пути любых файлов сайта.
     *
     * Здесь перебор допустим и никуда не растёт: это значения одной сущности,
     * отобранные по индексу, без поиска по содержимому.
     */
    public function isUsedBy(string $entityType, int $entityId, int $fileId): bool
    {
        // Все языки экземпляра — строки structura_value: файл может лежать только
        // в одной из них (File-поле перевели отдельной картинкой), и без этого
        // выдача файла на форме отказала бы в доступе к легитимному файлу.
        $rows = ValueTable::query()
            ->setSelect(['BLOCK_CODE', 'VALUE'])
            ->where('ENTITY_TYPE', $entityType)
            ->where('ENTITY_ID', $entityId)
            ->fetchAll();

        foreach ($rows as $row) {
            $block = Loader::load((string) $row['BLOCK_CODE']);
            if ($block === null) {
                continue;
            }

            $values = json_decode((string) $row['VALUE'], true);
            if (!is_array($values)) {
                continue;
            }

            if (self::holdsFile($block->getFields(), $values, $fileId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Держит ли хоть одно файловое поле этот файл.
     *
     * Обход по конфигу, а не по числу в JSON: ID файла — обычное число, и «12»
     * могло лежать в текстовом поле или в поле User.
     *
     * Group — один объект, а не список строк: её ветка стоит до общей.
     *
     * @param Field[]             $fields
     * @param array<string,mixed> $values
     */
    private static function holdsFile(array $fields, array $values, int $fileId): bool
    {
        foreach ($fields as $field) {
            $code = $field->getCode();
            if (!array_key_exists($code, $values)) {
                continue;
            }

            $value = $values[$code];

            if ($field instanceof Flexible) {
                foreach ((is_array($value) ? $value : []) as $item) {
                    $item = is_array($item) ? $item : [];
                    foreach ($field->getLayouts() as $layout) {
                        if ($layout->getCode() !== ($item['_layout'] ?? null)) {
                            continue;
                        }
                        // Макет — обычное поле, его значение лежит в `data`:
                        // подставляем его под код макета и идём тем же обходом.
                        if (self::holdsFile([$layout], [$layout->getCode() => $item['data'] ?? null], $fileId)) {
                            return true;
                        }
                    }
                }

                continue;
            }

            if ($field instanceof Group) {
                if (self::holdsFile($field->getFields(), is_array($value) ? $value : [], $fileId)) {
                    return true;
                }

                continue;
            }

            if ($field instanceof ContainerField) {
                foreach ((is_array($value) ? $value : []) as $row) {
                    if (self::holdsFile($field->getFields(), is_array($row) ? $row : [], $fileId)) {
                        return true;
                    }
                }

                continue;
            }

            if (($field instanceof ImageField || $field instanceof FileField) && self::isSame($value, $fileId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Значение поля равно этому ID файла.
     *
     * Строгое сравнение не годится: из JSON ID приезжает то числом, то строкой.
     * Но и == нельзя — "12abc" == 12 в PHP 7 было бы true.
     *
     * @param mixed $value
     */
    private static function isSame($value, int $fileId): bool
    {
        if (is_int($value)) {
            return $value === $fileId;
        }

        return is_string($value) && ctype_digit($value) && (int) $value === $fileId;
    }
}
