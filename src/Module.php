<?php
namespace modules\files;

use Aws\S3\S3Client;
use modules\files\storage\LocalStorage;
use modules\files\storage\MinioStorage;
use modules\files\storage\StorageInterface;
use yii\base\InvalidConfigException;
use Yii;
use yii\db\Connection;

/**
 * Class Module
 * @package floor12\files
 * @property string $token_salt
 * @property string $storage
 * @property string $controllerNamespace
 *
 */
class Module extends \yii\base\Module
{
    /**
     * @inheritdoc
     */
    public $controllerNamespace = 'modules\files\controllers';

    /** Путь к файловому хранилищу
     * @var string
     */
    public $storage = '@vendor/../storage';

    /** @var string local|minio */
    public $storageDriver = 'local';

    /** @var array<string, mixed> */
    public $minio = [];

    /** Путь к хранилищу кешей
     * @var string
     */
    public $cache = '@vendor/../storage_cache';
    /**
     * @var string
     */
    public $hostStatic = '';
    /**
     * @var string
     */
    public $ffmpeg = '/usr/bin/ffmpeg';
    /**
     * @var string
     */
    public $token_salt = 'randomString412DDs@#KJH';
    /**
     * @var string
     */
    public $storageFullPath;
    /**
     * @var string
     */
    public $cacheFullPath;
    /**
     * @var bool
     */
    public $allowOfficePreview = true;
    /**
     * @var array
     */
    public $params = ['db' => 'db'];
    /**
     * @var Connection
     */
    public $db;
    public $previewWidths = [32, 64, 80, 320, 440, 640, 880];

    /**
     * @var StorageInterface|null
     */
    private $storageAdapter = null;
    public ?string $watermark;
    public bool $apply_watermark = false;

    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->registerTranslations();
        $this->db = Yii::$app->{$this->params['db']};
        $this->storageFullPath = Yii::getAlias($this->storage);
        $this->cacheFullPath = Yii::getAlias($this->cache);
    }

    public function getStorage(): StorageInterface
    {
        if ($this->storageAdapter !== null) {
            return $this->storageAdapter;
        }

        if ($this->storageDriver === 'local') {
            return $this->storageAdapter = new LocalStorage($this->storageFullPath);
        }

        if ($this->storageDriver !== 'minio') {
            throw new InvalidConfigException('Unknown files storage driver: ' . $this->storageDriver);
        }

        foreach (['endpoint', 'accessKey', 'secretKey', 'region', 'bucket'] as $required) {
            if (empty($this->minio[$required]) || !is_string($this->minio[$required])) {
                throw new InvalidConfigException('Missing files MinIO configuration: ' . $required);
            }
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => $this->minio['region'],
            'endpoint' => $this->minio['endpoint'],
            'use_path_style_endpoint' => (bool)($this->minio['pathStyleEndpoint'] ?? true),
            'credentials' => [
                'key' => $this->minio['accessKey'],
                'secret' => $this->minio['secretKey'],
            ],
        ]);

        return $this->storageAdapter = new MinioStorage(
            $client,
            $this->minio['bucket'],
            $this->cacheFullPath . DIRECTORY_SEPARATOR . 'minio-temp',
            (string)($this->minio['cdnUrl'] ?? '')
        );
    }

    /**
     * @return void
     */
    public function registerTranslations()
    {
        $i18n = Yii::$app->i18n;
        $i18n->translations['files'] = [
            'class' => 'yii\i18n\PhpMessageSource',
            'sourceLanguage' => 'en-US',
            'basePath' => '@modules/files/messages',
        ];
    }

}
