<?php

namespace modules\files\storage;

use Aws\S3\S3Client;
use Throwable;

final class MinioStorage extends AbstractStorage implements StorageInterface
{
    private S3Client $client;
    private string $bucket;
    private string $temporaryDirectory;
    private string $publicBaseUrl;

    public function __construct(
        S3Client $client,
        string $bucket,
        string $temporaryDirectory,
        string $publicBaseUrl = ''
    )
    {
        if ($bucket === '') {
            throw new StorageException('MinIO bucket must not be empty.');
        }

        if ($temporaryDirectory === '') {
            throw new StorageException('MinIO temporary directory must not be empty.');
        }

        if (!is_dir($temporaryDirectory) && !mkdir($temporaryDirectory, 0775, true) && !is_dir($temporaryDirectory)) {
            throw new StorageException('Unable to create MinIO temporary directory.');
        }

        $this->client = $client;
        $this->bucket = $bucket;
        $this->temporaryDirectory = rtrim($temporaryDirectory, DIRECTORY_SEPARATOR);
        $this->publicBaseUrl = rtrim($publicBaseUrl, '/');
    }

    public function has(string $key): bool
    {
        $normalizedKey = $this->normalizeKey($key);

        try {
            $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $normalizedKey,
            ]);

            return true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    public function putFile(string $key, string $sourcePath, string $contentType): void
    {
        if (!is_file($sourcePath)) {
            throw new StorageException('Source file is not file.');
        }

        if (!is_readable($sourcePath)) {
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

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->normalizeKey($key),
                'Body' => $stream,
                'ContentType' => $contentType,
            ]);
        } catch (Throwable $exception) {
            throw new StorageException('Unable to write MinIO object.', 0, $exception);
        }
    }

    public function getToLocalPath(string $key): string
    {
        $temporaryPath = tempnam($this->temporaryDirectory, 'minio-');
        if ($temporaryPath === false) {
            throw new StorageException('Unable to create MinIO temporary file.');
        }

        try {
            if (file_put_contents($temporaryPath, $this->read($key)) === false) {
                throw new StorageException('Unable to write MinIO temporary file.');
            }

            return $temporaryPath;
        } catch (Throwable $exception) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            if ($exception instanceof StorageException) {
                throw $exception;
            }

            throw new StorageException('Unable to materialize MinIO object.', 0, $exception);
        }
    }

    public function read(string $key): string
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->normalizeKey($key),
            ]);

            return (string)$result['Body'];
        } catch (Throwable $exception) {
            throw new StorageException('Unable to read MinIO object.', 0, $exception);
        }
    }

    public function mime(string $key): string
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->normalizeKey($key),
            ]);

            return (string)$result['ContentType'];
        } catch (Throwable $exception) {
            throw new StorageException('Unable to read MinIO object.', 0, $exception);
        }
    }

    public function size(string $key): int
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->normalizeKey($key),
            ]);

            return (int)$result['ContentLength'];
        } catch (Throwable $exception) {
            throw new StorageException('Unable to read MinIO object size.', 0, $exception);
        }
    }

    public function delete(string $key): void
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $this->normalizeKey($key),
            ]);
        } catch (Throwable $exception) {
            throw new StorageException('Unable to delete MinIO object.', 0, $exception);
        }
    }

    public function deleteVariants(string $filename): void
    {
        $prefix = 'previews/' . $this->filenameWithoutExtension($filename) . '/';

        try {
            $objects = $this->client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ]);
            $keys = [];
            foreach ($objects['Contents'] ?? [] as $object) {
                if (isset($object['Key'])) {
                    $keys[] = ['Key' => (string)$object['Key']];
                }
            }

            if ($keys !== []) {
                $this->client->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => ['Objects' => $keys, 'Quiet' => true],
                ]);
            }
        } catch (Throwable $exception) {
            throw new StorageException('Unable to delete MinIO variants.', 0, $exception);
        }
    }

    public function publicUrl(string $key): ?string
    {
        if ($this->publicBaseUrl === '') {
            return null;
        }

        $normalizedKey = $this->normalizeKey($key);
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $normalizedKey)));

        return $this->publicBaseUrl . '/' . $encodedKey;
    }
}
