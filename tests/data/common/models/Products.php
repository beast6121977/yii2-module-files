<?php

namespace modules\files\tests\data\common\models;

use yii\db\ActiveRecord;

class Products extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%test_products_common}}';
    }
}
