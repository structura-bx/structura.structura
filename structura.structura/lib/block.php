<?php

namespace Structura\Structura;

use Structura\Structura\Traits\HasFields;
use Structura\Structura\Traits\HasVisibility;

/**
 * Блок — корневой контейнер пользовательских полей.
 *
 * Свойства унаследованы от абстрактного Field (название, код, политики).
 * Конфигурация блока живёт в файлах (config.php), в БД хранятся только значения.
 */
class Block extends Field
{
    use HasFields;
    use HasVisibility;

    protected string $version = '1.0.0';

    /**
     * Конфигурация ревизий значений блока. По умолчанию выключены — чтобы не
     * засорять БД, если разработчик их не запрашивал. Включаются явно в config.php:
     *   ->revisions()                              // вкл, лимит 50, без TTL
     *   ->revisions(['limit' => 100, 'ttl' => 'month'])
     * ttl: week|month|quarter|year|<дней int>|null. limit: 0 = без лимита по числу.
     *
     * @var array{enabled: bool, limit: int, ttl: string|int|null}
     */
    protected array $revisions = ['enabled' => false, 'limit' => 50, 'ttl' => null];

    /**
     * Можно ли добавить блок к одной сущности несколько раз.
     *
     * По умолчанию выключено: блок вроде «SEO-настройки» имеет смысл ровно один
     * на элемент, и случайная вторая копия — ошибка контент-менеджера, а не фича.
     * Включается явно в config.php: ->multiple().
     *
     * Каждая копия блока — «экземпляр» со своим INSTANCE_ID, значениями,
     * позицией (SORT) и собственной историей ревизий. У не-множественного блока
     * экземпляр ровно один, и его идентификатор равен коду блока.
     */
    protected bool $multiple = false;

    /**
     * Блок обязан быть на сущности.
     *
     * Это предупреждение, а не запрет: сохранение сущности без такого блока не
     * блокируется, вкладка лишь пишет, что блок не добавлен (иначе новый элемент
     * нельзя было бы создать — вкладке нужен ID, а он появляется после
     * сохранения). Обязательность считается по блокам, доступным сущности:
     * скрытый правилами visibleOn блок обязательным для неё не является.
     */
    protected bool $required = false;

    /**
     * Контент блока переводится по языкам.
     *
     * По умолчанию выключено: блок одноязычный, значение одно на экземпляр.
     * Включается флагом ->translatable() в config.php. Список языков и fallback —
     * глобальные (configuration.php), здесь только да/нет. Каждый язык экземпляра
     * — своя строка structura_value; метаданные экземпляра (активность, порядок,
     * авторство) общие на все языки.
     */
    protected bool $translatable = false;

    /**
     * Блок создаётся только с именем: код ему выдаёт загрузчик по имени папки
     * (`Loader::load()` → `setCode()`), поэтому блок можно двигать по дереву
     * групп, не теряя идентичность в БД. Сам код у блока обязателен — им
     * адресуются значения, события `<BlockCode>:<FieldCode>:<событие>` и
     * экземпляры.
     */
    public function __construct(string $name = '')
    {
        parent::__construct($name, '');
    }

    public function version(string $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Включить/настроить ревизии значений блока.
     *
     * @param bool|array{enabled?: bool, limit?: int, ttl?: string|int|null} $config
     *        true — включить с дефолтами; false — выключить; массив — тонкая настройка.
     */
    public function revisions(bool|array $config = true): static
    {
        if (is_bool($config)) {
            $this->revisions['enabled'] = $config;

            return $this;
        }

        $this->revisions['enabled'] = (bool) ($config['enabled'] ?? true);
        if (isset($config['limit'])) {
            $this->revisions['limit'] = max(0, (int) $config['limit']);
        }
        if (array_key_exists('ttl', $config)) {
            $this->revisions['ttl'] = $config['ttl'];
        }

        return $this;
    }

    /** @return array{enabled: bool, limit: int, ttl: string|int|null} */
    public function getRevisionsConfig(): array
    {
        return $this->revisions;
    }

    public function revisionsEnabled(): bool
    {
        return $this->revisions['enabled'] === true;
    }

    /**
     * Разрешить добавлять блок к одной сущности несколько раз.
     *
     *   ->multiple()        // можно добавлять копии
     *   ->multiple(false)   // ровно один экземпляр (по умолчанию)
     */
    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /**
     * Пометить блок обязательным для сущности.
     *
     *   ->required()        // вкладка предупредит, если блок не добавлен
     *   ->required(false)   // не обязателен (по умолчанию)
     *
     * Предупреждение, а не запрет: см. $required.
     */
    public function required(bool $required = true): static
    {
        $this->required = $required;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * Пометить блок переводимым (контент по языкам).
     *
     *   ->translatable()        // блок переводится
     *   ->translatable(false)   // одноязычный (по умолчанию)
     *
     * Список языков и fallback берутся из configuration.php, не отсюда.
     */
    public function translatable(bool $translatable = true): static
    {
        $this->translatable = $translatable;

        return $this;
    }

    public function isTranslatable(): bool
    {
        return $this->translatable;
    }

    public function getType(): string
    {
        return 'block';
    }

    /**
     * Плоская карта всех полей блока (включая вложенные в контейнеры),
     * ключ — путь кода вида "repeaterCode.childCode".
     *
     * @return array<string, Field>
     */
    public function flattenFields(string $prefix = ''): array
    {
        $result = [];

        foreach ($this->getFields() as $field) {
            $path          = $prefix === '' ? $field->getCode() : $prefix . '.' . $field->getCode();
            $result[$path] = $field;

            if (method_exists($field, 'getFields')) {
                $result += (function () use ($field, $path) {
                    $nested = [];
                    foreach ($field->getFields() as $child) {
                        $childPath          = $path . '.' . $child->getCode();
                        $nested[$childPath] = $child;
                    }
                    return $nested;
                })();
            }
        }

        return $result;
    }

    public function toArray(): array
    {
        return [
            'type'       => 'block',
            'name'       => $this->name,
            'code'       => $this->code,
            'version'    => $this->version,
            'multiple'   => $this->multiple,
            'required'   => $this->required,
            'translatable' => $this->translatable,
            'revisions'  => $this->revisions,
            'policy'     => $this->getPolicy(),
            // См. Field::toArray(): решение по политике принимает сервер.
            // Запрет на блоке не прячет его — блок рендерится со значениями, но
            // только для чтения.
            'editable'   => \Structura\Structura\Access\Policy::allows($this->getPolicy()),
            'lockReason' => \Structura\Structura\Access\Policy::reason($this->getPolicy()),
            'visibility' => $this->getVisibility(),
            'fields'     => array_map(
                static fn (Field $field) => $field->toArray(),
                $this->getFields()
            ),
        ];
    }
}
