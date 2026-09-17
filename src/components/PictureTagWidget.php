<?php


namespace modules\files\components;

use modules\files\models\File;
use modules\files\models\FileType;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * @deprecated
 * @extends Widget
 */
class PictureTagWidget extends Widget
{
    /** @var File */
    public $model;
    /** @var array */
    public $width;
    /** @var string */
    public $alt;
    /** @var string */
    public $title;
    /** @var string */
    public $classPicture;
    /** @var string */
    public $classImg;
    /** @var string */
    public $view = 'pictureTag';
    public $height = null;

    /**
     * @return string|null
     */
    public function run(): ?string
    {
        if (empty($this->model) || !is_array($this->width) || !in_array($this->model->type, [FileType::IMAGE, FileType::VIDEO]))
            return null;

        $sourceWebp = $this->renderSource($this->width, $this->model, 'image/webp');
        $sourceJpeg = $this->renderSource($this->width, $this->model, $this->model->content_type);

        $image = $this->renderImage();

        return Html::tag('picture', $sourceWebp . $sourceJpeg . $image, []);
    }

    private function renderSource(array $width, File $model, $type): string
    {
        $result = '';
        $is_webp = $type == 'image/webp';
        foreach ($width as $widthMediaQuery => $widthValue) {
            $url = $model->getHrefPreview($widthValue, $is_webp);
            $url2x = $model->getHrefPreview($widthValue * 2, $is_webp);

            $result .= (!empty($url) && !empty($url2x)) ? Html::tag('source', null, [
                'type' => $type,
                'media' => $widthMediaQuery,
                'srcset' => "{$url} 1x, {$url2x} 2x",
            ]) : null;
        }

        return $result;
    }

    private function renderImage()
    {
        $fallbackWidth = !empty($this->width) ? reset($this->width) : 0;
        $src = $this->model->getHrefPreview($fallbackWidth, false) ?? '/img/no-photo.svg';

        return Html::img($src, [
            'class' => $this->classImg,
            'alt' => $this->alt,
            'title' => $this->title,
            'itemprop' => 'image',
            'loading' => 'lazy',
            'height' => $this->height,
        ]);
    }
}
