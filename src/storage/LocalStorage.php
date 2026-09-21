<?php

namespace modules\files\storage;

class LocalStorage extends AbstractStorage implements StorageInterface
{
    /** @var string */
    private $rootPath;

    public function __construct(string $rootPath)
    {
        $this->rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);

        if ($this->rootPath === '') {
            throw new StorageException('Local storage root path must not be empty.');
        }

        if (!is_dir($this->rootPath) && !mkdir($this->rootPath, 0775, true) && !is_dir($this->rootPath)) {
            throw new StorageException('Unable to create local storage root.');
        }
    }

    public function has(string $key): bool
    {
        return is_file($this->pathFor($key));
    }

    public function putFile(string $key, string $sourcePath, string $contentType): void
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new StorageException('Source file is not readable.');
        }

        $stream = fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw new StorageException('Unable to open source file.');
        }

        try {
            $this->putStream($key, $stream, $contentType);
        } finally {
            fclose($stream);
        }
    }

    public function putStream(string $key, $stream, string $contentType): void
    {
        if (!is_resource($stream)) {
            throw new StorageException('Storage stream must be a resource.');
        }

        $targetPath = $this->pathFor($key);
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new StorageException('Unable to create local storage directory.');
        }

        $temporaryPath = tempnam($directory, '.storage-');
        if ($temporaryPath === false) {
            throw new StorageException('Unable to create temporary storage file.');
        }

        try {
            $temporaryStream = fopen($temporaryPath, 'wb');
            if ($temporaryStream === false) {
                throw new StorageException('Unable to open temporary storage file.');
            }

            try {
                if (stream_copy_to_stream($stream, $temporaryStream) === false) {
                    throw new StorageException('Unable to write temporary storage file.');
                }
            } finally {
                fclose($temporaryStream);
            }

            if (!rename($temporaryPath, $targetPath)) {
                throw new StorageException('Unable to atomically write storage file.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function getToLocalPath(string $key): string
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            throw new StorageException('Storage object does not exist.');
        }

        return $path;
    }

    public function read(string $key): string
    {
        $contents = file_get_contents($this->getToLocalPath($key));
        if ($contents === false) {
            throw new StorageException('Unable to read storage object.');
        }

        return $contents;
    }

    public function size(string $key): int
    {
        $size = filesize($this->getToLocalPath($key));
        if ($size === false) {
            throw new StorageException('Unable to read storage object size.');
        }

        return $size;
    }

    public function delete(string $key): void
    {
        $path = $this->pathFor($key);
        if (is_file($path) && !unlink($path)) {
            throw new StorageException('Unable to delete storage object.');
        }
    }

    public function deleteVariants(string $filename): void
    {
        $path = $this->pathFor('previews/' . $this->normalizeKey($filename));
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (!rmdir($item->getPathname())) {
                    throw new StorageException('Unable to delete storage variant directory.');
                }
                continue;
            }

            if (!unlink($item->getPathname())) {
                throw new StorageException('Unable to delete storage variant.');
            }
        }

        if (!rmdir($path)) {
            throw new StorageException('Unable to delete storage variant directory.');
        }
    }

    public function originalKey(string $filename, string $type = ''): string
    {
        return 'originals/' . $this->normalizeKey($filename);
    }

    public function publicUrl(string $key): ?string
    {
        return null;
    }

    private function pathFor(string $key): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->normalizeKey($key));
    }
}
