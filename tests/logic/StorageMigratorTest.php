<?php

namespace modules\files\tests\logic;

use modules\files\logic\StorageMigrator;
use modules\files\storage\StorageInterface;
use PHPUnit\Framework\TestCase;

final class StorageMigratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yii2-module-files-migrator-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/12/44', 0775, true);
        mkdir($this->root . '/previews/file', 0775, true);
        file_put_contents($this->root . '/12/44/file.jpg', 'original');
        file_put_contents($this->root . '/previews/file/80.jpg', 'preview');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testMovesLegacyOriginalAndPreviewAfterVerifiedUpload(): void
    {
        $storage = new class implements StorageInterface {
            public array $objects = [];

            public function has(string $key): bool { return isset($this->objects[$key]); }
            public function putFile(string $key, string $sourcePath, string $contentType): void
            {
                $this->objects[$key] = file_get_contents($sourcePath);
            }
            public function putStream(string $key, $stream, string $contentType): void {}
            public function getToLocalPath(string $key): string { return ''; }
            public function read(string $key): string { return $this->objects[$key]; }
            public function size(string $key): int { return strlen($this->objects[$key]); }
            public function delete(string $key): void {}
            public function deleteVariants(string $filename): void {}
            public function originalKey(string $filename, string $type): string { return 'originals/' . ltrim($filename, '/'); }
            public function previewKey(string $filename, string $type, int $width, bool $webp): string
            {
                return 'previews/file/' . $width . ($webp ? '.webp' : '.jpg');
            }
            public function publicUrl(string $key): ?string { return null; }
            public function mime(string $key): ?string { return null; }
        };

        $migrator = new StorageMigrator($this->root, $storage);

        $migrator->migrateFile('/12/44/file.jpg', 'image/jpeg', [[
            'path' => $this->root . '/previews/file/80.jpg',
            'width' => 80,
            'webp' => false,
            'contentType' => 'image/jpeg',
        ]], true);

        self::assertSame('original', $storage->objects['originals/12/44/file.jpg']);
        self::assertSame('preview', $storage->objects['previews/file/80.jpg']);
        self::assertFileDoesNotExist($this->root . '/12/44/file.jpg');
        self::assertFileDoesNotExist($this->root . '/previews/file/80.jpg');
    }
}
