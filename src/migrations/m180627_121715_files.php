<?php

use yii\db\Migration;

class m180627_121715_files extends Migration
{

    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable(
            '{{%file_module}}',
            [
                'id' => $this->primaryKey(11),
                'class' => $this->text()->notNull(),
                'field' => $this->text()->notNull(),
                'object_id' => $this->integer(11)->notNull()->defaultValue(0),
                'title' => $this->text()->notNull(),
                'filename' => $this->text()->notNull(),
                'content_type' => $this->text()->notNull(),
                'type' => $this->integer(1)->notNull(),
                'video_status' => $this->integer(1)->null()->defaultValue(null),
                'ordering' => $this->integer(11)->notNull()->defaultValue(0),
                'created' => $this->integer(11)->notNull(),
                'user_id' => $this->integer(11)->null(),
                'size' => $this->integer(20)->notNull(),
                'hash' => $this->text()->null(),
            ], $tableOptions
        );
    }

    public function safeDown()
    {

        $this->dropTable('{{%file_module}}');
    }
}
