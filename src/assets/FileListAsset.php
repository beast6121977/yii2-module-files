<?php

namespace modules\files\assets;


use yii\web\AssetBundle;


class FileListAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/../../assets/';

    public $css = [
        'yii2-floor12-files-block.css',
    ];
    public $js = [
        'yii2-floor12-lightbox-params.js',
        'yii2-floor12-files-block.js',
    ];
    public $depends = [
        'yii\web\JqueryAsset',
        'floor12\notification\NotificationAsset',
        'modules\files\assets\LightboxAsset'
    ];
}