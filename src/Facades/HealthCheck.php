<?php

namespace BoosterOps\HealthCheck\Facades;

use Illuminate\Support\Facades\Facade;

class HealthCheck extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'healthcheck';
    }
}
