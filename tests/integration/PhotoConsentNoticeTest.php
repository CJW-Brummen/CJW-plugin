<?php

declare(strict_types=1);

/**
 * The list of children who must not be in the photos, where photos are chosen.
 *
 * Consent was collected, indexed and filterable, and nothing put it in front
 * of the person picking a hero image. Needs WordPress: the names come out of
 * stored registrations and the capability check is WordPress's.
 */
final class PhotoConsentNoticeTest extends CJW_IntegrationTestCase
{
    private CJW_Wizard_Submission_Repository $repository;

    private int $wizardId = 0;

    public function set_up(): void
    {
        parent::set_up();

        $this->repository = new CJW_Wizard_Submission_Repository();
        $this->wizardId = self::factory()->post->create([ 'post_type' => 'cjw_wizard' ]);
    }

    /**
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
                                [ 'key' => 'fotos', 'type' => 'radio', 'label' => 'Foto’s', 'role' => 'photo_consent' ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<int, array{voornaam: string, fotos: string}> $children
     */
    private function register(array $children): int
    {
        return $this->repository->save($this->wizardId, $this->config(), [ 'kinderen' => $children ]);
    }

    public function testNothingIsSaidWhenNobodyHasRegistered(): void
    {
        $this->actAsAdministrator();

        $this->assertSame('', CJW_Photo_Consent_Notice::render());
    }

    public function testTheChildrenWithoutConsentAreNamed(): void
    {
        $this->actAsAdministrator();
        $this->register([
            [ 'voornaam' => 'Fleur', 'fotos' => 'nee' ],
            [ 'voornaam' => 'Sem', 'fotos' => 'ja' ],
        ]);
        $this->register([ [ 'voornaam' => 'Noor', 'fotos' => 'nee' ] ]);

        $html = CJW_Photo_Consent_Notice::render();

        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('2 van de 3 kinderen', $html);
        $this->assertStringContainsString('Fleur', $html);
        $this->assertStringContainsString('Noor', $html);
        $this->assertStringNotContainsString('Sem', $html);
    }

    public function testEverybodyConsentingIsSaidQuietly(): void
    {
        $this->actAsAdministrator();
        $this->register([ [ 'voornaam' => 'Sem', 'fotos' => 'ja' ] ]);

        $html = CJW_Photo_Consent_Notice::render();

        $this->assertStringNotContainsString('notice-warning', $html);
        $this->assertStringContainsString('heeft fototoestemming', $html);
    }

    /**
     * After the retention sweep the count survives and the name does not; the
     * notice says so instead of silently listing fewer names than it counts.
     */
    public function testAnAnonymisedChildIsCountedButNoLongerNamed(): void
    {
        $this->actAsAdministrator();
        $this->register([ [ 'voornaam' => 'Fleur', 'fotos' => 'nee' ] ]);
        $anonymised = $this->register([ [ 'voornaam' => 'Noor', 'fotos' => 'nee' ] ]);

        $this->repository->anonymise($anonymised);
        wp_cache_flush();

        $html = CJW_Photo_Consent_Notice::render();

        $this->assertStringContainsString('2 van de 2 kinderen', $html);
        $this->assertStringContainsString('Fleur', $html);
        $this->assertStringNotContainsString('Noor', $html);
        $this->assertStringContainsString('Van 1 kind is de naam al gewist', $html);
    }

    /**
     * Names are children's data. Somebody on the settings screen without the
     * camp capability gets the count and nothing more.
     */
    public function testWithoutTheCampCapabilityOnlyTheCountIsShown(): void
    {
        $this->register([ [ 'voornaam' => 'Fleur', 'fotos' => 'nee' ] ]);
        $this->actAsSubscriber();

        $html = CJW_Photo_Consent_Notice::render();

        $this->assertStringContainsString('1 van de 1 kinderen', $html);
        $this->assertStringNotContainsString('Fleur', $html);
    }

    public function testTheRepositoryListsOnlyTheChildrenWhoSaidNo(): void
    {
        $this->register([
            [ 'voornaam' => 'Fleur', 'fotos' => 'nee' ],
            [ 'voornaam' => 'Sem', 'fotos' => 'ja' ],
        ]);

        $this->assertSame([ 'Fleur' ], $this->repository->childrenWithoutConsent());
    }
}
