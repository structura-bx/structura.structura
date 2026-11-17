<?php

namespace Structura\Structura\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Localization\Loc;
use Structura\Structura\Config\Loader;
use Structura\Structura\Field;
use Structura\Structura\Fields\ContainerField;
use Structura\Structura\Fields\Flexible;
use Structura\Structura\Service\RevisionService;
use Structura\Structura\Service\RowId;
use Structura\Structura\Service\ValueService;
use Structura\Structura\Storage\ValueTable;

Loc::loadMessages(__FILE__);

/**
 * AJAX-контроллер для фронтенда (Vue). Действия:
 *   structura:structura.block.config    — конфигурация блоков, доступных сущности;
 *   structura:structura.block.instances — экземпляры блоков сущности (в порядке SORT);
 *   structura:structura.block.values    — значения экземпляра (с подстановкой default);
 *   structura:structura.block.save      — валидация и сохранение значений экземпляра;
 *   structura:structura.block.remove    — удаление экземпляра вместе с его историей;
 *   structura:structura.block.reorder   — порядок экземпляров на сущности.
 *
 * Единица работы — экземпляр блока (instanceId), а не код блока: блок с
 * ->multiple() может быть добавлен к сущности несколько раз.
 *
 * Внимание: имя действия — `<vendor>:<module>.<controller>.<action>`. Двоеточие
 * обязательно: по нему Engine\Router отделяет вендора и склеивает модуль обратно
 * в "structura.structura". Без него вендором станет "bitrix", модулем —
 * "structura", и роутер уйдёт искать модуль, которого нет.
 *
 * Сам класс контроллера резолвится не по id модуля, а по defaultNamespace из
 * .settings.php, поэтому пространство имён с ним совпадать не обязано.
 */
class Block extends Controller
{
    /**
     * Ко всем действиям добавлена проверка права на модуль.
     *
     * Модуль административный: он живёт на формах админки и больше нигде.
     * Дефолтные префильтры Engine требуют лишь авторизации, поэтому на сайте с
     * открытой регистрацией ajax-роут модуля мог дёргать любой посетитель.
     * Данные закрыты проверками внутри действий, но доступный роут — сам по себе
     * лишняя поверхность атаки.
     *
     * Именно префильтр, а не проверка в каждом действии: точечную легко забыть
     * в новом действии, а этот список — общий для всех и работает по умолчанию.
     *
     * Проверки внутри действий (canEditEntity/canReadEntity, политики) остаются:
     * право модуля отвечает «пускать ли вообще», а не «чей это элемент».
     */
    protected function getDefaultPreFilters(): array
    {
        return array_merge(
            parent::getDefaultPreFilters(),
            [new \Structura\Structura\Engine\ActionFilter\ModuleAccess()]
        );
    }

    public function configureActions(): array
    {
        // Чтения без CSRF; записи (save, rollback, remove, reorder) — под CSRF.
        return [
            'config'    => ['-prefilters' => [ActionFilter\Csrf::class]],
            'instances' => ['-prefilters' => [ActionFilter\Csrf::class]],
            'values'    => ['-prefilters' => [ActionFilter\Csrf::class]],
            'revisions' => ['-prefilters' => [ActionFilter\Csrf::class]],
            'revision'  => ['-prefilters' => [ActionFilter\Csrf::class]],
            'iblocks'   => ['-prefilters' => [ActionFilter\Csrf::class]],
            'highloads' => ['-prefilters' => [ActionFilter\Csrf::class]],
            'sections'  => ['-prefilters' => [ActionFilter\Csrf::class]],
            'section'   => ['-prefilters' => [ActionFilter\Csrf::class]],
            'hlrow'     => ['-prefilters' => [ActionFilter\Csrf::class]],
            'user'      => ['-prefilters' => [ActionFilter\Csrf::class]],
            'element'   => ['-prefilters' => [ActionFilter\Csrf::class]],
            'iblock'    => ['-prefilters' => [ActionFilter\Csrf::class]],
            'hlrows'    => ['-prefilters' => [ActionFilter\Csrf::class]],
            'file'      => ['-prefilters' => [ActionFilter\Csrf::class]],
            // upload — запись: CSRF остаётся.
        ];
    }

    /**
     * Загрузка файла для полей Image/File. Значение поля — ID файла (CFile).
     *
     * Файл сохраняется сразу и навсегда, а не во временное хранилище, как в
     * админском аплоадере (BXU): значение поля живёт в нашем JSON и попадает в
     * ревизии, а временный путь в снапшоте ревизии — бессмыслица.
     *
     * Проверки — стандартные CFile: CheckImageFile (это правда картинка) или
     * CheckFile (санация имени, запрет небезопасных расширений через
     * IsFileUnsafe, размер, квота). Клиентскому `accept` из конфига поля тут
     * веры нет — его можно подделать, поэтому решает сервер.
     *
     * @return array{file: array}|null null — ошибка (лежит в getErrors)
     */
    public function uploadAction(string $entityType, int $entityId, string $mode = 'file'): ?array
    {
        if (!$this->canEditEntity($entityType, $entityId)) {
            $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_NO_EDIT_RIGHTS')));

            return null;
        }

        $file = $this->getRequest()->getFile('file');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_FILE_NOT_RECEIVED')));

            return null;
        }

        // Режим картинки проверяется сам (CheckImageFile требует настоящую
        // картинку И расширение картинки). Для произвольного файла нужен
        // Явный список расширений: без него CheckFile пропускает .php —
        // IsFileUnsafe внутри него режет только .htaccess/.htpasswd/web.config/
        // global.asax, но не скрипты. Список берём с сервера, а не из конфига
        // поля: `accept` у поля — подсказка браузеру, её легко подделать.
        $error = $mode === 'image'
            ? \CFile::CheckImageFile($file)
            : \CFile::CheckFile($file, 0, false, $this->allowedExtensions());

        if ((string) $error !== '') {
            $this->addError(new \Bitrix\Main\Error((string) $error));

            return null;
        }

        $file['MODULE_ID'] = STRUCTURA_MODULE_ID;
        // forceRandom: имя файла приходит от пользователя, совпадения не нужны.
        // Второй аргумент — подкаталог в upload, а не id модуля: точку в имени
        // каталога заводить незачем, старые файлы остаются на своих местах.
        $id = \CFile::SaveFile($file, 'structura', true);

        if (!$id) {
            $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_FILE_SAVE_FAILED')));

            return null;
        }

        return ['file' => $this->fileInfo((int) $id)];
    }

    /**
     * Клонирование строки Repeater.
     *
     * Клонирует сервер, а не фронтенд, из-за идентификаторов строк: копия обязана
     * получить свежий `_id`, иначе для слияния политик обе строки — одна и та
     * же, и правка одной подставляется в другую. Ради этого же нужен и разбор
     * пути с макетами: `_id` проставляется по дереву полей, а не по угадыванию.
     *
     * Файлы клон не дублирует: строка-копия остаётся на том же ID картинки. Цена
     * — удаление файла: {@see FileService::delete} перед сносом байтов смотрит,
     * не держит ли их ещё какое-то значение этой сущности.
     *
     * `path` — путь до самого Repeater ("slides", "sections.0.content"), а не до
     * строки: числовые сегменты при поиске полей пропускаются.
     *
     * `layouts` — коды выбранных макетов Flexible по порядку их встречи в пути
     * (для "sections.0.content.0.data" это один код). Путь сам по себе через
     * Flexible не проходит: какой макет выбран, знает только значение
     * (`_layout`). См. fieldsAtPath() — там же, почему это не дыра.
     *
     * Gallery не трогаем: там значение — путь к общему файлу сайта, дублировать
     * нечего (и не надо — файл не «принадлежит» строке).
     *
     * @param string[] $layouts коды макетов по порядку Flexible в пути
     * @return array{row: array}|null null — ошибка (лежит в getErrors)
     */
    public function cloneRowAction(
        string $blockCode,
        string $entityType,
        int $entityId,
        string $path,
        array $row = [],
        array $layouts = []
    ): ?array {
        if (!$this->canEditEntity($entityType, $entityId)) {
            $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_NO_EDIT_RIGHTS')));

            return null;
        }

        $block = Loader::load($blockCode);
        if ($block === null) {
            $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_BLOCK_NOT_FOUND', ['#BLOCK#' => $blockCode])));

            return null;
        }

        // Политика блока запрещает правку — значит и клонирование строки: оно
        // меняет содержимое.
        if (!$this->allowedByPolicy($blockCode)) {
            return null;
        }

        $fields = $this->fieldsAtPath($block->getFields(), $path, $layouts);
        if ($fields === null) {
            $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_FIELD_NOT_FOUND', ['#PATH#' => $path])));

            return null;
        }

        // Свежий `_id` копии и всему, что внутри неё: с идентификатором
        // оригинала обе строки были бы для слияния политик одной и той же, и
        // правка одной подставлялась бы в другую. Значения — как есть, вместе с
        // ID файлов оригинала.
        return ['row' => RowId::refreshRow($fields, $row)];
    }

    /**
     * Поля контейнера по пути вида "sections.0.content" или
     * "sections.0.content.0.data" (строка внутри макета Flexible).
     *
     * Числовые сегменты — индексы строк, для поиска полей они не значат ничего.
     * Сегмент "data" пропускается тоже: так Flexible хранит значение макета.
     *
     * У Flexible `getFields()` возвращает макеты, а не поля, поэтому пройти его
     * насквозь одним лишь путём нельзя: какой макет выбран, записано в значении
     * (`_layout`), а путь адресует элемент Flexible по индексу. Отсюда $layouts:
     * коды выбранных макетов по порядку встречи в пути.
     *
     * Коды макетов приходят от клиента, но выбирают лишь среди объявленных в
     * конфиге; сами поля (и какие из них файловые) сервер берёт из конфига блока.
     *
     * @param Field[]  $fields
     * @param string[] $layouts коды макетов по порядку Flexible в пути
     * @return Field[]|null null — путь никуда не ведёт
     */
    private function fieldsAtPath(array $fields, string $path, array $layouts = []): ?array
    {
        $current = $fields;
        $trail   = array_values($layouts);

        foreach (explode('.', $path) as $segment) {
            if ($segment === '' || ctype_digit($segment) || $segment === 'data') {
                continue;
            }

            $found = null;
            foreach ($current as $field) {
                if ($field->getCode() === $segment) {
                    $found = $field;
                    break;
                }
            }

            if (!$found instanceof ContainerField) {
                return null;
            }

            // Flexible: дальше идём полями выбранного макета, а не макетами.
            if ($found instanceof Flexible) {
                $code   = array_shift($trail);
                $layout = null;
                foreach ($found->getLayouts() as $candidate) {
                    if ($candidate->getCode() === $code) {
                        $layout = $candidate;
                        break;
                    }
                }

                // Макет не назван или выдуман — отказ, а не «копируем как есть»:
                // молча скопированный ID файла даёт двум строкам один файл, и
                // удаление у копии убьёт картинку у оригинала. Ровно то, ради
                // чего клонирование вообще делает сервер.
                if (!$layout instanceof ContainerField) {
                    return null;
                }

                $current = $layout->getFields();
                continue;
            }

            $current = $found->getFields();
        }

        return $current;
    }

    /**
     * Дата из формы активности → Bitrix\Main\Type\DateTime.
     *
     * Пусто — null: край окна без ограничения. strtotime терпим к формату: и ISO
     * из <input type="datetime-local">, и обычная строка.
     *
     * Непустая строка, которую разобрать не удалось, — это FALSE, а не null:
     * иначе заданное окно дат молча превратилось бы в «ограничения нет».
     * Пустое и «не разобрали» — разные исходы.
     *
     * @return \Bitrix\Main\Type\DateTime|null|false
     */
    private static function parseDate(?string $value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);

        return $ts !== false ? \Bitrix\Main\Type\DateTime::createFromTimestamp($ts) : false;
    }

    /**
     * Расширения, которые разрешено загружать полем File.
     *
     * Меняется настройкой модуля `upload_extensions` (список через запятую).
     * По умолчанию — документы, картинки, архивы и медиа; не входят скрипты
     * (php и компания) и svg: svg — это XML, в него кладётся JavaScript, и
     * отдача его с нашего домена даёт XSS.
     */
    private function allowedExtensions(): string
    {
        $default = 'jpg,jpeg,png,gif,webp,bmp,ico,pdf,doc,docx,xls,xlsx,ppt,pptx,'
            . 'odt,ods,txt,csv,rtf,zip,rar,7z,gz,mp3,mp4,webm,ogg,avi,mov';

        return (string) \Bitrix\Main\Config\Option::get(STRUCTURA_MODULE_ID, 'upload_extensions', $default);
    }

    /**
     * Сведения о файле по ID — чтобы показать превью/имя у сохранённого значения.
     *
     * Метаданные файла — это данные сущности, поэтому отдаём их лишь при двух
     * условиях: пользователь вправе читать сущность (canReadEntity) и файл
     * действительно лежит в её значениях (checkOwnership). Иначе перебором ID
     * можно было бы вычитать имя и путь любого файла сайта (CFile::GetFileArray
     * бьёт по всей b_file), в обход прав на элемент/раздел (S3).
     *
     * Отдаём null (как «файла нет»), а не ошибку: существование чужого файла —
     * тоже сведения. Политика поля тут ни при чём: заблокированное политикой
     * поле всё равно показывается со значением, только read-only, — а значит и
     * превью ему нужно.
     *
     * @return array{file: array|null}
     */
    public function fileAction(int $id, ?string $entityType = null, ?int $entityId = null): array
    {
        if (
            $id <= 0
            || $entityType === null
            || $entityId === null
            || !$this->canReadEntity($entityType, $entityId)
            || !(new \Structura\Structura\Service\FileService())->isUsedBy($entityType, $entityId, $id)
        ) {
            return ['file' => null];
        }

        return ['file' => $this->fileInfo($id)];
    }

    /**
     * Настоящее удаление файла поля File/Image.
     *
     * «×» у поля только отвязывает файл от значения; здесь файл удаляется
     * физически, а ссылка на него убирается из живого значения и из снапшотов
     * ревизий — иначе откат упал бы на встроенной валидации fileExists.
     * Подробности и цена решения — в FileService.
     *
     * Операция точечная и адресуется полем: экземпляр блока (`instanceId`) плюс
     * путь значения (`path` — «pic», «rows.0.inner»). Чистится ровно это поле;
     * ни соседнее поле, ни другой блок той же сущности не трогаются, даже если
     * там лежит тот же ID.
     *
     * Что можно удалять, решает сервер по трём условиям:
     *  - есть право править сущность (canEditEntity, как у upload);
     *  - по указанному пути и правда лежит файловое поле с этим файлом — иначе
     *    перебором ID можно было бы сносить чужие файлы и вообще любые записи
     *    b_file;
     *  - поле с файлом не заперто политикой.
     *
     * `purged` = false означает, что запись b_file осталась, и `reason` говорит
     * почему: `shared_value` — тот же ID лежит в другом значении этой сущности
     * (другой язык, соседнее поле, клон строки — файлы между ними не
     * размножаются); `shared_bytes` — ссылок в модуле не осталось, но те же
     * байты держит другая запись b_file (дедупликация ядра по хэшу). Клиент
     * обязан сказать об этом вслух, а не рапортовать «удалено».
     *
     * @param string $instanceId экземпляр блока, которому принадлежит поле
     * @param string $path       путь поля в значениях экземпляра
     *
     * @return array{deleted: true, purged: bool, reason: string|null,
     *               values: int, revisions: int}|null
     */
    public function deleteFileAction(
        string $entityType,
        int $entityId,
        int $fileId,
        string $instanceId,
        string $path,
        ?string $language = null
    ): ?array {
        if (!$this->canEditEntity($entityType, $entityId)) {
            $this->denyEdit();

            return null;
        }

        $service = new \Structura\Structura\Service\FileService();
        $owner   = $service->checkFieldOwnership($entityType, $entityId, $instanceId, $path, $fileId, $language);

        if (!$owner['found']) {
            $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_FILE_NOT_IN_FIELD')));

            return null;
        }

        if (!$owner['allowed']) {
            $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_NO_FIELD_FILE_RIGHTS')));

            return null;
        }

        $result = $service->delete($fileId, $entityType, $entityId, $instanceId, $path, $language);
        if (!$result->isSuccess()) {
            foreach ($result->getErrors() as $error) {
                $this->addError($error);
            }

            return null;
        }

        $data = $result->getData();

        return [
            'deleted'   => true,
            'purged'    => (bool) ($data['purged'] ?? false),
            'reason'    => $data['reason'] ?? null,
            'values'    => (int) ($data['values'] ?? 0),
            'revisions' => (int) ($data['revisions'] ?? 0),
        ];
    }

    /**
     * @return array{id:int, name:string, size:int, src:string, isImage:bool,
     *               width:int, height:int}|null
     */
    private function fileInfo(int $id): ?array
    {
        $file = \CFile::GetFileArray($id);
        if (!$file) {
            return null;
        }

        return [
            'id'      => (int) $file['ID'],
            'name'    => (string) $file['ORIGINAL_NAME'],
            'size'    => (int) $file['FILE_SIZE'],
            'src'     => (string) $file['SRC'],
            'isImage' => (int) $file['WIDTH'] > 0 && (int) $file['HEIGHT'] > 0,
            'width'   => (int) $file['WIDTH'],
            'height'  => (int) $file['HEIGHT'],
        ];
    }

    /** Может ли текущий пользователь править эту сущность. */
    private function canEditEntity(string $entityType, int $entityId): bool
    {
        return $this->hasRightTo($entityType, $entityId, 'edit');
    }

    /**
     * Может ли текущий пользователь читать значения этой сущности.
     *
     * Отдельно от правки: смотреть значения и историю можно и тем, кому править
     * нельзя, — так же ведёт себя сама форма элемента (открыть на просмотр).
     */
    private function canReadEntity(string $entityType, int $entityId): bool
    {
        return $this->hasRightTo($entityType, $entityId, 'read');
    }

    /**
     * Право на операцию над сущностью.
     *
     * Инфоблок берём по ID самой сущности с сервера, а не из запроса: клиент мог
     * бы подставить чужой инфоблок, где права у него есть. Права — стандартные
     * (`element_read`/`element_edit`, `section_read`/`section_edit`), ровно те
     * же, что проверяют сами формы элемента и раздела.
     *
     * $operation — 'read' или 'edit'; префикс сущности дописывается здесь, чтобы
     * вызывающему не нужно было знать, элемент это или раздел.
     *
     * Незнакомый тип сущности — отказ: «неизвестно» должно значить «нельзя».
     */
    private function hasRightTo(string $entityType, int $entityId, string $operation): bool
    {
        if ($entityId <= 0 || !\Bitrix\Main\Loader::includeModule('iblock')) {
            return false;
        }

        if ($entityType === ValueTable::ENTITY_ELEMENT) {
            $row = \Bitrix\Iblock\ElementTable::getList([
                'select' => ['IBLOCK_ID'],
                'filter' => ['=ID' => $entityId],
                'limit'  => 1,
            ])->fetch();

            return $row && \CIBlockElementRights::UserHasRightTo(
                (int) $row['IBLOCK_ID'],
                $entityId,
                'element_' . $operation
            );
        }

        if ($entityType === ValueTable::ENTITY_SECTION) {
            $row = \Bitrix\Iblock\SectionTable::getList([
                'select' => ['IBLOCK_ID'],
                'filter' => ['=ID' => $entityId],
                'limit'  => 1,
            ])->fetch();

            return $row && \CIBlockSectionRights::UserHasRightTo(
                (int) $row['IBLOCK_ID'],
                $entityId,
                'section_' . $operation
            );
        }

        return false;
    }

    /**
     * Разрешает ли политика блока трогать его экземпляр целиком.
     *
     * Политика — это право править, и удаление экземпляра либо перестановка его
     * в списке правкой быть не перестают: и то и другое меняет то, что видно на
     * сайте. Значения защищает PolicyGuard внутри сохранения, а эти два действия
     * до него не доходят вообще — проверяем здесь, на границе админского роута.
     *
     * Блок без конфигурации (папку удалили) политики не имеет: его осиротевшие
     * значения должно быть можно убрать, иначе они не удаляются никак.
     *
     * Отказ пишется в ошибки действия с причиной — пользователь должен понимать,
     * почему кнопка не сработала.
     */
    private function allowedByPolicy(string $blockCode): bool
    {
        $block = Loader::load($blockCode);
        if ($block === null) {
            return true;
        }

        $reason = \Structura\Structura\Access\Policy::reason($block->getPolicy());
        if ($reason === null) {
            return true;
        }

        $this->addError(new \Bitrix\Main\Error($reason));

        return false;
    }

    /**
     * Код блока, которому реально принадлежит экземпляр на этой сущности.
     *
     * Источник истины — БД (listInstances), а не запрос: политику и удаление
     * нужно применять к тому блоку, что лежит под instanceId, иначе клиент
     * подменой blockCode проверял бы политику не того блока. null — экземпляра
     * с таким id у сущности нет.
     */
    private function blockCodeOfInstance(
        ValueService $service,
        string $entityType,
        int $entityId,
        string $instanceId
    ): ?string {
        foreach ($service->listInstances($entityType, $entityId) as $instance) {
            if ($instance['instanceId'] === $instanceId) {
                return $instance['blockCode'];
            }
        }

        return null;
    }

    /** Отказ в правах: одинаковый текст во всех действиях. */
    private function denyEdit(): void
    {
        $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_NO_EDIT_RIGHTS')));
    }

    /** Отказ в чтении. */
    private function denyRead(): void
    {
        $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_NO_VIEW_RIGHTS')));
    }

    /**
     * Размер страницы справочника с поиском (sections, hlrows). Списки могут
     * быть большими, поэтому страница + серверный поиск, а не выдача целиком.
     */
    private const REF_PAGE_SIZE = 50;

    /**
     * Строки Highload-блока для поля HighloadElement.
     *
     * `titleField` — явное переопределение поля подписи (аналог настройки
     * HLFIELD_ID у поля-привязки ядра). Без него поле выбирается по конвенции
     * справочников ядра: UF_NAME, затем UF_XML_ID (prop_directory), и лишь
     * когда нет ни того ни другого — голые ID (как у ядра при пустом
     * HLFIELD_ID). Настройка не обязательна: без неё работает конвенция, а не
     * голые ID.
     *
     * Страница + серверный поиск: список HL-блока может быть большим, а без
     * поиска в нём не выбрать нужное. Поиск идёт по полю-заголовку — остальные
     * поля произвольны, и что в них искать, знает только разработчик.
     *
     * `hasMore`, а не `total`: COUNT по большой таблице ради «ещё N» — лишний
     * проход, а «есть ещё» отвечает на тот же вопрос.
     *
     * Тип $hlblock не объявлен намеренно: Engine биндит параметры действий по
     * рефлексии и на union-типе падает («no support for union or intersection
     * types»), не дойдя до тела. ID или имя разбирает resolveHlblock().
     *
     * @param int|string $hlblock
     *
     * @return array{rows: array<int, array{id:int, label:string}>, hasMore: bool}
     */
    public function hlrowsAction(
        $hlblock,
        ?string $titleField = null,
        ?string $search = null,
        int $page = 1,
        int $limit = self::REF_PAGE_SIZE
    ): array {
        $empty = ['rows' => [], 'hasMore' => false];

        // Как и highloads: строки HL-блока — те же данные, что в админке
        // highloadblock, а она открыта только админу.
        if (!$this->canViewHighloads()) {
            return $empty;
        }

        if (!\Bitrix\Main\Loader::includeModule('highloadblock')) {
            return $empty;
        }

        $block = $this->resolveHlblock($hlblock);
        if ($block === null) {
            return $empty;
        }

        global $USER_FIELD_MANAGER;
        $userFields = $USER_FIELD_MANAGER->getUserFields(
            \Bitrix\Highloadblock\HighloadBlockTable::compileEntityId($block['ID'])
        );

        // Поле подписи (правило сверено с ядром). Явный ->titleField() —
        // аналог настройки HLFIELD_ID у поля-привязки ядра (cusertypehlblock:
        // getHlRows) и главнее всего. Без него — конвенция справочников ядра
        // (prop_directory: UF_NAME, затем UF_XML_ID), и только когда нет и их —
        // голый ID (как у ядра при пустом HLFIELD_ID). Кандидат берётся, только
        // если поле и правда есть у этого HL-блока: опечатка в конфиге не
        // роняет список, а проваливается дальше по конвенции.
        $title = null;
        foreach ([$titleField, 'UF_NAME', 'UF_XML_ID'] as $candidate) {
            if ($candidate !== null && $candidate !== '' && isset($userFields[$candidate])) {
                $title = $candidate;
                break;
            }
        }

        $dataClass = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($block)->getDataClass();
        $select    = $title !== null ? ['ID', $title] : ['ID'];

        $filter = [];
        $search = trim((string) $search);
        if ($search !== '') {
            // Искать можно только по полю-заголовку: остальные поля произвольны,
            // и что в них искать, знает лишь разработчик. Нет titleField — ищем
            // по ID: подписи всё равно голые «#N», искать больше нечего.
            if ($title !== null) {
                $filter = ['%' . $title => $search];
                if (ctype_digit($search)) {
                    $filter = ['LOGIC' => 'OR', ['%' . $title => $search], ['=ID' => (int) $search]];
                }
            } elseif (ctype_digit($search)) {
                $filter = ['=ID' => (int) $search];
            } else {
                return $empty;
            }
        }

        // Здесь D7, и смещение он понимает честно (в отличие от
        // CIBlockSection::GetList — см. sectionsAction). +1 строка — признак,
        // что дальше ещё есть: COUNT ради этого гонять не надо.
        $page = max(1, $page);
        $rows = $dataClass::getList([
            'select' => $select,
            'filter' => $filter,
            'order'  => ['ID' => 'ASC'],
            'offset' => ($page - 1) * $limit,
            'limit'  => $limit + 1,
        ])->fetchAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        // Для списка подписи вариантов тянем один раз на весь ответ, а не на
        // каждую строку.
        $enumLabels = ($title !== null && ($userFields[$title]['USER_TYPE_ID'] ?? '') === 'enumeration')
            ? $this->enumLabels((int) $userFields[$title]['ID'])
            : [];

        $out = [];
        foreach ($rows as $row) {
            $id  = (int) $row['ID'];
            $out[] = [
                'id'    => $id,
                'label' => $title === null
                    ? '#' . $id
                    : $this->rowLabel($userFields[$title], $row[$title], $id, $enumLabels),
            ];
        }

        return ['rows' => $out, 'hasMore' => $hasMore];
    }

    /**
     * Одна строка HL-блока по ID — подпись уже выбранного значения.
     *
     * Как sectionAction: из-за пагинации сохранённая строка может не попасть в
     * текущую страницу выдачи, а показывать голый ID вместо названия — плохо.
     *
     * @return array{row: array{id:int, label:string}|null}
     */
    public function hlrowAction($hlblock, int $id, ?string $titleField = null): array
    {
        if ($id <= 0 || !$this->canViewHighloads()) {
            return ['row' => null];
        }

        $rows = $this->hlrowsAction($hlblock, $titleField, (string) $id, 1, 1);

        foreach ($rows['rows'] as $row) {
            if ((int) $row['id'] === $id) {
                return ['row' => $row];
            }
        }

        return ['row' => null];
    }

    /**
     * Подпись строки HL-блока по значению поля-заголовка.
     *
     * Сознательно без getListView: он делает HTML для админских списков и
     * экранирует значение (у типа `string` — дважды: сам htmlspecialcharsbx +
     * ещё раз в GetAdminListViewHTML), а подпись уезжает в JSON и выводится во
     * Vue текстом. Подгонять число html_entity_decode под тип поля — гиблое
     * дело: сырое значение и есть нужный текст.
     *
     * Исключение — список (enumeration): там сырое значение это ID варианта,
     * человеку он ничего не говорит, поэтому берём подписи вариантов.
     *
     * @param array<int, string> $enumLabels подписи вариантов [ID => VALUE]
     */
    private function rowLabel(array $userField, mixed $value, int $id, array $enumLabels): string
    {
        // Множественное поле — сериализованный массив; однострочной подписи из
        // него не сделать, поэтому просто ID.
        if (($userField['MULTIPLE'] ?? 'N') === 'Y') {
            return '#' . $id;
        }

        $text = ($userField['USER_TYPE_ID'] ?? '') === 'enumeration'
            ? ($enumLabels[(int) $value] ?? '')
            : trim((string) $value);

        // Пустое поле: «[3]» без подписи ничего не говорит — тогда как ID.
        return $text !== '' ? $text . ' [' . $id . ']' : '#' . $id;
    }

    /**
     * Подписи вариантов списка: [ID варианта => текст].
     *
     * @return array<int, string>
     */
    private function enumLabels(int $userFieldId): array
    {
        $out = [];
        $rs  = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $userFieldId]);
        while ($row = $rs->Fetch()) {
            $out[(int) $row['ID']] = (string) $row['VALUE'];
        }

        return $out;
    }

    /**
     * Highload-блок по значению ->entity(): ID, имя сущности или имя таблицы.
     *
     * Резолв живёт в самом поле: ту же настройку разбирает правило валидации
     * HighloadRowExists, и второй копии этого кода быть не должно.
     *
     * @return array{ID:int, NAME:string, TABLE_NAME:string}|null
     */
    private function resolveHlblock(int|string $hlblock): ?array
    {
        return \Structura\Structura\Fields\HighloadElement::resolveBlock($hlblock);
    }

    /**
     * ID инфоблока по значению ->iblock() (ID или CODE).
     *
     * Нужно фронтенду: попап выбора элемента понимает только числовой
     * IBLOCK_ID, а в конфиге может стоять CODE.
     *
     * Тип $iblock не объявлен — см. hlrowsAction(): union-тип в сигнатуре
     * действия роняет биндер Engine. ID или CODE разбирает resolveIblockId().
     *
     * @param int|string $iblock
     *
     * @return array{id: int|null}
     */
    public function iblockAction($iblock): array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return ['id' => null];
        }

        $id = $this->resolveIblockId($iblock);
        if ($id === null) {
            return ['id' => null];
        }

        // Проверяем право видеть этот инфоблок: иначе действие работало бы
        // оракулом — по коду возвращало ID закрытого инфоблока. Спрашиваем то же
        // самое, что и iblocks: CIBlock::GetList сам фильтрует по правам.
        $visible = \CIBlock::GetList([], ['ID' => $id, 'CHECK_PERMISSIONS' => 'Y'])->Fetch();

        return ['id' => $visible ? $id : null];
    }

    /**
     * Можно ли смотреть Highload-блоки.
     *
     * Только админ — так же закрыта админка модуля highloadblock
     * (highloadblock_index.php: `if (!$USER->IsAdmin()) AuthForm(...)`). Своих
     * прав у модуля нет, дробить доступ тоньше нам не по чину.
     */
    private function canViewHighloads(): bool
    {
        global $USER;

        return is_object($USER) && $USER->IsAdmin();
    }

    /**
     * Элемент инфоблока по ID — для поля IblockElement.
     *
     * Как и у User: попап отдаёт только ID, а «что это за элемент» — вопрос к
     * серверу. Имя инфоблока отдаём тоже: когда ->iblock() не задан, выбирать
     * можно из любого инфоблока, и без этого непонятно, что за элемент.
     *
     * @return array{element: array{id:int, name:string, iblockId:int, iblockName:string}|null}
     */
    public function elementAction(int $id): array
    {
        if ($id <= 0 || !\Bitrix\Main\Loader::includeModule('iblock')) {
            return ['element' => null];
        }

        // Название элемента — это его данные: без права читать сам элемент
        // (element_read) отдавать его нельзя, иначе перебором ID вычитывается
        // содержимое закрытых инфоблоков. Проверка та же, что у чтения значений.
        if (!$this->canReadEntity(ValueTable::ENTITY_ELEMENT, $id)) {
            return ['element' => null];
        }

        $row = \Bitrix\Iblock\ElementTable::getList([
            'select' => ['ID', 'NAME', 'IBLOCK_ID', 'IBLOCK_NAME' => 'IBLOCK.NAME'],
            'filter' => ['=ID' => $id],
            'limit'  => 1,
        ])->fetch();

        if (!$row) {
            return ['element' => null];
        }

        return ['element' => [
            'id'         => (int) $row['ID'],
            'name'       => (string) $row['NAME'],
            'iblockId'   => (int) $row['IBLOCK_ID'],
            'iblockName' => (string) ($row['IBLOCK_NAME'] ?? ''),
        ]];
    }

    /**
     * Имя пользователя по ID — для поля User.
     *
     * Само значение поля выбирается стандартным попапом Битрикса, который
     * отдаёт только ID; это действие нужно, чтобы рядом с ID показать, кто это.
     * Имя собирается CUser::FormatName в том же формате, что и в списках
     * админки (учитывает настройку формата имени на сайте).
     *
     * @return array{user: array{id:int, name:string, login:string}|null}
     */
    public function userAction(int $id): array
    {
        // Ровно та же проверка, что у попапа, который это поле и открывает
        // (main/tools/user_search.php): без права смотреть пользователей нельзя
        // и узнать имя по ID — иначе перебором ID выгружается вся база
        // пользователей мимо попапа.
        global $USER;
        $canViewUsers = is_object($USER)
            && ($USER->CanDoOperation('view_subordinate_users') || $USER->CanDoOperation('view_all_users'));

        if (!$canViewUsers) {
            return ['user' => null];
        }

        if ($id <= 0) {
            return ['user' => null];
        }

        $row = \Bitrix\Main\UserTable::getList([
            'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
            'filter' => ['=ID' => $id],
            'limit'  => 1,
        ])->fetch();

        if (!$row) {
            return ['user' => null];
        }

        return ['user' => [
            'id'    => (int) $row['ID'],
            'name'  => trim(\CUser::FormatName(\CSite::GetNameFormat(), $row, true, false)),
            'login' => (string) $row['LOGIN'],
        ]];
    }

    /**
     * Справочник разделов инфоблока для поля IBlockSection.
     *
     * Порядок — по LEFT_MARGIN, то есть обход дерева сверху вниз, как в
     * стандартных списках разделов Битрикса; `depth` отдаётся для отступа,
     * чтобы вложенность была видна в плоском списке.
     *
     * `iblock` — ID или CODE (из ->iblock()); CODE резолвится в ID. Без
     * инфоблока справочник пуст: все разделы всех инфоблоков — не список,
     * а свалка.
     *
     * Тип $iblock не объявлен — см. hlrowsAction(): union-тип в сигнатуре
     * действия роняет биндер Engine.
     *
     * @param int|string|null $iblock
     *
     * @return array{sections: array<int, array{id:int, name:string, depth:int}>}
     */
    public function sectionsAction(
        $iblock = null,
        ?string $search = null,
        int $page = 1,
        int $limit = self::REF_PAGE_SIZE
    ): array {
        $empty = ['sections' => [], 'hasMore' => false];

        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return $empty;
        }

        $iblockId = $this->resolveIblockId($iblock);
        if ($iblockId === null) {
            return $empty;
        }

        $filter = ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'Y'];

        $search = trim((string) $search);
        if ($search !== '') {
            // Только по имени, вхождением — как в фильтрах админки.
            //
            // Без OR-подфильтров: старый фильтр CIBlockSection::GetList их не
            // понимает и молча игнорирует (возвращает весь список вместо
            // совпадений). Поэтому искать по имени или по ID одним запросом
            // здесь нельзя.
            $filter['%NAME'] = $search;
        }

        // CIBlockSection::GetList с CHECK_PERMISSIONS, а не SectionTable (D7):
        // старое ядро само отсеивает разделы, которые пользователю не видны
        // (section_read), — так же строит списки разделов сама админка.
        // D7-таблица прав не знает и отдавала всё дерево любому авторизованному.
        //
        // При поиске сортируем по имени: LEFT_MARGIN (порядок дерева) осмыслен
        // только когда дерево показано целиком, а в выдаче поиска предки обычно
        // отсутствуют.
        // Страницы — через nPageSize/iNumPage, а не nTopCount: nTopCount это LIMIT
        // без смещения, а смещения GetList не понимает (nOffset игнорирует), и
        // «показать ещё» вернуло бы ту же первую страницу.
        $page = max(1, $page);
        $rows = \CIBlockSection::GetList(
            $search !== '' ? ['NAME' => 'ASC'] : ['LEFT_MARGIN' => 'ASC'],
            $filter,
            false,
            ['ID', 'NAME', 'DEPTH_LEVEL'],
            ['nPageSize' => $limit, 'iNumPage' => $page, 'bShowAll' => false]
        );

        $sections = [];
        while ($row = $rows->Fetch()) {
            $sections[] = [
                'id'    => (int) $row['ID'],
                'name'  => (string) $row['NAME'],
                // Отступ вложенности осмыслен только у полного дерева.
                'depth' => $search !== '' ? 0 : max(0, (int) $row['DEPTH_LEVEL'] - 1),
            ];
        }

        // COUNT ядро в этом режиме делает само (иначе не собрать навигацию),
        // поэтому общее число достаётся бесплатно — берём его, а не гадаем.
        $total = (int) $rows->SelectedRowsCount();

        return [
            'sections' => $sections,
            'hasMore'  => $page * $limit < $total,
        ];
    }

    /**
     * Один раздел по ID — показать подпись уже выбранного значения.
     *
     * Нужен из-за пагинации: сохранённый раздел может не попасть в текущую
     * страницу выдачи, и показывать голый ID вместо названия — плохо.
     *
     * @return array{section: array{id:int, name:string}|null}
     */
    public function sectionAction(int $id): array
    {
        if ($id <= 0 || !\Bitrix\Main\Loader::includeModule('iblock')) {
            return ['section' => null];
        }

        // Права те же, что у списка: CHECK_PERMISSIONS. Иначе перебором ID
        // читались бы названия разделов закрытых инфоблоков.
        $row = \CIBlockSection::GetList(
            [],
            ['ID' => $id, 'CHECK_PERMISSIONS' => 'Y'],
            false,
            ['ID', 'NAME']
        )->Fetch();

        return ['section' => $row
            ? ['id' => (int) $row['ID'], 'name' => (string) $row['NAME']]
            : null];
    }

    /**
     * ID инфоблока из значения ->iblock(): число — уже ID, строка — CODE.
     *
     * Числовая строка считается ID: настройка приезжает из JSON, где ID мог
     * стать строкой. CODE инфоблока не бывает чисто числовым в осмысленных
     * конфигах, а вот ID-строка — обычное дело.
     */
    private function resolveIblockId(int|string|null $iblock): ?int
    {
        if ($iblock === null || $iblock === '') {
            return null;
        }

        if (is_int($iblock) || ctype_digit((string) $iblock)) {
            $id = (int) $iblock;

            return $id > 0 ? $id : null;
        }

        $row = \Bitrix\Iblock\IblockTable::getList([
            'select' => ['ID'],
            'filter' => ['=CODE' => (string) $iblock],
            'limit'  => 1,
        ])->fetch();

        return $row ? (int) $row['ID'] : null;
    }

    /**
     * Справочник Highload-блоков для поля Highload.
     *
     * Показываем то же, что в списке HL-блоков админки: название сущности и
     * таблицу. `title` — локализованное имя из b_hlblock_lang для текущего
     * языка (в админке в заголовке стоит именно оно), может отсутствовать.
     *
     * @return array{highloads: array<int, array{id:int, name:string, table:string, title:?string}>}
     */
    public function highloadsAction(): array
    {
        // Только админ — так же закрыт список HL-блоков в самой админке
        // (highloadblock_index.php: `if (!$USER->IsAdmin()) AuthForm(...)`).
        // Своих прав у модуля highloadblock нет, поэтому и у нас IsAdmin().
        if (!$this->canViewHighloads()) {
            return ['highloads' => []];
        }

        if (!\Bitrix\Main\Loader::includeModule('highloadblock')) {
            return ['highloads' => []];
        }

        $rows = \Bitrix\Highloadblock\HighloadBlockTable::getList([
            'select' => ['ID', 'NAME', 'TABLE_NAME', 'LANG_NAME' => 'LANG.NAME'],
            'order'  => ['NAME' => 'ASC'],
        ])->fetchAll();

        $highloads = [];
        foreach ($rows as $row) {
            $title = (string) ($row['LANG_NAME'] ?? '');
            $highloads[] = [
                'id'    => (int) $row['ID'],
                'name'  => (string) $row['NAME'],
                'table' => (string) $row['TABLE_NAME'],
                'title' => $title !== '' ? $title : null,
            ];
        }

        return ['highloads' => $highloads];
    }

    /**
     * Справочник инфоблоков для поля Iblock.
     *
     * Возвращает то же, что видит контент-менеджер в стандартных списках
     * Битрикса: активные инфоблоки, отсортированные по SORT и названию,
     * с типом в качестве группировки. `iblockType` — фильтр из ->type().
     *
     * @return array{iblocks: array<int, array{id:int, name:string, code:?string, type:string}>}
     */
    public function iblocksAction(?string $iblockType = null): array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return ['iblocks' => []];
        }

        // CIBlock::GetList, а не IblockTable (D7): старое ядро само фильтрует
        // список по правам — при отсутствии CHECK_PERMISSIONS=>'N' оно берёт
        // MIN_PERMISSION (по умолчанию CIBlockRights::PUBLIC_READ) и отдаёт
        // только доступные инфоблоки; админ видит всё. D7-таблица прав не знает
        // вообще, и справочник отдавал структуру сайта любому авторизованному.
        // Так же список инфоблоков строит сама админка (iblock_admin.php).
        $filter = ['ACTIVE' => 'Y'];
        if ($iblockType !== null && $iblockType !== '') {
            $filter['TYPE'] = $iblockType;
        }

        $rows = \CIBlock::GetList(
            ['IBLOCK_TYPE_ID' => 'ASC', 'SORT' => 'ASC', 'NAME' => 'ASC'],
            $filter
        );

        $iblocks = [];
        while ($row = $rows->Fetch()) {
            $iblocks[] = [
                'id'   => (int) $row['ID'],
                'name' => (string) $row['NAME'],
                'code' => $row['CODE'] !== null && $row['CODE'] !== '' ? (string) $row['CODE'] : null,
                'type' => (string) $row['IBLOCK_TYPE_ID'],
            ];
        }

        return ['iblocks' => $iblocks];
    }

    /**
     * Экземпляры блоков сущности в порядке отображения.
     *
     * @return array{instances: array<int, array>}
     */
    public function instancesAction(string $entityType, int $entityId): array
    {
        if (!$this->canReadEntity($entityType, $entityId)) {
            $this->denyRead();

            return ['instances' => []];
        }

        return ['instances' => (new ValueService())->listInstances($entityType, $entityId)];
    }

    /**
     * Удаление экземпляра блока вместе с его историей ревизий.
     *
     * @return array{status: string}|null
     */
    public function removeAction(string $blockCode, string $entityType, int $entityId, string $instanceId): ?array
    {
        if (!$this->canEditEntity($entityType, $entityId)) {
            $this->denyEdit();

            return null;
        }

        // Код блока для проверки политики берём по экземпляру с сервера, а не из
        // запроса: delete адресует строку одним instanceId (код блока в ключ не
        // входит, как и в save). Доверься мы клиентскому blockCode — послав код
        // открытого блока (или несуществующего, что пропускала ветка «блока
        // нет») с instanceId запертого, запертый экземпляр можно было бы удалить
        // мимо его политики. Так же поступает reorderAction.
        $service   = new ValueService();
        $blockCode = $this->blockCodeOfInstance($service, $entityType, $entityId, $instanceId);
        if ($blockCode === null) {
            $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_INSTANCE_NOT_FOUND')));

            return null;
        }

        if (!$this->allowedByPolicy($blockCode)) {
            return null;
        }

        $result = $service->delete($blockCode, $entityType, $entityId, $instanceId);

        if (!$result->isSuccess()) {
            foreach ($result->getErrors() as $error) {
                $this->addError($error);
            }

            return null;
        }

        return ['status' => 'ok'];
    }

    /**
     * Новый порядок экземпляров на сущности (позиция = место в массиве).
     *
     * @param array<int, string> $instanceIds
     * @return array{status: string}|null
     */
    public function reorderAction(string $entityType, int $entityId, array $instanceIds = []): ?array
    {
        if (!$this->canEditEntity($entityType, $entityId)) {
            $this->denyEdit();

            return null;
        }

        // Порядок блоков — тоже содержимое страницы: подвинуть запертый блок
        // вниз значит изменить то, что человеку менять не разрешено. Код блока
        // берём по экземпляру с сервера — в запросе его нет вовсе.
        $blockOf = [];
        foreach ((new ValueService())->listInstances($entityType, $entityId) as $instance) {
            $blockOf[$instance['instanceId']] = $instance['blockCode'];
        }

        foreach ($instanceIds as $instanceId) {
            if (isset($blockOf[$instanceId]) && !$this->allowedByPolicy($blockOf[$instanceId])) {
                return null;
            }
        }

        $result = (new ValueService())->reorder($entityType, $entityId, $instanceIds);

        if (!$result->isSuccess()) {
            foreach ($result->getErrors() as $error) {
                $this->addError($error);
            }

            return null;
        }

        return ['status' => 'ok'];
    }

    /**
     * @return array{blocks: array<int, array>}
     */
    public function configAction(
        string $entityType,
        int $entityId,
        ?int $iblockId = null,
        ?string $iblockCode = null
    ): array {
        if (!$this->canReadEntity($entityType, $entityId)) {
            $this->denyRead();

            return ['blocks' => []];
        }

        $blocks = [];
        foreach (Loader::forEntity($entityType, $entityId, $iblockId, $iblockCode) as $block) {
            // describe, а не toArray: пикер группирует блоки и должен знать про
            // unique-группы.
            $blocks[] = Loader::describe($block);
        }

        // Языки — глобальные: фронтенд рисует вкладки у ->translatable()-блоков.
        // fallback помечается как язык по умолчанию (его значения в базе).
        return [
            'blocks'           => $blocks,
            'languages'        => Loader::languages(),
            'languageFallback' => Loader::languageFallback(),
        ];
    }

    /**
     * @return array{values: array<string, mixed>, filled: bool}
     */
    public function valuesAction(
        string $blockCode,
        string $entityType,
        int $entityId,
        ?string $instanceId = null,
        ?string $language = null
    ): array {
        if (!$this->canReadEntity($entityType, $entityId)) {
            $this->denyRead();

            return ['values' => [], 'filled' => false];
        }

        // Значения именно этого языка (без кросс-языкового фолбэка): пустая
        // вкладка перевода должна остаться пустой, чтобы сработал prefill.
        $service = new ValueService();

        // `filled` — переводили ли уже этот язык. Отдельным запросом, потому что
        // по values этого не видно: getWithDefaults подставляет умолчания, и
        // нетронутый перевод выглядит точно как заполненный умолчаниями.
        return [
            'values' => $service->getWithDefaults($blockCode, $entityType, $entityId, $instanceId, $language),
            'filled' => $service->isFilled($blockCode, $entityType, $entityId, $instanceId, $language),
        ];
    }

    /**
     * Заполнить вкладку языка значениями другого языка (по умолчанию — базы).
     *
     * Ничего не сохраняет: отдаёт значения форме, дальше решает пользователь и
     * кнопка «Сохранить». Файлы не дублируются ни здесь, ни при сохранении —
     * языки одного экземпляра делят один файл, как и клон строки (cloneRowAction).
     *
     * Уже заполненный язык не перезаписываем: prefilled=false и его же
     * значения. Кнопка показывается только у пустой вкладки, но повторный клик
     * (или собранный руками запрос) не должен затирать перевод.
     *
     * @return array{values: array, prefilled: bool}|null null — ошибка (лежит в getErrors)
     */
    public function prefillAction(
        string $blockCode,
        string $entityType,
        int $entityId,
        string $language,
        ?string $instanceId = null,
        ?string $from = null
    ): ?array {
        if (!$this->canEditEntity($entityType, $entityId)) {
            $this->denyEdit();

            return null;
        }

        $block = Loader::load($blockCode);
        if ($block === null) {
            $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_BLOCK_NOT_FOUND', ['#BLOCK#' => $blockCode])));

            return null;
        }

        if (!$block->isTranslatable()) {
            $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_BLOCK_NOT_TRANSLATABLE')));

            return null;
        }

        // Заполнение — часть правки: оно готовит значения к сохранению и по
        // дороге дублирует файлы. Блок под запретом политики не заполняем, иначе
        // тот, кому править нельзя, плодил бы копии файлов и получал форму,
        // которую всё равно не сохранить.
        if (!$this->allowedByPolicy($blockCode)) {
            return null;
        }

        // Базовый язык заполнять неоткуда: он и есть источник.
        if ($language === '' || $language === Loader::languageFallback()) {
            $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_DEFAULT_LANG_MANUAL')));

            return null;
        }

        // Метки языков — из конфигурации, а не из запроса: выдуманный язык
        // означал бы копирование файлов «в никуда» (сохранить такой перевод
        // ValueService всё равно не даст).
        foreach ([$language, $from] as $candidate) {
            if ($candidate !== null && $candidate !== '' && !in_array($candidate, Loader::languages(), true)) {
                $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_LANG_NOT_CONFIGURED', ['#LANG#' => $candidate])));

                return null;
            }
        }

        $service = new ValueService();

        // Экземпляр должен принадлежать этому блоку на этой сущности: иначе,
        // подставив код открытого блока к instanceId запертого, можно было бы
        // прочитать и продублировать файлы чужого экземпляра мимо его политики.
        // Тот же приём, что в remove/reorder — источник правды БД, а не запрос.
        if ($instanceId !== null && $instanceId !== '') {
            $owner = $this->blockCodeOfInstance($service, $entityType, $entityId, $instanceId);
            if ($owner !== null && $owner !== $blockCode) {
                $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_INSTANCE_OTHER_BLOCK')));

                return null;
            }
        }
        if ($service->isFilled($blockCode, $entityType, $entityId, $instanceId, $language)) {
            return [
                'values'    => $service->getWithDefaults($blockCode, $entityType, $entityId, $instanceId, $language),
                'prefilled' => false,
            ];
        }

        // Значения отдаём как есть, вместе с ID файлов источника: форма должна
        // показать те же картинки, и они же лягут в перевод при сохранении —
        // своей копии файла у языка теперь не заводится вовсе.
        //
        // `_id` строк тоже оставляем: слияние политик сопоставляет строки в
        // пределах одного языка, и совпадение id между языками ему не мешает —
        // зато строка перевода остаётся привязанной к своей строке оригинала.
        return [
            'values'    => $service->getWithDefaults($blockCode, $entityType, $entityId, $instanceId, $from),
            'prefilled' => true,
        ];
    }

    /**
     * Сохранение значений. При ошибках валидации возвращает их в errors
     * (code каждой ошибки — путь к полю), данные — null.
     *
     * `valuesJson` важнее `values`. Форма шлёт значения дважды: как обычный
     * массив (`values`) и как JSON-строку (`valuesJson`). Причина — потеря
     * пустых коллекций при form-кодировании: `BX.ajax.prepareData` обходит
     * значение циклом `for..in`, и пустой массив `[]` (снятые все флажки
     * Checkbox, очищенный множественный Select, пустой Repeater) не даёт ни
     * одного поля — на сервер не приходит вовсе. Тогда `getWithDefaults` считает
     * поле незаполненным и подставляет умолчание обратно. JSON пустой массив
     * сохраняет, поэтому если строка пришла — она и авторитетна.
     *
     * Прямые вызовы (тесты, серверный код) `valuesJson` не передают и работают
     * с массивом `values`.
     *
     * @param array<string, mixed> $values
     * @return array{status: string, values: array}|null
     */
    public function saveAction(
        string $blockCode,
        string $entityType,
        int $entityId,
        array $values = [],
        ?string $instanceId = null,
        bool $active = true,
        ?string $activeFrom = null,
        ?string $activeTo = null,
        ?string $language = null,
        ?string $valuesJson = null
    ): ?array {
        // JSON-версия значений авторитетна: только она доносит пустые коллекции
        // целыми (см. докблок). Некорректный JSON игнорируем — останется массив.
        if ($valuesJson !== null && $valuesJson !== '') {
            $decoded = json_decode($valuesJson, true);
            if (is_array($decoded)) {
                $values = $decoded;
            }
        }

        if (!$this->canEditEntity($entityType, $entityId)) {
            $this->denyEdit();

            return null;
        }

        // Активность едет в том же запросе, что и значения (форма всегда шлёт
        // текущее состояние переключателя и дат), поэтому задаём её при каждом
        // сохранении из админки.
        $from = self::parseDate($activeFrom);
        $to   = self::parseDate($activeTo);

        // Нераспознанная дата — отказ, а не «ограничения нет». Иначе окно
        // активности молча не задавалось бы, а форма рапортовала успех.
        $edges = [
            'STRUCTURA_CONTROLLER_DATE_EDGE_FROM' => $from,
            'STRUCTURA_CONTROLLER_DATE_EDGE_TO'   => $to,
        ];
        foreach ($edges as $edgeKey => $parsed) {
            if ($parsed === false) {
                $this->addError(new \Bitrix\Main\Error((string) Loc::getMessage('STRUCTURA_CONTROLLER_BAD_ACTIVITY_DATE', [
                    '#EDGE#' => (string) Loc::getMessage($edgeKey),
                ])));

                return null;
            }
        }

        $activity = [
            'active' => $active,
            'from'   => $from,
            'to'     => $to,
        ];

        $result = (new ValueService())->save(
            $blockCode,
            $entityType,
            $entityId,
            $values,
            null,
            null,
            null,
            $instanceId,
            $activity,
            $language
        );

        if (!$result->isSuccess()) {
            foreach ($result->getErrors() as $error) {
                $this->addError($error);
            }

            return null;
        }

        return [
            'status'     => 'ok',
            // values — то, что реально сохранено: политики могли откатить часть
            // полей к прежним значениям, и клиент должен показать именно их.
            'values'     => $result->getData()['values'] ?? $values,
            'instanceId' => $result->getData()['instanceId'] ?? $instanceId,
            // denied — путь поля => причина. Не ошибки: save успешен, просто
            // часть полей осталась прежней. Нужно, чтобы пользователь понимал,
            // почему его правка не применилась.
            'denied'     => $result->getData()['denied'] ?? [],
        ];
    }

    /**
     * Страница списка ревизий сущности (мета, без значений). Новые сверху.
     *
     * total — всего ревизий, чтобы клиент знал, осталось ли что догружать.
     *
     * @return array{revisions: array<int, array>, total: int}
     */
    public function revisionsAction(
        string $blockCode,
        string $entityType,
        int $entityId,
        int $limit = 0,
        int $offset = 0,
        ?string $instanceId = null,
        ?string $language = null
    ): array {
        if (!$this->canReadEntity($entityType, $entityId)) {
            $this->denyRead();

            return ['revisions' => [], 'total' => 0];
        }

        $service = new RevisionService();
        // История по-языковая. Вкладка fallback собирает снимки базы (метка
        // fallback + легаси ''), остальные — свой язык; расширение делает
        // RevisionService::langScope. null (непереводимый блок) — без фильтра.
        $lang = $this->revisionLangFilter($blockCode, $language);

        return [
            'revisions' => $service->listFor($blockCode, $entityType, $entityId, $limit, $offset, $instanceId, $lang),
            'total'     => $service->countFor($blockCode, $entityType, $entityId, $instanceId, $lang),
        ];
    }

    /**
     * Метка языка вкладки → фильтр истории: fallback-вкладка отдаёт '' (его
     * RevisionService::langScope расширит до «метка fallback + легаси ''»),
     * остальные — свой язык. null / непереводимый блок → null (весь список).
     */
    private function revisionLangFilter(string $blockCode, ?string $language): ?string
    {
        if ($language === null) {
            return null;
        }

        $block = Loader::load($blockCode);
        if ($block === null || !$block->isTranslatable()) {
            return null;
        }

        return ($language === '' || $language === Loader::languageFallback()) ? '' : $language;
    }

    /**
     * Одна ревизия с декодированными значениями (для предпросмотра).
     *
     * Сущность берём из самой ревизии, а не из запроса: действие принимает
     * только ID ревизии, и поверить клиенту на слово «это моя ревизия» значило
     * бы отдать историю любого элемента по перебору ID.
     *
     * @return array{revision: array}|null
     */
    public function revisionAction(int $revisionId): ?array
    {
        $revision = (new RevisionService())->get($revisionId);
        if ($revision === null) {
            return null;
        }

        if (!$this->canReadEntity((string) $revision['entityType'], (int) $revision['entityId'])) {
            $this->denyRead();

            return null;
        }

        return ['revision' => $revision];
    }

    /**
     * Откат значений блока к ревизии. Создаёт новую ревизию (append-only).
     * При ошибках валидации возвращает их в errors (code — путь к полю).
     *
     * @return array{status: string, values: array}|null
     */
    public function rollbackAction(
        string $blockCode,
        string $entityType,
        int $entityId,
        int $revisionId,
        ?string $instanceId = null
    ): ?array {
        if (!$this->canEditEntity($entityType, $entityId)) {
            $this->denyEdit();

            return null;
        }

        // Откат запертого политикой блока иначе «удался» бы, ничего не меняя:
        // PolicyGuard внутри сохранения вернул бы каждому полю старое значение.
        // Отказ с причиной честнее — как у удаления и перестановки. Код блока
        // берём по экземпляру из базы: клиент мог бы прислать код открытого блока
        // к запертому экземпляру.
        $owner = $instanceId !== null && $instanceId !== ''
            ? $this->blockCodeOfInstance(new ValueService(), $entityType, $entityId, $instanceId)
            : null;

        if (!$this->allowedByPolicy($owner ?? $blockCode)) {
            return null;
        }

        $result = (new RevisionService())->rollback(
            $blockCode,
            $entityType,
            $entityId,
            $revisionId,
            null,
            $instanceId
        );

        if (!$result->isSuccess()) {
            foreach ($result->getErrors() as $error) {
                $this->addError($error);
            }

            return null;
        }

        return [
            'status' => 'ok',
            'values' => $result->getData()['values'] ?? [],
        ];
    }
}
