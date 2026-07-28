<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Displays the generated private late-registration link.
 */
class CJW_Late_Registration_Link_Field extends CJW_Field
{
    /**
     * Initializes the generated link field.
     *
     * @param string $key           The token field key.
     * @param string $label         The field label.
     * @param string $optionKey     The top-level option key.
     * @param string $baseUrlKey    The option key containing the registration page URL.
     * @param string $description   Optional help text rendered below the control.
     */
    public function __construct(
        string $key,
        string $label,
        string $optionKey,
        private readonly string $baseUrlKey = 'late_registration_link',
        string $description = ''
    ) {
        parent::__construct($key, $label, $optionKey, $description);
    }

    /**
     * Renders generated late-registration URL and token controls.
     *
     * @return void
     */
    public function render(): void
    {
        $options = get_option($this->optionKey, []);
        $token = CJW_Summer_Camp_Service::generateLateRegistrationToken();

        if (is_array($options) && !empty($options[$this->key])) {
            $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $options[$this->key]) ?: $token;
        }

        $base_url = is_array($options) ? esc_url_raw((string) ($options[$this->baseUrlKey] ?? '')) : '';

        if ($base_url === '') {
            $base_url = home_url('/');
        }

        $generated_link = CJW_Summer_Camp_Service::buildLateRegistrationLink($base_url, $token);

        printf(
            '<input type="hidden" name="%s" value="%s" />',
            esc_attr($this->getName()),
            esc_attr($token)
        );

        echo '<div class="cjw-generated-link-field">';
        printf(
            '<input type="url" class="regular-text cjw-generated-link-field__url" value="%s" readonly data-cjw-copy-source />',
            esc_attr($generated_link)
        );
        echo '<button type="button" class="button" data-cjw-copy-value>' . esc_html__('Kopieer link', 'cjw') . '</button>';
        echo '<p class="description">' . esc_html__('Deze privé-link opent de inschrijving ook wanneer de normale inschrijving gesloten is. Deel hem alleen met mensen die alsnog mogen inschrijven.', 'cjw') . '</p>';
        echo '<label class="cjw-generated-link-field__regenerate">';
        printf(
            '<input type="checkbox" name="%s[late_registration_regenerate]" value="1" />',
            esc_attr($this->optionKey)
        );
        echo ' ' . esc_html__('Maak een nieuwe unieke link bij opslaan', 'cjw') . '</label>';
        echo '</div>';

        $this->renderDescription();
    }
}
