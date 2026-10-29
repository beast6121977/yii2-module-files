<?php

use modules\files\forms\PreviewForm;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var yii\web\View $this */
/** @var PreviewForm $model */

$this->title = 'Создать preview из оригинального изображения';
?>
<div class="preview-create">
    <div class="row">
        <div class="col-6">
            <?php $form = ActiveForm::begin(); ?>
            <?= $form->errorSummary($model) ?>
            <?= $form->field($model, 'originalKey')->textInput() ?>
            <?= $form->field($model, 'width')->input('number', ['min' => 1, 'step' => 1]) ?>
            <?= $form->field($model, 'applyWatermark')->checkbox() ?>
            <?= Html::submitButton('Создать preview', ['class' => 'btn btn-primary']) ?>
            <?php ActiveForm::end(); ?>
        </div>
    </div>
</div>
