<?php

namespace modules\files\assets;

use yii\web\AssetBundle;

class LightboxAsset extends AssetBundle
{
    public $sourcePath = '@bower';

    public $css = [
        'lightbox2/dist/css/lightbox.css',
    ];
    public $js = [
        'lightbox2/dist/js/lightbox.min.js',
    ];
    public $depends = [
        'yii\web\JqueryAsset',
    ];
}
