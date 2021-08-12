<?php namespace Dimti\NestedRelationForms\Traits;

use Backend\Classes\FormWidgetBase;
use Backend\Widgets\Form;
use Dimti\Mirsporta\Controllers\ProductDescriptions;
use Dimti\Mirsporta\Models\ProductDescriptionNew;
use Dimti\NestedRelationForms\Behaviors\RelationController;
use Html;
use File;
use Lang;
use Block;
use Config;
use October\Rain\Exception\ExceptionBase;
use SystemException;
use Throwable;

/**
 * ViewMaker Trait adds view based methods to a class
 *
 * @package october\system
 * @author Alexey Bobkov, Samuel Georges
 */
trait ViewMaker
{
    use \System\Traits\ViewMaker;


    /**
     * makePartial renders a partial file contents located in the views folder
     * @param string $partial The view to load.
     * @param array $params Parameter variables to pass to the view.
     * @param bool $throwException Throw an exception if the partial is not found.
     * @return mixed Partial contents or false if not throwing an exception.
     */
    public function makePartial($partial, $params = [], $throwException = true)
    {
        $notRealPath = realpath($partial) === false || is_dir($partial) === true;
        if (!File::isPathSymbol($partial) && $notRealPath) {
            $folder = strpos($partial, '/') !== false ? dirname($partial) . '/' : '';
            $partial = $folder . '_' . strtolower(basename($partial)).'.htm';
        }

        if ($params &&
            array_key_exists('field', $params) &&
            array_key_exists('formModel', $params) &&
            $params['field']->type == 'partial' &&
            $params['formModel']->hasRelation($params['field']->fieldName)
        ) {
            if (method_exists($params['formModel'], 'getControllerClassName') &&
                get_class($this) != ($modelControllerClassName = $params['formModel']->getControllerClassName())
            ) {
                $this->oldViewPath = $this->viewPath;

                $this->viewPath = (new $modelControllerClassName())->viewPath;
            }
        } else if (isset($this->oldViewPath) && $this->oldViewPath && $this->oldViewPath != $this->viewPath) {
            $this->viewPath = $this->oldViewPath;

            unset($this->oldViewPath);
        }

        $partialPath = $this->getViewPath($partial);

        if (!File::exists($partialPath)) {
            if ($throwException) {
                throw new SystemException(Lang::get('backend::lang.partial.not_found_name', ['name' => $partialPath]));
            }

            return false;
        }

        return $this->makeFileContents($partialPath, $params);
    }

    /**
     * makeFileContents includes a file path using output buffering
     * @param string $filePath Absolute path to the view file.
     * @param array $extraParams Parameters that should be available to the view.
     * @return string
     */
    public function makeFileContents($filePath, $extraParams = [])
    {
        if (!strlen($filePath) ||
            !File::isFile($filePath) ||
            (!File::isLocalPath($filePath) && Config::get('system.restrict_base_dir', true))
        ) {
            return '';
        }

        if (!is_array($extraParams)) {
            $extraParams = [];
        }

        $vars = array_merge($this->vars, $extraParams);

        $obLevel = ob_get_level();

        ob_start();

        extract($vars);

        // We'll evaluate the contents of the view inside a try/catch block so we can
        // flush out any stray output that might get out before an error occurs or
        // an exception is thrown. This prevents any partial views from leaking.
        try {
            if ($extraParams &&
                array_key_exists('field', $extraParams) &&
                array_key_exists('formModel', $extraParams) &&
                $extraParams['field']->type == 'partial' &&
                $extraParams['formModel']->hasRelation($extraParams['field']->fieldName)
            ) {
                $controller = $this instanceof Form ? $this->controller : $this;

                if (method_exists($extraParams['formModel'], 'getControllerClassName') &&
                    get_class($controller) != ($modelControllerClassName = $extraParams['formModel']->getControllerClassName())
                ) {
                    $modelController = (new $modelControllerClassName());

                    if ($this instanceof Form) {
                        $eventTarget = $this->controller->getEventTarget();

                        $this->controller = $modelController;

                        if ($eventTarget) {
                            $this->controller->setEventTarget($eventTarget);
                        }

                    } else {
                        $this->setRelationConfig($modelController->getConfig());
                    }
                }

                if (!$controller->getModel() || get_class($controller->getModel()) != get_class($extraParams['formModel'])) {
                    $controller->initRelation($extraParams['formModel'], $extraParams['field']->fieldName);
                }
            } else {
                if (!$this instanceof Form && $this->oldConfig) {
                    $this->restoreRelationConfig();
                }
            }

            include $filePath;
        }
        catch (Throwable $e) {
            $this->handleViewException($e, $obLevel);
        }

        return ob_get_clean();
    }

}
