<?php

namespace modules\files\tests\controllers;

use modules\files\controllers\PreviewController;
use modules\files\Module;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\helpers\FileHelper;
use yii\web\Application;
use yii\web\MethodNotAllowedHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\IdentityInterface;
use yii\web\Response;
use yii\web\Session;
use yii\web\User;

final class PreviewTestIdentity implements IdentityInterface
{
    public static function findIdentity($id)
    {
        return new self();
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return new self();
    }

    public function getId()
    {
        return 1;
    }

    public function getAuthKey()
    {
        return 'preview-test';
    }

    public function validateAuthKey($authKey)
    {
        return $authKey === 'preview-test';
    }
}

final class PreviewControllerTest extends TestCase
{
    private string $root;
    private $previousApp;
    private array $previousSession;
    private $previousMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Yii::$app;
        $this->previousSession = $_SESSION ?? [];
        $this->previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->root = sys_get_temp_dir() . '/preview-controller-' . bin2hex(random_bytes(5));
        FileHelper::createDirectory($this->root . '/storage/originals');
        FileHelper::createDirectory($this->root . '/assets');
        // Keep Yii's flash implementation while avoiding native session headers in CLI.
        $session = new class extends Session {
            public function open() {}
            public function close() {}
        };
        new Application([
            'id' => 'preview-test', 'basePath' => dirname(__DIR__), 'layout' => false,
            'vendorPath' => dirname(__DIR__, 2) . '/vendor', 'runtimePath' => $this->root . '/runtime',
            'components' => [
                'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
                // CSRF transport is owned by Yii; exercise the action and verb filter here.
                'request' => ['cookieValidationKey' => 'test', 'enableCsrfValidation' => false,
                    'url' => '/files/preview/create',
                    'scriptFile' => __FILE__, 'scriptUrl' => '/index.php', 'baseUrl' => '', 'hostInfo' => 'http://localhost'],
                'urlManager' => ['enablePrettyUrl' => true, 'showScriptName' => false],
                'session' => $session,
                'user' => ['class' => User::class, 'identityClass' => PreviewTestIdentity::class,
                    'enableSession' => false, 'loginUrl' => null],
                'assetManager' => ['basePath' => $this->root . '/assets', 'baseUrl' => '/assets',
                    'bundles' => ['yii\web\JqueryAsset' => false, 'yii\web\YiiAsset' => false,
                        'yii\validators\ValidationAsset' => false, 'yii\widgets\ActiveFormAsset' => false]],
            ],
            'modules' => ['files' => ['class' => Module::class, 'storage' => $this->root . '/storage',
                'cache' => $this->root . '/cache']],
        ]);
    }

    protected function tearDown(): void
    {
        Yii::$app->errorHandler->unregister();
        Yii::$app = $this->previousApp;
        $_SESSION = $this->previousSession;
        if ($this->previousMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->previousMethod;
        }
        FileHelper::removeDirectory($this->root);
        parent::tearDown();
    }

    public function testRouteBelongsToDedicatedControllerAndAllowsGetPost(): void
    {
        [$controller, $action] = Yii::$app->createController('files/preview/create');
        self::assertInstanceOf(PreviewController::class, $controller);
        self::assertSame('files/preview', $controller->uniqueId);
        self::assertSame('create', $action);
        self::assertSame('actionCreate', $controller->createAction($action)->actionMethod);
        self::assertSame(['@'], $controller->accessRoles);
        self::assertSame(['@'], $controller->behaviors()['access']['rules'][0]['roles']);
        self::assertSame(['GET', 'POST'], $controller->behaviors()['verbs']['actions']['create']);
    }

    public function testGetRendersAllFieldsAndSubmitButton(): void
    {
        $this->authenticate();
        // Even populated body parameters must not cause an operation on GET.
        Yii::$app->request->setBodyParams(['PreviewForm' => ['originalKey' => 'originals/missing.png', 'width' => '80']]);
        $html = Yii::$app->runAction('files/preview/create');
        foreach (['PreviewForm[originalKey]', 'PreviewForm[width]', 'PreviewForm[applyWatermark]', 'type="submit"'] as $field) {
            self::assertStringContainsString($field, $html);
        }
        self::assertStringNotContainsString('does not exist', $html);
    }

    public function testInvalidPostRendersFieldErrorsWithoutCreatingPreview(): void
    {
        $this->authenticate();
        $html = $this->post(['originalKey' => 'originals/photo.png', 'width' => '0']);
        self::assertIsString($html);
        self::assertStringContainsString('has-error', $html);
        self::assertStringContainsString('originals/photo.png', $html);
        self::assertFalse(Yii::$app->session->hasFlash('success'));
        self::assertDirectoryDoesNotExist($this->root . '/storage/previews');
    }

    public function testValidPostCreatesWebpFlashesKeyAndRedirects(): void
    {
        $this->authenticate();
        copy(dirname(__DIR__) . '/data/photo.png', $this->root . '/storage/originals/photo.png');
        // A configured missing watermark must be ignored when checkbox is off.
        Yii::$app->getModule('files')->watermark = $this->root . '/missing-watermark.png';
        $response = $this->post(['originalKey' => 'originals/photo.png', 'width' => '120', 'applyWatermark' => '0']);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(302, $response->statusCode);
        self::assertSame('http://localhost/files/preview/create', $response->headers->get('Location'));
        self::assertSame('previews/photo/120.webp', Yii::$app->session->getFlash('success'));
        $image = getimagesize($this->root . '/storage/previews/photo/120.webp');
        self::assertSame(120, $image[0]);
        self::assertSame('image/webp', $image['mime']);
    }

    public function testGuestIsRejectedBeforeStorageChanges(): void
    {
        copy(dirname(__DIR__) . '/data/photo.png', $this->root . '/storage/originals/photo.png');

        $this->expectException(ForbiddenHttpException::class);
        try {
            $this->post(['originalKey' => 'originals/photo.png', 'width' => '120']);
        } finally {
            self::assertDirectoryDoesNotExist($this->root . '/storage/previews');
        }
    }

    public function testCreatorErrorsRenderOnTheForm(): void
    {
        $this->authenticate();
        foreach (['originals/missing.png', 'originals/../photo.png', '/tmp/photo.png'] as $key) {
            $html = $this->post(['originalKey' => $key, 'width' => '120']);
            self::assertIsString($html);
            self::assertStringContainsString('has-error', $html);
            self::assertFalse(Yii::$app->session->hasFlash('success'));
        }
    }

    public function testEnabledMissingWatermarkRendersError(): void
    {
        $this->authenticate();
        copy(dirname(__DIR__) . '/data/photo.png', $this->root . '/storage/originals/photo.png');
        $html = $this->post(['originalKey' => 'originals/photo.png', 'width' => '120', 'applyWatermark' => '1']);
        self::assertStringContainsString('Watermark file does not exist', $html);
        self::assertStringContainsString('has-error', $html);
        self::assertFalse(Yii::$app->session->hasFlash('success'));
    }

    public function testRejectsOtherVerbs(): void
    {
        $this->authenticate();
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $this->expectException(MethodNotAllowedHttpException::class);
        Yii::$app->runAction('files/preview/create');
    }

    private function post(array $fields)
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Yii::$app->request->setBodyParams(['PreviewForm' => $fields]);
        return Yii::$app->runAction('files/preview/create');
    }

    private function authenticate(): void
    {
        Yii::$app->user->setIdentity(new PreviewTestIdentity());
    }
}
