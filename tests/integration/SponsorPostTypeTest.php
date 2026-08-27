<?php

declare(strict_types=1);

/**
 * The sponsor post type, now that the plugin registers it.
 *
 * It used to be configured in the Advanced Custom Fields admin: stored in the
 * database, present in no file, and absent from any fresh install. The front
 * page answered that by rendering nothing at all -- the sponsor wall simply
 * vanished, without a word.
 */
final class SponsorPostTypeTest extends CJW_IntegrationTestCase
{
    public function testThePluginRegistersIt(): void
    {
        $type = get_post_type_object(CJW_Summer_Camp_Sponsor_Post_Type::POST_TYPE);

        $this->assertInstanceOf(WP_Post_Type::class, $type);
        $this->assertSame('Sponsoren', $type->labels->name);
    }

    public function testItSupportsWhatTheSponsorWallNeeds(): void
    {
        $type = CJW_Summer_Camp_Sponsor_Post_Type::POST_TYPE;

        // A logo, a name, and an order the organiser controls.
        $this->assertTrue(post_type_supports($type, 'thumbnail'));
        $this->assertTrue(post_type_supports($type, 'title'));
        $this->assertTrue(post_type_supports($type, 'page-attributes'));
    }

    public function testTheLinkFieldIsOnTheEditor(): void
    {
        $GLOBALS['wp_meta_boxes'] = [];
        $this->actAsAdministrator();

        $post = self::factory()->post->create_and_get([
            'post_type' => CJW_Summer_Camp_Sponsor_Post_Type::POST_TYPE,
        ]);

        do_action('add_meta_boxes', CJW_Summer_Camp_Sponsor_Post_Type::POST_TYPE, $post);

        $this->assertArrayHasKey(
            'cjw_sponsor_link',
            $GLOBALS['wp_meta_boxes'][ CJW_Summer_Camp_Sponsor_Post_Type::POST_TYPE ]['normal']['high'] ?? []
        );
    }

    public function testItReadsAndWritesTheSameMetaKeyAcfUsed(): void
    {
        $this->actAsAdministrator();

        $id = self::factory()->post->create([
            'post_type' => CJW_Summer_Camp_Sponsor_Post_Type::POST_TYPE,
        ]);

        // The ten sponsors already on the site keep working precisely because
        // the key did not change: ACF was never storing this specially.
        update_post_meta($id, CJW_Summer_Camp_Sponsor_Post_Type::META_LINK, 'https://voorbeeld.test/');

        ob_start();
        (new CJW_Summer_Camp_Sponsor_Post_Type())->renderBox(get_post($id));
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('value="https://voorbeeld.test/"', $html);
    }

    public function testAnUnsignedSaveIsIgnored(): void
    {
        $this->actAsAdministrator();

        $id = self::factory()->post->create([
            'post_type' => CJW_Summer_Camp_Sponsor_Post_Type::POST_TYPE,
        ]);
        update_post_meta($id, CJW_Summer_Camp_Sponsor_Post_Type::META_LINK, 'https://keep.test/');

        $_POST = [ CJW_Summer_Camp_Sponsor_Post_Type::META_LINK => 'https://attacker.test/' ];

        (new CJW_Summer_Camp_Sponsor_Post_Type())->save($id, get_post($id));

        $this->assertSame(
            'https://keep.test/',
            get_post_meta($id, CJW_Summer_Camp_Sponsor_Post_Type::META_LINK, true)
        );
    }

    public function testAValidSaveStoresTheLinkAndAnEmptyOneRemovesIt(): void
    {
        $this->actAsAdministrator();

        $id = self::factory()->post->create([
            'post_type' => CJW_Summer_Camp_Sponsor_Post_Type::POST_TYPE,
        ]);

        $_POST = [
            CJW_Summer_Camp_Sponsor_Post_Type::META_LINK => 'https://sponsor.test/',
            'cjw_sponsor_link_nonce' => wp_create_nonce('cjw_sponsor_link'),
        ];

        (new CJW_Summer_Camp_Sponsor_Post_Type())->save($id, get_post($id));

        $this->assertSame(
            'https://sponsor.test/',
            get_post_meta($id, CJW_Summer_Camp_Sponsor_Post_Type::META_LINK, true)
        );

        $_POST[ CJW_Summer_Camp_Sponsor_Post_Type::META_LINK ] = '';

        (new CJW_Summer_Camp_Sponsor_Post_Type())->save($id, get_post($id));

        // Cleared rather than stored empty, so the logo renders unlinked.
        $this->assertSame('', get_post_meta($id, CJW_Summer_Camp_Sponsor_Post_Type::META_LINK, true));
    }
}
