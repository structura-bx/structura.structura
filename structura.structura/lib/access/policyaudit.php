<?php

namespace Structura\Structura\Access;

use Bitrix\Main\Localization\Loc;
use Structura\Structura\Block;
use Structura\Structura\Field;
use Structura\Structura\Fields\ContainerField;
use Structura\Structura\Fields\Flexible;

Loc::loadMessages(__FILE__);

/**
 * Аудит политик: кто может править блоки и поля.
 *
 * Отвечает на вопрос «кому открыто это поле?», который иначе выясняется чтением
 * всех config.php. Экран только для чтения: политики задаются в config.php,
 * второго места настройки нет — иначе источник правды расщепился бы.
 *
 * Собираются только ограниченные записи: поле, которого нет в списке, доступно
 * всем. Описание берётся из Policy::describe(), а не reason(): аудит показывает
 * одно и то же кому угодно, независимо от прав смотрящего.
 */
final class PolicyAudit
{
    /**
     * @param array<string, Block> $blocks код => блок (обычно ConfigLoader::all())
     *
     * @return array<int, array{block: string, blockName: string, path: string,
     *                          name: string, kind: string, description: string}>
     */
    public static function rows(array $blocks): array
    {
        $rows = [];

        foreach ($blocks as $code => $block) {
            $blockDescription = Policy::describe($block->getPolicy());
            if ($blockDescription !== null) {
                $rows[] = [
                    'block'       => (string) $code,
                    'blockName'   => $block->getName(),
                    'path'        => '',
                    'name'        => $block->getName(),
                    'kind'        => (string) Loc::getMessage('STRUCTURA_POLICYAUDIT_KIND_BLOCK'),
                    'description' => $blockDescription,
                ];
            }

            self::walk($block->getFields(), (string) $code, $block->getName(), '', $rows);
        }

        return $rows;
    }

    /**
     * @param Field[]           $fields
     * @param array<int, array> $rows
     */
    private static function walk(array $fields, string $code, string $blockName, string $prefix, array &$rows): void
    {
        foreach ($fields as $field) {
            $path = $prefix === '' ? $field->getCode() : $prefix . '.' . $field->getCode();

            $description = Policy::describe($field->getPolicy());
            if ($description !== null) {
                $rows[] = [
                    'block'       => $code,
                    'blockName'   => $blockName,
                    'path'        => $path,
                    'name'        => $field->getName(),
                    'kind'        => self::kind($field),
                    'description' => $description,
                ];
            }

            // Внутрь идём всегда, даже если контейнер уже ограничен: у
            // вложенного поля может быть своя, ещё более узкая политика.
            if ($field instanceof Flexible) {
                foreach ($field->getLayouts() as $layout) {
                    self::walk([$layout], $code, $blockName, $path, $rows);
                }
            } elseif ($field instanceof ContainerField) {
                self::walk($field->getFields(), $code, $blockName, $path, $rows);
            }
        }
    }

    private static function kind(Field $field): string
    {
        if ($field instanceof Flexible) {
            return (string) Loc::getMessage('STRUCTURA_POLICYAUDIT_KIND_FLEXIBLE');
        }

        if ($field instanceof \Structura\Structura\Fields\Group) {
            return (string) Loc::getMessage('STRUCTURA_POLICYAUDIT_KIND_GROUP');
        }

        return (string) Loc::getMessage($field instanceof ContainerField
            ? 'STRUCTURA_POLICYAUDIT_KIND_CONTAINER'
            : 'STRUCTURA_POLICYAUDIT_KIND_FIELD');
    }
}
