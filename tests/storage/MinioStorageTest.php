<?php

namespace modules\files\tests\storage;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use modules\files\storage\MinioStorage;
use modules\files\storage\StorageException;
use PHPUnit\Framework\TestCase;

final class MinioStorageTest extends TestCase
{
    private string $tempDirectory;

    #[\Override]
    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/yii2-module-files-minio-' . bin2hex(random_bytes(4));
        mkdir($this->tempDirectory, 0775, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->tempDirectory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->tempDirectory);
    }

    public function testUsesDeterministicKeysAndS3Operations(): void
    {
        $handler = new MockHandler([
            new Result(['ContentLength' => 8]),
            new Result(['Body' => 'original']),
            new Result(['ContentLength' => 8]),
            new Result([]),
        ]);
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'http://minio:9000',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => $handler,
        ]);
        $storage = new MinioStorage($client, 'products', $this->tempDirectory);

        self::assertSame('originals/12/image.jpg', $storage->originalKey('/12/image.jpg', 'image/jpeg'));
        self::assertSame('previews/12/image/80.webp', $storage->previewKey('/12/image.jpg', 'image/jpeg', 80, true));
        self::assertSame(8, $storage->size('originals/12/image.jpg'));
        self::assertSame('original', $storage->read('originals/12/image.jpg'));

        $storage->delete('originals/12/image.jpg');
        $commands = $handler->getLastCommand();
        self::assertInstanceOf(CommandInterface::class, $commands);
        self::assertSame('DeleteObject', $commands->getName());
    }

    public function testPngUsesFullFilenameAndJpegPreviewKey(): void
    {
        $storage = new MinioStorage($this->clientWithHandler(new MockHandler([])), 'products', $this->tempDirectory);
        self::assertSame('previews/a/photo.png/64.jpg', $storage->importPreviewKey('/a/photo.png', 'image/png', 64, false));
        self::assertSame('previews/b/photo.png/64.webp', $storage->importPreviewKey('/b/photo.png', 'image/png', 64, true));
    }

    public function testOriginalSourceHashIsStoredInMetadata(): void
    {
        $handler = new MockHandler([new Result([]), new Result(['Metadata' => ['source-sha256' => 'source-digest']])]);
        $storage = new MinioStorage($this->clientWithHandler($handler), 'products', $this->tempDirectory);
        $source = $this->tempDirectory . '/source.png';
        file_put_contents($source, 'bytes');
        $storage->putOriginal('originals/a.png', $source, 'image/png', 'source-digest');
        self::assertSame(['source-sha256' => 'source-digest'], $handler->getLastCommand()['Metadata']);
        self::assertSame('source-digest', $storage->sourceHash('originals/a.png'));
    }

    public function testSourceMetadataErrorsAreNotTreatedAsAnUnchangedObject(): void
    {
        $command = new \Aws\Command('HeadObject');
        $error = new \Aws\S3\Exception\S3Exception('Denied', $command, ['response' => new \GuzzleHttp\Psr7\Response(403)]);
        $storage = new MinioStorage($this->clientWithHandler(new MockHandler([$error])), 'products', $this->tempDirectory);
        $this->expectException(StorageException::class);
        $storage->sourceHash('originals/a.png');
    }

    public function testWriteMetricsCountSuccessfulObjectWrites(): void
    {
        $storage = new MinioStorage($this->clientWithHandler(new MockHandler([new Result([])])), 'products', $this->tempDirectory);
        $path = $this->tempDirectory . '/image.png';
        file_put_contents($path, 'image');
        self::assertSame(0, $storage->writeMetrics()['count']);
        $storage->putOriginal('originals/a.png', $path, 'image/png', 'digest');
        self::assertSame(1, $storage->writeMetrics()['count']);
        self::assertGreaterThan(0.0, $storage->writeMetrics()['seconds']);
    }

    public function testDeletionCleansCanonicalAndLegacyVariantNamespaces(): void
    {
        $handler = new MockHandler([
            new Result(['Contents' => [['Key' => 'previews/a.png/64.jpg']]]), new Result([]),
            new Result(['Contents' => [['Key' => 'previews/a/64.png']]]), new Result([]),
        ]);
        $storage = new MinioStorage($this->clientWithHandler($handler), 'products', $this->tempDirectory);
        $storage->deleteVariants('/a.png');
        self::assertSame('DeleteObjects', $handler->getLastCommand()->getName());
        self::assertSame([['Key' => 'previews/a/64.png']], $handler->getLastCommand()['Delete']['Objects']);
    }

    private function clientWithHandler(MockHandler $handler): S3Client
    {
        return new S3Client([
            'version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://minio:9000',
            'use_path_style_endpoint' => true, 'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => $handler,
        ]);
    }

    public function testRejectsInvalidPreviewWidth(): void
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'http://minio:9000',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
        ]);
        $storage = new MinioStorage($client, 'products', $this->tempDirectory);

        $this->expectException(StorageException::class);
        $storage->previewKey('12/image.jpg', 'image/jpeg', 0, false);
    }
}
