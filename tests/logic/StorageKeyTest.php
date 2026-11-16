<?php

namespace modules\files\tests\logic;

use Aws\S3\S3Client;
use modules\files\storage\StorageException;
use modules\files\storage\MinioStorage;
use modules\files\storage\StorageKey;
use modules\files\tests\TestCase;

final class StorageKeyTest extends TestCase
{
    public function testBuildsOriginalAndPreviewKeysFromTheSameLogicalFilename(): void
    {
        self::assertSame('originals/photos/source.png', StorageKey::original('photos/source.png'));
        self::assertSame(
            'previews/photos/source/120.webp',
            StorageKey::preview('photos/source.png', 'image/png', 120, true)
        );
    }

    public function testBuildsExplicitImportCompatibilityPreviewKey(): void
    {
        self::assertSame(
            'previews/photos/source.png/120.webp',
            StorageKey::importPreview('photos/source.png', 120, true)
        );
    }

    public function testRejectsInvalidPreviewWidth(): void
    {
        $this->expectException(StorageException::class);
        StorageKey::preview('source.png', 'image/png', 0, true);
    }

    public function testRejectsTraversalInLogicalFilename(): void
    {
        $this->expectException(StorageException::class);
        StorageKey::original('../source.png');
    }

    public function testMinioReadCandidatesPreserveBothPreviewLayouts(): void
    {
        $storage = new MinioStorage(
            new S3Client([
                'version' => 'latest', 'region' => 'us-east-1',
                'endpoint' => 'http://minio:9000', 'use_path_style_endpoint' => true,
                'credentials' => ['key' => 'key', 'secret' => 'secret'],
            ]),
            'files',
            sys_get_temp_dir() . '/storage-key-' . bin2hex(random_bytes(4))
        );

        self::assertSame(
            ['previews/photos/source/120.webp', 'previews/photos/source.png/120.webp'],
            $storage->previewKeysForRecord('photos/source.png', 'image/png', 120, true, 'legacy-hash')
        );
        self::assertSame(
            ['previews/photos/source.png/120.webp', 'previews/photos/source/120.webp'],
            $storage->previewKeysForRecord('photos/source.png', 'image/png', 120, true, 'media-v1:hash')
        );
    }
}
