<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prints frontend CSS custom properties for the configured camp theme.
 */
final class CJW_Summer_Camp_Frontend_Theme_Styles
{
    /**
     * Registers frontend rendering hook.
     *
     * @return void
     */
    public function init(): void
    {
        add_action('wp_head', [$this, 'render'], 100);
    }

    /**
     * Outputs CSS variables consumed by the theme and registration wizard.
     *
     * @return void
     */
    public function render(): void
    {
        if (is_admin() || !$this->shouldRender()) {
            return;
        }

        $camp = cjw_summer_camp();
        $colors = $camp->getThemeColors();
        $overlay_opacity = number_format($camp->getHeroOverlayOpacity() / 100, 2, '.', '');

        ?>
        <style id="cjw-summer-camp-theme-config">
            :root,
            body {
                --cjw-camp-primary:
                    <?php echo esc_html($colors['primary']); ?>
                ;
                --cjw-camp-secondary:
                    <?php echo esc_html($colors['secondary']); ?>
                ;
                --cjw-camp-accent:
                    <?php echo esc_html($colors['accent']); ?>
                ;
                --cjw-camp-hero-text:
                    <?php echo esc_html($colors['hero_text']); ?>
                ;
                --cjw-camp-hero-overlay-opacity:
                    <?php echo esc_html($overlay_opacity); ?>
                ;
                --cjw-camp-hero-gradient: linear-gradient(135deg, var(--cjw-camp-primary) 0%, var(--cjw-camp-secondary) 100%);
                --wp--preset--color--sage-green: var(--cjw-camp-primary);
                --wp--preset--color--warm-orange: var(--cjw-camp-secondary);
                --wp--preset--color--bright-red: var(--cjw-camp-accent);
                --wp--preset--gradient--green-to-orange: var(--cjw-camp-hero-gradient);
                --wp--preset--gradient--warm-to-red: linear-gradient(135deg, var(--cjw-camp-secondary) 0%, var(--cjw-camp-accent) 100%);
            }
        </style>
        <?php
    }

    /**
     * Checks if the current request needs the camp theme variables.
     *
     * @return bool
     */
    private function shouldRender(): bool
    {
        $should_render = is_front_page();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public query argument.
        if (isset($_GET[CJW_Summer_Camp_Service::getLateRegistrationQueryArg()])) {
            $should_render = true;
        }

        if (is_singular()) {
            $post = get_post();

            if ($post instanceof WP_Post) {
                $content = (string) $post->post_content;
                $should_render = $should_render
                    || has_block('core/pattern', $post)
                    || str_contains($content, 'cjw/hero')
                    || str_contains($content, 'cjw')
                    || str_contains($content, 'cjw');
            }
        }

        /**
         * Filters whether the plugin should output frontend theme CSS variables.
         *
         * @param bool $should_render Whether to print the variables for this request.
         */
        return (bool) apply_filters('cjw_summer_camp_should_render_theme_styles', $should_render);
    }
}
