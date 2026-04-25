<?php

namespace modules\files\tests\data\frontend\models;

use modules\files\components\FileBehaviour;
use modules\files\tests\data\common\models\Products as BaseProducts;

class Products extends BaseProducts
{
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
                ],
                'baseClass' => BaseProducts::class,
            ],
        ];
    }
}
