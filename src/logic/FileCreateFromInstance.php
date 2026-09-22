<?php
namespace modules\files\logic;

use modules\files\components\SimpleImage;
use modules\files\models\File;
use modules\files\models\FileType;
use Yii;
use yii\base\ErrorException;
use yii\base\InvalidConfigException;
use yii\db\Exception;
use yii\web\BadRequestHttpException;
use yii\web\IdentityInterface;
use yii\web\UploadedFile;

/**
 * Class FileCreateFromInstance
 * @package floor12\files\logic
 */
class FileCreateFromInstance
{
    private $_model;
    private $_owner;
    private $_attribute;
    private $_instance;
    private $_onlyUploaded;
    private $maxWidth;

    public function __construct(UploadedFile $file, array $data, IdentityInterface $identity = null, $onlyUploaded = true)
    {
        $this->_onlyUploaded = $onlyUploaded;
        $this->maxWidth = $data['max_width'] ?? null;

        if (!isset($data['attribute']) || !$data['attribute'] || !isset($data['modelClass']) || !$data['modelClass'])
            throw new BadRequestHttpException("Attribute or class name not set.");

        // Загружаем полученные данные
        $this->_instance = $file;
        $this->_attribute = $data['attribute'];

        if (!file_exists($this->_instance->tempName))
            throw new ErrorException("Tmp file not found on disk.");

        // Инициализируем класс владельца файла для валидаций и ставим сценарий
        $this->_owner = new $data['modelClass'];

        if (isset($data['scenario']))
            $this->_owner->setScenario($data['scenario']);

        if (isset($this->_owner->behaviors['files']->attributes[$this->_attribute]['validator'])) {
            foreach ($this->_owner->behaviors['files']->attributes[$this->_attribute]['validator'] as $validator) {
                if ($validator->maxFiles && (int)$data['count'] > $validator->maxFiles) {
                    throw new BadRequestHttpException('The maximum number of files has been exceeded: ' . $validator->maxFiles);
                }

                if (!$validator->validate($this->_instance, $error))
                    throw new BadRequestHttpException($error);
            }
        }

        // Создаем модель нового файла и заполняем первоначальными данными
        $this->_model = new File();
        $this->_model->created = time();
        $this->_model->field = $this->_attribute;
        
        $modelClass = $data['modelClass'];
        if (isset($this->_owner->behaviors['files']) && 
            isset($this->_owner->behaviors['files']->baseClass) && 
            $this->_owner->behaviors['files']->baseClass) {
            $modelClass = $this->_owner->behaviors['files']->baseClass;
        }
        $this->_model->class = $modelClass;

        $this->_model->filename = (string)new PathGenerator(Yii::$app->getModule('files')->storageFullPath) . '.' . $this->_instance->extension;
        $this->_model->title = $this->_instance->name;
        $this->_model->content_type = \yii\helpers\FileHelper::getMimeType($this->_instance->tempName);
        $this->_model->size = $this->_instance->size;
        $this->_model->type = $this->detectType();
        if ($identity)
            $this->_model->user_id = $identity->getId();
        if ($this->_model->type == FileType::VIDEO)
            $this->_model->video_status = 0;
    }

    public function detectType()
    {
        $contentTypeArray = explode('/', $this->_model->content_type);
        if ($contentTypeArray[0] == 'image')
            return FileType::IMAGE;
        if ($contentTypeArray[0] == 'video')
            return FileType::VIDEO;
        return FileType::FILE;
    }

    /**
     * @throws Exception
     * @throws InvalidConfigException
     * @throws \ErrorException
     * @throws BadRequestHttpException
     */
    public function execute()
    {
        if ($this->_model->save()) {
            $storage = $this->_model->getStorage();
            $key = $this->_model->getOriginalStorageKey();

            $storage->putFile(
                $key,
                $this->_instance->tempName,
                $this->_model->content_type
            );

            if (!$storage->has($key)) {
                Yii::error("File was put to storage but is not found: " . $key, 'files');
                throw new \yii\web\BadRequestHttpException("File upload failed: object not found in storage.");
            }
        }

        if ($this->_model->type == FileType::IMAGE || $this->_model->type == FileType::VIDEO) {
            if ($this->_model->type == FileType::IMAGE) {
                $this->rotateAfterUpload();
                $this->resizeAfterUpload();
            }
            $this->_model->createPreviews($this->maxWidth);
        }

        return $this->_model;
    }

    protected function rotateAfterUpload()
    {
        $storage = $this->_model->getStorage();
        $key = $this->_model->getOriginalStorageKey();
        
        $localPath = $this->_model->rootPath; 
        
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
            
            $storage->putFile($key, $localPath, $this->_model->content_type);
        }
    }

    protected function resizeAfterUpload()
    {
        $maxWidth = $this->_owner->behaviors['files']->attributes[$this->_attribute]['maxWidth'] ?? 0;
        $maxHeight = $this->_owner->behaviors['files']->attributes[$this->_attribute]['maxHeight'] ?? 0;

        if ($maxWidth && $maxHeight) {
            $resizer = new FileResize($this->_model, $maxWidth, $maxHeight);
            $resizer->execute();
        }
    }
}
