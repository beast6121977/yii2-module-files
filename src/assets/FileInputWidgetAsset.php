<?php

namespace modules\files\assets;


use yii\web\AssetBundle;


class FileInputWidgetAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/../../assets/';

    public $css = [
        'yii2-floor12-files.css',
    ];
    public $js = [
        'yii2-floor12-files.js',
    ];
    public $depends = [
        'yii\web\JqueryAsset',
        'yii\jui\JuiAsset',
        'floor12\notification\NotificationAsset',
        'modules\files\assets\CropperAsset',
        'modules\files\assets\SimpleAjaxUploaderAsset',
    ];
}