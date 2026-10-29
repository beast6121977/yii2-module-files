<?php

namespace modules\files\forms;

use yii\base\Model;

class PreviewForm extends Model
{
    public $originalKey;
    public $width;
    public $applyWatermark = false;

    public function rules(): array
    {
        return [
            [['originalKey', 'width'], 'required'],
            ['originalKey', 'string'],
            ['width', 'integer', 'min' => 1],
            ['applyWatermark', 'boolean'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'originalKey' => 'Original key в storage',
            'width' => 'Ширина Preview',
            'applyWatermark' => 'Наложить watermark',
        ];
    }
}
