<?php
/**
 * Created by PhpStorm.
 * User: floor12
 * Date: 27.06.2016
 * Time: 8:32
 */

namespace modules\files\controllers;


use Aws\S3\S3Client;
use modules\files\logic\StorageMigrator;
use modules\files\models\File;
use modules\files\models\FileType;
use modules\files\models\VideoStatus;
use modules\files\storage\MinioStorage;
use modules\files\storage\StorageInterface;
use Throwable;
use Yii;
use yii\console\Controller;
use yii\db\ActiveRecord;
use yii\db\StaleObjectException;
use yii\helpers\Console;
use yii\helpers\FileHelper;

class ConsoleController extends Controller
{
    public $dryRun = 1;
    public $deleteLocal = 0;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun', 'deleteLocal']);
    }

    /**
     * Migrate linked files from the legacy local storage to MinIO.
     *
     * Run `./yii files/console/migrate-to-minio` for a dry run.
     */
    public function actionMigrateToMinio(): int
    {
        $dryRun = (int)$this->dryRun === 1;
        $deleteLocal = (string)$this->deleteLocal === '1';
        $selected = 0;
        $migrated = 0;
        $uploadedObjects = 0;
        $previewCandidates = 0;
        $errors = 0;
        $module = Yii::$app->getModule('files');
        $targetStorage = null;

        $this->stdout(sprintf("Migration started: dry_run=%d, delete_local=%d\n", (int)$dryRun, (int)$deleteLocal));

        if (!$dryRun) {
            try {
                $migrator = new StorageMigrator(
                    (string)$module->storageFullPath,
                    $targetStorage = $this->createMinioStorage($module)
                );
            } catch (Throwable $exception) {
                $this->stderr('Unable to initialize MinIO storage: ' . $exception->getMessage() . PHP_EOL, Console::FG_RED);
                return 1;
            }
        }

        $legacyProgress = $this->migrateLegacyProgressAttachments(
            $module,
            $dryRun,
            $deleteLocal,
            $dryRun ? null : $targetStorage
        );
        $selected += $legacyProgress['selected'];
        $migrated += $legacyProgress['migrated'];
        $uploadedObjects += $legacyProgress['uploadedObjects'];
        $previewCandidates += $legacyProgress['previewCandidates'];
        $errors += $legacyProgress['errors'];

        foreach (File::find()->where(['!=', 'object_id', 0])->each() as $file) {
            $selected++;

            try {
                if ($this->localOriginalPath($file, $module) === null) {
                    throw new \RuntimeException('Local original is not found.');
                }

                $previews = $this->previewDescriptors($file, $module);
                $previewCandidates += count($previews);
                if ($dryRun) {
                    continue;
                }

                $uploadedObjects += $migrator->migrateFile(
                    (string)$file->filename,
                    (string)$file->content_type,
                    $previews,
                    $deleteLocal
                );
                $migrated++;
            } catch (Throwable $exception) {
                $errors++;
                $this->stderr(sprintf(
                    "Migration failed for file #%s (%s): %s\n",
                    $file->getPrimaryKey(),
                    $file->filename,
                    $exception->getMessage()
                ), Console::FG_RED);
            }
        }

        $this->stdout(sprintf(
            "Migration finished: selected=%d, migrated=%d, uploaded_objects=%d, preview_candidates=%d, errors=%d\n",
            $selected,
            $migrated,
            $uploadedObjects,
            $previewCandidates,
            $errors
        ), $errors > 0 ? Console::FG_RED : Console::FG_GREEN);

        return $errors > 0 ? 1 : 0;
    }

    /**
     * Migrate legacy nemmo/yii2-attachments files belonging to Progress.
     *
     * @return array{selected: int, migrated: int, uploadedObjects: int, previewCandidates: int, errors: int}
     */
    private function migrateLegacyProgressAttachments($module, bool $dryRun, bool $deleteLocal, ?StorageInterface $targetStorage): array
    {
        $result = [
            'selected' => 0,
            'migrated' => 0,
            'uploadedObjects' => 0,
            'previewCandidates' => 0,
            'errors' => 0,
        ];
        $attachmentsModule = Yii::$app->getModule('attachments');
        $legacyTable = $attachmentsModule->tableName;

        foreach ((new \yii\db\Query())
            ->from($legacyTable)
            ->where(['model' => 'Progress'])
            ->orderBy(['itemId' => SORT_ASC, 'id' => SORT_ASC])
            ->all(Yii::$app->db) as $attachment) {
            $result['selected']++;
            $legacyPath = $this->legacyProgressPath($attachmentsModule, $attachment);
            $filename = $attachment['hash'] . '.' . $attachment['type'];

            try {
                if (!is_file($legacyPath)) {
                    throw new \RuntimeException('Legacy original is not found: ' . $legacyPath);
                }

                $file = File::findOne([
                    'class' => 'common\\models\\Progress',
                    'field' => 'images',
                    'object_id' => (int)$attachment['itemId'],
                    'filename' => $filename,
                ]);

                /* Если запись в БД существует - удаляем с диска */
                if ($file) {
                    if (!$dryRun) {
                        $this->removeLegacyProgressAttachment($attachmentsModule, $attachment, $legacyPath, $deleteLocal);
                    }
                    $result['migrated']++;
                    continue;
                }

                if ($dryRun) {
                    $result['migrated']++;
                    $result['previewCandidates'] += $this->legacyPreviewCandidateCount($attachment['mime'], $module);
                    continue;
                }

                $file = new File([
                    'class' => 'common\\models\\Progress',
                    'field' => 'files',
                    'object_id' => (int)$attachment['itemId'],
                    'title' => $attachment['name'],
                    'filename' => $filename,
                    'content_type' => $attachment['mime'],
                    'type' => $this->legacyFileType($attachment['mime']),
                    'ordering' => (int)$attachment['id'],
                    'created' => time(),
                    'size' => (int)$attachment['size'],
                ]);
                if (!$file->save()) {
                    throw new \RuntimeException('Unable to save migrated file: ' . json_encode($file->errors));
                }

                try {
                    $key = $file->getOriginalStorageKey();
                    $targetStorage->putFile($key, $legacyPath, (string)$file->content_type);
                    if (!$targetStorage->has($key)) {
                        throw new \RuntimeException('Migrated file is missing in MinIO: ' . $key);
                    }
                    if ($file->isImage() || $file->isVideo()) {
                        $file->createPreviewsByWidths([1380]);
                    }
                } catch (Throwable $exception) {

                    $this->stderr(sprintf(
                            "Создание в сторадж неуспешно #%s: %s\n",
                            $attachment['id'],
                            $exception->getMessage()
                    ), Console::FG_RED);

                    $targetStorage->delete($file->getOriginalStorageKey());
                    $targetStorage->deleteVariants($file->filename);
                    $file->delete();
                    throw $exception;
                }

                $this->removeLegacyProgressAttachment($attachmentsModule, $attachment, $legacyPath, $deleteLocal);
                $result['migrated']++;
                $result['uploadedObjects']++;
            } catch (Throwable $exception) {
                $result['errors']++;
                $this->stderr(sprintf(
                    "Legacy Progress migration failed for attachment #%s: %s\n",
                    $attachment['id'],
                    $exception->getMessage()
                ), Console::FG_RED);
            }
        }

        return $result;
    }

    private function legacyProgressPath($attachmentsModule, array $attachment): string
    {
        $hash = (string)$attachment['hash'];
        $filename = $hash . '.' . $attachment['type'];
        $relativePath = $attachmentsModule->getSubDirs($hash)
            . DIRECTORY_SEPARATOR . $filename;
        $storePaths = [
            rtrim($attachmentsModule->getStorePath(), DIRECTORY_SEPARATOR),
            dirname(rtrim($attachmentsModule->getStorePath(), DIRECTORY_SEPARATOR)),
        ];

        foreach (array_unique($storePaths) as $storePath) {
            $path = $storePath . DIRECTORY_SEPARATOR . $relativePath;
            if (is_file($path)) {
                return $path;
            }
        }

        return $storePaths[0] . DIRECTORY_SEPARATOR . $relativePath;
    }

    private function removeLegacyProgressAttachment($attachmentsModule, array $attachment, string $legacyPath, bool $deleteLocal): void
    {
        if ($deleteLocal && is_file($legacyPath) && !unlink($legacyPath)) {
            throw new \RuntimeException('Unable to delete legacy original: ' . $legacyPath);
        }

        if (!$this->dbDelete($attachmentsModule->tableName, ['id' => $attachment['id']])) {
            throw new \RuntimeException('Unable to delete legacy attachment record #' . $attachment['id']);
        }
    }

    private function dbDelete(string $table, array $condition): bool
    {
        return (bool)Yii::$app->db->createCommand()->delete($table, $condition)->execute();
    }

    private function legacyFileType(string $mime): int
    {
        if (str_starts_with($mime, 'image/')) {
            return FileType::IMAGE;
        }
        if (str_starts_with($mime, 'video/')) {
            return FileType::VIDEO;
        }

        return FileType::FILE;
    }

    private function legacyPreviewCandidateCount(string $mime, $module): int
    {
        return str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')
            ? count(array_filter($module->previewWidths, static fn ($width) => (int)$width <= 1380)) * 2
            : 0;
    }

    /**
     * @return list<array{path: string, width: int, webp: bool, contentType: string}>
     */
    private function previewDescriptors(File $file, $module): array
    {
        if (!$file->isImage() && !$file->isVideo()) {
            return [];
        }

        $legacyRoot = rtrim((string)$module->storageFullPath, DIRECTORY_SEPARATOR);
        $relativeFilename = ltrim(str_replace('\\', '/', (string)$file->filename), '/');
        $basename = pathinfo($relativeFilename, PATHINFO_FILENAME);
        $previews = [];
        foreach ($module->previewWidths as $width) {
            $width = (int)$width;
            foreach ([false, true] as $webp) {
                $candidates = [
                    $legacyRoot . DIRECTORY_SEPARATOR . str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $file->makeNameWithSize($relativeFilename, $width, $webp)
                    ),
                ];
                if ($webp) {
                    $candidates[] = $legacyRoot . DIRECTORY_SEPARATOR . 'previews' . DIRECTORY_SEPARATOR
                        . $basename . DIRECTORY_SEPARATOR . $width . '.webp';
                } else {
                    foreach (['jpg', 'jpeg', 'png', 'gif'] as $extension) {
                        $candidates[] = $legacyRoot . DIRECTORY_SEPARATOR . 'previews' . DIRECTORY_SEPARATOR
                            . $basename . DIRECTORY_SEPARATOR . $width . '.' . $extension;
                    }
                }

                foreach (array_unique($candidates) as $path) {
                    if (!is_file($path)) {
                        continue;
                    }

                    $contentType = $webp ? 'image/webp' : (mime_content_type($path) ?: 'image/jpeg');
                    $previews[] = [
                        'path' => $path,
                        'width' => $width,
                        'webp' => $webp,
                        'contentType' => $contentType,
                    ];
                    break;
                }
            }
        }

        return $previews;
    }

    private function localOriginalPath(File $file, $module): ?string
    {
        $relativeFilename = ltrim(str_replace('\\', '/', (string)$file->filename), '/');
        $legacyPath = rtrim((string)$module->storageFullPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFilename);
        if (is_file($legacyPath)) {
            return $legacyPath;
        }

        $newPath = rtrim((string)$module->storageFullPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'originals' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativeFilename);

        return is_file($newPath) ? $newPath : null;
    }

    private function createMinioStorage($module): MinioStorage
    {
        foreach (['endpoint', 'accessKey', 'secretKey', 'region', 'bucket'] as $required) {
            if (empty($module->minio[$required]) || !is_string($module->minio[$required])) {
                throw new \yii\base\InvalidConfigException('Missing files MinIO configuration: ' . $required);
            }
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => $module->minio['region'],
            'endpoint' => $module->minio['endpoint'],
            'use_path_style_endpoint' => (bool)($module->minio['pathStyleEndpoint'] ?? true),
            'credentials' => [
                'key' => $module->minio['accessKey'],
                'secret' => $module->minio['secretKey'],
            ],
        ]);

        return new MinioStorage(
            $client,
            $module->minio['bucket'],
            (string)$module->cacheFullPath . DIRECTORY_SEPARATOR . 'minio-temp',
            (string)($module->minio['cdnUrl'] ?? '')
        );
    }

    /**
     * Run `./yii files/console/clean` to remove all unlinked files more then 6 hours
     *
     * @throws Throwable
     * @throws StaleObjectException
     */
    function actionClean()
    {
        $time = strtotime('- 6 hours');
        $files = File::find()->where(['object_id' => '0'])->andWhere(['<', 'created', $time])->all();
        if ($files) foreach ($files as $file) {
            $file->delete();
        }
    }

    function actionClear()
    {
        $countDeleted = $countOk = 0;
        $module = Yii::$app->getModule('files');
        $path1 = $module->storageFullPath;
        foreach (scandir($path1) as $folder1) {
            $path2 = $path1 . '/' . $folder1;
            if ($this->checkFolderItem($folder1)) {
                continue;
            }
            foreach (scandir($path2) as $folder2) {
                $path3 = $path2 . '/' . $folder2;
                if ($this->checkFolderItem($folder2)) {
                    continue;
                };
                foreach (scandir($path3) as $filename) {
                    $path4 = $path3 . '/' . $filename;
                    if ($this->checkFolderItem($filename)) {
                        continue;
                    };
                    $dbFileName = "/{$folder1}/{$folder2}/{$filename}";
                    if (is_file($path4)) {
                        $this->stdout($path4 . "...");
                        if (File::find()->where(['filename' => $dbFileName])->count() === 0) {
                            $this->stdout('no' . PHP_EOL, Console::FG_RED);
                            unlink($path4);
                            $countDeleted++;
                        } else {
                            $this->stdout('ok' . PHP_EOL, Console::FG_GREEN);
                            $countOk++;
                        }
                    }
                }
            }
        }
        $this->stdout('Deleted: ' . $countDeleted . PHP_EOL, Console::FG_YELLOW);
        $this->stdout('Ok: ' . $countOk . PHP_EOL, Console::FG_GREEN);
    }

    private function checkFolderItem($string)
    {
        $ignoreItems = ['.', '..', '.gitignore', 'summerfiles'];
        if (in_array($string, $ignoreItems)) {
            return true;
        }
        return false;
    }


    /**
     * Run `./yii files/console/clean-cache` to remove all generated images and previews
     */
    function actionCleanCache()
    {
        $module = Yii::$app->getModule('files');
        $deletedCount = 0;

        foreach ([$module->storageFullPath, $module->cacheFullPath] as $path) {
            $deletedCount += $this->removeGeneratedCacheFiles((string)$path);
        }

        $this->stdout('Deleted generated files: ' . $deletedCount . PHP_EOL, Console::FG_GREEN);

    }

    private function removeGeneratedCacheFiles(string $rootPath): int
    {
        if (!is_dir($rootPath)) {
            return 0;
        }

        $deletedCount = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $basename = $item->getBasename();
            if (!preg_match('/^.{32}_.*/', $basename)
                && !preg_match('/^.{32}\..{3,4}\.jpg$/', $basename)
            ) {
                continue;
            }

            if ($item->isDir() && !$item->isLink()) {
                FileHelper::removeDirectory($item->getPathname());
            } elseif (is_file($item->getPathname()) || $item->isLink()) {
                @unlink($item->getPathname());
            }

            $deletedCount++;
        }

        return $deletedCount;
    }

    /**
     * Run `./yii files/console/convert` to proccess one video file from queue with ffmpeg
     * @return bool|int
     */
    function actionConvert()
    {
        $ffmpeg = Yii::$app->getModule('files')->ffmpeg;

        if (!file_exists($ffmpeg))
            return $this->stdout("ffmpeg is not found: {$ffmpeg}" . PHP_EOL, Console::FG_RED);

        if (!is_executable($ffmpeg))
            return $this->stdout("ffmpeg is not executable: {$ffmpeg}" . PHP_EOL, Console::FG_RED);

        $file = File::find()
            ->where(['type' => FileType::VIDEO, 'video_status' => VideoStatus::QUEUE])
            ->andWhere(['!=', 'object_id', 0])
            ->one();

        if (!$file)
            return $this->stdout("Convert queue is empty" . PHP_EOL, Console::FG_GREEN);

        if (!file_exists($file->rootPath))
            return $this->stdout("Source file is not found: {$file->rootPath}" . PHP_EOL, Console::FG_RED);


        $file->video_status = VideoStatus::CONVERTING;
        $file->save();
        $width = $this->getVideoWidth($file->class, $file->field);
        $height = $this->getVideoHeight($file->class, $file->field);
        $newFileName = $file->filename . ".mp4";
        $newFilePath = $file->rootPath . ".mp4";
        $command = Yii::$app->getModule('files')->ffmpeg . " -i {$file->rootPath} -vf scale={$width}:{$height} -threads 4 {$newFilePath}";
        echo $command . PHP_EOL;
        exec($command,
            $outout, $result);
        if ($result == 0) {
            @unlink($file->rootPath);
            $file->filename = $newFileName;
            $file->content_type = 'video/mp4';
            $file->video_status = VideoStatus::READY;
        } else {
            $file->video_status = VideoStatus::QUEUE;
        }
        $file->save();

        return $this->stdout("File converted: {$file->rootPath}" . PHP_EOL, Console::FG_GREEN);
    }

    protected
    function getVideoWidth($classname, $field)
    {
        /** @var ActiveRecord $ownerClassObject */
        $ownerClassObject = new $classname;
        return $ownerClassObject->getBehavior('files')->attributes[$field]['videoWidth'] ?? 1280;
    }

    protected
    function getVideoHeight($classname, $field)
    {
        /** @var ActiveRecord $ownerClassObject */
        $ownerClassObject = new $classname;
        return $ownerClassObject->getBehavior('files')->attributes[$field]['videoHeight'] ?? -1;
    }


}
