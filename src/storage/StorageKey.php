<?php

namespace modules\files\storage;

final class StorageKey
{
    public static function original(string $filename): string
    {
        return 'originals/' . self::normalize($filename);
    }

    public static function preview(string $filename, string $type, int $width, bool $webp): string
    {
        if ($width <= 0) {
            throw new StorageException('Preview width must be positive.');
        }

        return sprintf(
            'previews/%s/%d.%s',
            self::withoutExtension($filename),
            $width,
            self::extension($type, $webp)
        );
    }

    public static function importPreview(string $filename, int $width, bool $webp): string
    {
        if ($width <= 0) {
            throw new StorageException('Preview width must be positive.');
        }

        return sprintf(
            'previews/%s/%d.%s',
            self::normalize($filename),
            $width,
            $webp ? 'webp' : 'jpg'
        );
    }

    private static function extension(string $type, bool $webp): string
    {
        if ($webp) {
            return 'webp';
        }

        return match (strtolower($type)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'webp',
        };
    }

    private static function withoutExtension(string $filename): string
    {
        $normalized = self::normalize($filename);
        $extension = pathinfo($normalized, PATHINFO_EXTENSION);

        return $extension === ''
            ? $normalized
            : substr($normalized, 0, -strlen($extension) - 1);
    }

    private static function normalize(string $key): string
    {
        if (str_contains($key, "\0")) {
            throw new StorageException('Storage key contains a null byte.');
        }

        $parts = explode('/', str_replace('\\', '/', $key));
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new StorageException('Storage key traversal is not allowed.');
            }
            $normalized[] = $part;
        }

        if ($normalized === []) {
            throw new StorageException('Storage key must not be empty.');
        }

        return implode('/', $normalized);
    }
}
