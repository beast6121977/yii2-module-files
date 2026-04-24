<?php

namespace modules\files\actions;

use modules\files\logic\ImagePreviewer;
use modules\files\models\File;
use modules\files\models\FileType;
use Yii;
use yii\base\Action;
use yii\base\InvalidConfigException;
use yii\web\NotFoundHttpException;
use yii\web\RangeNotSatisfiableHttpException;

class GetFileAction extends Action
{
    /**
     * @throws InvalidConfigException
     * @throws NotFoundHttpException
     * @throws RangeNotSatisfiableHttpException
     */
    public function run($hash)
    {
        $model = File::findOne(['hash' => $hash]);

        if (!$model)
            throw new NotFoundHttpException("Запрашиваемый файл не найден");

        if (!file_exists($model->rootPath))
            throw new NotFoundHttpException('Запрашиваемый файл не найден на диске.');

        Yii::$app->response->headers->set('Last-Modified', date("c", $model->created));
        Yii::$app->response->headers->set('Cache-Control', 'public, max-age=' . (60 * 60 * 24 * 15));

        if ($model->type == FileType::IMAGE && $model->shouldApplyWatermark()) {
            if ($model->isSvg()) {
                throw new NotFoundHttpException('Original SVG cannot be served for a watermarked image.');
            }

            $watermarkPath = $model->getWatermarkPath();
            if (!$watermarkPath || !is_file($watermarkPath)) {
                throw new NotFoundHttpException('Watermark file is not found.');
            }

            $filename = Yii::createObject(ImagePreviewer::class, [$model, 0, false])->getUrl();
            if (!file_exists($filename)) {
                throw new NotFoundHttpException('Processed watermarked file is not found on disk.');
            }

            $stream = fopen($filename, 'rb');
            $contentType = mime_content_type($filename) ?: $model->content_type;

            Yii::$app->response->sendStreamAsFile($stream, $model->title, [
                'inline' => true,
                'mimeType' => $contentType,
                'filesize' => filesize($filename),
            ]);

            return;
        }

        $stream = fopen($model->rootPath, 'rb');
        Yii::$app->response->sendStreamAsFile($stream, $model->title, [
            'inline' => true,
            'mimeType' => $model->content_type,
            'filesize' => $model->size,
        ]);
    }
}
