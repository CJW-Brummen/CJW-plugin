<?php

declare(strict_types=1);

/**
 * A registration written to the database and read back out of it.
 *
 * The fast suite asserts this against arrays it keeps in a global, which can
 * only ever confirm what the shim's author expected. Here wp_insert_post(),
 * add_post_meta() and WP_Query do the work, so serialisation, the meta cache,
 * unique slugs and multi-row meta all behave the way they will in production.
 */
final class SubmissionRoundTripTest extends CJW_IntegrationTestCase
{
    private CJW_Wizard_Submission_Repository $repository;

    public function set_up(): void
    {
        parent::set_up();

        $this->repository = new CJW_Wizard_Submission_Repository();
    }

    /**
     * A form asking for two children and a parent, as the live one does.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'steps' => [
                [
                    'title' => 'Kinderen',
                    'fields' => [
                        [
                            'key' => 'kinderen',
                            'type' => 'repeater',
                            'label' => 'Kinderen',
                            'fields' => [
                                [ 'key' => 'voornaam', 'type' => 'text', 'label' => 'Voornaam', 'role' => 'child_name' ],
                                [ 'key' => 'tussenvoegsel', 'type' => 'text', 'label' => 'Tussenvoegsel', 'role' => 'child_infix' ],
                                [ 'key' => 'achternaam', 'type' => 'text', 'label' => 'Achternaam', 'role' => 'child_last_name' ],
                                [ 'key' => 'kamp', 'type' => 'select', 'label' => 'Kamp', 'role' => 'camp_type' ],
                            ],
                        ],
                    ],
                ],
                [
                    'title' => 'Ouder',
                    'fields' => [
                        [ 'key' => 'voornaam', 'type' => 'text', 'label' => 'Voornaam ouder' ],
                        [ 'key' => 'email', 'type' => 'email', 'label' => 'E-mailadres' ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'kinderen' => [
                [ 'voornaam' => 'Fleur', 'tussenvoegsel' => '', 'achternaam' => 'Jansen', 'kamp' => 'Weekendkamp - €44' ],
                [ 'voornaam' => 'Sem', 'tussenvoegsel' => 'van der', 'achternaam' => 'Meer', 'kamp' => 'Weekkamp - €101' ],
            ],
            'voornaam' => 'Karin',
            'email' => 'karin@example.test',
        ];
    }

    public function testARegistrationSurvivesTheRoundTrip(): void
    {
        $wizardId = self::factory()->post->create([ 'post_type' => 'cjw_wizard' ]);

        $id = $this->repository->save($wizardId, $this->config(), $this->payload());

        $this->assertGreaterThan(0, $id);

        // Read back through WordPress, not from a variable still in scope.
        wp_cache_flush();
        $post = get_post($id);

        $this->assertSame(CJW_Wizard_Submission_Repository::POST_TYPE, $post->post_type);
        $this->assertSame(CJW_Wizard_Submission_Status::NEW, $post->post_status);
        $this->assertSame('Inschrijving #' . $id, $post->post_title);
        $this->assertSame('inschrijving-' . $id, $post->post_name);

        $this->assertSame($this->payload(), $this->repository->get_payload($id));
        $this->assertSame('karin@example.test', $this->repository->get_email($id));
        $this->assertSame('Fleur Jansen, Sem van der Meer', $this->repository->get_child_name($id));
    }

    public function testTwoChildrenAtDifferentCampsBothGetTheirOwnMetaRow(): void
    {
        $wizardId = self::factory()->post->create([ 'post_type' => 'cjw_wizard' ]);
        $id = $this->repository->save($wizardId, $this->config(), $this->payload());

        wp_cache_flush();

        // add_post_meta(), not update_post_meta(): one row per camp, so a
        // filter by camp matches a family that split across two of them.
        $rows = get_post_meta($id, CJW_Wizard_Submission_Repository::META_CAMP_TYPE, false);

        $this->assertSame([ 'Weekendkamp - €44', 'Weekkamp - €101' ], $rows);
    }

    public function testFilteringByCampFindsAFamilySplitAcrossTwo(): void
    {
        $wizardId = self::factory()->post->create([ 'post_type' => 'cjw_wizard' ]);
        $id = $this->repository->save($wizardId, $this->config(), $this->payload());

        foreach ([ 'Weekendkamp - €44', 'Weekkamp - €101' ] as $camp) {
            $found = get_posts([
                'post_type' => CJW_Wizard_Submission_Repository::POST_TYPE,
                'post_status' => CJW_Wizard_Submission_Status::inAllList(),
                'numberposts' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [ 'key' => CJW_Wizard_Submission_Repository::META_CAMP_TYPE, 'value' => $camp ],
                ],
            ]);

            $this->assertSame([ $id ], array_map('intval', $found), $camp);
        }
    }

    public function testTheTitleAndSlugCarryNoChildsName(): void
    {
        $wizardId = self::factory()->post->create([ 'post_type' => 'cjw_wizard' ]);
        $id = $this->repository->save($wizardId, $this->config(), $this->payload());

        $post = get_post($id);

        foreach ([ 'Fleur', 'Jansen', 'Sem', 'Meer', 'karin' ] as $personal) {
            $this->assertStringNotContainsStringIgnoringCase($personal, $post->post_title);
            $this->assertStringNotContainsStringIgnoringCase($personal, $post->post_name);
        }
    }

    public function testAnonymisingStripsTheDataAndKeepsTheCount(): void
    {
        $wizardId = self::factory()->post->create([ 'post_type' => 'cjw_wizard' ]);
        $id = $this->repository->save($wizardId, $this->config(), $this->payload());

        $this->assertTrue($this->repository->anonymise($id));

        wp_cache_flush();

        $this->assertSame([], $this->repository->get_payload($id));
        $this->assertSame('', $this->repository->get_email($id));
        $this->assertSame('', $this->repository->get_child_name($id));

        // What the camp legitimately keeps: that a registration happened, for
        // which camp, in which year.
        $this->assertNotSame([], get_post_meta($id, CJW_Wizard_Submission_Repository::META_CAMP_TYPE, false));
        $this->assertNotSame('', $this->repository->get_camp_year($id));
        $this->assertInstanceOf(WP_Post::class, get_post($id));

        // Idempotent.
        $this->assertFalse($this->repository->anonymise($id));
    }

    public function testAnonymisingATrashedRegistrationLeavesItTrashed(): void
    {
        $wizardId = self::factory()->post->create([ 'post_type' => 'cjw_wizard' ]);
        $id = $this->repository->save($wizardId, $this->config(), $this->payload());

        wp_trash_post($id);
        $this->assertSame('trash', get_post_status($id));

        $this->repository->anonymise($id);

        // wp_update_post() would otherwise restore it to draft, undoing an
        // organiser's deletion as a side effect of scrubbing it.
        $this->assertSame('trash', get_post_status($id));
    }
}
