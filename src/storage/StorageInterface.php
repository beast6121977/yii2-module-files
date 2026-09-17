<?php

namespace modules\files\storage;

interface StorageInterface
{
    public function has(string $key): bool;

    /** @throws StorageException */
    public function putFile(string $key, string $sourcePath, string $contentType): void;

    /** @param resource $stream
     * @throws StorageException
     */
    public function putStream(string $key, $stream, string $contentType): void;

    /** @throws StorageException */
    public function getToLocalPath(string $key): string;

    /** @throws StorageException */
    public function read(string $key): string;

    /** @throws StorageException */
    public function size(string $key): int;

    /** @throws StorageException */
    public function delete(string $key): void;

    /** @throws StorageException */
    public function deleteVariants(string $filename): void;

    public function originalKey(string $filename, string $type): string;

    public function previewKey(string $filename, string $type, int $width, bool $webp): string;

    public function publicUrl(string $key): ?string;
}
