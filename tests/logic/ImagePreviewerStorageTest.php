<?php

namespace modules\files\tests\logic;

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use modules\files\logic\ImagePreviewer;
use modules\files\models\File;
use modules\files\models\FileType;
use modules\files\storage\MinioStorage;
use modules\files\tests\TestCase;
use Yii;

final class ImagePreviewerStorageTest extends TestCase
{
    public function setUp(): void
    {
        $this->sqlite = sys_get_temp_dir() . '/yii2-module-files-preview-' . bin2hex(random_bytes(4)) . '.db';
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        if (is_file($this->sqlite)) {
            unlink($this->sqlite);
        }
    }

    public function testReturnsMaterializedStoredMinioPreview(): void
    {
        $this->setApp();
        $module = Yii::$app->getModule('files');
        $module->storageDriver = 'minio';
        $module->cacheFullPath = sys_get_temp_dir() . '/yii2-module-files-preview-cache-' . bin2hex(random_bytes(4));

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'http://minio:9000',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => new MockHandler([
                new Result(['ContentLength' => 12]),
                new Result(['Body' => 'stored-preview']),
            ]),
        ]);
        $storage = new MinioStorage($client, 'products', $module->cacheFullPath . '/temp');
        $reflection = new \ReflectionProperty($module, 'storageAdapter');
        $reflection->setAccessible(true);
        $reflection->setValue($module, $storage);

        $file = new File([
            'filename' => '/12/image.jpg',
            'content_type' => 'image/jpeg',
            'type' => FileType::IMAGE,
        ]);

        $previewPath = (new ImagePreviewer($file, 80, false))->getUrl();

        self::assertIsString($previewPath);
        self::assertFileExists($previewPath);
        self::assertSame('stored-preview', file_get_contents($previewPath));
    }
}
