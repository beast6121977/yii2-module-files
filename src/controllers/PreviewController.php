<?php

namespace modules\files\controllers;

use modules\files\forms\PreviewForm;
use modules\files\logic\OriginalPreviewCreator;
use Throwable;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;

class PreviewController extends Controller
{
    /** @var array<int, string> Roles allowed to create or replace previews. */
    public array $accessRoles = ['@'];

    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['create'],
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['create'],
                        'roles' => $this->accessRoles,
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['create' => ['GET', 'POST']],
            ],
        ];
    }

    public function actionCreate()
    {
        $model = new PreviewForm();
        if (Yii::$app->request->isPost && $model->load(Yii::$app->request->post()) && $model->validate()) {
            try {
                $previewKey = (new OriginalPreviewCreator())->create(
                    $model->originalKey,
                    (int)$model->width,
                    (bool)$model->applyWatermark
                );
            } catch (Throwable $error) {
                $model->addError('originalKey', $error->getMessage());
                return $this->render('create', ['model' => $model]);
            }

            Yii::$app->session->setFlash('success', $previewKey);
            return $this->redirect(['create']);
        }

        return $this->render('create', ['model' => $model]);
    }
}
