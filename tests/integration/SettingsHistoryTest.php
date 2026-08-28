<?php

declare(strict_types=1);

/**
 * The undo the settings screen never had, against a real option row.
 *
 * The whole mechanism hangs off a WordPress filter firing inside
 * update_option(). The unit suite cannot see that -- its update_option is a
 * shim that writes an array -- so the part that would actually break in
 * production is checked here.
 */
final class SettingsHistoryTest extends CJW_IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        delete_option(CJW_Summer_Camp_Settings_History::OPTION_KEY);
        delete_option(CJW_Summer_Camp_Service::OPTION_KEY);

        $this->actAsAdministrator();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function save(array $settings): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, $settings);
    }

    public function testSavingTheSettingsKeepsWhatWasThereBefore(): void
    {
        $this->save([ 'theme_name' => 'Piraten' ]);
        $this->save([ 'theme_name' => 'Ruimtevaart' ]);

        $snapshots = CJW_Summer_Camp_Settings_History::all();

        $this->assertCount(1, $snapshots);
        $this->assertSame('Piraten', $snapshots[0]['settings']['theme_name']);
    }

    public function testTheSnapshotIsNotAutoloadedOnEveryRequest(): void
    {
        $this->save([ 'theme_name' => 'Piraten' ]);
        $this->save([ 'theme_name' => 'Ruimtevaart' ]);

        global $wpdb;

        $autoload = $wpdb->get_var($wpdb->prepare(
            "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
            CJW_Summer_Camp_Settings_History::OPTION_KEY
        ));

        // Ten copies of every camp setting, read on one admin screen. Loading
        // that into memory on every front-end request would make the history a
        // performance cost rather than a safety net.
        $this->assertNotSame('yes', $autoload);
    }

    public function testSavingTheSameValuesTwiceKeepsOneSnapshot(): void
    {
        $this->save([ 'theme_name' => 'Piraten' ]);
        $this->save([ 'theme_name' => 'Ruimtevaart' ]);
        $this->save([ 'theme_name' => 'Ruimtevaart' ]);

        $this->assertCount(1, CJW_Summer_Camp_Settings_History::all());
    }

    public function testRestoringPutsTheOldValuesBack(): void
    {
        $this->save([ 'theme_name' => 'Piraten', 'price_week' => 99 ]);
        $this->save([ 'theme_name' => 'Ruimtevaart', 'price_week' => 105 ]);

        $snapshots = CJW_Summer_Camp_Settings_History::all();
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, $snapshots[0]['settings']);

        $settings = get_option(CJW_Summer_Camp_Service::OPTION_KEY);

        $this->assertSame('Piraten', $settings['theme_name']);
        $this->assertSame(99, $settings['price_week']);
    }

    /**
     * An undo that cannot itself be undone is a second way to lose the same
     * work: press restore on the wrong row and the state you were in is gone.
     */
    public function testRestoringIsItselfUndoable(): void
    {
        $this->save([ 'theme_name' => 'Piraten' ]);
        $this->save([ 'theme_name' => 'Ruimtevaart' ]);

        update_option(
            CJW_Summer_Camp_Service::OPTION_KEY,
            CJW_Summer_Camp_Settings_History::all()[0]['settings']
        );

        $snapshots = CJW_Summer_Camp_Settings_History::all();

        $this->assertCount(2, $snapshots);
        $this->assertSame('Ruimtevaart', $snapshots[0]['settings']['theme_name']);
    }

    public function testTheHistoryStopsAtItsLimit(): void
    {
        for ($i = 1; $i <= CJW_Summer_Camp_Settings_History::KEEP + 3; $i++) {
            $this->save([ 'theme_name' => 'Thema ' . $i ]);
        }

        $this->assertCount(CJW_Summer_Camp_Settings_History::KEEP, CJW_Summer_Camp_Settings_History::all());
    }

    /**
     * sanitize() returns every known key, filling absent ones with defaults. So
     * an import that sanitised first and merged second would hand array_merge a
     * complete array of mostly-empty values, and quietly blank every field the
     * uploaded file happened not to carry.
     */
    public function testAnOlderExportDoesNotBlankNewerFields(): void
    {
        $this->save(CJW_Summer_Camp_Service::sanitize([
            'theme_name' => 'Piraten',
            'contact_email' => 'info@example.test',
            'footer_about' => 'Al veertig jaar kamp.',
            'price_week' => 99,
        ]));

        // An export from before footer_about and price_week existed.
        $uploaded = [ 'theme_name' => 'Ruimtevaart', 'contact_email' => 'info@example.test' ];

        $current = get_option(CJW_Summer_Camp_Service::OPTION_KEY);
        update_option(
            CJW_Summer_Camp_Service::OPTION_KEY,
            CJW_Summer_Camp_Service::sanitize(array_merge($current, $uploaded))
        );

        $settings = get_option(CJW_Summer_Camp_Service::OPTION_KEY);

        $this->assertSame('Ruimtevaart', $settings['theme_name']);
        $this->assertSame('Al veertig jaar kamp.', $settings['footer_about']);
        $this->assertSame(99, $settings['price_week']);
    }

    public function testTheSnapshotRecordsWhoSavedIt(): void
    {
        $user = get_userdata(get_current_user_id());

        $this->save([ 'theme_name' => 'Piraten' ]);
        $this->save([ 'theme_name' => 'Ruimtevaart' ]);

        $this->assertSame($user->display_name, CJW_Summer_Camp_Settings_History::all()[0]['by']);
    }
}
