<?php

declare(strict_types=1);

final class SettingsTest extends CJW_TestCase
{
    public function testDefaultsComeFromSharedDefaultsClass(): void
    {
        $settings = new CJW_Wizard_Settings();
        $defaults = CJW_Wizard_Defaults::settings();

        $this->assertSame($defaults, $settings->get(123));
    }

    public function testSanitizeFallsBackToSharedDefaults(): void
    {
        $settings = new CJW_Wizard_Settings();
        $sanitized = $settings->sanitize(
            [
                'submit_label' => 'Verder',
                'complete_label' => '',
                'success_message' => '',
                'show_previous_button' => '1',
                'notification_email' => 'invalid-email',
            ]
        );

        $this->assertSame('Verder', $sanitized['submit_label']);
        $this->assertSame(CJW_Wizard_Defaults::settings()['complete_label'], $sanitized['complete_label']);
        $this->assertSame(CJW_Wizard_Defaults::settings()['success_message'], $sanitized['success_message']);
        $this->assertTrue($sanitized['show_previous_button']);
        $this->assertSame('', $sanitized['notification_email']);
    }
}
