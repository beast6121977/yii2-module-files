<?php
/**
 * Created by PhpStorm.
 * User: floor12
 * Date: 07.01.2018
 * Time: 12:40
 */

namespace modules\files\tests;

use modules\files\Module;
use modules\files\tests\data\m180627_121715_files;
use Yii;
use yii\console\Application;
use yii\web\UrlManager;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{

    public $sqlite = 'tests/sqlite.db';

    /**
     * Чистим за собой временную базу данных
     */
    protected function clearDb()
    {
        Yii::createObject(m180627_121715_files::class, [])->safeDown();
    }

    /**
     * Настраиваем основные параметры приложения: базу данных и модуль
     */

    protected function setApp()
    {
        $storagePath = sys_get_temp_dir() . '/yii2-module-files-tests/storage';
        $cachePath = sys_get_temp_dir() . '/yii2-module-files-tests/cache';
        $files = [
            'class' => Module::class,
            'storage' => $storagePath,
            'cache' => $cachePath,
        ];
        Yii::$app->setModule('files', $files);


        $db = [
            'class' => 'yii\db\Connection',
            'dsn' => "sqlite:$this->sqlite",
        ];
        Yii::$app->set('db', $db);

        $urlManager = [
            'class' => UrlManager::class,
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'baseUrl' => 'http://test.com',
        ];
        Yii::$app->set('urlManager', $urlManager);

        Yii::createObject(m180627_121715_files::class, [])->safeUp();

    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        $this->destroyApplication();
        parent::tearDown();
    }

    /**
     * Убиваем приложение
     */
    protected function destroyApplication()
    {
        Yii::$app = null;
    }

    /**
     * @inheritdoc
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->mockApplication();
    }

    /**
     *  Запускаем приложение
     */
    protected function mockApplication()
    {
        new Application([
            'id' => 'testapp',
            'basePath' => __DIR__,
            'vendorPath' => dirname(__DIR__) . '/vendor',
            'runtimePath' => __DIR__ . '/runtime',
        ]);
    }
}
