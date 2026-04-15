<?php
namespace modules\files\tests\components;

use modules\files\models\File;
use modules\files\tests\TestCase;
use modules\files\tests\data\ProjectWithCustomPrimaryKey;
use Yii;

class FileBehaviourTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setApp();
        $this->createProjectTable();
    }

    protected function tearDown(): void
    {
        $this->dropProjectTable();
        $this->clearDb();
        parent::tearDown();
    }

    public function testUsesCustomOwnerPrimaryKeyAttribute()
    {
        $file = new File([
            'class' => ProjectWithCustomPrimaryKey::class,
            'field' => 'documents',
            'title' => 'test.txt',
            'filename' => 'test.txt',
            'content_type' => 'text/plain',
            'type' => 0,
            'created' => time(),
            'size' => 1,
        ]);
        $this->assertTrue($file->save(), 'Failed to save file fixture.');

        $project = new ProjectWithCustomPrimaryKey();
        $project->project_id = 321;
        $project->documents_ids = [$file->id];

        $this->assertTrue($project->save(false), 'Failed to save project owner.');

        $savedFile = File::findOne($file->id);
        $this->assertSame(321, (int)$savedFile->object_id);

        $project = ProjectWithCustomPrimaryKey::findOne(['project_id' => 321]);
        $relatedFile = $project->documents;

        $this->assertInstanceOf(File::class, $relatedFile);
        $this->assertSame($savedFile->id, $relatedFile->id);
    }

    public function testKeepsObjectIdWhenIdsContainPlaceholderAndDuplicate()
    {
        $file = new File([
            'class' => ProjectWithCustomPrimaryKey::class,
            'field' => 'documents',
            'title' => 'test.txt',
            'filename' => 'test.txt',
            'content_type' => 'text/plain',
            'type' => 0,
            'created' => time(),
            'size' => 1,
        ]);
        $this->assertTrue($file->save(), 'Failed to save file fixture.');

        $project = new ProjectWithCustomPrimaryKey();
        $project->project_id = 321;
        $project->documents_ids = [$file->id];
        $this->assertTrue($project->save(false), 'Failed to save project owner.');

        $project = ProjectWithCustomPrimaryKey::findOne(['project_id' => 321]);
        $project->title = 'Updated';
        $project->documents_ids = [null, $file->id, $file->id];

        $this->assertTrue($project->save(false), 'Failed to update project owner.');

        $savedFile = File::findOne($file->id);
        $this->assertSame(321, (int)$savedFile->object_id);
        $this->assertSame(0, (int)$savedFile->ordering);
    }

    private function createProjectTable()
    {
        Yii::$app->db->createCommand()->createTable('{{%test_project}}', [
            'project_id' => 'pk',
            'title' => 'string',
        ])->execute();
    }

    private function dropProjectTable()
    {
        $schema = Yii::$app->db->schema->getTableSchema('{{%test_project}}', true);
        if ($schema !== null) {
            Yii::$app->db->createCommand()->dropTable('{{%test_project}}')->execute();
        }
    }
}
