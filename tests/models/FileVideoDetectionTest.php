<?php

namespace modules\files\tests\models;

use modules\files\models\File;
use modules\files\tests\TestCase;

class FileVideoDetectionTest extends TestCase
{
    public function testVideoMimeTypeIsRecognizedAsVideo(): void
    {
        $file = new File([
            'type' => 1,
            'content_type' => 'video/mp4',
        ]);

        self::assertTrue($file->isVideo());
    }
}
