<?php

declare(strict_types=1);

/**
 * Camp data used to sit behind `edit_posts`, which Contributor and Author hold
 * by default. These assertions pin the replacement map so a future edit cannot
 * quietly widen it again.
 */
final class RolesTest extends CJW_TestCase
{
    public function testTheCampCapabilityIsNotAStockWordPressOne(): void
    {
        $stock = [ 'edit_posts', 'edit_pages', 'publish_posts', 'manage_options', 'read' ];

        $this->assertNotContains(
            CJW_Roles::CAPABILITY,
            $stock,
            'Camp data must be gated by a capability no stock role holds by accident.'
        );
        $this->assertStringStartsWith('cjw_', CJW_Roles::CAPABILITY);
    }

    public function testEveryPostTypeCapabilityMapsOntoTheCampCapability(): void
    {
        $map = CJW_Roles::postTypeCapabilities();

        $this->assertNotSame([], $map);

        foreach ($map as $capability => $mapped) {
            $this->assertSame(
                CJW_Roles::CAPABILITY,
                $mapped,
                "Capability '{$capability}' must map onto the camp capability, not a stock one."
            );
        }
    }

    public function testTheMapCoversThePrimitivesThatWouldOtherwiseFallBackToEditPosts(): void
    {
        $map = CJW_Roles::postTypeCapabilities();

        // If any of these is missing, register_post_type() falls back to the
        // 'post' default for it -- which is the exact hole being closed.
        foreach (
            [
                'edit_posts', 'edit_others_posts', 'publish_posts',
                'read_private_posts', 'delete_posts', 'delete_others_posts',
                'delete_private_posts', 'delete_published_posts',
                'edit_private_posts', 'edit_published_posts', 'create_posts',
            ] as $capability
        ) {
            $this->assertArrayHasKey($capability, $map, "'{$capability}' would fall back to the stock post capability.");
        }
    }

    /**
     * Regression guard for a bug that made every camp gate deny everyone,
     * administrators included.
     *
     * register_post_type() hands a post type's meta capabilities to
     * _post_type_meta_capabilities(), which records each custom name in the
     * global $post_type_meta_caps. Listing the camp capability there declares it
     * to be a meta capability, so a bare current_user_can('cjw_manage_camp') is
     * re-mapped to `edit_post` with no post id -- and map_meta_cap() answers
     * that with `do_not_allow`.
     */
    public function testMetaCapabilitiesAreNotMappedOntoTheCampCapability(): void
    {
        $map = CJW_Roles::postTypeCapabilities();

        foreach ([ 'edit_post', 'read_post', 'delete_post' ] as $metaCapability) {
            $this->assertArrayNotHasKey(
                $metaCapability,
                $map,
                "Mapping '{$metaCapability}' would register the camp capability as a meta capability, "
                . 'and every current_user_can() check on it would then return false.'
            );
        }
    }

    public function testTheRoleSlugIsNamespaced(): void
    {
        $this->assertStringStartsWith('cjw_', CJW_Roles::ROLE);
    }
}
