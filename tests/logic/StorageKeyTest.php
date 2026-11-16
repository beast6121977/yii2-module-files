<?php

namespace modules\files\tests\logic;

use modules\files\storage\StorageException;
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
}
