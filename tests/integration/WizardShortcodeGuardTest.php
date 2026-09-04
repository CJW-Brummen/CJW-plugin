<?php

declare(strict_types=1);

/**
 * What [cjw_wizard] does with an id that is wrong.
 *
 * The shortcode is copy-pasted by hand out of the form's sidebar, so a wrong
 * id is the likeliest mistake anybody makes on this site -- and it used to be
 * the quietest: an id pointing at nothing produced an empty config, an empty
 * config took the same branch as a finished registration, and a parent read
 * "Gelukt!" while nothing had been saved and nobody had been mailed.
 *
 * Only a real WordPress can settle these. Whether a page id has wizard meta,
 * what get_post_status() says about a draft, and whether current_user_can()
 * lets somebody preview one are all questions a shim would answer by agreeing
 * with whoever wrote it.
 */
final class WizardShortcodeGuardTest extends CJW_IntegrationTestCase
{
    private const string UNAVAILABLE = 'staat op dit moment niet klaar';

    /**
     * A published form with one real step, so the happy path is distinguishable
     * from every refusal below.
     */
    private function publishedForm(string $status = 'publish'): int
    {
        $id = self::factory()->post->create([
            'post_type' => 'cjw_wizard',
            'post_title' => 'Inschrijfformulier',
            'post_status' => $status,
        ]);

        update_post_meta($id, '_cjw_wizard_config', (string) wp_json_encode([
            'steps' => [
                [
                    'key' => 'deelnemer',
                    'label' => 'Deelnemer',
                    'type' => 'default',
                    'fields' => [
                        [ 'key' => 'voornaam', 'label' => 'Voornaam', 'type' => 'text' ],
                    ],
                ],
            ],
        ]));

        return $id;
    }

    private function render(string $shortcode): string
    {
        return (string) do_shortcode($shortcode);
    }

    public function testAPublishedFormStillRenders(): void
    {
        $id = $this->publishedForm();

        $output = $this->render('[cjw_wizard id="' . $id . '"]');

        $this->assertStringContainsString('cjw-wizard-form', $output);
        $this->assertStringNotContainsString(self::UNAVAILABLE, $output);
        $this->assertStringNotContainsString('Gelukt!', $output);
    }

    public function testAnIdThatPointsAtNothingIsRefusedRatherThanCongratulated(): void
    {
        $output = $this->render('[cjw_wizard id="999999"]');

        $this->assertStringNotContainsString('Gelukt!', $output);
        $this->assertStringContainsString(self::UNAVAILABLE, $output);
    }

    /**
     * A page id pasted by mistake reads its wizard meta as empty, which is
     * exactly the shape that used to render as a completed registration.
     */
    public function testAnIdOfSomeOtherPostTypeIsRefused(): void
    {
        $pageId = self::factory()->post->create([ 'post_type' => 'page' ]);

        $output = $this->render('[cjw_wizard id="' . $pageId . '"]');

        $this->assertStringNotContainsString('Gelukt!', $output);
        $this->assertStringContainsString(self::UNAVAILABLE, $output);
    }

    public function testTheShortcodeWithNoIdIsRefused(): void
    {
        $output = $this->render('[cjw_wizard]');

        $this->assertStringNotContainsString('Gelukt!', $output);
        $this->assertStringContainsString(self::UNAVAILABLE, $output);
    }

    public function testAFormWithNoStepsIsRefused(): void
    {
        $id = self::factory()->post->create([
            'post_type' => 'cjw_wizard',
            'post_status' => 'publish',
        ]);

        $output = $this->render('[cjw_wizard id="' . $id . '"]');

        $this->assertStringNotContainsString('Gelukt!', $output);
        $this->assertStringContainsString(self::UNAVAILABLE, $output);
    }

    public function testAVisitorCannotFillInADraftForm(): void
    {
        $id = $this->publishedForm('draft');

        $output = $this->render('[cjw_wizard id="' . $id . '"]');

        $this->assertStringContainsString(self::UNAVAILABLE, $output);
        $this->assertStringNotContainsString('cjw-wizard-form', $output);
    }

    /**
     * A parent must never be shown the reason. It tells them nothing they can
     * act on and unsettles them about a site holding their child's details.
     */
    public function testAVisitorIsNotToldWhyTheFormIsMissing(): void
    {
        $output = $this->render('[cjw_wizard id="999999"]');

        $this->assertStringNotContainsString('Alleen zichtbaar voor beheerders', $output);
        $this->assertStringNotContainsString('shortcode', $output);
    }

    public function testAnEditorIsToldExactlyWhatIsWrong(): void
    {
        $this->actAsAdministrator();

        $output = $this->render('[cjw_wizard id="999999"]');

        $this->assertStringContainsString('Alleen zichtbaar voor beheerders', $output);
        $this->assertStringContainsString('999999', $output);
    }

    /**
     * Checking the steps before going live is what a draft is for, so an
     * editor gets to walk it -- under a banner saying what they are looking at.
     */
    public function testAnEditorMayPreviewADraftUnderABanner(): void
    {
        $this->actAsAdministrator();
        $id = $this->publishedForm('draft');

        $output = $this->render('[cjw_wizard id="' . $id . '"]');

        $this->assertStringContainsString('cjw-wizard-draft-banner', $output);
        $this->assertStringContainsString('staat niet online', $output);
        $this->assertStringContainsString('cjw-wizard-form', $output);
    }
}
