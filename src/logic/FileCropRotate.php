<?php
/**
 * Created by PhpStorm.
 * User: floor12
 * Date: 03.01.2018
 * Time: 19:37
 */

namespace modules\files\logic;


use modules\files\models\File;
use modules\files\models\FileType;
use Yii;
use yii\base\ErrorException;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

class FileCropRotate
{
    private $_file;
    private $_width;
    private $_height;
    private $_top;
    private $_left;
    private $_rotated;


    public function __construct(array $data)
    {
        $this->_file = File::findOne($data['id']);

        if (!$this->_file)
            throw new NotFoundHttpException('File not found');

        if ($this->_file->type != FileType::IMAGE)
            throw new BadRequestHttpException('Requested file is not an image.');

        if (!file_exists($this->_file->rootPath))
            throw new BadRequestHttpException('File not found in file storage.');

        $this->_height = (int)$data['height'];
        $this->_width = (int)$data['width'];
        $this->_top = (int)$data['top'];
        $this->_left = (int)$data['left'];
        $this->_rotated = (int)($data['rotated'] ?? 0);

        if (!$this->_height && !$this->_width) {
            list($this->_width, $this->_height) = getimagesize($this->_file->rootPath);
        }

    }

    public function execute()
    {

        $src = $this->imageCreateFromAny();

        $src = imagerotate($src, -$this->_rotated, 0);

        $dest = imagecreatetruecolor($this->_width, $this->_height);

        imagecopy($dest, $src, 0, 0, $this->_left, $this->_top, $this->_width, $this->_height);

        $module = Yii::$app->getModule('files');
        $isMinio = $module->storageDriver === 'minio';
        $workingDirectory = $isMinio
            ? rtrim($module->cacheFullPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'transforms'
            : $module->storageFullPath;
        $newName = new PathGenerator($workingDirectory) . '.jpeg';

        $newPath = $workingDirectory . '/' . $newName;

        $oldPath = $this->_file->rootPath;
        $oldFilename = $this->_file->filename;
        $oldContentType = $this->_file->content_type;

        imagejpeg($dest, $newPath, 80);

        imagedestroy($dest);

        imagedestroy($src);

        $this->_file->filename = $newName;
        $this->_file->content_type = $this->_file->mime_content_type($newPath);
        $this->_file->size = filesize($newPath);
        $this->_file->changeHash();
        if ($this->_file->save()) {
            if ($isMinio) {
                $this->_file->getStorage()->putFile(
                    $this->_file->getOriginalStorageKey(),
                    $newPath,
                    $this->_file->content_type
                );
                $this->_file->getStorage()->deleteVariants($this->_file->filename);
                $this->_file->getStorage()->delete(
                    $this->_file->getStorage()->originalKey($oldFilename, $oldContentType)
                );
                @unlink($newPath);
            } else {
                @unlink($oldPath);
            }
            return $this->_file->href;
        } else
            throw new ErrorException("Error while saving file model.");


    }


    /**
     * Method to read files from any mime types
     * @return resource
     * @throws BadRequestHttpException
     */

    private function imageCreateFromAny()
    {
        $type = exif_imagetype($this->_file->rootPath);
        $allowedTypes = array(
            1, // [] gif
            2, // [] jpg
            3, // [] png
            6   // [] bmp
        );
        if (!in_array($type, $allowedTypes)) {
            throw new BadRequestHttpException('File must have GIF, JPG, PNG or BMP mime-type.');

        }
        switch ($type) {
            case 1 :
                $im = imageCreateFromGif($this->_file->rootPath);
                break;
            case 2 :
                $im = imageCreateFromJpeg($this->_file->rootPath);
                break;
            case 3 :
                $im = imageCreateFromPng($this->_file->rootPath);
                break;
            case 6 :
                $im = imageCreateFromBmp($this->_file->rootPath);
                break;
        }
        return $im;
    }
}
