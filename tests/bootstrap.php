<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../../../../');
}

if (! function_exists('__')) {
    function __(string $text, string $domain = ''): string
    {
        return $text;
    }
}

if (! function_exists('is_email')) {
    function is_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (! function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
    {
        return [];
    }
}

if (! function_exists('wp_parse_args')) {
    function wp_parse_args($args, array $defaults = []): array
    {
        if (is_array($args)) {
            return array_merge($defaults, $args);
        }

        return $defaults;
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test bootstrap shim for WordPress sanitizer.
        return trim(strip_tags($value));
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $value): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test bootstrap shim for WordPress sanitizer.
        return trim(strip_tags($value));
    }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = ''): string
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Test bootstrap shim mirrors the WordPress translation helper.
        return esc_html(__($text, $domain));
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}

if (! function_exists('esc_textarea')) {
    function esc_textarea(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $text): string
    {
        return $text;
    }
}

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test bootstrap shim for WordPress helper.
        return trim(strip_tags($text));
    }
}

if (! function_exists('wp_date')) {
    function wp_date(string $format, int $timestamp): string
    {
        return gmdate($format, $timestamp);
    }
}

if (! function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

if (! function_exists('number_format_i18n')) {
    function number_format_i18n(float $number, int $decimals = 0): string
    {
        return number_format($number, $decimals, ',', '.');
    }
}

if (! function_exists('checked')) {
    function checked(mixed $checked, mixed $current = true, bool $display = true): string
    {
        return $checked == $current ? 'checked="checked"' : '';
    }
}

if (! function_exists('sanitize_html_class')) {
    function sanitize_html_class(string $class): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $class) ?? '';
    }
}

if (! function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action, string $name): void
    {
        printf(
            '<input type="hidden" name="%s" value="nonce-for-%s">',
            esc_attr($name),
            esc_attr($action)
        );
    }
}

if (! function_exists('add_query_arg')) {
    function add_query_arg(array|string $key = [], string $value = '', string $url = 'http://example.test/current'): string
    {
        $base = $url;
        $params = [];

        if (is_string($key) && $key !== '') {
            $params[ $key ] = $value;
        } elseif (is_array($key)) {
            $params = $key;
        }

        if ($params === []) {
            return $base;
        }

        return $base . '?' . http_build_query($params);
    }
}

if (! function_exists('wp_nonce_url')) {
    function wp_nonce_url(string $url, string $action, string $name = '_wpnonce'): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . rawurlencode($name) . '=' . rawurlencode('nonce-for-' . $action);
    }
}

require_once __DIR__ . '/../inc/shared/class-camp-type.php';
require_once __DIR__ . '/../inc/shared/localization.php';
require_once __DIR__ . '/../inc/form-builder/domain/class-wizard-defaults.php';
require_once __DIR__ . '/../inc/form-builder/domain/class-wizard-config-normalizer.php';
require_once __DIR__ . '/../inc/form-builder/admin/class-wizard-settings.php';
require_once __DIR__ . '/../inc/form-builder/integrations/class-summer-camp-context.php';
require_once __DIR__ . '/../inc/form-builder/frontend/class-wizard-conditions.php';
require_once __DIR__ . '/../inc/form-builder/frontend/class-wizard-validator.php';
require_once __DIR__ . '/../inc/form-builder/frontend/class-wizard-engine.php';
require_once __DIR__ . '/../inc/form-builder/frontend/class-wizard-renderer.php';
require_once __DIR__ . '/../inc/form-builder/submissions/class-wizard-submission-repository.php';
require_once __DIR__ . '/support/TestCase.php';
