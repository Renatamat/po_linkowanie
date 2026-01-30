<?php
declare(strict_types=1);

namespace Piano\LinkedProduct\Hook;

use \Context;
use \Module;

abstract class AbstractHook
{
    /** @var Module */
    protected $module;

    /** @var Context */
    protected $context;

    /**
     * Działa na PS 1.7.6 (PHP 7.1/7.2/7.3) oraz wyżej. 
     * $context jest opcjonalny – dla starszych wersji bierzemy Context::getContext().
     *
     * @param Module       $module
     * @param Context|null $context
     */
    public function __construct($module, $context = null)
    {
        if (!($module instanceof Module)) {
            throw new \InvalidArgumentException('Expected instance of Module');
        }

        $this->module  = $module;
        $this->context = ($context instanceof Context) ? $context : Context::getContext();
    }

    /**
     * @param array $params
     * @return mixed
     */
    abstract public function run(array $params);
}
