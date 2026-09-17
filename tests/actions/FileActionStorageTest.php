<?php

namespace modules\files\tests\actions;

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use modules\files\actions\GetFileAction;
use modules\files\models\File;
use modules\files\models\FileType;
use modules\files\storage\MinioStorage;
use modules\files\tests\TestCase;
use Yii;
use yii\base\Controller;
use yii\web\Request;
use yii\web\Response;

final class FileActionStorageTest extends TestCase
{
    public function setUp(): void
    {
        $this->sqlite = sys_get_temp_dir() . '/yii2-module-files-action-' . bin2hex(random_bytes(4)) . '.db';
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        if (is_file($this->sqlite)) {
            unlink($this->sqlite);
        }
    }

    public function testGetFileActionReadsOriginalThroughMinioStorage(): void
    {
        $this->setApp();
        $module = Yii::$app->getModule('files');
        $module->storageDriver = 'minio';
        $module->cacheFullPath = sys_get_temp_dir() . '/yii2-module-files-action-cache-' . bin2hex(random_bytes(4));

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'http://minio:9000',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => new MockHandler([
                new Result(['ContentLength' => 11]),
                new Result(['Body' => 'minio-file']),
            ]),
        ]);
        $reflection = new \ReflectionProperty($module, 'storageAdapter');
        $reflection->setAccessible(true);
        $reflection->setValue($module, new MinioStorage($client, 'products', $module->cacheFullPath . '/temp'));

        $file = new File([
            'class' => 'tests\\models\\Owner',
            'field' => 'image',
            'object_id' => 1,
            'filename' => '/12/image.jpg',
            'title' => 'image.jpg',
            'content_type' => 'image/jpeg',
            'type' => FileType::IMAGE,
            'size' => 10,
            'created' => time(),
        ]);
        self::assertTrue($file->save());

        Yii::$app->set('request', ['class' => Request::class]);
        Yii::$app->set('response', ['class' => Response::class]);
        $controller = new Controller('test', Yii::$app);
        (new GetFileAction('get', $controller))->run($file->hash);

        self::assertIsArray(Yii::$app->response->stream);
        self::assertIsResource(Yii::$app->response->stream[0]);
        rewind(Yii::$app->response->stream[0]);
        self::assertSame('minio-file', stream_get_contents(Yii::$app->response->stream[0]));
    }
}
