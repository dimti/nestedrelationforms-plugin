<?php namespace Dimti\NestedRelationForms\Traits;

use October\Rain\Extension\ExtendableTrait;

/**
 * Trait ReExtendableTrait
 * @package Dimti\NestedRelationForms\Traits
 * @property array $extensionData
 * @see ExtendableTrait::extendClassWith
 */
trait ReExtendableTrait
{
    public function reExtendWith(string $extensionName, ?object $extendObject = null): void
    {
        $extensionName = str_replace('.', '\\', trim($extensionName));

        $this->extensionData['extensions'][$extensionName] = $extensionObject = $extendObject ?? new $extensionName($this);
        $this->extensionExtractMethods($extensionName, $extensionObject);
        $extensionObject->extensionApplyInitCallbacks();
    }
}
