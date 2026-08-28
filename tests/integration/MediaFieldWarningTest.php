<?php

declare(strict_types=1);

/**
 * The size warning on the media pickers.
 *
 * Needs a real attachment: the warning is decided from the file on disk and the
 * metadata WordPress generated for it, neither of which a stand-in has.
 */
final class MediaFieldWarningTest extends CJW_IntegrationTestCase
{
    /**
     * @var array<int, string>
     */
    private array $files = [];

    public function tear_down(): void
    {
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $this->files = [];

        parent::tear_down();
    }

    /**
     * An attachment of a given weight and width, with a file actually on disk.
     */
    private function attachment(int $bytes, int $width): int
    {
        $uploads = wp_upload_dir();
        $path = trailingslashit($uploads['path']) . 'cjw-test-' . $bytes . '-' . $width . '.jpg';

        wp_mkdir_p($uploads['path']);
        file_put_contents($path, str_repeat('x', $bytes));

        $this->files[] = $path;

        $id = self::factory()->attachment->create_object([
            'file' => $path,
            'post_mime_type' => 'image/jpeg',
        ]);

        wp_update_attachment_metadata($id, [
            'width' => $width,
            'height' => (int) round($width * 0.5625),
            'file' => $path,
        ]);

        return $id;
    }

    private function heroField(): CJW_Media_Field
    {
        return new CJW_Media_Field(
            'hero_image_id',
            'Hero',
            CJW_Summer_Camp_Service::OPTION_KEY,
            '',
            recommendedWidth: 2000,
            recommendedHeight: 1125,
            maxBytes: 800 * KB_IN_BYTES
        );
    }

    public function testAnImageWithinBudgetSaysNothing(): void
    {
        $id = $this->attachment(200 * KB_IN_BYTES, 2000);

        $this->assertSame('', $this->heroField()->warning($id));
    }

    public function testAPhotoStraightOffAPhoneIsCalledOut(): void
    {
        $id = $this->attachment(3 * MB_IN_BYTES, 4032);

        $warning = $this->heroField()->warning($id);

        // The separator follows the site locale -- a comma on the Dutch site,
        // a point on the English test install -- so only the number is pinned.
        $this->assertMatchesRegularExpression('/3[.,]0 MB/', $warning);
        $this->assertStringContainsString('telefoon', $warning);
    }

    /**
     * The recommended size belongs in the message. In a document it is a
     * document nobody opens.
     */
    public function testTheWarningCarriesTheSizeToAimFor(): void
    {
        $id = $this->attachment(3 * MB_IN_BYTES, 4032);

        $warning = $this->heroField()->warning($id);

        $this->assertStringContainsString('2000', $warning);
        $this->assertStringContainsString('1125', $warning);
        $this->assertStringContainsString('800', $warning);
    }

    /**
     * A well-compressed 6000-pixel JPEG is still the wrong file to hand a
     * browser, however small it happens to be.
     */
    public function testAnEnormousButLightImageIsStillCalledOut(): void
    {
        $id = $this->attachment(100 * KB_IN_BYTES, 6000);

        $warning = $this->heroField()->warning($id);

        $this->assertStringContainsString('6000 pixels breed', $warning);
    }

    /**
     * Somewhat larger than displayed is fine and often deliberate: it gives
     * WordPress room to generate the sizes it actually serves.
     */
    public function testABitLargerThanNeededIsLeftAlone(): void
    {
        $id = $this->attachment(200 * KB_IN_BYTES, 2800);

        $this->assertSame('', $this->heroField()->warning($id));
    }

    public function testNothingIsSaidWhenNoImageIsChosen(): void
    {
        $this->assertSame('', $this->heroField()->warning(0));
    }

    public function testAFieldWithNoRecommendationStillWarnsAboutWeight(): void
    {
        $id = $this->attachment(2 * MB_IN_BYTES, 1000);

        $field = new CJW_Media_Field('x', 'X', CJW_Summer_Camp_Service::OPTION_KEY);
        $warning = $field->warning($id);

        $this->assertMatchesRegularExpression('/2[.,]0 MB/', $warning);
        $this->assertStringNotContainsString('pixels', $warning);
    }

    /**
     * The polaroid budget is the theme's registered cjw-polaroid crop. If those
     * two ever disagree, the advice on the screen is wrong.
     */
    public function testThePolaroidAdviceMatchesTheSizeTheThemeServes(): void
    {
        $registered = wp_get_additional_image_sizes()['cjw-polaroid'] ?? null;

        if ($registered === null) {
            $this->markTestSkipped('The theme registering cjw-polaroid is not active in this run.');
        }

        $id = $this->attachment(2 * MB_IN_BYTES, 4000);

        $field = new CJW_Media_Field(
            'polaroid_1_image_id',
            'Foto 1',
            CJW_Summer_Camp_Service::OPTION_KEY,
            '',
            recommendedWidth: 900,
            recommendedHeight: 675,
            maxBytes: 300 * KB_IN_BYTES
        );

        $this->assertSame(900, $registered['width']);
        $this->assertSame(675, $registered['height']);
        $this->assertStringContainsString('900 bij 675', $field->warning($id));
    }
}
