<?php

declare(strict_types=1);

/**
 * Service class for handling Summer Camp data and logic.
 *
 * Provides methods for retrieving settings, calculating prices, and checking registration status.
 */
class CJW_Summer_Camp_Service
{
    /**
     * The top-level WordPress option key for summer camp settings.
     */
    public const string OPTION_KEY = 'cjw_summer_camp_settings';

    /**
     * Query argument used for private late-registration links.
     */
    private const string LATE_REGISTRATION_QUERY_ARG = 'cjw_late_registration';

    /**
     * Default settings used when no value has been configured yet.
     */
    private const array DEFAULTS = [
        'start_date' => '',
        'end_date' => '',
        // Camp basics shown on the website.
        'camp_age_range' => '',
        'camp_location' => '',
        'registration_deadline' => '',
        'registration_active' => false,
        'retention_months' => 12,
        'early_bird_start' => '',
        'early_bird_end' => '',
        'price_week_early' => 0,
        'price_weekend_early' => 0,
        'price_week' => 0,
        'price_weekend' => 0,
        'registration_closed_text' => '',
        'late_registration_link' => '',
        'late_registration_token' => '',
        'intro_text' => '',
        'drive_dates' => [],
        'birthday_mail_enabled' => false,
        'birthday_mail_text' => '',
        'theme_year' => '',
        'theme_name' => '',
        'hero_title' => '',
        'hero_subtitle' => '',
        'hero_image_id' => 0,
        'hero_image_alt' => '',
        'hero_primary_cta_label' => 'Meld je aan',
        'hero_primary_cta_url' => '#inschrijven',
        'hero_secondary_cta_label' => 'Lees meer',
        'hero_secondary_cta_url' => '#meer',
        'theme_primary_color' => '#588C7E',
        'theme_secondary_color' => '#F0AE73',
        'theme_accent_color' => '#ED1C24',
        'hero_text_color' => '#FFFFFF',
        'hero_overlay_opacity' => 25,
        'theme_description' => '',
        'intro_image_id' => 0,
        'card_zomerkamp_page_id' => 0,
        'card_info_page_id' => 0,
        'polaroid_1_image_id' => 0,
        'polaroid_1_caption' => '',
        'polaroid_2_image_id' => 0,
        'polaroid_2_caption' => '',
        'polaroid_3_image_id' => 0,
        'polaroid_3_caption' => '',
        'polaroid_4_image_id' => 0,
        'polaroid_4_caption' => '',
        'polaroid_5_image_id' => 0,
        'polaroid_5_caption' => '',
        'contact_email' => '',
        'social_facebook' => '',
        'social_instagram' => '',
        // Website copy (front page, footer and call-to-action band).
        'home_intro_title' => '',
        'home_intro_text' => '',
        'card_zomerkamp_title' => '',
        'card_zomerkamp_text' => '',
        'card_info_title' => '',
        'card_info_text' => '',
        'card_signup_title' => '',
        'card_signup_text' => '',
        'photos_title' => '',
        'photos_lead' => '',
        'sponsors_title' => '',
        'sponsors_lead' => '',
        'sponsors_cta_text' => '',
        'footer_about' => '',
        'footer_org' => '',
        'support_contact' => '',
        'cta_title' => '',
        'cta_text' => '',
    ];

    /**
     * Number of polaroid slots on the website's photo wall.
     */
    public const int POLAROID_SLOTS = 5;

    /**
     * Returns the complete default settings array.
     *
     * @param bool $generateLateRegistrationToken Whether to generate a new late-registration token.
     * @return array<string, mixed>
     */
    public static function defaults(bool $generateLateRegistrationToken = false): array
    {
        $defaults = self::DEFAULTS;

        if ($generateLateRegistrationToken) {
            $defaults['late_registration_token'] = self::generateLateRegistrationToken();
        }

        return $defaults;
    }

    /**
     * Internal cache for the settings array.
     *
     * @var array
     */
    /**
     * Loads the camp settings once, so every reader sees the same year.
     *
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * Initializes the service and loads current settings.
     */
    /**
     * The shared instance, held here rather than in cjw_summer_camp() so it can
     * be thrown away when the settings change.
     *
     * A static inside that function could not be: settings written during a
     * request -- by the settings form, by starting a new camp year, by
     * restoring an older version -- stayed invisible for the rest of it, and
     * whatever ran next answered from the values that had just been replaced.
     */
    private static ?self $instance = null;

    public function __construct()
    {
        $this->settings = $this->load();
    }

    /**
     * The shared summer camp service.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Throws the shared instance away, so the next caller reads the database.
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$instance = null;
    }

    /**
     * Keeps the shared instance honest about the option behind it.
     *
     * @return void
     */
    public static function watchSettings(): void
    {
        foreach ([
            'add_option_' . self::OPTION_KEY,
            'update_option_' . self::OPTION_KEY,
            'delete_option_' . self::OPTION_KEY,
        ] as $hook) {
            add_action($hook, [ self::class, 'flush' ], 10, 0);
        }
    }

    /**
     * Loads settings from the database and merges them with defaults.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        return wp_parse_args(
            get_option(self::OPTION_KEY, []),
            self::DEFAULTS
        );
    }

    /**
     * Helper to convert a string date to a DateTimeImmutable object.
     *
     * @param string|null $value The date string.
     *
     * @return DateTimeImmutable|null
     */
    private function toDate(?string $value): ?DateTimeImmutable
    {
        if (empty($value)) {
            return null;
        }

        foreach (['Y-m-d', 'd-m-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);

            if ($date instanceof DateTimeImmutable) {
                return $date->setTime(0, 0);
            }
        }

        try {
            return new DateTimeImmutable($value)->setTime(0, 0);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Returns the start date of the summer camp.
     *
     * @return DateTimeImmutable|null
     */
    public function getStartDate(): ?DateTimeImmutable
    {
        return $this->toDate($this->settings['start_date']);
    }

    /**
     * Returns the end date of the summer camp.
     *
     * @return DateTimeImmutable|null
     */
    public function getEndDate(): ?DateTimeImmutable
    {
        return $this->toDate($this->settings['end_date']);
    }

    /**
     * How many months after the camp ends a registration is kept.
     *
     * Zero means keep indefinitely. Registrations hold identifying data about
     * children, so the AVG expects a defined period rather than "forever"; the
     * default of twelve months carries the camp to just before the next season,
     * which is the last point the previous year's list is genuinely useful.
     *
     * @return int
     */
    public function getRetentionMonths(): int
    {
        return self::sanitizeRetentionMonths($this->settings['retention_months'] ?? self::DEFAULTS['retention_months']);
    }

    /**
     * Clamps the retention period to something sane.
     *
     * Capped at ten years so a stray keystroke cannot turn "delete eventually"
     * into "never" without the organiser choosing that explicitly.
     *
     * @param mixed $value Raw setting value.
     *
     * @return int
     */
    private static function sanitizeRetentionMonths(mixed $value): int
    {
        return max(0, min(120, (int) $value));
    }

    /**
     * Returns the registration deadline.
     *
     * @return DateTimeImmutable|null
     */
    public function getRegistrationDeadline(): ?DateTimeImmutable
    {
        return $this->toDate($this->settings['registration_deadline']);
    }

    /**
     * Checks if the registration is currently open based on status and deadline.
     *
     * @return bool
     */
    public function isRegistrationOpen(): bool
    {
        if (empty($this->settings['registration_active'])) {
            return false;
        }

        $deadline = $this->toDate($this->settings['registration_deadline']);

        return $deadline === null || new DateTimeImmutable()->setTime(0, 0) <= $deadline;
    }

    /**
     * Retrieves the text shown when registration is closed.
     *
     * @return string
     */
    public function getRegistrationClosedText(): string
    {
        return $this->localizedHtmlSetting('registration_closed_text');
    }

    /**
     * Retrieves the generated private link for late registration requests.
     *
     * @param string|null $baseUrl Optional base URL of the page that contains the registration wizard.
     *
     * @return string
     */
    public function getLateRegistrationLink(?string $baseUrl = null): string
    {
        $token = $this->getLateRegistrationToken();

        if ($token === '') {
            return '';
        }

        $base_url = trim($baseUrl ?? (string) $this->settings['late_registration_link']);

        if ($base_url === '') {
            $base_url = home_url('/');
        }

        return esc_url_raw(self::buildLateRegistrationLink($base_url, $token));
    }

    /**
     * Retrieves the private late-registration token.
     *
     * @return string
     */
    public function getLateRegistrationToken(): string
    {
        return self::sanitizeLateRegistrationToken($this->settings['late_registration_token']);
    }

    /**
     * Checks whether a submitted late-registration token matches the stored token.
     *
     * @param string|null $token Submitted token.
     *
     * @return bool
     */
    public function isLateRegistrationTokenValid(?string $token): bool
    {
        $stored_token = $this->getLateRegistrationToken();
        $submitted_token = self::sanitizeLateRegistrationToken($token);

        return $stored_token !== '' && $submitted_token !== '' && hash_equals($stored_token, $submitted_token);
    }

    /**
     * Builds a private late-registration URL.
     *
     * @param string $baseUrl URL of the page that contains the registration wizard.
     * @param string $token Late-registration token.
     *
     * @return string
     */
    public static function buildLateRegistrationLink(string $baseUrl, string $token): string
    {
        if ($baseUrl === '' || $token === '') {
            return '';
        }

        return add_query_arg(self::LATE_REGISTRATION_QUERY_ARG, $token, $baseUrl);
    }

    /**
     * Returns the late-registration query argument name.
     *
     * @return string
     */
    public static function getLateRegistrationQueryArg(): string
    {
        return self::LATE_REGISTRATION_QUERY_ARG;
    }

    /**
     * Generates a new private late-registration token.
     *
     * @return string
     */
    public static function generateLateRegistrationToken(): string
    {
        if (function_exists('wp_generate_password')) {
            return wp_generate_password(32, false);
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (Exception) {
            return md5((string) microtime(true));
        }
    }

    /**
     * Retrieves the price for a specific visit type, considering early_bird discounts.
     *
     * @param CampType $type The type of visit ('week' or 'weekend').
     *
     * @return int
     */
    public function getPrice(CampType $type): int
    {
        $early = $this->isEarlyBird();

        return match ($type) {
            CampType::WEEKEND => $early
                ? (int) $this->settings['price_weekend_early']
                : (int) $this->settings['price_weekend'],
            CampType::WEEK => $early
                ? (int) $this->settings['price_week_early']
                : (int) $this->settings['price_week'],
        };
    }

    /**
     * Checks if the current date falls within the early_bird discount period.
     *
     * @return bool
     */
    public function isEarlyBird(): bool
    {
        if (empty($this->settings['early_bird_start']) || empty($this->settings['early_bird_end'])) {
            return false;
        }

        $start = $this->toDate($this->settings['early_bird_start']);
        $end = $this->toDate($this->settings['early_bird_end']);

        if ($start === null || $end === null) {
            return false;
        }

        $now = new DateTimeImmutable()->setTime(0, 0);

        return $now >= $start && $now <= $end;
    }

    /**
     * Retrieves the introduction text for the summer camp.
     *
     * @return string
     */
    public function getIntroText(): string
    {
        return $this->localizedHtmlSetting('intro_text');
    }

    /**
     * Returns a human-readable camp date range.
     *
     * @return string
     */
    public function getDateRangeText(): string
    {
        return $this->formatDateRange();
    }

    /**
     * Returns the camp age range shown on the website (e.g. "6 t/m 17 jaar").
     *
     * @return string
     */
    public function getCampAgeRange(): string
    {
        return $this->localizedTextSetting('camp_age_range');
    }

    /**
     * Returns the camp location shown on the website (e.g. "Landgoed Brockhausen, Stokkum").
     *
     * @return string
     */
    public function getCampLocation(): string
    {
        return $this->localizedTextSetting('camp_location');
    }

    /**
     * Returns the public theme/campaign name for the current camp year.
     *
     * @return string
     */
    public function getThemeName(): string
    {
        return $this->localizedTextSetting('theme_name');
    }

    /**
     * Returns the configured theme year.
     *
     * @return string
     */
    public function getThemeYear(): string
    {
        return $this->localizedTextSetting('theme_year');
    }

    /**
     * Returns all values needed by the theme hero.
     *
     * @return array<string, mixed>
     */
    public function getHeroConfig(): array
    {
        return cjw_localize_value([
            'title' => $this->getHeroTitle(),
            'subtitle' => $this->getHeroSubtitle(),
            'image_id' => $this->getHeroImageId(),
            'image_url' => $this->getHeroImageUrl(),
            'image_alt' => $this->getHeroImageAlt(),
            'primary_cta' => $this->getPrimaryHeroCta(),
            'secondary_cta' => $this->getSecondaryHeroCta(),
            'colors' => $this->getThemeColors(),
            'overlay_opacity' => $this->getHeroOverlayOpacity(),
            'theme_name' => $this->getThemeName(),
            'theme_year' => $this->getThemeYear(),
        ]);
    }

    /**
     * Returns the hero title with a theme-name fallback.
     *
     * @return string
     */
    public function getHeroTitle(): string
    {
        $title = trim($this->localizedTextSetting('hero_title'));

        if ($title !== '') {
            return $title;
        }

        $theme_name = $this->getThemeName();

        return $theme_name !== '' ? $theme_name : __('Zomerkamp Avonturen', 'cjw');
    }

    /**
     * Returns the hero subtitle, falling back to a camp date range when possible.
     *
     * @return string
     */
    public function getHeroSubtitle(): string
    {
        $subtitle = trim($this->localizedTextSetting('hero_subtitle'));

        if ($subtitle !== '') {
            return $subtitle;
        }

        $date_range = $this->formatDateRange();

        if ($date_range !== '') {
            return sprintf(
                /* translators: %s: date range for the summer camp. */
                __('%s - een onvergetelijk avontuur wacht je!', 'cjw'),
                $date_range
            );
        }

        return __('Een onvergetelijk avontuur wacht je!', 'cjw');
    }

    /**
     * Returns the configured hero image attachment ID.
     *
     * @return int
     */
    public function getHeroImageId(): int
    {
        return absint($this->settings['hero_image_id']);
    }

    /**
     * Returns the configured hero image URL.
     *
     * @param string $size WordPress image size.
     *
     * @return string
     */
    public function getHeroImageUrl(string $size = 'full'): string
    {
        $image_id = $this->getHeroImageId();

        if ($image_id <= 0) {
            return '';
        }

        $url = wp_get_attachment_image_url($image_id, $size);

        return is_string($url) ? $url : '';
    }

    /**
     * Returns the hero image alternative text.
     *
     * @return string
     */
    public function getHeroImageAlt(): string
    {
        $configured_alt = trim($this->localizedTextSetting('hero_image_alt'));

        if ($configured_alt !== '') {
            return $configured_alt;
        }

        $image_id = $this->getHeroImageId();

        if ($image_id > 0) {
            $attachment_alt = trim((string) get_post_meta($image_id, '_wp_attachment_image_alt', true));

            if ($attachment_alt !== '') {
                return sanitize_text_field($attachment_alt);
            }
        }

        return $this->getHeroTitle();
    }

    /**
     * Returns the primary hero call-to-action.
     *
     * @return array<string, mixed>
     */
    public function getPrimaryHeroCta(): array
    {
        return [
            'label' => $this->textSettingWithDefault('hero_primary_cta_label'),
            'url' => $this->urlSettingWithDefault('hero_primary_cta_url'),
        ];
    }

    /**
     * Returns the secondary hero call-to-action.
     *
     * @return array<string, mixed>
     */
    public function getSecondaryHeroCta(): array
    {
        return [
            'label' => $this->textSettingWithDefault('hero_secondary_cta_label'),
            'url' => $this->urlSettingWithDefault('hero_secondary_cta_url'),
        ];
    }

    /**
     * Returns the configured public theme colors.
     *
     * @return array<string, mixed>
     */
    public function getThemeColors(): array
    {
        return [
            'primary' => $this->colorSettingWithDefault('theme_primary_color'),
            'secondary' => $this->colorSettingWithDefault('theme_secondary_color'),
            'accent' => $this->colorSettingWithDefault('theme_accent_color'),
            'hero_text' => $this->colorSettingWithDefault('hero_text_color'),
        ];
    }

    /**
     * Returns the configured image overlay opacity as a percentage.
     *
     * @return int
     */
    public function getHeroOverlayOpacity(): int
    {
        return $this->settings['hero_overlay_opacity']
                |> absint(...)
                |> (fn($x) => max(0, $x))
                |> (fn($x) => min(90, $x));
    }

    /**
     * Returns the public description of the yearly theme (website teaser).
     *
     * @return string
     */
    public function getThemeDescription(): string
    {
        return $this->localizedHtmlSetting('theme_description');
    }

    /**
     * Returns the attachment ID of the website's introduction photo.
     *
     * @return int
     */
    public function getIntroImageId(): int
    {
        return absint($this->settings['intro_image_id']);
    }

    /**
     * Returns the page ID behind the "Zomerkamp" card on the front page.
     *
     * @return int
     */
    public function getCardZomerkampPageId(): int
    {
        return absint($this->settings['card_zomerkamp_page_id']);
    }

    /**
     * Returns the page ID behind the "Praktische info" card on the front page.
     *
     * @return int
     */
    public function getCardInfoPageId(): int
    {
        return absint($this->settings['card_info_page_id']);
    }

    /**
     * Returns the configured photo-wall polaroids (slots with an image).
     *
     * @return array<int, array{image_id: int, caption: string}>
     */
    public function getPolaroids(): array
    {
        $polaroids = [];

        for ($slot = 1; $slot <= self::POLAROID_SLOTS; $slot++) {
            $image_id = absint($this->settings["polaroid_{$slot}_image_id"] ?? 0);

            if ($image_id <= 0) {
                continue;
            }

            $caption = cjw_localize_value($this->settings["polaroid_{$slot}_caption"] ?? '');

            $polaroids[$slot] = [
                'image_id' => $image_id,
                'caption' => sanitize_text_field(is_scalar($caption) ? (string) $caption : ''),
            ];
        }

        return $polaroids;
    }

    /**
     * Returns the public contact e-mail address.
     *
     * @return string
     */
    public function getContactEmail(): string
    {
        return sanitize_email((string) $this->settings['contact_email']);
    }

    /**
     * Returns the configured social profile URLs.
     *
     * @return array{facebook: string, instagram: string}
     */
    public function getSocialLinks(): array
    {
        return [
            'facebook' => esc_url_raw((string) $this->settings['social_facebook']),
            'instagram' => esc_url_raw((string) $this->settings['social_instagram']),
        ];
    }

    /**
     * Returns the title of the front-page introduction section.
     *
     * @return string
     */
    public function getHomeIntroTitle(): string
    {
        return $this->localizedTextSetting('home_intro_title');
    }

    /**
     * Returns the text of the front-page introduction section.
     *
     * @return string
     */
    public function getHomeIntroText(): string
    {
        return $this->localizedHtmlSetting('home_intro_text');
    }

    /**
     * Returns the title of the "Zomerkamp" card on the front page.
     *
     * @return string
     */
    public function getCardZomerkampTitle(): string
    {
        return $this->localizedTextSetting('card_zomerkamp_title');
    }

    /**
     * Returns the text of the "Zomerkamp" card on the front page.
     *
     * @return string
     */
    public function getCardZomerkampText(): string
    {
        return $this->localizedTextSetting('card_zomerkamp_text');
    }

    /**
     * Returns the title of the "Praktische info" card on the front page.
     *
     * @return string
     */
    public function getCardInfoTitle(): string
    {
        return $this->localizedTextSetting('card_info_title');
    }

    /**
     * Returns the text of the "Praktische info" card on the front page.
     *
     * @return string
     */
    public function getCardInfoText(): string
    {
        return $this->localizedTextSetting('card_info_text');
    }

    /**
     * Returns the title of the sign-up card on the front page.
     *
     * @return string
     */
    public function getCardSignupTitle(): string
    {
        return $this->localizedTextSetting('card_signup_title');
    }

    /**
     * Returns the text of the sign-up card on the front page.
     *
     * May contain the literal token {jaar}, replaced by the theme with the camp year.
     *
     * @return string
     */
    public function getCardSignupText(): string
    {
        return $this->localizedTextSetting('card_signup_text');
    }

    /**
     * Returns the title of the photo-wall section.
     *
     * @return string
     */
    public function getPhotosTitle(): string
    {
        return $this->localizedTextSetting('photos_title');
    }

    /**
     * Returns the lead text of the photo-wall section.
     *
     * @return string
     */
    public function getPhotosLead(): string
    {
        return $this->localizedTextSetting('photos_lead');
    }

    /**
     * Returns the title of the sponsors section.
     *
     * @return string
     */
    public function getSponsorsTitle(): string
    {
        return $this->localizedTextSetting('sponsors_title');
    }

    /**
     * Returns the lead text of the sponsors section.
     *
     * @return string
     */
    public function getSponsorsLead(): string
    {
        return $this->localizedTextSetting('sponsors_lead');
    }

    /**
     * Returns the call-to-action text of the sponsors section.
     *
     * @return string
     */
    public function getSponsorsCtaText(): string
    {
        return $this->localizedTextSetting('sponsors_cta_text');
    }

    /**
     * Returns the "about" text in the website footer.
     *
     * @return string
     */
    public function getFooterAbout(): string
    {
        return $this->localizedTextSetting('footer_about');
    }

    /**
     * Returns the organisation text in the website footer.
     *
     * @return string
     */
    public function getFooterOrg(): string
    {
        return $this->localizedTextSetting('footer_org');
    }

    /**
     * Who to call when the website itself is broken.
     *
     * Never shown on the public site. It exists because the last line of any
     * handover document is "and if none of this helps, ring...", and a manual
     * that ends without a name is a manual that ends nowhere.
     *
     * @return string
     */
    public function getSupportContact(): string
    {
        return trim((string) $this->settings['support_contact']);
    }

    /**
     * Returns the title of the call-to-action band.
     *
     * @return string
     */
    public function getCtaTitle(): string
    {
        return $this->localizedTextSetting('cta_title');
    }

    /**
     * Returns the text of the call-to-action band.
     *
     * @return string
     */
    public function getCtaText(): string
    {
        return $this->localizedTextSetting('cta_text');
    }

    /**
     * Retrieves the list of drive dates (ritten).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDriveDates(): array
    {
        return array_values(
            array_filter(
                is_array($this->settings['drive_dates']) ? $this->settings['drive_dates'] : [],
                static fn($row) => is_array($row) && (!empty($row['date']) || !empty($row['label']))
            )
        );
    }

    /**
     * Sanitizes raw input data for saving to the WordPress settings.
     *
     * @param array $input The raw input array.
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed> The sanitized array.
     */
    public static function sanitize(array $input): array
    {
        return [
            'start_date' => sanitize_text_field($input['start_date'] ?? ''),
            'end_date' => sanitize_text_field($input['end_date'] ?? ''),
            'camp_age_range' => sanitize_text_field($input['camp_age_range'] ?? ''),
            'camp_location' => sanitize_text_field($input['camp_location'] ?? ''),
            'registration_deadline' => sanitize_text_field($input['registration_deadline'] ?? ''),
            'registration_active' => !empty($input['registration_active']),
            'retention_months' => self::sanitizeRetentionMonths($input['retention_months'] ?? self::DEFAULTS['retention_months']),
            'early_bird_start' => sanitize_text_field($input['early_bird_start'] ?? ''),
            'early_bird_end' => sanitize_text_field($input['early_bird_end'] ?? ''),
            'price_week_early' => (int) ($input['price_week_early'] ?? 0),
            'price_weekend_early' => (int) ($input['price_weekend_early'] ?? 0),
            'price_week' => (int) ($input['price_week'] ?? 0),
            'price_weekend' => (int) ($input['price_weekend'] ?? 0),
            'registration_closed_text' => wp_kses_post($input['registration_closed_text'] ?? ''),
            'late_registration_link' => esc_url_raw($input['late_registration_link'] ?? ''),
            'late_registration_token' => self::sanitizeLateRegistrationTokenForSave($input),
            'intro_text' => wp_kses_post($input['intro_text'] ?? ''),
            'drive_dates' => self::sanitizeDriveDates($input['drive_dates'] ?? []),
            'birthday_mail_enabled' => !empty($input['birthday_mail_enabled']),
            'birthday_mail_text' => wp_kses_post($input['birthday_mail_text'] ?? ''),
            'theme_year' => self::sanitizeYear($input['theme_year'] ?? ''),
            'theme_name' => sanitize_text_field($input['theme_name'] ?? ''),
            'hero_title' => sanitize_text_field($input['hero_title'] ?? ''),
            'hero_subtitle' => sanitize_text_field($input['hero_subtitle'] ?? ''),
            'hero_image_id' => absint($input['hero_image_id'] ?? 0),
            'hero_image_alt' => sanitize_text_field($input['hero_image_alt'] ?? ''),
            'hero_primary_cta_label' => sanitize_text_field($input['hero_primary_cta_label'] ?? ''),
            'hero_primary_cta_url' => esc_url_raw($input['hero_primary_cta_url'] ?? ''),
            'hero_secondary_cta_label' => sanitize_text_field($input['hero_secondary_cta_label'] ?? ''),
            'hero_secondary_cta_url' => esc_url_raw($input['hero_secondary_cta_url'] ?? ''),
            'theme_primary_color' => self::sanitizeColor($input['theme_primary_color'] ?? '', self::DEFAULTS['theme_primary_color']),
            'theme_secondary_color' => self::sanitizeColor($input['theme_secondary_color'] ?? '', self::DEFAULTS['theme_secondary_color']),
            'theme_accent_color' => self::sanitizeColor($input['theme_accent_color'] ?? '', self::DEFAULTS['theme_accent_color']),
            'hero_text_color' => self::sanitizeColor($input['hero_text_color'] ?? '', self::DEFAULTS['hero_text_color']),
            'hero_overlay_opacity' => self::sanitizeOverlayOpacity($input['hero_overlay_opacity'] ?? self::DEFAULTS['hero_overlay_opacity']),
            'theme_description' => wp_kses_post($input['theme_description'] ?? ''),
            'intro_image_id' => absint($input['intro_image_id'] ?? 0),
            'card_zomerkamp_page_id' => absint($input['card_zomerkamp_page_id'] ?? 0),
            'card_info_page_id' => absint($input['card_info_page_id'] ?? 0),
            ...self::sanitizePolaroids($input),
            'contact_email' => sanitize_email($input['contact_email'] ?? ''),
            'social_facebook' => esc_url_raw($input['social_facebook'] ?? ''),
            'social_instagram' => esc_url_raw($input['social_instagram'] ?? ''),
            'home_intro_title' => sanitize_text_field($input['home_intro_title'] ?? ''),
            'home_intro_text' => wp_kses_post($input['home_intro_text'] ?? ''),
            'card_zomerkamp_title' => sanitize_text_field($input['card_zomerkamp_title'] ?? ''),
            'card_zomerkamp_text' => sanitize_text_field($input['card_zomerkamp_text'] ?? ''),
            'card_info_title' => sanitize_text_field($input['card_info_title'] ?? ''),
            'card_info_text' => sanitize_text_field($input['card_info_text'] ?? ''),
            'card_signup_title' => sanitize_text_field($input['card_signup_title'] ?? ''),
            'card_signup_text' => sanitize_text_field($input['card_signup_text'] ?? ''),
            'photos_title' => sanitize_text_field($input['photos_title'] ?? ''),
            'photos_lead' => sanitize_text_field($input['photos_lead'] ?? ''),
            'sponsors_title' => sanitize_text_field($input['sponsors_title'] ?? ''),
            'sponsors_lead' => sanitize_text_field($input['sponsors_lead'] ?? ''),
            'sponsors_cta_text' => sanitize_text_field($input['sponsors_cta_text'] ?? ''),
            'footer_about' => sanitize_text_field($input['footer_about'] ?? ''),
            'footer_org' => sanitize_text_field($input['footer_org'] ?? ''),
            'support_contact' => sanitize_textarea_field($input['support_contact'] ?? ''),
            'cta_title' => sanitize_text_field($input['cta_title'] ?? ''),
            'cta_text' => sanitize_text_field($input['cta_text'] ?? ''),
        ];
    }

    /**
     * Sanitizes the fixed photo-wall polaroid slots.
     *
     * @param array $input Raw settings input.
     * @param array<string, mixed> $input
     *
     * @return array<string, int|string>
     */
    private static function sanitizePolaroids(array $input): array
    {
        $sanitized = [];

        for ($slot = 1; $slot <= self::POLAROID_SLOTS; $slot++) {
            $sanitized["polaroid_{$slot}_image_id"] = absint($input["polaroid_{$slot}_image_id"] ?? 0);
            $sanitized["polaroid_{$slot}_caption"] = sanitize_text_field($input["polaroid_{$slot}_caption"] ?? '');
        }

        return $sanitized;
    }

    /**
     * Formats the configured start/end dates for public copy.
     *
     * @return string
     */
    private function formatDateRange(): string
    {
        $start = $this->getStartDate();
        $end = $this->getEndDate();

        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            return '';
        }

        $start_format = $start->format('Y-m') === $end->format('Y-m') ? 'j' : 'j F';

        return sprintf(
            '%s tot %s',
            cjw_localize_value($start, [
                'type' => 'date',
                'format' => $start_format,
            ]),
            cjw_localize_value($end, [
                'type' => 'date',
                'format' => 'j F',
            ])
        );
    }

    /**
     * Returns a text setting or its default when the saved value is blank.
     *
     * @param string $key Setting key.
     *
     * @return string
     */
    private function textSettingWithDefault(string $key): string
    {
        $value = trim($this->localizedTextSetting($key));

        return $value !== '' ? $value : (string) self::DEFAULTS[$key];
    }

    /**
     * Returns a URL setting or its default when the saved value is blank.
     *
     * @param string $key Setting key.
     *
     * @return string
     */
    private function urlSettingWithDefault(string $key): string
    {
        $localized = cjw_localize_value($this->settings[$key]);
        $value = esc_url_raw(is_scalar($localized) ? (string) $localized : '');

        return $value !== '' ? $value : (string) self::DEFAULTS[$key];
    }

    /**
     * Returns a localized plain-text setting.
     *
     * @param string $key Setting key.
     *
     * @return string
     */
    private function localizedTextSetting(string $key): string
    {
        $localized = cjw_localize_value($this->settings[$key] ?? '');

        return sanitize_text_field(is_scalar($localized) ? (string) $localized : '');
    }

    /**
     * Returns a localized HTML setting.
     *
     * @param string $key Setting key.
     *
     * @return string
     */
    private function localizedHtmlSetting(string $key): string
    {
        $localized = cjw_localize_value($this->settings[$key] ?? '');

        return wp_kses_post(is_scalar($localized) ? (string) $localized : '');
    }

    /**
     * Returns a color setting or its default when the saved value is invalid.
     *
     * @param string $key Setting key.
     *
     * @return string
     */
    private function colorSettingWithDefault(string $key): string
    {
        return self::sanitizeColor($this->settings[$key], self::DEFAULTS[$key]);
    }

    /**
     * Sanitizes the configured theme year.
     *
     * @param mixed $value Raw year value.
     *
     * @return string
     */
    private static function sanitizeYear(mixed $value): string
    {
        $year = absint($value);

        return $year > 0 ? (string) $year : '';
    }

    /**
     * Sanitizes a hex color with a fallback.
     *
     * @param mixed $value Raw color value.
     * @param string $default Default hex color.
     *
     * @return string
     */
    private static function sanitizeColor(mixed $value, string $default): string
    {
        $color = sanitize_hex_color((string) $value);

        return is_string($color) ? $color : $default;
    }

    /**
     * Sanitizes the hero overlay opacity percentage.
     *
     * @param mixed $value Raw opacity value.
     *
     * @return int
     */
    private static function sanitizeOverlayOpacity(mixed $value): int
    {
        return $value
                |> absint(...)
                |> (fn($x) => max(0, $x))
                |> (fn($x) => min(90, $x));
    }

    /**
     * Sanitizes late-registration token input and regenerates it when requested.
     *
     * @param array $input Raw settings input.
     * @param array<string, mixed> $input
     *
     * @return string
     */
    private static function sanitizeLateRegistrationTokenForSave(array $input): string
    {
        $token = self::sanitizeLateRegistrationToken($input['late_registration_token'] ?? '');

        if ($token === '' || !empty($input['late_registration_regenerate'])) {
            return self::generateLateRegistrationToken();
        }

        return $token;
    }

    /**
     * Sanitizes private token values without changing valid existing tokens.
     *
     * @param mixed $token Raw token.
     *
     * @return string
     */
    private static function sanitizeLateRegistrationToken(mixed $token): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', (string) $token) ?? '';
    }

    /**
     * Sanitizes repeater rows for drive dates and removes empty entries.
     *
     * @param mixed $rows Raw repeater data.
     *
     * @return array<int, array<string, string>>
     */
    private static function sanitizeDriveDates(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $sanitized = array_map(
            static function ($row): array {
                if (!is_array($row)) {
                    return [
                        'date' => '',
                        'label' => '',
                    ];
                }

                return [
                    'date' => sanitize_text_field($row['date'] ?? ''),
                    'label' => sanitize_text_field($row['label'] ?? ''),
                ];
            },
            $rows
        );

        return array_values(
            array_filter(
                $sanitized,
                static fn(array $row): bool => $row['date'] !== '' || $row['label'] !== ''
            )
        );
    }
}
