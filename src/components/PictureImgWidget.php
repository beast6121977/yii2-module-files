<?php

namespace modules\files\components;

use yii\base\Widget;
use yii\helpers\Html;

/**
 * @extends Widget
 */
class PictureImgWidget extends Widget
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
    /** @var string */
    public $height = null;
    public $storage;

    /**
     * @return string|null
     */
    public function run(): ?string
    {
        if (!is_array($this->width))
            return null;

        $module = \Yii::$app->getModule('files');
        $this->storage = $module->getStorage();

        $originalKey = 'originals'.DIRECTORY_SEPARATOR.basename($this->src);
        $type = $this->storage->mime($originalKey);

        $sourceWebp = $this->renderSource($this->width, 'image/webp');
        $sourceJpeg = $this->renderSource($this->width, $type);
        $image = ImgWidget::widget([
            'src' => $this->src,
            'width' => end($this->width),
            'class' => $this->classImg,
            'alt' => $this->alt,
            'title' => $this->title,
        ]);

        return Html::tag('picture', $sourceWebp . $sourceJpeg . $image, []);
    }

    private function renderSource(array $width, $type): string
    {
        $result = '';
        $is_webp = $type == 'image/webp';

        foreach ($width as $widthMediaQuery => $widthValue) {
            $url = $this->storage->publicUrl($this->storage->previewKey($this->src, $type, $widthValue, $is_webp));
            $url2x = $this->storage->publicUrl($this->storage->previewKey($this->src, $type, $widthValue * 2, $is_webp));

            $result .= (!empty($url) && !empty($url2x)) ? Html::tag('source', null, [
                'type' => $type,
                'media' => $widthMediaQuery,
                'srcset' => "{$url} 1x, {$url2x} 2x",
            ]) : null;
        }

        return $result;
    }
}
