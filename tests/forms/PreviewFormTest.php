<?php

namespace modules\files\tests\forms;

use modules\files\forms\PreviewForm;
use PHPUnit\Framework\TestCase;

final class PreviewFormTest extends TestCase
{
    public function testLoadsAndValidatesPostedFields(): void
    {
        $form = new PreviewForm();
        self::assertFalse((bool)$form->applyWatermark);
        self::assertTrue($form->load(['PreviewForm' => [
            'originalKey' => 'originals/photo.png', 'width' => '120', 'applyWatermark' => '1',
        ]]));
        self::assertTrue($form->validate(), json_encode($form->errors));
        self::assertSame('originals/photo.png', $form->originalKey);
        self::assertSame('120', $form->width);
        self::assertSame('1', $form->applyWatermark);
    }

    public function testRequiresOriginalKeyAndWidth(): void
    {
        $form = new PreviewForm();
        self::assertFalse($form->validate());
        self::assertTrue($form->hasErrors('originalKey'));
        self::assertTrue($form->hasErrors('width'));
    }

    public function testRejectsInvalidScalarValues(): void
    {
        foreach (['', ' ', [], 123] as $key) {
            $form = new PreviewForm(['originalKey' => $key, 'width' => 1]);
            self::assertFalse($form->validate());
            self::assertTrue($form->hasErrors('originalKey'));
        }
        foreach ([0, -1, '1.5', 'abc', []] as $width) {
            $form = new PreviewForm(['originalKey' => 'originals/photo.png', 'width' => $width]);
            self::assertFalse($form->validate());
            self::assertTrue($form->hasErrors('width'));
        }
        $form = new PreviewForm(['originalKey' => 'originals/photo.png', 'width' => 1, 'applyWatermark' => 'yes']);
        self::assertFalse($form->validate());
        self::assertTrue($form->hasErrors('applyWatermark'));
    }

    public function testAcceptsCheckboxValuesAndMinimumWidth(): void
    {
        foreach ([false, true, 0, 1, '0', '1'] as $watermark) {
            $form = new PreviewForm(['originalKey' => 'originals/photo.png', 'width' => 1, 'applyWatermark' => $watermark]);
            self::assertTrue($form->validate(), json_encode($form->errors));
        }
    }
}
