<?php

/**
 * CampType Enum
 *
 * Represents different types of camps offered by CJW Zomerkampen.
 *
 * @package CJW
 * @since 2.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Camp type enumeration.
 *
 * Defines the types of summer camps available, supporting both
 * weekend and week-long camp formats.
 *
 * @since 2.1.0
 */
enum CampType: string
{
    case WEEKEND = 'weekend';
    case WEEK = 'week';
}
