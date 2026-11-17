<?php

namespace Structura\Structura;

/**
 * Группа блоков — то, что возвращает `.group.php`.
 *
 * Папка каталога блоков становится группой, если содержит `.group.php`:
 *
 *   return (new BlockGroup('Баннеры'))->sort(60)->unique();
 *
 * Группа — только организация папок и правила показа. Код блока от неё не
 * зависит: он равен имени папки блока и остаётся плоским, поэтому папку с блоком
 * (и целую группу) можно перетаскивать по дереву, не ломая значения в БД,
 * ревизии, имена событий и рендер. Обратная сторона — имена папок блоков
 * уникальны по всему дереву; коллизии видит Loader::conflicts().
 */
final class BlockGroup
{
    private string $name;

    /** Порядок группы в пикере (меньше — выше). */
    private int $sort = 100;

    private bool $unique = false;

    /**
     * @param string $name человекочитаемое имя группы; пустое — имя папки
     *                     (его подставит загрузчик).
     */
    public function __construct(string $name = '')
    {
        $this->name = $name;
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

    /** Порядок группы в пикере: меньше — выше. */
    public function sort(int $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    public function getSort(): int
    {
        return $this->sort;
    }

    /**
     * Группа взаимоисключающих блоков: на одной сущности допустим только один
     * блок из этой группы (например, широкий или узкий баннер).
     *
     * Ограничение накрывает всю ветку, включая вложенные подгруппы, и сильнее
     * Block::multiple(): вторая копия множественного блока из такой группы для
     * группы тоже второй экземпляр. Применяет правило сервер при сохранении
     * (ValueService), пикер лишь показывает пункт заблокированным.
     */
    public function unique(bool $unique = true): static
    {
        $this->unique = $unique;

        return $this;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }
}
