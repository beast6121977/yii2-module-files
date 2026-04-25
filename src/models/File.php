<?php
namespace modules\files\models;


use ErrorException;
use modules\files\assets\IconHelper;
use modules\files\components\FileBehaviour;
use Yii;
use yii\db\ActiveRecord;
use yii\helpers\Url;


/**
 * @property integer $id
 * @property string $class
 * @property string $field
 * @property integer $object_id
 * @property string $title
 * @property string $filename
 * @property string $content_type
 * @property integer $type
 * @property integer $video_status
 * @property integer $ordering
 * @property integer $created
 * @property integer $user_id
 * @property integer $size
 * @property string $hash
 * @property string $alt
 * @property string $href
 * @property string $icon
 * @property string $rootPath
 * @property string|null $watermark
 */
class File extends ActiveRecord
{
    const DIRECTORY_SEPARATOR = "/";
    private const WATERMARK_SIGNATURE_VERSION = 'v2';


    /**
     * {@inheritdoc}
     */
    public static function getDb()
    {
        return Yii::$app->getModule('files')->db;
    }

    /**
     * @inheritdoc
     */

    public static function tableName()
    {
        return '{{%file_module}}';
    }

    /**
     * Create hash if its empty
     * @param bool $insert
     * @return bool
     */
    public function beforeSave($insert)
    {
        if (!$this->hash) {
            $this->changeHash();
        }
        return parent::beforeSave($insert);
    }

    /**
     * Change object hash
     */
    public function changeHash()
    {
        $this->hash = md5(time() . rand(99999, 99999999));

    }

    /**
     * @return string
     */
    public function getIcon()
    {
        $icon = IconHelper::FILE;

        if ($this->content_type == 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            $icon = IconHelper::FILE_WORD;

        if ($this->content_type == 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            $icon = IconHelper::FILE_EXCEL;

        if ($this->content_type == 'application/vnd.openxmlformats-officedocument.presentationml.presentation')
            $icon = IconHelper::FILE_POWERPOINT;

        if ($this->content_type == 'application/x-zip-compressed')
            $icon = IconHelper::FILE_ARCHIVE;

        if ($this->content_type == 'application/octet-stream')
            $icon = IconHelper::FILE_ARCHIVE;

        if (preg_match('/audio/', $this->content_type))
            $icon = IconHelper::FILE_AUDIO;

        if (preg_match('/pdf/', $this->content_type))
            $icon = IconHelper::FILE_PDF;

        if ($this->type == FileType::VIDEO)
            $icon = IconHelper::FILE_VIDEO;

        return $icon;
    }

    function mime_content_type($filename)
    {
        $idx = explode('.', $filename);
        $count_explode = count($idx);
        $idx = strtolower($idx[$count_explode - 1]);

        $mimet = array(
            'txt' => 'text/plain',
            'htm' => 'text/html',
            'html' => 'text/html',
            'php' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'swf' => 'application/x-shockwave-flash',
            'flv' => 'video/x-flv',

            // images
            'png' => 'image/png',
            'jpe' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'ico' => 'image/vnd.microsoft.icon',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
            'svg' => 'image/svg+xml',
            'svgz' => 'image/svg+xml',

            // archives
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            'exe' => 'application/x-msdownload',
            'msi' => 'application/x-msdownload',
            'cab' => 'application/vnd.ms-cab-compressed',

            // audio/video
            'mp3' => 'audio/mpeg',
            'qt' => 'video/quicktime',
            'mov' => 'video/quicktime',

            // adobe
            'pdf' => 'application/pdf',
            'psd' => 'image/vnd.adobe.photoshop',
            'ai' => 'application/postscript',
            'eps' => 'application/postscript',
            'ps' => 'application/postscript',

            // ms office
            'doc' => 'application/msword',
            'rtf' => 'application/rtf',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',
            'docx' => 'application/msword',
            'xlsx' => 'application/vnd.ms-excel',
            'pptx' => 'application/vnd.ms-powerpoint',


            // open office
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        );

        if (isset($mimet[$idx])) {
            return $mimet[$idx];
        } else {
            return 'application/octet-stream';
        }
    }

    /**
     * @inheritdoc
     */

    public function rules()
    {
        return [
            [['class', 'field', 'filename', 'content_type', 'type'], 'required'],
            [['object_id', 'type', 'video_status', 'ordering'], 'integer'],
            [['class', 'field', 'title', 'filename', 'content_type', 'alt'], 'string', 'max' => 255],
        ];
    }

    /**
     * @inheritdoc
     */

    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'class' => Yii::t('app', 'Class'),
            'field' => Yii::t('app', 'Field'),
            'object_id' => Yii::t('app', 'Object ID'),
            'title' => Yii::t('app', 'Title'),
            'filename' => Yii::t('app', 'Filename'),
            'content_type' => Yii::t('app', 'Con tent Type'),
            'type' => Yii::t('app', 'Type'),
            'video_status' => Yii::t('app', 'Video Status'),
            'alt' => Yii::t('app', 'Alternative title'),
        ];
    }

    /**
     * Return root path of preview
     * @return string
     */

    public function getRootPreviewPath()
    {
        if ($this->isSvg())
            return $this->getRootPath();

        return Yii::$app->getModule('files')->storageFullPath . $this->filename . '.jpg';
    }

    /**
     * @return bool
     */
    public function isSvg()
    {
        return $this->content_type == 'image/svg+xml';
    }

    /**
     * Return root path of image
     * @return string
     */

    public function getRootPath()
    {
        return Yii::$app->getModule('files')->storageFullPath . DIRECTORY_SEPARATOR . $this->filename;
    }


    /**
     * Return web path
     * @return string
     */

    public function getHref()
    {
        return Url::to(['/files/default/get', 'hash' => $this->hash, 'v' => $this->getDeliveryVersion()]);
    }

    /**
     * @return bool
     */
    public function isImage(): bool
    {
        return $this->type == FileType::IMAGE;
    }

    /**
     * Delete files from disk
     */

    public function afterDelete()
    {
        $this->deleteFiles();
        parent::afterDelete();
    }


    /**
     * Delete all files
     */
    public function deleteFiles()
    {
        $extension = pathinfo($this->rootPath, PATHINFO_EXTENSION);
        array_map('unlink', glob(str_replace(".{$extension}", '*', $this->rootPath)));
    }

    /**
     * Set object_id to 0 to break link with object
     * @return void
     */
    public function setZeroObject()
    {
        $this->object_id = 0;
        $this->save(false);
    }

    public function shouldApplyWatermark(): bool
    {
        return (bool)($this->getFieldConfig()['apply_watermark'] ?? false);
    }

    public function getWatermarkPath(): ?string
    {
        if (!$this->shouldApplyWatermark()) {
            return null;
        }

        $watermark = $this->getFieldConfig()['watermark'] ?? null;
        if (!$watermark) {
            return null;
        }

        $resolvedPath = Yii::getAlias($watermark, false);
        return $resolvedPath ?: null;
    }

    /**
     * @return string|null
     */
    public function getWatermark()
    {
        return $this->getWatermarkPath();
    }

    public function getWatermarkSignature(): string
    {
        if (!$this->shouldApplyWatermark()) {
            return 'plain';
        }

        $path = $this->getWatermarkPath();
        if (!$path) {
            return 'wm-missing';
        }

        return substr(md5(implode(':', [
            self::WATERMARK_SIGNATURE_VERSION,
            $path,
            (string)(@filemtime($path) ?: 0),
        ])), 0, 12);
    }

    public function getDeliveryVersion(): string
    {
        return md5(implode(':', [
            $this->hash,
            $this->created,
            $this->size,
            $this->getWatermarkSignature(),
        ]));
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->href;
    }

    /**
     * Return webp path to preview
     * @param int $width
     * @param bool $webp
     * @return string
     * @throws ErrorException
     */
    public function getPreviewWebPath(int $width = 0, bool $webp = false)
    {
        if (!file_exists($this->getRootPath()))
            return null;

        if (!$this->isVideo() && !$this->isImage())
            throw new ErrorException('Requiested file is not an image and its implsible to resize it.');

        if (Yii::$app->getModule('files')->hostStatic)
            return
                Yii::$app->getModule('files')->hostStatic .
                $this->makeNameWithSize($this->filename, $width, $webp) .
                "?hash={$this->hash}&width={$width}&webp=" . intval($webp) . "&v={$this->getDeliveryVersion()}";

        return Url::toRoute([
            '/files/default/image',
            'hash' => $this->hash,
            'width' => $width,
            'webp' => $webp,
            'v' => $this->getDeliveryVersion(),
        ]);
    }

    /**
     * @return bool
     */
    public function isVideo(): bool
    {
        return $this->type == FileType::VIDEO;
    }

    /**
     * Creates file paths to file versions
     * @param $name
     * @param int $width
     * @param bool $webp
     * @return string
     */
    public function makeNameWithSize($name, $width = 0, $webp = false)
    {
        $pathInfo = pathinfo($name);
        $directory = $pathInfo['dirname'] ?? '';
        $directory = $directory === '.' ? '' : $directory;
        $basename = $pathInfo['filename'] ?? '';
        $watermarkSuffix = $this->shouldApplyWatermark() ? '_wm' . $this->getWatermarkSignature() : '';
        $targetExtension = $this->getPreviewExtension($webp);

        return ($directory ? $directory . DIRECTORY_SEPARATOR : '')
            . $basename
            . $watermarkSuffix
            . '_w'
            . (int)$width
            . '.'
            . $targetExtension;
    }

    /**
     * Returns full path to custom preview version
     * @param int $width
     * @param bool $webp
     * @return string
     * @throws ErrorException
     */
    public function getPreviewRootPath($width = 0, $webp = false)
    {
        if (!$this->isVideo() && !$this->isImage())
            throw new ErrorException('Requiested file is not an image and its implsible to resize it.');
        return $this->makeNameWithSize($this->rootPath, $width, $webp);
    }

    /**
     * @return bool
     */
    public function isFile(): bool
    {
        return $this->type == FileType::FILE;
    }

    protected function getFilesBehavior(): ?FileBehaviour
    {
        foreach ($this->getBehaviorHostCandidates() as $className) {
            $behavior = $this->createFilesBehaviorFromClass($className);
            if (!$behavior) {
                continue;
            }

            if ($className === $this->class) {
                return $behavior;
            }

            if ($behavior->baseClass === $this->class) {
                return $behavior;
            }
        }

        return null;
    }

    protected function getFieldConfig(): array
    {
        $behavior = $this->getFilesBehavior();
        if (!$behavior || !array_key_exists($this->field, $behavior->attributes)) {
            return [];
        }

        $config = $behavior->attributes[$this->field];
        return is_array($config) ? $config : [];
    }

    protected function getPreviewExtension(bool $webp = false): string
    {
        if ($webp) {
            return 'webp';
        }

        if ($this->isVideo()) {
            return 'jpeg';
        }

        $extension = strtolower((string)pathinfo($this->rootPath, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'png',
            'gif' => 'gif',
            'jpg', 'jpeg' => 'jpeg',
            default => 'jpeg',
        };
    }

    protected function getBehaviorHostCandidates(): array
    {
        $candidates = [$this->class];

        if (is_string($this->class) && $this->class !== '') {
            foreach ($this->buildNamespaceVariantCandidates($this->class) as $candidate) {
                $candidates[] = $candidate;
            }

            foreach (get_declared_classes() as $declaredClass) {
                if (is_subclass_of($declaredClass, $this->class)) {
                    $candidates[] = $declaredClass;
                }
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    protected function buildNamespaceVariantCandidates(string $className): array
    {
        $replacements = $this->getNamespaceReplacementOrder();
        $candidates = [];

        foreach ($replacements as $search => $targets) {
            if (!str_contains($className, $search)) {
                continue;
            }

            foreach ($targets as $target) {
                $candidates[] = str_replace($search, $target, $className);
            }
        }

        return $candidates;
    }

    protected function getNamespaceReplacementOrder(): array
    {
        $preferBackend = false;
        $appId = strtolower((string)(Yii::$app->id ?? ''));
        $controllerNamespace = strtolower((string)(Yii::$app->controllerNamespace ?? ''));

        if (
            str_contains($appId, 'back')
            || str_contains($appId, 'admin')
            || str_contains($controllerNamespace, '\\backend\\')
        ) {
            $preferBackend = true;
        }

        $primaryTargets = $preferBackend
            ? ['\\backend\\models\\', '\\frontend\\models\\', '\\api\\models\\']
            : ['\\frontend\\models\\', '\\backend\\models\\', '\\api\\models\\'];

        $secondaryTargets = $preferBackend
            ? ['\\backend\\', '\\frontend\\', '\\api\\']
            : ['\\frontend\\', '\\backend\\', '\\api\\'];

        return [
            '\\common\\models\\' => $primaryTargets,
            '\\common\\' => $secondaryTargets,
        ];
    }

    protected function createFilesBehaviorFromClass(string $className): ?FileBehaviour
    {
        if (!class_exists($className)) {
            return null;
        }

        try {
            $owner = new $className();
            $behavior = $owner->getBehavior('files');
        } catch (\Throwable) {
            return null;
        }

        return $behavior instanceof FileBehaviour ? $behavior : null;
    }

}
