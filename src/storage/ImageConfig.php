<?php

namespace modules\files\storage;

use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
use Spatie\ImageOptimizer\Optimizers\Pngquant;
use Spatie\ImageOptimizer\Optimizers\Cwebp;
use Spatie\ImageOptimizer\Optimizers\Gifsicle;
use Spatie\ImageOptimizer\Optimizers\Svgo;

enum ImageConfig: string
{
    case FILE_JPEG = 'image/jpeg';
    case FILE_JPG = 'image/jpg';
    case FILE_PNG = 'image/png';
    case FILE_GIF = 'image/gif';
    case FILE_WEBP = 'image/webp';
    case FILE_SVG = 'image/svg+xml';

    public function config()
    {
        return match ($this) {
            self::FILE_JPEG, self::FILE_JPG => (new OptimizerChain)->addOptimizer(new Jpegoptim(['--strip-all', '--all-progressive', '-m85'])),
            self::FILE_PNG => (new OptimizerChain)->addOptimizer(new Pngquant(['-i0', '-o2'])),
            self::FILE_WEBP => (new OptimizerChain)->addOptimizer(new Cwebp(['-m 6', '-pass 10', '-mt', '-q 90'])),
            self::FILE_GIF => (new OptimizerChain)->addOptimizer(new Gifsicle(['-O3'])),
            self::FILE_SVG => (new OptimizerChain)->addOptimizer(new Svgo([])),
        };
    }



}
