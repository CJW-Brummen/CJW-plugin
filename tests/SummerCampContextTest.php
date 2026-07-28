<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
final readonly class CJW_Test_Summer_Camp_Service
{
    public function __construct(
        private bool $registrationOpen = true
    ) {}

    public function isRegistrationOpen(): bool
    {
        return $this->registrationOpen;
    }

    public function getRegistrationClosedText(): string
    {
        return '<p>Vol voor dit jaar.</p>';
    }

    public function getLateRegistrationLink(): string
    {
        return 'https://example.test/late';
    }

    public static function getLateRegistrationQueryArg(): string
    {
        return 'cjw_late_registration';
    }

    public function isLateRegistrationTokenValid(?string $token): bool
    {
        return $token === 'secret-token';
    }

    public function getIntroText(): string
    {
        return '<p>Welkom bij het kamp.</p>';
    }

    public function getStartDate(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-18');
    }

    public function getEndDate(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-25');
    }

    public function getDateRangeText(): string
    {
        return '18 tot 25 juli';
    }

    public function getThemeName(): string
    {
        return 'CJW Game on!';
    }

    public function getThemeYear(): string
    {
        return '2026';
    }

    public function getHeroConfig(): array
    {
        return [
            'title' => 'Game on!',
            'subtitle' => 'Speel mee deze zomer',
        ];
    }

    public function getPrice(CampType $type): int
    {
        return $type === CampType::WEEK ? 275 : 125;
    }
}

function cjw_summer_camp(): object
{
    return $GLOBALS['cjw_test_summer_camp'];
}

final class SummerCampContextTest extends CJW_TestCase
{
    protected function tearDown(): void
    {
        unset($_GET['cjw_late_registration']);
    }

    public function testReplacesSummerCampTokensWhenEnabled(): void
    {
        $GLOBALS['cjw_test_summer_camp'] = new CJW_Test_Summer_Camp_Service();

        $context = new CJW_Wizard_Summer_Camp_Context(
            [
                'settings' => [
                    ...CJW_Wizard_Defaults::settings(),
                    'use_summer_camp_settings' => true,
                ],
            ]
        );

        $config = $context->applyTokens(
            [
                'settings' => CJW_Wizard_Defaults::settings(),
                'steps' => [
                    'intro' => [
                        'label' => '{camp_theme_name}',
                        'fields' => [
                            [
                                'key' => 'info',
                                'type' => 'html',
                                'label' => 'Weekprijs: {camp_price_week}. Start: {camp_start_date_iso}.',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->assertSame('CJW Game on!', $config['steps']['intro']['label']);
        $this->assertSame('Weekprijs: € 275. Start: 2026-07-18.', $config['steps']['intro']['fields'][0]['label']);
    }

    public function testLocalizesLocaleKeyedConfigBeforeReplacingTokens(): void
    {
        $GLOBALS['cjw_test_summer_camp'] = new CJW_Test_Summer_Camp_Service();

        $context = new CJW_Wizard_Summer_Camp_Context(
            [
                'settings' => [
                    ...CJW_Wizard_Defaults::settings(),
                    'use_summer_camp_settings' => true,
                ],
            ]
        );

        $config = $context->applyTokens(
            [
                'settings' => CJW_Wizard_Defaults::settings(),
                'steps' => [
                    'intro' => [
                        'label' => [
                            'en_US' => '{camp_theme_name}',
                            'nl_NL' => 'Nederlands thema',
                        ],
                        'fields' => [],
                    ],
                ],
            ]
        );

        $this->assertSame('CJW Game on!', $config['steps']['intro']['label']);
    }

    public function testRendersClosedNoticeWhenRegistrationIsClosed(): void
    {
        $GLOBALS['cjw_test_summer_camp'] = new CJW_Test_Summer_Camp_Service(false);

        $context = new CJW_Wizard_Summer_Camp_Context(
            [
                'settings' => [
                    ...CJW_Wizard_Defaults::settings(),
                    'use_summer_camp_settings' => true,
                ],
            ]
        );

        $this->assertTrue($context->shouldBlockRegistration());
        $this->assertStringContainsString('Vol voor dit jaar.', $context->renderClosedNotice());
        $this->assertStringNotContainsString('https://example.test/late', $context->renderClosedNotice());
    }

    public function testValidLateRegistrationTokenBypassesClosedRegistration(): void
    {
        $_GET['cjw_late_registration'] = 'secret-token';
        $GLOBALS['cjw_test_summer_camp'] = new CJW_Test_Summer_Camp_Service(false);

        $context = new CJW_Wizard_Summer_Camp_Context(
            [
                'settings' => [
                    ...CJW_Wizard_Defaults::settings(),
                    'use_summer_camp_settings' => true,
                ],
            ]
        );

        $this->assertFalse($context->shouldBlockRegistration());
    }
}
