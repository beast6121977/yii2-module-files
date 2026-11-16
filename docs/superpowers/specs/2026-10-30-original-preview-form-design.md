# Original preview form design

## Intent

Add a backend-reachable Yii2 CRUD create form for regenerating one WebP preview
from an existing image object in module storage. The operation is useful when a
stored preview must be rebuilt with a chosen width and optional watermark.

## Goals

- Accept an original storage key, positive width, and explicit watermark flag.
- Read only `originals/...` image objects; never treat the form value as a local
  filesystem path.
- Generate `previews/{logical-name}/{width}.webp` through the existing storage
  image pipeline.
- Replace an existing preview at the same deterministic storage key.
- Expose the form through a dedicated module controller create action and
  standard `ActiveForm` view.

## Non-goals and constraints

- No database `file_module` row is created or changed.
- No new storage driver, output format, or watermark configuration field is
  introduced.
- The configured module watermark path is only the source asset; the checkbox is
  the only switch that enables application for this operation.
- Missing originals, non-image objects, invalid widths, traversal-like keys, or
  enabled watermark without a configured file are validation errors.

## Approaches

1. `PreviewForm` + `PreviewController::actionCreate()` +
   `OriginalPreviewCreator`: separate CRUD surface and storage logic; chosen.
2. Add an action to `DefaultController`: fewer files, but mixes administration
   with upload/media endpoints.
3. Construct a transient `File` model: reuses some code, but incorrectly couples
   a storage-key operation to database `class`/`field` state.

## Chosen design

`PreviewForm` owns the three submitted fields and scalar validation.
`OriginalPreviewCreator::create(string $originalKey, int $width, bool $applyWatermark): string`
loads the object through `StorageInterface`, validates image MIME, calls
`AbstractStorage::generatePreview()` for WebP, and returns the deterministic
preview key. `PreviewController` loads the form, catches operation errors as
field errors, flashes the resulting key, and redirects back to `create`.
