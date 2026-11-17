<?php

namespace Structura\Structura\Traits;

use Bitrix\Main\Localization\Loc;
use Structura\Structura\Field;

Loc::loadMessages(__FILE__);

/**
 * Контейнерное поведение: набор дочерних полей.
 * Используется Block, а также полями-контейнерами (Repeater, Flexible).
 */
trait HasFields
{
    /** @var Field[] Дочерние поля, индексированные по символьному коду. */
    protected array $fields = [];

    /**
     * Код поля: безопасный идентификатор [A-Za-z_][A-Za-z0-9_]*.
     *
     * Точка запрещена не случайно: по ней строится плоский путь поля
     * ("repeater.child" — см. Block::flattenFields, Validator, разбор пути при
     * клонировании строки). Код с точкой дал бы коллизию ключей в плоской карте,
     * увёл бы ошибку валидации не к тому полю и сломал бы explode('.') при клоне.
     * Прочие спецсимволы запрещены заодно: код уходит ещё и в имена событий
     * `block:code:event` и в ключи JS — один безопасный набор закрывает всё это.
     */
    private const CODE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Добавляет дочерние поля. Повторный вызов с тем же кодом перезаписывает
     * поле (документированное поведение — набор полей можно достраивать).
     *
     * Код проверяется здесь — в единственной точке, через которую поле попадает
     * в контейнер (в т.ч. дети Repeater/Group и макеты Flexible): дальше именно
     * этот код становится сегментом плоского пути.
     */
    public function setFields(Field ...$fields): static
    {
        // Повтор кода в пределах одного вызова — это ошибка config.php, а не
        // «достраивание»: два поля с общим кодом объявлены рядом, и без проверки
        // одно молча затирало бы другое ($this->fields[$code] = ...). Значение
        // адресуется кодом, поэтому «затёртое» поле нельзя ни заполнить, ни
        // прочитать — на форме и в JSON остаётся только последнее. Отдельно от
        // цикла ниже: тот сравнивает с уже добавленными полями (возможно, из
        // прежнего вызова — их перезапись разрешена намеренно), а здесь нужен
        // конфликт именно внутри текущего списка.
        $withinCall = [];
        foreach ($fields as $field) {
            $code = $field->getCode();
            if (isset($withinCall[$code])) {
                // Код контейнера, если он есть: у Group/Repeater/Flexible он
                // задан, у самого блока в момент чтения config.php ещё пуст
                // (его проставит загрузчик по имени папки).
                $ownCode = method_exists($this, 'getCode') ? $this->getCode() : '';
                $where = $ownCode === ''
                    ? Loc::getMessage('STRUCTURA_HASFIELDS_DUPLICATE_TOP')
                    : Loc::getMessage('STRUCTURA_HASFIELDS_DUPLICATE_INSIDE', ['#OWNER#' => $ownCode]);
                throw new \RuntimeException((string) Loc::getMessage('STRUCTURA_HASFIELDS_DUPLICATE_CODE', [
                    '#CODE#'  => $code,
                    '#WHERE#' => $where,
                ]));
            }
            $withinCall[$code] = true;
        }

        foreach ($fields as $field) {
            $code = $field->getCode();
            if (!preg_match(self::CODE_PATTERN, $code)) {
                throw new \RuntimeException((string) Loc::getMessage('STRUCTURA_HASFIELDS_BAD_CODE', ['#CODE#' => $code]));
            }

            // Radio без вариантов рисует пустое место (по одному элементу на
            // вариант, а вариантов нет), Select вырождается в список из одного
            // прочерка. Подпись при этом есть, и со стороны это неотличимо от
            // сломанного модуля, хотя причина в конфиге. Отвергаем здесь же, где
            // и плохой код: это единственная точка, через которую поле попадает
            // в контейнер.
            //
            // Спрашиваем само поле (optionsRequired), а не список классов:
            // Checkbox без вариантов — это законный одиночный переключатель, и
            // то же может быть верно для стороннего поля на трейте HasOptions.
            if (
                method_exists($field, 'getOptions')
                && method_exists($field, 'optionsRequired')
                && $field->optionsRequired()
                && $field->getOptions() === []
            ) {
                throw new \RuntimeException((string) Loc::getMessage('STRUCTURA_HASFIELDS_NO_OPTIONS', ['#CODE#' => $code]));
            }

            $this->fields[$code] = $field;
        }

        return $this;
    }

    /** @return Field[] */
    public function getFields(): array
    {
        return array_values($this->fields);
    }

    public function getField(string $code): ?Field
    {
        return $this->fields[$code] ?? null;
    }

    public function hasField(string $code): bool
    {
        return isset($this->fields[$code]);
    }
}
