<?php

use yii\db\Migration;

/**
 * Class m230602_185211_add_alt_to_file
 */
class m230602_185211_add_alt_to_file extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp(): void
    {
        $this->addColumn('{{%file_module}}', 'alt', $this->text()->null());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown(): void
    {
        $this->dropColumn('{{%file_module}}', 'alt');
    }
}
