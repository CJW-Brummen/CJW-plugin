<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    return;
}

if (! function_exists('cjw_summer_camp')) {
    /**
     * Returns the shared summer camp service.
     *
     * @return CJW_Summer_Camp_Service
     */
    function cjw_summer_camp(): CJW_Summer_Camp_Service
    {
        return CJW_Summer_Camp_Service::instance();
    }
}
