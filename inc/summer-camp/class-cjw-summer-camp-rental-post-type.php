<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The rental inventory: one post per item CJW hires out.
 *
 * The live site lists all of it as prose -- a name, a sentence, a dimension
 * string -- which means the structure is real but invisible. Every item
 * genuinely has a quantity, a capacity, a size and a note, and none of it was
 * marked up as such, so nothing could be sorted, compared or added up, and
 * "20x" became "18x" only by someone remembering to retype a paragraph.
 *
 * Here each item is a record. The theme renders it three ways -- cards with a
 * to-scale footprint, one specification table, and the "Past het?" helper --
 * all from the same fields, so the three can never disagree with each other.
 *
 * Deliberately not stored: prices. CJW quotes them per enquiry
 * ("Voor prijzen en beschikbaarheid kunt u contact opnemen met..."), and a
 * field nobody fills in is worse than no field at all.
 */
final class CJW_Summer_Camp_Rental_Post_Type
{
    public const string POST_TYPE = 'verhuurmateriaal';

    /**
     * Which kind of thing this is. Drives the theme's grouping, and decides
     * what a size means: a tent has a footprint, a stack of mattresses does not.
     */
    public const string META_CATEGORY = 'cjw_verhuur_categorie';

    /**
     * How many CJW owns. 0 means "op aanvraag" -- the site says so rather than
     * printing "0x beschikbaar", which is what the live page's vague
     * "diverse groepstenten" line really means.
     */
    public const string META_COUNT = 'cjw_verhuur_aantal';

    /**
     * People per unit. The lower bound when META_CAPACITY_MAX is also set.
     */
    public const string META_CAPACITY = 'cjw_verhuur_capaciteit';

    /**
     * Upper bound of a capacity range, for "8 tot 10 personen". 0 = no range.
     */
    public const string META_CAPACITY_MAX = 'cjw_verhuur_capaciteit_tot';

    public const string META_LENGTH = 'cjw_verhuur_lengte';
    public const string META_WIDTH = 'cjw_verhuur_breedte';
    public const string META_HEIGHT_FRONT = 'cjw_verhuur_hoogte_voor';
    public const string META_HEIGHT_MIDDLE = 'cjw_verhuur_hoogte_midden';

    /**
     * The one line under the item on the site. Kept as its own field rather
     * than the excerpt: this post type has no editor, and an excerpt box on a
     * screen with no editor above it is a box nobody finds.
     */
    public const string META_NOTE = 'cjw_verhuur_toelichting';

    private const string NONCE = 'cjw_verhuur_nonce';

    /**
     * Registers the post type and its editing screen.
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();

        add_action('init', [$instance, 'register']);
        add_action('add_meta_boxes', [$instance, 'registerBox']);
        add_action('save_post_' . self::POST_TYPE, [$instance, 'save'], 10, 2);
    }

    /**
     * The categories an item can be in, as slug => label.
     *
     * These are not decoration: each one decides what a total means. Group
     * tents add up to sleeping places (aantal x capaciteit), party tents to
     * square metres of cover (aantal x grondvlak), and slaapmateriaal to a
     * simple count -- which is what lets the site say "208 plaatsen, 130
     * matrassen" without anyone typing either number. Anything whose total
     * would be meaningless goes in "overig" and is listed but not summed.
     *
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            'groepstent' => __('Groepstent', 'cjw'),
            'partytent' => __('Partytent', 'cjw'),
            'slaapmateriaal' => __('Slaapmateriaal', 'cjw'),
            'overig' => __('Overig materiaal', 'cjw'),
        ];
    }

    /**
     * Registers the post type.
     *
     * Not public, unlike the sponsor: a rental item has no page of its own
     * worth visiting. Everything about it is shown on the verhuurpagina, and a
     * single-item permalink would render a title and nothing else.
     *
     * @return void
     */
    public function register(): void
    {
        register_post_type(
            self::POST_TYPE,
            [
                'labels' => [
                    'name' => __('Verhuurmateriaal', 'cjw'),
                    'singular_name' => __('Verhuurmateriaal', 'cjw'),
                    'add_new_item' => __('Nieuw verhuurmateriaal', 'cjw'),
                    'edit_item' => __('Verhuurmateriaal bewerken', 'cjw'),
                    'search_items' => __('Verhuurmateriaal zoeken', 'cjw'),
                    'not_found' => __('Geen verhuurmateriaal gevonden.', 'cjw'),
                    'not_found_in_trash' => __('Geen verhuurmateriaal in de prullenbak.', 'cjw'),
                ],
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => true,
                'publicly_queryable' => false,
                'exclude_from_search' => true,
                'menu_position' => 27,
                'menu_icon' => 'dashicons-shield-alt',
                'has_archive' => false,
                // No editor: every field this item has is on the screen below,
                // and a rich text area would only invite a second, contradictory
                // description of the same tent.
                'supports' => ['title', 'thumbnail', 'page-attributes'],
                'show_in_rest' => false,
                // Gated on the camp capability rather than on edit_posts. How
                // many tents CJW owns and which are out on loan is camp
                // operations, not website copy, so it belongs to Kampbeheerder
                // and not to Leiding -- who would otherwise reach it through
                // the same edit_posts that lets them write a page.
                'capabilities' => CJW_Roles::postTypeCapabilities(),
                'map_meta_cap' => true,
            ]
        );
    }

    /**
     * Adds the single editing box to the item screen.
     *
     * @param string $post_type Post type being edited.
     * @return void
     */
    public function registerBox(string $post_type = ''): void
    {
        if ($post_type !== self::POST_TYPE) {
            return;
        }

        add_meta_box(
            'cjw_verhuur',
            __('Gegevens van dit materiaal', 'cjw'),
            [$this, 'renderBox'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    /**
     * Reads one integer field off an item.
     *
     * @param int    $post_id Item id.
     * @param string $key     One of the META_* constants.
     * @return int
     */
    public static function number(int $post_id, string $key): int
    {
        $value = get_post_meta($post_id, $key, true);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Reads the category off an item, falling back to "overig".
     *
     * @param int $post_id Item id.
     * @return string
     */
    public static function category(int $post_id): string
    {
        $value = get_post_meta($post_id, self::META_CATEGORY, true);
        $value = is_string($value) ? $value : '';

        return isset(self::categories()[$value]) ? $value : 'overig';
    }

    /**
     * Reads the one-line note off an item.
     *
     * @param int $post_id Item id.
     * @return string
     */
    public static function note(int $post_id): string
    {
        $value = get_post_meta($post_id, self::META_NOTE, true);

        return is_string($value) ? $value : '';
    }

    /**
     * The editing screen: what it is, how many, how big, and one line about it.
     *
     * @param WP_Post $post Item being edited.
     * @return void
     */
    public function renderBox(WP_Post $post): void
    {
        wp_nonce_field('cjw_verhuur', self::NONCE);

        $post_id = (int) $post->ID;
        $category = self::category($post_id);

        echo '<style>.cjw-verhuur th{width:15em;text-align:left}.cjw-verhuur .small{width:7em}</style>';

        echo '<table class="form-table cjw-verhuur" role="presentation"><tbody>';

        // --- what it is -------------------------------------------------
        echo '<tr><th scope="row"><label for="cjw_verhuur_categorie">';
        esc_html_e('Soort', 'cjw');
        echo '</label></th><td>';
        printf('<select id="cjw_verhuur_categorie" name="%s">', esc_attr(self::META_CATEGORY));
        foreach (self::categories() as $slug => $label) {
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr($slug),
                selected($category, $slug, false),
                esc_html($label)
            );
        }
        echo '</select>';
        echo '<p class="description">';
        esc_html_e('Groepstenten met een aantal én een capaciteit tellen mee in de "Past het?"-hulp op de verhuurpagina.', 'cjw');
        echo '</p></td></tr>';

        // --- how many ---------------------------------------------------
        $this->numberRow(
            self::META_COUNT,
            __('Aantal', 'cjw'),
            self::number($post_id, self::META_COUNT),
            __('Hoeveel CJW er heeft. Laat 0 staan voor "op aanvraag".', 'cjw')
        );

        echo '<tr><th scope="row"><label for="' . esc_attr(self::META_CAPACITY) . '">';
        esc_html_e('Voor hoeveel personen', 'cjw');
        echo '</label></th><td>';
        $capacity_from = (string) self::number($post_id, self::META_CAPACITY);
        printf(
            '<input type="number" min="0" step="1" class="small" id="%1$s" name="%1$s" value="%2$s"> ',
            esc_attr(self::META_CAPACITY),
            esc_attr($capacity_from)
        );
        echo '<label for="' . esc_attr(self::META_CAPACITY_MAX) . '">' . esc_html__('tot', 'cjw') . '</label> ';
        $capacity_to = (string) self::number($post_id, self::META_CAPACITY_MAX);
        printf(
            '<input type="number" min="0" step="1" class="small" id="%1$s" name="%1$s" value="%2$s">',
            esc_attr(self::META_CAPACITY_MAX),
            esc_attr($capacity_to)
        );
        echo '<p class="description">';
        esc_html_e('Per stuk. Vul het tweede veld alleen in bij een bereik, zoals 8 tot 10 personen. Laat beide op 0 als personen niet van toepassing zijn.', 'cjw');
        echo '</p></td></tr>';

        // --- how big ----------------------------------------------------
        echo '<tr><th scope="row">' . esc_html__('Afmetingen', 'cjw') . '</th><td>';
        foreach (
            [
                self::META_LENGTH => __('lengte', 'cjw'),
                self::META_WIDTH => __('breedte', 'cjw'),
                self::META_HEIGHT_FRONT => __('hoogte voor', 'cjw'),
                self::META_HEIGHT_MIDDLE => __('hoogte midden', 'cjw'),
            ] as $key => $label
        ) {
            $size = (string) self::number($post_id, $key);
            printf(
                '<label for="%1$s" style="display:inline-block;margin:0 14px 6px 0">%2$s '
                . '<input type="number" min="0" step="1" class="small" id="%1$s" name="%1$s" value="%3$s"> cm</label>',
                esc_attr($key),
                esc_html($label),
                esc_attr($size)
            );
        }
        echo '<p class="description">';
        esc_html_e('In centimeters. Lengte en breedte tekenen de plattegrond op schaal; laat ze op 0 bij materiaal zonder grondvlak, zoals matrassen.', 'cjw');
        echo '</p></td></tr>';

        // --- the one line ------------------------------------------------
        echo '<tr><th scope="row"><label for="' . esc_attr(self::META_NOTE) . '">';
        esc_html_e('Toelichting', 'cjw');
        echo '</label></th><td>';
        printf(
            '<textarea id="%1$s" name="%1$s" rows="2" class="large-text">%2$s</textarea>',
            esc_attr(self::META_NOTE),
            esc_textarea(self::note($post_id))
        );
        echo '<p class="description">';
        esc_html_e('Eén regel onder het materiaal, bijvoorbeeld "Groepstent met vast grondzeil".', 'cjw');
        echo '</p></td></tr>';

        echo '</tbody></table>';

        echo '<p class="description">';
        esc_html_e('Prijzen staan bewust niet op de site: die gaan per aanvraag. Zet het contactadres in de tekst van de verhuurpagina zelf.', 'cjw');
        echo '</p>';
    }

    /**
     * One labelled number field with a description.
     *
     * @param string $key         Meta key, used as id and name.
     * @param string $label       Field label.
     * @param int    $value       Current value.
     * @param string $description Help text under the field.
     * @return void
     */
    private function numberRow(string $key, string $label, int $value, string $description): void
    {
        printf(
            '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>'
            . '<input type="number" min="0" step="1" class="small" id="%1$s" name="%1$s" value="%3$s">'
            . '<p class="description">%4$s</p></td></tr>',
            esc_attr($key),
            esc_html($label),
            esc_attr((string) $value),
            esc_html($description)
        );
    }

    /**
     * Stores the item's fields.
     *
     * @param int     $post_id Item id.
     * @param WP_Post $post    Item.
     * @return void
     */
    public function save(int $post_id, WP_Post $post): void
    {
        unset($post);

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $nonce = isset($_POST[self::NONCE])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE]))
            : '';

        if ($nonce === '' || !wp_verify_nonce($nonce, 'cjw_verhuur')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        foreach (
            [
                self::META_COUNT,
                self::META_CAPACITY,
                self::META_CAPACITY_MAX,
                self::META_LENGTH,
                self::META_WIDTH,
                self::META_HEIGHT_FRONT,
                self::META_HEIGHT_MIDDLE,
            ] as $key
        ) {
            $raw = isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
            $value = is_numeric($raw) ? max(0, (int) $raw) : 0;

            if ($value === 0) {
                delete_post_meta($post_id, $key);

                continue;
            }

            update_post_meta($post_id, $key, $value);
        }

        $category = isset($_POST[self::META_CATEGORY])
            ? sanitize_key(wp_unslash((string) $_POST[self::META_CATEGORY]))
            : '';

        if (isset(self::categories()[$category])) {
            update_post_meta($post_id, self::META_CATEGORY, $category);
        } else {
            delete_post_meta($post_id, self::META_CATEGORY);
        }

        $note = isset($_POST[self::META_NOTE])
            ? sanitize_textarea_field(wp_unslash((string) $_POST[self::META_NOTE]))
            : '';

        if ($note === '') {
            delete_post_meta($post_id, self::META_NOTE);

            return;
        }

        update_post_meta($post_id, self::META_NOTE, $note);
    }
}
