<?php

namespace Structura\Structura;

use Structura\Structura\Traits\HasDefault;
use Structura\Structura\Traits\HasPolicy;
use Structura\Structura\Traits\HasValidation;

/**
 * Абстрактное поле — базовый строительный блок конфигурации.
 *
 * Базовые методы (см. ТЗ):
 *  - установка названия / кода;
 *  - установка доступа по группам и политикам (setPolicy);
 *  - установка правил валидации (setValidation);
 *  - имена событий поля <BlockCode>:<Code>:<событие>.
 *
 * Конфигурация поля не хранится в БД — только его значения (см. Storage\ValueTable).
 */
abstract class Field
{
    use HasPolicy;
    use HasValidation;
    use HasDefault;

    protected string $name;
    protected string $code;

    /**
     * Текст подсказки для контент-менеджера, задаётся разработчиком в config.php.
     * В админке раскрывается по клику на «?» рядом с подписью поля.
     */
    protected ?string $help = null;

    /** Произвольные настройки конкретного типа поля (min/max, options и т.п.). */
    protected array $settings = [];

    public function __construct(string $name, string $code)
    {
        $this->name = $name;
        $this->code = $code;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Подсказка к полю: зачем оно и что в нём писать.
     *
     *   Text('Заголовок', 'title')->help('Показывается в шапке, до 60 символов')
     */
    public function help(string $text): static
    {
        $this->help = $text;

        return $this;
    }

    public function getHelp(): ?string
    {
        return $this->help;
    }

    /**
     * Короткий тип поля для фронтенда/сериализации (например, "text", "color").
     */
    public function getType(): string
    {
        return Registry::typeOf(static::class);
    }

    /**
     * Универсальная установка произвольной настройки конкретного поля
     * (например, placeholder, min, max, accept). Возвращает $this для цепочки.
     */
    public function set(string $key, mixed $value): static
    {
        $this->settings[$key] = $value;

        return $this;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * Имя события поля в формате <BlockCode>:<FieldCode>:<событие>.
     *
     * @param string $blockCode символьный код блока-владельца
     * @param string $event     Event\FieldEvent::* (add|update|delete)
     */
    public function eventName(string $blockCode, string $event): string
    {
        return sprintf('%s:%s:%s', $blockCode, $this->code, $event);
    }

    /**
     * Значение по умолчанию для нового значения поля.
     * Контейнерные поля переопределяют для возврата структуры дочерних полей.
     */
    public function normalizeDefault(): mixed
    {
        return $this->getDefault();
    }

    /**
     * «Пусто» этого поля — значение, когда его не заполняли, без настроенного
     * `->default()`.
     *
     * Отличие от {@see normalizeDefault} принципиальное: умолчание применимо
     * только к свежему (никогда не сохранявшемуся) слою значений. У уже
     * сохранённого слоя отсутствие ключа значит «поле оставили пустым» —
     * подставлять сюда настроенный default нельзя (иначе очищенное или
     * добавленное позже поле снова показывало бы умолчание, а следующее
     * сохранение его бы и записало). Тип пустого совпадает с типом значения
     * поля: скаляр — пустая строка, флажок с вариантами/множественный список —
     * массив, контейнер — пустой список. Совпадает с тем, что рисует пустому
     * полю фронтенд.
     */
    public function emptyValue(): mixed
    {
        return '';
    }

    /**
     * Сериализация конфигурации поля (не значения) для передачи во фронтенд.
     */
    public function toArray(): array
    {
        return [
            'type'       => $this->getType(),
            'name'       => $this->name,
            'code'       => $this->code,
            'help'       => $this->help,
            'default'    => $this->getDefault(),
            'settings'   => $this->settings,
            'policy'     => $this->getPolicy(),
            // Решение по политике считает сервер и отдаёт готовым: клиент не
            // знает ни групп текущего пользователя, ни именованных политик.
            // lockReason — чтобы пользователь понимал, почему поле недоступно,
            // а не упирался в молча заблокированный ввод.
            'editable'   => \Structura\Structura\Access\Policy::allows($this->getPolicy()),
            'lockReason' => \Structura\Structura\Access\Policy::reason($this->getPolicy()),
            'validation' => array_map(
                static fn (Validation $rule) => $rule->toArray(),
                $this->getValidation()
            ),
        ];
    }
}
