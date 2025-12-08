<?php

use yii\db\Migration;

/**
 * Class m190803_101632_alter_file
 */
class m190803_101632_alter_file extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createIndex('file-module-class', '{{%file_module}}', 'class');
        $this->createIndex('file-module-object_id', '{{%file_module}}', 'object_id');
        $this->createIndex('file-module-field', '{{%file_module}}', 'field');
        $this->createIndex('file-module-type', '{{%file_module}}', 'type');
        $this->createIndex('file-module-hash', '{{%file_module}}', 'hash');
        $this->createIndex('file-module-filename', '{{%file_module}}', 'filename');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        echo "m190803_101632_alter_file cannot be reverted.\n";

        return false;
    }

}
