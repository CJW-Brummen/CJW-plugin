<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Meta_Boxes
{
    private CJW_Wizard_Settings $settings;

    public function __construct(CJW_Wizard_Settings $settings)
    {
        $this->settings = $settings;
    }

    public function init(): void
    {
        add_action('add_meta_boxes', [ $this, 'register' ]);
        add_action('save_post_cjw_wizard', [ $this, 'save' ]);
    }

    public function register(): void
    {
        add_meta_box(
            'cjw_wizard_builder_meta',
            __('Formulier Bouwer', 'cjw'),
            [ $this, 'render_builder' ],
            'cjw_wizard',
            'normal',
            'high'
        );

        add_meta_box(
            'cjw_wizard_settings_meta',
            __('Formulier Instellingen', 'cjw'),
            [ $this, 'render_settings' ],
            'cjw_wizard',
            'side',
            'high'
        );
    }

    public function render_builder(WP_Post $post): void
    {
        wp_nonce_field('cjw_wizard_save_meta', 'cjw_wizard_nonce');

        $config = get_post_meta($post->ID, '_cjw_wizard_config', true);
        if (empty($config)) {
            $config = wp_json_encode([ 'steps' => [] ]);
        }

        require_once CJW_DIR . 'inc/form-builder/builder/views/builder.php';
    }

    public function render_settings(WP_Post $post): void
    {
        $settings = $this->settings->get($post->ID);

        echo '<div class="cjw-wizard-side-settings">';

        echo '<div class="cjw-shortcode-preview" style="background: #f0f6fb; padding: 12px; border-left: 4px solid #0073aa; margin-bottom: 16px;">';
        echo '<p style="margin-top: 0;"><strong>' . esc_html__('Shortcode', 'cjw') . '</strong></p>';
        echo '<code id="cjw-shortcode-text" style="display: block; background: #fff; padding: 8px 10px; border: 1px solid #ccd0d4; border-radius: 3px; margin-bottom: 10px;">[cjw_wizard id="' . esc_attr((string) $post->ID) . '"]</code>';
        echo '<button type="button" class="button button-secondary" id="cjw-copy-shortcode">' . esc_html__('Kopieer shortcode', 'cjw') . '</button>';
        echo '</div>';

        $this->render_text_setting(
            'submit_label',
            __('Tekst knop volgende', 'cjw'),
            $settings['submit_label'],
            __('Bijvoorbeeld: Volgende', 'cjw')
        );

        $this->render_text_setting(
            'complete_label',
            __('Tekst knop afronden', 'cjw'),
            $settings['complete_label'],
            __('Bijvoorbeeld: Inschrijving versturen', 'cjw')
        );

        $this->render_text_setting(
            'previous_label',
            __('Tekst knop vorige', 'cjw'),
            $settings['previous_label'],
            __('Bijvoorbeeld: Terug', 'cjw')
        );

        $this->render_checkbox_setting(
            'show_previous_button',
            __('Toon knop vorige stap', 'cjw'),
            $settings['show_previous_button']
        );

        $this->render_textarea_setting(
            'success_message',
            __('Succesbericht na afronden', 'cjw'),
            $settings['success_message'],
            __('Bericht dat bezoekers zien nadat het formulier is afgerond.', 'cjw')
        );

        echo '<hr>';
        echo '<p><strong>' . esc_html__('Zomerkamp koppeling', 'cjw') . '</strong><br><span class="description">' . esc_html__('Gebruik centrale zomerkampgegevens voor status, intro, datums, prijzen en thema.', 'cjw') . '</span></p>';

        $this->render_checkbox_setting(
            'use_summer_camp_settings',
            __('Gebruik Zomerkamp instellingen voor dit formulier', 'cjw'),
            $settings['use_summer_camp_settings']
        );

        echo '<div id="cjw-wizard-summer-camp-intro-wrapper"' . ($settings['use_summer_camp_settings'] ? '' : ' style="display:none;"') . '>';
        $this->render_checkbox_setting(
            'show_summer_camp_intro',
            __('Toon introductietekst boven het formulier', 'cjw'),
            $settings['show_summer_camp_intro']
        );
        echo '<p class="description">' . esc_html__('Beschikbare tokens: {camp_theme_name}, {camp_date_range}, {camp_start_date}, {camp_end_date}, {camp_price_week}, {camp_price_weekend}.', 'cjw') . '</p>';
        echo '</div>';

        echo '<hr>';
        echo '<p><strong>' . esc_html__('E-mail & export', 'cjw') . '</strong><br><span class="description">' . esc_html__('Voorbereid voor latere verwerking van inschrijvingen.', 'cjw') . '</span></p>';

        $this->render_checkbox_setting(
            'send_admin_notification',
            __('Stuur admin melding bij inzending', 'cjw'),
            $settings['send_admin_notification']
        );

        echo '<div id="cjw-wizard-notification-email-wrapper"' . ($settings['send_admin_notification'] ? '' : ' style="display:none;"') . '>';
        $this->render_text_setting(
            'notification_email',
            __('E-mailadres meldingen', 'cjw'),
            $settings['notification_email'],
            __('Leeg laten om het beheerdersadres van WordPress te gebruiken.', 'cjw')
        );
        echo '</div>';

        $this->render_checkbox_setting(
            'enable_csv_export',
            __('Voorbereiden voor CSV-export', 'cjw'),
            $settings['enable_csv_export']
        );

        echo '<hr>';
        echo '<p><strong>' . esc_html__('Privacy', 'cjw') . '</strong></p>';

        $this->render_checkbox_setting(
            'require_privacy_consent',
            __('Vraag akkoord op de privacytekst', 'cjw'),
            $settings['require_privacy_consent']
        );
        echo '<p class="description">' . esc_html__('Zet de privacytekst op de laatste stap, met een vinkje dat de ouder moet aanzetten om te kunnen versturen. Het moment en de tekst worden bij de inschrijving bewaard. De tekst schrijf je bij Zomerkamp → Instellingen → Inhoud.', 'cjw') . '</p>';

        echo '<hr>';
        echo '<p><strong>' . esc_html__('Betalen', 'cjw') . '</strong></p>';

        $this->render_checkbox_setting(
            'require_ideal_payment',
            __('Afronden met een iDEAL-betaling', 'cjw'),
            $settings['require_ideal_payment']
        );
        echo '<p class="description">' . esc_html__('De inschrijving wordt opgeslagen en de ouder gaat daarna meteen door naar iDEAL. Betaalt iemand niet, dan blijft de inschrijving staan als “nog te betalen” en krijgt de ouder de betaallink per mail. Werkt alleen als er een Mollie-sleutel is ingesteld bij Zomerkamp → Beheer.', 'cjw') . '</p>';

        echo '<hr>';
        echo '<p><strong>' . esc_html__('Bevestiging naar de ouder', 'cjw') . '</strong></p>';

        $this->render_checkbox_setting(
            'send_parent_confirmation',
            __('Stuur een bevestiging naar de ouder', 'cjw'),
            $settings['send_parent_confirmation']
        );
        echo '<p class="description">' . esc_html__('Staat uit tot e-mail betrouwbaar verstuurd wordt: een bevestiging die niet aankomt is erger dan geen bevestiging. Het e-mailadres wordt gehaald uit het veld met type E-mail.', 'cjw') . '</p>';

        // The copy itself is no longer here. It lived per form, which meant the
        // one mail a parent actually reads could only be changed by whoever was
        // editing the form -- and there is one form.
        printf(
            '<p class="description">%s</p>',
            wp_kses(
                sprintf(
                    /* translators: %s: link to the Mails screen. */
                    esc_html__('De tekst van deze mail schrijf je op %s.', 'cjw'),
                    sprintf(
                        '<a href="%s">%s</a>',
                        esc_url(admin_url('admin.php?page=' . CJW_Summer_Camp_Mails_Page::PAGE_SLUG)),
                        esc_html__('Zomerkamp → Mails', 'cjw')
                    )
                ),
                [ 'a' => [ 'href' => true ] ]
            )
        );

        echo '</div>';
    }

    public function save(int $post_id): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $nonce = isset($_POST['cjw_wizard_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['cjw_wizard_nonce']))
            : '';

        if ($nonce === '' || ! wp_verify_nonce($nonce, 'cjw_wizard_save_meta')) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['_cjw_wizard_config'])) {
            $json = wp_kses_post(wp_unslash((string) $_POST['_cjw_wizard_config']));
            $decoded = json_decode($json, true);

            if (is_array($decoded) && isset($decoded['steps'])) {
                update_post_meta($post_id, '_cjw_wizard_config', wp_slash($json));
            }
        }

        $raw_settings = isset($_POST['cjw_wizard_settings']) && is_array($_POST['cjw_wizard_settings'])
            ? map_deep(wp_unslash($_POST['cjw_wizard_settings']), 'sanitize_textarea_field')
            : [];

        update_post_meta(
            $post_id,
            CJW_Wizard_Settings::META_KEY,
            $this->settings->sanitize($raw_settings)
        );
    }

    private function render_text_setting(string $key, string $label, string $value, string $description = ''): void
    {
        echo '<p>';
        echo '<label for="cjw-wizard-setting-' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label>';
        echo '<input type="text" class="widefat" id="cjw-wizard-setting-' . esc_attr($key) . '" name="cjw_wizard_settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '">';
        if ($description !== '') {
            echo '<span class="description">' . esc_html($description) . '</span>';
        }
        echo '</p>';
    }

    private function render_textarea_setting(string $key, string $label, string $value, string $description = ''): void
    {
        echo '<p>';
        echo '<label for="cjw-wizard-setting-' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label>';
        echo '<textarea class="widefat" rows="4" id="cjw-wizard-setting-' . esc_attr($key) . '" name="cjw_wizard_settings[' . esc_attr($key) . ']">' . esc_textarea($value) . '</textarea>';
        if ($description !== '') {
            echo '<span class="description">' . esc_html($description) . '</span>';
        }
        echo '</p>';
    }

    private function render_checkbox_setting(string $key, string $label, bool $checked): void
    {
        echo '<p>';
        echo '<label for="cjw-wizard-setting-' . esc_attr($key) . '">';
        echo '<input type="checkbox" id="cjw-wizard-setting-' . esc_attr($key) . '" name="cjw_wizard_settings[' . esc_attr($key) . ']" value="1" ' . checked($checked, true, false) . '>';
        echo ' ' . esc_html($label) . '</label>';
        echo '</p>';
    }
}
