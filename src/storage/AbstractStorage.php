<?php

namespace modules\files\storage;

use Yii;
use modules\files\components\SimpleImage;
use yii\base\ErrorException;
use yii\console\Application;
use yii\helpers\Console;

abstract class AbstractStorage implements StorageInterface
{
    public $module;
    public $storage;

    public function has(string $key): bool
    {
        return false;
    }

    public function putFile(string $key, string $sourcePath, string $contentType): void
    {
    }

    public function putStream(string $key, $stream, string $contentType): void
    {
    }

    public function getToLocalPath(string $key): string
    {
        return '/img/no-photo.svg';
    }

    public function read(string $key): string
    {
        return '';
    }

    public function size(string $key): int
    {
        return 32;
    }

    public function delete(string $key): void
    {
    }


    public function deleteVariants(string $filename): void
    {
    }

    public function publicUrl(string $key): ?string
    {
        return null;
    }

    /**
     * @throws ErrorException
     */
    public function generatePreview(
        string $imagePath,
        int $width = 0,
        bool $is_webp = false,
        ?string $storageFilename = null
    ): void
    {
        $this->module = \Yii::$app->getModule('files');

        if (file_exists($imagePath)) :
            $this->storage = $this->module->getStorage();
            $jpegName = $this->makeNameWithSize($imagePath, $width, false);
            $webpName = $this->makeNameWithSize($imagePath, $width, true);

            if (!$is_webp) {
                $this->createPreview($imagePath, $jpegName, $width, $this->module->watermark);
                $type = mime_content_type($jpegName);

                $previewKey = $this->storage->previewKey(
                    $storageFilename ?? basename($imagePath),
                    $type,
                    $width,
                    false
                );
                $this->storage->putFile($previewKey, $jpegName, $type);
            } else {
                if ($is_webp && !file_exists($webpName)) {
                    $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
                    $isTransparentSource = in_array($ext, ['webp', 'png'], true)
                        || in_array($this->model->content_type ?? '', ['image/webp', 'image/png'], true);

                    if ($isTransparentSource) {
                        $this->createPreviewWebpFromSource($imagePath, $webpName, $width, $this->module->watermark);
                    } else {
                        $this->createPreviewWebp($imagePath, $webpName, $width, $this->module->watermark);
                    }
                    $previewKey = $this->storage->previewKey(
                        $storageFilename ?? basename($imagePath),
                        'image/webp',
                        $width,
                        true
                    );
                    $this->storage->putFile($previewKey, $webpName, 'image/webp');
                }
            }

            if (!YII_ENV_PROD && (Yii::$app instanceof Application)) {
                Console::output(Console::ansiFormat(
                    "Создано превью: {$jpegName}, {$webpName}",
                    [Console::FG_GREEN]
                ));
            }

        endif;
    }

    /**
     * Creates file paths to file versions
     * @param $imagePath
     * @param int $width
     * @param bool $is_webp
     * @return string
     */
    private function makeNameWithSize($imagePath, $width = 0, $is_webp = false): string
    {
        $pathInfo = pathinfo($imagePath);
        $directory = $pathInfo['dirname'] ?? '';
        $directory = $directory === '.' ? '' : $directory;
        $directory = str_ireplace('/import_files', '', $directory);
        return ($directory ? $directory . DIRECTORY_SEPARATOR : ''). $this->makeShortNameWithSize($imagePath, $width, $is_webp);
    }


    private function makeShortNameWithSize($imagePath, $width = 0, $is_webp = false): string
    {
        $pathInfo = pathinfo($imagePath);
        $basename = $pathInfo['filename'] ?? '';
        $targetExtension = $this->getPreviewExtension($imagePath, $is_webp);

        return
            $basename
            . DIRECTORY_SEPARATOR
            . $width
            . '.'
            . $targetExtension;
    }

    protected function getPreviewExtension($basename, bool $is_webp = false): string
    {
        if ($is_webp) {
            return 'webp';
        }

        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'png',
            'gif' => 'gif',
            'jpg', 'jpeg' => 'jpeg',
            default => 'jpeg',
        };
    }

    /**
     * Generate all folders for storing image thumbnails cache.
     * @throws ErrorException
     */
    protected function prepareFolder($path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            throw new ErrorException('Unable to create preview cache directory.');
        }
    }


    /**
     * Creat JPG preview
     * @param $sourceImagePath
     * @throws ErrorException
     */
    protected function createPreview($sourceImagePath, $resultName, $width, $watermarkInPng = null)
    {
        $this->prepareFolder($resultName);

        $img = new SimpleImage();
        $img->load($sourceImagePath);

        if ($watermarkInPng) $img->watermark($watermarkInPng);

        $imgWidth = $img->getWidth();

        if ($width && $width < $imgWidth) {
            $img->resizeToWidth($width);
        }

        $saveType = $this->normalizeSaveType($img->image_type);

        $img->save($resultName, $saveType, 95);
    }


    /**
     *  Create webp from default preview (jpeg)
     * @throws ErrorException
     */
    protected function createPreviewWebp($source, $target, $width, $watermarkInPng = null)
    {
        $this->prepareFolder($target);

        $img = new SimpleImage();
        $img->load($source);

        if ($watermarkInPng) {
            $img->watermark($watermarkInPng);
        }

        $imgWidth = $img->getWidth();
        if ($width && $width < $imgWidth) {
            $img->resizeToWidth($width);
        }

        $img->save($target, IMAGETYPE_WEBP, 95);
    }

    /**
     * Create webp from source with transparency preserved (for webp/png).
     * Prefer Imagick, then cwebp (preserves alpha); GD last (often loses alpha on WebP load).
     * @throws ErrorException
     */
    protected function createPreviewWebpFromSource(string $sourceImagePath, string $targetImagePath, int $width, $watermarkInPng = null)
    {

        $this->prepareFolder($targetImagePath);

        if (extension_loaded('imagick')) {
            $this->createPreviewWebpFromSourceImagick($sourceImagePath, $targetImagePath, $width, $watermarkInPng);
            return;
        }

        if (!$watermarkInPng && $this->createPreviewWebpViaCwebp($sourceImagePath, $targetImagePath, $width)) {
            return;
        }

        $img = new SimpleImage();
        $img->load($sourceImagePath);

        if ($watermarkInPng) {
            $img->watermark($watermarkInPng);
        }

        $imgWidth = $img->getWidth();
        if ($width && $width < $imgWidth) {
            $img->resizeToWidth($width);
        }

        $img->save($targetImagePath, IMAGETYPE_WEBP, 95);
    }

    /**
     * Create webp via cwebp CLI (preserves transparency). Used when Imagick unavailable.
     * Used only when watermark is not requested. Returns true on success.
     * @throws ErrorException
     */
    protected function createPreviewWebpViaCwebp(string $sourceImagePath, string $targetImagePath, $width): bool
    {
        $this->prepareFolder($targetImagePath);

        $cwebp = trim((string)shell_exec('which cwebp 2>/dev/null'));
        if ($cwebp === '') {
            $cwebp = '/usr/bin/cwebp';
        }
        if (!is_file($cwebp)) {
            return false;
        }

        $srcReal = (is_file($sourceImagePath) ? realpath($sourceImagePath) : null) ?: $sourceImagePath;

        $outDir = dirname($targetImagePath);
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $resize = '';
        if ($width > 0) {
            $resize = ' -resize ' . (int)$width . ' 0';
        }
        $src = escapeshellarg($srcReal);
        $out = escapeshellarg($targetImagePath);
        $cmd = $cwebp . ' -q 95' . $resize . ' ' . $src . ' -o ' . $out . ' 2>&1';
        exec($cmd, $output, $code);

        return $code === 0 && is_file($targetImagePath) && filesize($targetImagePath) > 0;
    }

    /**
     * Create webp from source using Imagick (preserves transparency where GD fails).
     */
    protected function createPreviewWebpFromSourceImagick(string $sourceImagePath, string $targetImagePath, int $width, $watermarkInPng = null): void
    {
        $this->prepareFolder($targetImagePath);

        $img = new \Imagick($sourceImagePath);
        $img->setImageFormat('webp');
        $w = $img->getImageWidth();
        $h = $img->getImageHeight();

        if ($width && $width < $w) {
            $img->resizeImage($width, round($h * $width / $w), \Imagick::FILTER_LANCZOS, 1);
        }

        if ($watermarkInPng && is_file($watermarkInPng)) {
            $stamp = new \Imagick($watermarkInPng);
            $stamp->resizeImage($img->getImageWidth(), $img->getImageHeight(), \Imagick::FILTER_LANCZOS, 1);
            $img->compositeImage($stamp, \Imagick::COMPOSITE_OVER, 0, 0);
            $stamp->destroy();
        }

        $img->setImageCompressionQuality(95);
        $img->writeImage($targetImagePath);
        $img->destroy();
    }

    protected function normalizeSaveType(int $saveType): int
    {
        return match ($saveType) {
            IMAGETYPE_PNG => IMAGETYPE_PNG,
            IMAGETYPE_GIF => IMAGETYPE_GIF,
            default => IMAGETYPE_JPEG,
        };
    }


    /**
     * @param string $key
     * @return string
     * @psalm-pure
     */
    protected function normalizeKey(string $key): string
    {
        if (strpos($key, "\0") !== false) {
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

    /**
     * @param string $filename желательно только название файла
     * @param string $type
     * @param int $width
     * @param bool $webp
     * @return string
     */
    public function previewKey(string $filename, string $type, int $width, bool $webp): string
    {
        if ($width <= 0) {
            throw new StorageException('Preview width must be positive.');
        }

        $extension = $this->getExtension($type, $webp);

        return sprintf(
            'previews/%s/%d.%s',
            $this->filenameWithoutExtension($filename),
            $width,
            $extension
        );
    }

    public function originalKey(string $filename, string $type): string
    {
        return sprintf(
            'originals/%s',
            $this->filenameWithExtension($filename)
        );
    }

    private function getExtension(string $type, bool $webp = false): string
    {
        $extension = 'webp';
        if ($webp) return $extension;


        /**Если у оригинального файла расширение jpeg, то ключ сформируется неправильно*/
        switch ($type) {
            case 'image/jpeg':
                $extension = 'jpg';
                break;
            case 'image/png':
                $extension = 'png';
                break;
            case 'image/gif':
                $extension = 'gif';
                break;
            case 'image/webp':
                $extension = 'webp';
                break;
        }

        return $extension;
    }

    protected function filenameWithExtension(string $filename): string
    {
        $normalized = $this->normalizeKey($filename);
        return pathinfo($normalized, PATHINFO_BASENAME);
    }

    protected function filenameWithoutExtension(string $filename): string
    {
        $normalized = $this->normalizeKey($filename);
        return pathinfo($normalized, PATHINFO_FILENAME);
    }
}
