<?php namespace Dimti\NestedRelationForms\Behaviors;

use Backend\Widgets\Form;
use Dimti\Mirsporta\Controllers\ProductDescriptions;
use Dimti\Mirsporta\Models\ProductDescriptionNew;

class FormController extends \Backend\Behaviors\FormController
{
    /**
     * Prepares commonly used view data.
     * @param October\Rain\Database\Model $model
     */
    protected function prepareVars($model)
    {
        /*
         * Detected Relation controller behavior
         */
        if ($this->controller->isClassExtendedWith('Dimti.NestedRelationForms.Behaviors.RelationController')) {
            if (($parentModelClassName = str_replace('.', '\\', post('parent_model'))) &&
                ($parentModelId = post('parent_model_id')) &&
                get_class($model) != $parentModelClassName
            ) {
                $model = $parentModelClassName::find($parentModelId);
            }

            $controllerIsChanged = false;

            if (method_exists($model, 'getControllerClassName') &&
                get_class($this->controller) != ($modelControllerClassName = $model->getControllerClassName())
            ) {
                $eventTarget = $this->controller->getEventTarget();

                $this->parentController = $this->controller;

                $this->controller = (new $modelControllerClassName);

                if ($eventTarget) {
                    $this->controller->setEventTarget($eventTarget);
                }

                $controllerIsChanged = true;
            }

            $this->controller->initRelation($model);

            if ($controllerIsChanged) {
                foreach ($this->controller->widget as $alias => $widget) {
                    $this->parentController->widget->{$alias} = $widget;
                }
            }
        }

        parent::prepareVars($model);
    }
}
