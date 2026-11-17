<?php

namespace Structura\Structura\Traits;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Правила привязки блока: к каким инфоблокам (ID или CODE), разделам, элементам
 * относится блок. Пустые списки означают «доступно везде».
 */
trait HasVisibility
{
    /**
     * Канонические ключи — в нижнем регистре, как и везде в настройках модуля.
     * (Константу в трейте объявить нельзя: это PHP >= 8.2, а модуль — с 8.0.)
     */
    protected array $visibility = [
        'iblocks'  => [],
        'sections' => [],
        'elements' => [],
    ];

    /**
     * Правила привязки блока.
     *
     * Ключи регистронезависимы: старые конфиги с 'Iblocks'/'Sections'/'Elements'
     * продолжают работать и приводятся к нижнему регистру.
     *
     * @param array{iblocks?: array, sections?: array, elements?: array} $rules
     *
     * @throws \InvalidArgumentException при неизвестном ключе — иначе опечатка
     *         («Iblock» вместо «iblocks») тихо превратилась бы в «доступно везде».
     */
    public function visibleOn(array $rules): static
    {
        $allowed    = ['iblocks', 'sections', 'elements'];
        $normalized = [];

        foreach ($rules as $key => $value) {
            $lower = strtolower((string) $key);
            if (!in_array($lower, $allowed, true)) {
                throw new \InvalidArgumentException((string) Loc::getMessage('STRUCTURA_HASVISIBILITY_UNKNOWN_RULE', [
                    '#RULE#'    => (string) $key,
                    '#ALLOWED#' => implode(', ', $allowed),
                ]));
            }
            $normalized[$lower] = $value;
        }

        $this->visibility = array_replace($this->visibility, $normalized);

        return $this;
    }

    public function getVisibility(): array
    {
        return $this->visibility;
    }

    /**
     * Доступен ли блок для конкретной сущности.
     *
     * @param string   $entityType Structura\Structura\Storage\ValueTable::ENTITY_* (iblock|section|element)
     * @param int      $entityId   ID сущности
     * @param int|null $iblockId   ID инфоблока, которому принадлежит сущность
     * @param string|null $iblockCode CODE инфоблока
     */
    public function isVisibleFor(
        string $entityType,
        int $entityId,
        ?int $iblockId = null,
        ?string $iblockCode = null
    ): bool {
        $iblocks  = $this->visibility['iblocks']  ?? [];
        $sections = $this->visibility['sections'] ?? [];
        $elements = $this->visibility['elements'] ?? [];

        // Полностью пустые правила — блок доступен везде.
        if (!$iblocks && !$sections && !$elements) {
            return true;
        }

        // Привязка к инфоблоку по ID или символьному коду.
        $iblockMatches = !$iblocks
            || ($iblockId !== null && in_array($iblockId, $iblocks, false))
            || ($iblockCode !== null && in_array($iblockCode, $iblocks, true));

        return match ($entityType) {
            'element' => $iblockMatches && (!$elements || in_array($entityId, $elements, false)),
            'section' => $iblockMatches && (!$sections || in_array($entityId, $sections, false)),
            default   => $iblockMatches,
        };
    }
}
