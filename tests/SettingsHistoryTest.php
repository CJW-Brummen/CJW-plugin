<?php

declare(strict_types=1);

final class SettingsHistoryTest extends CJW_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['cjw_test_options'] = [];
        $GLOBALS['cjw_test_current_user'] = new WP_User(3, 'Marieke');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['cjw_test_options'], $GLOBALS['cjw_test_current_user']);

        CJW_Summer_Camp_Settings_History::nameNextSnapshot('');

        parent::tearDown();
    }

    public function testSnapshotsAreNewestFirst(): void
    {
        $history = new CJW_Summer_Camp_Settings_History();

        $history->store(['theme_name' => 'Piraten'], 'eerste');
        $history->store(['theme_name' => 'Ruimtevaart'], 'tweede');

        $stored = CJW_Summer_Camp_Settings_History::all();

        $this->assertCount(2, $stored);
        $this->assertSame('Ruimtevaart', $stored[0]['settings']['theme_name']);
        $this->assertSame('Piraten', $stored[1]['settings']['theme_name']);
    }

    public function testOldestSnapshotsFallOffTheEnd(): void
    {
        $history = new CJW_Summer_Camp_Settings_History();

        for ($i = 1; $i <= CJW_Summer_Camp_Settings_History::KEEP + 5; $i++) {
            $history->store(['theme_name' => 'Thema ' . $i]);
        }

        $stored = CJW_Summer_Camp_Settings_History::all();

        $this->assertCount(CJW_Summer_Camp_Settings_History::KEEP, $stored);
        $this->assertSame('Thema 15', $stored[0]['settings']['theme_name']);
        $this->assertSame('Thema 6', $stored[ CJW_Summer_Camp_Settings_History::KEEP - 1 ]['settings']['theme_name']);
    }

    public function testSnapshotRecordsWhoAndWhy(): void
    {
        new CJW_Summer_Camp_Settings_History()->store(['theme_name' => 'Piraten'], 'Archief van kampjaar 2026');

        $stored = CJW_Summer_Camp_Settings_History::all();

        $this->assertSame('Marieke', $stored[0]['by']);
        $this->assertSame('Archief van kampjaar 2026', $stored[0]['reason']);
        $this->assertGreaterThan(0, $stored[0]['time']);
    }

    public function testAnIdenticalSaveIsNotSnapshotted(): void
    {
        $history = new CJW_Summer_Camp_Settings_History();
        $settings = ['theme_name' => 'Piraten'];

        $history->snapshotBeforeUpdate($settings, $settings);

        $this->assertSame([], CJW_Summer_Camp_Settings_History::all());
    }

    public function testTheFirstEverSaveIsNotSnapshotted(): void
    {
        $history = new CJW_Summer_Camp_Settings_History();

        $history->snapshotBeforeUpdate(['theme_name' => 'Piraten'], []);

        $this->assertSame([], CJW_Summer_Camp_Settings_History::all());
    }

    public function testSnapshotKeepsTheOutgoingValueNotTheIncomingOne(): void
    {
        new CJW_Summer_Camp_Settings_History()->snapshotBeforeUpdate(
            ['theme_name' => 'Ruimtevaart'],
            ['theme_name' => 'Piraten']
        );

        $stored = CJW_Summer_Camp_Settings_History::all();

        $this->assertSame('Piraten', $stored[0]['settings']['theme_name']);
    }

    public function testACallerCanNameTheNextSnapshotOnce(): void
    {
        $history = new CJW_Summer_Camp_Settings_History();

        CJW_Summer_Camp_Settings_History::nameNextSnapshot('Archief van kampjaar 2026');

        $history->snapshotBeforeUpdate(['a' => 2], ['a' => 1]);
        $history->snapshotBeforeUpdate(['a' => 3], ['a' => 2]);

        $stored = CJW_Summer_Camp_Settings_History::all();

        $this->assertSame('Archief van kampjaar 2026', $stored[1]['reason']);
        $this->assertNotSame('Archief van kampjaar 2026', $stored[0]['reason']);
    }

    public function testDifferenceCountComparesAgainstWhatIsStoredNow(): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, [
            'theme_name' => 'Ruimtevaart',
            'price_week' => 99,
            'contact_email' => 'info@example.test',
        ]);

        $differences = CJW_Summer_Camp_Settings_History::differenceCount([
            'theme_name' => 'Piraten',
            'price_week' => 99,
            'contact_email' => 'info@example.test',
        ]);

        $this->assertSame(1, $differences);
    }

    public function testAKeyMissingOnOneSideCountsAsADifference(): void
    {
        update_option(CJW_Summer_Camp_Service::OPTION_KEY, ['theme_name' => 'Piraten']);

        $this->assertSame(
            1,
            CJW_Summer_Camp_Settings_History::differenceCount([
                'theme_name' => 'Piraten',
                'theme_year' => '2027',
            ])
        );
    }

    public function testMalformedRowsAreSkippedRatherThanReturned(): void
    {
        update_option(CJW_Summer_Camp_Settings_History::OPTION_KEY, [
            ['time' => 1, 'settings' => ['theme_name' => 'Piraten']],
            ['time' => 2],
            'niet eens een rij',
        ]);

        $this->assertCount(1, CJW_Summer_Camp_Settings_History::all());
    }
}
