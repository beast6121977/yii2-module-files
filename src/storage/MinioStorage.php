<?php

namespace modules\files\storage;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Throwable;

final class MinioStorage extends AbstractStorage implements StorageInterface
{
    private S3Client $client;
    private string $bucket;
    private string $temporaryDirectory;
    private string $publicBaseUrl;

    private int $putCount = 0;
    private float $putSeconds = 0.0;

    /** @return array{count: int, seconds: float} */
    public function writeMetrics(): array
    {
        return ['count' => $this->putCount, 'seconds' => $this->putSeconds];
    }

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

    #[\Override]
    public function has(string $key): bool
    {
        $normalizedKey = $this->normalizeKey($key);

        try {
            $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $normalizedKey,
            ]);

            return true;
        } catch (S3Exception $exception) {
            if ($exception->getStatusCode() === 404) {
                return false;
            }

            throw new StorageException('Unable to check MinIO object.', 0, $exception);
        } catch (Throwable $exception) {
            throw new StorageException('Unable to check MinIO object.', 0, $exception);
        }
    }

    #[\Override]
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

    #[\Override]
    public function putStream(string $key, $stream, string $contentType): void
    {
        if (!is_resource($stream)) {
            throw new StorageException('Storage stream must be a resource.');
        }

        // The preview encoder emits JPEG/WebP regardless of the source MIME.
        if (str_starts_with($this->normalizeKey($key), 'previews/')) {
            if (str_ends_with($key, '.jpg')) {
                $contentType = 'image/jpeg';
            } elseif (str_ends_with($key, '.webp')) {
                $contentType = 'image/webp';
            }
        }
        $started = microtime(true);
        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->normalizeKey($key),
                'Body' => $stream,
                'ContentType' => $contentType,
            ]);
            ++$this->putCount;
        } catch (Throwable $exception) {
            throw new StorageException('Unable to write MinIO object.', 0, $exception);
        } finally {
            $this->putSeconds += microtime(true) - $started;
        }
    }

    /** Source identity is independent of optimization and S3 multipart ETags. */
    public function sourceHash(string $key): ?string
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->normalizeKey($key),
            ]);
            return $result['Metadata']['source-sha256'] ?? null;
        } catch (\Aws\S3\Exception\S3Exception $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }
            throw new StorageException('Unable to read MinIO source metadata.', 0, $exception);
        }
    }

    public function putOriginal(string $key, string $sourcePath, string $contentType, string $sourceHash): void
    {
        $stream = fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw new StorageException('Unable to open original source.');
        }
        $started = microtime(true);
        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->normalizeKey($key),
                'Body' => $stream,
                'ContentType' => $contentType,
                'Metadata' => ['source-sha256' => $sourceHash],
            ]);
            ++$this->putCount;
        } catch (Throwable $exception) {
            throw new StorageException('Unable to write MinIO original.', 0, $exception);
        } finally {
            $this->putSeconds += microtime(true) - $started;
            fclose($stream);
        }
    }

    public function importPreviewKey(string $filename, string $type, int $width, bool $webp): string
    {
        if ($width <= 0) {
            throw new StorageException('Preview width must be positive.');
        }
        return 'previews/' . $this->normalizeKey($filename) . '/' . $width . ($webp ? '.webp' : '.jpg');
    }

    /** Generate an import variant using canonical keys without changing legacy uploads. */
    public function generateImportPreview(
        \Spatie\Image\Image $image,
        string $tmpPath,
        int $width,
        bool $webp,
        string $mimeType,
        bool $applyWatermark,
        string $logicalFilename,
        ?string $watermarkPath
    ): void {
        if ($applyWatermark && ($watermarkPath === null || !is_file($watermarkPath))) {
            throw new StorageException('Watermark file does not exist.');
        }
        foreach (ProgressiveResizePlan::forWidth($image->getWidth(), $width) as $resizeWidth) {
            $image->width($resizeWidth);
        }
        $image->format($webp ? 'webp' : 'jpg');
        if ($applyWatermark) {
            $image->watermark(
                $watermarkPath,
                \Spatie\Image\Enums\AlignPosition::Center,
                width: $image->getWidth(), height: $image->getHeight(),
                fit: \Spatie\Image\Enums\Fit::Contain
            );
        }
        $optimizer = ImageConfig::tryFrom($mimeType)?->config();
        if ($optimizer !== null) {
            $image->optimize($optimizer);
        }
        $image->save($tmpPath);
        $this->putFile(
            $this->importPreviewKey($logicalFilename, $mimeType, $width, $webp),
            $tmpPath, $webp ? 'image/webp' : 'image/jpeg'
        );
    }

    /** Keep URLs for existing records without adding HEAD requests to API reads. */
    public function previewKeyForRecord(string $filename, string $type, int $width, bool $webp, string $hash): string
    {
        return str_starts_with($hash, 'media-v1:')
            ? $this->importPreviewKey($filename, $type, $width, $webp)
            : parent::previewKey($filename, $type, $width, $webp);
    }

    #[\Override]
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

    #[\Override]
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

    #[\Override]
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

    #[\Override]
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

    #[\Override]
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

    #[\Override]
    public function deleteVariants(string $filename): void
    {
        $prefixes = array_unique([
            'previews/' . $this->normalizeKey($filename) . '/',
            'previews/' . $this->filenameWithoutExtension($filename) . '/',
        ]);
        try {
            foreach ($prefixes as $prefix) {
                $objects = $this->client->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => $prefix]);
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
            }
        } catch (Throwable $exception) {
            throw new StorageException('Unable to delete MinIO variants.', 0, $exception);
        }
    }

    #[\Override]
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
