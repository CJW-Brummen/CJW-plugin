<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Site Health check for the registration wizard's rate limiting.
 *
 * The throttle identifies a visitor by their connecting address. If a proxy or
 * CDN sits in front of the site, that address is the proxy's, every visitor
 * collapses onto one fingerprint, and the cap that should stop a flood instead
 * turns away real parents once ten registrations have come in -- silently, and
 * most likely on the busiest day of the year.
 *
 * There is no way to detect that from configuration alone, so the guard samples
 * how many distinct visitors it actually sees and this check reads the result.
 */
final class CJW_Wizard_Site_Health
{
    public function init(): void
    {
        add_filter('site_status_tests', [ $this, 'register' ]);
    }

    /**
     * Adds the check to the Site Health screen.
     *
     * @param array $tests Registered tests.
     * @param array<string, mixed> $tests
     *
     * @return array<string, mixed>
     */
    public function register(array $tests): array
    {
        $tests['direct']['cjw_wizard_rate_limit_source'] = [
            'label' => __('Inschrijfformulier: herkenning van bezoekers', 'cjw'),
            'test' => [ $this, 'run' ],
        ];

        return $tests;
    }

    /**
     * Reports whether the throttle can tell visitors apart.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $sample = CJW_Wizard_Guard::trafficSample();

        $result = [
            'label' => __('Het inschrijfformulier herkent bezoekers afzonderlijk', 'cjw'),
            'status' => 'good',
            'badge' => [
                'label' => __('Beveiliging', 'cjw'),
                'color' => 'blue',
            ],
            'description' => '<p>' . esc_html__(
                'De limiet op het aantal inschrijvingen per uur werkt per bezoeker, zoals bedoeld.',
                'cjw'
            ) . '</p>',
            'actions' => '',
            'test' => 'cjw_wizard_rate_limit_source',
        ];

        if (! $sample['conclusive']) {
            $result['status'] = 'recommended';
            $result['label'] = __('Nog te weinig bezoek om dit te kunnen controleren', 'cjw');
            $result['description'] = '<p>' . sprintf(
                /* translators: 1: requests observed so far, 2: requests needed. */
                esc_html__(
                    'Er zijn %1$d van de %2$d benodigde bezoeken aan het inschrijfformulier gemeten. Kom hier terug zodra het formulier live staat.',
                    'cjw'
                ),
                absint($sample['count']),
                absint($sample['threshold'])
            ) . '</p>';

            return $result;
        }

        if ($sample['distinct'] > 1) {
            return $result;
        }

        $result['status'] = 'critical';
        $result['badge']['color'] = 'red';
        $result['label'] = __('Alle bezoekers van het inschrijfformulier lijken dezelfde persoon', 'cjw');
        $result['description']
            = '<p>' . sprintf(
                /* translators: %d: number of requests observed. */
                esc_html__(
                    'Bij %d gemeten bezoeken is er telkens maar één bezoeker herkend. Dat betekent vrijwel zeker dat de site achter een proxy of CDN staat en dat de server het adres van die tussenlaag ziet in plaats van dat van de bezoeker.',
                    'cjw'
                ),
                absint($sample['count'])
            ) . '</p>'
            . '<p>' . esc_html__(
                'Gevolg: de limiet op inschrijvingen per uur geldt dan voor de hele site samen. Na tien inschrijvingen in een uur worden échte ouders geweigerd.',
                'cjw'
            ) . '</p>'
            . '<p>' . esc_html__(
                'Op te lossen door het juiste bezoekersadres door te geven met de filter cjw_wizard_client_ip — bijvoorbeeld de header CF-Connecting-IP bij Cloudflare.',
                'cjw'
            ) . '</p>';

        return $result;
    }
}
