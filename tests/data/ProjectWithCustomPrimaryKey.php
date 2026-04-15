<?php
namespace modules\files\tests\data;

use modules\files\components\FileBehaviour;
use yii\db\ActiveRecord;

class ProjectWithCustomPrimaryKey extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%test_project}}';
    }

    public function behaviors()
    {
        return [
            'files' => [
                'class' => FileBehaviour::class,
                'ownerPrimaryKeyAttribute' => 'project_id',
                'attributes' => [
                    'documents' => [],
                ],
            ],
        ];
    }
}
