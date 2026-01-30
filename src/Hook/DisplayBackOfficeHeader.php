<?php
declare(strict_types=1);

namespace Piano\LinkedProduct\Hook;

use Tools;

class DisplayBackOfficeHeader extends AbstractHook
{
    public function run(array $params)
    {
        if (Tools::getValue('configure') === $this->module->name) {
            $baseUri = $this->module->getPathUri();
            $this->context->controller->addJS($baseUri.'views/js/back.js');
            $this->context->controller->addCSS($baseUri.'views/css/back.css');
        }
    }
}
