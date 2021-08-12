<?php namespace Dimti\NestedRelationForms\Behaviors;

use Backend\Widgets\Form;
use File;
use Config;
use October\Rain\Database\Model;
use October\Rain\Database\Relations\BelongsTo;
use October\Rain\Database\Relations\HasOne;
use October\Rain\Exception\ExceptionBase;
use ApplicationException;
use Lang;
use Request;

class RelationController extends \Backend\Behaviors\RelationController
{
    protected $oldConfig;

    /**
     * @var Model parentRelationModel
     */
    protected static $parentRelationModel;

    public function getViewPath($fileName, $viewPath = null)
    {
        if (isset($this->viewPath) && $this->viewPath) {
            $fileName = File::symbolizePath($fileName);

            if (File::isLocalPath($fileName) ||
                (!Config::get('system.restrict_base_dir', true) && realpath($fileName) !== false)
            ) {
                return $fileName;
            }

            foreach ([$this->viewPath] as $path) {
                $_fileName = File::symbolizePath($path) . '/' . $fileName;
                if (File::isFile($_fileName)) {
                    return $_fileName;
                }
            }

            $viewPath = str_replace('plugins/dimti/nestedrelationforms', 'modules/backend', $this->viewPath);
        }

        return parent::getViewPath($fileName, $viewPath);
    }

    protected function beforeAjax()
    {
        parent::beforeAjax();

        if (method_exists($this->relationModel, 'getControllerClassName') &&
            $relationModelControllerClassName = $this->relationModel->getControllerClassName()
        ) {
            $relationModelController = (new $relationModelControllerClassName());

            $this->parentController = $this->controller;

            $this->parentController->vars = array_merge($this->parentController->vars, $this->vars);

            $this->controller = $relationModelController;

            if ($this->forceManageMode == 'form') {
//                $relationConfigFromNewController = $relationModelController->getConfig();
//
//                $this->controller->reExtendWith(get_class($this), $this);
//
//                $this->setRelationConfig($relationConfigFromNewController);
            }

            $this->controller->setEventTarget($this->parentController->getEventTarget());

            foreach ($this->controller->widget as $alias => $widget) {
                $this->parentController->widget->{$alias} = $widget;
            }

            if (in_array(get_class($this->relationObject), [HasOne::class, BelongsTo::class])) {
                static::setParentRelationModel($this->relationObject->getResults());
            }

        }
    }

    public function getModel()
    {
        return $this->model;
    }

    public function setModel($model)
    {
        $this->model = $model;
    }

    public static function setParentRelationModel($model)
    {
        static::$parentRelationModel = $model;
    }

    public function getParentRelationModel()
    {
        return static::$parentRelationModel ?? $this->model;
    }

    public function setRelationConfig($config)
    {
        $this->oldConfig = $this->originalConfig;

        $this->originalConfig = $config;
    }

    public function restoreRelationConfig()
    {
        $this->originalConfig = $this->oldConfig;
    }


    protected function makeManageWidget()
    {
        $widget = null;

        /*
         * List / Pivot
         */
        if ($this->manageMode == 'list' || $this->manageMode == 'pivot') {
            $isPivot = $this->manageMode == 'pivot';

            $config = $this->makeConfigForMode('manage', 'list');
            $config->model = $this->relationModel;
            $config->alias = $this->alias . 'ManageList';
            $config->showSetup = false;
            $config->showCheckboxes = $this->getConfig('manage[showCheckboxes]', !$isPivot);
            $config->showSorting = $this->getConfig('manage[showSorting]', !$isPivot);
            $config->defaultSort = $this->getConfig('manage[defaultSort]');
            $config->recordsPerPage = $this->getConfig('manage[recordsPerPage]');

            if ($this->viewMode == 'single') {
                $config->showCheckboxes = false;
                $config->recordOnClick = sprintf(
                    "$.oc.relationBehavior.clickManageListRecord(':%s', '%s', '%s')",
                    $this->relationModel->getKeyName(),
                    $this->relationGetId(),
                    $this->relationGetSessionKey()
                );
            }
            elseif ($config->showCheckboxes) {
                $config->recordOnClick = "$.oc.relationBehavior.toggleListCheckbox(this)";
            }
            elseif ($isPivot) {
                $config->recordOnClick = sprintf(
                    "$.oc.relationBehavior.clickManagePivotListRecord(':%s', '%s', '%s')",
                    $this->relationModel->getKeyName(),
                    $this->relationGetId(),
                    $this->relationGetSessionKey()
                );
            }

            $widget = $this->makeWidget('Backend\Widgets\Lists', $config);

            /*
             * Apply defined constraints
             */
            if ($sqlConditions = $this->getConfig('manage[conditions]')) {
                $widget->bindEvent('list.extendQueryBefore', function ($query) use ($sqlConditions) {
                    $query->whereRaw($sqlConditions);
                });
            }
            elseif ($scopeMethod = $this->getConfig('manage[scope]')) {
                $widget->bindEvent('list.extendQueryBefore', function ($query) use ($scopeMethod) {
                    $query->$scopeMethod($this->model);
                });
            }
            else {
                $widget->bindEvent('list.extendQueryBefore', function ($query) {
                    $this->relationObject->addDefinedConstraintsToQuery($query);

                    // Reset any orders that may have come from the definition
                    // because it has a tendency to break things
                    $query->getQuery()->orders = [];
                });
            }

            /*
             * Link the Search Widget to the List Widget
             */
            if ($this->searchWidget) {
                $this->searchWidget->bindEvent('search.submit', function () use ($widget) {
                    $widget->setSearchTerm($this->searchWidget->getActiveTerm());
                    return $widget->onRefresh();
                });

                /*
                 * Persist the search term across AJAX requests only
                 */
                if (Request::ajax()) {
                    $widget->setSearchTerm($this->searchWidget->getActiveTerm());
                }
            }

            /*
             * Link the Filter Widget to the List Widget
             */
            if ($this->manageFilterWidget) {
                $this->manageFilterWidget->bindEvent('filter.update', function () use ($widget) {
                    return $widget->onFilter();
                });

                // Apply predefined filter values
                $widget->addFilter([$this->manageFilterWidget, 'applyAllScopesToQuery']);
            }
        }
        /*
         * Form
         */
        elseif ($this->manageMode == 'form') {
            if (!$config = $this->makeConfigForMode('manage', 'form', false)) {
                return null;
            }

            if (in_array(get_class($this->relationObject), [HasOne::class, BelongsTo::class])) {
                $config->model = $this->viewModel ?? ($this->relationObject->getResults() ?: $this->relationModel);
            } else {
                $config->model = $this->relationModel;
            }

            $config->arrayName = class_basename($this->relationModel);
            $config->context = $this->evalFormContext('manage', !!$this->manageId);
            $config->alias = $this->alias . 'ManageForm';

            /*
             * Existing record
             */
            if ($this->manageId && !$config->model->exists) {
                $model = $config->model->find($this->manageId);
                if ($model) {
                    $config->model = $model;
                } elseif(0) {
                    throw new ApplicationException(Lang::get('backend::lang.model.not_found', [
                        'class' => get_class($config->model),
                        'id' => $this->manageId,
                    ]));
                }
            }

            $widget = $this->makeWidget('Backend\Widgets\Form', $config);
        }

        if (!$widget) {
            return null;
        }

        /*
         * Exclude existing relationships
         */
        if ($this->manageMode == 'pivot' || $this->manageMode == 'list') {
            $widget->bindEvent('list.extendQuery', function ($query) {
                /*
                 * Where not in the current list of related records
                 */
                $existingIds = $this->findExistingRelationIds();
                if (count($existingIds)) {
                    $query->whereNotIn($this->relationModel->getQualifiedKeyName(), $existingIds);
                }
            });
        }

        return $widget;
    }

    protected function validateField($field = null)
    {
        $field = $field ?: post(self::PARAM_FIELD);

        if ($field && !$this->model) {
            if (($relationModelControllerClassName = str_replace('.', '\\', post('parent_model'))) &&
                ($parentModelId = post('parent_model_id'))
            ) {
                $this->model = $relationModelControllerClassName::find($parentModelId);
            }

            if (method_exists($this->model, 'getControllerClassName') &&
                $relationModelControllerClassName = $this->model->getControllerClassName()
            ) {
                $this->parentController = $this->controller;

                $this->controller = $relationModelController = (new $relationModelControllerClassName());

                $relationConfigFromNewController = $relationModelController->getConfig();

                $this->controller->reExtendWith(get_class($this), $this);

                $this->setRelationConfig($relationConfigFromNewController);
            }
        }

        return parent::validateField($field);
    }

    /**
     * Determine the management mode based on the relation type and settings.
     * @return string
     */
    protected function evalManageMode()
    {
        if ($this->forceManageMode) {
            return $this->forceManageMode;
        }

        switch ($this->eventTarget) {
            case 'button-create':
            case 'button-update':
                return 'form';

            case 'button-link':
            case 'button-add':
                return 'list';
        }

        if ($mode = post(self::PARAM_MODE)) {
            return $mode;
        }

        switch ($this->relationType) {
            case 'belongsTo':
                return 'list';

            case 'morphToMany':
            case 'morphedByMany':
            case 'belongsToMany':
                if (isset($this->config->pivot)) {
                    return 'pivot';
                }
                elseif ($this->eventTarget == 'list') {
                    return 'form';
                }
                else {
                    return 'list';
                }

            case 'hasOne':
            case 'morphOne':
            case 'hasMany':
            case 'morphMany':
                if ($this->eventTarget == 'button-add') {
                    return 'list';
                }

                return 'form';
        }
    }

    public function getEventTarget()
    {
        return $this->eventTarget;
    }

    public function setEventTarget($eventTarget)
    {
        $this->eventTarget = $eventTarget;
    }
}
