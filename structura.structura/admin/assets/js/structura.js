/**
 * Structura — фронтенд пользовательских свойств (Vue 2 + Vuex).
 *
 * Публичный API:
 *   window.Structura.mount(selector, options)   — смонтировать приложение;
 *   window.Structura.registerField(type, comp)  — зарегистрировать своё поле.
 *
 * options = {
 *   entity: { type, id, iblock },
 *   blocks:    [ blockConfig, ... ],        // каталог доступных блоков (Block::toArray())
 *   instances: [ { instanceId, blockCode, values }, ... ],  // что уже добавлено, в порядке показа
 *   save:      'structura:structura.block.save',      // ajax-действия (необязательны)
 *   remove:    'structura:structura.block.remove',    // удалить экземпляр
 *   reorder:   'structura:structura.block.reorder',   // порядок экземпляров
 *   revisions: 'structura:structura.block.revisions', // список ревизий (если у блока вкл.)
 *   revision:  'structura:structura.block.revision',  // одна ревизия — для предпросмотра
 *   rollback:  'structura:structura.block.rollback',  // откат к ревизии
 *   values:    'structura:structura.block.values',    // значения другого языка
 *   prefill:   'structura:structura.block.prefill',   // заполнить перевод из базы
 *   languages: ['ru', 'en'],                 // языки контента (глобальные)
 *   languageFallback: 'ru'                   // язык по умолчанию (лежит в базе)
 * }
 *
 * Языки. У блока с ->translatable() карточка получает вкладки языков. В форме
 * живут значения одного языка: вкладка выбирает строку хранения (fallback —
 * базовая запись, остальные — перевод), поэтому переключение перезагружает
 * значения, а «Сохранить» пишет ровно один язык. Активность, окно дат и
 * порядок экземпляра общие на все языки и правятся только на вкладке fallback.
 *
 * Экземпляры. Единица работы — экземпляр блока, а не блок: блок с ->multiple()
 * можно добавить к сущности несколько раз. Всё состояние (значения, ошибки,
 * история) адресуется instanceId, поэтому копии одного блока независимы.
 * У обычного блока экземпляр один и его id равен коду блока — то же правило,
 * что и на сервере (ValueService::defaultInstanceId).
 *
 * Панель истории/отката показывается у блока, если block.revisions.enabled.
 *
 * Состояние хранится в Vuex. Компоненты полей рекурсивны: Repeater и Flexible
 * рендерят вложенные поля тем же <structura-field>, что даёт произвольную
 * вложенность (Repeater → Flexible → Repeater → …).
 */
(function (window) {
    'use strict';

    var BX = window.BX || {};
    var Vue = BX.VueVendor || BX.VueVendorV2 || window.Vue;
    var Vuex = window.Vuex || BX.Vuex;

    // Локализация. Строки берутся из lang стандартным Bitrix-механизмом, но
    // печатаются в собственный словарь BX.structura.messages (см.
    // Integration\AdminIblock), а не в глобальный BX.message — чтобы не засорять
    // общий словарь ядра. Помощник подставляет значения #TOKEN# и отдаёт сам
    // ключ, если перевода нет, — так недостающая строка видна, а не пустая.
    function t(key, replacements) {
        var store = (BX.structura && BX.structura.messages) || {};
        var msg = Object.prototype.hasOwnProperty.call(store, key) ? store[key] : '';
        if (msg === undefined || msg === null || msg === false || msg === '') { msg = key; }
        if (replacements) {
            for (var token in replacements) {
                if (Object.prototype.hasOwnProperty.call(replacements, token)) {
                    msg = msg.split(token).join(replacements[token]);
                }
            }
        }
        return msg;
    }

    if (!Vue || !Vuex) {
        window.console && console.error('[Structura] Vue или Vuex недоступны.');
        return;
    }
    Vue.use(Vuex);
    // Метод t доступен во всех шаблонах компонентов: {{ t('KEY') }}.
    Vue.mixin({ methods: { t: t } });

    // Размер страницы истории. История бывает длинной (лимит ревизий у блока
    // может быть снят), поэтому список грузится порциями по кнопке «Показать ещё»,
    // а не целиком: иначе все записи оказались бы в DOM разом.
    var REV_PAGE_SIZE = 20;

    /* ------------------------------------------------------------------ *
     *  Утилиты доступа к вложенным значениям по строковому пути
     * ------------------------------------------------------------------ */
    function getByPath(root, path) {
        if (!path) { return root; }
        var parts = path.split('.');
        var node = root;
        for (var i = 0; i < parts.length; i++) {
            if (node == null) { return undefined; }
            node = node[parts[i]];
        }
        return node;
    }

    function setByPath(root, path, value) {
        var parts = path.split('.');
        var node = root;
        for (var i = 0; i < parts.length - 1; i++) {
            var key = parts[i];
            var nextIsIndex = /^\d+$/.test(parts[i + 1]);
            if (node[key] === undefined || node[key] === null) {
                Vue.set(node, key, nextIsIndex ? [] : {});
            }
            node = node[key];
        }
        Vue.set(node, parts[parts.length - 1], value);
    }

    // Помечает экземпляр как имеющий несохранённые правки. Зовётся из мутаций,
    // меняющих содержимое по действию пользователя (ввод, строки контейнеров).
    function markDirty(state, instance) {
        if (!state.dirty[instance]) { Vue.set(state.dirty, instance, true); }
    }

    // Контейнеры-списки: их значение — массив строк. Group сюда не входит
    // намеренно — у неё значение это один объект (см. defaultForField).
    function isContainer(type) {
        return type === 'repeater' || type === 'flexible';
    }

    /**
     * Одиночный флажок — Checkbox без ->options() (см. Fields\Checkbox).
     * С вариантами то же поле работает группой флажков, и значение у неё массив.
     */
    function isSingleCheckbox(field) {
        return field.type === 'checkbox'
            && Object.keys((field.settings || {}).options || {}).length === 0;
    }

    /** Множественный список — Select с ->multiple() (см. Fields\Select). */
    function isMultipleSelect(field) {
        return field.type === 'select' && !!((field.settings || {}).multiple);
    }

    // Включённость одиночного флажка. Значение приезжает из JSON и бывает
    // строкой, поэтому сравниваем по списку, а не приводим к boolean: пустая
    // строка и 'N' одинаково значат «выключено».
    function isCheckboxOn(value) {
        return value === 'Y' || value === true || value === 1 || value === '1';
    }

    function defaultForField(field) {
        if (isContainer(field.type)) { return []; }
        // Group: значение — объект из умолчаний вложенных полей. Правило обязано
        // совпадать с серверным (Fields\Group::normalizeDefault), иначе новая
        // строка приезжала бы на сервер с пустой группой вместо умолчаний.
        if (field.type === 'group') { return blankRow(field.fields); }
        // Одиночный флажок — тоже своё правило (Fields\Checkbox::normalizeDefault):
        // 'N' вместо пустоты, чтобы «выключено» и «не трогали» не различались.
        if (isSingleCheckbox(field)) { return isCheckboxOn(field.default) ? 'Y' : 'N'; }
        // Множественный список — своё правило (Fields\Select::normalizeDefault):
        // значение всегда массив, одиночное умолчание оборачиваем, пустое → [].
        if (isMultipleSelect(field)) {
            if (Array.isArray(field.default)) { return field.default.slice(); }
            return (field.default !== undefined && field.default !== null) ? [field.default] : [];
        }
        if (field.default !== undefined && field.default !== null) { return field.default; }
        return '';
    }

    function blankRow(fields) {
        var row = {};
        (fields || []).forEach(function (f) { row[f.code] = defaultForField(f); });
        return row;
    }

    /**
     * Идентификатор строки контейнера. Формат обязан совпадать с серверным
     * (Service\RowId::generate) — 16 шестнадцатеричных знаков.
     *
     * Нужен именно на клиенте: строки создаются здесь, а сопоставить их со
     * старыми значениями сервер должен уже при первом сохранении. Без своего id
     * новая строка была бы для слияния политик безымянной и попала бы в
     * сопоставление по индексу — ровно то, от чего id и заводится.
     */
    function newRowId() {
        var bytes = new Uint8Array(8);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < bytes.length; i++) { bytes[i] = Math.floor(Math.random() * 256); }
        }
        return Array.prototype.map.call(bytes, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
    }

    // Новая строка контейнера — с идентификатором. Отдельно от blankRow:
    // значение Group строится тем же blankRow, но строкой не является и своего
    // id не имеет (адресуется кодом поля), как и на сервере.
    function newRow(fields) {
        var row = blankRow(fields);
        row._id = newRowId();
        return row;
    }

    /** Свежие id строке-копии и всем вложенным строкам (рекурсивно). */
    function refreshRowIds(row) {
        if (Array.isArray(row)) {
            return row.map(refreshRowIds);
        }
        if (!row || typeof row !== 'object') { return row; }

        var out = {};
        Object.keys(row).forEach(function (k) { out[k] = refreshRowIds(row[k]); });
        if (Object.prototype.hasOwnProperty.call(row, '_id')) { out._id = newRowId(); }
        return out;
    }

    /* ------------------------------------------------------------------ *
     *  Сравнение значений (предпросмотр отката)
     * ------------------------------------------------------------------ */

    // Метка пустого контейнера. Объект, а не строка: сравнивается по ссылке,
    // поэтому не может совпасть с реальным значением поля.
    var EMPTY_MARK = { empty: true };

    /**
     * Разворачивает дерево значений в плоскую карту "путь → скаляр".
     * Пустые контейнеры фиксируются явно, иначе исчезновение строк Repeater
     * не попало бы в diff.
     */
    function flattenValues(value, prefix, out) {
        out = out || {};
        if (Array.isArray(value)) {
            if (!value.length) { out[prefix] = EMPTY_MARK; return out; }
            value.forEach(function (item, i) {
                flattenValues(item, prefix ? prefix + '.' + i : String(i), out);
            });
            return out;
        }
        if (value && typeof value === 'object') {
            // `_id` — служебный идентификатор строки контейнера (Service\RowId),
            // не значение поля. В diff ему не место: он меняется при клоне строки
            // и дал бы шумную запись «… → #1 → _id: старый → новый». Пустую строку
            // (только `_id`, без полей) после отсева считаем пустым контейнером.
            var keys = Object.keys(value).filter(function (k) { return k !== '_id'; });
            if (!keys.length) { out[prefix] = EMPTY_MARK; return out; }
            keys.forEach(function (k) {
                flattenValues(value[k], prefix ? prefix + '.' + k : k, out);
            });
            return out;
        }
        out[prefix] = value;
        return out;
    }

    /** Человекочитаемый путь: "Слайды → #1 → Подпись" вместо "slides.0.caption". */
    function labelForPath(blockConfig, path) {
        var parts  = String(path).split('.');
        var fields = (blockConfig && blockConfig.fields) || [];
        var out    = [];

        for (var i = 0; i < parts.length; i++) {
            var part = parts[i];
            if (/^\d+$/.test(part)) { out.push('#' + (parseInt(part, 10) + 1)); continue; }
            if (part === 'data' || part === '_layout') { continue; }

            var found = null;
            for (var j = 0; j < fields.length; j++) {
                if (fields[j].code === part) { found = fields[j]; break; }
            }
            if (!found) { out.push(part); fields = []; continue; }

            out.push(found.name || found.code);
            fields = found.fields || (found.layouts ? [] : []);
        }
        return out.join(' → ');
    }

    /** Отображение значения в diff: пустое/отсутствующее видно явно. */
    function formatValue(v) {
        if (v === EMPTY_MARK) { return t('STRUCTURA_JS_VALUE_EMPTY'); }
        if (v === undefined) { return t('STRUCTURA_JS_VALUE_NONE'); }
        if (v === null || v === '') { return t('STRUCTURA_JS_VALUE_EMPTY'); }
        if (v === true) { return t('STRUCTURA_JS_YES'); }
        if (v === false) { return t('STRUCTURA_JS_NO'); }
        return String(v);
    }

    /**
     * Разница между текущими значениями и значениями ревизии.
     * from — что сейчас, to — что станет после отката.
     * @return {Array<{path,label,from,to,type}>}
     */
    function diffValues(blockConfig, current, revision) {
        var a = flattenValues(current || {}, '', {});
        var b = flattenValues(revision || {}, '', {});
        var paths = Object.keys(a).concat(Object.keys(b)).filter(function (p, i, arr) {
            return arr.indexOf(p) === i;
        }).sort();

        // Есть ли на стороне map хоть один потомок пути p ("rows" → "rows.0.caption").
        function hasChildren(map, p) {
            var prefix = p + '.';
            return Object.keys(map).some(function (k) { return k.indexOf(prefix) === 0; });
        }

        var rows = [];
        paths.forEach(function (p) {
            var from = a[p];
            var to   = b[p];
            if (String(from) === String(to)) { return; }

            // Опустошение контейнера: на одной стороне метка "пусто", на другой —
            // сами строки. Про изменение уже скажут записи по потомкам, а строка
            // "Строки: (нет) → (пусто)" была бы шумом.
            if (from === EMPTY_MARK && hasChildren(b, p)) { return; }
            if (to === EMPTY_MARK && hasChildren(a, p)) { return; }

            var type = 'change';
            if (from === undefined) { type = 'add'; }
            else if (to === undefined) { type = 'remove'; }

            rows.push({
                path:  p,
                label: labelForPath(blockConfig, p),
                from:  formatValue(from),
                to:    formatValue(to),
                type:  type
            });
        });
        return rows;
    }

    /* ------------------------------------------------------------------ *
     *  Макеты Flexible в пути
     * ------------------------------------------------------------------ */
    /**
     * Коды макетов Flexible, встреченных по пути, — по порядку.
     *
     * Нужно серверу, чтобы клонировать строку внутри макета: у Flexible
     * `getFields()` отдаёт макеты, а не поля, и пройти его одним лишь путём
     * нельзя — какой макет выбран, записано в значении (`_layout`), а путь про
     * это молчит («content.0.data»: 0 — индекс элемента Flexible, а не поля).
     *
     * Идём по значениям, а не по конфигу: только там есть `_layout`. Элемент
     * Flexible узнаём ровно по нему — другого признака у значения нет.
     *
     * Считает стор, а не компонент строки: Repeater не знает, лежит ли он
     * внутри макета, и знать не должен — у него на руках только свой путь.
     *
     * @param {Object} values значения экземпляра блока
     * @param {String} path   путь до контейнера ("sections.0.content.0.data")
     * @returns {Array} коды макетов сверху вниз
     */
    function layoutTrail(values, path) {
        var node  = values;
        var trail = [];

        String(path == null ? '' : path).split('.').forEach(function (segment) {
            if (segment === '' || node === null || typeof node !== 'object') {
                node = null;
                return;
            }

            node = node[segment];
            if (node && typeof node === 'object' && typeof node._layout === 'string') {
                trail.push(node._layout);
            }
        });

        return trail;
    }

    /* ------------------------------------------------------------------ *
     *  Клиентская валидация (зеркало серверного рекурсивного Validator)
     * ------------------------------------------------------------------ */
    /**
     * Принимает правило целиком (`{rule, message, params}`), а не один код:
     * у length/range ограничения лежат в params. Правила с параметрами обязаны
     * повторять серверные (Validation\Length, Validation\Range) — расхождение
     * значит, что форма показывает не то, что решит сервер.
     */
    function passesRule(rule, v) {
        var code   = rule.rule;
        var params = rule.params || {};

        if (code === 'required') {
            if (v === null || v === undefined) { return false; }
            if (typeof v === 'string') { return v.trim() !== ''; }
            if (Array.isArray(v)) { return v.length > 0; }
            return true;
        }
        if (code === 'notEmpty') {
            var c = (typeof v === 'string') ? v.trim() : v;
            if (Array.isArray(c)) { return c.length > 0; }
            return !(c === '' || c === 0 || c === '0' || c === null || c === undefined || c === false);
        }
        // Пустое значение пропускают оба: обязательность — дело required.
        if (code === 'length') {
            if (v === null || v === undefined || v === '' || Array.isArray(v)) { return true; }
            // [...str] считает символы, а не единицы UTF-16, — так же, как
            // mb_strlen на сервере (у str.length «Привет» и эмодзи разойдутся).
            var len = Array.from(String(v)).length;
            if (params.min != null && len < params.min) { return false; }
            return !(params.max != null && len > params.max);
        }
        if (code === 'range') {
            if (v === null || v === undefined || v === '' || Array.isArray(v)) { return true; }
            // Number('12abc') даёт NaN, но Number(' ') даёт 0 — поэтому пустую
            // строку отсекаем выше, а не полагаемся на приведение.
            var n = Number(v);
            if (isNaN(n)) { return false; }
            if (params.min != null && n < params.min) { return false; }
            if (params.max != null && n > params.max) { return false; }
            if (params.step != null && params.step > 0) {
                // Шаг — от минимума, как у input[type=number]; сравнение с
                // допуском, иначе дробный шаг разваливается на погрешности.
                var steps = (n - (params.min != null ? params.min : 0)) / params.step;
                if (Math.abs(steps - Math.round(steps)) > 1e-9) { return false; }
            }
            return true;
        }
        return true;
    }

    function addErr(errors, path, msg) {
        (errors[path] = errors[path] || []).push(msg);
    }

    function itemsCount(field, n, path, errors) {
        var s = field.settings || {};
        if (s.min != null && n < s.min) { addErr(errors, path, t('STRUCTURA_JS_MIN_ITEMS', { '#MIN#': s.min })); }
        if (s.max != null && n > s.max) { addErr(errors, path, t('STRUCTURA_JS_MAX_ITEMS', { '#MAX#': s.max })); }
    }

    function validateField(field, v, path, errors) {
        (field.validation || []).forEach(function (r) {
            if (!passesRule(r, v)) { addErr(errors, path, r.message); }
        });
        // Group — до общей ветки контейнера и без индекса в пути: значение это
        // один объект, а не список строк, поэтому путь к вложенному полю
        // выглядит как «cta.text» — так же, как его считает сервер
        // (Validation\Validator::validateGroup).
        if (field.type === 'flexible') { validateFlexible(field, v, path, errors); }
        else if (field.type === 'group') { validateFields(field.fields, v || {}, path, errors); }
        else if (field.type === 'repeater') { validateRepeater(field, v, path, errors); }
    }

    function validateFields(fields, vals, prefix, errors) {
        (fields || []).forEach(function (field) {
            var p = prefix ? prefix + '.' + field.code : field.code;
            var v = vals ? vals[field.code] : undefined;
            validateField(field, v, p, errors);
        });
    }

    function validateRepeater(field, v, path, errors) {
        var rows = Array.isArray(v) ? v : [];
        itemsCount(field, rows.length, path, errors);
        rows.forEach(function (row, i) {
            validateFields(field.fields, row || {}, path + '.' + i, errors);
        });
    }

    function validateFlexible(field, v, path, errors) {
        var items = Array.isArray(v) ? v : [];
        itemsCount(field, items.length, path, errors);
        items.forEach(function (item, i) {
            item = item || {};
            var ip = path + '.' + i;
            var layout = (field.fields || []).filter(function (f) { return f.code === item._layout; })[0];
            if (!layout) { addErr(errors, ip, t('STRUCTURA_JS_UNKNOWN_LAYOUT')); return; }
            validateField(layout, item.data, ip + '.data', errors);
        });
    }

    function validateBlock(block, values) {
        var errors = {};
        validateFields(block.fields, values || {}, '', errors);
        return errors;
    }

    /**
     * Ошибки полей, куда браузер не смог прочитать введённое.
     *
     * У input[type=number] и полей даты/времени невалидный ввод приходит пустой
     * строкой: «12abc» и наполовину заполненная дата дают value === ''. По
     * значению отличить «не заполнил» от «ввёл ерунду» невозможно, поэтому
     * единственный признак — флаг validity.badInput, который поле выставляет
     * при вводе.
     *
     * На вход карта «путь => тип поля» (или пустое значение, если ввод снова
     * читается); тип нужен, чтобы сказать человеку, чего именно не хватает.
     */
    function badInputErrors(bad) {
        var messages = {
            number:   t('STRUCTURA_JS_BADINPUT_NUMBER'),
            date:     t('STRUCTURA_JS_BADINPUT_DATE'),
            datetime: t('STRUCTURA_JS_BADINPUT_DATETIME'),
            time:     t('STRUCTURA_JS_BADINPUT_TIME')
        };
        var errors = {};

        Object.keys(bad || {}).forEach(function (path) {
            var type = bad[path];
            if (!type) { return; }
            addErr(errors, path, messages[type] || t('STRUCTURA_JS_BADINPUT_DEFAULT'));
        });

        return errors;
    }

    /**
     * Тот же признак, но снятый с самих полей прямо перед сохранением.
     *
     * Отметок по событию `input` не хватает: у полей даты и времени браузер шлёт
     * `input` не на каждое изменение — дату без времени в
     * <input type="datetime-local"> можно набрать так, что события не будет
     * вовсе, и блок сохранился бы с пустой датой.
     *
     * Поэтому источник правды — DOM: перебираем поля карточки и спрашиваем
     * validity.badInput у каждого. События оставлены: они гасят подсветку сразу
     * после исправления, не дожидаясь следующего сохранения.
     *
     * Путь и тип поля лежат в data-атрибутах — это единственная связь между
     * элементом ввода и адресом значения в store.
     *
     * @return {Object} { <путь>: <тип поля> }
     */
    function scanBadInputs(instanceId) {
        var found = {};
        if (typeof document === 'undefined') { return found; }

        // Идентификатор попадает в селектор, поэтому экранируем: кавычка внутри
        // него роняет querySelector исключением, и сохранение перестаёт работать
        // у всей карточки. Сервер такие id больше не принимает (см.
        // ValueService::instanceIdError), но со старых установок они могли
        // остаться, а падать на чужих данных — не дело.
        // typeof, а не window.CSS: в тестах эта функция исполняется отдельным
        // куском без window, и обращение к нему уронило бы её ReferenceError'ом.
        var quoted = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
            ? CSS.escape(instanceId)
            : String(instanceId).replace(/["\\]/g, '\\$&');

        var card = document.querySelector('[data-st-instance="' + quoted + '"]');
        if (!card) { return found; }

        var inputs = card.querySelectorAll('input[data-st-path]');
        Array.prototype.forEach.call(inputs, function (el) {
            if (el.validity && el.validity.badInput) {
                found[el.getAttribute('data-st-path')] = el.getAttribute('data-st-type') || '';
            }
        });

        return found;
    }

    /**
     * Разбирает ошибки ответа сервера на «подсветить поле» и «сказать вслух».
     *
     * Ошибка поля отличается от общей по customData: рекурсивный Validator кладёт
     * туда {field, rule}, а всё остальное (отказ по правам, ошибка SQL, упавший
     * PHP) приходит с customData: null. Без этого признака общая ошибка не
     * совпала бы ни с одним путём поля и не показалась бы вовсе.
     *
     * @param {string} [title] чем открывается сообщение («Ошибка сохранения»,
     *                         «Ошибка отката») — операция известна вызывающему.
     * @return {{byPath: Object, text: string}}
     */
    function splitServerErrors(list, title) {
        var byPath   = {};
        var messages = [];

        (list || []).forEach(function (e) {
            if (e.customData && e.customData.rule) {
                addErr(byPath, e.code, e.message);
            } else if (e.message) {
                messages.push(e.message);
            }
        });

        var text = title || t('STRUCTURA_JS_SAVE_ERROR');
        if (messages.length) {
            text += ': ' + messages.join('; ');
        } else if (Object.keys(byPath).length) {
            // Поля уже подсвечены — повторять их текстом незачем.
            text = t('STRUCTURA_JS_FIX_FIELD_ERRORS');
        }

        return { byPath: byPath, text: text };
    }

    /* ------------------------------------------------------------------ *
     *  Реестр компонентов полей
     * ------------------------------------------------------------------ */
    var registry = {};
    function registerField(type, component) { registry[type] = component; }
    function resolveField(type) { return registry[type] || registry._fallback; }

    // Базовый миксин листовых полей: доступ к значению и ошибкам через store.
    var fieldMixin = {
        props: {
            field: { type: Object, required: true },
            instance: { type: String, required: true },
            path: { type: String, required: true }
        },
        computed: {
            value: function () { return this.$store.getters.valueAt(this.instance, this.path); },
            errors: function () { return this.$store.getters.errorsAt(this.instance, this.path); },
            invalid: function () { return this.errors.length > 0; },
            settings: function () { return this.field.settings || {}; }
        },
        methods: {
            commitValue: function (v) {
                this.$store.commit('SET_VALUE', { instance: this.instance, path: this.path, value: v });
            }
        }
    };

    // Контекст перетаскивания на всё приложение. У блоков источник (dragstart) и
    // цель (drop) — разные компоненты (каждая карточка своя), поэтому индекс
    // источника и «какой это список» держим в замыкании, а не в Vuex: это
    // транзиентный жест, а не состояние. kind+key вместе отвечают «тот же ли
    // список»: без key строку одного Repeater можно было бы уронить в соседний.
    var dragCtx = { kind: null, key: null, from: -1 };

    // Краевой автоскролл при перетаскивании: у списка блоков карточек больше
    // экрана, и без этого нужную позицию за краем видимой области не достать
    // (нативного автоскролла HTML5 DnD в админке недостаточно). Скорость —
    // чистая функция от координаты курсора, чтобы её можно было проверить.
    var autoScroll = { y: 0, raf: 0, on: false };

    function edgeScrollSpeed(y, height, margin) {
        if (y < margin)          { return -Math.ceil((margin - y) / 3); }
        if (y > height - margin) { return  Math.ceil((y - (height - margin)) / 3); }
        return 0;   // вне краевой зоны — не скроллим
    }

    function autoScrollTrack(e) { autoScroll.y = e.clientY; }

    function autoScrollStart() {
        if (autoScroll.on || typeof window === 'undefined') { return; }
        autoScroll.on = true;
        document.addEventListener('dragover', autoScrollTrack);
        var step = function () {
            if (!autoScroll.on) { return; }
            // Курсор мог замереть у края (dragover не приходит) — координату
            // помним и продолжаем скроллить, пока держат у края.
            var speed = edgeScrollSpeed(autoScroll.y, window.innerHeight, 60);
            if (speed) { window.scrollBy(0, speed); }
            autoScroll.raf = window.requestAnimationFrame(step);
        };
        autoScroll.raf = window.requestAnimationFrame(step);
    }

    function autoScrollStop() {
        if (!autoScroll.on) { return; }
        autoScroll.on = false;
        if (autoScroll.raf) { window.cancelAnimationFrame(autoScroll.raf); autoScroll.raf = 0; }
        document.removeEventListener('dragover', autoScrollTrack);
    }

    // Миксин перестановки перетаскиванием (нативный HTML5 DnD, без библиотек).
    // Кнопки ↑/↓ остаются рядом: клавиатурный и точный путь. Саму перестановку
    // компонент отдаёт в dragMoved — там уже готовая логика (MOVE_ROW /
    // moveInstance), умеющая произвольный сдвиг from→to.
    var dragReorder = {
        data: function () { return { dragOverIdx: -1 }; },
        methods: {
            // Переопределяет компонент: тип списка, его ключ и сама перестановка.
            dragKind:  function () { return 'row'; },
            dragKey:   function () { return ''; },
            dragMoved: function (from, to) {},

            // Тот же ли это список, что начал перетаскивание (иначе не наша цель).
            dragSame: function () {
                return dragCtx.kind === this.dragKind() && dragCtx.key === this.dragKey();
            },
            dragStart: function (e, i) {
                dragCtx.kind = this.dragKind();
                dragCtx.key  = this.dragKey();
                dragCtx.from = i;
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    // Firefox не начинает перетаскивание без установленных данных.
                    try { e.dataTransfer.setData('text/plain', String(i)); } catch (err) {}
                }
                autoScrollStart();
            },
            dragOver: function (e, i) {
                if (!this.dragSame()) { return; }   // чужой список — не мешаем
                e.preventDefault();                 // без этого drop не сработает
                if (e.dataTransfer) { e.dataTransfer.dropEffect = 'move'; }
                this.dragOverIdx = i;
            },
            dragLeaveList: function () { this.dragOverIdx = -1; },
            dropAt: function (e, i) {
                if (!this.dragSame()) { return; }
                e.preventDefault();
                var from = dragCtx.from;
                this.dragEnd();
                if (from < 0 || from === i) { return; }   // никуда не двигали
                this.dragMoved(from, i);
            },
            dragEnd: function () {
                dragCtx.kind = null; dragCtx.key = null; dragCtx.from = -1;
                this.dragOverIdx = -1;
                autoScrollStop();
            },
            // Подсветка: перетаскиваемая строка и текущая цель.
            isDragged:    function (i) { return this.dragSame() && dragCtx.from === i; },
            isDropTarget: function (i) { return this.dragOverIdx === i; }
        }
    };

    /**
     * Подпись поля с необязательной подсказкой разработчика (field.help).
     * Отдельный компонент, чтобы «?» появился разом у всех типов полей, включая
     * зарегистрированные сторонними разработчиками через registerField().
     */
    Vue.component('structura-label', {
        props: { field: { type: Object, required: true } },
        data: function () { return { open: false }; },
        computed: {
            help: function () { return this.field.help || null; }
        },
        template:
            // Слот — для приписок к названию (напр. тип поля у fallback-рендера).
            '<div class="st-label-row"><label class="st-label">{{ field.name }}<slot></slot></label>' +
            '  <button v-if="help" type="button" class="st-help-btn"' +
            '    :class="{\'st-help-btn--on\': open}" :aria-expanded="String(open)"' +
            '    :title="open ? t(\'STRUCTURA_JS_HINT_HIDE\') : t(\'STRUCTURA_JS_HINT_SHOW\')"' +
            '    @click="open = !open">?</button>' +
            '  <div class="st-help" v-if="help && open">{{ help }}</div>' +
            '</div>'
    });

    // Фабрика простого <input>-поля.
    function inputField(inputType) {
        return {
            mixins: [fieldMixin],
            computed: {
                inputType: function () { return inputType; }
            },
            methods: {
                /**
                 * Нечитаемый ввод приходит пустой строкой: «12abc» в type=number
                 * и неполная дата дают value === ''. Отличить это от «поле не
                 * заполнено» можно только по validity.badInput, поэтому флаг
                 * ставится на каждый ввод и учитывается при сохранении (см.
                 * badInputErrors).
                 */
                onInput: function (event) {
                    var el  = event.target;
                    var bad = !!(el.validity && el.validity.badInput);

                    this.$store.commit('SET_BAD_INPUT', {
                        instance: this.instance,
                        path: this.path,
                        type: bad ? this.field.type : null
                    });

                    this.commitValue(el.value);
                }
            },
            template:
                '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
                '  <structura-label :field="field"></structura-label>' +
                // data-атрибуты — адрес поля для сметания нечитаемого ввода
                // перед сохранением (см. scanBadInputs).
                '  <input class="st-input" :type="inputType" :value="value"' +
                '         :data-st-path="path" :data-st-type="field.type"' +
                '         :placeholder="settings.placeholder || \'\'"' +
                '         @input="onInput">' +
                '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
                '</div>'
        };
    }

    registerField('text', inputField('text'));
    registerField('email', inputField('email'));
    registerField('password', inputField('password'));
    registerField('number', inputField('number'));
    registerField('date', inputField('date'));
    registerField('datetime', inputField('datetime-local'));
    registerField('time', inputField('time'));
    registerField('hidden', inputField('hidden'));

    registerField('color', {
        mixins: [fieldMixin],
        computed: {
            // Палитра из конфига (->palette([...])). До этого настройка
            // доезжала до фронтенда и нигде не использовалась.
            palette: function () {
                return Array.isArray(this.settings.palette) ? this.settings.palette : [];
            }
        },
        methods: {
            isPicked: function (color) {
                return String(this.value || '').toLowerCase() === String(color).toLowerCase();
            }
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <span class="st-color">' +
            '    <input type="color" :value="value || \'#000000\'" @input="commitValue($event.target.value)">' +
            '    <input class="st-input st-color-text" type="text" :value="value" @input="commitValue($event.target.value)">' +
            '  </span>' +
            // Палитра — подсказка, а не ограничение: своё значение по-прежнему
            // вводится руками (сервер список тоже ничем не ограничивает).
            '  <div class="st-palette" v-if="palette.length">' +
            '    <button type="button" class="st-swatch" v-for="c in palette" :key="c"' +
            '      :class="{\'st-swatch--on\': isPicked(c)}" :style="{background: c}"' +
            '      :title="c" @click="commitValue(c)"></button>' +
            '  </div>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    var textareaField = {
        mixins: [fieldMixin],
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <textarea class="st-input st-textarea" :rows="settings.rows || 4" :value="value"' +
            '            @input="commitValue($event.target.value)"></textarea>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    };
    registerField('textarea', textareaField);

    function optionEntries(settings) {
        var opts = settings.options || {};
        return Object.keys(opts).map(function (k) { return { value: k, label: opts[k] }; });
    }

    // Список в двух режимах (см. Fields\Select): обычный — один выбор, значение
    // строка; ->multiple() — нативный <select multiple>, значение массив
    // отмеченных ключей (тот же тип значения, что у группы Checkbox).
    registerField('select', {
        mixins: [fieldMixin],
        computed: {
            entries:  function () { return optionEntries(this.settings); },
            multiple: function () { return !!this.settings.multiple; },
            selected: function () { return Array.isArray(this.value) ? this.value : []; },
            // v-model для множественного списка. Именно v-model, а не :selected по
            // опциям: Vue 2 надёжно отражает выбор в <select multiple> только через
            // него (ставит DOM-свойство .selected, а не атрибут), поэтому умолчание
            // и сохранённое значение подсвечиваются на первом же рендере. Бонус —
            // нестрогое сравнение (looseIndexOf): числовой ключ default'а совпадёт
            // со строковым значением <option>. get отдаёт массив, set — коммитит.
            model: {
                get: function () { return this.selected; },
                set: function (v) { this.commitValue(Array.isArray(v) ? v : []); }
            }
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <select class="st-input" multiple v-if="multiple" v-model="model">' +
            '    <option v-for="o in entries" :key="o.value" :value="o.value">{{ o.label }}</option>' +
            '  </select>' +
            '  <select class="st-input" v-else :value="value" @change="commitValue($event.target.value)">' +
            '    <option value="">—</option>' +
            '    <option v-for="o in entries" :key="o.value" :value="o.value">{{ o.label }}</option>' +
            '  </select>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    registerField('radio', {
        mixins: [fieldMixin],
        computed: { entries: function () { return optionEntries(this.settings); } },
        methods: {
            // Ключи вариантов приходят из Object.keys — всегда строки, а
            // умолчание (->default(1)) может приехать числом. Сравниваем по
            // строке: иначе выбранный по умолчанию вариант не подсвечивался бы
            // (нативный <select> у Select приводит значение к строке сам,
            // поэтому там умолчание «работало», а у radio — нет).
            isPicked: function (optionValue) { return String(this.value) === String(optionValue); }
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <label class="st-radio" v-for="o in entries" :key="o.value">' +
            '    <input type="radio" :value="o.value" :checked="isPicked(o.value)" @change="commitValue(o.value)"> {{ o.label }}' +
            '  </label>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    // Флажок в двух режимах (см. Fields\Checkbox): без ->options() это один
    // переключатель со значением 'Y'/'N', с вариантами — группа флажков,
    // значение которой массив отмеченных ключей.
    registerField('checkbox', {
        mixins: [fieldMixin],
        computed: {
            entries: function () { return optionEntries(this.settings); },
            single: function () { return this.entries.length === 0; },
            // 'Y' — единственное «включено»: значение приходит из JSON строкой.
            on: function () { return isCheckboxOn(this.value); },
            selected: function () { return Array.isArray(this.value) ? this.value : []; }
        },
        methods: {
            toggle: function (val) {
                var cur = this.selected.slice();
                var idx = cur.indexOf(val);
                if (idx === -1) { cur.push(val); } else { cur.splice(idx, 1); }
                this.commitValue(cur);
            },
            // Пишем 'N', а не пустоту: читателю значений на сайте не должно
            // приходиться отличать «выключено» от «поля не касались».
            toggleSingle: function (checked) { this.commitValue(checked ? 'Y' : 'N'); }
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <label class="st-checkbox" v-if="single">' +
            '    <input type="checkbox" :checked="on" @change="toggleSingle($event.target.checked)"> {{ t(\'STRUCTURA_JS_CHECKBOX_YES\') }}' +
            '  </label>' +
            '  <label class="st-checkbox" v-for="o in entries" :key="o.value" v-else>' +
            '    <input type="checkbox" :checked="selected.indexOf(o.value)!==-1" @change="toggle(o.value)"> {{ o.label }}' +
            '  </label>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    // Поля-ссылки на сущности Bitrix (image/file/gallery/user/iblock*/highload*):
    // на этом этапе — ввод ID; полноценные пикеры — следующий шаг.
    /* ------------------------------------------------------------------ *
     *  Справочники ссылочных полей
     * ------------------------------------------------------------------ */

    // Кэш ответов: справочник (список инфоблоков и т.п.) одинаков для всех полей
    // на странице, поэтому грузим его один раз, а не на каждое поле.
    var refCache = {};

    /**
     * Загружает справочник ajax-действием с кэшированием по ключу.
     * @return {Promise<Array>} пустой массив, если действие недоступно.
     */
    function loadRef(action, params, key) {
        if (!action || !BX.ajax || !BX.ajax.runAction) { return Promise.resolve([]); }
        if (refCache[key]) { return refCache[key]; }

        refCache[key] = BX.ajax.runAction(action, { data: params || {} }).then(
            function (r) { return (r && r.data) ? r.data : {}; },
            function () { delete refCache[key]; return {}; }   // ошибку не кэшируем
        );

        return refCache[key];
    }

    /**
     * Кладёт в кэш готовый ответ — когда данные уже на руках и спрашивать
     * сервер незачем (или бесполезно: он ответит «нет такого»).
     */
    function primeRef(key, data) {
        refCache[key] = Promise.resolve(data || {});
    }

    /**
     * Поле выбора инфоблока — выпадающий список, как в стандартных формах
     * Битрикса. Значение — ID инфоблока.
     */
    registerField('iblock', {
        mixins: [fieldMixin],
        data: function () { return { items: null }; },   // null — ещё грузится
        computed: {
            // Группировка по типу инфоблока, как в админке Битрикса.
            groups: function () {
                var out = [];
                var byType = {};
                (this.items || []).forEach(function (i) {
                    if (!byType[i.type]) { byType[i.type] = { type: i.type, items: [] }; out.push(byType[i.type]); }
                    byType[i.type].items.push(i);
                });
                return out;
            }
        },
        mounted: function () {
            var self = this;
            var type = this.settings.iblockType || '';
            loadRef(this.$store.state.endpoints.iblocks, { iblockType: type }, 'iblocks:' + type)
                .then(function (d) { self.items = d.iblocks || []; });
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <div class="st-hint" v-if="items === null">{{ t(\'STRUCTURA_JS_LOADING_IBLOCKS\') }}</div>' +
            '  <div class="st-hint" v-else-if="!items.length">{{ t(\'STRUCTURA_JS_IBLOCKS_EMPTY\') }}</div>' +
            '  <select class="st-input" v-else :value="value"' +
            '          @change="commitValue($event.target.value)">' +
            '    <option value="">—</option>' +
            '    <optgroup v-for="g in groups" :key="g.type" :label="g.type">' +
            '      <option v-for="i in g.items" :key="i.id" :value="i.id">' +
            '        {{ i.name }} [{{ i.id }}]</option>' +
            '    </optgroup>' +
            '  </select>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    /**
     * Поле выбора Highload-блока — выпадающий список. Значение — ID HL-блока.
     * Группировать не по чему (у HL-блоков нет типов), поэтому список плоский:
     * подпись — как в списке HL-блоков админки, локализованное название с
     * именем сущности рядом.
     */
    registerField('highload', {
        mixins: [fieldMixin],
        data: function () { return { items: null }; },   // null — ещё грузится
        mounted: function () {
            var self = this;
            loadRef(this.$store.state.endpoints.highloads, {}, 'highloads')
                .then(function (d) { self.items = d.highloads || []; });
        },
        methods: {
            // Локализованное имя есть не у каждого HL-блока — тогда имя сущности.
            label: function (i) {
                return i.title ? i.title + ' (' + i.name + ')' : i.name;
            }
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <div class="st-hint" v-if="items === null">{{ t(\'STRUCTURA_JS_LOADING_HLBLOCKS\') }}</div>' +
            '  <div class="st-hint" v-else-if="!items.length">{{ t(\'STRUCTURA_JS_HLBLOCKS_EMPTY\') }}</div>' +
            '  <select class="st-input" v-else :value="value"' +
            '          @change="commitValue($event.target.value)">' +
            '    <option value="">—</option>' +
            '    <option v-for="i in items" :key="i.id" :value="i.id">' +
            '      {{ label(i) }} [{{ i.id }}]</option>' +
            '  </select>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    /**
     * Поле выбора раздела инфоблока. Значение — ID раздела.
     *
     * Инфоблок берётся из ->iblock() (ID или CODE), а без него — из инфоблока
     * самого редактируемого элемента: раздел «этого же инфоблока» — то, чего
     * ждёшь по умолчанию на форме элемента.
     *
     * Дерево показываем плоским списком с отступом по глубине — так же, как
     * стандартные списки разделов Битрикса.
     */
    /**
     * Справочник с поиском и подгрузкой по страницам.
     *
     * sections и hlrows могут быть большими, а в обычном <select> без поиска
     * нужное всё равно не выбрать. Общий компонент для обоих полей: разница
     * только в том, чем грузить —
     * `load(search, page)` и `resolve(id)` приходят от поля.
     *
     * `resolve` нужен из-за пагинации: сохранённое значение может не попасть в
     * текущую страницу выдачи, и показывать голый ID вместо названия — плохо.
     */
    Vue.component('structura-lookup', {
        props: {
            value: { default: '' },
            load: { type: Function, required: true },     // (search, page) => Promise<{items, hasMore}>
            resolve: { type: Function, required: true },  // (id) => Promise<label|null>
            placeholder: { type: String, default: t('STRUCTURA_JS_SEARCH') },
            emptyText: { type: String, default: t('STRUCTURA_JS_NOTHING_FOUND') }
        },
        data: function () {
            return {
                open: false, search: '', items: [], page: 1, hasMore: false,
                loading: false, label: null, timer: null,
                // Для какого значения уже известна подпись. Без этого выбор из
                // списка выбрасывал бы только что показанное название и шёл за
                // ним на сервер повторно — с миганием «…» в поле.
                labelFor: null
            };
        },
        computed: {
            hasValue: function () { return this.value !== '' && this.value !== null && this.value !== undefined; }
        },
        watch: {
            value: { immediate: true, handler: function () { this.refreshLabel(); } }
        },
        beforeDestroy: function () { if (this.timer) { clearTimeout(this.timer); } },
        methods: {
            refreshLabel: function () {
                var self = this;
                if (!this.hasValue) { this.label = null; this.labelFor = null; return; }
                // Подпись уже знаем (только что выбрали из списка) — не мигаем.
                if (this.labelFor !== null && String(this.value) === this.labelFor) { return; }

                var want = String(this.value);
                this.label = '…';
                this.resolve(this.value).then(function (l) {
                    // Значение могло смениться, пока ходили на сервер.
                    if (String(self.value) !== want) { return; }
                    self.label = l || t('STRUCTURA_JS_REF_NOT_FOUND', { '#REF#': '#' + want });
                    self.labelFor = want;
                });
            },
            toggle: function () {
                this.open = !this.open;
                if (this.open && !this.items.length) { this.fetch(1); }
            },
            onSearch: function () {
                var self = this;
                // Debounce: без него каждый символ — отдельный запрос, а они
                // ещё и сериализуются блокировкой сессии.
                if (this.timer) { clearTimeout(this.timer); }
                this.timer = setTimeout(function () { self.fetch(1); }, 300);
            },
            fetch: function (page) {
                var self = this;
                var q = this.search;
                this.loading = true;
                this.load(q, page).then(function (d) {
                    // Ответ на устаревший запрос игнорируем: пока он летел,
                    // человек мог дописать ещё букв.
                    if (q !== self.search) { return; }
                    var got = (d && d.items) || [];
                    self.items = page === 1 ? got : self.items.concat(got);
                    self.hasMore = !!(d && d.hasMore);
                    self.page = page;
                    self.loading = false;
                }, function () { self.loading = false; });
            },
            more: function () { this.fetch(this.page + 1); },
            pick: function (item) {
                // Сначала подпись, потом emit: watch на value сработает уже
                // зная labelFor и не пойдёт за названием, которое у нас есть.
                this.label = item.label;
                this.labelFor = String(item.id);
                this.$emit('input', String(item.id));
                this.open = false;
            },
            clear: function () {
                this.labelFor = null;
                this.$emit('input', '');
                this.open = false;
            }
        },
        template:
            '<div class="st-lookup">' +
            '  <div class="st-ref">' +
            // Подпись показывается как есть: id в неё добавляет тот, кто грузит
            // (у hlrows его дописывает сервер). Дописывать здесь ещё раз —
            // получить «Москва [1] [1]».
            '    <span class="st-lookup-value" v-if="hasValue">{{ label }}</span>' +
            '    <span class="st-lookup-value st-lookup-value--empty" v-else>{{ t(\'STRUCTURA_JS_NOT_SELECTED\') }}</span>' +
            '    <button type="button" class="st-ref-btn" @click="toggle">' +
            '      {{ open ? t(\'STRUCTURA_JS_COLLAPSE\') : t(\'STRUCTURA_JS_CHOOSE\') }}</button>' +
            '    <button type="button" class="st-ref-btn st-ref-btn--clear" v-if="hasValue"' +
            '            :title="t(\'STRUCTURA_JS_CLEAR\')" @click="clear">×</button>' +
            '  </div>' +
            '  <div class="st-lookup-panel" v-if="open">' +
            '    <input type="text" class="st-input st-lookup-search" v-model="search"' +
            '           :placeholder="placeholder" @input="onSearch">' +
            '    <div class="st-hint" v-if="loading && !items.length">{{ t(\'STRUCTURA_JS_LOADING\') }}</div>' +
            '    <div class="st-hint" v-else-if="!items.length">{{ emptyText }}</div>' +
            '    <ul class="st-lookup-list" v-else>' +
            '      <li v-for="i in items" :key="i.id">' +
            '        <button type="button" class="st-lookup-item"' +
            '                :class="{\'st-lookup-item--on\': String(i.id) === String(value)}"' +
            '                @click="pick(i)">' +
            '          <span :style="{paddingLeft: (i.depth || 0) * 14 + \'px\'}">{{ i.label }}</span>' +
            '        </button></li>' +
            '    </ul>' +
            '    <button type="button" class="st-lookup-more" v-if="hasMore"' +
            '            :disabled="loading" @click="more">' +
            '      {{ loading ? t(\'STRUCTURA_JS_LOADING\') : t(\'STRUCTURA_JS_SHOW_MORE\') }}</button>' +
            '  </div>' +
            '</div>'
    });

    registerField('iblockSection', {
        mixins: [fieldMixin],
        computed: {
            iblock: function () {
                var own = this.settings.iblock;
                return (own === undefined || own === null || own === '')
                    ? (this.$store.state.entity.iblock || '')
                    : own;
            }
        },
        methods: {
            loadPage: function (search, page) {
                var self = this;
                // Кэша тут нет намеренно: выдача зависит от строки поиска и
                // номера страницы — кэшировать каждый запрос значит копить мусор.
                return BX.ajax.runAction(this.$store.state.endpoints.sections, {
                    data: { iblock: self.iblock, search: search || '', page: page }
                }).then(function (r) {
                    var d = (r && r.data) || {};
                    return {
                        // ID в подписи — как у hlrows, только там его дописывает
                        // сервер. Он нужен: названия разделов повторяются.
                        items: (d.sections || []).map(function (s) {
                            return { id: s.id, label: s.name + ' [' + s.id + ']', depth: s.depth };
                        }),
                        hasMore: !!d.hasMore
                    };
                });
            },
            resolveLabel: function (id) {
                return BX.ajax.runAction(this.$store.state.endpoints.section, { data: { id: id } })
                    .then(function (r) {
                        var s = r && r.data && r.data.section;
                        return s ? (s.name + ' [' + s.id + ']') : null;
                    });
            }
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <div class="st-hint" v-if="iblock === \'\'">{{ t(\'STRUCTURA_JS_IBLOCK_NOT_SET\') }}</div>' +
            '  <structura-lookup v-else :value="value" :load="loadPage" :resolve="resolveLabel"' +
            '    :placeholder="t(\'STRUCTURA_JS_SEARCH_SECTION\')" :empty-text="t(\'STRUCTURA_JS_SECTIONS_EMPTY\')"' +
            '    @input="commitValue($event)"></structura-lookup>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    // Счётчик для уникальных имён колбэков попапа (см. поле user).
    var popupSeq = 0;

    /**
     * Поле выбора пользователя. Значение — ID пользователя.
     *
     * Выбор — стандартным попапом Битрикса (user_search.php). Из двух его
     * режимов берём JSFUNC: он зовёт window.opener.SUV<имя>(id), тогда как
     * второй режим (FN/FC) пишет в document.<имя формы>[<имя поля>] — то есть
     * требует именованную форму, а поле живёт и на форме элемента, и на форме
     * раздела, и на стенде без формы вообще.
     *
     * Расплата за JSFUNC: попап в этом режиме сам не закрывается (window.close
     * есть только в ветке FN/FC), поэтому открываем через window.open и держим
     * ссылку, чтобы закрыть после выбора. Размеры — как у CMain::FindUserID.
     *
     * Имя показываем рядом с ID: попап отдаёт только ID, а «кто это» — вопрос
     * к серверу (действие structura:structura.block.user).
     */
    registerField('user', {
        mixins: [fieldMixin],
        data: function () {
            return {
                info: null,        // { id, name, login } — кто выбран
                loading: false,
                popup: null        // окно попапа, чтобы закрыть после выбора
            };
        },
        computed: {
            // Пользователь мог быть удалён — тогда сервер вернёт null, и
            // честнее показать это, чем молчать.
            missing: function () {
                return !!this.value && !this.loading && this.info === null;
            }
        },
        watch: { value: function () { this.fetchName(); } },
        mounted: function () {
            var self = this;
            // Имя колбэка чистится попапом по /[^a-z0-9_\[\]:]/i, поэтому
            // только буквы, цифры и подчёркивание.
            this.callback = 'structura_' + (++popupSeq);
            window['SUV' + this.callback] = function (id) {
                self.commitValue(String(id));
                if (self.popup && !self.popup.closed) { self.popup.close(); }
                self.popup = null;
            };
            this.fetchName();
        },
        beforeDestroy: function () {
            // Иначе колбэки копятся на window при каждом удалении блока.
            delete window['SUV' + this.callback];
            if (this.popup && !this.popup.closed) { this.popup.close(); }
        },
        methods: {
            fetchName: function () {
                var self = this;
                var id = parseInt(this.value, 10);
                if (!id || id <= 0) { this.info = null; this.loading = false; return; }

                this.loading = true;
                loadRef(this.$store.state.endpoints.user, { id: id }, 'user:' + id)
                    .then(function (d) {
                        // Значение могло смениться, пока ответ летел.
                        if (parseInt(self.value, 10) !== id) { return; }
                        self.info = (d && d.user) ? d.user : null;
                        self.loading = false;
                    });
            },
            openPicker: function () {
                var url = this.$store.state.endpoints.userSearchUrl;
                if (!url) { return; }
                url += (url.indexOf('?') === -1 ? '?' : '&') + 'JSFUNC=' + this.callback;

                var w = 760, h = 500;
                this.popup = window.open(url, '', 'scrollbars=yes,resizable=yes,width=' + w +
                    ',height=' + h + ',top=' + Math.floor((screen.height - h) / 2 - 14) +
                    ',left=' + Math.floor((screen.width - w) / 2 - 5));
                if (this.popup) { this.popup.focus(); }
            },
            clear: function () { this.commitValue(''); }
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <div class="st-ref">' +
            '    <input class="st-input st-ref-id" type="text" :value="value" placeholder="ID"' +
            '           @input="commitValue($event.target.value)">' +
            '    <button class="st-ref-btn" type="button" :title="t(\'STRUCTURA_JS_PICK_USER\')"' +
            '            @click="openPicker">…</button>' +
            '    <button class="st-ref-btn st-ref-btn--clear" type="button" v-if="value"' +
            '            :title="t(\'STRUCTURA_JS_CLEAR\')" @click="clear">×</button>' +
            '  </div>' +
            '  <div class="st-hint" v-if="loading">{{ t(\'STRUCTURA_JS_LOADING\') }}</div>' +
            '  <div class="st-hint" v-else-if="info">{{ info.name }} ({{ info.login }})</div>' +
            '  <div class="st-hint st-ref-missing" v-else-if="missing">{{ t(\'STRUCTURA_JS_USER\') }} #{{ value }} {{ t(\'STRUCTURA_JS_NOT_FOUND\') }}</div>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    /**
     * Поле выбора элемента инфоблока. Значение — ID элемента.
     *
     * Попап тут другой, чем у user (у него свой контракт): режим `n` пишет ID в
     * document.getElementById(n), кидает 'change' и закрывается сам. По id, а не
     * по имени формы, — поэтому именованная форма не нужна и приём годится, в
     * отличие от FN/FC у user_search. Раз попап закрывается сам, хэндл не нужен
     * и открываем стандартным jsUtils.OpenWindow (900×700 — как в admin_tools).
     *
     * ->iblock() → IBLOCK_ID + iblockfix=y: попап прячет выбор инфоблока и
     * запирает поиск в нужном. Без ->iblock() инфоблок не подставляем (в
     * отличие от iblockSection): у попапа есть свой выбор инфоблока, а ссылка
     * на элемент чужого инфоблока — обычное дело.
     */
    registerField('iblockElement', {
        mixins: [fieldMixin],
        data: function () {
            return {
                // id нужен уже при первом рендере (попап ищет инпут по нему),
                // поэтому здесь, а не в mounted: тот отработает после рендера.
                // Попап чистит `n` по /[^a-zA-Z0-9_:\[\]]/.
                domId: 'structura_el_' + (++popupSeq),
                info: null,          // { id, name, iblockId, iblockName }
                loading: false,
                iblockId: null,      // резолв ->iblock() (CODE → ID)
                iblockReady: false   // резолв закончен (или не нужен)
            };
        },
        computed: {
            missing: function () {
                return !!this.value && !this.loading && this.info === null;
            }
        },
        watch: { value: function () { this.fetchName(); } },
        mounted: function () {
            var self = this;
            this.fetchName();

            var iblock = this.settings.iblock;
            if (iblock === undefined || iblock === null || iblock === '') {
                this.iblockReady = true;    // не задан — попап покажет свой список
                return;
            }
            // ID резолвим заранее: открыть попап можно только в обработчике
            // клика (иначе браузер сочтёт всплывашкой), ждать ответ там поздно.
            loadRef(this.$store.state.endpoints.iblock, { iblock: iblock }, 'iblockid:' + iblock)
                .then(function (d) {
                    self.iblockId = (d && d.id) ? d.id : null;
                    self.iblockReady = true;
                });
        },
        methods: {
            fetchName: function () {
                var self = this;
                var id = parseInt(this.value, 10);
                if (!id || id <= 0) { this.info = null; this.loading = false; return; }

                this.loading = true;
                loadRef(this.$store.state.endpoints.element, { id: id }, 'element:' + id)
                    .then(function (d) {
                        if (parseInt(self.value, 10) !== id) { return; }   // значение уже сменилось
                        self.info = (d && d.element) ? d.element : null;
                        self.loading = false;
                    });
            },
            openPicker: function () {
                var url = this.$store.state.endpoints.elementSearchUrl;
                if (!url || !this.iblockReady) { return; }

                url += '&n=' + this.domId;
                if (this.iblockId) { url += '&IBLOCK_ID=' + this.iblockId + '&iblockfix=y'; }

                if (window.jsUtils && window.jsUtils.OpenWindow) {
                    window.jsUtils.OpenWindow(url, 900, 700);
                } else {
                    window.open(url, '', 'scrollbars=yes,resizable=yes,width=900,height=700');
                }
            },
            clear: function () { this.commitValue(''); }
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <div class="st-ref">' +
            '    <input class="st-input st-ref-id" type="text" :id="domId" :value="value"' +
            '           placeholder="ID" @input="commitValue($event.target.value)"' +
            '           @change="commitValue($event.target.value)">' +
            '    <button class="st-ref-btn" type="button" :title="t(\'STRUCTURA_JS_PICK_ELEMENT\')"' +
            '            :disabled="!iblockReady" @click="openPicker">…</button>' +
            '    <button class="st-ref-btn st-ref-btn--clear" type="button" v-if="value"' +
            '            :title="t(\'STRUCTURA_JS_CLEAR\')" @click="clear">×</button>' +
            '  </div>' +
            '  <div class="st-hint" v-if="loading">{{ t(\'STRUCTURA_JS_LOADING\') }}</div>' +
            '  <div class="st-hint" v-else-if="info">{{ info.name }}' +
            '    <template v-if="info.iblockName"> — {{ info.iblockName }}</template>' +
            '  </div>' +
            '  <div class="st-hint st-ref-missing" v-else-if="missing">{{ t(\'STRUCTURA_JS_ELEMENT\') }} #{{ value }} {{ t(\'STRUCTURA_JS_NOT_FOUND\') }}</div>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    /**
     * Поле выбора строки Highload-блока. Значение — ID строки.
     *
     * Попапа для строк HL-блока в ядре нет, поэтому выпадающий список, как у
     * highload. HL-блок задаётся ->entity() (ID, имя сущности или таблицы).
     *
     * Поле подписи выбирает сервер: явный ->titleField(), иначе конвенция
     * ядра UF_NAME → UF_XML_ID → ID. Клиент лишь передаёт настройку — есть ли у
     * блока UF_NAME, знает только сервер, поэтому подсказки «подписи будут по
     * ID» здесь больше нет: чаще всего это было бы неправдой.
     */
    registerField('highloadElement', {
        mixins: [fieldMixin],
        computed: {
            hlblock: function () {
                var v = this.settings.hlblock;
                return (v === undefined || v === null) ? '' : v;
            },
            title: function () { return this.settings.titleField || ''; }
        },
        methods: {
            loadPage: function (search, page) {
                var self = this;
                return BX.ajax.runAction(this.$store.state.endpoints.hlrows, {
                    data: {
                        hlblock: self.hlblock, titleField: self.title,
                        search: search || '', page: page
                    }
                }).then(function (r) {
                    var d = (r && r.data) || {};
                    return { items: d.rows || [], hasMore: !!d.hasMore };
                });
            },
            resolveLabel: function (id) {
                var self = this;
                return BX.ajax.runAction(this.$store.state.endpoints.hlrow, {
                    data: { hlblock: self.hlblock, id: id, titleField: self.title }
                }).then(function (r) {
                    var row = r && r.data && r.data.row;
                    return row ? row.label : null;
                });
            }
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <div class="st-hint" v-if="hlblock === \'\'">{{ t(\'STRUCTURA_JS_HLBLOCK_NOT_SET\') }}</div>' +
            '  <structura-lookup v-else :value="value" :load="loadPage" :resolve="resolveLabel"' +
            '    :placeholder="title ? t(\'STRUCTURA_JS_SEARCH_BY_FIELD\') + title + \'…\' : t(\'STRUCTURA_JS_SEARCH\')"' +
            '    :empty-text="t(\'STRUCTURA_JS_RECORDS_EMPTY\')"' +
            '    @input="commitValue($event)"></structura-lookup>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    /**
     * Общая начинка полей с загрузкой файла (image/file). Значение — ID файла.
     *
     * Грузим своим действием: файл сохраняется сразу и навсегда, поэтому в
     * значении оказывается постоянный ID — он и уходит в наш JSON, и переживает
     * ревизии/откат. Админский аплоадер (BXU) так не умеет: он кладёт файл во
     * временное хранилище, а постоянный CFile создаётся только при сохранении
     * формы.
     */
    var uploadFieldMixin = {
        mixins: [fieldMixin],
        data: function () {
            return {
                info: null,        // { id, name, size, src, isImage, ... }
                loading: false,    // грузится имя/превью по ID
                uploading: false,
                uploadError: ''
            };
        },
        computed: {
            missing: function () {
                return !!this.value && !this.loading && !this.uploading && this.info === null;
            }
        },
        watch: { value: function () { this.fetchInfo(); } },
        mounted: function () { this.fetchInfo(); },
        methods: {
            fetchInfo: function () {
                var self = this;
                var id = parseInt(this.value, 10);
                if (!id || id <= 0) { this.info = null; this.loading = false; return; }

                this.loading = true;
                // Сущность нужна серверу для проверки прав: метаданные файла
                // отдаются, только если это файл сущности, которую можно читать
                // (S3). Инфоблок на странице один, поэтому кэш по id файла верен.
                var entity = this.$store.state.entity;
                loadRef(
                    this.$store.state.endpoints.file,
                    { id: id, entityType: entity.type, entityId: entity.id },
                    'file:' + id
                )
                    .then(function (d) {
                        if (parseInt(self.value, 10) !== id) { return; }   // значение уже сменилось
                        self.info = (d && d.file) ? d.file : null;
                        self.loading = false;
                    });
            },
            pick: function () { this.$refs.input.click(); },
            upload: function (e) {
                var self = this;
                var file = e.target.files && e.target.files[0];
                if (!file) { return; }

                var action = this.$store.state.endpoints.upload;
                var entity = this.$store.state.entity;
                if (!action || !BX.ajax || !BX.ajax.runAction) { return; }

                // Только FormData: runAction шлёт multipart, лишь когда data —
                // это FormData (core_ajax: `config.data instanceof FormData` →
                // preparePost = false). Обычный объект он сериализует, File
                // превращается в строку, и до сервера файл не доезжает —
                // upload отвечает «Файл не получен».
                // CSRF при этом на месте: он уходит заголовком
                // X-Bitrix-Csrf-Token, а не полем данных.
                var fd = new FormData();
                fd.append('file', file);
                fd.append('entityType', entity.type);
                fd.append('entityId', entity.id);
                fd.append('mode', this.uploadMode);

                this.uploading = true;
                this.uploadError = '';
                BX.ajax.runAction(action, { data: fd }).then(
                    function (r) {
                        self.uploading = false;
                        // Свежий файл — свежий id, старый ответ кэша не мешает.
                        if (r && r.data && r.data.file) {
                            // Ответ upload обязан попасть в кэш до commitValue:
                            // смена value будит watcher, тот идёт в `file`, а `file`
                            // отдаёт метаданные только для файлов, уже лежащих в
                            // сохранённых значениях сущности (проверка владения).
                            // Значение свежей загрузки живёт пока лишь в форме,
                            // сервер ответил бы «нет такого», и поле писало бы
                            // «Файл #N не найден» до сохранения страницы.
                            primeRef('file:' + r.data.file.id, { file: r.data.file });
                            self.info = r.data.file;
                            self.commitValue(String(r.data.file.id));
                        }
                    },
                    function (r) {
                        self.uploading = false;
                        self.uploadError = (r && r.errors && r.errors[0])
                            ? r.errors[0].message
                            : t('STRUCTURA_JS_FILE_UPLOAD_FAILED');
                    }
                );
                e.target.value = '';   // тот же файл можно выбрать повторно
            },
            clear: function () { this.commitValue(''); },

            /**
             * Удалить файл насовсем — в отличие от «×», который только
             * отвязывает файл от поля, оставляя его на диске.
             *
             * Отдельная кнопка, а не поведение «×»: отвязать и уничтожить —
             * разные намерения, и второе необратимо.
             *
             * Сервер заодно вычистит ссылки на файл из ревизий этого же поля,
             * поэтому в подтверждении сказано и про историю: пользователь должен
             * понимать, что теряет, до нажатия, а не после.
             *
             * Экземпляр и путь поля уходят на сервер обязательно: операция
             * точечная — чистится ровно это поле, и по этому же адресу сервер
             * проверяет, что файл и правда наш.
             */
            destroyFile: function () {
                var self   = this;
                var action = this.$store.state.endpoints.deleteFile;
                var id     = this.value;

                if (!id || !action || !BX.ajax || !BX.ajax.runAction) { return; }

                if (!window.confirm(t('STRUCTURA_JS_FILE_DELETE_CONFIRM'))) { return; }

                var entity = this.$store.state.entity;
                this.uploading   = true;
                this.uploadError = '';

                BX.ajax.runAction(action, {
                    data: {
                        entityType: entity.type,
                        entityId: entity.id,
                        fileId: id,
                        instanceId: this.instance,
                        path: this.path,
                        // Язык выбирает строку, в которой лежит поле: у перевода
                        // свои файлы, и чистить надо ссылку именно его слоя
                        // (и снапшоты того же языка).
                        language: this.$store.getters.langOf(this.instance)
                    }
                }).then(
                    function (r) {
                        self.uploading = false;
                        self.info = null;
                        // Сервер уже вычистил значение в БД; синхронизируем форму,
                        // иначе поле показывало бы ID удалённого файла.
                        self.commitValue('');

                        // Файл мог пережить удаление, и причины две. Промолчать
                        // здесь — соврать: человек нажимал «удалить с диска» и
                        // вправе знать, что файл на месте.
                        var data = (r && r.data) || {};
                        if (data.purged === false) {
            self.uploadError = data.reason === 'shared_value'
                                // Тот же файл лежит в другом значении этого же
                                // элемента: другой язык, соседнее поле, копия
                                // строки. Файлы между ними не размножаются, и
                                // снести байты значит испортить те значения.
                                ? t('STRUCTURA_JS_FILE_KEPT_SHARED_VALUE')
                                // Ссылка убрана, но ядро файл не удалило: ровно
                                // те же байты держит другая запись b_file
                                // (дедупликация по хэшу — так устроены копии
                                // элементов).
                                : t('STRUCTURA_JS_FILE_KEPT_SHARED_BYTES');
                        }
                    },
                    function (r) {
                        self.uploading = false;
                        self.uploadError = (r && r.errors && r.errors[0])
                            ? r.errors[0].message
                            : t('STRUCTURA_JS_FILE_DELETE_FAILED');
                    }
                );
            }
        }
    };

    /** Изображение: загрузка + превью. Значение — ID файла. */
    registerField('image', {
        mixins: [uploadFieldMixin],
        data: function () { return { uploadMode: 'image' }; },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <div class="st-ref">' +
            '    <input class="st-input st-ref-id" type="text" :value="value" placeholder="ID"' +
            '           @input="commitValue($event.target.value)">' +
            '    <button class="st-ref-btn" type="button" :title="t(\'STRUCTURA_JS_UPLOAD_IMAGE\')"' +
            '            :disabled="uploading" @click="pick">' +
            '      {{ uploading ? \'…\' : t(\'STRUCTURA_JS_UPLOAD\') }}</button>' +
            '    <button class="st-ref-btn st-ref-btn--clear" type="button" v-if="value"' +
            '            :title="t(\'STRUCTURA_JS_CLEAR_FILE\')" @click="clear">×</button>' +
            '    <button class="st-ref-btn st-ref-btn--destroy" type="button" v-if="value"' +
            '            :title="t(\'STRUCTURA_JS_DELETE_FILE\')"' +
            '            :disabled="uploading" @click="destroyFile">🗑</button>' +
            '    <input class="st-file-native" type="file" ref="input"' +
            '           :accept="settings.accept || \'image/*\'" @change="upload">' +
            '  </div>' +
            '  <div class="st-hint st-ref-missing" v-if="uploadError">{{ uploadError }}</div>' +
            '  <div class="st-hint" v-else-if="loading || uploading">' +
            '    {{ uploading ? t(\'STRUCTURA_JS_UPLOADING_FILE\') : t(\'STRUCTURA_JS_LOADING\') }}</div>' +
            '  <div class="st-thumb" v-else-if="info && info.isImage">' +
            '    <img :src="info.src" :alt="info.name">' +
            '    <span class="st-hint">{{ info.name }} · {{ info.width }}×{{ info.height }}</span>' +
            '  </div>' +
            '  <div class="st-hint" v-else-if="info">{{ info.name }} {{ t(\'STRUCTURA_JS_NOT_IMAGE\') }}</div>' +
            '  <div class="st-hint st-ref-missing" v-else-if="missing">{{ t(\'STRUCTURA_JS_FILE\') }} #{{ value }} {{ t(\'STRUCTURA_JS_NOT_FOUND\') }}</div>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    /** Произвольный файл: загрузка + имя/размер. Значение — ID файла. */
    registerField('file', {
        mixins: [uploadFieldMixin],
        data: function () { return { uploadMode: 'file' }; },
        methods: {
            size: function (bytes) {
                var units = ['Б', 'КБ', 'МБ', 'ГБ'];
                var n = bytes || 0;
                var i = 0;
                while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
                // Байты целыми, остальное — с одним знаком: «1.5 МБ» полезнее,
                // чем «2 МБ», а «1.0 Б» — бессмыслица.
                return (i === 0 ? n : n.toFixed(1)) + ' ' + units[i];
            }
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <div class="st-ref">' +
            '    <input class="st-input st-ref-id" type="text" :value="value" placeholder="ID"' +
            '           @input="commitValue($event.target.value)">' +
            '    <button class="st-ref-btn" type="button" :title="t(\'STRUCTURA_JS_UPLOAD_FILE_TITLE\')"' +
            '            :disabled="uploading" @click="pick">' +
            '      {{ uploading ? \'…\' : t(\'STRUCTURA_JS_UPLOAD\') }}</button>' +
            '    <button class="st-ref-btn st-ref-btn--clear" type="button" v-if="value"' +
            '            :title="t(\'STRUCTURA_JS_CLEAR_FILE\')" @click="clear">×</button>' +
            '    <button class="st-ref-btn st-ref-btn--destroy" type="button" v-if="value"' +
            '            :title="t(\'STRUCTURA_JS_DELETE_FILE\')"' +
            '            :disabled="uploading" @click="destroyFile">🗑</button>' +
            '    <input class="st-file-native" type="file" ref="input"' +
            '           :accept="settings.accept || \'\'" @change="upload">' +
            '  </div>' +
            '  <div class="st-hint st-ref-missing" v-if="uploadError">{{ uploadError }}</div>' +
            '  <div class="st-hint" v-else-if="loading || uploading">' +
            '    {{ uploading ? t(\'STRUCTURA_JS_UPLOADING_FILE\') : t(\'STRUCTURA_JS_LOADING\') }}</div>' +
            '  <div class="st-hint" v-else-if="info">' +
            '    <a :href="info.src" target="_blank" rel="noopener">{{ info.name }}</a>' +
            '    · {{ size(info.size) }}</div>' +
            '  <div class="st-hint st-ref-missing" v-else-if="missing">{{ t(\'STRUCTURA_JS_FILE\') }} #{{ value }} {{ t(\'STRUCTURA_JS_NOT_FOUND\') }}</div>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    /**
     * Поле выбора файла из файловой структуры сайта (диалог Битрикса).
     *
     * Значение — один относительный путь: в галерее у файлов нет ID, сохранять
     * кроме пути нечего. Нужно несколько файлов — Repeater с Gallery внутри.
     *
     * Диалог на странице один (его печатает сервер, см. showFileDialogScript):
     * на каждое поле его не отрендерить, строки Repeater появляются на клиенте.
     * Поэтому поле, открывшее диалог, запоминается здесь, и общий колбэк
     * возвращает путь именно ему.
     */
    var galleryPicking = null;   // поле, которое сейчас открыло диалог

    registerField('gallery', {
        mixins: [fieldMixin],
        computed: {
            // Нет fileman или прав на файловую структуру — открывашки не будет,
            // и кнопку показывать незачем: путь останется вписать руками.
            hasDialog: function () {
                var name = this.$store.state.endpoints.fileDialog;
                return !!name && typeof window[name] === 'function';
            },
            isImage: function () {
                return /\.(jpe?g|png|gif|webp|bmp|svg|ico)$/i.test(this.value || '');
            }
        },
        mounted: function () {
            var self = this;
            var cbName = this.$store.state.endpoints.fileDialogResult;
            if (!cbName || window[cbName]) { return; }   // колбэк уже стоит

            // Колбэк общий, ставится один раз первым появившимся полем и живёт
            // до перезагрузки страницы: ядро зовёт его по имени из своего
            // скрипта. Ядро отдаёт имя файла и папку отдельно — склеиваем сами
            // (готовый путь оно собирает только для режимов FORM/ELEMENT_ID).
            window[cbName] = function (filename, path) {
                if (!galleryPicking) { return; }
                var full = ((path || '') + '/' + (filename || '')).replace(/\/+/g, '/');
                galleryPicking.commitValue(full);
                galleryPicking = null;
            };
            void self;
        },
        methods: {
            openDialog: function () {
                if (!this.hasDialog) { return; }
                galleryPicking = this;
                var open = window[this.$store.state.endpoints.fileDialog];
                // Первый аргумент — грузить ли js диалога: он подгружается лениво.
                open(true, { path: this.settings.path || '' });
            },
            clear: function () { this.commitValue(''); }
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <div class="st-ref">' +
            '    <input class="st-input st-ref-id" type="text" :value="value"' +
            '           placeholder="/upload/gallery/1.jpg"' +
            '           @input="commitValue($event.target.value)">' +
            '    <button class="st-ref-btn" type="button" v-if="hasDialog"' +
            '            :title="t(\'STRUCTURA_JS_PICK_ON_SITE\')" @click="openDialog">{{ t(\'STRUCTURA_JS_CHOOSE\') }}</button>' +
            '    <button class="st-ref-btn st-ref-btn--clear" type="button" v-if="value"' +
            '            :title="t(\'STRUCTURA_JS_CLEAR\')" @click="clear">×</button>' +
            '  </div>' +
            '  <div class="st-thumb" v-if="value && isImage">' +
            '    <img :src="value" :alt="value">' +
            '    <span class="st-hint">{{ value }}</span>' +
            '  </div>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    /**
     * Поле выбора файла из медиабиблиотеки Bitrix (диалог CMedialib).
     *
     * Устроено как gallery, отличий два: свой диалог (медиабиблиотека, а не
     * файловая структура сайта) и колбэк ядра отдаёт объект ({src, name, ...}),
     * а не имя+папку. Значение — путь (src). Диалог на странице один (печатает
     * сервер), поэтому выбирающее поле запоминаем, а общий колбэк отдаёт путь ему.
     */
    var mlPicking = null;   // поле, которое сейчас открыло диалог медиабиблиотеки

    registerField('medialibrary', {
        mixins: [fieldMixin],
        computed: {
            // Нет fileman или прав на медиабиблиотеку — диалога не будет, и
            // кнопку показывать незачем: путь останется вписать руками.
            hasDialog: function () {
                var name = this.$store.state.endpoints.mlDialog;
                return !!name && typeof window[name] === 'function';
            },
            isImage: function () {
                return /\.(jpe?g|png|gif|webp|bmp|svg|ico)$/i.test(this.value || '');
            }
        },
        mounted: function () {
            var cbName = this.$store.state.endpoints.mlDialogResult;
            if (!cbName || window[cbName]) { return; }   // колбэк уже стоит

            // Колбэк общий, ставится один раз первым появившимся полем и живёт
            // до перезагрузки: ядро зовёт его по имени из своего скрипта. В
            // режиме FUNCTION_NAME медиабиблиотека отдаёт объект с готовым
            // путём (src) — в отличие от файлового диалога, склеивать не нужно.
            window[cbName] = function (result) {
                if (!mlPicking) { return; }
                mlPicking.commitValue((result && result.src) || '');
                mlPicking = null;
            };
        },
        methods: {
            openDialog: function () {
                if (!this.hasDialog) { return; }
                mlPicking = this;
                // Единственный аргумент — грузить ли js диалога (лениво).
                window[this.$store.state.endpoints.mlDialog](true);
            },
            clear: function () { this.commitValue(''); }
        },
        template:
            '<div class="st-field" :class="{\'st-invalid\': invalid}">' +
            '  <structura-label :field="field"></structura-label>' +
            '  <div class="st-ref">' +
            '    <input class="st-input st-ref-id" type="text" :value="value"' +
            '           placeholder="/upload/medialibrary/1.jpg"' +
            '           @input="commitValue($event.target.value)">' +
            '    <button class="st-ref-btn" type="button" v-if="hasDialog"' +
            '            :title="t(\'STRUCTURA_JS_PICK_FROM_ML\')" @click="openDialog">{{ t(\'STRUCTURA_JS_ML_BTN\') }}</button>' +
            '    <button class="st-ref-btn st-ref-btn--clear" type="button" v-if="value"' +
            '            :title="t(\'STRUCTURA_JS_CLEAR\')" @click="clear">×</button>' +
            '  </div>' +
            '  <div class="st-thumb" v-if="value && isImage">' +
            '    <img :src="value" :alt="value">' +
            '    <span class="st-hint">{{ value }}</span>' +
            '  </div>' +
            '  <div class="st-hint" v-else-if="value">{{ value }}</div>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '</div>'
    });

    // Запасного «просто ввод ID» (referenceField) больше нет: у всех девяти
    // ссылочных полей есть свой пикер. Незнакомый тип по-прежнему
    // ловит _fallback ниже.

    registerField('_fallback', inputField('text'));

    /**
     * Причина запрета — отдельным компонентом, чтобы выглядела одинаково у поля
     * и у блока.
     *
     * Пользователь должен понимать, почему не может изменить поле. Текст
     * приходит с сервера (field.lockReason): клиент не знает ни групп
     * пользователя, ни именованных политик.
     */
    Vue.component('structura-lock', {
        props: { reason: { type: String, default: null } },
        template:
            '<div class="st-lock" :title="reason || \'\'">' +
            '  <span class="st-lock-icon" aria-hidden="true">🔒</span>' +
            '  <span class="st-lock-text">{{ reason || t(\'STRUCTURA_JS_LOCKED\') }}</span>' +
            '</div>'
    });

    // Диспетчер поля.
    //
    // Здесь же — блокировка по политике. Это единственное место, через которое
    // проходят все поля, включая зарегистрированные сторонними разработчиками,
    // поэтому дописывать :disabled в каждый шаблон не нужно (и невозможно —
    // чужих шаблонов мы не видим).
    //
    // Блокирует <fieldset disabled>: он нативно гасит все инпуты, селекты и
    // кнопки внутри, чем бы поле ни рендерилось. CSS-обёртки вроде
    // pointer-events для этого мало — с клавиатуры поле осталось бы доступным.
    //
    // Это только UI. Настоящая защита — на сервере (PolicyGuard): значения
    // приходят обычным ajax-запросом, который собирается руками из консоли.
    Vue.component('structura-field', {
        props: {
            field: { type: Object, required: true },
            instance: { type: String, required: true },
            path: { type: String, required: true }
        },
        computed: {
            component: function () { return resolveField(this.field.type); },
            locked: function () { return this.field.editable === false; }
        },
        render: function (h) {
            var node = h(this.component, {
                props: { field: this.field, instance: this.instance, path: this.path }
            });

            if (!this.locked) {
                return node;
            }

            return h('fieldset', { class: 'st-locked', attrs: { disabled: 'disabled' } }, [
                h('structura-lock', { props: { reason: this.field.lockReason } }),
                node
            ]);
        }
    });

    // Repeater.
    Vue.component('structura-repeater', {
        mixins: [fieldMixin, dragReorder],
        computed: {
            rows: function () { return Array.isArray(this.value) ? this.value : []; }
        },
        methods: {
            // Перетаскивание строк: ключ списка — экземпляр + путь контейнера,
            // чтобы строку одного Repeater нельзя было уронить в соседний.
            dragKind: function () { return 'row'; },
            dragKey:  function () { return this.instance + ' ' + this.path; },
            dragMoved: function (from, to) {
                this.$store.commit('MOVE_ROW', { instance: this.instance, path: this.path, from: from, to: to });
            },
            add: function () {
                this.$store.commit('ADD_ROW', {
                    instance: this.instance, path: this.path, row: newRow(this.field.fields)
                });
            },
            remove: function (i) {
                this.$store.commit('REMOVE_ROW', { instance: this.instance, path: this.path, index: i });
            },
            move: function (i, delta) {
                this.$store.commit('MOVE_ROW', { instance: this.instance, path: this.path, from: i, to: i + delta });
            },
            /**
             * Копия строки — со своими файлами.
             *
             * Копию делает сервер: значение файлового поля — ID, и простая копия
             * значения дала бы двум строкам один файл; удалили файл у копии —
             * пропал и у оригинала. Сервер дублирует файлы (CFile::CopyFile).
             * Без действия (демо-стенд, save: null) копируем как есть: там
             * ничего не сохраняется, а файлы всё равно общие.
             */
            copy: function (i) {
                var self  = this;
                var store = this.$store;
                var src   = JSON.parse(JSON.stringify(this.rows[i]));   // отвязка от исходной строки
                var action = store.state.endpoints.cloneRow;

                // Какие макеты Flexible встретились по пути. Без этого сервер не
                // дойдёт до полей строки внутри макета и отдаст копии чужой ID
                // картинки. Считаем по значениям — только там есть _layout.
                var layouts = layoutTrail(store.state.values[this.instance], this.path);

                if (!action || !BX.ajax || !BX.ajax.runAction) {
                    // Файлы тут остаются общими (дублировать их может только
                    // сервер), но id обязан быть свой: две строки с одним `_id`
                    // слились бы в одну при сопоставлении с политиками.
                    store.commit('INSERT_ROW', {
                        instance: this.instance, path: this.path, index: i + 1, row: refreshRowIds(src)
                    });
                    return;
                }

                this.cloning = i;
                BX.ajax.runAction(action, {
                    data: {
                        blockCode:  store.getters.blockCodeOf(this.instance),
                        entityType: store.state.entity.type,
                        entityId:   store.state.entity.id,
                        path:       this.path,
                        row:        src,
                        layouts:    layouts
                    }
                }).then(
                    function (r) {
                        self.cloning = null;
                        var clone = (r && r.data && r.data.row) ? r.data.row : src;
                        store.commit('INSERT_ROW', { instance: self.instance, path: self.path, index: i + 1, row: clone });
                    },
                    function () {
                        // Сервер не смог — молча копировать значения нельзя:
                        // файлы остались бы общими и удаление копии било бы по
                        // оригиналу. Честнее не создавать строку.
                        self.cloning = null;
                        store.commit('SET_MESSAGE', {
                            instance: self.instance,
                            message: { type: 'error', text: t('STRUCTURA_JS_ROW_COPY_FAILED') }
                        });
                    }
                );
            }
        },
        data: function () { return { cloning: null }; },
        template:
            '<div class="st-repeater" :class="{\'st-invalid\': invalid}">' +
            '  <div class="st-container-head"><structura-label :field="field"></structura-label>' +
            '    <span class="st-count">{{ rows.length }}</span></div>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '  <div class="st-empty" v-if="!rows.length">{{ t(\'STRUCTURA_JS_REPEATER_EMPTY\') }}</div>' +
            '  <div class="st-rows">' +
            '    <div class="st-row" v-for="(row, i) in rows" :key="i"' +
            '      :class="{\'st-dragging\': isDragged(i), \'st-drag-over\': isDropTarget(i)}"' +
            '      @dragover="dragOver($event, i)" @drop="dropAt($event, i)" @dragleave="dragLeaveList()">' +
            '      <div class="st-row-head">' +
            '        <span class="st-drag-handle" draggable="true" :title="t(\'STRUCTURA_JS_DRAG_ROW\')"' +
            '          @dragstart="dragStart($event, i)" @dragend="dragEnd()">⠿</span>' +
            '        #{{ i + 1 }}' +
            '        <button type="button" class="st-move" :disabled="i === 0" :title="t(\'STRUCTURA_JS_MOVE_UP\')"' +
            '          @click="move(i, -1)">↑</button>' +
            '        <button type="button" class="st-move" :disabled="i === rows.length - 1" :title="t(\'STRUCTURA_JS_MOVE_DOWN\')"' +
            '          @click="move(i, 1)">↓</button>' +
            '        <button type="button" class="st-move" :title="t(\'STRUCTURA_JS_COPY_ROW\')"' +
            '          :disabled="cloning === i" @click="copy(i)">⧉</button>' +
            '        <button type="button" class="st-remove" @click="remove(i)">✕</button></div>' +
            '      <structura-field v-for="child in field.fields" :key="child.code"' +
            '        :field="child" :instance="instance" :path="path + \'.\' + i + \'.\' + child.code"></structura-field>' +
            '    </div>' +
            '  </div>' +
            '  <button type="button" class="st-add" @click="add">{{ t(\'STRUCTURA_JS_ADD\') }}</button>' +
            '</div>'
    });
    registerField('repeater', 'structura-repeater');

    /**
     * Group — один вложенный набор полей, а не список.
     *
     * Ни кнопок «добавить/удалить», ни счётчика: набор существует всегда и
     * ровно один. Путь к вложенному полю — «cta.href», без индекса строки
     * (у Repeater он был бы «cta.0.href»), и точно так же его считает сервер.
     *
     * Смысл — переиспользование: класс-наследник Group со своими полями
     * кладётся в setFields() как обычное поле.
     */
    Vue.component('structura-group', {
        mixins: [fieldMixin],
        template:
            '<div class="st-group" :class="{\'st-invalid\': invalid}">' +
            '  <div class="st-container-head"><structura-label :field="field"></structura-label></div>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '  <div class="st-group-body">' +
            '    <structura-field v-for="child in field.fields" :key="child.code"' +
            '      :field="child" :instance="instance" :path="path + \'.\' + child.code"></structura-field>' +
            '  </div>' +
            '</div>'
    });
    registerField('group', 'structura-group');

    // Flexible.
    Vue.component('structura-flexible', {
        mixins: [fieldMixin, dragReorder],
        computed: {
            items: function () { return Array.isArray(this.value) ? this.value : []; },
            layouts: function () { return this.field.fields || []; }
        },
        methods: {
            dragKind: function () { return 'row'; },
            dragKey:  function () { return this.instance + ' ' + this.path; },
            dragMoved: function (from, to) {
                this.$store.commit('MOVE_ROW', { instance: this.instance, path: this.path, from: from, to: to });
            },
            layoutFor: function (code) {
                return this.layouts.filter(function (l) { return l.code === code; })[0];
            },
            add: function (layout) {
                this.$store.commit('ADD_FLEX', {
                    instance: this.instance, path: this.path,
                    item: { _layout: layout.code, data: defaultForField(layout), _id: newRowId() }
                });
            },
            remove: function (i) {
                this.$store.commit('REMOVE_ROW', { instance: this.instance, path: this.path, index: i });
            },
            move: function (i, delta) {
                this.$store.commit('MOVE_ROW', { instance: this.instance, path: this.path, from: i, to: i + delta });
            }
        },
        template:
            '<div class="st-flexible" :class="{\'st-invalid\': invalid}">' +
            '  <div class="st-container-head"><structura-label :field="field"></structura-label>' +
            '    <span class="st-count">{{ items.length }}</span></div>' +
            '  <div class="st-errors" v-if="invalid"><span v-for="(e,i) in errors" :key="i">{{ e }}</span></div>' +
            '  <div class="st-empty" v-if="!items.length">{{ t(\'STRUCTURA_JS_FLEX_EMPTY\') }}</div>' +
            '  <div class="st-flex-items">' +
            '    <div class="st-flex-item" v-for="(item, i) in items" :key="i"' +
            '      :class="{\'st-dragging\': isDragged(i), \'st-drag-over\': isDropTarget(i)}"' +
            '      @dragover="dragOver($event, i)" @drop="dropAt($event, i)" @dragleave="dragLeaveList()">' +
            '      <div class="st-row-head">' +
            '        <span class="st-drag-handle" draggable="true" :title="t(\'STRUCTURA_JS_DRAG_LAYOUT\')"' +
            '          @dragstart="dragStart($event, i)" @dragend="dragEnd()">⠿</span>' +
            '        {{ (layoutFor(item._layout) || {}).name || item._layout }}' +
            '        <button type="button" class="st-move" :disabled="i === 0" :title="t(\'STRUCTURA_JS_MOVE_UP\')"' +
            '          @click="move(i, -1)">↑</button>' +
            '        <button type="button" class="st-move" :disabled="i === items.length - 1" :title="t(\'STRUCTURA_JS_MOVE_DOWN\')"' +
            '          @click="move(i, 1)">↓</button>' +
            '        <button type="button" class="st-remove" @click="remove(i)">✕</button></div>' +
            '      <structura-field v-if="layoutFor(item._layout)" :field="layoutFor(item._layout)"' +
            '        :instance="instance" :path="path + \'.\' + i + \'.data\'"></structura-field>' +
            '    </div>' +
            '  </div>' +
            '  <div class="st-flex-add">' +
            '    <button type="button" class="st-add" v-for="l in layouts" :key="l.code" @click="add(l)">+ {{ l.name }}</button>' +
            '  </div>' +
            '</div>'
    });
    registerField('flexible', 'structura-flexible');

    /* ------------------------------------------------------------------ *
     *  Языковые вкладки
     * ------------------------------------------------------------------ */
    /**
     * Вкладки языков для карточки блока.
     *
     * Решение делится надвое ровно так же, как на сервере: список языков
     * глобальный (configuration.php → state.languages), а «переводится ли этот
     * блок» — флаг самого блока (->translatable() → config.translatable).
     * Не совпало хоть одно — вкладок нет, и карточка ведёт себя как раньше.
     *
     * Один язык вкладками не рисуем: выбирать не из чего, а лишняя полоса
     * поверх каждой карточки только мешает. Это не то же самое, что «языков
     * нет»: с одним языком блок всё равно переводимый, просто выбора нет.
     *
     * Порядок вкладок — порядок languages: первый там же, где fallback
     * (Loader::languageFallback берёт первый, если явного нет).
     *
     * @param {?Object} config     конфигурация блока (Loader::describe)
     * @param {Array}   languages  глобальный список языков
     * @returns {Array} метки языков; пусто — вкладок не показывать
     */
    function languageTabs(config, languages) {
        var list = languages || [];
        if (!config || !config.translatable || list.length < 2) { return []; }

        return list;
    }

    /* ------------------------------------------------------------------ *
     *  Хранилище (Vuex)
     * ------------------------------------------------------------------ */
    /**
     * Идентификатор только что добавленной копии блока.
     *
     * У обычного блока экземпляр один и его id — код блока. У множественного —
     * uuid, который клиент выдаёт сразу при добавлении, ещё до сохранения: иначе
     * новая копия перезаписала бы основной экземпляр (id = код блока).
     *
     * Это не то же, что серверный ValueService::defaultInstanceId (тот отвечает
     * «какой экземпляр адресовать, когда id не передан» — основной, id = код
     * блока). Совпадают на обычном блоке, на множественном расходятся сознательно.
     */
    function newInstanceId(blockConfig) {
        if (!blockConfig || !blockConfig.multiple) {
            return blockConfig ? blockConfig.code : '';
        }
        var c = window.crypto || window.msCrypto;
        if (c && typeof c.randomUUID === 'function') {
            return c.randomUUID().replace(/-/g, '');           // 32 hex — влезает в varchar(32)
        }
        if (c && typeof c.getRandomValues === 'function') {
            var b = new Uint8Array(16);
            c.getRandomValues(b);
            return Array.prototype.map.call(b, function (x) {
                return ('0' + x.toString(16)).slice(-2);
            }).join('');
        }
        return (Date.now().toString(16) + Math.random().toString(16).slice(2)).slice(0, 32);
    }

    function createStore(options) {
        var configByCode = {};
        (options.blocks || []).forEach(function (b) { configByCode[b.code] = b; });

        // Экземпляры — упорядоченный список; значения адресуются instanceId,
        // потому что один и тот же блок может быть добавлен несколько раз.
        var instances = [];
        var values    = {};
        // Активность экземпляра: флаг + окно дат. Даты приходят как "Y-m-d H:i:s",
        // а <input type="datetime-local"> хочет "Y-m-dTH:i" — приводим к нему.
        var activity = {};
        function toLocalInput(s) { return s ? String(s).replace(' ', 'T').slice(0, 16) : ''; }

        // Языки контента — глобальные (configuration.php), вкладки рисуются
        // только у ->translatable()-блоков. fallback хранится в базовой таблице
        // и приезжает в instances.values, остальные языки грузятся по клику.
        var languages = options.languages || [];
        var fallback  = options.languageFallback || languages[0] || '';
        // Языки, перевод которых уже сохранён: { instanceId: { lang: true } }.
        var translated = {};

        (options.instances || []).forEach(function (inst) {
            // meta — авторство/даты с сервера (createdName, updatedName, ...).
            // У добавленного на клиенте экземпляра его нет — подпись просто не
            // показывается до сохранения и перезагрузки формы.
            instances.push({ instanceId: inst.instanceId, blockCode: inst.blockCode, meta: inst.meta || null });
            values[inst.instanceId] = inst.values || {};
            activity[inst.instanceId] = {
                active: inst.active !== false,          // по умолчанию активен
                from:   toLocalInput(inst.activeFrom),
                to:     toLocalInput(inst.activeTo),
                now:    inst.activeNow !== false        // виден ли сейчас на сайте
            };

            var marks = {};
            (inst.translated || []).forEach(function (lang) { marks[lang] = true; });
            translated[inst.instanceId] = marks;
        });

        return new Vuex.Store({
            state: {
                entity: options.entity || {},
                blocks: options.blocks || [],       // каталог доступных блоков
                // Значения блоков, у которых больше нет конфигурации:
                // [{blockCode, count}]. Считает сервер (см. orphanInstances).
                orphans: options.orphans || [],
                configByCode: configByCode,
                // Имена ajax-действий. Лежат в state, потому что компоненты полей
                // регистрируются глобально и до options из замыкания не достают.
                endpoints: options,
                instances: instances,               // [{instanceId, blockCode}] в порядке показа
                // Активность экземпляра: { instanceId: {active, from, to, now} }.
                // from/to — строки для <input type="datetime-local">.
                activity: activity,
                picking: false,                     // открыт ли список блоков для добавления
                values: values,                     // { instanceId: { code: value } }
                errors: {},          // { instanceId: { path: [msg] } }
                // Поля, где браузер не смог прочитать введённое (validity.badInput):
                // { instanceId: { path: <тип поля> }. Значение приходит пустой
                // строкой, поэтому по нему такое поле не отличить от незаполненного.
                badInput: {},
                saving: {},          // { instanceId: bool }
                message: {},         // { instanceId: { type, text } }
                revisions: {},       // { instanceId: [ {id,userId,comment,sourceRevisionId,createdAt} ] }
                revTotal: {},        // { instanceId: int } — всего ревизий на сервере
                revOpen: {},         // { instanceId: bool } — открыта ли панель истории
                revLoading: {},      // { instanceId: bool }
                revMoreLoading: {},  // { instanceId: bool } — идёт догрузка страницы
                // Предпросмотр отката: какая ревизия раскрыта и её значения.
                // { instanceId: { id: <revId>, diff: [ {path,label,from,to,type} ] } }
                revDiff: {},
                // Экземпляры, ещё не сохранённые на сервере: удалять их нужно
                // только из состояния, без обращения к нему.
                unsaved: {},
                // Развёрнутые экземпляры. По умолчанию блок свёрнут: в карте
                // его нет → collapsed. Так и при загрузке формы, и при
                // добавлении нового блока карточка показывается свёрнутой —
                // когда блоков много, развёрнутый список неудобен.
                expanded: {},
                // Экземпляры с несохранёнными правками: помечаются при первом
                // же изменении содержимого и очищаются после сохранения/отката.
                // Значения приходят с сервера прямо в values, минуя мутации, —
                // поэтому загрузка формы блок «изменённым» не делает.
                dirty: {},

                // --- Языковые вкладки -------------------------------------
                // Список языков и fallback — глобальные, одни на всю форму.
                languages: languages,
                languageFallback: fallback,
                // Активная вкладка экземпляра. Экземпляра нет в карте → fallback:
                // форма открывается на языке по умолчанию, его значения уже в values.
                lang: {},
                // Языки с сохранённым переводом: { instanceId: { lang: true } }.
                // Нужны, чтобы пометить непереведённые вкладки и предложить
                // заполнить их из базы.
                translated: translated,
                // Идёт загрузка значений вкладки: { instanceId: bool }.
                langLoading: {}
            },
            getters: {
                // Конфигурация блока для экземпляра.
                configOf: function (state) {
                    return function (instanceId) {
                        var meta = state.instances.filter(function (i) { return i.instanceId === instanceId; })[0];
                        return meta ? state.configByCode[meta.blockCode] : null;
                    };
                },
                blockCodeOf: function (state) {
                    return function (instanceId) {
                        var meta = state.instances.filter(function (i) { return i.instanceId === instanceId; })[0];
                        return meta ? meta.blockCode : null;
                    };
                },
                // Свёрнута ли карточка экземпляра (по умолчанию — да).
                isCollapsed: function (state) {
                    return function (instanceId) { return !state.expanded[instanceId]; };
                },
                // Есть ли у экземпляра несохранённые правки.
                isDirty: function (state) {
                    return function (instanceId) { return !!state.dirty[instanceId]; };
                },
                // Есть ли хоть один развёрнутый блок — чтобы кнопка в панели
                // предлагала уместное действие (свернуть/развернуть все).
                anyExpanded: function (state) {
                    return state.instances.some(function (i) { return !!state.expanded[i.instanceId]; });
                },
                // Можно ли добавить блок ещё раз: обычный блок — только один
                // экземпляр, множественный — сколько угодно.
                /**
                 * Кто занял unique-группу этого блока.
                 *
                 * Группа с `unique` — набор взаимоисключающих блоков: на
                 * сущности допустим только один экземпляр из всей группы.
                 * Правило применяет сервер при сохранении; здесь — только
                 * подсказка, чтобы человек не заполнял блок, который всё равно
                 * не сохранится.
                 *
                 * @return {?Object} { group, blockName } или null
                 */
                groupBlocker: function (state) {
                    return function (blockCode) {
                        var config = state.configByCode[blockCode];
                        if (!config) { return null; }

                        var found = null;
                        (config.groups || []).forEach(function (group) {
                            if (!group.unique || found) { return; }

                            state.instances.forEach(function (inst) {
                                if (found) { return; }
                                var other = state.configByCode[inst.blockCode];
                                if (!other) { return; }
                                // Занял ли эту же группу кто-то из уже добавленных.
                                // Сравниваем по path: имена групп могут совпадать
                                // в разных ветках дерева.
                                var sameGroup = (other.groups || []).some(function (g) {
                                    return g.path === group.path;
                                });
                                if (sameGroup) {
                                    found = { group: group.name, blockName: other.name };
                                }
                            });
                        });

                        return found;
                    };
                },
                canAdd: function (state, getters) {
                    return function (blockCode) {
                        var config = state.configByCode[blockCode];
                        if (!config) { return false; }
                        // unique-группа сильнее multiple(): для группы вторая
                        // копия — такой же второй блок, чей бы он ни был.
                        if (getters.groupBlocker(blockCode)) { return false; }
                        if (config.multiple) { return true; }
                        return !state.instances.some(function (i) { return i.blockCode === blockCode; });
                    };
                },
                valueAt: function (state) {
                    return function (instance, path) { return getByPath(state.values[instance] || {}, path); };
                },
                errorsAt: function (state) {
                    return function (instance, path) {
                        var be = state.errors[instance];
                        return (be && be[path]) ? be[path] : [];
                    };
                },
                messageOf: function (state) {
                    return function (instance) { return state.message[instance] || null; };
                },
                isSaving: function (state) {
                    return function (instance) { return !!state.saving[instance]; };
                },
                historyList: function (state) {
                    return function (instance) { return state.revisions[instance] || []; };
                },
                historyOpen: function (state) {
                    return function (instance) { return !!state.revOpen[instance]; };
                },
                historyLoading: function (state) {
                    return function (instance) { return !!state.revLoading[instance]; };
                },
                historyMoreLoading: function (state) {
                    return function (instance) { return !!state.revMoreLoading[instance]; };
                },
                // Сколько ревизий ещё не показано (0 — всё загружено).
                historyRest: function (state) {
                    return function (instance) {
                        var shown = (state.revisions[instance] || []).length;
                        var total = state.revTotal[instance] || 0;
                        return Math.max(0, total - shown);
                    };
                },
                // Предпросмотр раскрыт для этой ревизии?
                previewOpen: function (state) {
                    return function (instance, revisionId) {
                        var d = state.revDiff[instance];
                        return !!d && d.id === revisionId;
                    };
                },
                // Список изменений раскрытого предпросмотра (null — не загружен).
                previewDiff: function (state) {
                    return function (instance, revisionId) {
                        var d = state.revDiff[instance];
                        return (d && d.id === revisionId) ? d.diff : null;
                    };
                },

                // Вкладки языков экземпляра (пусто — вкладок нет вовсе).
                langTabs: function (state, getters) {
                    return function (instance) {
                        return languageTabs(getters.configOf(instance), state.languages);
                    };
                },
                // Активная вкладка. Умолчание — fallback: с него открывается форма.
                langOf: function (state) {
                    return function (instance) {
                        return state.lang[instance] || state.languageFallback;
                    };
                },
                // Правится ли сейчас базовый слой. От него зависят не только
                // значения: активность, порядок и авторство экземпляра общие на
                // все языки и живут в базе — на вкладке перевода их не меняют.
                isBaseLang: function (state, getters) {
                    return function (instance) {
                        return getters.langOf(instance) === state.languageFallback;
                    };
                },
                // Есть ли сохранённый перевод. fallback — всегда да: базовая
                // запись существует, пока существует сам экземпляр.
                isTranslated: function (state) {
                    return function (instance, lang) {
                        if (lang === state.languageFallback) { return true; }
                        return !!(state.translated[instance] || {})[lang];
                    };
                },
                langLoading: function (state) {
                    return function (instance) { return !!state.langLoading[instance]; };
                }
            },
            mutations: {
                SET_PICKING: function (state, v) { state.picking = v; },

                // Новый экземпляр добавляется в конец и помечается несохранённым.
                ADD_INSTANCE: function (state, p) {
                    state.instances.push({ instanceId: p.instanceId, blockCode: p.blockCode });
                    Vue.set(state.values, p.instanceId, p.values || {});
                    Vue.set(state.unsaved, p.instanceId, true);
                    // Новый экземпляр активен, без ограничения по датам.
                    Vue.set(state.activity, p.instanceId, { active: true, from: '', to: '', now: true });
                },
                REMOVE_INSTANCE: function (state, p) {
                    state.instances = state.instances.filter(function (i) {
                        return i.instanceId !== p.instanceId;
                    });
                    Vue.delete(state.values, p.instanceId);
                    Vue.delete(state.errors, p.instanceId);
                    Vue.delete(state.badInput, p.instanceId);
                    Vue.delete(state.message, p.instanceId);
                    Vue.delete(state.unsaved, p.instanceId);
                    // Флаг «идёт сохранение»: удаление ставит его в true и на
                    // успехе зовёт эту мутацию, но сам его не снимал. Обычный блок
                    // (id = код блока), добавленный заново, наследовал бы saving=true
                    // от удаления — и кнопка «Сохранить» у нового блока была бы
                    // намертво отключена до перезагрузки страницы.
                    Vue.delete(state.saving, p.instanceId);
                    // Ревизионные карты — по той же причине (id совпадает у
                    // пере-добавленного обычного блока): панель истории, список,
                    // индикаторы загрузки не должны утекать на новый экземпляр.
                    Vue.delete(state.revDiff, p.instanceId);
                    Vue.delete(state.revisions, p.instanceId);
                    Vue.delete(state.revTotal, p.instanceId);
                    Vue.delete(state.revOpen, p.instanceId);
                    Vue.delete(state.revLoading, p.instanceId);
                    Vue.delete(state.revMoreLoading, p.instanceId);
                    // Иначе повторно добавленный обычный блок (у него id = код
                    // блока) унаследовал бы «развёрнут»/«изменён» от прежнего.
                    Vue.delete(state.expanded, p.instanceId);
                    Vue.delete(state.dirty, p.instanceId);
                    Vue.delete(state.activity, p.instanceId);
                    // Языковые карты — по той же причине: новый экземпляр с тем
                    // же id открылся бы на чужой вкладке и с чужими пометками
                    // «переведено», хотя переводов у него ещё нет.
                    Vue.delete(state.lang, p.instanceId);
                    Vue.delete(state.translated, p.instanceId);
                    Vue.delete(state.langLoading, p.instanceId);
                },
                // Правка активности экземпляра (флаг или края окна дат). Помечаем
                // «изменён» — активность едет в общем «Сохранить».
                SET_ACTIVITY: function (state, p) {
                    var a = state.activity[p.instanceId] || { active: true, from: '', to: '', now: true };
                    var next = { active: a.active, from: a.from, to: a.to, now: a.now };
                    if ('active' in p) { next.active = p.active; }
                    if ('from' in p)   { next.from = p.from; }
                    if ('to' in p)     { next.to = p.to; }
                    Vue.set(state.activity, p.instanceId, next);

                    // Исправили дату — снимаем её ошибку сразу, как и у полей
                    // (SET_VALUE): иначе подсветка висела бы до сохранения.
                    var be = state.errors[p.instanceId];
                    ['from', 'to'].forEach(function (edge) {
                        var path = '_activity:' + edge;
                        if (edge in p && be && be[path]) { Vue.delete(be, path); }
                    });

                    markDirty(state, p.instanceId);
                },
                // Развернуть/свернуть карточку экземпляра.
                TOGGLE_COLLAPSE: function (state, p) {
                    Vue.set(state.expanded, p.instanceId, !state.expanded[p.instanceId]);
                },
                // Развернуть либо свернуть все экземпляры разом.
                SET_ALL_EXPANDED: function (state, expand) {
                    if (!expand) { state.expanded = {}; return; }
                    var m = {};
                    state.instances.forEach(function (i) { m[i.instanceId] = true; });
                    state.expanded = m;
                },
                MARK_SAVED: function (state, p) {
                    Vue.delete(state.unsaved, p.instanceId);
                    Vue.delete(state.dirty, p.instanceId);
                },
                // Значения, которые сервер действительно сохранил.
                //
                // Нужны из-за политик: запрещённое поле сервер откатывает к
                // старому значению, и без этого в форме осталось бы висеть
                // введённое, которого в БД нет. Показывать несохранённое как
                // сохранённое — худший вид вранья интерфейса.
                SET_SAVED_VALUES: function (state, p) {
                    if (p.values && typeof p.values === 'object') {
                        Vue.set(state.values, p.instance, p.values);
                    }
                },
                // Перестановка экземпляров (архитектура под drag & drop).
                MOVE_INSTANCE: function (state, p) {
                    var list = state.instances.slice();
                    if (p.to < 0 || p.to >= list.length) { return; }
                    list.splice(p.to, 0, list.splice(p.from, 1)[0]);
                    state.instances = list;
                },

                SET_VALUE: function (state, p) {
                    setByPath(state.values[p.instance], p.path, p.value);
                    var be = state.errors[p.instance];
                    if (be && be[p.path]) { Vue.delete(be, p.path); }
                    markDirty(state, p.instance);
                },
                // Ставит или снимает пометку «браузер не смог прочитать ввод».
                // Снятие обязательно: иначе исправленное поле осталось бы
                // помеченным навсегда и блокировало сохранение.
                // Разом снять все пометки нечитаемого ввода: поля в форме
                // сменились целиком (переключение языка), и отметка от прежней
                // вкладки блокировала бы сохранение поля, которого уже нет.
                CLEAR_BAD_INPUT: function (state, p) { Vue.set(state.badInput, p.instance, {}); },
                SET_BAD_INPUT: function (state, p) {
                    var map = state.badInput[p.instance];
                    if (!map) { Vue.set(state.badInput, p.instance, {}); map = state.badInput[p.instance]; }

                    if (p.type) { Vue.set(map, p.path, p.type); }
                    else if (map[p.path]) { Vue.delete(map, p.path); }
                },
                ADD_ROW: function (state, p) {
                    var arr = getByPath(state.values[p.instance], p.path);
                    if (!Array.isArray(arr)) { setByPath(state.values[p.instance], p.path, []); arr = getByPath(state.values[p.instance], p.path); }
                    arr.push(p.row);
                    markDirty(state, p.instance);
                },
                ADD_FLEX: function (state, p) {
                    var arr = getByPath(state.values[p.instance], p.path);
                    if (!Array.isArray(arr)) { setByPath(state.values[p.instance], p.path, []); arr = getByPath(state.values[p.instance], p.path); }
                    arr.push(p.item);
                    markDirty(state, p.instance);
                },
                // Вставка строки на позицию (копия встаёт сразу под оригиналом).
                INSERT_ROW: function (state, p) {
                    var arr = getByPath(state.values[p.instance], p.path);
                    if (!Array.isArray(arr)) { return; }
                    arr.splice(Math.min(p.index, arr.length), 0, p.row);
                    markDirty(state, p.instance);
                },
                REMOVE_ROW: function (state, p) {
                    var arr = getByPath(state.values[p.instance], p.path);
                    if (Array.isArray(arr)) { arr.splice(p.index, 1); markDirty(state, p.instance); }
                },
                // Перестановка строк внутри Repeater/Flexible.
                MOVE_ROW: function (state, p) {
                    var arr = getByPath(state.values[p.instance], p.path);
                    if (!Array.isArray(arr) || p.to < 0 || p.to >= arr.length) { return; }
                    arr.splice(p.to, 0, arr.splice(p.from, 1)[0]);
                    markDirty(state, p.instance);
                },
                SET_ERRORS: function (state, p) { Vue.set(state.errors, p.instance, p.errors || {}); },
                SET_SAVING: function (state, p) { Vue.set(state.saving, p.instance, p.value); },
                SET_MESSAGE: function (state, p) { Vue.set(state.message, p.instance, p.message); },
                SET_REVISIONS: function (state, p) {
                    Vue.set(state.revisions, p.instance, p.list || []);
                    Vue.set(state.revTotal, p.instance, p.total || 0);
                },
                // Догрузка следующей страницы — дописываем в конец списка.
                APPEND_REVISIONS: function (state, p) {
                    var cur = state.revisions[p.instance] || [];
                    Vue.set(state.revisions, p.instance, cur.concat(p.list || []));
                    Vue.set(state.revTotal, p.instance, p.total || 0);
                },
                SET_REV_MORE_LOADING: function (state, p) { Vue.set(state.revMoreLoading, p.instance, p.value); },
                SET_REV_OPEN: function (state, p) { Vue.set(state.revOpen, p.instance, p.value); },
                // diff === null → предпросмотр раскрыт, но значения ещё грузятся.
                SET_REV_DIFF: function (state, p) {
                    Vue.set(state.revDiff, p.instance, { id: p.id, diff: p.diff });
                },
                CLOSE_REV_DIFF: function (state, p) { Vue.delete(state.revDiff, p.instance); },
                SET_REV_LOADING: function (state, p) { Vue.set(state.revLoading, p.instance, p.value); },
                // Откат к ревизии — это возврат к сохранённому состоянию,
                // поэтому «изменён» снимается (значения на сервере уже такие).
                REPLACE_VALUES: function (state, p) {
                    Vue.set(state.values, p.instance, p.values || {});
                    Vue.delete(state.dirty, p.instance);
                },

                // Пометить экземпляр изменённым без правки значений: значения
                // подменены целиком (заполнение перевода из базы), а в БД их
                // ещё нет.
                MARK_DIRTY: function (state, p) { markDirty(state, p.instance); },
                // Активная языковая вкладка экземпляра.
                SET_LANG: function (state, p) { Vue.set(state.lang, p.instance, p.lang); },
                SET_LANG_LOADING: function (state, p) { Vue.set(state.langLoading, p.instance, p.value); },
                // Язык получил сохранённый перевод — вкладка больше не «пустая».
                MARK_TRANSLATED: function (state, p) {
                    var marks = state.translated[p.instance];
                    if (!marks) { Vue.set(state.translated, p.instance, {}); marks = state.translated[p.instance]; }
                    Vue.set(marks, p.lang, true);
                }
            },
            actions: {
                togglePicker: function (ctx) { ctx.commit('SET_PICKING', !ctx.state.picking); },

                /**
                 * Добавляет экземпляр блока. Значения — из default полей; на
                 * сервер ничего не уходит до «Сохранить», поэтому экземпляр
                 * сразу получает собственный id (см. newInstanceId).
                 */
                addInstance: function (ctx, blockCode) {
                    var config = ctx.state.configByCode[blockCode];
                    if (!config || !ctx.getters.canAdd(blockCode)) { return null; }

                    var instanceId = newInstanceId(config);
                    ctx.commit('ADD_INSTANCE', {
                        instanceId: instanceId,
                        blockCode: blockCode,
                        values: blankRow(config.fields)
                    });
                    // Пикер не закрываем: блоки часто добавляют пачкой, а после
                    // каждого добавления открывать список заново — лишний клик.
                    // Закрыть можно кнопкой «Отмена».
                    return instanceId;
                },

                /**
                 * Удаляет экземпляр. Несохранённый убираем только из состояния —
                 * на сервере его ещё нет.
                 */
                removeInstance: function (ctx, instanceId) {
                    var blockCode = ctx.getters.blockCodeOf(instanceId);
                    var isNew     = !!ctx.state.unsaved[instanceId];

                    if (isNew || !options.remove || !BX.ajax || !BX.ajax.runAction) {
                        ctx.commit('REMOVE_INSTANCE', { instanceId: instanceId });
                        return Promise.resolve(true);
                    }

                    ctx.commit('SET_SAVING', { instance: instanceId, value: true });
                    return BX.ajax.runAction(options.remove, {
                        data: {
                            blockCode: blockCode,
                            entityType: ctx.state.entity.type,
                            entityId: ctx.state.entity.id,
                            instanceId: instanceId
                        }
                    }).then(function () {
                        ctx.commit('REMOVE_INSTANCE', { instanceId: instanceId });
                        return true;
                    }, function () {
                        ctx.commit('SET_SAVING', { instance: instanceId, value: false });
                        ctx.commit('SET_MESSAGE', {
                            instance: instanceId,
                            message: { type: 'error', text: t('STRUCTURA_JS_BLOCK_DELETE_FAILED') }
                        });
                        return false;
                    });
                },

                /**
                 * Переставляет экземпляр и сохраняет порядок на сервере.
                 * Несохранённые экземпляры в reorder не отправляем — их на
                 * сервере ещё нет, порядок закрепится при их сохранении.
                 */
                moveInstance: function (ctx, p) {
                    ctx.commit('MOVE_INSTANCE', { from: p.from, to: p.to });

                    if (!options.reorder || !BX.ajax || !BX.ajax.runAction) { return Promise.resolve(true); }

                    var ids = ctx.state.instances
                        .filter(function (i) { return !ctx.state.unsaved[i.instanceId]; })
                        .map(function (i) { return i.instanceId; });
                    if (!ids.length) { return Promise.resolve(true); }

                    return BX.ajax.runAction(options.reorder, {
                        data: {
                            entityType: ctx.state.entity.type,
                            entityId: ctx.state.entity.id,
                            instanceIds: ids
                        }
                    }).then(function () { return true; }, function () { return false; });
                },

                save: function (ctx, instanceId) {
                    var blockCode = ctx.getters.blockCodeOf(instanceId);
                    var config    = ctx.getters.configOf(instanceId);
                    var values    = ctx.state.values[instanceId];

                    // 1. Клиентская валидация (быстрая обратная связь).
                    // К ней добавляются поля с нечитаемым вводом: по значению
                    // они выглядят пустыми, и без этого блок сохранился бы
                    // пустым «успешно» — см. badInputErrors.
                    var errors = validateBlock(config, values);
                    // Отметки по вводу И осмотр самих полей: у дат события
                    // приходят не всегда, поэтому решает DOM (см. scanBadInputs).
                    var marked = Object.assign({}, ctx.state.badInput[instanceId], scanBadInputs(instanceId));
                    var bad    = badInputErrors(marked);
                    Object.keys(bad).forEach(function (path) {
                        errors[path] = (errors[path] || []).concat(bad[path]);
                    });

                    if (Object.keys(errors).length) {
                        ctx.commit('SET_ERRORS', { instance: instanceId, errors: errors });
                        ctx.commit('SET_MESSAGE', { instance: instanceId, message: { type: 'error', text: t('STRUCTURA_JS_FIX_FIELD_ERRORS') } });
                        return Promise.resolve(false);
                    }
                    ctx.commit('SET_ERRORS', { instance: instanceId, errors: {} });

                    // 2. Серверное сохранение (если доступен ajax-роутер).
                    if (!options.save || !BX.ajax || !BX.ajax.runAction) {
                        ctx.commit('MARK_SAVED', { instanceId: instanceId });
                        ctx.commit('SET_MESSAGE', { instance: instanceId, message: { type: 'ok', text: t('STRUCTURA_JS_DEMO_CHECKED') } });
                        return Promise.resolve(true);
                    }

                    ctx.commit('SET_SAVING', { instance: instanceId, value: true });
                    var act  = ctx.state.activity[instanceId] || { active: true, from: '', to: '' };
                    // Сохранение идёт по одному языку — по активной вкладке. Её
                    // же язык решает, куда лягут значения (база или перевод) и в
                    // историю какого языка попадёт снимок.
                    var lang = ctx.getters.langOf(instanceId);
                    return BX.ajax.runAction(options.save, {
                        data: {
                            blockCode: blockCode,
                            entityType: ctx.state.entity.type,
                            entityId: ctx.state.entity.id,
                            instanceId: instanceId,
                            values: values,
                            // Значения ещё раз строкой JSON: form-кодирование
                            // BX.ajax выбрасывает пустой массив (снятые флажки,
                            // очищенный множественный Select, пустой Repeater) —
                            // на сервер он не приходит, и getWithDefaults вернул
                            // бы умолчание вместо сохранённой пустоты. Сервер
                            // предпочитает эту строку (см. saveAction).
                            valuesJson: JSON.stringify(values),
                            language: lang,
                            // Активность едет в том же запросе (1/0 — чтобы bool не
                            // перепутался: "false" строкой на сервере был бы истиной).
                            active: act.active ? 1 : 0,
                            activeFrom: act.from || '',
                            activeTo: act.to || ''
                        }
                    }).then(function (response) {
                        ctx.commit('SET_SAVING', { instance: instanceId, value: false });
                        ctx.commit('MARK_SAVED', { instanceId: instanceId });
                        // Перевод сохранён — вкладка перестала быть пустой.
                        if (lang !== ctx.state.languageFallback) {
                            ctx.commit('MARK_TRANSLATED', { instance: instanceId, lang: lang });
                        }

                        var data   = (response && response.data) || {};
                        var denied = data.denied || {};
                        var paths  = Object.keys(denied);

                        // Сервер мог откатить запрещённые поля — показываем то,
                        // что реально легло в БД, а не то, что ввели.
                        ctx.commit('SET_SAVED_VALUES', { instance: instanceId, values: data.values });

                        if (paths.length) {
                            // Причина — от сервера: она уже человекочитаемая
                            // («Доступно только группам: Администраторы»).
                            ctx.commit('SET_MESSAGE', {
                                instance: instanceId,
                                message: {
                                    type: 'warn',
                                    text: t('STRUCTURA_JS_SAVED_PARTIAL', { '#COUNT#': paths.length, '#FIELD#': denied[paths[0]] }) +
                                        (paths.length > 1 ? t('STRUCTURA_JS_SAVED_PARTIAL_MORE') : '')
                                }
                            });
                        } else {
                            ctx.commit('SET_MESSAGE', { instance: instanceId, message: { type: 'ok', text: t('STRUCTURA_JS_SAVED') } });
                        }
                        // Значения сменились — открытый diff сравнивал бы со старым.
                        ctx.commit('CLOSE_REV_DIFF', { instance: instanceId });
                        // Если открыта панель истории — подтянуть свежую ревизию.
                        if (ctx.state.revOpen[instanceId]) { ctx.dispatch('loadRevisions', instanceId); }
                        return true;
                    }, function (response) {
                        ctx.commit('SET_SAVING', { instance: instanceId, value: false });
                        // Ошибку показываем текстом, а не прячем за общим «Ошибка
                        // сохранения»: отказ по правам, ошибка БД и упавший PHP
                        // не привязаны ни к какому полю и иначе не видны нигде.
                        var split = splitServerErrors(response && response.errors);
                        ctx.commit('SET_ERRORS', { instance: instanceId, errors: split.byPath });
                        ctx.commit('SET_MESSAGE', { instance: instanceId, message: { type: 'error', text: split.text } });
                        return false;
                    });
                },

                /**
                 * Первая страница истории (мета, без значений). Список сбрасывается:
                 * вызывается при открытии панели и после save/rollback, когда сверху
                 * появляется новая ревизия.
                 */
                loadRevisions: function (ctx, instanceId) {
                    if (!options.revisions || !BX.ajax || !BX.ajax.runAction) { return Promise.resolve([]); }
                    ctx.commit('SET_REV_LOADING', { instance: instanceId, value: true });
                    return BX.ajax.runAction(options.revisions, {
                        data: {
                            blockCode: ctx.getters.blockCodeOf(instanceId),
                            entityType: ctx.state.entity.type,
                            entityId: ctx.state.entity.id,
                            instanceId: instanceId,
                            // История по-языковая: вкладка показывает ревизии
                            // своего языка, иначе откат «этой» записи уехал бы
                            // в чужой слой.
                            language: ctx.getters.langOf(instanceId),
                            limit: REV_PAGE_SIZE,
                            offset: 0
                        }
                    }).then(function (r) {
                        var d    = (r && r.data) ? r.data : {};
                        var list = d.revisions || [];
                        ctx.commit('SET_REVISIONS', { instance: instanceId, list: list, total: d.total || list.length });
                        ctx.commit('SET_REV_LOADING', { instance: instanceId, value: false });
                        return list;
                    }, function () {
                        ctx.commit('SET_REV_LOADING', { instance: instanceId, value: false });
                        return [];
                    });
                },

                /**
                 * Догрузить следующую страницу истории («Показать ещё»).
                 * Смещение — по числу уже показанных: список отсортирован по ID
                 * убыв., а новые ревизии добавляются только сверху и только после
                 * save/rollback, которые список сбрасывают.
                 */
                loadMoreRevisions: function (ctx, instanceId) {
                    if (!options.revisions || !BX.ajax || !BX.ajax.runAction) { return Promise.resolve([]); }
                    if (ctx.getters.historyMoreLoading(instanceId)) { return Promise.resolve([]); }

                    var offset = (ctx.state.revisions[instanceId] || []).length;
                    ctx.commit('SET_REV_MORE_LOADING', { instance: instanceId, value: true });

                    return BX.ajax.runAction(options.revisions, {
                        data: {
                            blockCode: ctx.getters.blockCodeOf(instanceId),
                            entityType: ctx.state.entity.type,
                            entityId: ctx.state.entity.id,
                            instanceId: instanceId,
                            language: ctx.getters.langOf(instanceId),
                            limit: REV_PAGE_SIZE,
                            offset: offset
                        }
                    }).then(function (r) {
                        var d    = (r && r.data) ? r.data : {};
                        var list = d.revisions || [];
                        ctx.commit('APPEND_REVISIONS', {
                            instance: instanceId,
                            list: list,
                            total: d.total || (offset + list.length)
                        });
                        ctx.commit('SET_REV_MORE_LOADING', { instance: instanceId, value: false });
                        return list;
                    }, function () {
                        ctx.commit('SET_REV_MORE_LOADING', { instance: instanceId, value: false });
                        return [];
                    });
                },

                /**
                 * Предпросмотр отката: тянет значения ревизии и считает разницу
                 * с текущими значениями формы. Повторный клик — свернуть.
                 */
                togglePreview: function (ctx, p) {
                    var open = ctx.getters.previewOpen(p.instance, p.revisionId);
                    if (open) {
                        ctx.commit('CLOSE_REV_DIFF', { instance: p.instance });
                        return Promise.resolve([]);
                    }
                    if (!options.revision || !BX.ajax || !BX.ajax.runAction) { return Promise.resolve([]); }

                    // Пустой diff до ответа — панель показывает «Загрузка…».
                    ctx.commit('SET_REV_DIFF', { instance: p.instance, id: p.revisionId, diff: null });

                    return BX.ajax.runAction(options.revision, {
                        data: { revisionId: p.revisionId }
                    }).then(function (r) {
                        var rev = (r && r.data && r.data.revision) ? r.data.revision : null;
                        if (!rev) { return []; }
                        var diff = diffValues(
                            ctx.getters.configOf(p.instance),
                            ctx.state.values[p.instance] || {},
                            rev.values || {}
                        );
                        ctx.commit('SET_REV_DIFF', { instance: p.instance, id: p.revisionId, diff: diff });
                        return diff;
                    }, function () {
                        ctx.commit('CLOSE_REV_DIFF', { instance: p.instance });
                        return [];
                    });
                },

                /**
                 * Переключение языковой вкладки экземпляра.
                 *
                 * В форме живут значения одного языка: они уходят на сервер
                 * целиком и целиком же приходят обратно. Поэтому смена вкладки —
                 * это перезагрузка значений, а несохранённые правки прежней
                 * вкладки при ней теряются: спрашиваем подтверждение, как при
                 * удалении и откате.
                 *
                 * Несохранённый экземпляр переключать некуда: перевод требует
                 * размещения в базе (иначе его никто не увидит — список блоков
                 * идёт по базовой таблице), и сервер такой save отвергнет.
                 */
                switchLanguage: function (ctx, p) {
                    var instanceId = p.instance;
                    var lang       = p.lang;
                    var current    = ctx.getters.langOf(instanceId);

                    if (lang === current || ctx.getters.langLoading(instanceId)) {
                        return Promise.resolve(false);
                    }

                    if (ctx.state.unsaved[instanceId] && lang !== ctx.state.languageFallback) {
                        ctx.commit('SET_MESSAGE', {
                            instance: instanceId,
                            message: {
                                type: 'warn',
                                text: t('STRUCTURA_JS_SAVE_DEFAULT_FIRST')
                            }
                        });
                        return Promise.resolve(false);
                    }

                    if (
                        ctx.getters.isDirty(instanceId)
                        && !window.confirm(
                            t('STRUCTURA_JS_UNSAVED_SWITCH', { '#LANG#': current })
                        )
                    ) {
                        return Promise.resolve(false);
                    }

                    // Без сервера значений другого языка взять негде, а показать
                    // прежние значит соврать, что это перевод. Молчать тоже
                    // нельзя — кнопка выглядела бы сломанной.
                    if (!options.values || !BX.ajax || !BX.ajax.runAction) {
                        ctx.commit('SET_MESSAGE', {
                            instance: instanceId,
                            message: { type: 'warn', text: t('STRUCTURA_JS_LANG_SWITCH_DEMO') }
                        });
                        return Promise.resolve(false);
                    }

                    ctx.commit('SET_LANG_LOADING', { instance: instanceId, value: true });

                    return BX.ajax.runAction(options.values, {
                        data: {
                            blockCode: ctx.getters.blockCodeOf(instanceId),
                            entityType: ctx.state.entity.type,
                            entityId: ctx.state.entity.id,
                            instanceId: instanceId,
                            language: lang
                        }
                    }).then(function (r) {
                        var d = (r && r.data) ? r.data : {};

                        ctx.commit('SET_LANG', { instance: instanceId, lang: lang });
                        ctx.commit('REPLACE_VALUES', { instance: instanceId, values: d.values || {} });
                        ctx.commit('SET_ERRORS', { instance: instanceId, errors: {} });
                        ctx.commit('CLEAR_BAD_INPUT', { instance: instanceId });
                        // Значения сменились — открытый diff сравнивал бы со старым,
                        // да и история теперь другого языка.
                        ctx.commit('CLOSE_REV_DIFF', { instance: instanceId });
                        ctx.commit('SET_MESSAGE', { instance: instanceId, message: null });
                        ctx.commit('SET_LANG_LOADING', { instance: instanceId, value: false });

                        // Сервер знает, переводили ли этот язык (по values этого
                        // не видно: там подставлены умолчания). Синхронизируем
                        // пометку — по ней рисуется и «не переведён», и кнопка
                        // «Заполнить из базы».
                        if (d.filled && lang !== ctx.state.languageFallback) {
                            ctx.commit('MARK_TRANSLATED', { instance: instanceId, lang: lang });
                        }

                        if (ctx.state.revOpen[instanceId]) { ctx.dispatch('loadRevisions', instanceId); }

                        return true;
                    }, function (response) {
                        ctx.commit('SET_LANG_LOADING', { instance: instanceId, value: false });
                        var split = splitServerErrors(response && response.errors, t('STRUCTURA_JS_LANG_LOAD_FAILED'));
                        ctx.commit('SET_MESSAGE', {
                            instance: instanceId,
                            message: { type: 'error', text: split.text }
                        });
                        return false;
                    });
                },

                /**
                 * Заполнить пустую вкладку перевода значениями языка по умолчанию.
                 *
                 * Копирует сервер (как клонирование строки): значение файлового
                 * поля — ID файла, и общий ID у двух языков означал бы, что
                 * удаление картинки на одном убивает её на другом. Ответ в БД не
                 * пишется — блок помечается изменённым, а записывает обычное
                 * «Сохранить».
                 */
                prefillLanguage: function (ctx, instanceId) {
                    var lang = ctx.getters.langOf(instanceId);

                    if (lang === ctx.state.languageFallback) { return Promise.resolve(false); }
                    if (!options.prefill || !BX.ajax || !BX.ajax.runAction) { return Promise.resolve(false); }

                    ctx.commit('SET_LANG_LOADING', { instance: instanceId, value: true });

                    return BX.ajax.runAction(options.prefill, {
                        data: {
                            blockCode: ctx.getters.blockCodeOf(instanceId),
                            entityType: ctx.state.entity.type,
                            entityId: ctx.state.entity.id,
                            instanceId: instanceId,
                            language: lang
                        }
                    }).then(function (r) {
                        var d = (r && r.data) ? r.data : {};
                        ctx.commit('SET_LANG_LOADING', { instance: instanceId, value: false });

                        if (!d.values) { return false; }

                        ctx.commit('REPLACE_VALUES', { instance: instanceId, values: d.values });
                        // REPLACE_VALUES снимает «изменён» (он для отката), а
                        // здесь правка как раз не сохранена — помечаем обратно,
                        // иначе человек ушёл бы со страницы, думая, что записано.
                        ctx.commit('MARK_DIRTY', { instance: instanceId });
                        ctx.commit('SET_MESSAGE', {
                            instance: instanceId,
                            message: {
                                type: 'warn',
                                text: t('STRUCTURA_JS_PREFILLED', { '#LANG#': ctx.state.languageFallback })
                            }
                        });

                        return true;
                    }, function (response) {
                        ctx.commit('SET_LANG_LOADING', { instance: instanceId, value: false });
                        var split = splitServerErrors(response && response.errors, t('STRUCTURA_JS_PREFILL_FAILED'));
                        ctx.commit('SET_MESSAGE', {
                            instance: instanceId,
                            message: { type: 'error', text: split.text }
                        });
                        return false;
                    });
                },

                // Открыть/скрыть панель истории (при открытии — лениво загрузить).
                toggleHistory: function (ctx, instanceId) {
                    var open = !ctx.state.revOpen[instanceId];
                    ctx.commit('SET_REV_OPEN', { instance: instanceId, value: open });
                    return open ? ctx.dispatch('loadRevisions', instanceId) : Promise.resolve();
                },

                // Откат к ревизии. Сервер создаёт новую ревизию и возвращает значения.
                rollback: function (ctx, p) {
                    if (!options.rollback || !BX.ajax || !BX.ajax.runAction) { return Promise.resolve(false); }
                    ctx.commit('SET_SAVING', { instance: p.instance, value: true });
                    return BX.ajax.runAction(options.rollback, {
                        data: {
                            blockCode: ctx.getters.blockCodeOf(p.instance),
                            entityType: ctx.state.entity.type,
                            entityId: ctx.state.entity.id,
                            instanceId: p.instance,
                            revisionId: p.revisionId
                        }
                    }).then(function (r) {
                        ctx.commit('SET_SAVING', { instance: p.instance, value: false });
                        if (r && r.data && r.data.values) {
                            ctx.commit('REPLACE_VALUES', { instance: p.instance, values: r.data.values });
                        }
                        ctx.commit('SET_ERRORS', { instance: p.instance, errors: {} });
                        ctx.commit('SET_MESSAGE', { instance: p.instance, message: { type: 'ok', text: t('STRUCTURA_JS_ROLLBACK_DONE') } });
                        // Значения сменились — открытый diff сравнивал бы со старым.
                        ctx.commit('CLOSE_REV_DIFF', { instance: p.instance });
                        return ctx.dispatch('loadRevisions', p.instance).then(function () { return true; });
                    }, function (response) {
                        ctx.commit('SET_SAVING', { instance: p.instance, value: false });
                        // Та же беда, что у сохранения: без разбора общая ошибка
                        // (нет прав, чужая ревизия, ошибка БД) не видна нигде.
                        var split = splitServerErrors(response && response.errors, t('STRUCTURA_JS_ROLLBACK_ERROR'));
                        ctx.commit('SET_ERRORS', { instance: p.instance, errors: split.byPath });
                        ctx.commit('SET_MESSAGE', { instance: p.instance, message: { type: 'error', text: split.text } });
                        return false;
                    });
                }
            }
        });
    }

    /* ------------------------------------------------------------------ *
     *  Обязательные блоки
     * ------------------------------------------------------------------ */
    /**
     * Обязательные блоки, которых на сущности нет.
     *
     * Это предупреждение, а не запрет: сохранению оно не мешает — ни блока, ни
     * самого элемента. Серверной проверки у него нет: сервер, который «решает»,
     * но ничего не запрещает, был бы вторым источником правды на пустом месте.
     *
     * Что здесь от сервера: флаг `required` (из config.php блока) и сам список
     * blocks — он уже отфильтрован по visibleOn для этой сущности
     * (Loader::forEntity), поэтому недоступный элементу блок обязательным для
     * него не станет. Клиент лишь вычитает одно из другого, вживую.
     *
     * Сверяем по blockCode, а не по instanceId: обязателен блок, а какая у него
     * копия и сколько их (->multiple()) — неважно, хватает первой.
     *
     * @param {Array} blocks    конфигурации блоков, доступных сущности
     * @param {Array} instances экземпляры на сущности ({ blockCode, ... })
     * @returns {Array} конфигурации недостающих блоков, в порядке blocks
     */
    function missingRequired(blocks, instances) {
        var present = {};
        (instances || []).forEach(function (m) { present[m.blockCode] = true; });

        return (blocks || []).filter(function (b) {
            return !!b.required && !present[b.code];
        });
    }

    /* ------------------------------------------------------------------ *
     *  Корневое приложение
     * ------------------------------------------------------------------ */
    /**
     * Карточка одного экземпляра блока. Всё состояние адресуется instanceId,
     * поэтому две копии одного блока на элементе полностью независимы:
     * свои значения, ошибки, сообщения и своя история.
     */
    Vue.component('structura-instance', {
        mixins: [dragReorder],
        props: {
            meta:  { type: Object, required: true },   // { instanceId, blockCode }
            index: { type: Number, required: true },
            total: { type: Number, required: true }
        },
        computed: {
            id:     function () { return this.meta.instanceId; },
            config: function () { return this.$store.getters.configOf(this.id); },
            isNew:  function () { return !!this.$store.state.unsaved[this.id]; },
            // Политика на самом блоке: правка, удаление, перестановка и откат
            // недоступны. Решение принял сервер (config.editable), клиент про
            // группы пользователя ничего не знает.
            blockLocked: function () {
                var c = this.config;
                return !!c && c.editable === false;
            },
            // У несохранённого экземпляра истории ещё нет — панель прячем.
            hasHistory: function () {
                var c = this.config;
                return !!(c && c.revisions && c.revisions.enabled) && !this.isNew;
            },
            // Свёрнута ли карточка (по умолчанию — да).
            collapsed: function () { return this.$store.getters.isCollapsed(this.id); },
            // Есть ли несохранённые правки. У ещё не сохранённого блока не
            // показываем: у него свой статус «не сохранён».
            dirty: function () { return !this.isNew && this.$store.getters.isDirty(this.id); },
            // Подпись «создал/изменил». Имя приходит с сервера (если у текущего
            // пользователя есть право смотреть пользователей), иначе — числовой
            // id. У нового (несохранённого) экземпляра автора ещё нет.
            authorship: function () {
                var m = this.meta;
                if (!m || this.isNew) { return ''; }
                var who = function (name, id) { return name || (id ? t('STRUCTURA_JS_USER_SHORT') + id : null); };
                var when = function (dt) { return dt ? ' (' + dt + ')' : ''; };
                var parts = [];
                var c = who(m.createdName, m.createdBy);
                if (c) { parts.push(t('STRUCTURA_JS_AUTHOR_CREATED') + c + when(m.createdAt)); }
                var u = who(m.updatedName, m.updatedBy);
                if (u) { parts.push(t('STRUCTURA_JS_AUTHOR_UPDATED') + u + when(m.updatedAt)); }
                return parts.join(' · ');
            },
            // Активность экземпляра (флаг + окно дат для datetime-local).
            activity: function () {
                return this.$store.state.activity[this.id] || { active: true, from: '', to: '', now: true };
            },
            // Ошибки окна дат. Путь у них служебный (`_activity:from`), полем
            // быть не может — двоеточие в кодах полей запрещено, — поэтому с
            // ошибками полей не пересекается.
            activityErrors: function () {
                return this.$store.getters.errorsAt(this.id, '_activity:from')
                    .concat(this.$store.getters.errorsAt(this.id, '_activity:to'));
            },
            // --- Языковые вкладки ---------------------------------------
            // Языки карточки (пусто — блок одноязычный либо язык один).
            langTabs: function () { return this.$store.getters.langTabs(this.id); },
            lang:     function () { return this.$store.getters.langOf(this.id); },
            fallback: function () { return this.$store.state.languageFallback; },
            // Базовый слой: только на нём правятся общие для всех языков
            // активность и окно дат (у перевода их попросту нет — см. ValueService).
            isBaseLang:  function () { return this.$store.getters.isBaseLang(this.id); },
            langLoading: function () { return this.$store.getters.langLoading(this.id); },
            // Открытая вкладка перевода, которого ещё нет: показываем это прямо,
            // а не пустыми полями — «не переведён» и «переведён пустым» для
            // сайта разные вещи (кросс-языкового фолбэка по полям нет).
            langEmpty: function () {
                return this.langTabs.length > 0
                    && !this.isNew
                    && !this.$store.getters.isTranslated(this.id, this.lang);
            },
            // Короткая пометка в шапке (видна и у свёрнутой карточки): выключен
            // вручную или вне окна дат — значит на сайте сейчас не показывается.
            activityBadge: function () {
                if (this.isNew) { return ''; }
                var a = this.activity;
                if (!a.active) { return t('STRUCTURA_JS_BADGE_OFF'); }
                if (a.now === false) { return t('STRUCTURA_JS_BADGE_OUTDATED'); }
                return '';
            }
        },
        methods: {
            // Перетаскивание карточек: список один на сущность (ключ постоянный),
            // перестановка идёт тем же путём, что и кнопки ↑/↓ (moveInstance —
            // сразу в БД через reorder). Источник drag — отдельная ручка (⠿) в
            // шапке, как у строк контейнеров: тащить за всю карточку мешало
            // править поля и перетаскивать вложенные строки. Заблокированную
            // карточку не тащим (draggable=false), да и сервер отверг бы
            // перестановку с запертым блоком (reorderAction проверяет политику
            // каждого экземпляра).
            dragKind: function () { return 'instance'; },
            dragKey:  function () { return 'instances'; },
            dragMoved: function (from, to) {
                this.$store.dispatch('moveInstance', { from: from, to: to });
            },
            setActive: function (v) { this.$store.commit('SET_ACTIVITY', { instanceId: this.id, active: !!v }); },
            setFrom:   function (v) { this.$store.commit('SET_ACTIVITY', { instanceId: this.id, from: v }); },
            setTo:     function (v) { this.$store.commit('SET_ACTIVITY', { instanceId: this.id, to: v }); },
            toggle:  function () { this.$store.commit('TOGGLE_COLLAPSE', { instanceId: this.id }); },
            // Переключение языка перезагружает значения с сервера (в форме
            // всегда значения одного языка) — см. действие switchLanguage.
            switchLang: function (lang) {
                return this.$store.dispatch('switchLanguage', { instance: this.id, lang: lang });
            },
            translated: function (lang) { return this.$store.getters.isTranslated(this.id, lang); },
            prefill: function () { return this.$store.dispatch('prefillLanguage', this.id); },
            save:    function () { return this.$store.dispatch('save', this.id); },
            saving:  function () { return this.$store.getters.isSaving(this.id); },
            message: function () { return this.$store.getters.messageOf(this.id); },
            remove: function () {
                var what = this.isNew ? t('STRUCTURA_JS_REMOVE_NEW') : t('STRUCTURA_JS_REMOVE_SAVED');
                if (window.confirm(what)) { this.$store.dispatch('removeInstance', this.id); }
            },
            move: function (delta) {
                this.$store.dispatch('moveInstance', { from: this.index, to: this.index + delta });
            },
            toggleHistory: function () { return this.$store.dispatch('toggleHistory', this.id); },
            historyOpen: function () { return this.$store.getters.historyOpen(this.id); },
            historyLoading: function () { return this.$store.getters.historyLoading(this.id); },
            historyList: function () { return this.$store.getters.historyList(this.id); },
            historyRest: function () { return this.$store.getters.historyRest(this.id); },
            historyMoreLoading: function () { return this.$store.getters.historyMoreLoading(this.id); },
            loadMore: function () { return this.$store.dispatch('loadMoreRevisions', this.id); },
            previewOpen: function (rid) { return this.$store.getters.previewOpen(this.id, rid); },
            previewDiff: function (rid) { return this.$store.getters.previewDiff(this.id, rid); },
            togglePreview: function (rid) {
                return this.$store.dispatch('togglePreview', { instance: this.id, revisionId: rid });
            },
            rollback: function (rid) {
                if (window.confirm(t('STRUCTURA_JS_ROLLBACK_CONFIRM'))) {
                    this.$store.dispatch('rollback', { instance: this.id, revisionId: rid });
                }
            }
        },
        template:
            // data-st-instance — якорь для осмотра полей карточки перед
            // сохранением (scanBadInputs): по нему находится её поддерево DOM.
            '<div class="st-block"' +
            '  :class="{\'st-block--collapsed\': collapsed, \'st-dragging\': isDragged(index), \'st-drag-over\': isDropTarget(index)}"' +
            '  @dragover="dragOver($event, index)" @drop="dropAt($event, index)" @dragleave="dragLeaveList()"' +
            '  :data-st-instance="id" v-if="config">' +
            '  <div class="st-block-head" @click="toggle()">' +
            '    <span class="st-drag-handle" :draggable="!blockLocked" :title="t(\'STRUCTURA_JS_DRAG_BLOCK\')"' +
            '      @click.stop @dragstart="dragStart($event, index)" @dragend="dragEnd()">⠿</span>' +
            '    <button type="button" class="st-block-caret"' +
            '      :title="collapsed ? t(\'STRUCTURA_JS_EXPAND\') : t(\'STRUCTURA_JS_COLLAPSE\')">' +
            '      <span class="st-caret" :class="{\'st-caret--open\': !collapsed}">▸</span></button>' +
            '    <h3 class="st-block-title">{{ config.name }}</h3>' +
            '    <span class="st-block-ver">v{{ config.version }}</span>' +
            '    <span class="st-block-new" v-if="isNew">{{ t(\'STRUCTURA_JS_BLOCK_NEW\') }}</span>' +
            // Язык виден и у свёрнутой карточки: иначе после сворачивания
            // непонятно, какой язык правится.
            '    <span class="st-block-lang" v-if="langTabs.length">{{ lang }}</span>' +
            '    <span class="st-block-dirty" v-if="dirty">{{ t(\'STRUCTURA_JS_BLOCK_DIRTY\') }}</span>' +
            '    <span class="st-block-off" v-if="activityBadge" :title="t(\'STRUCTURA_JS_BLOCK_OFF_TITLE\')">{{ activityBadge }}</span>' +
            '    <span class="st-block-author" v-if="authorship" :title="authorship">{{ authorship }}</span>' +
            // Свёрнутый блок скрывает поля и статус сохранения — показываем
            // сообщение прямо в шапке, иначе результат Save остался бы не виден.
            '    <span class="st-block-msg" v-if="collapsed && message()"' +
            '      :class="\'st-message--\' + message().type">{{ message().text }}</span>' +
            // Клик по кнопкам действий не должен сворачивать блок.
            '    <div class="st-block-acts" @click.stop>' +
            '      <button type="button" class="st-move" :disabled="index === 0 || blockLocked"' +
            '        :title="t(\'STRUCTURA_JS_MOVE_UP\')" @click="move(-1)">↑</button>' +
            '      <button type="button" class="st-move" :disabled="index === total - 1 || blockLocked"' +
            '        :title="t(\'STRUCTURA_JS_MOVE_DOWN\')" @click="move(1)">↓</button>' +
            '      <button type="button" class="st-remove-block" :title="t(\'STRUCTURA_JS_DELETE_BLOCK\')"' +
            '        :disabled="blockLocked" @click="remove()">✕</button>' +
            '    </div>' +
            '  </div>' +
            // Тело карточки скрывается в свёрнутом состоянии. v-if, а не v-show:
            // поля ссылочного типа грузят справочники в mounted, и держать их
            // смонтированными у свёрнутых блоков — это те самые лишние запросы,
            // от которых длинный список и тормозит. Значения живут в Vuex, так
            // что при повторном разворачивании ничего не теряется.
            '  <div class="st-block-body" v-if="!collapsed">' +
            // Языковые вкладки переводимого блока. Вкладка выбирает строку
            // хранения: fallback — базовая запись, остальные — перевод. Поэтому
            // переключение перезагружает значения, а сохранение идёт по одному
            // языку за раз.
            '  <div class="st-langs" v-if="langTabs.length">' +
            '    <button type="button" class="st-lang" v-for="l in langTabs" :key="l"' +
            '      :class="{\'st-lang--on\': l === lang, \'st-lang--empty\': !translated(l)}"' +
            '      :disabled="langLoading || (isNew && l !== fallback)"' +
            '      :title="l === fallback ? t(\'STRUCTURA_JS_LANG_DEFAULT_TITLE\') :' +
            '        (translated(l) ? t(\'STRUCTURA_JS_TRANSLATED_TITLE\') : t(\'STRUCTURA_JS_NOT_TRANSLATED_TITLE\'))"' +
            '      @click="switchLang(l)">{{ l }}' +
            '      <span class="st-lang-mark" v-if="!translated(l)">•</span></button>' +
            '    <span class="st-lang-note" v-if="isNew">' +
            '      {{ t(\'STRUCTURA_JS_TRANSLATIONS_AFTER_SAVE\') }}</span>' +
            '    <span class="st-lang-note" v-else-if="langLoading">{{ t(\'STRUCTURA_JS_LANG_LOADING\') }}</span>' +
            '    <span class="st-lang-note" v-else-if="!isBaseLang">' +
            '      {{ t(\'STRUCTURA_JS_ACTIVITY_SHARED\') }}</span>' +
            '  </div>' +
            // Пустая вкладка перевода. Говорим прямо: сайту нечего показать на
            // этом языке, пока перевод не сохранён (по полям фолбэка нет).
            '  <div class="st-lang-empty" v-if="langEmpty">' +
            '    <span>{{ t(\'STRUCTURA_JS_TR_EMPTY_A\') }}«{{ lang }}»{{ t(\'STRUCTURA_JS_TR_EMPTY_B\') }}«{{ fallback }}».</span>' +
            '    <button type="button" class="st-lang-prefill" :disabled="langLoading || blockLocked"' +
            '      @click="prefill()">{{ t(\'STRUCTURA_JS_PREFILL_BTN\') }}«{{ fallback }}»</button>' +
            '  </div>' +
            // Активность экземпляра: выключить на время (не удаляя) и/или задать
            // окно дат. Едет в общее «Сохранить». Даты пустые — без ограничения.
            //
            // На вкладке перевода поля заблокированы: активность, окно дат и
            // порядок живут в базовой записи и общие для всех языков — сервер
            // их из перевода не пишет вообще, и редактируемый вид тут врал бы.
            '  <div class="st-activity" :class="{\'st-invalid\': activityErrors.length}">' +
            '    <label class="st-activity-flag">' +
            '      <input type="checkbox" :checked="activity.active" :disabled="blockLocked || !isBaseLang"' +
            '        @change="setActive($event.target.checked)"> {{ t(\'STRUCTURA_JS_ACTIVE_LABEL\') }}' +
            '    </label>' +
            // Даты окна проходят ту же проверку на нечитаемый ввод, что и поля:
            // дата без времени даёт пустое значение, и раньше блок сохранялся
            // «успешно», а окно молча оставалось незаданным.
            '    <label class="st-activity-date">{{ t(\'STRUCTURA_JS_DATE_FROM\') }}' +
            '      <input type="datetime-local" :value="activity.from" :disabled="blockLocked || !isBaseLang"' +
            '        data-st-path="_activity:from" data-st-type="datetime"' +
            '        @input="setFrom($event.target.value)"></label>' +
            '    <label class="st-activity-date">{{ t(\'STRUCTURA_JS_DATE_TO\') }}' +
            '      <input type="datetime-local" :value="activity.to" :disabled="blockLocked || !isBaseLang"' +
            '        data-st-path="_activity:to" data-st-type="datetime"' +
            '        @input="setTo($event.target.value)"></label>' +
            '    <span class="st-activity-hint" v-if="!activity.active">{{ t(\'STRUCTURA_JS_NOT_SHOWN\') }}</span>' +
            '    <div class="st-errors" v-if="activityErrors.length">' +
            '      <span v-for="(e,i) in activityErrors" :key="i">{{ e }}</span></div>' +
            '  </div>' +
            // Блок под запретом рендерится со значениями, но только для чтения:
            // политика — это право править, а право видеть элемент уже проверено
            // на сервере (element_read).
            '  <structura-lock v-if="blockLocked" :reason="config.lockReason"></structura-lock>' +
            '  <fieldset class="st-locked-block" :disabled="blockLocked">' +
            '    <structura-field v-for="f in config.fields" :key="f.code"' +
            '      :field="f" :instance="id" :path="f.code"></structura-field>' +
            '  </fieldset>' +
            '  <div class="st-actions">' +
            '    <button type="button" class="st-save" :disabled="saving() || blockLocked"' +
            '      @click="save()">{{ t(\'STRUCTURA_JS_SAVE\') }}</button>' +
            '    <button type="button" class="st-history-btn" v-if="hasHistory"' +
            '      :class="{\'st-history-btn--on\': historyOpen()}"' +
            '      @click="toggleHistory()">{{ t(\'STRUCTURA_JS_HISTORY\') }}</button>' +
            '    <span class="st-message" v-if="message()"' +
            '      :class="\'st-message--\' + message().type">{{ message().text }}</span>' +
            '  </div>' +
            '  <div class="st-history" v-if="hasHistory && historyOpen()">' +
            '    <div class="st-history-head">{{ t(\'STRUCTURA_JS_HISTORY_HEAD\') }}</div>' +
            '    <div class="st-empty" v-if="historyLoading()">{{ t(\'STRUCTURA_JS_LOADING\') }}</div>' +
            '    <div class="st-empty" v-else-if="!historyList().length">{{ t(\'STRUCTURA_JS_NO_REVISIONS\') }}</div>' +
            '    <ul class="st-history-list" v-else>' +
            '      <li class="st-history-item" v-for="rev in historyList()" :key="rev.id">' +
            '        <div class="st-history-row">' +
            '          <div class="st-history-meta">' +
            '            <span class="st-history-id">#{{ rev.id }}</span>' +
            '            <span class="st-history-date">{{ rev.createdAt }}</span>' +
            '            <span class="st-history-user" v-if="rev.userId">{{ t(\'STRUCTURA_JS_USER_SHORT\') }}{{ rev.userId }}</span>' +
            '            <span class="st-history-comment" v-if="rev.comment">{{ rev.comment }}</span>' +
            '          </div>' +
            '          <div class="st-history-acts">' +
            '            <button type="button" class="st-preview"' +
            '              :class="{\'st-preview--on\': previewOpen(rev.id)}"' +
            '              @click="togglePreview(rev.id)">{{ t(\'STRUCTURA_JS_PREVIEW\') }}</button>' +
            '            <button type="button" class="st-rollback" :disabled="saving() || blockLocked"' +
            '              @click="rollback(rev.id)">{{ t(\'STRUCTURA_JS_ROLLBACK_BTN\') }}</button>' +
            '          </div>' +
            '        </div>' +
            '        <div class="st-diff" v-if="previewOpen(rev.id)">' +
            '          <div class="st-empty" v-if="previewDiff(rev.id) === null">{{ t(\'STRUCTURA_JS_LOADING\') }}</div>' +
            '          <div class="st-empty" v-else-if="!previewDiff(rev.id).length">' +
            '            {{ t(\'STRUCTURA_JS_DIFF_SAME\') }}</div>' +
            '          <table class="st-diff-table" v-else>' +
            '            <thead><tr><th>{{ t(\'STRUCTURA_JS_TH_FIELD\') }}</th><th>{{ t(\'STRUCTURA_JS_TH_NOW\') }}</th><th>{{ t(\'STRUCTURA_JS_TH_AFTER\') }}</th></tr></thead>' +
            '            <tbody>' +
            '              <tr v-for="d in previewDiff(rev.id)" :key="d.path"' +
            '                :class="\'st-diff--\' + d.type">' +
            '                <td class="st-diff-label" :title="d.path">{{ d.label }}</td>' +
            '                <td class="st-diff-from">{{ d.from }}</td>' +
            '                <td class="st-diff-to">{{ d.to }}</td>' +
            '              </tr>' +
            '            </tbody>' +
            '          </table>' +
            '        </div>' +
            '      </li>' +
            '    </ul>' +
            '    <div class="st-history-more" v-if="historyRest() > 0">' +
            '      <button type="button" class="st-more" :disabled="historyMoreLoading()"' +
            '        @click="loadMore()">' +
            '        {{ historyMoreLoading() ? t(\'STRUCTURA_JS_LOADING\') : t(\'STRUCTURA_JS_SHOW_MORE\') + \' (\' + historyRest() + \')\' }}' +
            '      </button>' +
            '    </div>' +
            '  </div>' +
            '  </div>' +
            '</div>'
    });

    var StructuraApp = {
        template:
            '<div class="structura">' +
            '  <div class="st-toolbar">' +
            '    <button type="button" class="st-add-block" :class="{\'st-add-block--on\': picking}"' +
            '      @click="togglePicker()">{{ picking ? t(\'STRUCTURA_JS_CANCEL\') : t(\'STRUCTURA_JS_ADD_BLOCK\') }}</button>' +
            '    <span class="st-toolbar-note" v-if="instances.length">' +
            '      {{ t(\'STRUCTURA_JS_BLOCKS_COUNT\') }} {{ instances.length }}</span>' +
            // Свернуть/развернуть все — ради длинных списков (одна кнопка,
            // действие зависит от того, раскрыт ли хоть один блок).
            '    <button type="button" class="st-collapse-all" v-if="instances.length > 1"' +
            '      @click="toggleAll()">{{ anyExpanded ? t(\'STRUCTURA_JS_COLLAPSE_ALL\') : t(\'STRUCTURA_JS_EXPAND_ALL\') }}</button>' +
            '  </div>' +
            // Обязательные блоки, которых нет. Предупреждение, а не
            // запрет: сохранить элемент без блока никто не мешает.
            '  <div class="st-required-note" v-if="missing.length">' +
            '    <span class="st-required-note-text">' +
            '      {{ t(\'STRUCTURA_JS_REQUIRED_MISSING\') }}</span>' +
            '    <button type="button" class="st-required-add" v-for="b in missing" :key="b.code"' +
            '      @click="add(b.code)">+ {{ b.name }}</button>' +
            '  </div>' +
            // Значения блоков, у которых больше нет конфигурации. Не действие,
            // а предупреждение: удалять данные за пользователя нельзя — папку
            // могли переименовать по ошибке, и тогда всё чинится её возвратом.
            '  <div class="st-orphan-note" v-if="orphans.length">' +
            '    <b>{{ t(\'STRUCTURA_JS_ORPHAN_A\') }}{{ entityWord }}{{ t(\'STRUCTURA_JS_ORPHAN_B\') }}</b>' +
            '    <ul class="st-orphan-list">' +
            '      <li v-for="o in orphans" :key="o.blockCode">' +
            '        <code>{{ o.blockCode }}</code> — {{ t(\'STRUCTURA_JS_ORPHAN_COPIES\') }} {{ o.count }}</li>' +
            '    </ul>' +
            '    {{ t(\'STRUCTURA_JS_ORPHAN_HINT\') }}' +
            '  </div>' +
            '  <div class="st-picker" v-if="picking">' +
            '    <div class="st-picker-head">{{ t(\'STRUCTURA_JS_PICKER_HEAD\') }}</div>' +
            '    <div class="st-empty" v-if="!blocks.length">{{ t(\'STRUCTURA_JS_PICKER_EMPTY\') }}</div>' +
            // Секции завёрнуты в контейнер намеренно: v-for приоритетнее v-if,
            // и v-else на том же элементе, что и v-for, — грабля Vue 2.
            '    <div class="st-picker-groups" v-else>' +
            '    <div class="st-picker-group" v-for="s in sections" :key="s.key">' +
            '      <div class="st-picker-group-head" v-if="s.title">' +
            '        <span class="st-picker-group-name">{{ s.title }}</span>' +
            '        <span class="st-picker-badge st-picker-badge--one" v-if="s.unique">{{ t(\'STRUCTURA_JS_BADGE_ONE\') }}</span>' +
            '      </div>' +
            '      <ul class="st-picker-list">' +
            '        <li v-for="b in s.blocks" :key="b.code">' +
            '          <button type="button" class="st-picker-item" :disabled="!canAdd(b.code)"' +
            '            @click="add(b.code)">' +
            '            <span class="st-picker-name">{{ b.name }}</span>' +
            // Отдельным бейджем, а не веткой цепочки ниже: обязательность живёт
            // сама по себе и должна быть видна вместе с «можно несколько».
            '            <span class="st-picker-badge st-picker-badge--req" v-if="b.required">{{ t(\'STRUCTURA_JS_BADGE_REQUIRED\') }}</span>' +
            '            <span class="st-picker-badge st-picker-badge--off" v-if="blockedBy(b.code)">' +
            '              {{ t(\'STRUCTURA_JS_ADDED_BY\') }} «{{ blockedBy(b.code).blockName }}»</span>' +
            '            <span class="st-picker-badge" v-else-if="b.multiple">{{ t(\'STRUCTURA_JS_BADGE_MULTIPLE\') }}</span>' +
            '            <span class="st-picker-badge st-picker-badge--off" v-else-if="!canAdd(b.code)">{{ t(\'STRUCTURA_JS_ADDED\') }}</span>' +
            '          </button>' +
            '        </li>' +
            '      </ul>' +
            '    </div>' +
            '    </div>' +
            '  </div>' +
            '  <div class="st-empty" v-if="!instances.length && !picking">' +
            '    {{ t(\'STRUCTURA_JS_PICKER_NOTHING\') }}</div>' +
            '  <structura-instance v-for="(m, i) in instances" :key="m.instanceId"' +
            '    :meta="m" :index="i" :total="instances.length"></structura-instance>' +
            '</div>',
        computed: {
            blocks:    function () { return this.$store.state.blocks; },
            instances: function () { return this.$store.state.instances; },
            picking:   function () { return this.$store.state.picking; },
            anyExpanded: function () { return this.$store.getters.anyExpanded; },

            // Значения блоков без конфигурации: [{blockCode, count}]. Считает
            // сервер — знать, какие блоки существуют, может только он.
            orphans: function () { return this.$store.state.orphans; },
            entityWord: function () {
                return (this.$store.state.entity || {}).type === 'section' ? t('STRUCTURA_JS_ENTITY_SECTION_GEN') : t('STRUCTURA_JS_ENTITY_ELEMENT_GEN');
            },

            /** Обязательные блоки, которых на сущности нет. */
            missing: function () { return missingRequired(this.blocks, this.instances); },

            /**
             * Блоки пикера, разбитые по группам (папкам с .group.php).
             *
             * Секция — внутренняя группа блока: заголовок показывает всю цепочку
             * («Маркетинг → Промо-акции»), поэтому вложенность видна без отступов
             * и рекурсии. Блоки вне групп — первой секцией, без заголовка.
             *
             * Порядок секций — по sort группы, затем по имени: тот же порядок,
             * что у Loader::tree() на сервере. Список блоков внутри секции
             * приходит с сервера уже отсортированным.
             */
            sections: function () {
                var byKey = {};
                var list  = [];

                this.blocks.forEach(function (b) {
                    var trail = b.groups || [];
                    var group = trail.length ? trail[trail.length - 1] : null;
                    var key   = group ? group.path : '';

                    if (!byKey[key]) {
                        byKey[key] = {
                            key:    key,
                            title:  trail.map(function (g) { return g.name; }).join(' → '),
                            sort:   group ? group.sort : 0,
                            unique: group ? !!group.unique : false,
                            blocks: []
                        };
                        list.push(byKey[key]);
                    }
                    byKey[key].blocks.push(b);
                });

                return list.sort(function (a, b) {
                    // Блоки без группы — всегда сверху: это не «группа с пустым
                    // именем», а корень списка.
                    if (a.key === '') { return -1; }
                    if (b.key === '') { return 1; }
                    return a.sort - b.sort || a.title.localeCompare(b.title);
                });
            }
        },
        methods: {
            togglePicker: function () { return this.$store.dispatch('togglePicker'); },
            toggleAll: function () { this.$store.commit('SET_ALL_EXPANDED', !this.anyExpanded); },
            canAdd: function (code) { return this.$store.getters.canAdd(code); },
            blockedBy: function (code) { return this.$store.getters.groupBlocker(code); },
            add: function (code) { return this.$store.dispatch('addInstance', code); }
        }
    };

    /* ------------------------------------------------------------------ *
     *  Точка входа
     * ------------------------------------------------------------------ */
    function mount(selector, options) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (!el) { window.console && console.error('[Structura] mount: элемент не найден', selector); return null; }

        var store = createStore(options || {});
        return new Vue({ store: store, render: function (h) { return h(StructuraApp); } }).$mount(el);
    }

    window.Structura = {
        version: '0.3.0',
        mount: mount,
        registerField: registerField,
        validateBlock: validateBlock,
        get Vue() { return Vue; }
    };
})(window);
