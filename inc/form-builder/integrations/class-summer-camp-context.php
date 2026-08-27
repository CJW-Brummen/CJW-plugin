<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_Summer_Camp_Context
{
    private ?object $camp;
    /**
     * Holds the form configuration the camp tokens are applied to.
     *
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * Holds the form configuration the camp tokens are applied to.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->settings = wp_parse_args(
            $config['settings'] ?? [],
            CJW_Wizard_Defaults::settings()
        );
        $this->camp = ! empty($this->settings['use_summer_camp_settings']) && function_exists('cjw_summer_camp')
            ? cjw_summer_camp()
            : null;
    }

    public function isEnabled(): bool
    {
        return ! empty($this->settings['use_summer_camp_settings']) && $this->camp !== null;
    }

    public function shouldBlockRegistration(): bool
    {
        return $this->isEnabled()
            && method_exists($this->camp, 'isRegistrationOpen')
            && ! $this->camp->isRegistrationOpen()
            && ! $this->hasValidLateRegistrationToken();
    }

    public function shouldShowIntro(): bool
    {
        if (! $this->isEnabled() || empty($this->settings['show_summer_camp_intro']) || ! method_exists($this->camp, 'getIntroText')) {
            return false;
        }

        return trim(wp_strip_all_tags((string) $this->camp->getIntroText())) !== '';
    }

    /**
     * Replaces every camp token in a configuration with this year's value.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function applyTokens(array $config): array
    {
        $config = cjw_localize_value($config);

        if (! $this->isEnabled()) {
            return $config;
        }

        return $this->replaceTokens($config, $this->tokens());
    }

    public function renderIntro(): string
    {
        if (! $this->shouldShowIntro()) {
            return '';
        }

        return '<div class="cjw-wizard-camp-intro">' . wp_kses_post((string) $this->camp->getIntroText()) . '</div>';
    }

    public function renderClosedNotice(): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        $text = method_exists($this->camp, 'getRegistrationClosedText')
            ? trim((string) $this->camp->getRegistrationClosedText())
            : '';
        if ($text === '') {
            $text = '<p>' . esc_html__('De inschrijving voor het zomerkamp is gesloten.', 'cjw') . '</p>';
        }

        $html = '<div class="cjw-wizard-container">';
        $html .= '<div class="cjw-wizard-camp-closed">';
        $html .= '<h2>' . esc_html__('Inschrijven is gesloten', 'cjw') . '</h2>';
        $html .= '<div class="cjw-wizard-camp-closed__content">' . wp_kses_post($text) . '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Token name => the value it stands for this camp year.
     *
     * @return array<string, mixed>
     */
    private function tokens(): array
    {
        $start = method_exists($this->camp, 'getStartDate') ? $this->camp->getStartDate() : null;
        $end = method_exists($this->camp, 'getEndDate') ? $this->camp->getEndDate() : null;
        $hero = method_exists($this->camp, 'getHeroConfig') ? $this->camp->getHeroConfig() : [];

        return [
            '{camp_theme_name}' => method_exists($this->camp, 'getThemeName') ? $this->camp->getThemeName() : '',
            '{camp_theme_year}' => method_exists($this->camp, 'getThemeYear') ? $this->camp->getThemeYear() : '',
            '{camp_hero_title}' => (string) ($hero['title'] ?? ''),
            '{camp_hero_subtitle}' => (string) ($hero['subtitle'] ?? ''),
            '{camp_start_date}' => $this->formatDate($start),
            '{camp_start_date_iso}' => $this->formatIsoDate($start),
            '{camp_end_date}' => $this->formatDate($end),
            '{camp_end_date_iso}' => $this->formatIsoDate($end),
            '{camp_date_range}' => method_exists($this->camp, 'getDateRangeText') ? $this->camp->getDateRangeText() : '',
            '{camp_price_week}' => $this->getPriceToken('WEEK'),
            '{camp_price_week_raw}' => $this->getRawPriceToken('WEEK'),
            '{camp_price_weekend}' => $this->getPriceToken('WEEKEND'),
            '{camp_price_weekend_raw}' => $this->getRawPriceToken('WEEKEND'),
            '{camp_intro_text}' => method_exists($this->camp, 'getIntroText') ? $this->camp->getIntroText() : '',
        ];
    }

    private function hasValidLateRegistrationToken(): bool
    {
        if (! method_exists($this->camp, 'isLateRegistrationTokenValid')) {
            return false;
        }

        return $this->camp->isLateRegistrationTokenValid($this->requestLateRegistrationToken());
    }

    private function requestLateRegistrationToken(): string
    {
        $query_arg = method_exists($this->camp, 'getLateRegistrationQueryArg')
            ? $this->camp::getLateRegistrationQueryArg()
            : 'cjw_late_registration';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public late-registration links are authenticated by the unique token itself.
        if (! isset($_GET[ $query_arg ])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public late-registration links are authenticated by the unique token itself.
        return sanitize_text_field(wp_unslash((string) $_GET[ $query_arg ]));
    }

    /**
     * Replaces tokens in a value, walking into arrays.
     *
     * @param array<string, mixed> $tokens
     */
    private function replaceTokens(mixed $value, array $tokens): mixed
    {
        if (is_string($value)) {
            return strtr($value, $tokens);
        }

        if (! is_array($value)) {
            return $value;
        }

        $replaced = [];

        foreach ($value as $key => $item) {
            $replaced[ $key ] = $this->replaceTokens($item, $tokens);
        }

        return $replaced;
    }

    private function getPriceToken(string $case): string
    {
        $raw = $this->getRawPriceToken($case);

        if ($raw === '') {
            return '';
        }

        return cjw_localize_value($raw, ['type' => 'currency']);
    }

    private function getRawPriceToken(string $case): string
    {
        if (! method_exists($this->camp, 'getPrice') || ! enum_exists('CampType')) {
            return '';
        }

        $camp_type = constant('CampType::' . $case);

        return (string) $this->camp->getPrice($camp_type);
    }

    private function formatDate(mixed $date): string
    {
        if (! $date instanceof DateTimeImmutable) {
            return '';
        }

        return cjw_localize_value($date, [
            'type' => 'date',
            'format' => 'j F Y',
        ]);
    }

    private function formatIsoDate(mixed $date): string
    {
        if (! $date instanceof DateTimeImmutable) {
            return '';
        }

        return $date->format('Y-m-d');
    }
}
