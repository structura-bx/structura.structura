<?php

namespace Structura\Structura\Validation;

use Bitrix\Main\Localization\Loc;
use Structura\Structura\Fields\HighloadElement;
use Structura\Structura\Validation;

Loc::loadMessages(__FILE__);

/**
 * Значение — ID существующей строки заданного Highload-блока.
 *
 * Правило встроенное: поле HighloadElement вешает его на себя само
 * (Field::defaultValidation) — ID несуществующей строки всегда ошибка, а до
 * ajax-действия save можно дойти запросом мимо пикера. Значение — именно ID, а
 * не UF_XML_ID (тот участвует лишь в подписи строки, см. hlrowsAction).
 *
 * Пустое значение валидно: обязательность — дело Required(). Во фронтенд правило
 * уезжает, но там игнорируется (клиент про содержимое HL-блока не знает).
 */
class HighloadRowExists extends Validation
{
    /** @param int|string|null $hlblock значение ->entity(): ID, имя сущности или таблицы */
    public function __construct(private int|string|null $hlblock = null)
    {
    }

    public function passes(mixed $value, array $context = []): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (!is_scalar($value)) {
            return false;
        }

        $raw = trim((string) $value);
        $id  = (int) $raw;

        // Сравнение с исходной строкой отсекает «12abc» и «1.5»: (int) молча
        // возьмёт из них 12 и 1, а это не ID строки. Здесь же отсекается и
        // XML_ID, если кто-то попробует положить в значение его.
        if ($id <= 0 || (string) $id !== $raw) {
            return false;
        }

        // HL-блок не задан — проверять не по чему; ронять сохранение из-за
        // незаполненного конфига незачем.
        if ($this->hlblock === null || $this->hlblock === '') {
            return true;
        }

        // Без ядра Bitrix проверить нечем — пропускаем (как FileExists).
        if (!class_exists(\Bitrix\Main\Loader::class)
            || !\Bitrix\Main\Loader::includeModule('highloadblock')
        ) {
            return true;
        }

        $block = HighloadElement::resolveBlock($this->hlblock);
        if ($block === null) {
            // HL-блока с таким именем нет — это ошибка конфига, а не значения.
            return true;
        }

        $dataClass = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($block)->getDataClass();

        return (bool) $dataClass::getList([
            'select' => ['ID'],
            'filter' => ['=ID' => $id],
            'limit'  => 1,
        ])->fetch();
    }

    public function message(): string
    {
        return (string) Loc::getMessage('STRUCTURA_HLROWEXISTS_MESSAGE');
    }

    public function code(): string
    {
        return 'highloadRowExists';
    }
}
