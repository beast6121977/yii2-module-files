<?php

use modules\files\assets\IconHelper;
use yii\bootstrap5\BootstrapPluginAsset;
use yii\helpers\Html;

BootstrapPluginAsset::register($this);

if (YII_ENV == 'test') // This code is only for testing
    echo Html::fileInput('files', null, [
        'id' => "files-upload-field-{$attribute}",
        'class' => 'yii2-files-upload-field',
        'data-modelclass' => $model::className(),
        'data-attribute' => $attribute,
        'data-mode' => 'single',
        'data-name' => $name ?: (new ReflectionClass($model))->getShortName() . "[{$attribute}_ids][]",
        'data-ratio' => $ratio ?? 0,
        'data-block' => $block_id,

    ]) ?>

<div class="floor12-files-widget-single-block files-widget-block" id="files-widget-block_<?= $block_id ?>"
     data-ratio="<?= $ratio ?>">
    <button class="<?= $uploadButtonClass ?>" type="button">
        <div class="icon"><?= IconHelper::PLUS ?></div>
        <?= $uploadButtonText ?>
    </button>
    <?= Html::hiddenInput($name ?: (new ReflectionClass($model))->getShortName() . "[{$attribute}_ids][]", null) ?>
    <div class="floor12-files-widget-list">
        <?php if ($value ?? $model->$attribute) echo $this->render('@modules/files/views/default/_single', [
            'model' => $value ?? $model->$attribute,
            'ratio' => $ratio,
            'name' => $name
        ]) ?>
    </div>
    <div class="clearfix"></div>
</div>
