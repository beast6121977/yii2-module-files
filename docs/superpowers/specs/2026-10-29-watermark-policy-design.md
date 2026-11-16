# Watermark policy design

## Intent

Устранить рассинхронизацию между eager- и lazy-preview: watermark не включается глобальной настройкой модуля и не накладывается по умолчанию. Настройка watermark принадлежит `FileBehaviour`, а каждый вызов генерации получает явный флаг применения.

## Goals

- Использовать `FileBehaviour` как единственный источник watermark policy.
- Разрешить задавать `watermark` и `apply_watermark` непосредственно в объявлении behavior.
- Сохранить совместимость с текущей настройкой file-поля в `attributes`.
- Сделать значение применения по умолчанию `false`.
- Передавать watermark path и apply-флаг в eager preview generation явно.
- Не читать `Module::$watermark` и `Module::$apply_watermark` при принятии решения.

## Non-goals

- Не удалять свойства `Module` немедленно, чтобы не ломать конфигурацию приложения неизвестным свойством.
- Не менять формат ключей хранения, размеры preview или алгоритм наложения.
- Не выполнять миграции или регенерацию уже сохранённых preview автоматически.

## Approach

Сохраняем существующий policy resolver в `File`, но строим его из `FileBehaviour`: behavior-level `watermark`/`apply_watermark` являются настройкой по умолчанию, а одноимённые параметры поля в `attributes` могут её переопределить. `AbstractStorage::generatePreview()` принимает explicit `apply_watermark` и `watermarkPath`; при `false` путь игнорируется, при `true` отсутствующий файл является ошибкой. `createPreviews()` и `createPreviewsByWidths()` передают значения текущего `File`.

## Alternatives considered

- Оставить `Module` источником настройки и только исправить boolean в callers — отклонено: сохраняет глобальный скрытый switch и не выполняет требование единого места.
- Удалить всю настройку из `FileBehaviour` и передавать путь во все вызовы вручную — отклонено: путь перестаёт быть частью model/file policy и ломается lazy-preview.

## Acceptance criteria

- Plain `FileBehaviour` не включает watermark, даже если старые свойства `Module` выставлены в `true`.
- Behavior с `watermark` и `apply_watermark=true` создаёт watermarked eager-preview.
- `apply_watermark=false` не накладывает watermark даже при заданном пути.
- Lazy-preview продолжает использовать ту же policy.
- Focused PHPUnit запускается в Energy `php-test`; host PHP не используется как proof.
