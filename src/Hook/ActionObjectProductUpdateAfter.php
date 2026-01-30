<?php
declare(strict_types=1);

namespace Piano\LinkedProduct\Hook;

class ActionObjectProductUpdateAfter extends AbstractHook
{
    public function run(array $params)
    {
        $productId = 0;
        if (isset($params['object']) && isset($params['object']->id)) {
            $productId = (int) $params['object']->id;
        } elseif (isset($params['id_product'])) {
            $productId = (int) $params['id_product'];
        }

        if ($productId > 0) {
            $this->module->saveProductFamilyAssignmentFromRequest($productId);
            $this->module->updateFeatureIndexForProduct($productId);
        }

        return null;
    }
}
