<?php

namespace modules\files\tests\logic;

use modules\files\logic\OriginalPreviewCreator;
use modules\files\storage\StorageException;
use modules\files\tests\TestCase;
use Yii;

final class OriginalPreviewCreatorTest extends TestCase
{
    private string $storageRoot;

    public function setUp(): void
    {
        $this->sqlite = sys_get_temp_dir() . '/yii2-module-files-original-preview-' . bin2hex(random_bytes(4)) . '.db';
        $this->storageRoot = sys_get_temp_dir() . '/yii2-module-files-original-preview-storage-' . bin2hex(random_bytes(4));

        parent::setUp();
        $this->setApp();

        $module = Yii::$app->getModule('files');
        $module->storageFullPath = $this->storageRoot;
        $module->cacheFullPath = $this->storageRoot . '/cache';
        mkdir($module->storageFullPath . '/originals', 0775, true);
        mkdir($module->cacheFullPath, 0775, true);
    }

    public function tearDown(): void
    {
        $this->clearDirectory($this->storageRoot);
        $this->clearDb();
        parent::tearDown();

        if (is_file($this->sqlite)) {
            unlink($this->sqlite);
        }
    }

    public function testCreatesDeterministicWebpPreviewFromOriginalStorageKey(): void
    {
        $originalPath = $this->writeOriginal('photos/source.png', 'photo.png');

        $previewKey = (new OriginalPreviewCreator())->create('originals/photos/source.png', 120, false);
        $storage = Yii::$app->getModule('files')->getStorage();

        self::assertSame('previews/photos/source/120.webp', $previewKey);
        self::assertTrue($storage->has($previewKey));
        self::assertSame('image/webp', $storage->mime($previewKey));
        self::assertSame(120, getimagesize($storage->getToLocalPath($previewKey))[0]);
        self::assertFileExists($originalPath);
    }

    public function testRejectsNonPositiveWidth(): void
    {
        $this->writeOriginal('photos/source.png', 'photo.png');

        $this->expectException(StorageException::class);
        (new OriginalPreviewCreator())->create('originals/photos/source.png', 0, false);
    }

    public function testRejectsMissingOriginal(): void
    {
        $this->expectException(StorageException::class);
        (new OriginalPreviewCreator())->create('originals/photos/missing.png', 120, false);
    }

    public function testRejectsNonImageOriginal(): void
    {
        $this->writeOriginalContents('documents/not-image.txt', 'not an image');

        $this->expectException(StorageException::class);
        (new OriginalPreviewCreator())->create('originals/documents/not-image.txt', 120, false);
    }

    public function testRejectsTraversalKey(): void
    {
        $this->expectException(StorageException::class);
        (new OriginalPreviewCreator())->create('originals/../photos/source.png', 120, false);
    }

    public function testRejectsLocalFilesystemPath(): void
    {
        $this->expectException(StorageException::class);
        (new OriginalPreviewCreator())->create('/tmp/photos/source.png', 120, false);
    }

    public function testAppliesConfiguredWatermarkOnlyWhenRequested(): void
    {
        $this->writeOriginal('photos/watermark.png', 'photo.png');
        Yii::$app->getModule('files')->watermark = Yii::getAlias('@app/data/graphic_alpha.png');

        $creator = new OriginalPreviewCreator();
        $storage = Yii::$app->getModule('files')->getStorage();
        $previewKey = $creator->create('originals/photos/watermark.png', 180, false);
        $plainBytes = $storage->read($previewKey);

        self::assertSame($previewKey, $creator->create('originals/photos/watermark.png', 180, true));
        self::assertNotSame($plainBytes, $storage->read($previewKey));
    }

    public function testOverwritesSamePreviewKeyWhenOriginalBytesChange(): void
    {
        $originalPath = $this->writeOriginal('photos/replaced.png', 'photo.png');
        $creator = new OriginalPreviewCreator();
        $storage = Yii::$app->getModule('files')->getStorage();

        $previewKey = $creator->create('originals/photos/replaced.png', 180, false);
        $firstPreviewBytes = $storage->read($previewKey);

        copy(Yii::getAlias('@app/data/graphic.png'), $originalPath);
        self::assertSame($previewKey, $creator->create('originals/photos/replaced.png', 180, false));
        self::assertNotSame($firstPreviewBytes, $storage->read($previewKey));
        self::assertFileExists($originalPath);
    }

    private function writeOriginal(string $relativePath, string $fixtureName): string
    {
        return $this->writeOriginalContents(
            $relativePath,
            file_get_contents(Yii::getAlias('@app/data/' . $fixtureName))
        );
    }

    private function writeOriginalContents(string $relativePath, string $contents): string
    {
        $path = $this->storageRoot . '/originals/' . $relativePath;
        mkdir(dirname($path), 0775, true);
        file_put_contents($path, $contents);

        return $path;
    }

    private function clearDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
