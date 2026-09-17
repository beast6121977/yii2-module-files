<?php

namespace modules\files\components;

use yii\base\Widget;
use yii\helpers\Html;

/**
 * @extends Widget
 */
class ImgWidget extends Widget
{
    /** @var array */
    public $width;

    /** @var @var string */
    public $src;
    /** @var string */
    public $alt;
    /** @var string */
    public $title;

    /** @var string */
    public $classImg;

    /**
     * @return string|null
     */
    public function run(): ?string
    {
        $module = \Yii::$app->getModule('files');
        $storage = $module->getStorage();
        $src = '/img/no-photo.svg';
        $previewKey = $storage->previewKey($this->src, 'image/webp', $this->width, true);

        if ($storage->has($previewKey)) {
            $src = $storage->publicUrl($previewKey);
        } else {
            $originalKey = 'originals' . DIRECTORY_SEPARATOR . basename($this->src);

            if ($storage->has($originalKey)) {
                $type = $storage->mime($originalKey);
                $previewKey = $storage->previewKey($this->src, $type, $this->width, false);

                if ($storage->has($previewKey)) {
                    $src = $storage->publicUrl($previewKey);
                }
            }
        }

        return Html::img($src, [
            'class' => $this->classImg,
            'alt' => $this->alt,
            'title' => $this->title,
            'itemprop' => 'image',
            'loading' => 'lazy',
        ]);
    }
}
