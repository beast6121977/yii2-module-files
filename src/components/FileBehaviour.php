<?php
namespace modules\files\components;


use modules\files\models\File;
use Yii;
use yii\base\Behavior;
use yii\base\ErrorException;
use yii\db\ActiveRecord;
use yii\validators\Validator;

class FileBehaviour extends Behavior
{
    /** Массив для хранения файловых атрибутов и их параметров.
     *  Задается через Behaviors в моделе
     * @var array
     */
    public $attributes = [];

    /** Базовый класс для хранения в БД. Если указан, будет использоваться вместо className().
     *  Полезно когда backend и frontend модели наследуют один базовый класс.
     * @var string|null
     */
    public $baseClass = null;

    /** Имя атрибута primary key у owner-модели.
     * @var string|null
     */
    public $ownerPrimaryKeyAttribute = null;

    /** В этот массив помещаются id связанных файлов с текущей моделью для последующейго сохранения.
     * @var array
     */
    private $_values = [];

    /**
     * Вещаем сохранение данных на события.
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_INSERT => 'filesSave',
            ActiveRecord::EVENT_AFTER_UPDATE => 'filesSave',
            ActiveRecord::EVENT_AFTER_DELETE => 'filesDelete',
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'validateRequiredFields'
        ];
    }

    protected $cachedFiles = [];

    /**
     * Возвращает имя класса для сохранения в БД.
     * Если указан baseClass, возвращает его, иначе возвращает className() владельца.
     * @return string
     */
    public function getModelClass(): string
    {
        return $this->baseClass ?? $this->owner->className();
    }

    /**
     * Метод сохранения в базу связей с файлами. Вызывается после сохранения основной модели AR.
     * @throws ErrorException
     * @throws \yii\db\Exception
     */

    public function filesSave()
    {
        $order = 0;
        $ownerPrimaryKeyValue = $this->getOwnerPrimaryKeyValue();
        if ($this->_values) {

            foreach ($this->_values as $field => $ids) {
                $ids = $this->normalizeIds($ids);

                Yii::$app->db->createCommand()->update(
                    "{{%file_module}}",
                    ['object_id' => 0],
                    [
                        'class' => $this->getModelClass(),
                        'object_id' => $ownerPrimaryKeyValue,
                        'field' => $field,
                    ]
                )->execute();

                if ($ids) foreach ($ids as $id) {
                    $file = File::findOne($id);
                    if ($file) {
                        $file->object_id = $ownerPrimaryKeyValue;
                        $file->ordering = $order++;
                        if (!$file->save()) {
                            throw new ErrorException('Невозможно обновить объект File.');
                        }
                    }
                }
            }
        }
    }

    public function filesDelete()
    {
        File::deleteAll([
            'class' => $this->getModelClass(),
            'object_id' => $this->getOwnerPrimaryKeyValue(),
        ]);
    }

    public function validateRequiredFields()
    {
        foreach ($this->attributes as $attributeName => $params) {
            $attributeIds = $this->getRealAttributeName($attributeName);

            if (
                isset($params['required']) &&
                $params['required'] &&
                in_array($this->owner->scenario, $params['requiredOn']) &&
                !in_array($this->owner->scenario, $params['requiredExcept']) &&
                !isset($this->_values[$attributeIds][1])
            )
                $this->owner->addError($attributeName, $params['requiredMessage']);
        }
    }

    /**
     * Устанавливаем валидаторы.
     * @param ActiveRecord $owner
     */
    public
    function attach($owner)
    {
        parent::attach($owner);

        // Получаем валидаторы AR
        $validators = $owner->validators;

        // Пробегаемся по валидаторам и вычисляем, какие из них касаются наших файл-полей
        if ($validators)
            foreach ($validators as $key => $validator) {

                // Сначала пробегаемся по файловым валидаторам
                if ($validator::className() == 'yii\validators\FileValidator' || $validator::className() == 'floor12\files\components\ReformatValidator') {
                    foreach ($this->attributes as $field => $params) {

                        if (is_string($params)) {
                            $field = $params;
                            $params = [];
                        }

                        $index = array_search($field, $validator->attributes);
                        if ($index !== false) {
                            $this->attributes[$field]['validator'][$validator::className()] = $validator;
                            unset($validator->attributes[$index]);
                        }
                    }
                }


                if ($validator::className() == 'yii\validators\RequiredValidator') {
                    foreach ($this->attributes as $field => $params) {

                        if (is_string($params)) {
                            $field = $params;
                            $params = [];
                        }

                        $index = array_search($field, $validator->attributes);
                        if ($index !== false) {
                            unset($validator->attributes[$index]);
                            $this->attributes[$field]['required'] = true;
                            $this->attributes[$field]['requiredExcept'] = $validator->except;
                            $this->attributes[$field]['requiredOn'] = sizeof($validator->on) ? $validator->on : [ActiveRecord::SCENARIO_DEFAULT];
                            $this->attributes[$field]['requiredMessage'] = str_replace("{attribute}", $this->owner->getAttributeLabel($field), $validator->message);
                        }
                    }
                }


            }

        // Добавляем дефолтный валидатор для прилетающих айдишников
        if ($this->attributes) foreach ($this->attributes as $fieldName => $fieldParams) {
            $validator = Validator::createValidator('safe', $owner, ["{$fieldName}_ids"]);
            $validators->append($validator);
        }
    }


    /**
     * @inheritdoc
     */
    public function canGetProperty($name, $checkVars = true)
    {
        return array_key_exists($name, $this->attributes) ?
            true : parent::canGetProperty($name, $checkVars);
    }


    /**
     * @inheritdoc
     */
    public function canSetProperty($name, $checkVars = true)
    {
        if (array_key_exists($this->getRealAttributeName($name), $this->attributes))
            return true;

        return parent::canSetProperty($name, $checkVars = true);
    }


    /**
     * @inheritdoc
     */
    public function __get($att_name)
    {
        if (isset($this->_values[$att_name])) {
            unset($this->_values[$att_name][0]);
            if (sizeof($this->_values[$att_name]))
                return array_map(function ($fileId) {
                    return File::findOne($fileId);
                }, $this->_values[$att_name]);
        } else {
            if (!isset($this->cachedFiles[$att_name])) {
                if (
                    isset($this->attributes[$att_name]['validator']) &&
                    isset($this->attributes[$att_name]['validator']['yii\validators\FileValidator']) &&
                    $this->attributes[$att_name]['validator']['yii\validators\FileValidator']->maxFiles > 1
                )
                    $this->cachedFiles[$att_name] = File::find()
                        ->where(
                            [
                                'object_id' => $this->getOwnerPrimaryKeyValue(),
                                'field' => $att_name,
                                'class' => $this->getModelClass()
                            ])
                        ->orderBy('ordering ASC')
                        ->all();
                else {
                    $this->cachedFiles[$att_name] = File::find()
                        ->where(
                            [
                                'object_id' => $this->getOwnerPrimaryKeyValue(),
                                'field' => $att_name,
                                'class' => $this->getModelClass()
                            ])
                        ->orderBy('ordering ASC')
                        ->one();
                }
            }
            return $this->cachedFiles[$att_name];
        }
    }


    /**
     * @inheritdoc
     */
    public
    function __set($name, $value)
    {
        $attribute = $this->getRealAttributeName($name);

        if (array_key_exists($attribute, $this->attributes))
            $this->_values[$attribute] = $value;
    }


    /** Отбрасываем постфикс _ids
     * @param $attribute string
     * @return string
     */
    private
    function getRealAttributeName($attribute)
    {
        return str_replace("_ids", "", $attribute);
    }

    /**
     * Возвращает значение primary key owner-модели.
     * Если явно задан ownerPrimaryKeyAttribute, используется он.
     * Иначе используется primary key ActiveRecord-модели.
     *
     * @return mixed
     * @throws ErrorException
     */
    protected function getOwnerPrimaryKeyValue()
    {
        $attribute = $this->ownerPrimaryKeyAttribute;

        if (!$attribute) {
            $primaryKey = $this->owner->primaryKey();
            if (count($primaryKey) !== 1) {
                throw new ErrorException('Composite primary keys are not supported by FileBehaviour.');
            }
            $attribute = reset($primaryKey);
        }

        if (!$this->owner->hasAttribute($attribute)) {
            throw new ErrorException("Owner primary key attribute '{$attribute}' is not found.");
        }

        return $this->owner->getAttribute($attribute);
    }

    /**
     * Нормализует список file ids: убирает пустые и дублирующиеся значения.
     *
     * @param mixed $ids
     * @return array
     */
    protected function normalizeIds($ids): array
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $normalizedIds = [];
        foreach ($ids as $id) {
            if ($id === null || $id === '' || $id === false) {
                continue;
            }

            $id = (int)$id;
            if ($id <= 0 || isset($normalizedIds[$id])) {
                continue;
            }

            $normalizedIds[$id] = $id;
        }

        return array_values($normalizedIds);
    }
}
