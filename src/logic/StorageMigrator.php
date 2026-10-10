<?php

namespace modules\files\logic;

use modules\files\storage\StorageException;
use modules\files\storage\StorageInterface;

final class StorageMigrator
{
    public function __construct(
        private readonly string $localRoot,
        private readonly StorageInterface $targetStorage,
    ) {
    }

    /**
     * @param list<array{path: string, width: int, webp: bool, contentType: string}> $previews
     */
    public function migrateFile(
        string $filename,
        string $contentType,
        array $previews = [],
        bool $deleteLocal = false
    ): int
    {
        $localOriginal = $this->findLocalOriginal($filename);
        if ($localOriginal === null) {
            throw new StorageException('Local original file is not found: ' . $filename);
        }

        $uploaded = 0;
        $originalKey = $this->targetStorage->originalKey($filename, $contentType);
        $this->uploadVerified($originalKey, $localOriginal, $contentType);
        $uploaded++;

        $uploadedPreviews = [];
        foreach ($previews as $preview) {
            if (!is_file($preview['path'])) {
                continue;
            }

            $previewKey = $this->targetStorage->previewKey(
                $filename,
                $preview['contentType'],
                $preview['width'],
                $preview['webp']
            );
            $this->uploadVerified($previewKey, $preview['path'], $preview['contentType']);
            $uploadedPreviews[] = $preview['path'];
            $uploaded++;
        }

        if ($deleteLocal) {
            if (!unlink($localOriginal)) {
                throw new StorageException('Unable to delete local original: ' . $localOriginal);
            }
            foreach ($uploadedPreviews as $preview) {
                if (!unlink($preview)) {
                    throw new StorageException('Unable to delete local preview: ' . $preview);
                }
            }
        }

        return $uploaded;
    }

    private function findLocalOriginal(string $filename): ?string
    {
        $relativeFilename = ltrim(str_replace('\\', '/', $filename), '/');
        $legacyPath = $this->localRoot . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativeFilename);

        if (is_file($legacyPath)) {
            return $legacyPath;
        }

        $newPath = $this->localRoot . DIRECTORY_SEPARATOR . 'originals' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativeFilename);

        return is_file($newPath) ? $newPath : null;
    }

    private function uploadVerified(string $key, string $sourcePath, string $contentType): void
    {
        $sourceSize = filesize($sourcePath);
        if ($sourceSize === false) {
            throw new StorageException('Unable to read local file size: ' . $sourcePath);
        }

        if ($this->targetStorage->has($key)) {
            if (
                $this->targetStorage->size($key) !== $sourceSize
                || $this->hash($sourcePath) !== hash('sha256', $this->targetStorage->read($key))
            ) {
                throw new StorageException('MinIO object exists with different content or size: ' . $key);
            }
            return;
        }

        $this->targetStorage->putFile($key, $sourcePath, $contentType);
        if (
            !$this->targetStorage->has($key)
            || $this->targetStorage->size($key) !== $sourceSize
            || $this->hash($sourcePath) !== hash('sha256', $this->targetStorage->read($key))
        ) {
            throw new StorageException('MinIO object verification failed: ' . $key);
        }
    }

    private function hash(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new StorageException('Unable to hash local file: ' . $path);
        }

        return $hash;
    }
}
