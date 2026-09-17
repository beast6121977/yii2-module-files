<?php
namespace modules\files\logic;


use modules\files\components\SimpleImage;
use modules\files\models\FileType;
use modules\files\models\File;
use Yii;
use yii\base\ErrorException;
use yii\db\ActiveRecordInterface;

class FileCreateFromPath
{
    private $className;
    private $fieldName;
    private $filePath;
    private $fileName;
    private $storagePath;
    private $model;

    public function __construct(ActiveRecordInterface $model, string $filePath, string $className, string $fieldName, string $storagePath, string $fileName = null)
    {
        $this->model = $model;

        if (!$filePath || !$className || !$fieldName || !$storagePath)
            throw new ErrorException("Empty params not allowed.");

        if (!file_exists($filePath))
            throw new ErrorException("File not found on disk.");

        $this->filePath = $filePath;
        $this->fileName = $fileName;
        $this->fieldName = $fieldName;
        $this->className = $className;
        $this->storagePath = $storagePath;
    }

    /** Основная работа
     * @return bool
     * @throws ErrorException
     */
    public function execute()
    {
        $storage = Yii::$app->getModule('files')->getStorage();
        
        $tmp_extension = explode('?', pathinfo($this->filePath, PATHINFO_EXTENSION));
        $extension = $tmp_extension[0];
        
        $filename = $this->fileName ?? (string)new PathGenerator($this->storagePath) . "." . $extension;
        
        $fileModel = new File();
        $fileModel->field = $this->fieldName;
        $fileModel->class = $this->className;
        $fileModel->filename = $filename;
        $fileModel->title = $filename;
        $fileModel->content_type = $fileModel->mime_content_type($filename);
        $fileModel->type = $this->detectType($fileModel->content_type);
        $fileModel->size = filesize($this->filePath);
        $fileModel->created = time();
        
        if ($fileModel->type == FileType::VIDEO)
            $fileModel->video_status = 0;

        if ($fileModel->save()) {
            $key = $fileModel->getOriginalStorageKey();
            $storage->putFile($key, $this->filePath, $fileModel->content_type);

            if ($fileModel->type == FileType::IMAGE || $fileModel->type == FileType::VIDEO) {
                if ($fileModel->type == FileType::IMAGE) {
                    $this->processImage($fileModel, $storage, $key);
                }
                $fileModel->createPreviews();
            }

            return true;
        }
        return false;
    }

    private function processImage(File $fileModel, $storage, string $key)
    {
        $localPath = $fileModel->rootPath;
        $exif = '';
        @$exif = exif_read_data($localPath);
        if (isset($exif['Orientation'])) {
            $ort = $exif['Orientation'];
            $rotatingImage = new SimpleImage();
            $rotatingImage->load($localPath);
            switch ($ort) {
                case 3:
                    $rotatingImage->rotateDegrees(180);
                    break;
                case 6:
                    $rotatingImage->rotateDegrees(270);
                    break;
                case 8:
                    $rotatingImage->rotateDegrees(90);
                    break;
            }
            $rotatingImage->save($localPath);
            $storage->putFile($key, $localPath, $fileModel->content_type);
        }
    }

    /**
     * @return integer
     */
    private function detectType(string $contentType)
    {
        $contentTypeArray = explode('/', $contentType);
        if ($contentTypeArray[0] == 'image')
            return FileType::IMAGE;
        if ($contentTypeArray[0] == 'video')
            return FileType::VIDEO;
        return FileType::FILE;
    }
}
