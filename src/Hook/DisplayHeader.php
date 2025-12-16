<?php
declare(strict_types=1);
namespace Piano\LinkedProduct\Hook;

class DisplayHeader extends AbstractHook
{
    public function run(array $params)
    {
            \PrestaShopLogger::addLog('DisplayHeader działa!');

        $this->context->controller->registerJavascript(
            'modules-po_linkedproduct-powertip',
            'modules/' . $this->module->name . '/views/js/jquery.powertip.min.js',
            ['position' => 'head', 'priority' => 100]
        );
        $this->context->controller->registerJavascript(
            'modules-po_linkedproduct-front',
            'modules/' . $this->module->name . '/views/js/front.js',
            ['position' => 'head', 'priority' => 200]
        );
        $this->context->controller->registerStylesheet(
            'modules-po_linkedproduct-style',
            'modules/' . $this->module->name . '/views/css/jquery.powertip.min.css',
            ['media' => 'all', 'priority' => 100]
        );
        $this->context->controller->registerStylesheet(
                'po_linkedproduct-front',
                'modules/' . $this->module->name . '/views/css/front.css',
                ['media' => 'all', 'priority' => 150]
        );
    }
}


