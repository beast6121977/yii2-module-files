<?php
namespace modules\files\tests\logic;

use modules\files\logic\ImagePreviewer;
use modules\files\models\File;
use modules\files\models\FileType;
use modules\files\tests\data\common\models\Products as BaseProducts;
use modules\files\tests\data\TestModel;
use modules\files\tests\data\WatermarkEnabledModel;
use modules\files\tests\data\frontend\models\Products as FrontendProducts;
use modules\files\tests\TestCase;
use Spatie\Image\Image;
use Yii;

class ImageWatermarkPolicyTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->setApp();
        $this->ensureStorageFolders();
        $this->createOwnerTable();
    }

    public function tearDown(): void
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

    public function testCreatesWatermarkedEightHundredEightyPixelPreview(): void
    {
        $sourcePath = Yii::getAlias('@app/data/photo.png');
        $relativePath = '/photos/880-watermark.png';
        $storagePath = $this->writeOriginal($relativePath, $sourcePath);

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

        $file->createPreviews();

        $watermarkedPath = Yii::$app->getModule('files')->storageFullPath . DIRECTORY_SEPARATOR
            . $file->getStorage()->previewKey($relativePath, 'image/jpeg', 880, false);

        $plainRelativePath = '/photos/880-plain.png';
        $plainStoragePath = $this->writeOriginal($plainRelativePath, $sourcePath);
        $plainFile = new File([
            'class' => TestModel::class,
            'field' => 'image',
            'title' => 'photo.png',
            'filename' => $plainRelativePath,
            'content_type' => 'image/png',
            'type' => FileType::IMAGE,
            'created' => time(),
            'size' => filesize($plainStoragePath),
        ]);
        $plainFile->createPreviews();
        $plainPath = Yii::$app->getModule('files')->storageFullPath . DIRECTORY_SEPARATOR
            . $plainFile->getStorage()->previewKey($plainRelativePath, 'image/jpeg', 880, false);

        $this->assertFileExists($watermarkedPath);
        $this->assertFileExists($plainPath);
        $this->assertNotSame(md5_file($plainPath), md5_file($watermarkedPath));
    }

    public function testModuleWatermarkSettingsDoNotEnablePlainEagerPreview(): void
    {
        $module = Yii::$app->getModule('files');
        $module->apply_watermark = true;
        $module->watermark = Yii::getAlias('@app/data/graphic_alpha.png');

        $sourcePath = Yii::getAlias('@app/data/photo.png');
        $relativePath = '/photos/module-global-ignored.png';
        $storagePath = $this->writeOriginal($relativePath, $sourcePath);
        $file = new File([
            'class' => TestModel::class,
            'field' => 'image',
            'title' => 'photo.png',
            'filename' => $relativePath,
            'content_type' => 'image/png',
            'type' => FileType::IMAGE,
            'created' => time(),
            'size' => filesize($storagePath),
        ]);

        $storage = $file->getStorage();
        $tmpPath = $storage->getTmpPath($relativePath);
        $storage->generatePreview(
            Image::load($file->getRootPath()),
            $tmpPath,
            880,
            true,
            'image/webp',
            false,
            $relativePath,
            null
        );
        $previewPath = Yii::$app->getModule('files')->storageFullPath . DIRECTORY_SEPARATOR
            . $file->getStorage()->previewKey($relativePath, 'image/webp', 880, true);
        $plainHash = md5_file($previewPath);
        if (is_file($tmpPath)) {
            unlink($tmpPath);
        }

        $file->createPreviewsByWidths([880]);

        $this->assertSame($plainHash, md5_file($previewPath));
    }

    public function testExplicitWatermarkRequiresAnExistingPath(): void
    {
        $storage = Yii::$app->getModule('files')->getStorage();
        $this->expectException(\ErrorException::class);

        $storage->generatePreview(
            Image::load(Yii::getAlias('@app/data/photo.png')),
            $storage->getTmpPath('/photos/missing-watermark.png'),
            80,
            true,
            'image/webp',
            true,
            '/photos/missing-watermark.png',
            sys_get_temp_dir() . '/missing-watermark-' . bin2hex(random_bytes(4)) . '.png'
        );
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

    private function writeOriginal(string $relativePath, string $sourcePath): string
    {
        $storagePath = Yii::$app->getModule('files')->storageFullPath . $relativePath;
        if (!is_dir(dirname($storagePath))) {
            mkdir(dirname($storagePath), 0777, true);
        }
        copy($sourcePath, $storagePath);

        return $storagePath;
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
