<?php

namespace Structura\Structura;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Structura\Structura\Config\Loader;
use Structura\Structura\Service\ValueService;
use Structura\Structura\Storage\ValueTable;

Loc::loadMessages(__FILE__);

/**
 * Значения блоков одной сущности — результат чтения через {@see Blocks}.
 *
 * Внутри — список экземпляров (в порядке SORT), у каждого код блока,
 * instanceId, язык базового значения и сырые значения базы. Config блока при
 * чтении не грузится: выбирается лишь языковая строка, значения отдаются как в
 * базе. Пропущенные ключи полей не достраиваются, ->default() не подставляется —
 * список полей блока и умолчания знает вызывающий код.
 *
 * По умолчанию читается базовое значение (язык fallback). Выбрать язык
 * переводимого блока:
 *
 *   Blocks::element(42)->language('en')->block('banner');
 *
 * Промах (у языка нет перевода) отдаёт базовое значение — fallback. Язык у
 * непереводимого блока игнорируется.
 *
 * Значения сырые: ссылочные поля (Image/File/User/IblockElement…) отдают ID или
 * путь, как в БД. Резолвить их в файлы/объекты — забота вызывающего кода.
 *
 * Объект помнит адрес сущности, поэтому от него же идёт запись (в выбранный язык):
 *
 *   Blocks::element(42)->save('banner', ['title' => 'Лето']);
 *   Blocks::element(42)->language('en')->save('banner', ['title' => 'Summer']);
 *
 * Прочитанные значения — снимок на момент чтения: после save() они не
 * обновляются. Свежие берёт {@see reload()}.
 */
final class BlockValues
{
    /** Переводы выбранного языка: instanceId => сырые значения. Ленивый кеш. */
    private ?array $translations = null;

    /**
     * @param array<int, array{blockCode:string, instanceId:string, lang:string, values:array}> $instances
     *        экземпляры в порядке отображения (сырые значения базы)
     * @param string      $entityType тип сущности (element|section)
     * @param int         $entityId   0 — сущность не найдена: читать нечего, писать некуда
     * @param string|null $language   выбранный язык чтения/записи (null — по умолчанию/база)
     */
    public function __construct(
        private readonly array $instances,
        private readonly string $entityType = '',
        private readonly int $entityId = 0,
        private readonly ?string $language = null
    ) {
    }

    /**
     * Читать/писать значения на выбранном языке.
     *
     * Возвращает новый объект (снимок тот же, меняется лишь язык доступа): null
     * или '' — язык по умолчанию (база/fallback).
     */
    public function language(?string $language): self
    {
        return new self($this->instances, $this->entityType, $this->entityId, $language);
    }

    /**
     * Значения первого экземпляра блока (или null, если блока на сущности нет).
     * Удобно для обычных, немножественных блоков.
     *
     * @return array<string,mixed>|null
     */
    public function block(string $blockCode): ?array
    {
        foreach ($this->instances as $instance) {
            if ($instance['blockCode'] === $blockCode) {
                return $this->resolve($instance);
            }
        }

        return null;
    }

    /**
     * Все экземпляры блока в порядке отображения (для ->multiple()).
     * Пустой массив, если блока на сущности нет.
     *
     * @return array<int, array<string,mixed>>
     */
    public function instances(string $blockCode): array
    {
        $out = [];
        foreach ($this->instances as $instance) {
            if ($instance['blockCode'] === $blockCode) {
                $out[] = $this->resolve($instance);
            }
        }

        return $out;
    }

    /** Есть ли на сущности хоть один экземпляр этого блока. */
    public function has(string $blockCode): bool
    {
        foreach ($this->instances as $instance) {
            if ($instance['blockCode'] === $blockCode) {
                return true;
            }
        }

        return false;
    }

    /**
     * Коды блоков, присутствующих на сущности (в порядке отображения).
     *
     * @return string[]
     */
    public function codes(): array
    {
        $codes = [];
        foreach ($this->instances as $instance) {
            $codes[$instance['blockCode']] = true;
        }

        return array_keys($codes);
    }

    /**
     * Все экземпляры сущности плоским списком в порядке SORT. Каждый элемент —
     * код блока, instanceId и значения выбранного языка под ключом `data` (тем
     * же, под которым Flexible хранит содержимое макета: `{_layout, data}`).
     *
     * Список плоский, а не карта «код => экземпляры»: карта потеряла бы
     * глобальный порядок между разными блоками и склеила бы повторяющиеся коды.
     *
     * @return array<int, array{blockCode:string, instanceId:string, data:array<string,mixed>}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->instances as $instance) {
            $out[] = [
                'blockCode'  => $instance['blockCode'],
                'instanceId' => $instance['instanceId'],
                'data'       => $this->resolve($instance),
            ];
        }

        return $out;
    }

    /** На сущности нет ни одного блока (или сущность не найдена). */
    public function isEmpty(): bool
    {
        return $this->instances === [];
    }

    /** Сущность, к которой привязаны значения: 0 — не найдена. */
    public function entityId(): int
    {
        return $this->entityId;
    }

    /**
     * Записывает значения блока на эту сущность (в выбранный ->language()).
     *
     * Значения передаются целиком: как и в админке, сохраняется весь блок (в
     * пределах языка), а не отдельные поля. Чтобы поправить одно поле, прочитайте
     * блок, измените массив и отдайте обратно.
     *
     * Политики полей не применяются — это системная запись (подробности в
     * {@see ValueService::saveSystem()}). Валидация, правило unique-группы,
     * события полей и история ревизий работают как обычно.
     *
     * @param array<string,mixed> $values     значения полей блока
     * @param string|null         $instanceId экземпляр; для ->multiple()-блока
     *                            обязателен, у обычного — код блока
     */
    public function save(string $blockCode, array $values, ?string $instanceId = null): Result
    {
        $result = $this->requireEntity();
        if ($result !== null) {
            return $result;
        }

        return (new ValueService())->saveSystem(
            $blockCode,
            $this->entityType,
            $this->entityId,
            $values,
            $instanceId,
            null,
            null,
            null,
            $this->language
        );
    }

    /**
     * Удаляет экземпляр блока с этой сущности целиком (все языки) вместе с его
     * историей. Чтобы стереть один язык — сохраните на нём пустые значения.
     *
     * @param string|null $instanceId экземпляр; null — единственный
     */
    public function delete(string $blockCode, ?string $instanceId = null): Result
    {
        $result = $this->requireEntity();
        if ($result !== null) {
            return $result;
        }

        return (new ValueService())->delete($blockCode, $this->entityType, $this->entityId, $instanceId);
    }

    /**
     * Перечитывает значения этой же сущности из БД (сохраняя выбранный язык).
     *
     * Нужен после save(): объект держит снимок, а не живую связь.
     */
    public function reload(): self
    {
        if ($this->entityId <= 0) {
            return $this;
        }

        $fresh = $this->entityType === \Structura\Structura\Storage\ValueTable::ENTITY_SECTION
            ? Blocks::section($this->entityId)
            : Blocks::element($this->entityId);

        return $this->language === null ? $fresh : $fresh->language($this->language);
    }

    /**
     * Значения экземпляра на выбранном языке — сырые, как в базе.
     *
     * Config блока здесь не читается: публичное чтение не зависит от целостности
     * конфигурации и не достраивает пропущенные ключи полей. Выбирается лишь
     * языковая строка — база или перевод, с фолбэком на базу.
     *
     * @param array{blockCode:string, instanceId:string, lang:string, values:array} $instance
     * @return array<string,mixed>
     */
    private function resolve(array $instance): array
    {
        $fallback  = Loader::languageFallback();
        // Язык не выбран — это язык по умолчанию (fallback). Его тоже разрешаем
        // по метке базы: после смены language_fallback база помнит прежний язык,
        // и контент fallback лежит уже в переводах, а не в базе.
        $requested = ($this->language === null || $this->language === '') ? $fallback : $this->language;
        // Пустая метка базы (старые данные) = fallback.
        $baseLang  = $instance['lang'] !== '' ? $instance['lang'] : $fallback;

        if ($requested === $baseLang) {
            return $instance['values'];
        }

        // Перевод есть — он; нет — фолбэк на базу (публичное чтение не оставляет
        // сайт пустым, в отличие от сырого ValueService::get). У непереводимого
        // блока строки перевода нет, промах translations() возвращает базу —
        // язык игнорируется сам, без похода в config за флагом isTranslatable().
        return $this->translations()[$instance['instanceId']] ?? $instance['values'];
    }

    /**
     * Переводы выбранного языка для всех экземпляров сущности: instanceId =>
     * сырые значения. Один запрос, ленивый — только при обращении к языку,
     * отличному от базового.
     *
     * @return array<string, array<string,mixed>>
     */
    private function translations(): array
    {
        if ($this->translations !== null) {
            return $this->translations;
        }

        $this->translations = [];
        if ($this->entityId <= 0) {
            return $this->translations;
        }

        // Язык null/'' — язык по умолчанию (fallback): его перевод понадобится,
        // если база экземпляра разошлась с fallback (см. resolve). Языки не
        // настроены (fallback пуст) — переводов нет.
        $requested = ($this->language === null || $this->language === '')
            ? Loader::languageFallback()
            : $this->language;
        if ($requested === '') {
            return $this->translations;
        }

        // Каждый язык — своя строка structura_value. Строки нужного языка сущности
        // одним запросом (у языка по умолчанию сюда попадёт и легаси-'').
        $langs = [$requested];
        if ($requested === Loader::languageFallback()) {
            $langs[] = '';
        }

        $rows = ValueTable::query()
            ->setSelect(['INSTANCE_ID', 'LANG', 'VALUE'])
            ->where('ENTITY_TYPE', $this->entityType)
            ->where('ENTITY_ID', $this->entityId)
            ->whereIn('LANG', $langs)
            ->fetchAll();

        // Непустая метка (сам $requested) вперёд легаси-'': сортируем по LANG
        // по убыванию, чтобы точная метка перезаписала '' у того же экземпляра.
        usort($rows, static fn (array $a, array $b): int => (string) $b['LANG'] <=> (string) $a['LANG']);
        foreach ($rows as $row) {
            $id = (string) $row['INSTANCE_ID'];
            if (array_key_exists($id, $this->translations)) {
                continue; // уже взяли непустую метку
            }
            $decoded = json_decode((string) $row['VALUE'], true);
            $this->translations[$id] = is_array($decoded) ? $decoded : [];
        }

        return $this->translations;
    }

    /**
     * Ошибка, если сущности нет: писать в «сущность 0» нельзя.
     *
     * Молчаливый успех был бы худшим исходом — импорт отработал бы «успешно»,
     * не записав ничего.
     */
    private function requireEntity(): ?Result
    {
        if (
            $this->entityId > 0
            && $this->entityType !== ''
            && Blocks::exists($this->entityType, $this->entityId)
        ) {
            return null;
        }

        return (new Result())->addError(
            new Error((string) Loc::getMessage('STRUCTURA_BLOCKVALUES_NO_ENTITY'), 'entity')
        );
    }
}
