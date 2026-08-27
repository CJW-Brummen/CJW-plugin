<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds a "Zomerkamp" dashboard widget with camp status, attention points and quick links.
 */
final class CJW_Summer_Camp_Dashboard_Widget
{
    /**
     * Dashboard widget identifier.
     */
    private const string WIDGET_ID = 'cjw_summer_camp_dashboard_widget';

    /**
     * Registers the dashboard hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('wp_dashboard_setup', [new self(), 'register']);
    }

    /**
     * Registers the dashboard widget for users who manage the site content.
     *
     * @return void
     */
    public function register(): void
    {
        if (!CJW_Roles::currentUserCan() || !function_exists('cjw_summer_camp')) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __('Zomerkamp', 'cjw'),
            [$this, 'render']
        );
    }

    /**
     * Renders the widget content.
     *
     * @return void
     */
    public function render(): void
    {
        $camp = cjw_summer_camp();

        $this->renderStatus($camp);
        $this->renderAttentionList($camp);
        $this->renderQuickLinks();
    }

    /**
     * Renders the camp status summary: dates, registration and prices.
     *
     * @param CJW_Summer_Camp_Service $camp Summer camp service.
     *
     * @return void
     */
    private function renderStatus(CJW_Summer_Camp_Service $camp): void
    {
        $date_range = $camp->getDateRangeText();
        $countdown = $this->campCountdownText($camp);
        $registration_open = $camp->isRegistrationOpen();
        $deadline = $camp->getRegistrationDeadline();
        $badge_label = $registration_open ? __('Open', 'cjw') : __('Gesloten', 'cjw');
        $badge_style = 'display:inline-block;padding:1px 8px;border-radius:9999px;font-weight:600;'
            . ($registration_open ? 'background:#edfaef;color:#007017;' : 'background:#fcf0f1;color:#b32d2e;');

        ?>
        <p>
            <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
            <strong><?php echo esc_html($date_range !== '' ? $date_range : __('Kampdata nog niet ingesteld', 'cjw')); ?></strong>
            <?php if ($countdown !== ''): ?>
                &mdash; <?php echo esc_html($countdown); ?>
            <?php endif; ?>
        </p>
        <p>
            <span class="dashicons dashicons-groups" aria-hidden="true"></span>
            <?php esc_html_e('Inschrijvingen:', 'cjw'); ?>
            <span style="<?php echo esc_attr($badge_style); ?>"><?php echo esc_html($badge_label); ?></span>
            <?php if ($deadline instanceof DateTimeImmutable): ?>
                <?php
                echo esc_html(sprintf(
                    /* translators: %s: registration deadline date. */
                    __('— deadline %s', 'cjw'),
                    $this->formatDate($deadline)
                ));
                ?>
            <?php else : ?>
                <?php esc_html_e('— geen deadline ingesteld', 'cjw'); ?>
            <?php endif; ?>
        </p>
        <?php if (enum_exists('CampType')): ?>
            <p>
                <span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
                <?php
                echo esc_html(sprintf(
                    /* translators: 1: week price, 2: weekend price. */
                    __('Week %1$s · Weekend %2$s', 'cjw'),
                    $this->formatPrice($camp->getPrice(CampType::WEEK)),
                    $this->formatPrice($camp->getPrice(CampType::WEEKEND))
                ));
            ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Renders the attention list with configuration issues that need action.
     *
     * @param CJW_Summer_Camp_Service $camp Summer camp service.
     *
     * @return void
     */
    private function renderAttentionList(CJW_Summer_Camp_Service $camp): void
    {
        $items = $this->attentionItems($camp);

        ?>
        <h3 style="margin-bottom:4px;"><?php esc_html_e('Aandachtspunten', 'cjw'); ?></h3>
        <?php if ($items === []): ?>
            <p>
                <span class="dashicons dashicons-yes-alt" style="color:#007017;" aria-hidden="true"></span>
                <?php esc_html_e('Alles staat klaar, geen aandachtspunten.', 'cjw'); ?>
            </p>
        <?php else : ?>
            <ul style="list-style:disc;margin:0 0 12px 20px;">
                <?php foreach ($items as $item): ?>
                    <li><?php echo esc_html($item); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php
    }

    /**
     * Builds the list of attention points.
     *
     * @param CJW_Summer_Camp_Service $camp Summer camp service.
     *
     * @return string[]
     */
    private function attentionItems(CJW_Summer_Camp_Service $camp): array
    {
        $items = [];

        if (!$camp->getStartDate() instanceof DateTimeImmutable || !$camp->getEndDate() instanceof DateTimeImmutable) {
            $items[] = __('Er zijn nog geen kampdata (start- en einddatum) ingesteld.', 'cjw');
        }

        if ($camp->getContactEmail() === '') {
            $items[] = __('Er is nog geen contact-e-mailadres ingesteld.', 'cjw');
        }

        if (post_type_exists('sponsor') && !$this->hasPublishedSponsorWithLogo()) {
            $items[] = __('Er is nog geen gepubliceerde sponsor met een logo (uitgelichte afbeelding).', 'cjw');
        }

        if ($camp->getPolaroids() === []) {
            $items[] = __('Er zijn nog geen foto’s voor het kampplakboek gekozen.', 'cjw');
        }

        if (post_type_exists('cjw_wizard') && !$this->hasPublishedWizardForm()) {
            $items[] = __('Er is nog geen gepubliceerd inschrijfformulier.', 'cjw');
        }

        return $items;
    }

    /**
     * Renders the quick links to the most used admin screens.
     *
     * @return void
     */
    private function renderQuickLinks(): void
    {
        $links = [
            [admin_url('admin.php?page=cjw-summer-camp'), __('Zomerkamp-instellingen', 'cjw')],
        ];

        if (post_type_exists('sponsor')) {
            $links[] = [admin_url('edit.php?post_type=sponsor'), __('Sponsoren', 'cjw')];
        }

        if (post_type_exists('cjw_wizard')) {
            $links[] = [admin_url('edit.php?post_type=cjw_wizard'), __('Formulieren', 'cjw')];
        }

        // Spelled out rather than read from CJW_Wizard_Submission_Repository:
        // the summer-camp module does not reach into the form-builder module.
        if (post_type_exists('cjw_wizard_entry')) {
            $links[] = [admin_url('edit.php?post_type=cjw_wizard_entry'), __('Inschrijvingen', 'cjw')];
        }

        $links[] = [admin_url('edit.php?post_type=page'), __('Pagina’s', 'cjw')];

        echo '<p style="border-top:1px solid #dcdcde;padding-top:8px;margin-top:8px;">';

        foreach ($links as $index => $link) {
            if ($index > 0) {
                echo ' | ';
            }

            printf('<a href="%s">%s</a>', esc_url($link[0]), esc_html($link[1]));
        }

        echo '</p>';
    }

    /**
     * Returns the countdown text for the configured camp dates.
     *
     * @param CJW_Summer_Camp_Service $camp Summer camp service.
     *
     * @return string
     */
    private function campCountdownText(CJW_Summer_Camp_Service $camp): string
    {
        $start = $camp->getStartDate();
        $end = $camp->getEndDate();

        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            return '';
        }

        $today = new DateTimeImmutable()->setTime(0, 0);

        if ($today > $end) {
            return __('afgelopen', 'cjw');
        }

        if ($today >= $start) {
            return __('nu bezig', 'cjw');
        }

        $days = (int) $today->diff($start)->days;

        return sprintf(
            /* translators: %d: number of days until the camp starts. */
            _n('nog %d dag te gaan', 'nog %d dagen te gaan', $days, 'cjw'),
            $days
        );
    }

    /**
     * Checks whether at least one published sponsor has a featured image.
     *
     * @return bool
     */
    private function hasPublishedSponsorWithLogo(): bool
    {
        $sponsors = get_posts([
            'post_type' => 'sponsor',
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields' => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin-only check on a small sponsor set.
            'meta_key' => '_thumbnail_id',
        ]);

        return $sponsors !== [];
    }

    /**
     * Checks whether at least one wizard form is published.
     *
     * @return bool
     */
    private function hasPublishedWizardForm(): bool
    {
        $forms = get_posts([
            'post_type' => 'cjw_wizard',
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields' => 'ids',
        ]);

        return $forms !== [];
    }

    /**
     * Formats an admin-facing date.
     *
     * @param DateTimeImmutable $date Date to format.
     *
     * @return string
     */
    private function formatDate(DateTimeImmutable $date): string
    {
        if (function_exists('cjw_localize_value')) {
            $formatted = cjw_localize_value($date, [
                'type' => 'date',
                'format' => (string) get_option('date_format'),
            ]);

            if (is_scalar($formatted) && (string) $formatted !== '') {
                return (string) $formatted;
            }
        }

        return date_i18n((string) get_option('date_format'), $date->getTimestamp());
    }

    /**
     * Formats a camp price for display.
     *
     * @param int $amount Price in whole euros.
     *
     * @return string
     */
    private function formatPrice(int $amount): string
    {
        if (function_exists('cjw_localize_value')) {
            $formatted = cjw_localize_value($amount, [
                'type' => 'currency',
                'currency_symbol' => 'EUR',
            ]);

            if (is_scalar($formatted) && (string) $formatted !== '') {
                return (string) $formatted;
            }
        }

        return '€ ' . number_format_i18n($amount);
    }
}
