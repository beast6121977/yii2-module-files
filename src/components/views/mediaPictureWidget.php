<?php
/**
 * @var $this View
 * @var $model File
 * @var $width array Формат: ['min-width: 500px' => 150, 'max-width: 500px' => 350]
 *                   где ключ - медиа-запрос (строка без скобок), значение - ширина в пикселях (int)
 * @var $classPicture string
 * @var $classImg string
 * @var $alt string
 */

use modules\files\models\File;
use yii\helpers\Html;
use yii\web\View;

?>

<?php

if (!is_array($width)) {
    $width = [$width];
}

// Получаем последнее значение из массива для fallback изображения
$widthValues = array_values($width);
$fallbackWidth = !empty($widthValues) ? end($widthValues) : 0;
?>
<picture<?= $classPicture ? " class=\"{$classPicture}\"" : NULL ?>>
    <?php foreach ($width as $widthMediaQuery => $widthValue) { ?>
        <source
                type="image/webp"
                media='(<?= $widthMediaQuery ?>)'
                srcset="
                <?= $model->getPreviewWebPath(1.5 * $widthValue, true) ?> 1x,
                <?= $model->getPreviewWebPath(2 * $widthValue, true) ?> 2x">
    <?php } ?>
    <?php foreach ($width as $widthMediaQuery => $widthValue) { ?>
        <source
                type="image/jpeg"
                media='(<?= $widthMediaQuery ?>)'
                srcset="
                <?= $model->getPreviewWebPath(1.5 * $widthValue) ?> 1x,
                <?= $model->getPreviewWebPath(2 * $widthValue) ?> 2x">
    <?php } ?>

    <?=Html::img($model->getPreviewWebPath($fallbackWidth), ['class' => $classImg, 'alt' => $alt, 'itemprop' => 'image', 'loading' => 'lazy'])?>
</picture>
