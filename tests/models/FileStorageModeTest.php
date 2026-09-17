<?php

namespace modules\files\tests\models;

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use modules\files\models\File;
use modules\files\models\FileType;
use modules\files\storage\MinioStorage;
use modules\files\tests\TestCase;
use Yii;

final class FileStorageModeTest extends TestCase
{
    public function setUp(): void
    {
        $this->sqlite = sys_get_temp_dir() . '/yii2-module-files-file-' . bin2hex(random_bytes(4)) . '.db';
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        if (is_file($this->sqlite)) {
            unlink($this->sqlite);
        }
    }

    public function testLocalRootPathRemainsAStorageFile(): void
    {
        $this->setApp();
        $module = Yii::$app->getModule('files');
        $path = $module->storageFullPath . '/12/image.jpg';
        mkdir(dirname($path), 0775, true);
        file_put_contents($path, 'local-image');

        $file = new File([
            'filename' => '/12/image.jpg',
            'content_type' => 'image/jpeg',
            'type' => FileType::IMAGE,
        ]);

        self::assertSame(realpath($path), realpath($file->getRootPath()));
        self::assertSame('originals/12/image.jpg', $file->getOriginalStorageKey());
    }

    public function testMinioRootPathMaterializesOriginalForLegacyConsumers(): void
    {
        $this->setApp();
        $module = Yii::$app->getModule('files');
        $module->storageDriver = 'minio';
        $module->cacheFullPath = sys_get_temp_dir() . '/yii2-module-files-file-cache-' . bin2hex(random_bytes(4));

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'http://minio:9000',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => new MockHandler([
                new Result(['ContentLength' => 11]),
                new Result(['Body' => 'minio-image']),
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

        $rootPath = $file->getRootPath();
        self::assertFileExists($rootPath);
        self::assertSame('minio-image', file_get_contents($rootPath));
        self::assertSame('originals/12/image.jpg', $file->getOriginalStorageKey());
    }
}
