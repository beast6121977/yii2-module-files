<?php
namespace modules\files\tests\data;

use modules\files\components\FileBehaviour;
use yii\db\ActiveRecord;

class WatermarkEnabledModel extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%watermark_enabled_model}}';
    }

    public function behaviors()
    {
        return [
            'files' => [
                'class' => FileBehaviour::class,
                'attributes' => [
                    'image' => [
                        'apply_watermark' => true,
                        'watermark' => '@app/data/graphic_alpha.png',
                    ],
                    'plainImage' => [],
                ],
            ],
        ];
    }
}
