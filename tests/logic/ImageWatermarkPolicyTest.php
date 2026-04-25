<?php
namespace modules\files\tests\logic;

use modules\files\logic\ImagePreviewer;
use modules\files\models\File;
use modules\files\models\FileType;
use modules\files\tests\data\common\models\Products as BaseProducts;
use modules\files\tests\data\WatermarkEnabledModel;
use modules\files\tests\data\frontend\models\Products as FrontendProducts;
use modules\files\tests\TestCase;
use Yii;

class ImageWatermarkPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setApp();
        $this->ensureStorageFolders();
        $this->createOwnerTable();
    }

    protected function tearDown(): void
    {
        $this->dropOwnerTable();
        $this->clearDb();
        parent::tearDown();
    }

    public function testReadsWatermarkSettingsFromFileBehaviour(): void
    {
        $file = new File([
            'class' => WatermarkEnabledModel::class,
            'field' => 'image',
            'title' => 'photo.png',
            'filename' => '/photos/12345678901234567890123456789012.png',
            'content_type' => 'image/png',
            'type' => FileType::IMAGE,
            'created' => time(),
            'size' => 1,
        ]);

        $this->assertTrue($file->shouldApplyWatermark());
        $this->assertSame(
            Yii::getAlias('@app/data/graphic_alpha.png'),
            $file->getWatermarkPath()
        );
        $this->assertStringContainsString(
            '_wm' . $file->getWatermarkSignature(),
            $file->makeNameWithSize($file->filename, 200)
        );
    }

    public function testResolvesWatermarkConfigFromFrontendChildWhenBaseClassStored(): void
    {
        $file = new File([
            'class' => BaseProducts::class,
            'field' => 'image',
            'title' => 'photo.png',
            'filename' => '/photos/12345678901234567890123456789012.png',
            'content_type' => 'image/png',
            'type' => FileType::IMAGE,
            'created' => time(),
            'size' => 1,
        ]);

        $this->assertTrue(class_exists(FrontendProducts::class));
        $this->assertTrue($file->shouldApplyWatermark());
        $this->assertSame(
            Yii::getAlias('@app/data/graphic_alpha.png'),
            $file->getWatermarkPath()
        );
    }

    public function testCreatesFullSizeProcessedVariantForWatermarkedImage(): void
    {
        $sourcePath = Yii::getAlias('@app/data/photo.png');
        $relativePath = '/photos/12345678901234567890123456789012.png';
        $storagePath = Yii::$app->getModule('files')->storageFullPath . $relativePath;

        if (!is_dir(dirname($storagePath))) {
            mkdir(dirname($storagePath), 0777, true);
        }
        copy($sourcePath, $storagePath);

        $file = new File([
            'class' => WatermarkEnabledModel::class,
            'field' => 'image',
            'title' => 'photo.png',
            'filename' => $relativePath,
            'content_type' => 'image/png',
            'type' => FileType::IMAGE,
            'created' => time(),
            'size' => filesize($storagePath),
        ]);

        $processedPath = Yii::createObject(ImagePreviewer::class, [$file, 0, false])->getUrl();

        $this->assertNotSame($file->getRootPath(), $processedPath);
        $this->assertFileExists($processedPath);
        $this->assertStringContainsString('_wm' . $file->getWatermarkSignature(), $processedPath);
    }

    private function ensureStorageFolders(): void
    {
        foreach ([
            Yii::$app->getModule('files')->storageFullPath,
            Yii::$app->getModule('files')->cacheFullPath,
        ] as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }
    }

    private function createOwnerTable(): void
    {
        Yii::$app->db->createCommand()->createTable('{{%watermark_enabled_model}}', [
            'id' => 'pk',
        ])->execute();
    }

    private function dropOwnerTable(): void
    {
        $schema = Yii::$app->db->schema->getTableSchema('{{%watermark_enabled_model}}', true);
        if ($schema !== null) {
            Yii::$app->db->createCommand()->dropTable('{{%watermark_enabled_model}}')->execute();
        }
    }
}
