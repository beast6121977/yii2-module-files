<?php

use modules\files\assets\IconHelper;
use yii\bootstrap5\BootstrapPluginAsset;
use yii\helpers\Html;

BootstrapPluginAsset::register($this);

$currentFile = $value ?? $model->$attribute;
if (is_array($currentFile)) {
    $currentFile = $currentFile[array_key_first($currentFile)] ?? null;
}

$inputName = $name ?: (new ReflectionClass($model))->getShortName() . "[{$attribute}_ids][]";

if (YII_ENV == 'test') // This code is only for testing
    echo Html::fileInput('files', null, [
        'id' => "files-upload-field-{$attribute}",
        'class' => 'yii2-files-upload-field',
        'data-modelclass' => $model::className(),
        'data-attribute' => $attribute,
        'data-mode' => 'single',
        'data-name' => $inputName,
        'data-ratio' => $ratio ?? 0,
        'data-block' => $block_id,

    ]) ?>

<div class="floor12-files-widget-single-block files-widget-block" id="files-widget-block_<?= $block_id ?>"
     data-ratio="<?= $ratio ?>">
    <button class="<?= $uploadButtonClass ?>" type="button">
        <div class="icon"><?= IconHelper::PLUS ?></div>
        <?= $uploadButtonText ?>
    </button>
    <?= Html::hiddenInput($inputName, null, ['class' => 'f12-file-input-placeholder']) ?>
    <?php if ($currentFile): ?>
        <?= Html::hiddenInput($inputName, $currentFile->id, ['class' => 'f12-file-input-current']) ?>
    <?php endif; ?>
    <div class="floor12-files-widget-list">
        <?php if ($currentFile) echo $this->render('@modules/files/views/default/_single', [
            'model' => $currentFile,
            'ratio' => $ratio,
            'name' => $name,
            'renderHiddenInput' => false,
        ]) ?>
    </div>
    <div class="clearfix"></div>
</div>
