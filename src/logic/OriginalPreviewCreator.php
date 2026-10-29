<?php

namespace modules\files\logic;

use modules\files\storage\AbstractStorage;
use modules\files\storage\MinioStorage;
use modules\files\storage\StorageException;
use Spatie\Image\Image;
use Yii;

final class OriginalPreviewCreator
{
    public function create(string $originalKey, int $width, bool $applyWatermark): string
    {
        if ($width <= 0) {
            throw new StorageException('Preview width must be positive.');
        }

        $normalizedKey = $this->normalizeOriginalKey($originalKey);
        $module = Yii::$app->getModule('files');
        $storage = $module->getStorage();

        if (!$storage->has($normalizedKey)) {
            throw new StorageException('Original storage object does not exist: ' . $normalizedKey);
        }

        if (!$storage instanceof AbstractStorage) {
            throw new StorageException('Configured files storage cannot generate previews.');
        }

        $sourcePath = null;
        $temporaryPreviewPath = null;

        try {
            $sourcePath = $storage->getToLocalPath($normalizedKey);
            if (!is_file($sourcePath) || !is_readable($sourcePath)) {
                throw new StorageException('Original storage object is not readable: ' . $normalizedKey);
            }

            $storageMime = strtolower((string)$storage->mime($normalizedKey));
            $imageInfo = @getimagesize($sourcePath);
            $imageMime = strtolower((string)($imageInfo['mime'] ?? ''));
            if (!str_starts_with($storageMime, 'image/') || !str_starts_with($imageMime, 'image/')) {
                throw new StorageException('Original storage object is not an image: ' . $normalizedKey);
            }

            $watermarkPath = null;
            if ($applyWatermark) {
                $watermarkPath = $module->watermark ?? null;
                if (!is_string($watermarkPath) || !is_file($watermarkPath)) {
                    throw new StorageException('Watermark file does not exist: ' . (string)$watermarkPath);
                }
            }

            $temporaryPreviewPath = tempnam(sys_get_temp_dir(), 'original-preview-');
            if ($temporaryPreviewPath === false) {
                throw new StorageException('Unable to create temporary preview file.');
            }

            $logicalFilename = substr($normalizedKey, strlen('originals/'));
            $storage->generatePreview(
                Image::load($sourcePath),
                $temporaryPreviewPath,
                $width,
                true,
                'image/webp',
                $applyWatermark,
                $logicalFilename,
                $watermarkPath
            );

            return $storage->previewKey($logicalFilename, 'image/webp', $width, true);
        } finally {
            if ($temporaryPreviewPath !== null && is_file($temporaryPreviewPath)) {
                unlink($temporaryPreviewPath);
            }

            if ($storage instanceof MinioStorage && $sourcePath !== null && is_file($sourcePath)) {
                unlink($sourcePath);
            }
        }
    }

    private function normalizeOriginalKey(string $originalKey): string
    {
        if ($originalKey === '' || str_contains($originalKey, "\0")) {
            throw new StorageException('Original storage key is invalid.');
        }

        $parts = explode('/', str_replace('\\', '/', $originalKey));
        if ($parts[0] !== 'originals') {
            throw new StorageException('Only originals storage keys are accepted.');
        }

        $logicalParts = [];
        foreach (array_slice($parts, 1) as $part) {
            if ($part === '..') {
                throw new StorageException('Original storage key traversal is not allowed.');
            }

            if ($part === '' || $part === '.') {
                continue;
            }

            $logicalParts[] = $part;
        }

        if ($logicalParts === []) {
            throw new StorageException('Original storage key must name an object.');
        }

        return 'originals/' . implode('/', $logicalParts);
    }
}
