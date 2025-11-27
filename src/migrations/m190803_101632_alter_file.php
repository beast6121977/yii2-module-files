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
        $this->createIndex('file-class', '{{%file_module}}', 'class');
        $this->createIndex('file-object_id', '{{%file_module}}', 'object_id');
        $this->createIndex('file-field', '{{%file_module}}', 'field');
        $this->createIndex('file-type', '{{%file_module}}', 'type');
        $this->createIndex('file-hash', '{{%file_module}}', 'hash');
        $this->createIndex('file-filename', '{{%file_module}}', 'filename');
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
