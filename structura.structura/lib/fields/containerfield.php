<?php

namespace Structura\Structura\Fields;

use Bitrix\Main\Localization\Loc;
use Structura\Structura\Field;
use Structura\Structura\Traits\HasFields;

Loc::loadMessages(__FILE__);

/**
 * Базовый класс полей-контейнеров (Repeater, Flexible).
 * Содержит вложенный набор полей и сериализует их в конфигурацию.
 */
abstract class ContainerField extends Field
{
    use HasFields;

    protected ?int $minItems = null;
    protected ?int $maxItems = null;

    /**
     * У контейнера своего умолчания нет: значение Repeater/Flexible — это
     * набор строк (по умолчанию пустой), а Group собирает умолчание из
     * вложенных полей. Заданный тут `->default()` никуда не попадал —
     * normalizeDefault его игнорирует, — поэтому это молчаливая пустышка, а не
     * настройка. Падаем с именем поля, чтобы автор конфигурации увидел ошибку,
     * а не гадал, почему умолчание «не сработало» (задавать умолчания вложенным
     * полям — можно и нужно, каждому своё).
     *
     * @throws \LogicException всегда
     */
    public function default(mixed $value): static
    {
        throw new \LogicException((string) Loc::getMessage('STRUCTURA_CONTAINERFIELD_NO_DEFAULT', [
            '#NAME#' => $this->getName(),
            '#CODE#' => $this->getCode(),
        ]));
    }

    public function min(int $count): static
    {
        $this->minItems = $count;
        $this->settings['min'] = $count;

        return $this;
    }

    public function max(int $count): static
    {
        $this->maxItems = $count;
        $this->settings['max'] = $count;

        return $this;
    }

    public function normalizeDefault(): mixed
    {
        // Контейнер по умолчанию пуст (набор повторов).
        return [];
    }

    /** Пусто контейнера-списка — тот же пустой набор строк. */
    public function emptyValue(): mixed
    {
        return [];
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'fields' => array_map(
                static fn (Field $field) => $field->toArray(),
                $this->getFields()
            ),
        ]);
    }
}
