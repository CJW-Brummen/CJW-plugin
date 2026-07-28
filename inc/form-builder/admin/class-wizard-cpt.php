<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class CJW_Wizard_CPT
{
    private CJW_Wizard_Post_Type $post_type;
    private CJW_Wizard_Meta_Boxes $meta_boxes;
    private CJW_Wizard_Admin_Assets $assets;

    public function __construct()
    {
        $settings = new CJW_Wizard_Settings();

        $this->post_type = new CJW_Wizard_Post_Type();
        $this->meta_boxes = new CJW_Wizard_Meta_Boxes($settings);
        $this->assets = new CJW_Wizard_Admin_Assets();
    }

    public function init(): void
    {
        $this->post_type->init();
        $this->meta_boxes->init();
        $this->assets->init();
    }
}
