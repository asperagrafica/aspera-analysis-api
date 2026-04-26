<?php
/**
 * Plugin Name: AsperAi Site Tools
 * Description: Server-side site-audit en herstel-acties voor Aspera-websites. Read-only REST-endpoints voor analyse (WPBakery, ACF, headers, kleuren, navigatie, widgets, cache, theme-instellingen, site-health) plus deterministische fix-acties via wp-admin (orphaned meta, scheduled actions, shortcode-correcties).
 * Version: 1.93.2
 * Author: Aspera
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Plugin Update Checker ────────────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$aspera_updater = PucFactory::buildUpdateChecker(
    'https://github.com/asperagrafica/aspera-analysis-api/',
    __FILE__,
    'aspera-analysis-api'
);
$aspera_updater->setAuthentication( base64_decode( 'Z2l0aHViX3BhdF8xMUNBRUY3NkkwTkx3bW9jQUFyTjlLX0lsWkRraVpKaFN2enkySERtaTNmYjdjTWxuRWRrd0R2TUZteHhIZ05DdWJEWTVUVE1ITzNRMmh1eFZu' ) );
$aspera_updater->setBranch( 'main' );
// ─────────────────────────────────────────────────────────────────────────────

// ─── Secret key: genereer als die nog niet bestaat (activation + runtime) ────
register_activation_hook( __FILE__, 'aspera_ensure_secret_key' );
add_action( 'admin_init', 'aspera_ensure_secret_key' );
function aspera_ensure_secret_key(): void {
    // Als ASPERA_SECRET_KEY constant in wp-config.php staat: geen DB-fallback nodig.
    if ( defined( 'ASPERA_SECRET_KEY' ) && ASPERA_SECRET_KEY ) {
        return;
    }
    if ( ! get_option( 'aspera_secret_key' ) ) {
        update_option( 'aspera_secret_key', wp_generate_password( 48, false ), false );
    }
}
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Strips only empty strings and empty arrays recursively.
 * Preserves all theme-specific values including animation, color,
 * visibility, and hide_on_states — these are workflow-relevant.
 */
function aspera_strip_empty( array $data ): array {
    $result = [];
    foreach ( $data as $key => $value ) {
        if ( $value === '' || $value === [] ) continue;
        if ( is_array( $value ) ) {
            $value = aspera_strip_empty( $value );
            if ( $value === [] ) continue;
        }
        $result[ $key ] = $value;
    }
    return $result;
}

/**
/**
 * Geeft het ACF veldtype terug voor een gegeven veldslug.
 * Bouwt eenmalig per request een slug→type map op basis van acf-field posts.
 * Geeft null terug als de slug niet gevonden wordt.
 */
function aspera_acf_field_type( string $slug ): ?string {
    static $map = null;
    if ( $map === null ) {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT post_excerpt AS slug, post_content AS content
             FROM {$wpdb->posts}
             WHERE post_type = 'acf-field' AND post_status = 'publish'"
        );
        $map = [];
        foreach ( $rows as $row ) {
            if ( $row->slug === '' || isset( $map[ $row->slug ] ) ) continue;
            $data = maybe_unserialize( $row->content );
            if ( is_array( $data ) && isset( $data['type'] ) ) {
                $map[ $row->slug ] = $data['type'];
            }
        }
    }
    return $map[ $slug ] ?? null;
}

/**
 * Valideert de WPBakery shortcodes van één post op beleidsschendingen.
 * Geeft een array terug met 'violations' en 'shortcode_count'.
 * Wordt gebruikt door zowel /wpb/validate/{id} als /wpb/validate/all.
 */
function aspera_wpb_validate_post( WP_Post $post ): array {

    $content   = get_post_field( 'post_content', $post->ID, 'raw' );
    $post_type = $post->post_type;

    // Hardcoded tekst alleen controleren in template post types
    $check_text = in_array( $post_type, [ 'us_content_template', 'us_page_block' ], true );

    preg_match_all( '/\[(\w+)((?:"[^"]*"|\'[^\']*\'|[^\]])*)\]/', $content, $matches, PREG_SET_ORDER );

    // Pre-pass: tel siblings per container voor us_page_block parent_row controle.
    $container_tags_list = [ 'vc_row', 'vc_column', 'vc_row_inner', 'vc_column_inner' ];
    $cstack              = [];
    $pb_sibling          = [];

    preg_match_all( '/\[(\/?[\w]+)((?:"[^"]*"|\'[^\']*\'|[^\]])*)\]/', $content, $all_sc, PREG_SET_ORDER );

    foreach ( $all_sc as $sc ) {
        $raw_tag    = $sc[1];
        $sc_attrs   = trim( $sc[2] );
        $is_closing = ( $raw_tag[0] === '/' );
        $clean_tag  = $is_closing ? substr( $raw_tag, 1 ) : $raw_tag;

        if ( $is_closing && in_array( $clean_tag, $container_tags_list, true ) ) {
            if ( ! empty( $cstack ) ) {
                $finished = array_pop( $cstack );
                foreach ( $finished['pb_attrs'] as $pb_key ) {
                    $pb_sibling[ $pb_key ] = $finished['count'];
                }
            }
        } elseif ( ! $is_closing && in_array( $clean_tag, $container_tags_list, true ) ) {
            $cstack[] = [ 'count' => 0, 'pb_attrs' => [] ];
        } elseif ( ! $is_closing && ! empty( $cstack ) ) {
            $top = count( $cstack ) - 1;
            $cstack[ $top ]['count']++;
            if ( $clean_tag === 'us_page_block' ) {
                $cstack[ $top ]['pb_attrs'][] = $sc_attrs;
            }
        }
    }

    $violations = [];

    // Pre-pass: vc_row_inner columns_reverse controle.
    // Regel: eerste kolom bevat afbeelding → columns_reverse="1" verplicht.
    //        eerste kolom bevat geen afbeelding → columns_reverse mag niet voorkomen.
    preg_match_all( '/\[vc_row_inner([^\]]*)\](.*?)\[\/vc_row_inner\]/s', $content, $ri_blocks, PREG_SET_ORDER );
    foreach ( $ri_blocks as $ri ) {
        $ri_attrs  = $ri[1];
        $ri_body   = $ri[2];
        $cr        = (bool) preg_match( '/\bcolumns_reverse="1"/', $ri_attrs );
        $snippet   = '[vc_row_inner' . substr( trim( $ri_attrs ), 0, 60 ) . '…]';

        $ri_pos    = strpos( $content, $ri[0] );
        $ri_row    = preg_match_all( '/\[vc_row[\s\]]/', substr( $content, 0, $ri_pos ) );
        $ri_el_id  = '';
        if ( preg_match( '/\bel_id="([^"]*)"/', $ri_attrs, $_reid ) ) {
            $ri_el_id = $_reid[1];
        }
        $ri_location = [
            'row'        => $ri_row,
            'el_id'      => $ri_el_id,
            'column'     => 0,
            'position'   => 0,
            'breadcrumb' => 'Rij ' . $ri_row . ' → Inner rij' . ( $ri_el_id !== '' ? ' (' . $ri_el_id . ')' : '' ),
        ];

        if ( ! preg_match( '/\[vc_column_inner[^\]]*\](.*?)(?=\[vc_column_inner|\[\/vc_row_inner\])/s', $ri_body, $first_col ) ) {
            continue;
        }
        // Controleer alleen het EERSTE inhoudelijke element van col1.
        // columns_reverse is bedoeld voor kolommen waarbij de afbeelding als eerste
        // element staat — niet voor kolommen waarbij tekst eerst staat en een afbeelding
        // verder naar beneden voorkomt.
        $first_has_image = false;
        if ( preg_match( '/\[(us_image|us_post_custom_field|us_text|us_btn|vc_video)\b([^\]]*)\]/', $first_col[1], $first_el ) ) {
            $first_el_tag   = $first_el[1];
            $first_el_attrs = $first_el[2];
            if ( $first_el_tag === 'us_image' ) {
                $first_has_image = true;
            } elseif ( $first_el_tag === 'us_post_custom_field' ) {
                preg_match( '/\bkey="([^"]+)"/', $first_el_attrs, $key_match );
                $first_key = $key_match[1] ?? '';
                if ( $first_key !== '' && aspera_acf_field_type( $first_key ) === 'image' ) {
                    $first_has_image = true;
                }
            }
        }

        if ( $first_has_image && ! $cr ) {
            $violations[] = [
                'tag'      => 'vc_row_inner',
                'rule'     => 'missing_columns_reverse',
                'detail'   => 'Eerste kolom bevat een afbeelding maar columns_reverse="1" ontbreekt — op mobiel verschijnt de afbeelding boven de tekst',
                'snippet'  => $snippet,
                'location' => $ri_location,
            ];
        } elseif ( ! $first_has_image && $cr ) {
            $violations[] = [
                'tag'      => 'vc_row_inner',
                'rule'     => 'unexpected_columns_reverse',
                'detail'   => 'columns_reverse="1" aanwezig maar eerste kolom bevat geen afbeelding — op mobiel verschijnt de tekst onder de afbeelding',
                'snippet'  => $snippet,
                'location' => $ri_location,
            ];
        }
    }

    // ── Locatie-tracking voor violations ──────────────────────────────────────
    $loc_row   = 0;
    $loc_col   = 0;
    $loc_elem  = 0;
    $loc_el_id = '';
    $loc_inner = false;
    $loc_icol  = 0;

    foreach ( $matches as $m ) {
        $tag     = $m[1];
        $attrs   = $m[2];
        $full_sc = $m[0];
        $snippet = substr( trim( $attrs ), 0, 80 );

        // ── Locatie bijwerken ─────────────────────────────────────────────
        if ( $tag === 'vc_row' ) {
            $loc_row++;
            $loc_col   = 0;
            $loc_elem  = 0;
            $loc_inner = false;
            $loc_el_id = '';
            if ( preg_match( '/\bel_id="([^"]*)"/', $attrs, $_eid ) ) {
                $loc_el_id = $_eid[1];
            }
        } elseif ( $tag === 'vc_column' && ! $loc_inner ) {
            $loc_col++;
            $loc_elem = 0;
        } elseif ( $tag === 'vc_row_inner' ) {
            $loc_inner = true;
            $loc_icol  = 0;
        } elseif ( $tag === 'vc_column_inner' ) {
            $loc_icol++;
            $loc_elem = 0;
        } elseif ( ! in_array( $tag, [ 'vc_row', 'vc_column', 'vc_row_inner', 'vc_column_inner' ], true ) ) {
            $loc_elem++;
        }

        $_lc = $loc_inner ? $loc_icol : $loc_col;
        $_cb = [];
        if ( $loc_row )  $_cb[] = 'Rij ' . $loc_row . ( $loc_el_id !== '' ? ' (' . $loc_el_id . ')' : '' );
        if ( $_lc )      $_cb[] = ( $loc_inner ? 'Inner kolom ' : 'Kolom ' ) . $_lc;
        if ( $loc_elem && ! in_array( $tag, [ 'vc_row', 'vc_column', 'vc_row_inner', 'vc_column_inner' ], true ) ) {
            $_cb[] = '#' . $loc_elem;
        }
        $current_location = [
            'row' => $loc_row, 'el_id' => $loc_el_id,
            'column' => $_lc, 'position' => $loc_elem,
            'breadcrumb' => implode( ' → ', $_cb ),
        ];

        $attr = function ( string $name ) use ( $attrs ): ?string {
            return preg_match( '/\b' . $name . '="([^"]*)"/', $attrs, $v ) ? $v[1] : null;
        };

        // ─── Universele css= check (alle shortcodes) ─────────────────
        // Twee mogelijke vormen:
        //  - URL-encoded JSON `%7B%22default%22%3A%7B...%7D%7D` (Impreza Design-tab)
        //  - Legacy WPBakery `.vc_custom_xxx{...}` string
        $css_raw = $attr( 'css' );
        if ( $css_raw !== null && $css_raw !== '' ) {
            $css_decoded = json_decode( urldecode( $css_raw ), true );
            if ( is_array( $css_decoded ) ) {
                $design_props = [];
                $anim_props   = [];
                foreach ( $css_decoded as $bp => $bp_props ) {
                    if ( ! is_array( $bp_props ) ) continue;
                    foreach ( $bp_props as $prop => $val ) {
                        if ( $prop === 'aspect-ratio' ) continue;
                        if ( strpos( (string) $prop, 'animation' ) === 0 ) {
                            $anim_props[] = $bp . '.' . $prop;
                            continue;
                        }
                        $design_props[] = $bp . '.' . $prop;
                    }
                }
                if ( ! empty( $design_props ) ) {
                    $violations[] = [ 'tag' => $tag, 'rule' => 'design_css_forbidden',
                        'detail' => 'Design-tab CSS overrides: ' . implode( ', ', $design_props ),
                        'snippet' => $snippet, 'location' => $current_location ];
                }
                if ( ! empty( $anim_props ) ) {
                    $violations[] = [ 'tag' => $tag, 'rule' => 'animate_detected',
                        'detail' => 'animation-properties in Design-tab: ' . implode( ', ', $anim_props ),
                        'snippet' => $snippet, 'location' => $current_location ];
                }
            } else {
                $violations[] = [ 'tag' => $tag, 'rule' => 'css_forbidden',
                    'detail' => 'css= attribuut aanwezig (legacy WPBakery CSS-string)',
                    'snippet' => $snippet, 'location' => $current_location ];
            }
        }

        // ─── vc_row / vc_column ───────────────────────────────────────
        if ( in_array( $tag, [ 'vc_row', 'vc_column' ], true ) ) {

            if ( $tag === 'vc_row' ) {

                if ( preg_match( '/\bscroll_effect="1"/', $attrs ) ) {
                    $violations[] = [ 'tag' => $tag, 'rule' => 'scroll_effect_forbidden',
                        'detail' => 'scroll_effect="1" aanwezig', 'snippet' => $snippet,
                        'location' => $current_location ];
                }

                $bg_image = $attr( 'us_bg_image' );
                if ( $bg_image !== null && ctype_digit( $bg_image ) ) {
                    $violations[] = [ 'tag' => $tag, 'rule' => 'hardcoded_bg_image',
                        'detail' => 'us_bg_image="' . $bg_image . '" — gebruik ACF veldslug in us_bg_image_source',
                        'snippet' => $snippet,
                        'location' => $current_location ];
                }

                $bg_video = $attr( 'us_bg_video' );
                if ( $bg_video !== null && preg_match( '/^https?:/', $bg_video ) ) {
                    $violations[] = [ 'tag' => $tag, 'rule' => 'hardcoded_bg_video',
                        'detail' => 'us_bg_video="' . $bg_video . '" — gebruik {{veldslug}}',
                        'snippet' => $snippet,
                        'location' => $current_location ];
                }
            }
        }

        // ─── us_post_custom_field ─────────────────────────────────────
        if ( $tag === 'us_post_custom_field' ) {
            $key = $attr( 'key' ) ?? '';

            if ( $attr( 'hide_empty' ) !== '1' ) {
                $violations[] = [ 'tag' => $tag, 'key' => $key, 'rule' => 'missing_hide_empty',
                    'detail' => 'hide_empty="1" ontbreekt',
                    'location' => $current_location,
                    'proposed_fix' => [
                        'fixable'   => true,
                        'action'    => 'add_attribute',
                        'attribute' => 'hide_empty',
                        'value'     => '1',
                        'before'    => $full_sc,
                        'after'     => substr( $full_sc, 0, -1 ) . ' hide_empty="1"]',
                    ] ];
            }

            if ( $attr( 'color_link' ) !== '0' && aspera_acf_field_type( $key ) !== 'image' ) {
                $violations[] = [ 'tag' => $tag, 'key' => $key, 'rule' => 'missing_color_link',
                    'detail' => 'color_link="0" ontbreekt',
                    'location' => $current_location,
                    'proposed_fix' => [
                        'fixable'   => true,
                        'action'    => 'add_attribute',
                        'attribute' => 'color_link',
                        'value'     => '0',
                        'before'    => $full_sc,
                        'after'     => substr( $full_sc, 0, -1 ) . ' color_link="0"]',
                    ] ];
            }
        }

        // ─── us_btn ───────────────────────────────────────────────────
        if ( $tag === 'us_btn' ) {
            $label     = $attr( 'label' ) ?? '';
            $link_raw  = $attr( 'link' );
            $link_data = $link_raw !== null ? json_decode( urldecode( $link_raw ), true ) : null;

            if ( $attr( 'hide_with_empty_link' ) !== '1' ) {
                $violations[] = [ 'tag' => $tag, 'label' => $label,
                    'rule' => 'missing_hide_with_empty_link',
                    'detail' => 'hide_with_empty_link="1" ontbreekt',
                    'location' => $current_location,
                    'proposed_fix' => [
                        'fixable'   => true,
                        'action'    => 'add_attribute',
                        'attribute' => 'hide_with_empty_link',
                        'value'     => '1',
                        'before'    => $full_sc,
                        'after'     => substr( $full_sc, 0, -1 ) . ' hide_with_empty_link="1"]',
                    ] ];
            }

            $el_class = $attr( 'el_class' );
            if ( $el_class === null || $el_class === '' ) {
                $violations[] = [ 'tag' => $tag, 'label' => $label,
                    'rule' => 'missing_el_class',
                    'detail' => 'el_class ontbreekt op us_btn',
                    'location' => $current_location ];
            }

            // ─── empty_btn_style: style="" aanwezig ────────────────────────
            $style = $attr( 'style' );
            if ( $style !== null && $style === '' ) {
                $violations[] = [ 'tag' => $tag, 'label' => $label,
                    'rule'   => 'empty_btn_style',
                    'detail' => 'style="" — stijl was ingesteld maar het button-stijlobject bestaat niet meer in Impreza',
                    'location' => $current_location ];
            }

            if ( preg_match( '/\{\{bl_[\w_]+\}\}/', $label ) ) {
                if ( ! isset( $link_data['type'] ) || $link_data['type'] !== 'custom_field' || empty( $link_data['custom_field'] ) ) {
                    $violations[] = [ 'tag' => $tag, 'label' => $label,
                        'rule'   => 'missing_acf_link',
                        'detail' => 'label verwijst naar ACF bl_-veld maar link= heeft geen custom_field verwijzing',
                        'location' => $current_location ];
                }
            }

            // ─── hardcoded_link: hardcoded URL in link= ───────────────
            if ( is_array( $link_data )
                 && ( ! isset( $link_data['type'] ) || $link_data['type'] !== 'custom_field' )
                 && ! empty( $link_data['url'] ) ) {
                $violations[] = [ 'tag' => $tag, 'label' => $label,
                    'rule'   => 'hardcoded_link',
                    'detail' => 'link= bevat hardcoded URL "' . $link_data['url'] . '" — gebruik een ACF custom_field verwijzing',
                    'location' => $current_location ];
            }

            // ─── wrong_link_field_prefix: opt_ veld zonder option/ ────
            if ( isset( $link_data['type'] ) && $link_data['type'] === 'custom_field'
                 && ! empty( $link_data['custom_field'] )
                 && preg_match( '/^opt_/', $link_data['custom_field'] ) ) {
                $violations[] = [ 'tag' => $tag, 'label' => $label,
                    'rule'   => 'wrong_link_field_prefix',
                    'detail' => 'link= verwijst naar option page veld "' . $link_data['custom_field'] . '" zonder option/ prefix — gebruik "option/' . $link_data['custom_field'] . '"',
                    'location' => $current_location ];
            }
        }

        // ─── us_page_block ────────────────────────────────────────────
        if ( $tag === 'us_page_block' ) {
            $remove_rows = $attr( 'remove_rows' );
            $pb_id       = $attr( 'id' ) ?? '';
            $pb_title    = $pb_id ? get_the_title( (int) $pb_id ) : '';
            $pb_ref      = $pb_id ? 'page block #' . $pb_id . ( $pb_title ? ' (' . $pb_title . ')' : '' ) : '';

            if ( $remove_rows === null || $remove_rows === '' ) {
                $violations[] = [
                    'tag'    => $tag, 'id' => $pb_id,
                    'rule'   => 'missing_remove_rows',
                    'detail' => $pb_ref . ' — remove_rows ontbreekt — voeg remove_rows="1" toe',
                    'location' => $current_location,
                ];
            } elseif ( $remove_rows === 'parent_row' ) {
                $sibling_count = $pb_sibling[ trim( $attrs ) ] ?? 0;
                if ( $sibling_count > 1 ) {
                    $violations[] = [
                        'tag'    => $tag, 'id' => $pb_id,
                        'rule'   => 'parent_row_with_siblings',
                        'detail' => $pb_ref . ' — remove_rows="parent_row" maar de parent container bevat ' . $sibling_count . ' elementen — gebruik remove_rows="1"',
                        'location' => $current_location,
                    ];
                }
            }
        }

        // ─── us_image ─────────────────────────────────────────────────
        if ( $tag === 'us_image' ) {
            $image = $attr( 'image' );
            if ( $image !== null && ctype_digit( $image ) ) {
                $violations[] = [ 'tag' => $tag, 'rule' => 'hardcoded_image',
                    'detail' => 'image="' . $image . '" — hardcoded media-ID; gebruik {{veldslug}} of een ACF-veldverwijzing',
                    'location' => $current_location ];
            }
        }

        // ─── vc_video ─────────────────────────────────────────────────
        if ( $tag === 'vc_video' ) {
            if ( preg_match( '/\bkey="/', $attrs ) && $attr( 'source' ) === null ) {
                $violations[] = [ 'tag' => $tag, 'rule' => 'vc_video_wrong_attribute',
                    'detail' => 'key= aanwezig — gebruik source= voor de oEmbed veldslug',
                    'snippet' => $snippet,
                    'location' => $current_location ];
            }
        }

        // ─── empty_style_attr: lege *_style attribuut op us_* elementen ─
        if ( strpos( $tag, 'us_' ) === 0 ) {
            if ( preg_match_all( '/\b((?:\w+_)?style)="\s*"/', $attrs, $style_m ) ) {
                foreach ( $style_m[1] as $style_attr ) {
                    if ( $tag === 'us_btn' && $style_attr === 'style' ) continue;
                    $violations[] = [ 'tag' => $tag, 'rule' => 'empty_style_attr',
                        'detail' => $style_attr . '="" — stijl was ingesteld maar het stijlobject bestaat niet meer in Impreza',
                        'location' => $current_location,
                        'proposed_fix' => [
                            'fixable'   => true,
                            'action'    => 'remove_attribute',
                            'attribute' => $style_attr,
                            'before'    => $full_sc,
                            'after'     => preg_replace( '/\s+' . preg_quote( $style_attr, '/' ) . '="\s*"/', '', $full_sc ),
                        ] ];
                }
            }
        }

        // ─── Wrong option syntax: {{option: in plaats van {{option/ ──
        if ( strpos( $attrs, '{{option:' ) !== false ) {
            if ( preg_match_all( '/\b([\w_]+)="([^"]*\{\{option:[^}]*\}\}[^"]*)"/', $attrs, $opt_m, PREG_SET_ORDER ) ) {
                foreach ( $opt_m as $om ) {
                    $corrected_val = str_replace( '{{option:', '{{option/', $om[2] );
                    $violations[] = [ 'tag' => $tag, 'rule' => 'wrong_option_syntax',
                        'detail' => $om[1] . '="' . $om[2] . '" — gebruik {{option/veldslug}} in plaats van {{option:veldslug}}',
                        'location' => $current_location,
                        'proposed_fix' => [
                            'fixable'   => true,
                            'action'    => 'replace_value',
                            'attribute' => $om[1],
                            'value'     => $corrected_val,
                            'before'    => $om[1] . '="' . $om[2] . '"',
                            'after'     => $om[1] . '="' . $corrected_val . '"',
                        ] ];
                }
            }
        }

        // ─── Hardcoded tekst (alleen templates en page blocks) ────────
        if ( $check_text ) {
            foreach ( [ 'label', 'text' ] as $attr_name ) {
                $val = $attr( $attr_name );
                if ( $val === null || $val === '' ) continue;
                if ( preg_match( '/^\{\{[\w_\/]+\}\}$/', $val ) ) continue;
                if ( strpos( $val, '%7B' ) === 0 || strpos( $val, '%7b' ) === 0 ) continue;
                if ( preg_match( '/[a-zA-Z]/', $val ) && ! preg_match( '/^[\w_]+$/', $val ) ) {
                    $violations[] = [ 'tag' => $tag, 'rule' => 'hardcoded_' . $attr_name,
                        'detail' => $attr_name . '="' . $val . '" — hardcoded tekst in template/page block',
                        'location' => $current_location ];
                }
            }
        }

        // ─── animate_detected: appear animatie aanwezig (universeel) ──
        $animate = $attr( 'animate' );
        if ( $animate !== null && $animate !== '' ) {
            $violations[] = [ 'tag' => $tag, 'rule' => 'animate_detected',
                'detail' => 'animate="' . $animate . '" — appear animatie aanwezig', 'snippet' => $snippet,
                'location' => $current_location ];
        }

        // ─── responsive_hide_detected: verborgen op breakpoint (universeel) ──
        $hide_bps = [];
        foreach ( [ 'default', 'laptops', 'tablets', 'mobiles' ] as $bp ) {
            if ( $attr( 'hide_on_' . $bp ) === '1' ) {
                $hide_bps[] = $bp;
            }
        }
        if ( ! empty( $hide_bps ) ) {
            $violations[] = [ 'tag' => $tag, 'rule' => 'responsive_hide_detected',
                'detail' => 'verborgen op: ' . implode( ', ', $hide_bps ), 'snippet' => $snippet,
                'location' => $current_location ];
        }
    }

    return [
        'violations'      => $violations,
        'shortcode_count' => count( $matches ),
        'post_type'       => $post_type,
    ];
}

/**
 * Extraheert een genormaliseerde tag-reeks uit WPBakery post_content.
 * Attributen worden genegeerd — alleen de volgorde van shortcode-tags telt.
 * Sluitende tags worden uitgefilterd zodat alleen opening tags overblijven.
 */
function aspera_tag_sequence( string $content ): array {
    preg_match_all( '/\[(\w+)(?:"[^"]*"|\'[^\']*\'|[^\]])*\]/', $content, $m );
    return $m[1];
}

/**
 * Berekent de structurele gelijkenis tussen twee tag-reeksen via LCS.
 * Geeft een waarde tussen 0.0 (volledig anders) en 1.0 (identiek).
 * Formule: 2 * LCS_lengte / (lengte_a + lengte_b)
 */
function aspera_sequence_similarity( array $a, array $b ): float {
    $la = count( $a );
    $lb = count( $b );
    if ( $la === 0 && $lb === 0 ) return 1.0;
    if ( $la === 0 || $lb === 0 ) return 0.0;

    // Longest Common Subsequence via dynamisch programmeren
    $dp = array_fill( 0, $la + 1, array_fill( 0, $lb + 1, 0 ) );
    for ( $i = 1; $i <= $la; $i++ ) {
        for ( $j = 1; $j <= $lb; $j++ ) {
            $dp[$i][$j] = ( $a[ $i - 1 ] === $b[ $j - 1 ] )
                ? $dp[$i-1][$j-1] + 1
                : max( $dp[$i-1][$j], $dp[$i][$j-1] );
        }
    }

    return ( 2 * $dp[$la][$lb] ) / ( $la + $lb );
}

/**
 * Whitelist van geldige Impreza CSS var-namen.
 * Formaat: naam zonder --color- prefix, hyphens vervangen door underscores.
 * In shortcodes/JSON opgeslagen als _varnaam; in CSS als var(--color-varnaam).
 * Leest dynamisch de actieve Impreza kleurenschema-waarden uit de database.
 * Geeft een array terug met twee keys:
 * - 'whitelist' : array van var-namen (zonder _-prefix) die geldig zijn
 * - 'hex_map'   : array van strtolower(#hex) => [var_naam, ...] voor auto-suggesties
 */
function aspera_get_color_scheme(): array {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    // usof_options_Impreza bevat de live kleurwaarden na aanpassing via de Theme Options UI.
    // usof_style_schemes_Impreza bevat alleen de presets — niet de actuele staat.
    $options = get_option( 'usof_options_Impreza', [] );
    if ( empty( $options ) || ! is_array( $options ) ) {
        return [ 'whitelist' => [], 'hex_map' => [] ];
    }

    $whitelist = [];
    $hex_map   = [];

    foreach ( $options as $key => $value ) {
        if ( strpos( $key, 'color_' ) !== 0 ) continue;
        if ( ! is_string( $value ) ) continue;

        $var_name    = substr( $key, 6 );
        $whitelist[] = $var_name;

        // Alleen simpele hex-waarden opnemen in hex_map
        if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
            $hex_lower = strtolower( $value );
            $hex_map[ $hex_lower ][] = $var_name;
        }
    }

    $cache = [ 'whitelist' => array_unique( $whitelist ), 'hex_map' => $hex_map ];
    return $cache;
}

/**
 * Bekende Impreza vars die buiten het kleurenschema (usof_style_schemes_Impreza) vallen.
 * Herkomst onbekend — mogelijk interne Impreza vars voor transparante header-modus.
 * Worden als geldig behandeld totdat Impreza uitsluitsel geeft.
 *
 * @return string[]
 */
function aspera_impreza_extra_vars(): array {
    static $vars = [
        'header_top_transparent_bg',
        'header_top_transparent_text',
        'header_top_transparent_text_hover',
        'header_transparent_bg',
        'alt_content_overlay_grad',
        'content_overlay_grad',
        'content_primary_grad',
        'alt_content_primary_grad',
        'content_secondary_grad',
        'alt_content_secondary_grad',
        'content_faded_grad',
        'alt_content_faded_grad',
        'footer_bg_grad',
    ];
    return $vars;
}

/**
 * Retourneert arrays met geïnstalleerde en actieve plugin slugs.
 * Gecached per request via static variabele.
 *
 * @return array{ installed: string[], active: string[] }
 */
function aspera_get_plugin_slugs(): array {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $active_files = (array) get_option( 'active_plugins', [] );
    $extract_slug = function ( string $file ): string {
        return strpos( $file, '/' ) !== false ? explode( '/', $file )[0] : str_replace( '.php', '', $file );
    };

    $cache = [
        'installed' => array_map( $extract_slug, array_keys( get_plugins() ) ),
        'active'    => array_map( $extract_slug, $active_files ),
    ];
    return $cache;
}

/**
 * Valideert een ACF field group op structuurfouten.
 * Gedeelde logica voor /acf/validate/{id} en /acf/validate/all.
 *
 * @return array{ fields: array, issues: array }
 */
function aspera_validate_acf_group( int $group_id ): array {
    $fields = acf_get_fields( $group_id );
    if ( ! $fields ) return [ 'fields' => [], 'issues' => [] ];

    $all_keys = [];
    foreach ( $fields as $f ) {
        if ( ! empty( $f['key'] ) ) $all_keys[] = $f['key'];
    }

    $issues = [];

    foreach ( $fields as $f ) {
        $name = $f['name'] ?? '';
        $key  = $f['key']  ?? '';
        $type = $f['type'] ?? '';

        // Ontbrekende naam — tab en accordion zijn by design naamloos
        if ( ! in_array( $type, [ 'tab', 'accordion' ], true ) && empty( $name ) ) {
            $issues[] = [
                'type'  => 'missing_name',
                'key'   => $key,
                'label' => $f['label'] ?? '',
            ];
        }

        // Gebroken conditional logic references
        if ( ! empty( $f['conditional_logic'] ) && is_array( $f['conditional_logic'] ) ) {
            foreach ( $f['conditional_logic'] as $group ) {
                foreach ( $group as $rule ) {
                    if ( ! empty( $rule['field'] ) && ! in_array( $rule['field'], $all_keys, true ) ) {
                        $issues[] = [
                            'type'        => 'broken_conditional_reference',
                            'field_name'  => $name,
                            'field_key'   => $key,
                            'missing_ref' => $rule['field'],
                        ];
                    }
                }
            }
        }

        // Gemengde choice key types (int én string)
        if ( ! empty( $f['choices'] ) && is_array( $f['choices'] ) ) {
            $has_int    = false;
            $has_string = false;
            foreach ( array_keys( $f['choices'] ) as $choice_key ) {
                if ( is_int( $choice_key ) ) $has_int    = true;
                else                          $has_string = true;
            }
            if ( $has_int && $has_string ) {
                $issues[] = [
                    'type'    => 'mixed_choice_key_types',
                    'field'   => $name,
                    'choices' => array_keys( $f['choices'] ),
                ];
            }
        }

        // WYSIWYG veld met media upload buttons ingeschakeld
        if ( $type === 'wysiwyg' && (int) ( $f['media_upload'] ?? 1 ) !== 0 ) {
            $issues[] = [
                'type'        => 'wysiwyg_media_upload_enabled',
                'field_label' => $f['label'] ?? $name,
                'field_slug'  => $name,
                'field_key'   => $key,
            ];
        }
    }

    return [ 'fields' => $fields, 'issues' => $issues ];
}

/**
 * Valideert één kleurwaarde tegen het Impreza kleurbeleid.
 * Geeft null terug als de waarde correct is.
 * Geeft een array terug met 'rule', 'detail' en 'severity' als er een probleem is.
 *
 * Toegestaan:
 * - Leeg, transparent, inherit, initial, unset
 * - #fff, #ffffff, #000, #000000 (hardcoded wit/zwart)
 * - _varnaam die overeenkomt met een Impreza CSS var (whitelist)
 *
 * Fout:
 * - _bd795c — hex-code als var-naam (deprecated_hex_var)
 * - _cc1 / _rood — onbekende custom var-naam (deprecated_custom_var)
 * - #613912 — hardcoded hex anders dan wit/zwart (hardcoded_hex_color)
 *
 * Observatie:
 * - rgba(0,0,0,0.1) — native CSS kleur, mogelijk vervangbaar (rgba_color)
 *
 * @param string $value      De te valideren kleurwaarde
 * @param string $attr_name  Naam van het attribuut (voor rapportage)
 * @param array  $whitelist  Geldige Impreza var-namen (uit aspera_get_color_scheme)
 * @param array  $hex_map    Hex → var-namen mapping voor auto-suggesties
 */
function aspera_validate_color_value( string $value, string $attr_name, array $whitelist, array $hex_map = [] ): ?array {

    if ( $value === '' ) return null;

    // Toegestane CSS keywords
    if ( in_array( $value, [ 'transparent', 'inherit', 'initial', 'unset' ], true ) ) return null;

    // rgba / hsla: observatie — niet per se fout, maar rapporteren
    if ( preg_match( '/^rgba?\s*\(/i', $value ) || preg_match( '/^hsla?\s*\(/i', $value ) ) {
        return [
            'rule'     => 'rgba_color',
            'detail'   => $attr_name . '="' . $value . '" — rgba/hsla waarde; mogelijk vervangbaar door een Impreza CSS var',
            'severity' => 'observation',
        ];
    }

    // #fff en #000 (alle varianten) zijn toegestaan als hardcoded
    if ( in_array( strtolower( $value ), [ '#fff', '#ffffff', '#000', '#000000' ], true ) ) return null;

    // Overige hardcoded hex
    if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
        $hex_key     = strtolower( $value );
        $suggestions = isset( $hex_map[ $hex_key ] ) ? array_map( fn( $n ) => '_' . $n, $hex_map[ $hex_key ] ) : [];
        $result      = [
            'rule'     => 'hardcoded_hex_color',
            'detail'   => $attr_name . '="' . $value . '" — hardcoded hex kleur; gebruik een Impreza CSS var',
            'severity' => 'error',
        ];
        if ( ! empty( $suggestions ) ) {
            $result['suggestion'] = implode( ' / ', $suggestions );
        }
        return $result;
    }

    // _ prefix: var-verwijzing
    if ( isset( $value[0] ) && $value[0] === '_' ) {
        $var_name = substr( $value, 1 );

        // Geldig Impreza var
        if ( in_array( $var_name, $whitelist, true ) ) return null;

        // Bekende Impreza var buiten kleurenschema (uitzondering)
        if ( in_array( $var_name, aspera_impreza_extra_vars(), true ) ) return null;

        // Hex-code als var-naam: _bd795c, _fff, _000, etc.
        if ( preg_match( '/^[0-9a-fA-F]{3,8}$/', $var_name ) ) {
            $hex_key     = '#' . strtolower( $var_name );
            $suggestions = isset( $hex_map[ $hex_key ] ) ? array_map( fn( $n ) => '_' . $n, $hex_map[ $hex_key ] ) : [];
            $result      = [
                'rule'     => 'deprecated_hex_var',
                'detail'   => $attr_name . '="' . $value . '" — hex-code als CSS var; vervang door bijpassende Impreza CSS var',
                'severity' => 'error',
            ];
            if ( ! empty( $suggestions ) ) {
                $result['suggestion'] = implode( ' / ', $suggestions );
            }
            return $result;
        }

        // Onbekende custom var: _cc1, _rood, _primair, etc.
        return [
            'rule'     => 'deprecated_custom_var',
            'detail'   => $attr_name . '="' . $value . '" — onbekende custom CSS var; vervang door een Impreza CSS var',
            'severity' => 'error',
        ];
    }

    return null;
}

/**
 * Doorzoekt recursief een JSON-array op kleurkeys en verzamelt violations.
 * Wordt gebruikt voor us_header en us_grid_layout post_content.
 *
 * @param array    $data        Te doorzoeken data
 * @param WP_Post  $post        De post waaruit de data afkomstig is
 * @param array    $violations   Bijgewerkte violations array (by reference)
 * @param array    $observations Bijgewerkte observations array (by reference)
 * @param array    $whitelist    Geldige Impreza var-namen (uit aspera_get_color_scheme)
 * @param array    $hex_map      Hex → var-namen mapping voor auto-suggesties
 * @param string   $path         Huidige JSON-path voor rapportage
 */
function aspera_find_color_violations_in_json( array $data, WP_Post $post, array &$violations, array &$observations, array $whitelist, array $hex_map = [], string $path = '' ): void {
    foreach ( $data as $key => $value ) {
        $current_path = $path !== '' ? $path . '.' . $key : (string) $key;

        if ( is_array( $value ) ) {
            // Skip element.css objecten — Design-tab CSS valt onder design_css_forbidden,
            // niet onder hardcoded_hex_color (zou anders dubbele meldingen geven).
            if ( $key === 'css' ) continue;
            aspera_find_color_violations_in_json( $value, $post, $violations, $observations, $whitelist, $hex_map, $current_path );
            continue;
        }

        if ( ! is_string( $value ) || $value === '' ) continue;

        // Alleen keys die 'color' bevatten
        if ( strpos( (string) $key, 'color' ) === false ) continue;

        $issue = aspera_validate_color_value( $value, (string) $key, $whitelist, $hex_map );
        if ( $issue === null ) continue;

        $entry = [
            'post_id'    => (int) $post->ID,
            'post_type'  => $post->post_type,
            'post_title' => $post->post_title,
            'source'     => 'json',
            'path'       => $current_path,
            'attribute'  => (string) $key,
            'value'      => $value,
            'rule'       => $issue['rule'],
            'detail'     => $issue['detail'],
            'severity'   => $issue['severity'],
        ];

        if ( isset( $issue['suggestion'] ) ) {
            $entry['suggestion'] = $issue['suggestion'];
        }

        if ( $issue['severity'] === 'observation' ) {
            $observations[] = $entry;
        } else {
            $violations[] = $entry;
        }
    }
}

/**
 * Scant aanvullende kleurlocaties in us_grid_layout en us_header JSON die niet
 * door aspera_find_color_violations_in_json worden opgepikt:
 *
 * 1. Element-level `css` objecten — opgeslagen als URL-encoded JSON string per element,
 *    waardoor de recursieve scanner ze als string overslaat. Bevat CSS-properties als
 *    `background-color`, `color`, `border-color` etc.
 *
 * 2. Tegel-level `default.options.color_*` instellingen — direct in de tegel-config,
 *    bijv. `color_bg`, `color_text`, `color_border`.
 */
function aspera_scan_grid_extended_colors( array $data, WP_Post $post, array &$violations, array &$observations, array $whitelist, array $hex_map ): void {

    // ── 1. Element-level css objecten ────────────────────────────────────────
    // Bewust overgeslagen: kleuren binnen element.css.{breakpoint}.* vallen onder
    // design_css_forbidden (severity error op het hele Design-tab CSS-blok).
    // Apart rapporteren als hardcoded_hex_color zou dubbele meldingen produceren
    // voor wat conceptueel één probleem is.

    // ── 2. Tegel-level default.options.color_* ───────────────────────────────
    $options = $data['default']['options'] ?? null;
    if ( ! is_array( $options ) ) return;

    foreach ( $options as $key => $value ) {
        if ( strpos( (string) $key, 'color' ) === false ) continue;
        if ( ! is_string( $value ) || $value === '' ) continue;

        $issue = aspera_validate_color_value( $value, (string) $key, $whitelist, $hex_map );
        if ( $issue === null ) continue;

        $entry = [
            'post_id'    => (int) $post->ID,
            'post_type'  => $post->post_type,
            'post_title' => $post->post_title,
            'source'     => 'json',
            'path'       => 'default.options.' . $key,
            'attribute'  => (string) $key,
            'value'      => $value,
            'rule'       => $issue['rule'],
            'detail'     => $issue['detail'],
            'severity'   => $issue['severity'],
        ];
        if ( isset( $issue['suggestion'] ) ) $entry['suggestion'] = $issue['suggestion'];
        if ( $issue['severity'] === 'observation' ) $observations[] = $entry; else $violations[] = $entry;
    }
}

/**
 * Doorzoekt recursief een gedecodeerd CSS-object (breakpoint → property → waarde)
 * op hardcoded of deprecated kleurwaarden.
 *
 * @param array   $data         Het gedecodeerde CSS-object
 * @param string  $element_key  Elementsleutel uit data[] (bijv. "vwrapper:2")
 * @param string  $path_prefix  Huidig pad voor rapportage (bijv. "css.default")
 */
function aspera_scan_css_object_for_colors( array $data, string $element_key, string $path_prefix, WP_Post $post, array &$violations, array &$observations, array $whitelist, array $hex_map ): void {
    foreach ( $data as $key => $value ) {
        $current_path = $path_prefix . '.' . $key;

        if ( is_array( $value ) ) {
            aspera_scan_css_object_for_colors(
                $value, $element_key, $current_path,
                $post, $violations, $observations, $whitelist, $hex_map
            );
            continue;
        }

        if ( ! is_string( $value ) || $value === '' ) continue;

        // Alleen CSS-properties met 'color' in de naam (color, background-color, border-color etc.)
        if ( strpos( (string) $key, 'color' ) === false ) continue;

        $issue = aspera_validate_color_value( $value, (string) $key, $whitelist, $hex_map );
        if ( $issue === null ) continue;

        $entry = [
            'post_id'    => (int) $post->ID,
            'post_type'  => $post->post_type,
            'post_title' => $post->post_title,
            'source'     => 'json',
            'path'       => 'data.' . $element_key . '.' . $current_path,
            'attribute'  => (string) $key,
            'value'      => $value,
            'rule'       => $issue['rule'],
            'detail'     => $issue['detail'],
            'severity'   => $issue['severity'],
        ];
        if ( isset( $issue['suggestion'] ) ) $entry['suggestion'] = $issue['suggestion'];
        if ( $issue['severity'] === 'observation' ) $observations[] = $entry; else $violations[] = $entry;
    }
}

/**
 * Geheime sleutel ophalen — eerst constant in wp-config.php, anders DB-option.
 * Constant zetten: define( 'ASPERA_SECRET_KEY', '...' ); in wp-config.php.
 * Voorkomt dat een DB-leak directe API-toegang geeft.
 */
function aspera_get_secret_key(): string {
    if ( defined( 'ASPERA_SECRET_KEY' ) && ASPERA_SECRET_KEY ) {
        return (string) ASPERA_SECRET_KEY;
    }
    return (string) get_option( 'aspera_secret_key', '' );
}

/**
 * Mapt een audit-categorie naar de bijbehorende REST-endpoint die opnieuw
 * gecheckt kan worden voor een single-violation re-validatie.
 * Categorieën zonder dedicated endpoint (wp_settings, theme_check) returnen null.
 */
function aspera_get_endpoint_for_category( string $cat ): ?string {
    $map = [
        'wpb'              => 'wpb/validate/all',
        'grid'             => 'grid/validate',
        'colors'           => 'colors/validate',
        'forms'            => 'forms/validate',
        'plugins'          => 'plugins/validate',
        'cpt'              => 'cpt/validate',
        'db_tables'        => 'db/tables/validate',
        'css'              => 'css/unused',
        'nav'              => 'nav/validate',
        'wpb_modules'      => 'wpb/modules/validate',
        'theme_breakpoints'=> 'theme/breakpoints',
        'widgets'          => 'widgets/validate',
        'wpb_templates'    => 'wpb/templates/validate',
        'taxonomy'         => 'taxonomy/validate',
        'header_config'    => 'header/validate',
        'acf_fields'       => 'acf/validate/all',
        'meta_orphaned'    => 'meta/validate',
        'options_orphaned' => 'options/validate',
        'naming'           => 'naming/validate',
        'options_config'   => 'options/config/validate',
        'acf_slugs'        => 'acf/validate/slugs',
        'acf_locations'    => 'acf/validate/locations',
        'cache'            => 'cache/validate',
    ];
    return $map[ $cat ] ?? null;
}

/**
 * Doorzoekt recursief de response van een validate-endpoint naar een
 * specifieke {rule, post_id} combinatie. Draagt context_post_id mee
 * vanuit wrapper-arrays (bv. forms-loop) zodat post_id niet altijd in
 * dezelfde node hoeft te zitten als rule.
 */
function aspera_walk_for_violation( $data, string $rule, $target_post_id, $context_post_id = null ): bool {
    if ( ! is_array( $data ) ) return false;
    if ( isset( $data['post_id'] ) && ( is_int( $data['post_id'] ) || ctype_digit( (string) $data['post_id'] ) ) ) {
        $context_post_id = $data['post_id'];
    }
    if ( ( $data['rule'] ?? null ) === $rule ) {
        $local_pid = $data['post_id'] ?? $context_post_id;
        if ( $target_post_id === null || $target_post_id === '' || (string) $local_pid === (string) $target_post_id ) {
            return true;
        }
    }
    foreach ( $data as $v ) {
        if ( is_array( $v ) && aspera_walk_for_violation( $v, $rule, $target_post_id, $context_post_id ) ) return true;
    }
    return false;
}

/**
 * Bereken delta tussen huidige summary en voorgaande snapshot.
 * Returnt array met: prev_date, total_diff, severity_diffs, score_diff, category_diffs.
 * Returnt null als er geen historie is.
 */
function aspera_get_audit_delta( array $current ): ?array {
    $history = get_option( 'aspera_audit_history', [] );
    if ( ! is_array( $history ) || empty( $history ) ) return null;
    $last = end( $history );
    if ( ! is_array( $last ) || empty( $last['summary'] ) ) return null;
    $prev = json_decode( $last['summary'], true );
    if ( ! is_array( $prev ) ) return null;

    $cur_total = (int) ( $current['total_violations'] ?? 0 );
    $pre_total = (int) ( $prev['total_violations'] ?? 0 );
    $cur_score = (int) ( $current['score'] ?? 0 );
    $pre_score = (int) ( $prev['score'] ?? 0 );

    $sev_diffs = [];
    foreach ( [ 'critical', 'error', 'warning', 'observation' ] as $s ) {
        $sev_diffs[ $s ] = (int) ( $current['severity_counts'][ $s ] ?? 0 ) - (int) ( $prev['severity_counts'][ $s ] ?? 0 );
    }

    $cat_diffs = [];
    $all_cats  = array_unique( array_merge(
        array_keys( $current['category_scores'] ?? [] ),
        array_keys( $prev['category_scores'] ?? [] )
    ) );
    foreach ( $all_cats as $c ) {
        $cur_v = (int) ( $current['category_scores'][ $c ]['violations'] ?? 0 );
        $pre_v = (int) ( $prev['category_scores'][ $c ]['violations'] ?? 0 );
        $diff  = $cur_v - $pre_v;
        if ( $diff !== 0 ) $cat_diffs[ $c ] = $diff;
    }

    return [
        'prev_date'      => $last['date'] ?? null,
        'total_diff'     => $cur_total - $pre_total,
        'score_diff'     => $cur_score - $pre_score,
        'severity_diffs' => $sev_diffs,
        'category_diffs' => $cat_diffs,
    ];
}

/**
 * Verifieert de geheime sleutel op elk REST-verzoek.
 * Sleutel wordt meegegeven als query-parameter: ?aspera_key=...
 */
function aspera_check_key( WP_REST_Request $req ): true|WP_Error {
    $stored = aspera_get_secret_key();
    if ( empty( $stored ) ) {
        return new WP_Error( 'no_key', 'Aspera secret key niet geconfigureerd.', [ 'status' => 500 ] );
    }
    $provided = (string) ( $req->get_param( 'aspera_key' ) ?? '' );
    if ( ! hash_equals( $stored, $provided ) ) {
        return new WP_Error( 'unauthorized', 'Ongeldige of ontbrekende aspera_key.', [ 'status' => 401 ] );
    }
    return true;
}

/**
 * Genereert het site-paspoort: een snapshot van templates, page blocks,
 * field groups, custom post types en option pages.
 */
function aspera_generate_passport(): array {
    global $wpdb;

    $templates = get_posts( [
        'post_type'      => 'us_content_template',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    $page_blocks = get_posts( [
        'post_type'      => 'us_page_block',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    $field_groups = [];
    if ( function_exists( 'acf_get_field_groups' ) ) {
        foreach ( acf_get_field_groups() as $group ) {
            $fields         = acf_get_fields( $group['key'] );
            $field_groups[] = [
                'id'          => $group['ID'],
                'title'       => $group['title'],
                'field_count' => $fields ? count( $fields ) : 0,
            ];
        }
    }

    $exclude = [ 'us_content_template', 'us_page_block', 'us_header', 'us_grid_layout',
                 'acf-field-group', 'acf-field', 'acf-ui-options-page', 'attachment' ];
    $cpts    = array_values( array_diff(
        array_keys( get_post_types( [ '_builtin' => false, 'public' => true ] ) ),
        $exclude
    ) );

    $option_pages = [];
    if ( function_exists( 'acf_get_options_pages' ) ) {
        foreach ( (array) acf_get_options_pages() as $slug => $page ) {
            $option_pages[] = [ 'slug' => $slug, 'title' => $page['page_title'] ?? $slug ];
        }
    }

    return [
        'format_version'     => 1,
        'generated_at'       => current_time( 'Y-m-d H:i:s' ),
        'site_url'           => get_option( 'siteurl' ),
        'table_prefix'       => $wpdb->prefix,
        'templates'          => array_map( fn( $p ) => [ 'id' => $p->ID, 'title' => $p->post_title ], $templates ),
        'page_blocks'        => array_map( fn( $p ) => [ 'id' => $p->ID, 'title' => $p->post_title ], $page_blocks ),
        'field_groups'       => $field_groups,
        'custom_post_types'  => $cpts,
        'option_pages'       => $option_pages,
    ];
}

// Stale-vlag zetten bij relevante wijzigingen — autoload: no om geen paginabelasting te veroorzaken
add_action( 'save_post', function ( int $post_id, WP_Post $post ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    static $watch = [ 'us_content_template', 'us_page_block', 'acf-field-group', 'acf-ui-options-page' ];
    if ( in_array( $post->post_type, $watch, true ) ) {
        update_option( 'aspera_passport_stale', '1', false );
    }
}, 10, 2 );

add_action( 'before_delete_post', function ( int $post_id ) {
    static $watch = [ 'us_content_template', 'us_page_block', 'acf-field-group', 'acf-ui-options-page' ];
    $type = get_post_type( $post_id );
    if ( $type && in_array( $type, $watch, true ) ) {
        update_option( 'aspera_passport_stale', '1', false );
    }
} );

// ── Site Health integratie ─────────────────────────────────────────────────
// Toont de Aspera audit score in WP Admin → Hulpmiddelen → Site Health.
// Leest de snapshot uit wp_options (gezet door /site/audit) — geen extra REST call.
add_filter( 'site_status_tests', function ( array $tests ): array {
    $tests['direct']['aspera_audit'] = [
        'label' => 'Aspera Site Audit',
        'test'  => 'aspera_site_health_test',
    ];
    return $tests;
} );

function aspera_site_health_test(): array {
    $score   = get_option( 'aspera_audit_score' );
    $date    = get_option( 'aspera_audit_date' );
    $summary = get_option( 'aspera_audit_summary' );

    // Nog geen audit gedraaid
    if ( $score === false || $date === false ) {
        return [
            'label'       => 'Aspera Audit: nog niet uitgevoerd',
            'status'      => 'recommended',
            'badge'       => [ 'label' => 'Aspera', 'color' => 'orange' ],
            'description' => '<p>Er is nog geen Aspera site-audit uitgevoerd. Roep <code>/wp-json/aspera/v1/site/audit</code> aan om de eerste audit te starten.</p>',
            'test'        => 'aspera_audit',
        ];
    }

    $score   = (int) $score;
    $data    = is_string( $summary ) ? json_decode( $summary, true ) : [];
    $counts  = $data['severity_counts'] ?? [];
    $cat_scores = $data['category_scores'] ?? [];

    // Status en kleur op basis van stoplicht
    if ( $score >= 80 ) {
        $status = 'good';
        $color  = 'green';
        $emoji  = '';
    } elseif ( $score >= 50 ) {
        $status = 'recommended';
        $color  = 'orange';
        $emoji  = '';
    } else {
        $status = 'critical';
        $color  = 'red';
        $emoji  = '';
    }

    // Categorie-overzicht opbouwen
    $cat_labels = [
        'wpb'       => 'WPBakery Templates',
        'grid'      => 'Grid Layouts & Headers',
        'colors'    => 'Kleuren',
        'acf_slugs'     => 'ACF Slugs',
        'acf_locations' => 'ACF Locatieregels',
        'forms'         => 'Formulieren',
        'plugins'       => 'Plugins',
        'cpt'           => 'Custom Post Types',
        'db_tables'     => 'Database Tabellen',
        'theme_check'   => 'Thema Check',
        'wp_settings'   => 'WP Instellingen',
        'cache'         => 'Cache',
    ];

    $rows = '';
    foreach ( $cat_scores as $key => $cs ) {
        $label      = $cat_labels[ $key ] ?? $key;
        $v_count    = $cs['violation_count'] ?? 0;
        $deductions = $cs['deductions'] ?? 0;
        $cap        = $cs['cap'] ?? 0;
        $row_status = $v_count === 0 ? 'ok' : $deductions . '/' . $cap . ' aftrek';
        $rows      .= '<tr><td>' . esc_html( $label ) . '</td><td>' . $v_count . '</td><td>' . esc_html( $row_status ) . '</td></tr>';
    }

    $critical = $counts['critical'] ?? 0;
    $error    = $counts['error'] ?? 0;
    $warning  = $counts['warning'] ?? 0;
    $total    = $data['total_violations'] ?? 0;

    $severity_line = '';
    if ( $total > 0 ) {
        $parts = [];
        if ( $critical > 0 ) $parts[] = $critical . ' critical';
        if ( $error > 0 )    $parts[] = $error . ' error';
        if ( $warning > 0 )  $parts[] = $warning . ' warning';
        $severity_line = '<p><strong>Verdeling:</strong> ' . implode( ', ', $parts ) . '</p>';
    }

    $description = '<p><strong>Health score: ' . $score . '/100</strong> — '
        . $total . ' violation' . ( $total !== 1 ? 's' : '' ) . ' gevonden.</p>'
        . $severity_line
        . '<table class="widefat striped"><thead><tr><th>Categorie</th><th>Violations</th><th>Status</th></tr></thead><tbody>'
        . $rows
        . '</tbody></table>'
        . '<p><small>Laatste audit: ' . esc_html( $date ) . '</small></p>';

    return [
        'label'       => 'Aspera Audit: ' . $score . '/100',
        'status'      => $status,
        'badge'       => [ 'label' => 'Aspera', 'color' => $color ],
        'description' => $description,
        'test'        => 'aspera_audit',
    ];
}

// ── Dashboard Widget ──────────────────────────────────────────────────────────
// Toont de Aspera audit snapshot in WP Dashboard — alleen zichtbaar voor Administrators.
// Leest opgeslagen snapshots uit wp_options; refresh via AJAX triggert /site/audit opnieuw.

function aspera_user_is_administrator(): bool {
    $user = wp_get_current_user();
    return in_array( 'administrator', (array) $user->roles, true );
}

add_action( 'admin_menu', function () {
    if ( ! aspera_user_is_administrator() ) return;
    add_management_page(
        'AsperAi Site Tools',
        'AsperAi Site Tools',
        'manage_options',
        'aspera-analysis-api',
        'aspera_admin_page_render'
    );
} );

function aspera_admin_page_render(): void {
    echo '<div class="wrap" id="aspera-audit-page">';
    echo '<h1>AsperAi Site Tools</h1>';
    aspera_dashboard_widget_render();
    aspera_admin_page_pdf_export();
    echo '</div>';
}

function aspera_admin_page_pdf_export(): void {
    ?>
    <style>
        #aspera-pdf-btn { margin-right: 6px; }
        @media print {
            #adminmenumain, #adminmenuback, #wpadminbar, #wpfooter,
            .update-nag, .notice, .update-message, #screen-meta, #screen-meta-links,
            #aspera-refresh-btn, #aspera-pdf-btn, #aspera-refresh-status { display: none !important; }
            html.wp-toolbar { padding-top: 0 !important; }
            #wpcontent, #wpbody-content { margin-left: 0 !important; padding-left: 0 !important; padding-top: 0 !important; }
            .wrap { margin: 0 !important; }
            details { page-break-inside: avoid; }
        }
    </style>
    <script>
    (function () {
        var refreshBtn = document.getElementById('aspera-refresh-btn');
        if (!refreshBtn || document.getElementById('aspera-pdf-btn')) return;
        var pdfBtn = document.createElement('button');
        pdfBtn.type = 'button';
        pdfBtn.id = 'aspera-pdf-btn';
        pdfBtn.className = 'button button-secondary';
        pdfBtn.innerHTML = '⤓&ensp;Exporteer als PDF';
        pdfBtn.addEventListener('click', function () { window.print(); });
        refreshBtn.parentNode.insertBefore(pdfBtn, refreshBtn);
    })();
    </script>
    <?php
}

add_action( 'wp_dashboard_setup', function () {
    if ( ! aspera_user_is_administrator() ) return;
    wp_add_dashboard_widget(
        'aspera_audit_widget',
        'Aspera Site Audit',
        'aspera_dashboard_summary_render'
    );
} );

function aspera_dashboard_summary_render(): void {
    $score    = get_option( 'aspera_audit_score', null );
    $summary  = get_option( 'aspera_audit_summary' );
    $data     = is_string( $summary ) ? json_decode( $summary, true ) : [];

    if ( $score === false ) {
        echo '<p style="color:#72777c;margin:0;">Nog geen audit uitgevoerd.</p>';
        echo '<p style="margin:8px 0 0;"><a href="' . esc_url( admin_url( 'tools.php?page=aspera-analysis-api' ) ) . '" class="button">Audit uitvoeren</a></p>';
        return;
    }

    $score_int = (int) $score;
    $counts    = $data['severity_counts'] ?? [];
    $traffic   = $data['traffic_light'] ?? ( $score_int >= 80 ? 'green' : ( $score_int >= 50 ? 'yellow' : 'red' ) );

    $score_color_map = [ 'green' => '#00a32a', 'yellow' => '#dba617', 'red' => '#d63638' ];
    $score_color     = $score_color_map[ $traffic ] ?? '#72777c';
    $score_label_map = [ 'green' => 'Schoon', 'yellow' => 'Aandacht nodig', 'red' => 'Kritieke problemen' ];
    $score_label     = $score_label_map[ $traffic ] ?? '';

    $sev_colors = [
        'critical' => '#d63638', 'error' => '#d63638',
        'warning'  => '#dba617', 'observation' => '#2271b1',
    ];

    echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">';
    echo '<span style="font-size:2.2em;font-weight:800;color:' . esc_attr( $score_color ) . ';line-height:1;">' . $score_int . '</span>';
    echo '<span style="font-size:1.1em;color:#72777c;font-weight:400;">/100</span>';
    echo '<span style="font-size:13px;font-weight:700;color:' . esc_attr( $score_color ) . ';padding:3px 9px;background:' . esc_attr( $score_color ) . '22;border-radius:3px;">' . esc_html( $score_label ) . '</span>';
    echo '</div>';

    echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">';
    foreach ( [ 'critical' => 'Kritiek', 'error' => 'Fout', 'warning' => 'Waarschuwing', 'observation' => 'Opmerking' ] as $sev => $blabel ) {
        $cnt = (int) ( $counts[ $sev ] ?? 0 );
        $bg  = $cnt > 0 ? $sev_colors[ $sev ] : '#dcdcde';
        $fc  = $cnt > 0 ? '#fff' : '#50575e';
        echo '<span style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fc ) . ';border-radius:3px;padding:2px 8px;font-size:12px;font-weight:700;">' . $cnt . '&nbsp;' . esc_html( $blabel ) . '</span>';
    }
    echo '</div>';

    echo '<a href="' . esc_url( admin_url( 'tools.php?page=aspera-analysis-api' ) ) . '" class="button button-primary">Volledig rapport</a>';
}

add_action( 'wp_ajax_aspera_refresh_audit', function () {
    if ( ! aspera_user_is_administrator() ) {
        wp_send_json_error( 'Onvoldoende rechten.' );
    }
    check_ajax_referer( 'aspera_refresh_nonce', 'nonce' );

    $key = aspera_get_secret_key();
    if ( ! $key ) {
        wp_send_json_error( 'Aspera secret key niet geconfigureerd (constant of option).' );
    }

    $request = new WP_REST_Request( 'GET', '/aspera/v1/site/audit' );
    $request->set_param( 'aspera_key', $key );
    $response = rest_do_request( $request );

    if ( $response->is_error() ) {
        wp_send_json_error( $response->as_error()->get_error_message() );
    }

    wp_send_json_success( [ 'reload' => true ] );
} );

add_action( 'wp_ajax_aspera_add_exception', function () {
    check_ajax_referer( 'aspera_refresh_nonce', 'nonce' );
    if ( ! aspera_user_is_administrator() ) wp_die( -1 );

    $items_json = wp_unslash( $_POST['items'] ?? '' );
    $items      = json_decode( $items_json, true );

    if ( ! is_array( $items ) || empty( $items ) ) {
        $exc_id   = sanitize_text_field( wp_unslash( $_POST['exception_id'] ?? '' ) );
        $category = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );
        $rule     = sanitize_text_field( wp_unslash( $_POST['rule']     ?? '' ) );
        $post_id  = intval( $_POST['post_id'] ?? 0 );
        if ( ! $exc_id ) wp_die( -1 );
        $items = [ [ 'id' => $exc_id, 'category' => $category, 'rule' => $rule, 'post_id' => $post_id ] ];
    }

    $exceptions = get_option( 'aspera_audit_exceptions', [] );
    if ( ! is_array( $exceptions ) ) $exceptions = [];
    $existing = [];
    foreach ( $exceptions as $e ) { $existing[ $e['id'] ] = true; }

    $added = [];
    foreach ( $items as $item ) {
        $id = sanitize_text_field( $item['id'] ?? '' );
        if ( ! $id || isset( $existing[ $id ] ) ) continue;
        $exceptions[] = [
            'id'         => $id,
            'category'   => sanitize_text_field( $item['category'] ?? '' ),
            'rule'       => sanitize_text_field( $item['rule'] ?? '' ),
            'post_id'    => intval( $item['post_id'] ?? 0 ),
            'created_at' => current_time( 'Y-m-d' ),
        ];
        $existing[ $id ] = true;
        $added[] = $id;
    }

    update_option( 'aspera_audit_exceptions', $exceptions );
    wp_send_json_success( [ 'added' => $added ] );
} );

add_action( 'wp_ajax_aspera_remove_exception', function () {
    check_ajax_referer( 'aspera_refresh_nonce', 'nonce' );
    if ( ! aspera_user_is_administrator() ) wp_die( -1 );

    $items_json = wp_unslash( $_POST['items'] ?? '' );
    $items      = json_decode( $items_json, true );

    if ( is_array( $items ) && ! empty( $items ) ) {
        $ids = array_flip( array_map( 'sanitize_text_field', $items ) );
    } else {
        $exc_id = sanitize_text_field( wp_unslash( $_POST['exception_id'] ?? '' ) );
        if ( ! $exc_id ) wp_die( -1 );
        $ids = [ $exc_id => true ];
    }

    $exceptions = get_option( 'aspera_audit_exceptions', [] );
    if ( ! is_array( $exceptions ) ) $exceptions = [];
    $exceptions = array_values( array_filter( $exceptions, fn( $e ) => ! isset( $ids[ $e['id'] ] ) ) );
    update_option( 'aspera_audit_exceptions', $exceptions );
    wp_send_json_success();
} );

add_action( 'wp_ajax_aspera_cleanup_exceptions', function () {
    check_ajax_referer( 'aspera_refresh_nonce', 'nonce' );
    if ( ! aspera_user_is_administrator() ) wp_die( -1 );

    // Verzamel alle exception_ids die voorkomen in de huidige audit-snapshot
    $snapshot = get_option( 'aspera_audit_snapshot' );
    $cats     = is_string( $snapshot ) ? json_decode( $snapshot, true ) : [];
    $active   = [];
    if ( is_array( $cats ) ) {
        foreach ( $cats as $cat_data ) {
            foreach ( $cat_data['violations'] ?? [] as $v ) {
                $eid = $v['exception_id'] ?? '';
                if ( $eid ) $active[ $eid ] = true;
            }
        }
    }

    // Bewaar alleen exceptions die matchen met een violation in de snapshot
    $exceptions = get_option( 'aspera_audit_exceptions', [] );
    if ( ! is_array( $exceptions ) ) $exceptions = [];
    $before     = count( $exceptions );
    $exceptions = array_values( array_filter( $exceptions, fn( $e ) => isset( $active[ $e['id'] ] ) ) );
    $removed    = $before - count( $exceptions );
    update_option( 'aspera_audit_exceptions', $exceptions );

    wp_send_json_success( [ 'removed' => $removed ] );
} );

add_action( 'wp_ajax_aspera_recheck_violation', function () {
    check_ajax_referer( 'aspera_refresh_nonce', 'nonce' );
    if ( ! aspera_user_is_administrator() ) wp_die( -1 );

    $rule    = sanitize_text_field( $_POST['rule'] ?? '' );
    $post_id = sanitize_text_field( $_POST['post_id'] ?? '' );
    $cat     = sanitize_text_field( $_POST['category'] ?? '' );
    if ( ! $rule ) wp_send_json_error( 'rule ontbreekt.' );

    // Categorie afleiden uit registry als niet meegegeven
    if ( ! $cat ) {
        foreach ( aspera_get_rules_per_category() as $c => $rules ) {
            if ( in_array( $rule, $rules, true ) ) { $cat = $c; break; }
        }
    }
    $endpoint = $cat ? aspera_get_endpoint_for_category( $cat ) : null;
    if ( ! $endpoint ) {
        wp_send_json_error( [ 'message' => 'Geen recheck-endpoint voor categorie "' . $cat . '". Gebruik Vernieuwen.', 'unsupported' => true ] );
    }

    $request = new WP_REST_Request( 'GET', '/aspera/v1/' . $endpoint );
    $request->set_param( 'aspera_key', aspera_get_secret_key() );
    $response = rest_do_request( $request );
    if ( $response->is_error() ) {
        wp_send_json_error( $response->as_error()->get_error_message() );
    }
    $data = $response->get_data();
    $still_present = aspera_walk_for_violation( $data, $rule, $post_id !== '' ? $post_id : null );
    wp_send_json_success( [ 'still_present' => $still_present, 'rule' => $rule, 'post_id' => $post_id ] );
} );

add_action( 'wp_ajax_aspera_apply_fix', function () {
    check_ajax_referer( 'aspera_refresh_nonce', 'nonce' );
    if ( ! aspera_user_is_administrator() ) wp_die( -1 );

    $action  = sanitize_text_field( $_POST['fix_action'] ?? '' );
    $post_id = intval( $_POST['post_id'] ?? 0 );

    $no_post_id_actions = [ 'delete_orphaned_meta', 'delete_wpforms_scheduled_actions' ];
    if ( ! $action || ( ! $post_id && ! in_array( $action, $no_post_id_actions, true ) ) ) {
        wp_send_json_error( 'Ongeldige parameters.' );
    }

    switch ( $action ) {
        case 'delete_field_group':
            $post = get_post( $post_id );
            if ( ! $post || $post->post_type !== 'acf-field-group' ) {
                wp_send_json_error( 'Field group niet gevonden.' );
            }
            wp_trash_post( $post_id );
            wp_send_json_success( [ 'message' => 'Field group "' . $post->post_title . '" verplaatst naar prullenbak.' ] );
            break;

        case 'delete_orphaned_meta':
            global $wpdb;
            $meta_key = sanitize_text_field( $_POST['meta_key'] ?? '' );
            if ( ! $meta_key ) {
                wp_send_json_error( 'meta_key ontbreekt.' );
            }
            // Re-verify: bevestig dat meta_key nog steeds verweesd is
            // (zelfde criterium als /meta/validate: bijbehorende _<key>=field_* bestaat
            // én die field_key komt niet voor in actieve acf-field post_name's).
            $field_key = $wpdb->get_var( $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value LIKE 'field_%%' LIMIT 1",
                '_' . $meta_key
            ) );
            if ( ! $field_key ) {
                wp_send_json_error( 'Verificatie mislukt: geen field_* referentie meer voor "' . $meta_key . '". Voer audit opnieuw uit.' );
            }
            $field_key_active = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'acf-field' AND post_status = 'publish' AND post_name = %s",
                $field_key
            ) );
            if ( $field_key_active > 0 ) {
                wp_send_json_error( 'Verificatie mislukt: meta_key "' . $meta_key . '" is weer in gebruik (actief ACF-veld). Verwijdering geannuleerd.' );
            }
            $del_data = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key
            ) );
            $del_ref = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_' . $meta_key
            ) );
            wp_send_json_success( [
                'message' => 'Meta key "' . $meta_key . '" verwijderd (' . ( $del_data + $del_ref ) . ' rijen).',
            ] );
            break;

        case 'delete_wpforms_scheduled_actions':
            global $wpdb;
            $wpforms_active = false;
            foreach ( (array) get_option( 'active_plugins', [] ) as $p ) {
                if ( stripos( $p, 'wpforms' ) !== false ) { $wpforms_active = true; break; }
            }
            if ( $wpforms_active ) {
                wp_send_json_error( 'WPForms is actief — scheduled actions worden niet verwijderd.' );
            }
            $as_table  = $wpdb->prefix . 'actionscheduler_actions';
            $log_table = $wpdb->prefix . 'actionscheduler_logs';
            $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $as_table ) );
            if ( ! $table_exists ) {
                wp_send_json_error( 'actionscheduler_actions tabel niet gevonden.' );
            }
            $action_ids = $wpdb->get_col( "SELECT action_id FROM {$as_table} WHERE hook LIKE 'wpforms%'" );
            $deleted_logs    = 0;
            $deleted_actions = 0;
            if ( ! empty( $action_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $action_ids ), '%d' ) );
                $deleted_logs    = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$log_table} WHERE action_id IN ($placeholders)", $action_ids ) );
                $deleted_actions = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$as_table} WHERE action_id IN ($placeholders)", $action_ids ) );
            }
            wp_send_json_success( [
                'message' => $deleted_actions . ' WPForms scheduled action(s) verwijderd, ' . $deleted_logs . ' loggegevens.',
            ] );
            break;

        case 'add_attribute':
        case 'remove_attribute':
        case 'replace_value':
            $before = wp_unslash( $_POST['before'] ?? '' );
            $after  = wp_unslash( $_POST['after'] ?? '' );
            if ( $before === '' ) {
                wp_send_json_error( 'before-waarde ontbreekt.' );
            }
            $post = get_post( $post_id );
            if ( ! $post ) {
                wp_send_json_error( 'Post niet gevonden.' );
            }
            if ( strpos( $post->post_content, $before ) === false ) {
                wp_send_json_error( 'Shortcode niet gevonden in post_content — mogelijk al gewijzigd.' );
            }
            $new_content = str_replace( $before, $after, $post->post_content );
            wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ] );
            wp_send_json_success( [ 'message' => 'Shortcode bijgewerkt in post #' . $post_id . '.' ] );
            break;

        default:
            wp_send_json_error( 'Onbekende fix-actie: ' . $action );
    }
} );

function aspera_humanize_rule( string $rule ): string {
    return ucwords( str_replace( '_', ' ', $rule ) );
}

/**
 * Bepaal admin-deeplink voor het oogje per violation.
 * Retourneert null wanneer er geen logische bestemming is.
 *
 * @return array{url:string,title:string}|null
 */
function aspera_get_violation_admin_link( string $category, string $rule, $post_id, string $detail = '' ) {
    // Eerst: post_id leidend voor categorieën die altijd een post bewerken
    $pid = (int) $post_id;
    if ( $pid > 0 ) {
        switch ( $category ) {
            case 'wpb':
            case 'grid':
            case 'forms':
            case 'colors':
            case 'naming':
            case 'header_config':
            case 'wpb_modules':
            case 'wpb_templates':
                $url = get_edit_post_link( $pid, 'raw' );
                if ( $url ) return [ 'url' => $url, 'title' => 'Open bewerk-pagina' ];
                break;
            case 'acf_fields':
            case 'acf_locations':
                $url = get_edit_post_link( $pid, 'raw' );
                if ( $url ) return [ 'url' => $url, 'title' => 'Open field group' ];
                break;
            case 'cpt':
                $url = get_edit_post_link( $pid, 'raw' );
                if ( $url ) return [ 'url' => $url, 'title' => 'Open post type' ];
                break;
        }
    }

    switch ( $category ) {
        case 'cpt':
            return [ 'url' => admin_url( 'edit.php?post_type=acf-post-type' ), 'title' => 'ACF Post Types' ];

        case 'taxonomy':
            return [ 'url' => admin_url( 'edit.php?post_type=acf-taxonomy' ), 'title' => 'ACF Taxonomies' ];

        case 'nav':
            return [ 'url' => admin_url( 'nav-menus.php' ), 'title' => "Menu's" ];

        case 'widgets':
            return [ 'url' => admin_url( 'widgets.php' ), 'title' => 'Widgets' ];

        case 'colors':
            return [ 'url' => admin_url( 'themes.php?page=us-theme-options&panel=colors' ), 'title' => 'Impreza colors' ];

        case 'cache':
            return [ 'url' => admin_url( 'options-general.php?page=wpfastestcacheoptions' ), 'title' => 'WP Fastest Cache' ];

        case 'theme_check':
            switch ( $rule ) {
                case 'impreza_license_inactive':
                    return [ 'url' => admin_url( 'themes.php?page=us-license-activation' ), 'title' => 'Impreza license' ];
                case 'theme_recaptcha_site_key_missing':
                case 'theme_recaptcha_secret_key_missing':
                    return [ 'url' => admin_url( 'themes.php?page=us-theme-options&panel=advanced' ), 'title' => 'Impreza theme options' ];
                default:
                    return [ 'url' => admin_url( 'themes.php' ), 'title' => 'Themes' ];
            }

        case 'wpb_modules':
            if ( $rule === 'beheerder_post_types_not_disabled' ) {
                return [ 'url' => admin_url( 'admin.php?page=vc-roles' ), 'title' => 'WPBakery Role Manager' ];
            }
            return null;

        case 'wp_settings':
            switch ( $rule ) {
                case 'search_engine_noindex':
                case 'posts_per_page_invalid':
                case 'posts_per_rss_invalid':
                case 'homepage_on_latest_posts':
                case 'homepage_missing':
                case 'homepage_unexpected_title':
                    return [ 'url' => admin_url( 'options-reading.php' ), 'title' => 'Reading settings' ];
                case 'permalink_structure_invalid':
                    return [ 'url' => admin_url( 'options-permalink.php' ), 'title' => 'Permalinks' ];
                case 'date_format_invalid':
                case 'timezone_invalid':
                case 'site_language_invalid':
                case 'start_of_week_invalid':
                case 'default_role_invalid':
                case 'users_can_register_enabled':
                case 'admin_email_invalid':
                    return [ 'url' => admin_url( 'options-general.php' ), 'title' => 'General settings' ];
                case 'missing_favicon':
                    return [ 'url' => admin_url( 'customize.php?autofocus[section]=title_tagline' ), 'title' => 'Site identity' ];
                case 'php_version_critical':
                case 'php_version_outdated':
                case 'php_memory_limit_low':
                    return [ 'url' => admin_url( 'site-health.php?tab=debug' ), 'title' => 'Site Health' ];
                case 'orphaned_wpforms_scheduled_actions':
                    return [ 'url' => admin_url( 'tools.php?page=action-scheduler' ), 'title' => 'Action Scheduler' ];
            }
            return null;
    }

    return null;
}

function aspera_get_rules_per_category(): array {
    static $reg = null;
    if ( $reg !== null ) return $reg;
    $reg = [
        'wpb' => [ 'hardcoded_label','hardcoded_image','hardcoded_link','empty_style_attr','missing_hide_empty','missing_color_link','missing_hide_with_empty_link','css_forbidden','design_css_forbidden','wrong_option_syntax','missing_acf_link','wrong_link_field_prefix','missing_el_class','missing_remove_rows','parent_row_with_siblings','hardcoded_bg_image','hardcoded_bg_video','hardcoded_text','empty_btn_style','scroll_effect_forbidden','vc_video_wrong_attribute','missing_columns_reverse','unexpected_columns_reverse','wpforms_deprecated','animate_detected','responsive_hide_detected' ],
        'grid' => [ 'image_lazy_loading_enabled','image_missing_homepage_link','image_has_ratio','image_has_style','image_wrong_size' ],
        'colors' => [ 'deprecated_hex_var','deprecated_custom_var','hardcoded_hex_color','deprecated_theme_var','unknown_theme_var','rgba_color' ],
        'forms' => [ 'cform_inbound_disabled','missing_receiver_email','hardcoded_receiver_email','missing_button_text','hardcoded_button_text','empty_button_style','missing_success_message','hardcoded_success_message','missing_email_subject','missing_email_message','missing_field_list','missing_recaptcha','missing_email_field','wrong_email_field_type','missing_move_label','empty_option_field' ],
        'plugins' => [ 'extra_plugin' ],
        'cpt' => [ 'missing_rest','default_icon','duplicate_icon','empty_labels','unexpected_supports','missing_title_support','nav_menus_no_frontend','cptui_leftover' ],
        'db_tables' => [ 'orphaned_table','unknown_table','orphaned_post_type','orphaned_plugin_options','orphaned_plugin_meta' ],
        'css' => [ 'unused_css_class','wrong_css_prefix' ],
        'nav' => [ 'unused_nav_menu','broken_menu_reference','invalid_menu_name','mismatched_menu_placement','external_link_no_target_blank','page_not_in_menu','custom_menu_label' ],
        'wpb_modules' => [ 'wpb_post_custom_css','wpb_post_custom_js','wpb_module_active','beheerder_post_types_not_disabled' ],
        'theme_breakpoints' => [ 'breakpoint_mobile_group_mismatch','breakpoint_order_invalid','breakpoint_convention_deviation','breakpoint_exceeds_content_width','laptops_breakpoint_mismatch' ],
        'widgets' => [ 'widgetised_sidebar_in_template','extra_widget_area','default_sidebar_not_empty','active_widget_text','active_widget_nav_menu','active_widget_other' ],
        'wpb_templates' => [ 'wpb_saved_templates' ],
        'taxonomy' => [ 'orphaned_taxonomy','orphaned_taxonomy_has_dependencies' ],
        'header_config' => [ 'custom_breakpoint_invalid_order','custom_breakpoint_exceeds_content_width','custom_breakpoint_active','orientation_vertical_forbidden','menu_mobile_always','menu_mobile_exceeds_content_width','menu_mobile_exceeds_breakpoints','menu_mobile_behavior_not_label_and_arrow','menu_mobile_icon_size_too_large','menu_mobile_icon_size_inconsistent','menu_align_edges_mismatch','scroll_breakpoint_inconsistent','centering_missing','centering_unexpected','header_element_unused' ],
        'acf_fields' => [ 'missing_name','broken_conditional_reference','mixed_choice_key_types','wysiwyg_media_upload_enabled','wrong_group_name_prefix' ],
        'acf_locations' => [ 'orphaned_location_taxonomy','orphaned_location_term','empty_location_term' ],
        'meta_orphaned' => [ 'orphaned_meta','orphaned_meta_in_templates' ],
        'options_orphaned' => [ 'orphaned_option' ],
        'naming' => [ 'wrong_template_prefix','wrong_block_prefix','deprecated_page_block_term' ],
        'options_config' => [ 'wrong_option_slug','wrong_option_position','wrong_option_icon' ],
        'acf_slugs' => [ 'missing_number','wrong_opt_format','wrong_cpt_format','wrong_page_format','wrong_cpt_format_multi','wrong_page_format_multi' ],
        'theme_check' => [ 'wrong_active_theme','impreza_license_inactive','unauthorized_installed_theme','theme_recaptcha_site_key_missing','theme_recaptcha_secret_key_missing' ],
        'wp_settings' => [ 'search_engine_noindex','missing_favicon','permalink_structure_invalid','posts_per_page_invalid','posts_per_rss_invalid','homepage_on_latest_posts','homepage_missing','homepage_unexpected_title','date_format_invalid','timezone_invalid','site_language_invalid','start_of_week_invalid','default_role_invalid','users_can_register_enabled','admin_email_invalid','php_version_critical','php_version_outdated','php_memory_limit_low','orphaned_wpforms_scheduled_actions' ],
        'cache' => [ 'cache_disabled','cache_preload_disabled','cache_preload_homepage_missing','cache_preload_post_missing','cache_preload_page_missing','cache_preload_cpt_missing','cache_preload_threads_missing','cache_preload_restart_missing','cache_purge_on_new_post_missing','cache_purge_on_update_post_missing','cache_minify_html_disabled','cache_minify_css_disabled','cache_combine_css_disabled','cache_minify_js_enabled','cache_combine_js_enabled','cache_gzip_disabled','cache_browser_caching_disabled','cache_emojis_enabled','cache_mobile_theme_enabled','cache_logged_in_user_enabled','cache_timeout_missing','cache_timeout_not_daily','cache_timeout_scope_partial','cache_language_not_english','cache_toolbar_admin_only_missing' ],
    ];
    return $reg;
}

function aspera_get_rule_context(): array {
    static $ctx = null;
    if ( $ctx !== null ) return $ctx;
    $ctx = [
        // ── Cache (WP Fastest Cache) ──────────────────────────────────────
        'cache_disabled' => [ 'label' => 'WP Fastest Cache hoofdstatus uit', 'explanation' => 'Master cache-toggle staat op off; geen enkele pagina wordt gecached.', 'action' => 'WP Fastest Cache > Settings > vink "Cache System" aan.' ],
        'cache_preload_disabled' => [ 'label' => 'Preload uit', 'explanation' => 'Cache-pages worden niet vooraf opgebouwd; eerste bezoeker krijgt langzame uncached response.', 'action' => 'WP Fastest Cache > Preload > activeer Preload + sub-opties (homepage, post, page, customposttypes).' ],
        'cache_preload_homepage_missing' => [ 'label' => 'Homepage niet in preload', 'explanation' => 'De homepage wordt niet vooraf gecached.', 'action' => 'WP Fastest Cache > Preload > vink Homepage aan.' ],
        'cache_preload_post_missing' => [ 'label' => 'Posts niet in preload', 'explanation' => 'Posts worden niet vooraf gecached.', 'action' => 'WP Fastest Cache > Preload > vink Posts aan.' ],
        'cache_preload_page_missing' => [ 'label' => 'Pages niet in preload', 'explanation' => 'Pages worden niet vooraf gecached.', 'action' => 'WP Fastest Cache > Preload > vink Pages aan.' ],
        'cache_preload_cpt_missing' => [ 'label' => 'Custom post types niet in preload', 'explanation' => 'CPT-posts worden niet vooraf gecached.', 'action' => 'WP Fastest Cache > Preload > vink Custom Post Types aan.' ],
        'cache_preload_threads_missing' => [ 'label' => 'Preload-threads niet ingesteld', 'explanation' => 'Aantal parallelle preload-threads is leeg.', 'action' => 'WP Fastest Cache > Preload > stel een waarde in (default 4).' ],
        'cache_preload_restart_missing' => [ 'label' => 'Preload herstart na cache-clear uit', 'explanation' => 'Na een cache-clear wordt preload niet opnieuw uitgevoerd; site blijft uncached tot bezoek.', 'action' => 'WP Fastest Cache > Preload > vink "Restart after completed" aan.' ],
        'cache_purge_on_new_post_missing' => [ 'label' => 'Cache wordt niet gewist bij nieuwe post', 'explanation' => 'Bij publicatie van nieuwe content blijft oude cache staan; nieuwe content niet zichtbaar voor bezoekers.', 'action' => 'WP Fastest Cache > Delete Cache > vink "Clear cache when a post is published" aan.' ],
        'cache_purge_on_update_post_missing' => [ 'label' => 'Cache wordt niet gewist bij update post', 'explanation' => 'Bij content-update blijft oude cache staan; wijzigingen niet zichtbaar.', 'action' => 'WP Fastest Cache > Delete Cache > vink "Clear cache when a post is updated" aan.' ],
        'cache_minify_html_disabled' => [ 'label' => 'Minify HTML uit', 'explanation' => 'HTML-output wordt niet gecomprimeerd; gemiste performance-winst.', 'action' => 'WP Fastest Cache > Settings > vink "Minify HTML" aan.' ],
        'cache_minify_css_disabled' => [ 'label' => 'Minify CSS uit', 'explanation' => 'CSS wordt niet gecomprimeerd.', 'action' => 'WP Fastest Cache > Settings > vink "Minify CSS" aan.' ],
        'cache_combine_css_disabled' => [ 'label' => 'Combine CSS uit', 'explanation' => 'Meerdere CSS-bestanden worden niet samengevoegd; meer HTTP-requests.', 'action' => 'WP Fastest Cache > Settings > vink "Combine CSS" aan.' ],
        'cache_minify_js_enabled' => [ 'label' => 'Minify JS staat aan (moet uit)', 'explanation' => 'Minify JS breekt vaak reCAPTCHA en formulier-scripts; voor Aspera-sites bewust uit.', 'action' => 'WP Fastest Cache > Settings > Minify JS uitvinken.' ],
        'cache_combine_js_enabled' => [ 'label' => 'Combine JS staat aan (moet uit)', 'explanation' => 'Combine JS breekt vaak reCAPTCHA en formulier-scripts; voor Aspera-sites bewust uit.', 'action' => 'WP Fastest Cache > Settings > Combine JS uitvinken.' ],
        'cache_gzip_disabled' => [ 'label' => 'Gzip-compressie uit', 'explanation' => 'Server-output wordt niet gecomprimeerd; tragere transfer.', 'action' => 'WP Fastest Cache > Settings > vink "Gzip" aan.' ],
        'cache_browser_caching_disabled' => [ 'label' => 'Browser caching uit', 'explanation' => 'Browsers krijgen geen cache-headers; statics moeten elke keer opnieuw geladen.', 'action' => 'WP Fastest Cache > Settings > vink "Leverage Browser Caching" aan.' ],
        'cache_emojis_enabled' => [ 'label' => 'Emoji-script wordt geladen', 'explanation' => 'WP-emoji-fallback-script (~17KB) wordt op elke pagina geladen; nutteloos voor moderne browsers.', 'action' => 'WP Fastest Cache > Settings > vink "Disable Emojis" aan.' ],
        'cache_mobile_theme_enabled' => [ 'label' => 'Aparte mobiele cache aan (moet uit)', 'explanation' => 'Aparte mobile cache is voor sites met aparte mobiele theme; niet van toepassing bij responsive design.', 'action' => 'WP Fastest Cache > Settings > "Mobile Theme" uitvinken.' ],
        'cache_logged_in_user_enabled' => [ 'label' => 'Cache voor ingelogde users aan', 'explanation' => 'Cachen voor ingelogde users veroorzaakt cross-user-leaks (mini-cart, account-data, nonces) en is een privacy/security-risico, vooral bij WooCommerce.', 'action' => 'WP Fastest Cache > Settings > "Logged-in Users" uitvinken.' ],
        'cache_timeout_missing' => [ 'label' => 'Geen cache-timeout ingesteld', 'explanation' => 'Zonder timeout-rule blijft cache oneindig staan; stale content op stille content-mutaties (widgets, menu).', 'action' => 'WP Fastest Cache > Delete Cache > Timeout Rules > voeg dagelijkse "all/all" rule toe op 00:00.' ],
        'cache_timeout_not_daily' => [ 'label' => 'Cache-timeout niet dagelijks', 'explanation' => 'Standaard is 1× per dag (onceaday/86400). Vaker = onnodig veel preload-werk; zelden = stale content.', 'action' => 'WP Fastest Cache > Delete Cache > Timeout Rules > stel "Once a Day" in.' ],
        'cache_timeout_scope_partial' => [ 'label' => 'Timeout-rule purge-scope onvolledig', 'explanation' => 'Timeout-rule wist niet alle cache; sommige content blijft stale.', 'action' => 'WP Fastest Cache > Delete Cache > Timeout Rules > zet prefix=all en content=all.' ],
        'cache_language_not_english' => [ 'label' => 'Plugin-taal niet Engels', 'explanation' => 'Admin-interface van WP Fastest Cache is niet op Engels gezet; visueel inconsistent met overige Aspera-conventie.', 'action' => 'WP Fastest Cache > Languages > kies English (US) of English (UK).' ],
        'cache_toolbar_admin_only_missing' => [ 'label' => 'Admin-toolbar niet beperkt tot beheerder', 'explanation' => 'Cache-toolbar is voor andere user-rollen zichtbaar; klanten zien admin-tooling.', 'action' => 'WP Fastest Cache > Toolbar > activeer "Admin only".' ],

        // ── ACF slugs / cross-context ─────────────────────────────────────
        'wrong_cpt_format' => [ 'label' => 'ACF-veldnaam mist CPT-prefix', 'explanation' => 'Een veld dat alleen op één CPT gebruikt wordt hoort de format "<cpt>_<context>_<num>" te volgen (bv. "casinovestiging_blok_1").', 'action' => 'Hernoem het veld in ACF zodat het start met de CPT-slug.' ],
        'wrong_cpt_format_multi' => [ 'label' => 'ACF-veld in meerdere field groups (cross-context)', 'explanation' => 'Een veld komt in 2+ field groups voor over verschillende post types. Normaal bij hergebruik (bv. "afbeelding_block_1" op meerdere CPTs). Als observation gemeld omdat geen CPT-prefix nodig is.', 'action' => 'Bevestigen dat cross-context bewust is. Geen actie tenzij onbedoelde duplicatie.' ],
        'wrong_page_format' => [ 'label' => 'ACF-veldnaam voor pagina mist juiste prefix', 'explanation' => 'Pagina-specifieke velden horen format "p_<context>_<num>" te volgen.', 'action' => 'Hernoem het veld in ACF.' ],
        'wrong_page_format_multi' => [ 'label' => 'ACF-veld op meerdere pages (cross-context)', 'explanation' => 'Een veld wordt op meerdere pages gebruikt; geen p_-prefix vereist.', 'action' => 'Bevestigen dat cross-page-gebruik bewust is.' ],
        'wrong_opt_format' => [ 'label' => 'ACF option page veldnaam mist opt_-prefix', 'explanation' => 'Velden op een ACF option page horen "opt_<naam>" te zijn.', 'action' => 'Hernoem het veld in ACF.' ],
        'missing_number' => [ 'label' => 'ACF-veldnaam mist volgnummer', 'explanation' => 'Veldnaam eindigt niet op "_<nummer>" — Aspera-conventie vereist volgnummer voor sortering en uniciteit.', 'action' => 'Hernoem het veld met _1, _2 etc. aan het einde.' ],
        'missing_name' => [ 'label' => 'ACF-veld zonder naam', 'explanation' => 'Field heeft geen naam-attribuut.', 'action' => 'Open de field group in ACF en geef het veld een naam.' ],
        'wrong_group_name_prefix' => [ 'label' => 'ACF field group naam zonder juiste prefix', 'explanation' => 'Field group naam volgt niet de Aspera prefix-conventie (CPT/Page/Opt).', 'action' => 'Hernoem de field group in ACF.' ],
        'broken_conditional_reference' => [ 'label' => 'ACF conditional logic verwijst naar niet-bestaand veld', 'explanation' => 'Een veld heeft conditional-logic die wijst naar een veld dat niet (meer) bestaat.', 'action' => 'Open de field group, los de conditional logic op (verwijder of pas aan).' ],
        'mixed_choice_key_types' => [ 'label' => 'ACF select-veld met gemixte key-types', 'explanation' => 'Choices in een select hebben deels numerieke deels string-keys; lastig te queryen.', 'action' => 'Standaardiseer choice-keys (alle string of alle numeriek).' ],
        'wysiwyg_media_upload_enabled' => [ 'label' => 'ACF wysiwyg met media-upload aan', 'explanation' => 'Een wysiwyg-veld laat media-upload toe; klanten kunnen ongecontroleerd images plaatsen.', 'action' => 'ACF veld > Toolbar > zet "Media Upload" uit.' ],

        // ── Orphaned ─────────────────────────────────────────────────────
        'orphaned_meta' => [ 'label' => 'Verweesde post-meta', 'explanation' => 'Een meta_key wordt nergens meer in field groups, templates of code gerefereerd; alleen historische data in postmeta.', 'action' => 'Beoordeel of de meta verwijderd kan; gebruik fix-button om alle rijen te verwijderen.' ],
        'orphaned_meta_in_templates' => [ 'label' => 'Verweesde meta in templates', 'explanation' => 'Een meta_key komt nog voor in WPBakery-templates ondanks dat de field group hem niet meer kent.', 'action' => 'Verwijder de referentie uit de template eerst, dan meta opruimen.' ],
        'orphaned_table' => [ 'label' => 'Verweesde database-tabel', 'explanation' => 'Een tabel hoort bij een plugin/feature die niet meer actief is.', 'action' => 'Bevestig dat de tabel niet meer nodig is en drop hem.' ],
        'unknown_table' => [ 'label' => 'Onbekende database-tabel', 'explanation' => 'Een tabel is niet herkend uit de Aspera-whitelist; mogelijk onbekende plugin of legacy.', 'action' => 'Onderzoek herkomst en classificeer (governed/orphaned).' ],
        'orphaned_post_type' => [ 'label' => 'Verweesde posts van inactief post-type', 'explanation' => 'Posts in een post-type dat niet meer geregistreerd is.', 'action' => 'Onderzoek herkomst; verwijderen of post-type herregistreren.' ],
        'orphaned_plugin_options' => [ 'label' => 'Options van inactieve plugin', 'explanation' => 'wp_options-rijen van een plugin die niet meer actief is.', 'action' => 'Beoordeel of options nog nodig (deactivatie tijdelijk?) en verwijder anders.' ],
        'orphaned_plugin_meta' => [ 'label' => 'Postmeta van inactieve plugin', 'explanation' => 'Postmeta-rijen van een plugin die niet meer actief is.', 'action' => 'Beoordeel en verwijder.' ],
        'orphaned_taxonomy' => [ 'label' => 'Verweesde taxonomy', 'explanation' => 'Een taxonomy is niet meer geregistreerd maar terms en relaties bestaan nog.', 'action' => 'Verwijder ongebruikte terms of herregistreer de taxonomy.' ],
        'orphaned_taxonomy_has_dependencies' => [ 'label' => 'Verweesde taxonomy met dependencies', 'explanation' => 'Taxonomy is verdwenen maar er zijn nog posts/term-relations gekoppeld; opruimen vereist eerst dependency-resolutie.', 'action' => 'Identificeer afhankelijke posts; verwijder relaties voor je de taxonomy laat staan.' ],
        'orphaned_option' => [ 'label' => 'Verweesde wp_option', 'explanation' => 'Een wp_options-key matcht geen actieve plugin/theme.', 'action' => 'Beoordeel en verwijder indien legacy.' ],
        'orphaned_location_taxonomy' => [ 'label' => 'ACF location-rule wijst naar inactieve taxonomy', 'explanation' => 'Een field group heeft een location-rule die verwijst naar een taxonomy die niet bestaat.', 'action' => 'Verwijder de location-rule of herregistreer de taxonomy.' ],
        'orphaned_location_term' => [ 'label' => 'ACF location-rule wijst naar verwijderde term', 'explanation' => 'Location-rule wijst naar een specifieke term die niet meer bestaat.', 'action' => 'Pas de location-rule aan.' ],
        'empty_location_term' => [ 'label' => 'ACF location-rule wijst naar lege term', 'explanation' => 'Location-rule verwijst naar een term zonder posts.', 'action' => 'Bevestig of dit de bedoeling is; eventueel verwijderen.' ],
        'orphaned_wpforms_scheduled_actions' => [ 'label' => 'WPForms scheduled actions terwijl plugin inactief', 'explanation' => 'In de actionscheduler-tabellen staan WPForms-hooks terwijl WPForms niet (meer) actief is. Deze blijven failen en groeien onbeperkt.', 'action' => 'Gebruik de fix-button om alle WPForms scheduled actions en logs op te ruimen.' ],

        // ── Hardcoded content ─────────────────────────────────────────────
        'hardcoded_label' => [ 'label' => 'Hardcoded button-label in shortcode', 'explanation' => 'Een button heeft een vaste tekst i.p.v. via ACF-veld; niet vertaalbaar of beheerbaar via dashboard.', 'action' => 'Vervang door us_post_custom_field shortcode met ACF-koppeling.' ],
        'hardcoded_image' => [ 'label' => 'Hardcoded afbeelding (us_image)', 'explanation' => 'Image-shortcode heeft vaste image_id in plaats van ACF.', 'action' => 'Vervang door ACF-gekoppelde image, of zet via us_image image="{{acf_field}}".' ],
        'hardcoded_link' => [ 'label' => 'Hardcoded link in button/element', 'explanation' => 'Link is vaste URL i.p.v. ACF link-veld; niet via dashboard aanpasbaar.', 'action' => 'Vervang door ACF link-veld referentie.' ],
        'hardcoded_text' => [ 'label' => 'Hardcoded tekst in shortcode', 'explanation' => 'Tekst is vast in shortcode i.p.v. via ACF.', 'action' => 'Vervang door ACF-veld referentie.' ],
        'hardcoded_bg_image' => [ 'label' => 'Hardcoded achtergrond-afbeelding', 'explanation' => 'Row/section heeft vaste bg_image i.p.v. ACF-koppeling.', 'action' => 'Vervang door ACF-image referentie.' ],
        'hardcoded_bg_video' => [ 'label' => 'Hardcoded achtergrond-video', 'explanation' => 'Row heeft vaste video-URL i.p.v. ACF.', 'action' => 'Vervang door ACF-veld of verwijder de video.' ],
        'hardcoded_button_text' => [ 'label' => 'Hardcoded button-tekst in formulier', 'explanation' => 'Submit-button-tekst is vast i.p.v. via opt_forms option.', 'action' => 'Verplaats naar option page (opt_forms) en refereer dynamisch.' ],
        'hardcoded_receiver_email' => [ 'label' => 'Hardcoded e-mailadres in formulier', 'explanation' => 'Receiver e-mail is vast in shortcode; niet centraal beheerbaar.', 'action' => 'Verplaats naar opt_forms option page.' ],
        'hardcoded_success_message' => [ 'label' => 'Hardcoded success-message in formulier', 'explanation' => 'Bevestigingstekst is vast in shortcode.', 'action' => 'Verplaats naar opt_forms option.' ],
        'hardcoded_hex_color' => [ 'label' => 'Hardcoded hex-kleur', 'explanation' => 'Een vaste kleurcode (#bd0000 etc.) i.p.v. theme-variabele. Niet centraal aanpasbaar.', 'action' => 'Vervang door theme color var (bv. _content_primary).' ],

        // ── Forms / reCAPTCHA / Cookies ───────────────────────────────────
        'missing_recaptcha' => [ 'label' => 'Formulier zonder reCAPTCHA', 'explanation' => 'Het formulier heeft geen reCAPTCHA-veld; spam-risico.', 'action' => 'Voeg een reCAPTCHA-veld toe in de us_cform shortcode.' ],
        'missing_receiver_email' => [ 'label' => 'Formulier zonder ontvanger-e-mailadres', 'explanation' => 'Geen receiver_email gedefinieerd; submissions gaan nergens heen.', 'action' => 'Voeg receiver_email toe via opt_forms.' ],
        'missing_email_field' => [ 'label' => 'Formulier zonder e-mail-veld', 'explanation' => 'Geen email-input; de gebruiker kan niet teruggebeld worden.', 'action' => 'Voeg een email-veld toe.' ],
        'missing_button_text' => [ 'label' => 'Formulier zonder button-tekst', 'explanation' => 'Submit-button heeft geen tekst.', 'action' => 'Voeg button-tekst toe via opt_forms.' ],
        'missing_email_subject' => [ 'label' => 'Formulier zonder e-mail-onderwerp', 'explanation' => 'Notificatie-mail heeft geen subject.', 'action' => 'Voeg email_subject toe.' ],
        'missing_email_message' => [ 'label' => 'Formulier zonder e-mail-bericht-template', 'explanation' => 'Geen email_message gedefinieerd.', 'action' => 'Voeg email_message toe.' ],
        'missing_field_list' => [ 'label' => 'Formulier zonder field_list', 'explanation' => 'field_list ontbreekt; mail-output toont niet welke velden ingevuld zijn.', 'action' => 'Voeg field_list toe in shortcode.' ],
        'missing_success_message' => [ 'label' => 'Formulier zonder success-message', 'explanation' => 'Geen bevestigingstekst na submit.', 'action' => 'Voeg success_message toe via opt_forms.' ],
        'cform_inbound_disabled' => [ 'label' => 'us_cform inbound-mailbox uit', 'explanation' => 'Inbound-mail-opslag uit; submissions worden niet bewaard, alleen gemaild.', 'action' => 'us_cform shortcode > zet inbound="true".' ],
        'wpforms_deprecated' => [ 'label' => 'Deprecated WPForms shortcode', 'explanation' => '[wpforms]-shortcode wordt niet meer gebruikt; vervang door us_cform.', 'action' => 'Migreer naar us_cform met dezelfde velden.' ],

        // ── WPBakery / Modules / Widgets ──────────────────────────────────
        'wpb_module_active' => [ 'label' => 'WPBakery module actief', 'explanation' => 'Een WPBakery Module Manager module is aan; alle modules horen uit op Aspera-sites.', 'action' => 'WPBakery > Role Manager > Module Manager > schakel alle modules uit.' ],
        'wpb_post_custom_css' => [ 'label' => 'WPBakery Custom CSS op post', 'explanation' => 'Per-post Custom CSS is gedefinieerd; CSS hoort centraal in theme/Custom Code.', 'action' => 'Verplaats CSS naar theme of Impreza Custom CSS.' ],
        'wpb_post_custom_js' => [ 'label' => 'WPBakery Custom JS op post', 'explanation' => 'Per-post Custom JS gedefinieerd; JS hoort centraal.', 'action' => 'Verplaats JS naar theme of header/footer template.' ],
        'wpb_saved_templates' => [ 'label' => 'Opgeslagen WPBakery templates aanwezig', 'explanation' => 'Templates in WPBakery template library; meestal niet meer nodig.', 'action' => 'WPBakery > My Templates > opruimen.' ],
        'beheerder_post_types_not_disabled' => [ 'label' => 'Beheerder-rol heeft post types niet disabled', 'explanation' => 'Voor WPBakery Role Manager moet de "beheerder"-rol post types op disabled hebben (vc_access_rules_post_types=false).', 'action' => 'WPBakery > Role Manager > Beheerder > Post Types > zet op "None".' ],
        'default_sidebar_not_empty' => [ 'label' => 'Default sidebar bevat widgets', 'explanation' => 'WordPress default sidebar heeft widgets; widgets horen niet gebruikt te worden.', 'action' => 'Appearance > Widgets > leeg de default sidebar.' ],
        'extra_widget_area' => [ 'label' => 'Extra widget area gedefinieerd', 'explanation' => 'Een extra (Impreza) widget area is gedefinieerd; widgets niet toegestaan.', 'action' => 'Verwijder de extra widget area.' ],
        'active_widget_text' => [ 'label' => 'Actieve text-widget', 'explanation' => 'Tekstwidget in een sidebar.', 'action' => 'Verwijder of migreer inhoud naar opt_widgets.' ],
        'active_widget_nav_menu' => [ 'label' => 'Actieve nav-menu-widget', 'explanation' => 'Menu-widget in sidebar.', 'action' => 'Verwijder; navigatie hoort in header/footer-template.' ],
        'active_widget_other' => [ 'label' => 'Andere actieve widget', 'explanation' => 'Niet-text/menu widget actief.', 'action' => 'Verwijder.' ],
        'widgetised_sidebar_in_template' => [ 'label' => 'Sidebar-shortcode in template', 'explanation' => 'us_sidebar shortcode in een WPBakery-template; widgets horen niet gebruikt.', 'action' => 'Verwijder us_sidebar shortcode.' ],

        // ── Theme / Header / Permalinks / Settings ────────────────────────
        'wrong_active_theme' => [ 'label' => 'Verkeerd actief thema', 'explanation' => 'Actief thema is niet Aspera (Child); alleen Aspera mag actief zijn.', 'action' => 'Appearance > Themes > activeer Aspera Child.' ],
        'impreza_license_inactive' => [ 'label' => 'Impreza licentie niet geactiveerd', 'explanation' => 'us_license_activated option staat niet op 1; theme-updates en addons werken niet.', 'action' => 'Impreza > Licentie activeren met purchase-code.' ],
        'unauthorized_installed_theme' => [ 'label' => 'Geïnstalleerd thema niet toegestaan', 'explanation' => 'Een geïnstalleerd thema buiten Aspera/Impreza-set; ruimt zelden iets op maar voorkomt confusion.', 'action' => 'Appearance > Themes > verwijder.' ],
        'theme_recaptcha_site_key_missing' => [ 'label' => 'reCAPTCHA site key leeg in theme', 'explanation' => 'Impreza theme heeft geen reCAPTCHA site_key terwijl er formulieren met reCAPTCHA bestaan; formulieren werken niet.', 'action' => 'Impreza > Theme Options > reCAPTCHA > vul site_key in.' ],
        'theme_recaptcha_secret_key_missing' => [ 'label' => 'reCAPTCHA secret key leeg in theme', 'explanation' => 'Impreza theme mist reCAPTCHA secret_key; formulieren werken niet.', 'action' => 'Impreza > Theme Options > reCAPTCHA > vul secret_key in.' ],
        'permalink_structure_invalid' => [ 'label' => 'Permalink-structuur niet conform', 'explanation' => 'Permalink-structure is iets anders dan /%postname%/ of /%category%/%postname%/.', 'action' => 'Settings > Permalinks > kies "Post name".' ],
        'posts_per_page_invalid' => [ 'label' => 'Posts per pagina afwijkend', 'explanation' => 'Settings > Reading > Blog pages show at most ≠ 12.', 'action' => 'Settings > Reading > stel in op 12.' ],
        'posts_per_rss_invalid' => [ 'label' => 'Syndication feed-aantal afwijkend', 'explanation' => 'Syndication feeds show ≠ 12.', 'action' => 'Settings > Reading > stel in op 12.' ],
        'homepage_on_latest_posts' => [ 'label' => 'Homepage staat op latest posts', 'explanation' => 'Voorpagina toont laatste posts in plaats van static page.', 'action' => 'Settings > Reading > kies "A static page".' ],
        'homepage_missing' => [ 'label' => 'Static homepage ontbreekt of unpublished', 'explanation' => 'Voorpagina is op static page gezet maar de pagina bestaat niet of is niet gepubliceerd.', 'action' => 'Settings > Reading > selecteer een gepubliceerde page als front page.' ],
        'homepage_unexpected_title' => [ 'label' => 'Homepage-pagina-titel ongebruikelijk', 'explanation' => 'Static homepage heet niet "Home" of "Homepage".', 'action' => 'Hernoem of bevestig dat afwijkende titel bewust is.' ],
        'date_format_invalid' => [ 'label' => 'Datum-format afwijkend', 'explanation' => 'Settings > General > Date Format ≠ "j F Y".', 'action' => 'Settings > General > Custom > j F Y.' ],
        'timezone_invalid' => [ 'label' => 'Tijdzone afwijkend', 'explanation' => 'Site-tijdzone ≠ Europe/Amsterdam.', 'action' => 'Settings > General > Timezone > Amsterdam.' ],
        'site_language_invalid' => [ 'label' => 'Site-taal afwijkend', 'explanation' => 'get_locale() niet in [nl_NL, en_US, en_GB].', 'action' => 'Settings > General > Site Language > Nederlands of English.' ],
        'start_of_week_invalid' => [ 'label' => 'Eerste dag van de week niet maandag', 'explanation' => 'start_of_week ≠ 1 (maandag).', 'action' => 'Settings > General > Week Starts On > Monday.' ],
        'default_role_invalid' => [ 'label' => 'Default user-rol niet subscriber', 'explanation' => 'New User Default Role ≠ subscriber.', 'action' => 'Settings > General > New User Default Role > Subscriber.' ],
        'users_can_register_enabled' => [ 'label' => 'Membership-registratie aan', 'explanation' => 'Anyone can register staat aan; ongewenste user-creatie mogelijk.', 'action' => 'Settings > General > Membership > vink uit.' ],
        'admin_email_invalid' => [ 'label' => 'Admin-email afwijkend', 'explanation' => 'admin_email ≠ wp@asperagrafica.nl.', 'action' => 'Settings > General > Administration Email Address > wp@asperagrafica.nl.' ],
        'php_version_critical' => [ 'label' => 'PHP-versie kritiek verouderd', 'explanation' => 'PHP < 8.0; security-risico en compatibiliteitsproblemen.', 'action' => 'Hosting > PHP-versie naar 8.4.' ],
        'php_version_outdated' => [ 'label' => 'PHP-versie verouderd', 'explanation' => 'PHP < 8.4 (aanbevolen).', 'action' => 'Hosting > PHP-versie naar 8.4.' ],
        'php_memory_limit_low' => [ 'label' => 'PHP memory limit te laag', 'explanation' => 'memory_limit < 128M; complexe pagina\'s kunnen crashen.', 'action' => 'Hosting > verhoog memory_limit naar 256M.' ],
        'search_engine_noindex' => [ 'label' => 'Site geblokkeerd voor zoekmachines', 'explanation' => 'blog_public=0; site indexeert niet.', 'action' => 'Settings > Reading > "Discourage search engines" uitvinken.' ],
        'missing_favicon' => [ 'label' => 'Favicon ontbreekt', 'explanation' => 'Site Icon is niet ingesteld.', 'action' => 'Settings > General > Site Icon > upload.' ],

        // ── Header / Breakpoints ──────────────────────────────────────────
        'breakpoint_mobile_group_mismatch' => [ 'label' => 'Mobile-groep breakpoints onjuist', 'explanation' => 'Theme- en grid-mobile breakpoints kloppen niet met elkaar.', 'action' => 'Impreza > Theme Options > breakpoints harmoniseren.' ],
        'breakpoint_order_invalid' => [ 'label' => 'Breakpoints in verkeerde volgorde', 'explanation' => 'Mobile > tablet > laptop volgorde klopt niet.', 'action' => 'Pas breakpoint-waarden aan.' ],
        'breakpoint_exceeds_content_width' => [ 'label' => 'Breakpoint groter dan content-width', 'explanation' => 'Een breakpoint is groter dan de site-content-width; layout-bug.', 'action' => 'Verlaag breakpoint of verhoog content-width.' ],
        'laptops_breakpoint_mismatch' => [ 'label' => 'Laptops-breakpoint inconsistent', 'explanation' => 'Laptops-breakpoint matcht niet de theme-conventie.', 'action' => 'Theme Options > stel laptops_breakpoint in op 1400px.' ],
        'orientation_vertical_forbidden' => [ 'label' => 'Header in vertical orientation', 'explanation' => 'Header staat op vertical; alleen horizontal toegestaan.', 'action' => 'Header builder > orientation > Horizontal.' ],

        // ── Naming ────────────────────────────────────────────────────────
        'wrong_template_prefix' => [ 'label' => 'us_content_template naam zonder juiste prefix', 'explanation' => 'Template heet niet "Page - X", "CPT - X" of "TAX - X".', 'action' => 'Hernoem template volgens conventie.' ],
        'wrong_block_prefix' => [ 'label' => 'us_page_block naam zonder juiste prefix', 'explanation' => 'Page-block volgt niet "Block - X" conventie.', 'action' => 'Hernoem het page-block.' ],
        'deprecated_page_block_term' => [ 'label' => 'Deprecated page_block-term', 'explanation' => 'Een term gebruikt verouderde naamgeving.', 'action' => 'Hernoem term.' ],

        // ── Options config ────────────────────────────────────────────────
        'wrong_option_slug' => [ 'label' => 'ACF option page slug ongebruikelijk', 'explanation' => 'Slug volgt niet de opt_-conventie.', 'action' => 'Pas option page slug aan.' ],
        'wrong_option_position' => [ 'label' => 'ACF option page positie afwijkend', 'explanation' => 'Menu-positie van option page is ongebruikelijk.', 'action' => 'Pas position-attribuut aan.' ],
        'wrong_option_icon' => [ 'label' => 'ACF option page icon afwijkend', 'explanation' => 'Dashicon ongebruikelijk voor de inhoud.', 'action' => 'Kies passende dashicon.' ],

        // ── Colors ────────────────────────────────────────────────────────
        'deprecated_hex_var' => [ 'label' => 'Deprecated hex-kleurvar', 'explanation' => 'Een verouderde hex-variabele in shortcode (bv. oude Aspera-rood).', 'action' => 'Vervang door huidige theme color var.' ],
        'deprecated_custom_var' => [ 'label' => 'Deprecated custom-kleurvar', 'explanation' => 'Verouderde custom kleur-naam.', 'action' => 'Migreer naar huidige naming.' ],
        'deprecated_theme_var' => [ 'label' => 'Deprecated theme-kleurvar', 'explanation' => 'Theme color var is verouderd.', 'action' => 'Vervang door huidige.' ],
        'unknown_theme_var' => [ 'label' => 'Onbekende theme-kleurvar', 'explanation' => 'Verwijst naar theme color var die niet meer bestaat.', 'action' => 'Pas naar bestaande var aan.' ],
        'rgba_color' => [ 'label' => 'Rgba-kleur', 'explanation' => 'rgba(...) gebruikt; werkt maar minder consistent dan theme var met opacity.', 'action' => 'Optioneel: vervang door theme var.' ],

        // ── Plugins ───────────────────────────────────────────────────────
        'extra_plugin' => [ 'label' => 'Extra plugin actief', 'explanation' => 'Plugin niet in Aspera-whitelist; bewust of legacy?', 'action' => 'Beoordeel of plugin nodig is, anders deactiveren/verwijderen.' ],

        // ── CPT ───────────────────────────────────────────────────────────
        'missing_rest' => [ 'label' => 'CPT zonder REST-API support', 'explanation' => 'show_in_rest=false; CPT niet via REST/Gutenberg/MCP toegankelijk.', 'action' => 'CPT-registratie > zet show_in_rest op true.' ],
        'default_icon' => [ 'label' => 'CPT met default dashicon', 'explanation' => 'Geen specifieke dashicon gekozen; default pin gebruikt.', 'action' => 'Kies passende dashicon.' ],
        'duplicate_icon' => [ 'label' => 'CPT-dashicon dupliceert andere CPT', 'explanation' => 'Twee CPTs delen dezelfde dashicon; verwarrend in admin-menu.', 'action' => 'Kies unieke dashicon per CPT.' ],
        'empty_labels' => [ 'label' => 'CPT zonder labels', 'explanation' => 'Labels-array is leeg of incomplete.', 'action' => 'Vul labels (singular_name, menu_name, etc.).' ],
        'unexpected_supports' => [ 'label' => 'CPT met ongebruikelijke supports-array', 'explanation' => 'Supports bevat onverwachte features.', 'action' => 'Beoordeel en pas supports aan.' ],
        'missing_title_support' => [ 'label' => 'CPT zonder title-support', 'explanation' => 'CPT heeft geen "title" in supports; admin-edit broken.', 'action' => 'Voeg "title" toe.' ],
        'nav_menus_no_frontend' => [ 'label' => 'CPT zonder publicly_queryable', 'explanation' => 'CPT laat zich niet via menu naar frontend linken.', 'action' => 'Beoordeel; activeer publicly_queryable indien nodig.' ],
        'cptui_leftover' => [ 'label' => 'CPTUI-data nog aanwezig', 'explanation' => 'cptui_post_types option bestaat terwijl ACF leidend is.', 'action' => 'Verwijder CPTUI-data of migreer.' ],

        // ── Nav ──────────────────────────────────────────────────────────
        'unused_nav_menu' => [ 'label' => 'Ongebruikt navigatiemenu', 'explanation' => 'Menu bestaat maar is nergens aan een location toegewezen.', 'action' => 'Verwijder of wijs toe aan location.' ],
        'broken_menu_reference' => [ 'label' => 'Menu-item verwijst naar niet-bestaande post', 'explanation' => 'Menu-item linkt naar post die niet meer bestaat.', 'action' => 'Pas menu-item aan of verwijder.' ],
        'invalid_menu_name' => [ 'label' => 'Menu-naam volgt niet conventie', 'explanation' => 'Naam van het menu is niet "Header - X" of vergelijkbaar.', 'action' => 'Hernoem het menu.' ],
        'mismatched_menu_placement' => [ 'label' => 'Menu in afwijkende plaatsing', 'explanation' => 'Menu hangt op een ander theme-location dan z\'n naam suggereert.', 'action' => 'Verschuif menu naar correcte location.' ],
        'external_link_no_target_blank' => [ 'label' => 'Externe link zonder target=_blank', 'explanation' => 'Custom link in menu wijst naar externe URL maar opent in zelfde tab.', 'action' => 'Menu-item > zet "Open in new tab" aan.' ],
        'page_not_in_menu' => [ 'label' => 'Pagina niet in enig menu', 'explanation' => 'Een gepubliceerde page is in geen enkel menu opgenomen.', 'action' => 'Beoordeel of bewust (landingspagina) of toevoegen.' ],
        'custom_menu_label' => [ 'label' => 'Custom label in menu-item', 'explanation' => 'Menu-item heeft override-label dat afwijkt van post-titel.', 'action' => 'Bevestig of bewust; eventueel post-titel aanpassen.' ],

        // ── CSS ───────────────────────────────────────────────────────────
        'unused_css_class' => [ 'label' => 'Ongebruikte CSS-class', 'explanation' => 'Class in custom CSS wordt nergens in templates/posts gebruikt.', 'action' => 'Verwijder of beoordeel.' ],
        'wrong_css_prefix' => [ 'label' => 'CSS-class zonder ag_-prefix', 'explanation' => 'Custom class volgt niet ag_-conventie.', 'action' => 'Hernoem class met ag_-prefix.' ],
        'css_forbidden' => [ 'label' => 'CSS-attribuut in shortcode', 'explanation' => 'Shortcode heeft inline css= attribuut; CSS hoort centraal.', 'action' => 'Verplaats naar CSS-bestand.' ],
        'design_css_forbidden' => [ 'label' => 'Design-tab CSS overrides op grid-element', 'explanation' => 'Element heeft inline CSS via Design-tab (kleur/typo/spacing/border/positie/shadow/transform); buiten Impreza stijlsysteem. Aspect-ratio en animation-* zijn uitgesloten.', 'action' => 'Verwijder Design-tab overrides en gebruik centrale stijlen of theme-classes.' ],
        'empty_style_attr' => [ 'label' => 'Lege style-attribuut', 'explanation' => 'Shortcode heeft style="" leeg.', 'action' => 'Verwijder lege attribuut.' ],

        // ── WPBakery shortcode-conventies ─────────────────────────────────
        'missing_color_link' => [ 'label' => 'Shortcode zonder color_link', 'explanation' => 'Element mist verplichte color_link parameter voor link-state.', 'action' => 'Voeg color_link toe.' ],
        'missing_hide_empty' => [ 'label' => 'Veld zonder hide_empty', 'explanation' => 'us_post_custom_field zonder hide_empty="true"; toont placeholder bij leeg.', 'action' => 'Voeg hide_empty="true" toe.' ],
        'missing_hide_with_empty_link' => [ 'label' => 'Element zonder hide_with_empty_link', 'explanation' => 'Element verbergt zich niet bij leeg ACF link-veld.', 'action' => 'Voeg attribuut toe.' ],
        'missing_acf_link' => [ 'label' => 'Element zonder acf_link', 'explanation' => 'Verplichte acf_link parameter ontbreekt.', 'action' => 'Voeg acf_link toe.' ],
        'wrong_link_field_prefix' => [ 'label' => 'Link-veld zonder juiste prefix', 'explanation' => 'Link-veldnaam volgt niet de conventie.', 'action' => 'Hernoem link-veld.' ],
        'missing_el_class' => [ 'label' => 'Shortcode zonder el_class', 'explanation' => 'Geen el_class voor styling/scoping.', 'action' => 'Voeg el_class toe.' ],
        'missing_remove_rows' => [ 'label' => 'Shortcode zonder remove_rows', 'explanation' => 'Element mist remove_rows attribuut voor lege rij-handling.', 'action' => 'Voeg remove_rows toe.' ],
        'parent_row_with_siblings' => [ 'label' => 'Parent row met siblings', 'explanation' => 'Een vc_row met remove_rows heeft siblings; structuur klopt niet.', 'action' => 'Restructureer parent/child.' ],
        'empty_btn_style' => [ 'label' => 'Button zonder stijl', 'explanation' => 'us_btn zonder style-attribuut.', 'action' => 'Voeg style-keuze toe.' ],
        'empty_button_style' => [ 'label' => 'Form-button zonder stijl', 'explanation' => 'Submit-button zonder stijl.', 'action' => 'Definieer stijl in opt_forms.' ],
        'scroll_effect_forbidden' => [ 'label' => 'Scroll-effect attribuut gebruikt', 'explanation' => 'Element heeft scroll_effect; voor performance UIT.', 'action' => 'Verwijder scroll_effect attribuut.' ],
        'vc_video_wrong_attribute' => [ 'label' => 'vc_video met verkeerd attribuut', 'explanation' => 'Video gebruikt afgeschafte attribuut-naam.', 'action' => 'Pas naar huidige naming.' ],
        'missing_columns_reverse' => [ 'label' => 'Row mist columns_reverse', 'explanation' => 'Two-column row mist columns_reverse voor mobile-stacking.', 'action' => 'Voeg columns_reverse toe.' ],
        'unexpected_columns_reverse' => [ 'label' => 'Onverwachte columns_reverse', 'explanation' => 'columns_reverse op single-column row; betekenisloos.', 'action' => 'Verwijder attribuut.' ],
        'animate_detected' => [ 'label' => 'Animate-attribuut gedetecteerd', 'explanation' => 'Element heeft animate-eigenschap; meestal performance-impact.', 'action' => 'Beoordeel of nodig; eventueel verwijderen.' ],
        'responsive_hide_detected' => [ 'label' => 'Responsive-hide attribuut', 'explanation' => 'Element heeft responsive_hide_at_*; layout-keuze om expliciet te zijn.', 'action' => 'Bevestigen of bewust.' ],

        // ── Grid (us_header / us_grid_layout) ─────────────────────────────
        'image_lazy_loading_enabled' => [ 'label' => 'Header-image met lazy-loading', 'explanation' => 'Hero/header-image heeft lazy=true; eerste paint vertraagd.', 'action' => 'Zet lazy=false op header-image.' ],
        'image_missing_homepage_link' => [ 'label' => 'Header-image zonder homepage-link', 'explanation' => 'Logo-image klikt niet naar home.', 'action' => 'Voeg homepage-link toe.' ],
        'image_has_ratio' => [ 'label' => 'Header-image met ratio-attribuut', 'explanation' => 'Image-ratio is gezet; overschrijft natural ratio.', 'action' => 'Verwijder ratio.' ],
        'image_has_style' => [ 'label' => 'Header-image met style-attribuut', 'explanation' => 'Image heeft inline style.', 'action' => 'Verwijder.' ],
        'image_wrong_size' => [ 'label' => 'Header-image verkeerde grootte', 'explanation' => 'Image-size attribuut afwijkend van conventie.', 'action' => 'Pas size-attribuut aan.' ],

        // ── Header config ─────────────────────────────────────────────────
        'custom_breakpoint_invalid_order' => [ 'label' => 'Custom breakpoint volgorde ongeldig', 'explanation' => 'Header custom-breakpoint logischerwijs niet in volgorde.', 'action' => 'Header builder > breakpoints > pas volgorde aan.' ],
        'custom_breakpoint_exceeds_content_width' => [ 'label' => 'Custom breakpoint groter dan content-width', 'explanation' => 'Custom breakpoint > site-content-width.', 'action' => 'Verlaag breakpoint.' ],
        'custom_breakpoint_active' => [ 'label' => 'Custom breakpoint actief', 'explanation' => 'Header gebruikt custom breakpoint i.p.v. defaults.', 'action' => 'Bevestigen of bewust.' ],
        'menu_mobile_always' => [ 'label' => 'Mobile menu altijd zichtbaar', 'explanation' => 'menu-element toont mobile-versie op alle breakpoints.', 'action' => 'Bevestigen.' ],
        'menu_mobile_exceeds_content_width' => [ 'label' => 'Mobile menu boven content-width', 'explanation' => 'Mobile-menu actief boven site-content-width.', 'action' => 'Pas breakpoint aan.' ],
        'menu_mobile_exceeds_breakpoints' => [ 'label' => 'Mobile menu boven theme-breakpoints', 'explanation' => 'Mobile-menu trigger boven theme tablet-breakpoint.', 'action' => 'Harmoniseer.' ],
        'menu_mobile_behavior_not_label_and_arrow' => [ 'label' => 'Mobile menu-behavior afwijkend', 'explanation' => 'Behavior is niet "label and arrow".', 'action' => 'Header builder > Mobile menu > behavior > Label and arrow.' ],
        'menu_mobile_icon_size_too_large' => [ 'label' => 'Mobile-menu-icon te groot', 'explanation' => 'Icon-size te groot voor het ontwerp.', 'action' => 'Verklein icon-size.' ],
        'menu_mobile_icon_size_inconsistent' => [ 'label' => 'Mobile-menu-icon-sizes inconsistent', 'explanation' => 'Icon-sizes verschillen tussen breakpoints.', 'action' => 'Synchroniseer.' ],
        'menu_align_edges_mismatch' => [ 'label' => 'Menu-uitlijning rand-mismatch', 'explanation' => 'Menu-edges-align niet conform.', 'action' => 'Header builder > pas align aan.' ],
        'centering_missing' => [ 'label' => 'Element-centrering ontbreekt', 'explanation' => 'Verwacht centrering-instelling ontbreekt.', 'action' => 'Voeg toe in header builder.' ],
        'centering_unexpected' => [ 'label' => 'Element-centrering onverwacht', 'explanation' => 'Centrering staat aan op element waar het niet hoort.', 'action' => 'Verwijder.' ],
        'header_element_unused' => [ 'label' => 'Ongebruikt header-element', 'explanation' => 'Element bestaat maar wordt op geen breakpoint getoond.', 'action' => 'Verwijder.' ],
        'scroll_breakpoint_inconsistent' => [ 'label' => 'Scroll-breakpoint inconsistent', 'explanation' => 'Scroll-breakpoint waarden inconsistent over header.', 'action' => 'Synchroniseer.' ],

        // ── Forms (overig) ────────────────────────────────────────────────
        'wrong_email_field_type' => [ 'label' => 'Email-veld verkeerd type', 'explanation' => 'Veld heeft type "text" i.p.v. "email".', 'action' => 'Pas veld-type aan.' ],
        'empty_option_field' => [ 'label' => 'Lege opt_-option-field', 'explanation' => 'Een opt_forms-veld is leeg.', 'action' => 'Vul option page in.' ],
        'missing_move_label' => [ 'label' => 'Form-veld zonder move-label', 'explanation' => 'Float-label-pattern ontbreekt.', 'action' => 'Voeg move_label="true" toe.' ],

        // ── Misc ─────────────────────────────────────────────────────────
        'wrong_option_syntax' => [ 'label' => 'Verkeerde opt_-syntax in shortcode', 'explanation' => 'opt_-veld wordt niet correct gerefereerd.', 'action' => 'Pas opt_field-syntax aan.' ],
        'breakpoint_convention_deviation' => [ 'label' => 'Breakpoint wijkt van conventie af', 'explanation' => 'Niet-standaard breakpoint-waarde.', 'action' => 'Bevestigen of harmoniseren.' ],
    ];
    return $ctx;
}

function aspera_dashboard_widget_render(): void {
    $score    = get_option( 'aspera_audit_score',    null );
    $date_raw = get_option( 'aspera_audit_date',     null );
    $summary  = get_option( 'aspera_audit_summary' );
    $snapshot = get_option( 'aspera_audit_snapshot' );

    $data = is_string( $summary )  ? json_decode( $summary,  true ) : [];
    $cats = is_string( $snapshot ) ? json_decode( $snapshot, true ) : [];

    $cat_labels = [
        'wpb'              => 'WPBakery Templates',
        'grid'             => 'Grid Layouts & Headers',
        'colors'           => 'Kleuren',
        'acf_slugs'        => 'ACF Slugs',
        'acf_locations'    => 'ACF Locatieregels',
        'forms'            => 'Formulieren',
        'plugins'          => 'Plugins',
        'cpt'              => 'Custom Post Types',
        'db_tables'        => 'Database Tabellen',
        'css'              => 'Ongebruikte CSS',
        'nav'              => 'Navigatiemenu\'s',
        'wpb_modules'      => 'WPBakery Modules',
        'theme_breakpoints'=> 'Thema Breakpoints',
        'widgets'          => 'Widgets',
        'wpb_templates'    => 'Opgeslagen WPBakery Templates',
        'taxonomy'         => 'Taxonomieën',
        'header_config'    => 'Header Configuratie',
        'acf_fields'       => 'ACF Field Groups',
        'meta_orphaned'    => 'Orphaned ACF Meta',
        'theme_check'      => 'Thema Check',
        'wp_settings'      => 'WP Instellingen',
        'cache'            => 'Cache',
    ];

    $sev_colors = [
        'critical'    => '#d63638',
        'error'       => '#d63638',
        'warning'     => '#dba617',
        'observation' => '#5856d6',
    ];

    $nonce = wp_create_nonce( 'aspera_refresh_nonce' );

    // ── Uitzonderingen laden ───────────────────────────────────────────────────
    $exceptions_raw = get_option( 'aspera_audit_exceptions', [] );
    if ( ! is_array( $exceptions_raw ) ) $exceptions_raw = [];
    $exc_index = [];
    foreach ( $exceptions_raw as $e ) {
        $exc_index[ $e['id'] ] = true;
    }

    // ── Inline stijlen (native WP admin kleuren) ──────────────────────────────
    echo '<style>
        #aspera-audit-page details > summary { list-style: none; user-select: none; }
        #aspera-audit-page details > summary::-webkit-details-marker { display: none; }
        #aspera-audit-page details[open] .aspera-chevron { transform: rotate(90deg); }
        #aspera-audit-page .aspera-chevron { display:inline-block; transition: transform 0.15s; margin-right:4px; color:#72777c; }
        #aspera-audit-page .aspera-viol-row:last-child { border-bottom: none !important; }
        #aspera-audit-page .aspera-exc-btn { background:none; border:none; padding:0; cursor:pointer; font-size:11px; color:#a7aaad; text-decoration:underline; flex-shrink:0; margin-left:auto; }
        #aspera-audit-page .aspera-exc-btn:hover { color:#d63638; }
        #aspera-audit-page .aspera-exc-btn.is-excepted { color:#a7aaad; }
        #aspera-audit-page .aspera-exc-btn.is-excepted:hover { color:#2271b1; }
        #aspera-audit-page .aspera-exc-cb { flex-shrink:0; margin:3px 0 0; cursor:pointer; }
        #aspera-audit-page .aspera-bulk-bar { display:none; align-items:center; gap:8px; padding:6px 0; }
        #aspera-audit-page .aspera-bulk-bar.has-selection { display:flex; }
        #aspera-audit-page .aspera-bulk-btn { font-size:12px; cursor:pointer; background:#f0f0f0; border:1px solid #c3c4c7; border-radius:3px; padding:2px 10px; }
        #aspera-audit-page .aspera-bulk-btn:hover { background:#e0e0e0; }
        #aspera-audit-page .aspera-bulk-bar-ignored { display:none; align-items:center; gap:8px; padding:6px 0; }
        #aspera-audit-page .aspera-bulk-bar-ignored.has-selection { display:flex; }
        #aspera-audit-page .aspera-bulk-btn-ignored { font-size:12px; cursor:pointer; background:#f0f0f0; border:1px solid #c3c4c7; border-radius:3px; padding:2px 10px; }
        #aspera-audit-page .aspera-bulk-btn-ignored:hover { background:#e0e0e0; }
        #aspera-audit-page .aspera-ignored { opacity:0.45; }
        #aspera-audit-page .aspera-sev-filter-btn { transition: opacity 0.15s, transform 0.1s; }
        #aspera-audit-page .aspera-sev-filter-btn:not(:disabled):hover { opacity: 0.85; }
        #aspera-audit-page .aspera-sev-filter-btn.is-active { box-shadow: 0 0 0 2px #1d2327; }
        #aspera-audit-page.is-filtering .aspera-viol-row { display: none !important; }
        #aspera-audit-page.is-filtering-critical    .aspera-viol-row.aspera-sev-critical    { display: flex !important; }
        #aspera-audit-page.is-filtering-error       .aspera-viol-row.aspera-sev-error       { display: flex !important; }
        #aspera-audit-page.is-filtering-warning     .aspera-viol-row.aspera-sev-warning     { display: flex !important; }
        #aspera-audit-page.is-filtering-observation .aspera-viol-row.aspera-sev-observation { display: flex !important; }
        #aspera-audit-page.is-filtering .aspera-cat-details.aspera-cat-empty { display: none; }
        #aspera-audit-page.is-searching .aspera-viol-row.aspera-no-search-match { display: none !important; }
        #aspera-audit-page.is-searching .aspera-cat-details.aspera-cat-search-empty { display: none; }
        #aspera-audit-page #aspera-search-input { width:240px; padding:4px 8px; font-size:13px; border:1px solid #c3c4c7; border-radius:3px; }
        #aspera-audit-page #aspera-fixall-btn { font-size:12px; cursor:pointer; background:#00a32a; color:#fff; border:none; border-radius:3px; padding:4px 12px; font-weight:600; }
        #aspera-audit-page #aspera-fixall-btn:disabled { background:#dcdcde; color:#50575e; cursor:default; }
        #aspera-audit-page #aspera-fixall-btn:not(:disabled):hover { background:#00831f; }
        #aspera-audit-page #aspera-fixall-status { font-size:12px; color:#50575e; margin-left:6px; }
        #aspera-audit-page .aspera-row-tools { display:flex; gap:4px; align-items:center; flex-shrink:0; margin-left:auto; }
        #aspera-audit-page .aspera-row-tool { font-size:11px; cursor:pointer; background:#f0f0f0; color:#50575e; border:1px solid #c3c4c7; border-radius:3px; padding:2px 7px; line-height:1.4; }
        #aspera-audit-page a.aspera-row-tool { text-decoration:none; display:inline-block; }
        #aspera-audit-page .aspera-row-tool:hover { background:#e0e0e0; }
        #aspera-audit-page .aspera-row-tool.is-flash { background:#00a32a; color:#fff; border-color:#00a32a; }
        #aspera-audit-page .aspera-viol-row.is-resolved { background:#e6f6ea; transition:opacity 0.4s, max-height 0.4s, padding 0.4s; }
        #aspera-audit-page .aspera-viol-row.is-fading { opacity:0; max-height:0; padding-top:0 !important; padding-bottom:0 !important; overflow:hidden; }
        #aspera-audit-page #aspera-group-toggle { font-size:12px; cursor:pointer; background:#f6f7f7; border:1px solid #c3c4c7; border-radius:3px; padding:4px 10px; }
        #aspera-audit-page #aspera-group-toggle.is-active { background:#1d2327; color:#fff; border-color:#1d2327; }
        #aspera-audit-page.is-grouped .aspera-tab-content[data-tab-content="issues"] > div:not(#aspera-grouped-view) { display:none; }
        #aspera-audit-page #aspera-grouped-view { display:none; border:1px solid #dcdcde; border-radius:4px; overflow:hidden; margin-bottom:12px; }
        #aspera-audit-page.is-grouped #aspera-grouped-view { display:block; }
        #aspera-audit-page #aspera-grouped-view .aspera-group-post { border-bottom:1px solid #dcdcde; }
        #aspera-audit-page #aspera-grouped-view .aspera-group-post:last-child { border-bottom:none; }
        #aspera-audit-page #aspera-grouped-view summary { display:flex; align-items:center; justify-content:space-between; padding:7px 12px; cursor:pointer; background:#f6f7f7; }
        #aspera-audit-page #aspera-grouped-view .aspera-group-body { padding:8px 12px; background:#fff; }
        #aspera-audit-page .aspera-fix-btn { font-size:11px; cursor:pointer; background:#00a32a; color:#fff; border:none; border-radius:3px; padding:2px 8px; flex-shrink:0; margin-left:auto; font-weight:600; }
        #aspera-audit-page .aspera-fix-btn:hover { background:#00831f; }
        #aspera-audit-page .aspera-bulk-fix-btn { font-size:12px; cursor:pointer; background:#00a32a; color:#fff; border:none; border-radius:3px; padding:2px 10px; font-weight:600; }
        #aspera-audit-page .aspera-bulk-fix-btn:hover { background:#00831f; }
        #aspera-audit-toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; padding:8px 0 12px; border-bottom:1px solid #dcdcde; margin-bottom:12px; }
        #aspera-audit-sticky-bar { position:sticky; bottom:0; left:0; right:0; background:#f0f0f1; border-top:1px solid #c3c4c7; padding:10px 16px; margin:24px -20px 0; display:flex; align-items:center; justify-content:flex-end; gap:8px; box-shadow:0 -2px 6px rgba(0,0,0,0.04); z-index:50; }
        @media print { #aspera-audit-sticky-bar { display:none !important; } }
        #aspera-hero { display:flex; align-items:center; gap:24px; padding:18px 22px; border-radius:6px; margin-bottom:14px; flex-wrap:wrap; }
        #aspera-hero.is-green  { background:linear-gradient(135deg, #e6f6ea 0%, #f6fbf7 100%); border:1px solid #b7e0c2; }
        #aspera-hero.is-yellow { background:linear-gradient(135deg, #fcf2d9 0%, #fdfaf0 100%); border:1px solid #ecd58a; }
        #aspera-hero.is-red    { background:linear-gradient(135deg, #fbe5e6 0%, #fdf3f3 100%); border:1px solid #e8a5a7; }
        #aspera-hero .aspera-hero-score { display:flex; align-items:center; gap:4px; flex-shrink:0; }
        #aspera-hero .aspera-hero-num { font-size:3em; font-weight:800; line-height:1; }
        #aspera-hero .aspera-hero-100 { font-size:1em; color:#72777c; font-weight:400; align-self:center; }
        #aspera-hero .aspera-hero-label { display:inline-block; font-size:12px; font-weight:700; padding:4px 10px; border-radius:3px; cursor:default; }
        #aspera-hero .aspera-hero-divider { width:1px; align-self:stretch; background:rgba(0,0,0,0.08); }
        #aspera-hero .aspera-hero-meta { display:flex; flex-direction:column; gap:6px; flex:1; min-width:200px; }
        #aspera-hero .aspera-hero-total { font-size:13px; color:#50575e; }
        #aspera-hero .aspera-hero-total strong { color:#1d2327; font-size:15px; }
        #aspera-hero .aspera-sev-grid { display:flex; gap:6px; flex-wrap:wrap; }
        #aspera-audit-page .aspera-viol-panel { margin-top:6px; padding:6px 9px; border-radius:0 3px 3px 0; font-size:12px; line-height:1.4; color:#1d2327; }
        #aspera-audit-page .aspera-viol-panel-label { display:block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px; }
        #aspera-audit-page .aspera-viol-panel-problem  { background:#f6f7f7; border-left:3px solid #646970; }
        #aspera-audit-page .aspera-viol-panel-problem  .aspera-viol-panel-label { color:#646970; }
        #aspera-audit-page .aspera-viol-panel-location { background:#fcf9e8; border-left:3px solid #dba617; }
        #aspera-audit-page .aspera-viol-panel-location .aspera-viol-panel-label { color:#996800; }
        #aspera-audit-page .aspera-viol-panel-location code { background:rgba(255,255,255,0.6); padding:1px 4px; border-radius:2px; font-size:11px; color:#1d2327; }
        #aspera-audit-page .aspera-viol-panel-solution { background:#f0f6fc; border-left:3px solid #2271b1; }
        #aspera-audit-page .aspera-viol-panel-solution .aspera-viol-panel-label { color:#0073aa; }
        #aspera-audit-page .aspera-viol-fix-preview { display:block; margin-top:4px; padding-top:4px; border-top:1px dashed #c5d9ed; font-size:11px; color:#50575e; }
        #aspera-audit-page .aspera-viol-fix-preview code { font-size:11px; }
        @media print {
            #aspera-audit-page .aspera-viol-panel { background:transparent !important; }
        }
        #aspera-audit-page .aspera-tab-content[hidden] { display:none !important; }
        #aspera-audit-page .aspera-cat-passed[open] .aspera-chevron { transform: rotate(90deg); }
        #aspera-audit-page .aspera-passed-toggle-btn.is-active { box-shadow: 0 0 0 2px #1d2327; }
        #aspera-audit-page .aspera-passed-toggle-btn:not(:disabled):hover { opacity: 0.85; }
        #aspera-audit-page .aspera-toggle-all-bar { display:flex; justify-content:flex-end; margin:0 0 8px; }
        #aspera-audit-page .aspera-toggle-all-btn { background:none; border:1px solid #c3c4c7; border-radius:3px; padding:3px 9px; font-size:12px; color:#50575e; cursor:pointer; }
        #aspera-audit-page .aspera-toggle-all-btn:hover { background:#f0f0f1; color:#1d2327; }
    </style>';

    // ── Nog geen audit ─────────────────────────────────────────────────────────
    if ( $score === false || $date_raw === false ) {
        echo '<p style="color:#72777c;margin:0 0 10px;">Nog geen audit uitgevoerd.</p>';
        echo '<button class="button" id="aspera-refresh-btn" data-nonce="' . esc_attr( $nonce ) . '">Audit uitvoeren</button>';
        echo '<span id="aspera-refresh-status" style="display:inline-block;margin-left:8px;font-size:12px;color:#72777c;vertical-align:middle;"></span>';
        aspera_dashboard_widget_script();
        return;
    }

    $score_int = (int) $score;
    $traffic   = $data['traffic_light'] ?? ( $score_int >= 80 ? 'green' : ( $score_int >= 50 ? 'yellow' : 'red' ) );
    $counts    = $data['severity_counts'] ?? [];
    $total     = (int) ( $data['total_violations'] ?? 0 );

    $ts       = $date_raw ? strtotime( $date_raw ) : false;
    $date_fmt = $ts ? date_i18n( 'd-m-Y H:i', $ts ) : esc_html( $date_raw );

    $score_color_map = [ 'green' => '#00a32a', 'yellow' => '#dba617', 'red' => '#d63638' ];
    $score_color     = $score_color_map[ $traffic ] ?? '#72777c';
    $score_label_map = [ 'green' => 'Schoon', 'yellow' => 'Aandacht nodig', 'red' => 'Kritieke problemen' ];
    $score_label     = $score_label_map[ $traffic ] ?? '';

    // ── Tel exceptions die matchen met huidige violations ─────────────────────
    $ignored_total = 0;
    foreach ( $cats as $cat_data ) {
        foreach ( $cat_data['violations'] ?? [] as $_v ) {
            $eid = $_v['exception_id'] ?? '';
            if ( $eid && isset( $exc_index[ $eid ] ) ) {
                $ignored_total++;
            }
        }
    }
    $stale_count = count( $exceptions_raw ) - $ignored_total;

    // ── Bereken passed rules per categorie ────────────────────────────────────
    $rules_registry = aspera_get_rules_per_category();
    $passed_per_cat = [];
    $passed_total   = 0;
    foreach ( $cats as $cat_key => $cat_data ) {
        $registry_rules = $rules_registry[ $cat_key ] ?? [];
        if ( empty( $registry_rules ) ) continue;
        $violation_rules = [];
        foreach ( $cat_data['violations'] ?? [] as $_v ) {
            $violation_rules[ $_v['rule'] ?? '' ] = true;
        }
        // Categorie niet-toepasselijk skip-conditie
        $cache_inactive = ( $cat_key === 'cache' && empty( $cat_data['violations'] ) && empty( $cat_data['error'] ) );
        if ( $cache_inactive ) continue;
        $passed = [];
        foreach ( $registry_rules as $r ) {
            if ( ! isset( $violation_rules[ $r ] ) ) {
                $passed[] = $r;
            }
        }
        if ( ! empty( $passed ) ) {
            $passed_per_cat[ $cat_key ] = $passed;
            $passed_total += count( $passed );
        }
    }

    // Tel hoeveel violations bulk-fixable zijn (alleen niet-destructieve actions)
    $bulk_fixable_count = 0;
    $bulk_safe_actions  = [ 'add_attribute', 'remove_attribute', 'replace_value' ];
    foreach ( $cats as $_c ) {
        foreach ( $_c['violations'] ?? [] as $_v ) {
            $eid = $_v['exception_id'] ?? '';
            if ( $eid && isset( $exc_index[ $eid ] ) ) continue;
            $f = $_v['proposed_fix'] ?? null;
            if ( is_array( $f ) && ( $f['fixable'] ?? false ) && in_array( $f['action'] ?? '', $bulk_safe_actions, true ) ) {
                $bulk_fixable_count++;
            }
        }
    }

    // ── Top toolbar: datum + Search + Fix-all + Vernieuwen ─────────────────────
    echo '<div id="aspera-audit-toolbar">';
    echo '<small style="color:#72777c;">Laatste audit: ' . $date_fmt . '</small>';
    echo '<span style="display:flex;align-items:center;gap:8px;">';
    echo '<input type="search" id="aspera-search-input" placeholder="Zoek in meldingen..." />';
    echo '<button type="button" id="aspera-group-toggle" title="Groepeer alle meldingen per pagina i.p.v. per categorie">Groepeer per pagina</button>';
    if ( $bulk_fixable_count > 0 ) {
        echo '<button id="aspera-fixall-btn" data-nonce="' . esc_attr( $nonce ) . '" data-count="' . (int) $bulk_fixable_count . '" title="Voert alleen veilige shortcode-fixes uit (geen verwijderingen).">&#x2713;&nbsp;Fix alle ' . (int) $bulk_fixable_count . ' fixable</button>';
        echo '<span id="aspera-fixall-status"></span>';
    }
    echo '<button class="button button-secondary" id="aspera-refresh-btn" data-nonce="' . esc_attr( $nonce ) . '">&#x21BA;&ensp;Vernieuwen</button>';
    echo '</span>';
    echo '</div>';
    echo '<span id="aspera-refresh-status" style="display:block;margin-bottom:10px;font-size:12px;color:#72777c;min-height:16px;"></span>';

    // ── Hero: score + traffic-light + severity badges ─────────────────────────
    echo '<div id="aspera-hero" class="is-' . esc_attr( $traffic ) . '">';
    echo '<div class="aspera-hero-score">';
    echo '<span class="aspera-hero-num" style="color:' . esc_attr( $score_color ) . ';">' . $score_int . '</span>';
    echo '<span class="aspera-hero-100">/100</span>';
    echo '</div>';
    echo '<span class="aspera-hero-label" style="color:' . esc_attr( $score_color ) . ';background:' . esc_attr( $score_color ) . '22;">' . esc_html( $score_label ) . '</span>';
    echo '<div class="aspera-hero-divider"></div>';
    echo '<div class="aspera-hero-meta">';
    // Delta vs vorige snapshot
    $delta = aspera_get_audit_delta( is_array( $data ) ? $data : [] );
    $delta_html = '';
    if ( $delta && ( $delta['total_diff'] !== 0 || $delta['score_diff'] !== 0 ) ) {
        $diff_total = $delta['total_diff'];
        $diff_score = $delta['score_diff'];
        $prev_ts    = $delta['prev_date'] ? strtotime( $delta['prev_date'] ) : false;
        $prev_fmt   = $prev_ts ? date_i18n( 'd-m-Y H:i', $prev_ts ) : '?';
        $parts = [];
        if ( $diff_total !== 0 ) {
            $sign = $diff_total > 0 ? '+' : '';
            $col  = $diff_total > 0 ? '#d63638' : '#00a32a';
            $parts[] = '<span style="color:' . $col . ';font-weight:700;">' . $sign . $diff_total . ' meldingen</span>';
        }
        if ( $diff_score !== 0 ) {
            $sign = $diff_score > 0 ? '+' : '';
            $col  = $diff_score > 0 ? '#00a32a' : '#d63638';
            $parts[] = '<span style="color:' . $col . ';font-weight:700;">score ' . $sign . $diff_score . '</span>';
        }
        $delta_html = ' &middot; <span style="font-size:12px;color:#50575e;" title="vorige run: ' . esc_attr( $prev_fmt ) . '">sinds vorige run: ' . implode( ', ', $parts ) . '</span>';
    }
    echo '<div class="aspera-hero-total"><strong>' . (int) $total . '</strong> meldingen totaal' . ( $ignored_total > 0 ? ' &middot; ' . $ignored_total . ' genegeerd' : '' ) . ( $stale_count > 0 ? ' &middot; <button id="aspera-cleanup-btn" data-nonce="' . esc_attr( $nonce ) . '" style="font-size:11px;cursor:pointer;background:none;border:1px solid #c3c4c7;border-radius:3px;padding:1px 7px;color:#72777c;vertical-align:baseline;" title="' . esc_attr( $stale_count . ' opgeslagen uitzonderingen matchen geen huidige melding' ) . '">&#x1F9F9;&nbsp;' . $stale_count . '&nbsp;stale</button>' : '' ) . $delta_html . '</div>';
    echo '<div id="aspera-sev-bar" class="aspera-sev-grid">';
    foreach ( [ 'critical' => 'Kritiek', 'error' => 'Fout', 'warning' => 'Waarschuwing', 'observation' => 'Opmerking' ] as $sev => $blabel ) {
        $cnt = (int) ( $counts[ $sev ] ?? 0 );
        $bg  = $cnt > 0 ? ( $sev_colors[ $sev ] ) : '#dcdcde';
        $fc  = $cnt > 0 ? '#fff' : '#50575e';
        $cursor = $cnt > 0 ? 'pointer' : 'default';
        echo '<button type="button" class="aspera-sev-filter-btn" data-sev="' . esc_attr( $sev ) . '" ' . ( $cnt === 0 ? 'disabled' : '' ) . ' style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fc ) . ';border:none;border-radius:3px;padding:4px 10px;font-size:12px;font-weight:700;cursor:' . $cursor . ';">' . $cnt . '&nbsp;' . esc_html( $blabel ) . '</button>';
    }
    $passed_disabled = $passed_total === 0;
    $passed_bg = $passed_disabled ? '#dcdcde' : '#00a32a';
    $passed_fc = $passed_disabled ? '#50575e' : '#fff';
    $passed_cursor = $passed_disabled ? 'default' : 'pointer';
    echo '<button type="button" class="aspera-passed-toggle-btn" ' . ( $passed_disabled ? 'disabled' : '' ) . ' style="background:' . $passed_bg . ';color:' . $passed_fc . ';border:none;border-radius:3px;padding:4px 10px;font-size:12px;font-weight:700;cursor:' . $passed_cursor . ';">' . (int) $passed_total . '&nbsp;Geslaagd</button>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    // ── Per-categorie accordion (Issues tab) ──────────────────────────────────
    echo '<div class="aspera-tab-content" data-tab-content="issues">';
    echo '<div id="aspera-grouped-view"></div>';
    echo '<div class="aspera-toggle-all-bar"><button type="button" class="aspera-toggle-all-btn" data-state="mixed">&#x2195;&nbsp;Alles uit-/inklappen</button></div>';
    echo '<div style="border:1px solid #dcdcde;border-radius:4px;overflow:hidden;margin-bottom:12px;">';

    foreach ( $cat_labels as $key => $label ) {
        if ( ! isset( $cats[ $key ] ) ) continue;

        $cat     = $cats[ $key ];
        $v_count = (int) ( $cat['violation_count'] ?? 0 );
        $viols   = $cat['violations'] ?? [];
        $cat_err = $cat['error'] ?? null;
        $is_open = ''; // wordt hieronder opnieuw bepaald na splits

        // Splits violations in actief en genegeerd op basis van exception_id
        $active_viols  = [];
        $ignored_viols = [];
        foreach ( $viols as $_v ) {
            $eid   = $_v['exception_id'] ?? '';
            $_v['_exc_id'] = $eid;
            if ( isset( $exc_index[ $eid ] ) ) {
                $ignored_viols[] = $_v;
            } else {
                $active_viols[] = $_v;
            }
        }
        $active_count  = count( $active_viols );
        $ignored_count = count( $ignored_viols );

        // Badge kleur op basis van ergste severity in actieve violations
        $sev_prio = [ 'critical' => 0, 'error' => 1, 'warning' => 2, 'observation' => 3 ];
        if ( $active_count === 0 ) {
            $badge_bg = '#00a32a';
            $worst    = null;
        } else {
            $worst = 'observation';
            foreach ( $active_viols as $_v ) {
                $_s = $_v['severity'] ?? 'observation';
                if ( ( $sev_prio[ $_s ] ?? 3 ) < ( $sev_prio[ $worst ] ?? 3 ) ) {
                    $worst = $_s;
                }
            }
            $badge_bg = $sev_colors[ $worst ] ?? '#d63638';
        }

        // Categorie met uitsluitend observations — niet highlighten
        $obs_only = ( $active_count > 0 && $worst === 'observation' );

        $is_open  = ( $active_count > 0 && ! $obs_only || $cat_err ) ? ' open' : '';
        $row_bg   = ( $active_count > 0 && ! $obs_only ) ? '#fff8f8' : '#f6f7f7';
        $font_w   = ( $active_count > 0 && ! $obs_only ) ? '600' : '400';

        echo '<details' . $is_open . ' class="aspera-cat-details" data-cat="' . esc_attr( $key ) . '" style="border-bottom:1px solid #dcdcde;">';
        echo '<summary style="display:flex;align-items:center;justify-content:space-between;padding:7px 12px;cursor:pointer;background:' . esc_attr( $row_bg ) . ';">';
        echo '<span style="font-size:13px;font-weight:' . $font_w . ';color:#1d2327;">';
        echo '<span class="aspera-chevron">▶</span>' . esc_html( $label );
        echo '</span>';
        echo '<span style="display:flex;align-items:center;gap:5px;">';
        echo '<span style="background:' . esc_attr( $badge_bg ) . ';color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;min-width:20px;text-align:center;">' . $active_count . '</span>';
        if ( $ignored_count > 0 ) {
            echo '<span style="background:#dcdcde;color:#50575e;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;" title="Genegeerde violations">' . $ignored_count . ' &#x1F6AB;</span>';
        }
        // Delta-badge per categorie
        if ( ! empty( $delta['category_diffs'][ $key ] ) ) {
            $cd   = (int) $delta['category_diffs'][ $key ];
            $sign = $cd > 0 ? '+' : '';
            $bg   = $cd > 0 ? '#fde7e7' : '#e6f6ea';
            $col  = $cd > 0 ? '#d63638' : '#00a32a';
            echo '<span style="background:' . $bg . ';color:' . $col . ';border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700;" title="Verschil sinds vorige run">' . $sign . $cd . '</span>';
        }
        echo '</span>';
        echo '</summary>';

        echo '<div style="padding:8px 12px;background:#fff;font-size:13px;">';

        if ( $cat_err ) {
            echo '<p style="color:#d63638;margin:4px 0;">⚠ Endpoint fout: ' . esc_html( $cat_err ) . '</p>';
        } elseif ( empty( $active_viols ) && empty( $ignored_viols ) ) {
            echo '<p style="color:#00a32a;margin:4px 0;">✓ Geen meldingen gevonden.</p>';
        } else {
            $sev_order = [ 'critical' => 0, 'error' => 1, 'warning' => 2, 'observation' => 3 ];

            // ── Actieve violations ────────────────────────────────────────
            if ( empty( $active_viols ) && $ignored_count > 0 ) {
                echo '<p style="color:#00a32a;margin:4px 0 6px;">✓ Geen actieve issues.</p>';
            } else {
                usort( $active_viols, function ( $a, $b ) use ( $sev_order ) {
                    return ( $sev_order[ $a['severity'] ?? 'warning' ] ?? 2 ) <=> ( $sev_order[ $b['severity'] ?? 'warning' ] ?? 2 );
                } );
                echo '<div class="aspera-bulk-bar" data-cat="' . esc_attr( $key ) . '">';
                echo '<button class="aspera-bulk-btn" data-nonce="' . esc_attr( $nonce ) . '">Negeer geselecteerde</button>';
                echo '<button class="aspera-bulk-fix-btn" data-nonce="' . esc_attr( $nonce ) . '" style="display:none;">Fix geselecteerde</button>';
                echo '<span class="aspera-bulk-count" style="font-size:12px;color:#50575e;"></span>';
                echo '</div>';
                $rule_context = aspera_get_rule_context();
                foreach ( $active_viols as $v ) {
                    $sev     = $v['severity'] ?? 'warning';
                    $rule_raw= (string) ( $v['rule'] ?? '' );
                    $rule    = esc_html( $rule_raw );
                    $detail  = esc_html( $v['detail'] ?? '' );
                    $post_id = isset( $v['post_id'] ) ? (int) $v['post_id'] : null;
                    $dot_col = $sev_colors[ $sev ] ?? '#72777c';
                    $eid     = esc_attr( $v['_exc_id'] ?? '' );
                    $cat_key_attr = esc_attr( $key );
                    $rule_attr    = esc_attr( $rule_raw );
                    $pid_attr     = esc_attr( (string) ( $post_id ?? 0 ) );

                    $rctx        = $rule_context[ $rule_raw ] ?? null;
                    $rule_label  = $rctx['label']       ?? aspera_humanize_rule( $rule_raw );
                    $rule_expl   = $rctx['explanation'] ?? '';
                    $rule_action = $rctx['action']      ?? '';

                    $fix = $v['proposed_fix'] ?? null;
                    $has_fix = is_array( $fix ) && ( $fix['fixable'] ?? false );
                    $fix_json = $has_fix ? esc_attr( wp_json_encode( $fix ) ) : '';

                    // Zoekstring voor clipboard: 'before' van fix, anders detail, anders rule
                    $search_str = '';
                    if ( $has_fix && ! empty( $fix['before'] ) ) $search_str = (string) $fix['before'];
                    elseif ( $detail )                            $search_str = wp_strip_all_tags( (string) $detail );
                    else                                          $search_str = $rule_raw;
                    $search_str = mb_substr( trim( $search_str ), 0, 80 );

                    echo '<div class="aspera-viol-row aspera-sev-' . esc_attr( $sev ) . '" data-sev="' . esc_attr( $sev ) . '" data-rule="' . $rule_attr . '" data-post-id="' . $pid_attr . '" data-category="' . $cat_key_attr . '" data-search="' . esc_attr( $search_str ) . '" style="display:flex;align-items:flex-start;gap:8px;padding:7px 0;border-bottom:1px solid #f0f0f0;">';
                    echo '<input type="checkbox" class="aspera-exc-cb" data-exc-id="' . $eid . '" data-category="' . $cat_key_attr . '" data-rule="' . $rule_attr . '" data-post-id="' . $pid_attr . '"' . ( $has_fix ? ' data-fix="' . $fix_json . '"' : '' ) . '>';
                    echo '<span style="color:' . esc_attr( $dot_col ) . ';font-weight:700;flex-shrink:0;font-size:14px;margin-top:1px;">&#x25CF;</span>';
                    echo '<div style="flex:1;min-width:0;">';
                    echo '<div style="font-weight:600;color:#1d2327;font-size:13px;margin-bottom:1px;">' . esc_html( $rule_label ) . '</div>';
                    echo '<code style="background:#f6f7f7;padding:1px 5px;border-radius:2px;font-size:11px;color:#50575e;">' . $rule . '</code>';
                    echo '&ensp;<span style="font-size:10px;color:' . esc_attr( $dot_col ) . ';font-weight:700;text-transform:uppercase;letter-spacing:0.4px;">' . esc_html( $sev ) . '</span>';
                    if ( $post_id ) {
                        $edit_url   = get_edit_post_link( $post_id );
                        $post_title = get_the_title( $post_id );
                        $link_label = $post_title ?: ( 'Post #' . $post_id );
                        echo ' &mdash; <a href="' . esc_url( (string) $edit_url ) . '" style="font-size:12px;" target="_blank">' . esc_html( $link_label ) . '</a>';
                    }
                    $loc = $v['location'] ?? null;
                    if ( is_array( $loc ) && ! empty( $loc['breadcrumb'] ) ) {
                        echo ' &mdash; <span style="font-size:11px;color:#72777c;">' . esc_html( $loc['breadcrumb'] ) . '</span>';
                    }
                    // ── Paneel 1: Probleem ───────────────────────────────
                    if ( $rule_expl ) {
                        echo '<div class="aspera-viol-panel aspera-viol-panel-problem">';
                        echo '<span class="aspera-viol-panel-label">Probleem</span>';
                        echo esc_html( $rule_expl );
                        echo '</div>';
                    }
                    // ── Paneel 2: Locatie ────────────────────────────────
                    if ( $detail ) {
                        echo '<div class="aspera-viol-panel aspera-viol-panel-location">';
                        echo '<span class="aspera-viol-panel-label">Locatie</span>';
                        echo $detail; // detail is reeds esc_html op regel 2293
                        echo '</div>';
                    }
                    // ── Paneel 3: Oplossing (actie + fix-preview) ────────
                    if ( $rule_action || $has_fix ) {
                        echo '<div class="aspera-viol-panel aspera-viol-panel-solution">';
                        echo '<span class="aspera-viol-panel-label">Oplossing</span>';
                        if ( $rule_action ) {
                            echo esc_html( $rule_action );
                        }
                        if ( $has_fix ) {
                            $fa = $fix['action'] ?? '';
                            echo '<span class="aspera-viol-fix-preview"><strong>Fix-preview:</strong> ';
                            if ( $fa === 'delete_field_group' ) {
                                echo 'field group naar prullenbak';
                            } elseif ( $fa === 'delete_orphaned_meta' ) {
                                echo 'meta key <code>' . esc_html( $fix['meta_key'] ?? '' ) . '</code> verwijderen (' . (int) ( $fix['rows'] ?? 0 ) . ' rijen)';
                            } elseif ( $fa === 'delete_wpforms_scheduled_actions' ) {
                                echo (int) ( $fix['count'] ?? 0 ) . ' WPForms scheduled action(s) verwijderen';
                            } else {
                                $before_short = esc_html( mb_strimwidth( $fix['before'] ?? '', 0, 80, '...' ) );
                                $after_short  = esc_html( mb_strimwidth( $fix['after'] ?? '', 0, 80, '...' ) );
                                echo '<code style="text-decoration:line-through;color:#d63638;">' . $before_short . '</code> &rarr; <code style="color:#00a32a;">' . $after_short . '</code>';
                            }
                            echo '</span>';
                        }
                        echo '</div>';
                    }
                    echo '</div>';
                    // Tools cluster: locatie + clipboard + recheck + fix
                    echo '<div class="aspera-row-tools">';
                    $eye = aspera_get_violation_admin_link( $key, $rule_raw, $post_id, (string) ( $v['detail'] ?? '' ) );
                    if ( $eye ) {
                        echo '<a href="' . esc_url( $eye['url'] ) . '" target="_blank" class="aspera-row-tool aspera-eye-btn" title="' . esc_attr( $eye['title'] ) . '">&#x1F441;</a>';
                    }
                    if ( $search_str !== '' ) {
                        echo '<button type="button" class="aspera-row-tool aspera-clip-btn" title="Kopieer zoekstring naar klembord">&#x1F4CB;</button>';
                    }
                    echo '<button type="button" class="aspera-row-tool aspera-recheck-btn" data-nonce="' . esc_attr( $nonce ) . '" title="Hervalideer alleen deze regel">&#x21BB;</button>';
                    if ( $has_fix ) {
                        echo '<button class="aspera-fix-btn" data-fix="' . $fix_json . '" data-post-id="' . $pid_attr . '" data-nonce="' . esc_attr( $nonce ) . '">Fix</button>';
                    }
                    echo '</div>';
                    echo '</div>';
                }
            }

            // ── Genegeerde violations ─────────────────────────────────────
            if ( ! empty( $ignored_viols ) ) {
                echo '<div style="margin-top:4px;border-top:1px dashed #dcdcde;padding-top:4px;">';
                echo '<div class="aspera-bulk-bar-ignored" data-cat="' . esc_attr( $key ) . '">';
                echo '<button class="aspera-bulk-btn-ignored" data-nonce="' . esc_attr( $nonce ) . '">Herstel geselecteerde</button>';
                echo '<span class="aspera-bulk-count-ignored" style="font-size:12px;color:#50575e;"></span>';
                echo '</div>';
                foreach ( $ignored_viols as $v ) {
                    $sev     = $v['severity'] ?? 'warning';
                    $rule    = esc_html( $v['rule'] ?? '' );
                    $detail  = esc_html( $v['detail'] ?? '' );
                    $post_id = isset( $v['post_id'] ) ? (int) $v['post_id'] : null;
                    $eid     = esc_attr( $v['_exc_id'] ?? '' );

                    echo '<div class="aspera-viol-row aspera-ignored aspera-sev-' . esc_attr( $sev ) . '" data-sev="' . esc_attr( $sev ) . '" style="display:flex;align-items:flex-start;gap:8px;padding:5px 0;border-bottom:1px solid #f0f0f0;">';
                    echo '<input type="checkbox" class="aspera-unexc-cb" data-exc-id="' . $eid . '">';
                    echo '<span style="color:#a7aaad;font-weight:700;flex-shrink:0;font-size:14px;margin-top:1px;">●</span>';
                    echo '<div style="flex:1;min-width:0;">';
                    echo '<code style="background:#f6f7f7;padding:1px 5px;border-radius:2px;font-size:12px;text-decoration:line-through;color:#a7aaad;">' . $rule . '</code>';
                    echo '&ensp;<span style="font-size:11px;color:#a7aaad;font-weight:700;text-transform:uppercase;">' . esc_html( $sev ) . '</span>';
                    if ( $post_id ) {
                        $post_title = get_the_title( $post_id );
                        $link_label = $post_title ?: ( 'Post #' . $post_id );
                        echo ' &mdash; <span style="font-size:12px;color:#a7aaad;">' . esc_html( $link_label ) . '</span>';
                    }
                    if ( $detail ) {
                        echo '<br><span style="color:#a7aaad;font-size:12px;word-break:break-word;">' . $detail . '</span>';
                    }
                    echo '</div>';
                    echo '<button class="aspera-exc-btn is-excepted" data-action="remove" data-nonce="' . esc_attr( $nonce ) . '" data-exc-id="' . $eid . '" title="Herstel deze violation">Herstel</button>';
                    echo '</div>';
                }
                echo '</div>';
            }
        }

        echo '</div>';
        echo '</details>';
    }

    echo '</div>'; // end accordion border-wrap
    echo '</div>'; // end tab-content issues

    // ── Per-categorie accordion (Passed tab) ──────────────────────────────────
    echo '<div class="aspera-tab-content" data-tab-content="passed" hidden>';
    if ( empty( $passed_per_cat ) ) {
        echo '<p style="color:#72777c;margin:8px 0;">Geen passed checks om te tonen.</p>';
    } else {
        $rule_context = aspera_get_rule_context();
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 10px;flex-wrap:wrap;">';
        echo '<p style="color:#50575e;margin:0;font-size:12px;flex:1;min-width:200px;">Deze checks zijn uitgevoerd en goedgekeurd. Categorieen die niet van toepassing zijn (bv. cache-plugin inactief) worden weggelaten.</p>';
        echo '<button type="button" class="aspera-toggle-all-btn" data-state="mixed">&#x2195;&nbsp;Alles uit-/inklappen</button>';
        echo '</div>';
        echo '<div style="border:1px solid #dcdcde;border-radius:4px;overflow:hidden;">';
        foreach ( $cat_labels as $key => $label ) {
            if ( empty( $passed_per_cat[ $key ] ) ) continue;
            $passed_rules = $passed_per_cat[ $key ];
            $count = count( $passed_rules );
            echo '<details open class="aspera-cat-passed" style="border-bottom:1px solid #dcdcde;">';
            echo '<summary style="display:flex;align-items:center;justify-content:space-between;padding:7px 12px;cursor:pointer;background:#f6fbf7;">';
            echo '<span style="font-size:13px;font-weight:600;color:#1d2327;"><span class="aspera-chevron">&#x25B6;</span>' . esc_html( $label ) . '</span>';
            echo '<span style="background:#00a32a;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;min-width:20px;text-align:center;">' . $count . '</span>';
            echo '</summary>';
            echo '<div style="padding:8px 12px;background:#fff;font-size:13px;">';
            foreach ( $passed_rules as $r ) {
                $rctx       = $rule_context[ $r ] ?? null;
                $rule_label = $rctx['label'] ?? aspera_humanize_rule( $r );
                $rule_expl  = $rctx['explanation'] ?? '';
                echo '<div style="display:flex;align-items:flex-start;gap:8px;padding:5px 0;border-bottom:1px solid #f0f0f0;">';
                echo '<span style="color:#00a32a;font-weight:700;flex-shrink:0;font-size:14px;margin-top:1px;">&#x2713;</span>';
                echo '<div style="flex:1;min-width:0;">';
                echo '<div style="font-weight:600;color:#1d2327;font-size:13px;">' . esc_html( $rule_label ) . '</div>';
                echo '<code style="background:#f6f7f7;padding:1px 5px;border-radius:2px;font-size:11px;color:#50575e;">' . esc_html( $r ) . '</code>';
                if ( $rule_expl ) {
                    echo '<div style="margin-top:3px;font-size:11px;color:#72777c;line-height:1.4;">' . esc_html( $rule_expl ) . '</div>';
                }
                echo '</div></div>';
            }
            echo '</div>';
            echo '</details>';
        }
        echo '</div>';
    }
    echo '</div>'; // end tab-content passed

    // ── Sticky bottom bar: tweede Vernieuwen-trigger ──────────────────────────
    echo '<div id="aspera-audit-sticky-bar">';
    echo '<button class="button button-primary aspera-refresh-btn-sticky" data-nonce="' . esc_attr( $nonce ) . '">&#x21BA;&ensp;Vernieuwen</button>';
    echo '</div>';

    aspera_dashboard_widget_script();
}

function aspera_dashboard_widget_script(): void {
    ?>
    <script>
    (function () {
        var refreshBtn = document.getElementById('aspera-refresh-btn');
        var status     = document.getElementById('aspera-refresh-status');
        document.querySelectorAll('.aspera-refresh-btn-sticky').forEach(function (b) {
            b.addEventListener('click', function () {
                if (refreshBtn) refreshBtn.click();
            });
        });

        // ── Severity-filter ──────────────────────────────────────────────
        var auditPage = document.getElementById('aspera-audit-page');
        if (auditPage) {
            var sevButtons = auditPage.querySelectorAll('.aspera-sev-filter-btn');
            var activeSev  = null;
            function applyFilter(sev) {
                ['critical','error','warning','observation'].forEach(function (s) {
                    auditPage.classList.remove('is-filtering-' + s);
                });
                sevButtons.forEach(function (b) { b.classList.remove('is-active'); });
                if (!sev) {
                    auditPage.classList.remove('is-filtering');
                    auditPage.querySelectorAll('.aspera-cat-details').forEach(function (d) {
                        d.classList.remove('aspera-cat-empty');
                    });
                    return;
                }
                auditPage.classList.add('is-filtering');
                auditPage.classList.add('is-filtering-' + sev);
                var btn = auditPage.querySelector('.aspera-sev-filter-btn[data-sev="' + sev + '"]');
                if (btn) btn.classList.add('is-active');
                auditPage.querySelectorAll('.aspera-cat-details').forEach(function (d) {
                    var has = d.querySelector('.aspera-viol-row.aspera-sev-' + sev);
                    if (has) {
                        d.classList.remove('aspera-cat-empty');
                        d.setAttribute('open', '');
                    } else {
                        d.classList.add('aspera-cat-empty');
                    }
                });
            }
            sevButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (btn.disabled) return;
                    var sev = btn.dataset.sev;
                    activeSev = (activeSev === sev) ? null : sev;
                    applyFilter(activeSev);
                });
            });

            // ── Passed-toggle (mode switch) ──────────────────────────────
            var passedBtn   = auditPage.querySelector('.aspera-passed-toggle-btn');
            var tabContents = auditPage.querySelectorAll('.aspera-tab-content');
            function showTab(name) {
                tabContents.forEach(function (c) {
                    if (c.dataset.tabContent === name) {
                        c.removeAttribute('hidden');
                    } else {
                        c.setAttribute('hidden', '');
                    }
                });
            }
            var passedActive = false;
            if (passedBtn) {
                passedBtn.addEventListener('click', function () {
                    if (passedBtn.disabled) return;
                    passedActive = !passedActive;
                    if (passedActive) {
                        passedBtn.classList.add('is-active');
                        // Severity-filter uitzetten als die actief was
                        if (activeSev) { activeSev = null; applyFilter(null); }
                        showTab('passed');
                    } else {
                        passedBtn.classList.remove('is-active');
                        showTab('issues');
                    }
                });
            }
            // Wanneer een severity-filter wordt aangezet en passed is actief, switch naar issues
            sevButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (passedActive && passedBtn) {
                        passedActive = false;
                        passedBtn.classList.remove('is-active');
                        showTab('issues');
                    }
                });
            });

            // ── Toggle alle accordions in zichtbare tab ──────────────────
            auditPage.querySelectorAll('.aspera-toggle-all-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var visibleTab = Array.prototype.find.call(tabContents, function (c) { return !c.hasAttribute('hidden'); });
                    if (!visibleTab) return;
                    var details = visibleTab.querySelectorAll('details');
                    var allOpen = Array.prototype.every.call(details, function (d) { return d.hasAttribute('open'); });
                    details.forEach(function (d) {
                        if (allOpen) { d.removeAttribute('open'); }
                        else         { d.setAttribute('open', ''); }
                    });
                });
            });
        }

        function runAudit(statusMsg) {
            if (!refreshBtn) return;
            refreshBtn.disabled = true;
            if (status) {
                status.style.color   = '#72777c';
                status.textContent   = statusMsg || 'Audit wordt uitgevoerd\u2026 (dit kan 5\u201320 seconden duren)';
            }
            fetch(ajaxurl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    'action=aspera_refresh_audit&nonce=' + encodeURIComponent(refreshBtn.dataset.nonce)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    if (status) {
                        status.style.color  = '#00a32a';
                        status.textContent  = 'Klaar \u2014 pagina herlaadt\u2026';
                    }
                    setTimeout(function () { location.reload(); }, 800);
                } else {
                    if (status) {
                        status.style.color = '#d63638';
                        status.textContent = 'Fout: ' + (data.data || 'onbekend');
                    }
                    refreshBtn.disabled = false;
                }
            })
            .catch(function () {
                if (status) {
                    status.style.color = '#d63638';
                    status.textContent = 'Netwerkfout \u2014 probeer opnieuw.';
                }
                refreshBtn.disabled = false;
            });
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () { runAudit(); });
        }

        // ── Checkboxes: bulk-bar tonen/verbergen ─────────────────────────────
        function updateBulkBars() {
            document.querySelectorAll('.aspera-bulk-bar').forEach(function (bar) {
                var container = bar.parentElement;
                var checked = container.querySelectorAll('.aspera-exc-cb:checked');
                var count = checked.length;
                bar.classList.toggle('has-selection', count > 0);
                var lbl = bar.querySelector('.aspera-bulk-count');
                if (lbl) lbl.textContent = count > 0 ? count + ' geselecteerd' : '';
                var fixBtn = bar.querySelector('.aspera-bulk-fix-btn');
                if (fixBtn) {
                    var fixable = 0;
                    checked.forEach(function (cb) { if (cb.dataset.fix) fixable++; });
                    fixBtn.style.display = fixable > 0 ? '' : 'none';
                }
            });
            document.querySelectorAll('.aspera-bulk-bar-ignored').forEach(function (bar) {
                var container = bar.parentElement;
                var checked = container.querySelectorAll('.aspera-unexc-cb:checked');
                var count = checked.length;
                bar.classList.toggle('has-selection', count > 0);
                var lbl = bar.querySelector('.aspera-bulk-count-ignored');
                if (lbl) lbl.textContent = count > 0 ? count + ' geselecteerd' : '';
            });
        }
        document.querySelectorAll('.aspera-exc-cb').forEach(function (cb) {
            cb.addEventListener('change', updateBulkBars);
        });
        document.querySelectorAll('.aspera-unexc-cb').forEach(function (cb) {
            cb.addEventListener('change', updateBulkBars);
        });

        // ── Bulk negeer ──────────────────────────────────────────────────────
        document.querySelectorAll('.aspera-bulk-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var container = btn.closest('.aspera-bulk-bar').parentElement;
                var checked   = container.querySelectorAll('.aspera-exc-cb:checked');
                if (!checked.length) return;

                var items = [];
                checked.forEach(function (cb) {
                    items.push({
                        id:       cb.dataset.excId,
                        category: cb.dataset.category,
                        rule:     cb.dataset.rule,
                        post_id:  cb.dataset.postId || '0'
                    });
                });

                btn.disabled    = true;
                btn.textContent = '\u2026';

                fetch(ajaxurl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    'action=aspera_add_exception&nonce=' + encodeURIComponent(btn.dataset.nonce)
                            + '&items=' + encodeURIComponent(JSON.stringify(items))
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        runAudit('Uitzonderingen opgeslagen \u2014 audit wordt bijgewerkt\u2026');
                    } else {
                        btn.disabled    = false;
                        btn.textContent = 'Negeer geselecteerde';
                    }
                })
                .catch(function () {
                    btn.disabled    = false;
                    btn.textContent = 'Negeer geselecteerde';
                });
            });
        });

        // ── Bulk fix ─────────────────────────────────────────────────────────
        document.querySelectorAll('.aspera-bulk-fix-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var container = btn.closest('.aspera-bulk-bar').parentElement;
                var checked   = container.querySelectorAll('.aspera-exc-cb:checked');
                var fixes = [];
                checked.forEach(function (cb) {
                    if (!cb.dataset.fix) return;
                    fixes.push({ fix: JSON.parse(cb.dataset.fix), postId: cb.dataset.postId });
                });
                if (!fixes.length) return;

                var delFG = 0, delMeta = 0, scFix = 0;
                fixes.forEach(function (f) {
                    if (f.fix.action === 'delete_field_group') delFG++;
                    else if (f.fix.action === 'delete_orphaned_meta') delMeta++;
                    else scFix++;
                });
                var parts = [];
                if (scFix > 0) parts.push(scFix + ' shortcode-fix' + (scFix > 1 ? 'es' : ''));
                if (delMeta > 0) parts.push(delMeta + ' meta-verwijdering' + (delMeta > 1 ? 'en' : ''));
                if (delFG > 0) parts.push(delFG + ' field group-verwijdering' + (delFG > 1 ? 'en' : ''));
                if (!confirm(fixes.length + ' fixes toepassen?\n\n' + parts.join(' + '))) return;

                btn.disabled    = true;
                btn.textContent = '0/' + fixes.length + '...';
                var done = 0, failed = 0;

                function runNext() {
                    if (done + failed >= fixes.length) {
                        if (failed > 0) {
                            btn.textContent = failed + ' mislukt';
                            btn.style.background = '#d63638';
                        }
                        runAudit('Bulk fix voltooid (' + done + '/' + fixes.length + ') — audit wordt vernieuwd…');
                        return;
                    }
                    var f = fixes[done + failed];
                    var body = 'action=aspera_apply_fix&nonce=' + encodeURIComponent(btn.dataset.nonce)
                        + '&fix_action=' + encodeURIComponent(f.fix.action)
                        + '&post_id=' + encodeURIComponent(f.postId);
                    if (f.fix.meta_key) body += '&meta_key=' + encodeURIComponent(f.fix.meta_key);
                    if (f.fix.before) body += '&before=' + encodeURIComponent(f.fix.before);
                    if (f.fix.after !== undefined) body += '&after=' + encodeURIComponent(f.fix.after);

                    fetch(ajaxurl, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body:    body
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) { done++; } else { failed++; }
                        btn.textContent = (done + failed) + '/' + fixes.length + '...';
                        runNext();
                    })
                    .catch(function () { failed++; btn.textContent = (done + failed) + '/' + fixes.length + '...'; runNext(); });
                }
                runNext();
            });
        });

        // ── Bulk herstel ─────────────────────────────────────────────────────
        document.querySelectorAll('.aspera-bulk-btn-ignored').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var container = btn.closest('.aspera-bulk-bar-ignored').parentElement;
                var checked   = container.querySelectorAll('.aspera-unexc-cb:checked');
                if (!checked.length) return;

                var ids = [];
                checked.forEach(function (cb) { ids.push(cb.dataset.excId); });

                btn.disabled    = true;
                btn.textContent = '…';

                fetch(ajaxurl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    'action=aspera_remove_exception&nonce=' + encodeURIComponent(btn.dataset.nonce)
                            + '&items=' + encodeURIComponent(JSON.stringify(ids))
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        runAudit('Uitzonderingen hersteld — audit wordt bijgewerkt…');
                    } else {
                        btn.disabled    = false;
                        btn.textContent = 'Herstel geselecteerde';
                    }
                })
                .catch(function () {
                    btn.disabled    = false;
                    btn.textContent = 'Herstel geselecteerde';
                });
            });
        });

        // ── Herstel knoppen (individueel) ─────────────────────────────────────
        document.querySelectorAll('.aspera-exc-btn.is-excepted').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var nonce = btn.dataset.nonce;
                var excId = btn.dataset.excId;
                btn.disabled    = true;
                btn.textContent = '\u2026';
                fetch(ajaxurl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    'action=aspera_remove_exception&nonce=' + encodeURIComponent(nonce)
                            + '&exception_id=' + encodeURIComponent(excId)
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        runAudit('Uitzondering hersteld \u2014 audit wordt bijgewerkt\u2026');
                    } else {
                        btn.disabled    = false;
                        btn.textContent = 'Herstel';
                    }
                })
                .catch(function () {
                    btn.disabled    = false;
                    btn.textContent = 'Herstel';
                });
            });
        });
        // ── Stale exceptions opruimen ─────────────────────────────────────────
        var cleanupBtn = document.getElementById('aspera-cleanup-btn');
        if (cleanupBtn) {
            cleanupBtn.addEventListener('click', function () {
                cleanupBtn.disabled    = true;
                cleanupBtn.textContent = '…';
                fetch(ajaxurl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    'action=aspera_cleanup_exceptions&nonce=' + encodeURIComponent(cleanupBtn.dataset.nonce)
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        location.reload();
                    } else {
                        cleanupBtn.disabled    = false;
                        cleanupBtn.textContent = '🧹 stale';
                    }
                })
                .catch(function () {
                    cleanupBtn.disabled    = false;
                    cleanupBtn.textContent = '🧹 stale';
                });
            });
        }

        // ── Fix-knoppen ──────────────────────────────────────────────────────
        document.querySelectorAll('.aspera-fix-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var fix    = JSON.parse(btn.dataset.fix);
                var postId = btn.dataset.postId;
                var msg    = 'Fix toepassen?\n\n';
                if (fix.action === 'delete_field_group') {
                    msg += 'Field group "' + (fix.title || '#' + postId) + '" wordt verplaatst naar de prullenbak.';
                } else if (fix.action === 'delete_orphaned_meta') {
                    msg += 'Meta key "' + fix.meta_key + '" verwijderen (' + fix.rows + ' rijen + referenties).';
                } else if (fix.action === 'delete_wpforms_scheduled_actions') {
                    msg += fix.count + ' WPForms scheduled action(s) en bijbehorende logs verwijderen uit actionscheduler tabellen.';
                } else {
                    msg += 'Actie: ' + fix.action + '\nAttribuut: ' + fix.attribute;
                    if (fix.value) msg += '\nWaarde: ' + fix.value;
                }
                if (!confirm(msg)) return;

                var body = 'action=aspera_apply_fix&nonce=' + encodeURIComponent(btn.dataset.nonce)
                    + '&fix_action=' + encodeURIComponent(fix.action)
                    + '&post_id=' + encodeURIComponent(postId);
                if (fix.meta_key) body += '&meta_key=' + encodeURIComponent(fix.meta_key);
                if (fix.before) body += '&before=' + encodeURIComponent(fix.before);
                if (fix.after !== undefined) body += '&after=' + encodeURIComponent(fix.after);

                btn.disabled    = true;
                btn.textContent = '...';

                fetch(ajaxurl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    body
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        btn.textContent   = '✓';
                        btn.style.background = '#00a32a';
                        runAudit('Fix toegepast — audit wordt vernieuwd…');
                    } else {
                        alert('Fix mislukt: ' + (data.data || 'onbekend'));
                        btn.disabled    = false;
                        btn.textContent = 'Fix';
                        btn.style.background = '';
                    }
                })
                .catch(function () {
                    btn.disabled    = false;
                    btn.textContent = 'Fix';
                    btn.style.background = '';
                });
            });
        });

        // ── Live search filter ────────────────────────────────────────────
        var searchInput = document.getElementById('aspera-search-input');
        if (searchInput) {
            var page = document.getElementById('aspera-audit-page');
            function applySearch() {
                var q = (searchInput.value || '').trim().toLowerCase();
                if (!q) {
                    if (page) page.classList.remove('is-searching');
                    document.querySelectorAll('.aspera-viol-row.aspera-no-search-match').forEach(function (r) { r.classList.remove('aspera-no-search-match'); });
                    document.querySelectorAll('.aspera-cat-details.aspera-cat-search-empty').forEach(function (d) { d.classList.remove('aspera-cat-search-empty'); });
                    return;
                }
                if (page) page.classList.add('is-searching');
                document.querySelectorAll('.aspera-cat-details').forEach(function (det) {
                    var anyMatch = false;
                    det.querySelectorAll('.aspera-viol-row').forEach(function (row) {
                        var txt = (row.textContent || '').toLowerCase();
                        if (txt.indexOf(q) === -1) {
                            row.classList.add('aspera-no-search-match');
                        } else {
                            row.classList.remove('aspera-no-search-match');
                            anyMatch = true;
                        }
                    });
                    if (anyMatch) {
                        det.classList.remove('aspera-cat-search-empty');
                        if (!det.open) det.open = true;
                    } else {
                        det.classList.add('aspera-cat-search-empty');
                    }
                });
            }
            var searchTimer;
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(applySearch, 120);
            });
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { searchInput.value = ''; applySearch(); }
            });
        }

        // ── Fix-all fixable queue ─────────────────────────────────────────
        var fixAllBtn = document.getElementById('aspera-fixall-btn');
        if (fixAllBtn) {
            fixAllBtn.addEventListener('click', function () {
                var safeActions = ['add_attribute', 'remove_attribute', 'replace_value'];
                var fixes = [];
                document.querySelectorAll('.aspera-fix-btn').forEach(function (btn) {
                    if (btn.disabled) return;
                    try {
                        var fix = JSON.parse(btn.dataset.fix);
                        if (fix && safeActions.indexOf(fix.action) !== -1) {
                            fixes.push({ btn: btn, fix: fix, postId: btn.dataset.postId, nonce: btn.dataset.nonce });
                        }
                    } catch (e) {}
                });
                if (!fixes.length) { alert('Geen fixable shortcode-meldingen gevonden.'); return; }
                if (!confirm('Pas ' + fixes.length + ' shortcode-fixes toe? Verwijderingen (orphaned meta, scheduled actions) zijn uitgesloten en moeten individueel worden bevestigd.')) return;

                fixAllBtn.disabled = true;
                var status   = document.getElementById('aspera-fixall-status');
                var done     = 0;
                var failures = [];
                var i        = 0;

                function next() {
                    if (i >= fixes.length) {
                        var msg = 'Klaar: ' + done + '/' + fixes.length + ' toegepast';
                        if (failures.length) msg += ', ' + failures.length + ' mislukt';
                        if (status) status.textContent = msg;
                        runAudit(msg + ' — audit wordt vernieuwd…');
                        return;
                    }
                    var item = fixes[i++];
                    if (status) status.textContent = i + '/' + fixes.length + '…';
                    var body = 'action=aspera_apply_fix&nonce=' + encodeURIComponent(item.nonce)
                        + '&fix_action=' + encodeURIComponent(item.fix.action)
                        + '&post_id=' + encodeURIComponent(item.postId);
                    if (item.fix.before) body += '&before=' + encodeURIComponent(item.fix.before);
                    if (item.fix.after !== undefined) body += '&after=' + encodeURIComponent(item.fix.after);
                    fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data && data.success) { done++; item.btn.disabled = true; item.btn.textContent = '✓'; item.btn.style.background = '#00a32a'; }
                            else { failures.push(item); }
                            next();
                        })
                        .catch(function () { failures.push(item); next(); });
                }
                next();
            });
        }

        // ── Clipboard search-bridge ────────────────────────────────────────
        document.querySelectorAll('.aspera-clip-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('.aspera-viol-row');
                if (!row) return;
                var str = row.dataset.search || '';
                if (!str) return;
                var done = function () {
                    btn.classList.add('is-flash');
                    var orig = btn.innerHTML;
                    btn.innerHTML = '✓';
                    setTimeout(function () { btn.classList.remove('is-flash'); btn.innerHTML = orig; }, 900);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(str).then(done).catch(function () { window.prompt('Kopieer handmatig:', str); });
                } else {
                    window.prompt('Kopieer handmatig:', str);
                }
            });
        });

        // ── Surgical re-validation per row ────────────────────────────────
        document.querySelectorAll('.aspera-recheck-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('.aspera-viol-row');
                if (!row) return;
                var rule = row.dataset.rule || '';
                var pid  = row.dataset.postId || '';
                var cat  = row.dataset.category || '';
                btn.disabled = true;
                var orig = btn.innerHTML;
                btn.innerHTML = '…';
                var body = 'action=aspera_recheck_violation&nonce=' + encodeURIComponent(btn.dataset.nonce)
                    + '&rule=' + encodeURIComponent(rule)
                    + '&post_id=' + encodeURIComponent(pid)
                    + '&category=' + encodeURIComponent(cat);
                fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            if (data.data && data.data.still_present === false) {
                                row.classList.add('is-resolved');
                                btn.innerHTML = '✓';
                                setTimeout(function () {
                                    row.classList.add('is-fading');
                                    setTimeout(function () { row.remove(); }, 400);
                                }, 600);
                            } else {
                                btn.innerHTML = orig;
                                btn.disabled = false;
                                btn.title = 'Nog steeds aanwezig';
                                btn.style.borderColor = '#d63638';
                                setTimeout(function () { btn.style.borderColor = ''; }, 1200);
                            }
                        } else {
                            btn.innerHTML = orig;
                            btn.disabled = false;
                            var msg = (data && data.data && data.data.message) ? data.data.message : 'Recheck mislukt — gebruik Vernieuwen.';
                            btn.title = msg;
                        }
                    })
                    .catch(function () { btn.innerHTML = orig; btn.disabled = false; });
            });
        });

        // ── Groepeer per pagina ────────────────────────────────────────────
        var groupBtn = document.getElementById('aspera-group-toggle');
        var groupedView = document.getElementById('aspera-grouped-view');
        if (groupBtn && groupedView) {
            groupBtn.addEventListener('click', function () {
                var pageEl = document.getElementById('aspera-audit-page');
                var active = pageEl.classList.toggle('is-grouped');
                groupBtn.classList.toggle('is-active', active);
                if (!active) { groupedView.innerHTML = ''; return; }
                var byPost = {};
                document.querySelectorAll('.aspera-tab-content[data-tab-content="issues"] .aspera-viol-row').forEach(function (row) {
                    if (row.classList.contains('aspera-ignored')) return;
                    var pid = row.dataset.postId || '0';
                    if (!byPost[pid]) byPost[pid] = [];
                    byPost[pid].push(row);
                });
                var html = '';
                var pids = Object.keys(byPost).sort(function (a, b) { return byPost[b].length - byPost[a].length; });
                pids.forEach(function (pid) {
                    var rows = byPost[pid];
                    var titleLink = '';
                    if (pid && pid !== '0') {
                        var first = rows[0];
                        var a = first.querySelector('a[href*="post.php"]');
                        titleLink = a ? a.outerHTML : 'Post #' + pid;
                    } else {
                        titleLink = '<em>Site-breed (geen post)</em>';
                    }
                    html += '<details open class="aspera-group-post">';
                    html += '<summary><span style="font-weight:600;">' + titleLink + '</span><span style="background:#d63638;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;">' + rows.length + '</span></summary>';
                    html += '<div class="aspera-group-body" data-pid="' + pid + '"></div>';
                    html += '</details>';
                });
                groupedView.innerHTML = html;
                pids.forEach(function (pid) {
                    var body = groupedView.querySelector('.aspera-group-body[data-pid="' + pid + '"]');
                    if (!body) return;
                    byPost[pid].forEach(function (row) { body.appendChild(row.cloneNode(true)); });
                });
                // Re-bind handlers in cloned rows
                groupedView.querySelectorAll('.aspera-clip-btn').forEach(function (btn) {
                    if (btn.dataset.bound) return; btn.dataset.bound = '1';
                    btn.addEventListener('click', function () {
                        var row = btn.closest('.aspera-viol-row');
                        var str = row && row.dataset.search ? row.dataset.search : '';
                        if (!str) return;
                        var orig = btn.innerHTML;
                        var done = function () { btn.classList.add('is-flash'); btn.innerHTML = '✓'; setTimeout(function () { btn.classList.remove('is-flash'); btn.innerHTML = orig; }, 900); };
                        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(str).then(done).catch(function () { window.prompt('Kopieer handmatig:', str); });
                        else window.prompt('Kopieer handmatig:', str);
                    });
                });
            });
        }

    })();
    </script>
    <?php
}

// ── Dashboard Widget — einde ──────────────────────────────────────────────────

add_action( 'rest_api_init', function () {

    /**
     * GET /wp-json/aspera/v1/wpb/{id}
     * Parseert WPBakery post_content en geeft alleen elementen terug
     * met condities of ACF-veldverwijzingen. Ondersteunt uitbreiding
     * via de filter 'aspera_field_patterns'.
     */
    register_rest_route( 'aspera/v1', '/wpb/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            $id      = (int) $req['id'];
            $content = get_post_field( 'post_content', $id, 'raw' );

            if ( ! $content ) {
                return new WP_Error( 'not_found', 'Post niet gevonden of leeg.', [ 'status' => 404 ] );
            }

            // Standaard patronen voor ACF-veldverwijzingen; uitbreidbaar per site
            $field_patterns = apply_filters( 'aspera_field_patterns', [
                '/\{\{([\w_]+)\}\}/',   // {{veldnaam}} — UpSolution/US
                '/\bkey="([\w_]+)"/',   // key="veldnaam" — us_post_custom_field
            ] );

            // Structurele containers: altijd tonen met relevante attributen
            $structural = [
                'vc_row'          => [ 'el_id', 'el_class', 'scroll_effect', 'css', 'us_bg_image_source', 'us_bg_image', 'us_bg_show', 'us_bg_video', 'us_bg_video_disable_width' ],
                'vc_column'       => [ 'width', 'el_class', 'css' ],
                'vc_row_inner'    => [ 'el_class' ],
                'vc_column_inner' => [ 'width', 'el_class' ],
            ];

            // Helper: haal een specifiek attribuut op
            $get_attr = function ( string $name, string $attrs ): ?string {
                return preg_match( '/\b' . preg_quote( $name, '/' ) . '="([^"]*)"/', $attrs, $v ) ? $v[1] : null;
            };

            // Alle shortcode-tags + attributen ophalen
            preg_match_all( '/\[(\w+)((?:"[^"]*"|\'[^\']*\'|[^\]])*)\]/', $content, $matches, PREG_SET_ORDER );

            $elements = [];

            foreach ( $matches as $m ) {
                $tag   = $m[1];
                $attrs = $m[2];

                // Condities decoderen (WPBakery standaard: URL-encoded JSON)
                $conditions = [];
                if ( preg_match( '/conditions="([^"]+)"/', $attrs, $cond ) ) {
                    $decoded = json_decode( urldecode( $cond[1] ), true );
                    if ( is_array( $decoded ) ) {
                        $conditions = array_map( function ( $c ) {
                            return array_filter( [
                                'field'    => $c['cf_name_predefined'] ?? $c['cf_name'] ?? null,
                                'mode'     => $c['cf_mode'] ?? null,
                                'value'    => $c['cf_value'] ?? null,
                                'param'    => $c['param'] ?? null,
                            ] );
                        }, $decoded );
                    }
                }

                // ACF-veldverwijzingen ophalen via configureerbare patronen
                $field_refs = [];
                foreach ( $field_patterns as $pattern ) {
                    if ( preg_match_all( $pattern, $attrs, $f ) ) {
                        $field_refs = array_merge( $field_refs, array_filter( $f[1] ) );
                    }
                }
                $field_refs = array_values( array_unique( $field_refs ) );

                // us_btn: link= URL-encoded JSON met custom_field verwijzing
                if ( $tag === 'us_btn' && preg_match( '/\blink="([^"]+)"/', $attrs, $link_match ) ) {
                    $link_data = json_decode( urldecode( $link_match[1] ), true );
                    if ( isset( $link_data['type'], $link_data['custom_field'] )
                         && $link_data['type'] === 'custom_field'
                         && ! empty( $link_data['custom_field'] ) ) {
                        $field_refs[] = $link_data['custom_field'];
                        $field_refs   = array_values( array_unique( $field_refs ) );
                    }
                }

                // Structurele containers: altijd opnemen, alleen niet-lege attributen tonen
                if ( isset( $structural[ $tag ] ) ) {
                    $struct_attrs = [];
                    foreach ( $structural[ $tag ] as $attr_name ) {
                        $val = $get_attr( $attr_name, $attrs );
                        if ( $val !== null && $val !== '' ) {
                            $struct_attrs[ $attr_name ] = $val;
                        }
                    }
                    $entry = [ 'tag' => $tag ];
                    if ( $conditions )   $entry['conditions'] = $conditions;
                    if ( $field_refs )   $entry['fields']     = $field_refs;
                    if ( $struct_attrs ) $entry['attrs']      = $struct_attrs;
                    $elements[] = $entry;

                // Overige elementen: alleen opnemen bij condities of veldverwijzingen
                } elseif ( $conditions || $field_refs ) {
                    $entry = [
                        'tag'        => $tag,
                        'conditions' => $conditions,
                        'fields'     => $field_refs,
                    ];
                    $el_class = $get_attr( 'el_class', $attrs );
                    if ( $el_class !== null && $el_class !== '' ) {
                        $entry['attrs'] = [ 'el_class' => $el_class ];
                    }
                    $elements[] = $entry;
                }
            }

            return $elements;
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/acf/group/{id}
     * Geeft ACF field group terug als schone JSON:
     * naam, key, type, choices en conditional_logic per veld.
     */
    register_rest_route( 'aspera/v1', '/acf/group/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            if ( ! function_exists( 'acf_get_fields' ) ) {
                return new WP_Error( 'acf_missing', 'ACF is niet actief.', [ 'status' => 500 ] );
            }

            $fields = acf_get_fields( (int) $req['id'] );

            if ( ! $fields ) {
                return new WP_Error( 'not_found', 'Field group niet gevonden of leeg.', [ 'status' => 404 ] );
            }

            return array_map( function ( $f ) {
                return array_filter( [
                    'name'              => $f['name'] ?? null,
                    'key'               => $f['key'] ?? null,
                    'type'              => $f['type'] ?? null,
                    'choices'           => ! empty( $f['choices'] ) ? $f['choices'] : null,
                    'conditional_logic' => ! empty( $f['conditional_logic'] ) ? $f['conditional_logic'] : false,
                ] );
            }, $fields );
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/acf/validate/{id}
     * Valideert een ACF field group op veelvoorkomende structuurfouten:
     * - Gebroken conditional logic references (verwijzing naar niet-bestaande field key)
     * - Gemengde choice key types (int én string in dezelfde choices-array)
     * - Ontbrekende veldnamen (exclusief tab-velden)
     * - WYSIWYG veld met media upload buttons ingeschakeld (wysiwyg_media_upload_enabled)
     */
    register_rest_route( 'aspera/v1', '/acf/validate/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            if ( ! function_exists( 'acf_get_fields' ) ) {
                return new WP_Error( 'acf_missing', 'ACF is niet actief.', [ 'status' => 500 ] );
            }

            $result = aspera_validate_acf_group( (int) $req['id'] );

            if ( empty( $result['fields'] ) ) {
                return new WP_Error( 'not_found', 'Field group niet gevonden of leeg.', [ 'status' => 404 ] );
            }

            if ( empty( $result['issues'] ) ) {
                return [ 'status' => 'ok', 'field_count' => count( $result['fields'] ) ];
            }

            return [
                'status'      => 'issues_found',
                'field_count' => count( $result['fields'] ),
                'issues'      => $result['issues'],
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/acf/validate/all
     * Aggregeert /acf/validate/{id} over alle actieve ACF field groups.
     * Geeft violations terug per group met post_id voor directe navigatie naar de editor.
     */
    register_rest_route( 'aspera/v1', '/acf/validate/all', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            if ( ! function_exists( 'acf_get_fields' ) ) {
                return new WP_Error( 'acf_missing', 'ACF is niet actief.', [ 'status' => 500 ] );
            }

            $groups = get_posts( [
                'post_type'      => 'acf-field-group',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            ] );

            $violations  = [];
            $group_count = count( $groups );
            $sev_map     = [
                'missing_name'                 => 'error',
                'broken_conditional_reference' => 'error',
                'mixed_choice_key_types'       => 'warning',
                'wysiwyg_media_upload_enabled' => 'warning',
                'wrong_group_name_prefix'      => 'warning',
            ];

            foreach ( $groups as $group ) {
                // Field group naming convention check
                $group_content   = maybe_unserialize( $group->post_content );
                $expected_prefix = null;
                if ( is_array( $group_content ) && ! empty( $group_content['location'] ) ) {
                    foreach ( $group_content['location'] as $rule_group ) {
                        foreach ( $rule_group as $rule ) {
                            if ( $rule['param'] === 'options_page' ) {
                                $expected_prefix = 'OPT - ';
                                break 2;
                            }
                            if ( $rule['param'] === 'post_type' && $rule['value'] === 'page' ) {
                                $expected_prefix = 'Page - ';
                                break 2;
                            }
                            if ( $rule['param'] === 'post_type' && $rule['value'] !== 'page' && $rule['value'] !== 'post' ) {
                                $expected_prefix = 'CPT - ';
                                break 2;
                            }
                            if ( $rule['param'] === 'taxonomy' ) {
                                $expected_prefix = 'TAX - ';
                                break 2;
                            }
                        }
                    }
                }
                if ( $expected_prefix !== null && strpos( $group->post_title, $expected_prefix ) !== 0 ) {
                    $violations[] = [
                        'rule'     => 'wrong_group_name_prefix',
                        'severity' => 'warning',
                        'post_id'  => $group->ID,
                        'detail'   => '"' . $group->post_title . '" — verwacht prefix "' . $expected_prefix . '"',
                    ];
                }

                $result = aspera_validate_acf_group( $group->ID );
                if ( empty( $result['fields'] ) ) continue;

                foreach ( $result['issues'] as $issue ) {
                    $t     = $issue['type'];
                    $name  = $issue['field_name'] ?? $issue['field'] ?? $issue['field_slug'] ?? '';
                    $key   = $issue['key'] ?? $issue['field_key'] ?? '';
                    $label = $issue['label'] ?? $issue['field_label'] ?? $name;

                    switch ( $t ) {
                        case 'missing_name':
                            $detail = $group->post_title . ': veld zonder naam (key: ' . $key . ', label: ' . $label . ')';
                            break;
                        case 'broken_conditional_reference':
                            $detail = $group->post_title . ': "' . $name . '" verwijst naar niet-bestaande field key ' . $issue['missing_ref'];
                            break;
                        case 'mixed_choice_key_types':
                            $detail = $group->post_title . ': "' . $name . '" heeft gemengde choice key types';
                            break;
                        case 'wysiwyg_media_upload_enabled':
                            $detail = $group->post_title . ': WYSIWYG "' . ( $label ?: $name ) . '" heeft media upload ingeschakeld';
                            break;
                        default:
                            $detail = $group->post_title . ': ' . $t;
                    }

                    $violations[] = [
                        'rule'     => $t,
                        'severity' => $sev_map[ $t ] ?? 'warning',
                        'post_id'  => $group->ID,
                        'detail'   => $detail,
                    ];
                }
            }

            return [
                'status'      => empty( $violations ) ? 'ok' : 'issues_found',
                'violations'  => $violations,
                'group_count' => $group_count,
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/acf/post/{id}
     * Geeft alle ACF-veldwaarden van een post terug.
     * Compact alternatief voor wp_get_post_snapshot.
     */
    register_rest_route( 'aspera/v1', '/acf/post/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            if ( ! function_exists( 'get_fields' ) ) {
                return new WP_Error( 'acf_missing', 'ACF is niet actief.', [ 'status' => 500 ] );
            }

            $fields = get_fields( (int) $req['id'] );

            if ( $fields === false || $fields === null ) {
                return new WP_Error( 'not_found', 'Post niet gevonden of geen ACF-velden.', [ 'status' => 404 ] );
            }

            return $fields ?: new WP_REST_Response( [], 200 );
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/header/{id}
     * Geeft us_header JSON terug: elementen per breakpoint
     * (default / laptops / tablets / mobiles), lege waarden gestript.
     */
    register_rest_route( 'aspera/v1', '/header/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            $id   = (int) $req['id'];
            $post = get_post( $id );

            if ( ! $post || $post->post_type !== 'us_header' ) {
                return new WP_Error( 'not_found', 'us_header post niet gevonden.', [ 'status' => 404 ] );
            }

            $data = json_decode( $post->post_content, true );

            if ( ! is_array( $data ) ) {
                return new WP_Error( 'parse_error', 'Kon post_content niet als JSON parsen.', [ 'status' => 500 ] );
            }

            $breakpoints = [ 'default', 'laptops', 'tablets', 'mobiles' ];
            $result      = [];

            foreach ( $breakpoints as $bp ) {
                if ( ! isset( $data[ $bp ] ) ) continue;

                $bp_data  = $data[ $bp ];
                $layout   = $bp_data['layout'] ?? null;
                $options  = isset( $bp_data['options'] ) ? aspera_strip_empty( $bp_data['options'] ) : [];
                $elements = [];

                if ( isset( $bp_data['elements'] ) && is_array( $bp_data['elements'] ) ) {
                    foreach ( $bp_data['elements'] as $el_id => $el ) {
                        $elements[ $el_id ] = aspera_strip_empty( $el );
                    }
                }

                $result[ $bp ] = array_filter( [
                    'layout'   => $layout,
                    'options'  => $options ?: null,
                    'elements' => $elements ?: null,
                ] );
            }

            return $result;
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/header/migrate/{id}
     * Normaliseert een us_header door deprecated breakpoint-waarden te uniformeren
     * en hoogte-anomalieën te rapporteren. Schrijft het resultaat terug naar de post.
     *
     * Auto-fix (kopieer default → alle breakpoints):
     *   - Kleuren (alle *_bg_color, *_text_color, *_hover_color, *_transparent_*)
     *   - Fullwidth (top_fullwidth, middle_fullwidth, bottom_fullwidth)
     *   - Shadow
     *   - scroll_breakpoint
     *   - Lege layout posities verwijderen
     *
     * Observaties (rapporteren, niet auto-fixen):
     *   - Centering-verschillen tussen breakpoints
     *   - Hoogte-anomalieën per zone (top/middle/bottom, regular + sticky)
     *   - Element hoogte-anomalieën (height_default > height_laptops > etc.)
     *   - Orientation: tablets/mobiles moeten altijd horizontal zijn
     *   - Width-inconsistentie tussen vertical breakpoints
     */
    register_rest_route( 'aspera/v1', '/header/migrate/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            $id   = (int) $req['id'];
            $post = get_post( $id );

            if ( ! $post || $post->post_type !== 'us_header' ) {
                return new WP_Error( 'not_found', 'us_header post niet gevonden.', [ 'status' => 404 ] );
            }

            $raw  = json_decode( $post->post_content, true );
            if ( ! is_array( $raw ) ) {
                return new WP_Error( 'parse_error', 'Kon post_content niet als JSON parsen.', [ 'status' => 500 ] );
            }

            $breakpoints     = [ 'laptops', 'tablets', 'mobiles' ];
            $zones           = [ 'top', 'middle', 'bottom' ];
            $changes         = [];
            $observations    = [];
            $default_options = $raw['default']['options'] ?? [];

            // --- 1. Auto-fix: kopieer deprecated opties van default naar breakpoints ---

            // Kleur-opties per zone
            $color_suffixes = [
                '_bg_color', '_text_color', '_text_hover_color',
                '_transparent_bg_color', '_transparent_text_color', '_transparent_text_hover_color',
            ];
            $color_keys = [];
            foreach ( $zones as $zone ) {
                foreach ( $color_suffixes as $suffix ) {
                    $color_keys[] = $zone . $suffix;
                }
            }

            // Fullwidth per zone
            $fullwidth_keys = [];
            foreach ( $zones as $zone ) {
                $fullwidth_keys[] = $zone . '_fullwidth';
            }

            // Globale opties
            $global_keys = [ 'shadow', 'scroll_breakpoint' ];

            $all_fix_keys = array_merge( $color_keys, $fullwidth_keys, $global_keys );

            foreach ( $breakpoints as $bp ) {
                if ( ! isset( $raw[ $bp ]['options'] ) ) continue;

                foreach ( $all_fix_keys as $key ) {
                    if ( ! array_key_exists( $key, $default_options ) ) continue;

                    $default_val = $default_options[ $key ];
                    $bp_val      = $raw[ $bp ]['options'][ $key ] ?? null;

                    if ( $bp_val !== null && $bp_val !== $default_val ) {
                        $changes[] = [
                            'breakpoint' => $bp,
                            'key'        => $key,
                            'old'        => $bp_val,
                            'new'        => $default_val,
                        ];
                    }

                    $raw[ $bp ]['options'][ $key ] = $default_val;
                }
            }

            // --- 2. Auto-fix: lege layout posities verwijderen ---

            $all_bps = array_merge( [ 'default' ], $breakpoints );
            foreach ( $all_bps as $bp ) {
                if ( ! isset( $raw[ $bp ]['layout'] ) ) continue;
                $layout = $raw[ $bp ]['layout'];
                foreach ( $layout as $pos => $items ) {
                    if ( $pos === 'hidden' ) continue;
                    if ( is_array( $items ) && empty( $items ) ) {
                        unset( $raw[ $bp ]['layout'][ $pos ] );
                        $changes[] = [
                            'breakpoint' => $bp,
                            'key'        => "layout.{$pos}",
                            'old'        => '[]',
                            'new'        => '(removed)',
                        ];
                    }
                }
            }

            // --- 3. Observatie: centering-verschillen ---

            $centering_keys = [];
            foreach ( $zones as $zone ) {
                $centering_keys[] = $zone . '_centering';
            }

            foreach ( $centering_keys as $key ) {
                $default_val = $default_options[ $key ] ?? null;
                if ( $default_val === null ) continue;

                foreach ( $breakpoints as $bp ) {
                    $bp_val = $raw[ $bp ]['options'][ $key ] ?? null;
                    if ( $bp_val !== null && $bp_val !== $default_val ) {
                        $observations[] = [
                            'type'       => 'centering_difference',
                            'key'        => $key,
                            'breakpoint' => $bp,
                            'default'    => $default_val,
                            'value'      => $bp_val,
                        ];
                    }
                }
            }

            // --- 4. Observatie: hoogte-anomalieën per zone ---

            $height_suffixes = [ '_height', '_sticky_height' ];
            $bp_order        = [ 'default', 'laptops', 'tablets', 'mobiles' ];
            $sticky_enabled  = (int) ( $default_options['sticky'] ?? 0 );

            foreach ( $zones as $zone ) {
                // Skip zone als deze uitgeschakeld is (top_show/bottom_show = 0)
                // Middle zone heeft geen show-toggle, is altijd zichtbaar
                if ( $zone !== 'middle' ) {
                    $zone_show = (int) ( $default_options[ $zone . '_show' ] ?? 1 );
                    if ( $zone_show === 0 ) continue;
                }

                foreach ( $height_suffixes as $suffix ) {
                    // Skip sticky checks als sticky uitgeschakeld is
                    if ( $suffix === '_sticky_height' && ! $sticky_enabled ) continue;

                    $key    = $zone . $suffix;
                    $values = [];

                    foreach ( $bp_order as $bp ) {
                        $val = $raw[ $bp ]['options'][ $key ] ?? null;
                        if ( $val !== null ) {
                            $values[ $bp ] = $val;
                        }
                    }

                    if ( count( $values ) < 2 ) continue;

                    // Vergelijk: kleiner breakpoint mag niet groter zijn dan groter breakpoint
                    $prev_bp  = null;
                    $prev_num = null;
                    foreach ( $bp_order as $bp ) {
                        if ( ! isset( $values[ $bp ] ) ) continue;
                        $num = (int) $values[ $bp ];
                        if ( $prev_bp !== null && $num > $prev_num ) {
                            $observations[] = [
                                'type'       => 'height_anomaly',
                                'zone'       => $zone,
                                'key'        => $key,
                                'breakpoint' => $bp,
                                'value'      => $values[ $bp ],
                                'larger_than' => $prev_bp,
                                'reference'  => $values[ $prev_bp ],
                                'detail'     => "{$bp} ({$values[$bp]}) > {$prev_bp} ({$values[$prev_bp]})",
                            ];
                        }
                        $prev_bp  = $bp;
                        $prev_num = $num;
                    }
                }
            }

            // --- 5. Observatie: orientation per breakpoint ---
            // Beleid: tablets en mobiles zijn altijd horizontal.
            // Default en laptops mogen vertical zijn.
            // Width moet gelijk zijn over alle vertical breakpoints.

            $ver_widths = [];
            foreach ( $all_bps as $bp ) {
                $bp_opts     = $raw[ $bp ]['options'] ?? [];
                $orientation = $bp_opts['orientation'] ?? 'hor';
                $width       = $bp_opts['width'] ?? '';

                if ( in_array( $bp, [ 'tablets', 'mobiles' ], true ) && $orientation === 'ver' ) {
                    $observations[] = [
                        'type'       => 'orientation_vertical_forbidden',
                        'breakpoint' => $bp,
                        'value'      => $orientation,
                        'detail'     => "{$bp} heeft vertical orientation — moet altijd horizontal zijn",
                    ];
                }

                if ( $orientation === 'ver' && $width !== '' ) {
                    $ver_widths[ $bp ] = $width;
                }
            }

            // Width-consistentie over vertical breakpoints
            if ( count( $ver_widths ) > 1 ) {
                $unique_widths = array_unique( array_values( $ver_widths ) );
                if ( count( $unique_widths ) > 1 ) {
                    $parts = [];
                    foreach ( $ver_widths as $bp => $w ) {
                        $parts[] = "{$bp}: {$w}";
                    }
                    $observations[] = [
                        'type'       => 'orientation_inconsistent_width',
                        'widths'     => $ver_widths,
                        'detail'     => 'Vertical breakpoints hebben verschillende width: ' . implode( ', ', $parts ),
                    ];
                }
            }

            // --- 5b. Observatie: custom breakpoints ---
            // Beleid: custom breakpoints zijn meestal onnodig (thema regelt dit).
            // Als ze aan staan: kleiner device mag nooit een hogere breakpoint-waarde hebben.

            $bp_device_order = [ 'laptops', 'tablets', 'mobiles' ];
            $custom_bp_values = [];

            foreach ( $bp_device_order as $bp ) {
                $bp_opts   = $raw[ $bp ]['options'] ?? [];
                $is_custom = ( $bp_opts['custom_breakpoint'] ?? 0 ) == 1;

                if ( $is_custom ) {
                    $bp_val = (int) $bp_opts['breakpoint'];
                    $custom_bp_values[ $bp ] = $bp_val;

                    $observations[] = [
                        'type'       => 'custom_breakpoint_active',
                        'breakpoint' => $bp,
                        'value'      => $bp_opts['breakpoint'],
                        'detail'     => "{$bp} heeft custom breakpoint ({$bp_opts['breakpoint']}) — meestal onnodig, thema reguleert dit",
                    ];
                }
            }

            // Controleer afschaling: kleiner device mag niet hoger zijn dan groter device
            $prev_bp  = null;
            $prev_val = null;
            foreach ( $bp_device_order as $bp ) {
                if ( ! isset( $custom_bp_values[ $bp ] ) ) continue;
                if ( $prev_bp !== null && $custom_bp_values[ $bp ] >= $prev_val ) {
                    $observations[] = [
                        'type'         => 'custom_breakpoint_invalid_order',
                        'breakpoint'   => $bp,
                        'value'        => $custom_bp_values[ $bp ] . 'px',
                        'larger_than'  => $prev_bp,
                        'reference'    => $prev_val . 'px',
                        'detail'       => "{$bp} ({$custom_bp_values[$bp]}px) >= {$prev_bp} ({$prev_val}px) — kleiner device mag geen hogere of gelijke breakpoint hebben",
                    ];
                }
                $prev_bp  = $bp;
                $prev_val = $custom_bp_values[ $bp ];
            }

            // --- 5c. Custom breakpoints vs site_content_width ---

            $theme_opts    = get_option( 'usof_options_Impreza', [] );
            $content_width = is_array( $theme_opts ) && isset( $theme_opts['site_content_width'] )
                ? (int) $theme_opts['site_content_width']
                : null;

            if ( $content_width !== null ) {
                foreach ( $custom_bp_values as $bp => $bp_val ) {
                    if ( $bp_val > $content_width ) {
                        $observations[] = [
                            'type'       => 'custom_breakpoint_exceeds_content_width',
                            'breakpoint' => $bp,
                            'value'      => $bp_val . 'px',
                            'reference'  => $content_width . 'px',
                            'detail'     => "{$bp} custom breakpoint ({$bp_val}px) > site_content_width ({$content_width}px)",
                        ];
                    }
                }
            }

            // --- 5d. Menu mobile_width checks ---

            if ( isset( $raw['data'] ) && is_array( $raw['data'] ) ) {
                // Bepaal hoogste actieve header breakpoint
                $all_bps_ordered = [ 'default', 'laptops', 'tablets', 'mobiles' ];
                $highest_bp = 0;
                foreach ( $all_bps_ordered as $bp ) {
                    if ( $bp === 'default' ) continue;
                    $bp_opts_check = $raw[ $bp ]['options'] ?? [];
                    $bp_val_check  = (int) ( $bp_opts_check['breakpoint'] ?? 0 );
                    if ( $bp_val_check > $highest_bp ) {
                        $highest_bp = $bp_val_check;
                    }
                }

                foreach ( $raw['data'] as $el_id => $el ) {
                    if ( strpos( $el_id, 'menu:' ) !== 0 ) continue;
                    $mw = isset( $el['mobile_width'] ) ? (int) $el['mobile_width'] : null;
                    if ( $mw === null ) continue;

                    if ( $mw > 10000 ) {
                        $observations[] = [
                            'type'    => 'menu_mobile_always',
                            'element' => $el_id,
                            'value'   => $el['mobile_width'],
                            'detail'  => "{$el_id}: mobile_width ({$el['mobile_width']}) > 10000px — always-hamburger patroon",
                        ];
                    } elseif ( $content_width !== null && $mw > $content_width ) {
                        $observations[] = [
                            'type'      => 'menu_mobile_exceeds_content_width',
                            'element'   => $el_id,
                            'value'     => $el['mobile_width'],
                            'reference' => $content_width . 'px',
                            'detail'    => "{$el_id}: mobile_width ({$el['mobile_width']}) > site_content_width ({$content_width}px) — ongebruikelijk",
                        ];
                    } elseif ( $highest_bp > 0 && $mw > $highest_bp ) {
                        $observations[] = [
                            'type'      => 'menu_mobile_exceeds_breakpoints',
                            'element'   => $el_id,
                            'value'     => $el['mobile_width'],
                            'reference' => $highest_bp . 'px',
                            'detail'    => "{$el_id}: mobile_width ({$el['mobile_width']}) > hoogste header breakpoint ({$highest_bp}px) — menu gaat eerder mobiel dan de header",
                        ];
                    }
                }
            }

            // --- 5e. Observatie: element hoogte-anomalieën ---

            $el_bp_map = [
                'height_default' => 'default',
                'height_laptops' => 'laptops',
                'height_tablets' => 'tablets',
                'height_mobiles' => 'mobiles',
            ];
            $el_sticky_map = [
                'height_sticky'         => 'default',
                'height_sticky_laptops' => 'laptops',
                'height_sticky_tablets' => 'tablets',
                'height_sticky_mobiles' => 'mobiles',
            ];

            if ( isset( $raw['data'] ) && is_array( $raw['data'] ) ) {
                foreach ( $raw['data'] as $el_id => $el ) {
                    // Reguliere hoogtes (+ sticky als sticky ingeschakeld)
                    $el_maps = [ $el_bp_map ];
                    if ( $sticky_enabled ) {
                        $el_maps[] = $el_sticky_map;
                    }
                    foreach ( $el_maps as $map ) {
                        $values = [];
                        foreach ( $map as $el_key => $bp ) {
                            if ( isset( $el[ $el_key ] ) && $el[ $el_key ] !== '' ) {
                                $values[ $bp ] = $el[ $el_key ];
                            }
                        }

                        if ( count( $values ) < 2 ) continue;

                        $prev_bp  = null;
                        $prev_num = null;
                        foreach ( $bp_order as $bp ) {
                            if ( ! isset( $values[ $bp ] ) ) continue;
                            $num = (int) $values[ $bp ];
                            if ( $prev_bp !== null && $num > $prev_num ) {
                                $label = ( $map === $el_sticky_map ) ? 'sticky' : 'regular';
                                $observations[] = [
                                    'type'        => 'element_height_anomaly',
                                    'element'     => $el_id,
                                    'height_type' => $label,
                                    'breakpoint'  => $bp,
                                    'value'       => $values[ $bp ],
                                    'larger_than' => $prev_bp,
                                    'reference'   => $values[ $prev_bp ],
                                    'detail'      => "{$el_id} {$label}: {$bp} ({$values[$bp]}) > {$prev_bp} ({$values[$prev_bp]})",
                                ];
                            }
                            $prev_bp  = $bp;
                            $prev_num = $num;
                        }
                    }
                }
            }

            // --- 6. Schrijf gecleande JSON terug ---

            $new_json = wp_json_encode( $raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

            wp_update_post( [
                'ID'           => $id,
                'post_content' => $new_json,
            ] );

            return [
                'header_id'    => $id,
                'title'        => $post->post_title,
                'status'       => 'migrated',
                'changes'      => count( $changes ),
                'change_log'   => $changes,
                'observations' => $observations,
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/header/validate
     * Read-only validatie van alle us_header posts op configuratiefouten:
     * custom breakpoint volgorde, exceeds content width, orientation, menu mobile_width.
     * Tegenhanger van /header/migrate (schrijftool) — deze endpoint schrijft niets terug.
     */
    register_rest_route( 'aspera/v1', '/header/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            $headers = get_posts( [
                'post_type'      => 'us_header',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            ] );

            $theme_opts    = get_option( 'usof_options_Impreza', [] );
            $content_width = is_array( $theme_opts ) && isset( $theme_opts['site_content_width'] )
                ? (int) $theme_opts['site_content_width']
                : null;

            $bp_device_order = [ 'laptops', 'tablets', 'mobiles' ];
            $violations      = [];
            $observations    = [];

            foreach ( $headers as $post ) {
                $raw = json_decode( $post->post_content, true );
                if ( ! is_array( $raw ) ) continue;

                $title            = $post->post_title;
                $custom_bp_values = [];

                // ── Custom breakpoints ────────────────────────────────────
                foreach ( $bp_device_order as $bp ) {
                    $bp_opts   = $raw[ $bp ]['options'] ?? [];
                    $is_custom = ( $bp_opts['custom_breakpoint'] ?? 0 ) == 1;
                    if ( ! $is_custom ) continue;

                    $bp_val = (int) $bp_opts['breakpoint'];
                    $custom_bp_values[ $bp ] = $bp_val;

                    $observations[] = [
                        'rule'     => 'custom_breakpoint_active',
                        'severity' => 'observation',
                        'post_id'  => $post->ID,
                        'detail'   => $title . ': ' . $bp . ' heeft custom breakpoint (' . $bp_opts['breakpoint'] . ')',
                    ];
                }

                // Ongeldige volgorde: kleiner device >= groter device
                $prev_bp  = null;
                $prev_val = null;
                foreach ( $bp_device_order as $bp ) {
                    if ( ! isset( $custom_bp_values[ $bp ] ) ) { $prev_bp = null; $prev_val = null; continue; }
                    if ( $prev_bp !== null && $custom_bp_values[ $bp ] >= $prev_val ) {
                        $violations[] = [
                            'rule'     => 'custom_breakpoint_invalid_order',
                            'severity' => 'error',
                            'post_id'  => $post->ID,
                            'detail'   => $title . ': ' . $bp . ' (' . $custom_bp_values[ $bp ] . 'px) >= ' . $prev_bp . ' (' . $prev_val . 'px) — kleiner device mag geen hogere breakpoint hebben',
                        ];
                    }
                    $prev_bp  = $bp;
                    $prev_val = $custom_bp_values[ $bp ];
                }

                // Custom breakpoint > site_content_width
                if ( $content_width !== null ) {
                    foreach ( $custom_bp_values as $bp => $bp_val ) {
                        if ( $bp_val > $content_width ) {
                            $violations[] = [
                                'rule'     => 'custom_breakpoint_exceeds_content_width',
                                'severity' => 'warning',
                                'post_id'  => $post->ID,
                                'detail'   => $title . ': ' . $bp . ' (' . $bp_val . 'px) > site_content_width (' . $content_width . 'px)',
                            ];
                        }
                    }
                }

                // ── Orientation: tablets en mobiles moeten horizontal zijn ─
                foreach ( [ 'tablets', 'mobiles' ] as $bp ) {
                    $orientation = $raw[ $bp ]['options']['orientation'] ?? 'hor';
                    if ( $orientation === 'ver' ) {
                        $violations[] = [
                            'rule'     => 'orientation_vertical_forbidden',
                            'severity' => 'error',
                            'post_id'  => $post->ID,
                            'detail'   => $title . ': ' . $bp . ' heeft vertical orientation — moet altijd horizontal zijn',
                        ];
                    }
                }

                // ── Scroll breakpoint checks ──────────────────────────────
                $sb_values = [];
                foreach ( [ 'default', 'laptops', 'tablets', 'mobiles' ] as $bp ) {
                    $sb = $raw[ $bp ]['options']['scroll_breakpoint'] ?? null;
                    if ( $sb === null || $sb === '' ) continue;
                    $sb_values[ $bp ] = $sb;
                    if ( $sb !== '1px' ) {
                        $observations[] = [
                            'rule'     => 'scroll_breakpoint_not_1px',
                            'severity' => 'observation',
                            'post_id'  => $post->ID,
                            'detail'   => $title . ': ' . $bp . ' scroll_breakpoint = ' . $sb . ' (verwacht 1px)',
                        ];
                    }
                }
                if ( count( $sb_values ) > 1 && count( array_unique( $sb_values ) ) > 1 ) {
                    $parts = [];
                    foreach ( $sb_values as $bp => $v ) {
                        $parts[] = $bp . ': ' . $v;
                    }
                    $observations[] = [
                        'rule'     => 'scroll_breakpoint_inconsistent',
                        'severity' => 'observation',
                        'post_id'  => $post->ID,
                        'detail'   => $title . ': scroll_breakpoint inconsistent over breakpoints — ' . implode( ', ', $parts ),
                    ];
                }

                // ── Centering checks per zone per breakpoint ──────────────
                // Regel: centering=1 is alleen correct als center gevuld is EN
                // (left OF right gevuld). Bij elke andere staat moet centering=0.
                foreach ( [ 'default', 'laptops', 'tablets', 'mobiles' ] as $bp ) {
                    $bp_layout  = $raw[ $bp ]['layout'] ?? null;
                    $bp_options = $raw[ $bp ]['options'] ?? null;
                    if ( ! is_array( $bp_layout ) || ! is_array( $bp_options ) ) continue;

                    foreach ( [ 'top', 'middle', 'bottom' ] as $zone ) {
                        // Skip uitgeschakelde zones (middle is altijd actief)
                        if ( $zone !== 'middle' ) {
                            $show = (int) ( $bp_options[ $zone . '_show' ] ?? 1 );
                            if ( $show === 0 ) continue;
                        }

                        $left   = ! empty( $bp_layout[ $zone . '_left' ] );
                        $center = ! empty( $bp_layout[ $zone . '_center' ] );
                        $right  = ! empty( $bp_layout[ $zone . '_right' ] );
                        $cent   = (int) ( $bp_options[ $zone . '_centering' ] ?? 0 );

                        $needed = $center && ( $left || $right );

                        if ( ! $cent && $needed ) {
                            $violations[] = [
                                'rule'     => 'centering_missing',
                                'severity' => 'warning',
                                'post_id'  => $post->ID,
                                'detail'   => $title . ': ' . $bp . '/' . $zone . ' heeft center + randkolom gevuld maar centering staat uit',
                            ];
                        } elseif ( $cent && ! $needed ) {
                            $violations[] = [
                                'rule'     => 'centering_unexpected',
                                'severity' => 'warning',
                                'post_id'  => $post->ID,
                                'detail'   => $title . ': ' . $bp . '/' . $zone . ' heeft centering aan maar center+randkolom voorwaarde niet vervuld',
                            ];
                        }
                    }
                }

                // ── Unused element checks ─────────────────────────────────
                // Element wordt nergens daadwerkelijk gerenderd: in alle 4
                // breakpoints staat het in `hidden`, OF in een gedeactiveerde
                // top/bottom-zone, OF een combinatie daarvan. 0 actieve voorkomens.
                if ( isset( $raw['data'] ) && is_array( $raw['data'] ) ) {
                    foreach ( array_keys( $raw['data'] ) as $el_id ) {
                        $active_placements = 0;
                        $placement_states  = [];

                        foreach ( [ 'default', 'laptops', 'tablets', 'mobiles' ] as $bp ) {
                            $bp_layout  = $raw[ $bp ]['layout'] ?? null;
                            $bp_options = $raw[ $bp ]['options'] ?? null;
                            if ( ! is_array( $bp_layout ) ) continue;

                            $hidden_arr = isset( $bp_layout['hidden'] ) && is_array( $bp_layout['hidden'] ) ? $bp_layout['hidden'] : [];
                            if ( in_array( $el_id, $hidden_arr, true ) ) {
                                $placement_states[] = $bp . ':hidden';
                                continue;
                            }

                            foreach ( [ 'top', 'middle', 'bottom' ] as $zone ) {
                                foreach ( [ 'left', 'center', 'right' ] as $col ) {
                                    $cell = $bp_layout[ $zone . '_' . $col ] ?? null;
                                    if ( ! is_array( $cell ) ) continue;
                                    if ( ! in_array( $el_id, $cell, true ) ) continue;

                                    $zone_active = true;
                                    if ( $zone !== 'middle' ) {
                                        $show = (int) ( $bp_options[ $zone . '_show' ] ?? 1 );
                                        $zone_active = ( $show === 1 );
                                    }

                                    if ( $zone_active ) {
                                        $active_placements++;
                                        $placement_states[] = $bp . ':' . $zone . '_' . $col;
                                    } else {
                                        $placement_states[] = $bp . ':' . $zone . '_' . $col . ' (zone uit)';
                                    }
                                }
                            }
                        }

                        if ( $active_placements === 0 && ! empty( $placement_states ) ) {
                            $observations[] = [
                                'rule'     => 'header_element_unused',
                                'severity' => 'observation',
                                'post_id'  => $post->ID,
                                'detail'   => $title . ': element ' . $el_id . ' nergens in een actieve zone gebruikt (' . implode( ', ', $placement_states ) . ')',
                            ];
                        }
                    }
                }

                // ── Menu mobile_width checks ──────────────────────────────
                if ( isset( $raw['data'] ) && is_array( $raw['data'] ) ) {
                    $highest_bp = 0;
                    foreach ( $bp_device_order as $bp ) {
                        $bpv = (int) ( $raw[ $bp ]['options']['breakpoint'] ?? 0 );
                        if ( $bpv > $highest_bp ) $highest_bp = $bpv;
                    }

                    foreach ( $raw['data'] as $el_id => $el ) {
                        if ( strpos( $el_id, 'menu:' ) !== 0 ) continue;

                        // mobile_behavior: "1" = label and arrow (gewenst).
                        // "0" = arrow only, "2" = label only — beide ongewenst (UX).
                        if ( isset( $el['mobile_behavior'] ) && (string) $el['mobile_behavior'] !== '1' ) {
                            $violations[] = [
                                'rule'     => 'menu_mobile_behavior_not_label_and_arrow',
                                'severity' => 'warning',
                                'post_id'  => $post->ID,
                                'detail'   => $title . ': ' . $el_id . ' mobile_behavior = "' . $el['mobile_behavior'] . '" (verwacht "1" = label and arrow)',
                            ];
                        }

                        // mobile_icon_size per breakpoint: max 50px, en niet-stijgend
                        // van default → laptops → tablets → mobiles.
                        $size_keys = [
                            'default' => 'mobile_icon_size',
                            'laptops' => 'mobile_icon_size_laptops',
                            'tablets' => 'mobile_icon_size_tablets',
                            'mobiles' => 'mobile_icon_size_mobiles',
                        ];
                        $size_values = [];
                        foreach ( $size_keys as $bp_lbl => $key ) {
                            if ( ! isset( $el[ $key ] ) || $el[ $key ] === '' ) continue;
                            $px = (int) preg_replace( '/[^0-9-]/', '', (string) $el[ $key ] );
                            $size_values[ $bp_lbl ] = [ 'raw' => $el[ $key ], 'px' => $px ];
                        }

                        $too_large = [];
                        foreach ( $size_values as $bp_lbl => $sv ) {
                            if ( $sv['px'] > 50 ) {
                                $too_large[] = $bp_lbl . '=' . $sv['raw'];
                            }
                        }
                        if ( ! empty( $too_large ) ) {
                            $violations[] = [
                                'rule'     => 'menu_mobile_icon_size_too_large',
                                'severity' => 'warning',
                                'post_id'  => $post->ID,
                                'detail'   => $title . ': ' . $el_id . ' mobile_icon_size > 50px op ' . implode( ', ', $too_large ),
                            ];
                        }

                        $bp_seq    = [ 'default', 'laptops', 'tablets', 'mobiles' ];
                        $increases = [];
                        $prev_lbl  = null;
                        foreach ( $bp_seq as $bp_lbl ) {
                            if ( ! isset( $size_values[ $bp_lbl ] ) ) continue;
                            if ( $prev_lbl !== null && $size_values[ $bp_lbl ]['px'] > $size_values[ $prev_lbl ]['px'] ) {
                                $increases[] = $prev_lbl . ' (' . $size_values[ $prev_lbl ]['raw'] . ') → ' . $bp_lbl . ' (' . $size_values[ $bp_lbl ]['raw'] . ')';
                            }
                            $prev_lbl = $bp_lbl;
                        }
                        if ( ! empty( $increases ) ) {
                            $seq_str = [];
                            foreach ( $bp_seq as $bp_lbl ) {
                                if ( isset( $size_values[ $bp_lbl ] ) ) {
                                    $seq_str[] = $bp_lbl . '=' . $size_values[ $bp_lbl ]['raw'];
                                }
                            }
                            $violations[] = [
                                'rule'     => 'menu_mobile_icon_size_inconsistent',
                                'severity' => 'warning',
                                'post_id'  => $post->ID,
                                'detail'   => $title . ': ' . $el_id . ' mobile_icon_size sequentie [' . implode( ', ', $seq_str ) . '] stijgt op: ' . implode( ', ', $increases ),
                            ];
                        }

                        // align_edges: per actieve plaatsing bepalen of de setting
                        // klopt. Verwacht 1 als menu de header-rand raakt
                        // (eerste in *_left, of laatste in *_right). Anders 0.
                        if ( isset( $el['align_edges'] ) ) {
                            $actual_ae = (int) $el['align_edges'];
                            $ae_mismatches = [];
                            foreach ( [ 'default', 'laptops', 'tablets', 'mobiles' ] as $bp ) {
                                $bp_layout  = $raw[ $bp ]['layout'] ?? null;
                                $bp_options = $raw[ $bp ]['options'] ?? null;
                                if ( ! is_array( $bp_layout ) ) continue;

                                foreach ( [ 'top', 'middle', 'bottom' ] as $zone ) {
                                    if ( $zone !== 'middle' ) {
                                        $show = (int) ( $bp_options[ $zone . '_show' ] ?? 1 );
                                        if ( $show === 0 ) continue;
                                    }
                                    foreach ( [ 'left', 'center', 'right' ] as $col ) {
                                        $cell = $bp_layout[ $zone . '_' . $col ] ?? null;
                                        if ( ! is_array( $cell ) || empty( $cell ) ) continue;
                                        if ( ! in_array( $el_id, $cell, true ) ) continue;

                                        $expected = 0;
                                        if ( $col === 'left' && $cell[0] === $el_id ) {
                                            $expected = 1;
                                        } elseif ( $col === 'right' && end( $cell ) === $el_id ) {
                                            $expected = 1;
                                        }

                                        if ( $expected !== $actual_ae ) {
                                            $ae_mismatches[] = $bp . '/' . $zone . '_' . $col . ' (verwacht ' . $expected . ')';
                                        }
                                    }
                                }
                            }
                            if ( ! empty( $ae_mismatches ) ) {
                                $violations[] = [
                                    'rule'     => 'menu_align_edges_mismatch',
                                    'severity' => 'warning',
                                    'post_id'  => $post->ID,
                                    'detail'   => $title . ': ' . $el_id . ' align_edges = ' . $actual_ae . ' maar verkeerd op: ' . implode( ', ', $ae_mismatches ),
                                ];
                            }
                        }

                        $mw = isset( $el['mobile_width'] ) ? (int) $el['mobile_width'] : null;
                        if ( $mw === null ) continue;

                        if ( $mw > 10000 ) {
                            $observations[] = [
                                'rule'     => 'menu_mobile_always',
                                'severity' => 'observation',
                                'post_id'  => $post->ID,
                                'detail'   => $title . ': ' . $el_id . ' mobile_width (' . $el['mobile_width'] . ') > 10000px — always-hamburger',
                            ];
                        } elseif ( $content_width !== null && $mw > $content_width ) {
                            $observations[] = [
                                'rule'     => 'menu_mobile_exceeds_content_width',
                                'severity' => 'observation',
                                'post_id'  => $post->ID,
                                'detail'   => $title . ': ' . $el_id . ' mobile_width (' . $mw . 'px) > site_content_width (' . $content_width . 'px)',
                            ];
                        } elseif ( $highest_bp > 0 && $mw > $highest_bp ) {
                            $observations[] = [
                                'rule'     => 'menu_mobile_exceeds_breakpoints',
                                'severity' => 'observation',
                                'post_id'  => $post->ID,
                                'detail'   => $title . ': ' . $el_id . ' mobile_width (' . $mw . 'px) > hoogste header breakpoint (' . $highest_bp . 'px)',
                            ];
                        }
                    }
                }
            }

            return [
                'status'       => empty( $violations ) ? 'ok' : 'issues_found',
                'violations'   => $violations,
                'observations' => $observations,
                'header_count' => count( $headers ),
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/grid/{id}
     * Geeft us_grid_layout JSON terug: elementen, layout en options,
     * lege waarden gestript.
     */
    register_rest_route( 'aspera/v1', '/grid/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            $id   = (int) $req['id'];
            $post = get_post( $id );

            if ( ! $post || $post->post_type !== 'us_grid_layout' ) {
                return new WP_Error( 'not_found', 'us_grid_layout post niet gevonden.', [ 'status' => 404 ] );
            }

            $data = json_decode( $post->post_content, true );

            if ( ! is_array( $data ) ) {
                return new WP_Error( 'parse_error', 'Kon post_content niet als JSON parsen.', [ 'status' => 500 ] );
            }

            $elements = [];
            if ( isset( $data['elements'] ) && is_array( $data['elements'] ) ) {
                foreach ( $data['elements'] as $el_id => $el ) {
                    $elements[ $el_id ] = aspera_strip_empty( $el );
                }
            }

            return array_filter( [
                'elements' => $elements ?: null,
                'layout'   => $data['layout'] ?? null,
                'options'  => isset( $data['options'] ) ? aspera_strip_empty( $data['options'] ) : null,
            ] );
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/wpb/validate/all
     * Valideert us_content_template en us_page_block posts op beleidsschendingen.
     *
     * Optionele query parameters:
     * - post_types  kommagescheiden post types (default: us_content_template,us_page_block)
     * - page        paginanummer (default: 1)
     * - per_page    posts per pagina, max 100 (default: 20)
     */
    register_rest_route( 'aspera/v1', '/wpb/validate/all', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            $raw_types  = $req->get_param( 'post_types' );
            $post_types = $raw_types
                ? array_map( 'trim', explode( ',', $raw_types ) )
                : [ 'us_content_template', 'us_page_block' ];

            $page     = max( 1, (int) ( $req->get_param( 'page' ) ?? 1 ) );
            $per_page = min( 100, max( 1, (int) ( $req->get_param( 'per_page' ) ?? 20 ) ) );

            $query = new WP_Query( [
                'post_type'      => $post_types,
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'post_type',
                'order'          => 'ASC',
                'no_found_rows'  => false,
            ] );

            if ( ! $query->have_posts() ) {
                return new WP_Error( 'not_found', 'Geen posts gevonden voor de opgegeven post types.', [ 'status' => 404 ] );
            }

            $posts              = $query->posts;
            $all_violations     = [];
            $total_shortcodes   = 0;
            $posts_with_issues  = 0;

            foreach ( $posts as $post ) {
                if ( ! $post->post_content ) continue;

                // Deprecated WPForms shortcode detectie
                if ( preg_match_all( '/\[wpforms\s+[^\]]*id=["\']?(\d+)["\']?/', $post->post_content, $wpf_matches ) ) {
                    $posts_with_issues++;
                    foreach ( $wpf_matches[1] as $wpf_id ) {
                        $all_violations[] = [
                            'post_id'    => $post->ID,
                            'post_type'  => $post->post_type,
                            'post_title' => $post->post_title,
                            'rule'       => 'wpforms_deprecated',
                            'severity'   => 'warning',
                            'snippet'    => '[wpforms id="' . $wpf_id . '"] — deprecated, vervang door us_cform',
                        ];
                    }
                }

                $result           = aspera_wpb_validate_post( $post );
                $total_shortcodes += $result['shortcode_count'];

                if ( ! empty( $result['violations'] ) ) {
                    $posts_with_issues++;
                    foreach ( $result['violations'] as $v ) {
                        $all_violations[] = array_merge(
                            [
                                'post_id'    => $post->ID,
                                'post_type'  => $post->post_type,
                                'post_title' => $post->post_title,
                            ],
                            $v
                        );
                    }
                }
            }

            $response = [
                'status'             => empty( $all_violations ) ? 'ok' : 'violations_found',
                'page'               => $page,
                'per_page'           => $per_page,
                'total_posts'        => (int) $query->found_posts,
                'total_pages'        => (int) $query->max_num_pages,
                'posts_scanned'      => count( $posts ),
                'shortcodes_scanned' => $total_shortcodes,
                'posts_with_issues'  => $posts_with_issues,
                'violation_count'    => count( $all_violations ),
            ];

            if ( ! empty( $all_violations ) ) {
                $response['violations'] = $all_violations;
            }

            return $response;
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/wpb/similar
     * Detecteert structureel vergelijkbare templates als fuseer-kandidaten.
     *
     * Optionele query parameters:
     * - post_types  kommagescheiden post types (default: us_content_template)
     * - threshold   minimale gelijkenis 0.0–1.0 (default: 0.80)
     * - max_posts   maximaal aantal posts voor LCS-vergelijking, max 100 (default: 50)
     *               Bij overschrijding wordt de operatie afgebroken (status: limit_exceeded).
     *
     * Vergelijking op basis van genormaliseerde shortcode tag-reeks (LCS).
     * Attribuutwaarden, ACF-slugs en condities worden genegeerd.
     * Output gesorteerd op similarity aflopend.
     *
     * Let op: de rekenbelasting schaalt kwadratisch met het aantal posts
     * (n posts = n*(n-1)/2 LCS-vergelijkingen). De max_posts limiet voorkomt
     * dat grote sets de server overbelasten.
     */
    register_rest_route( 'aspera/v1', '/wpb/similar', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            $raw_types  = $req->get_param( 'post_types' );
            $post_types = $raw_types
                ? array_map( 'trim', explode( ',', $raw_types ) )
                : [ 'us_content_template' ];

            $threshold = (float) ( $req->get_param( 'threshold' ) ?? 0.80 );
            $threshold = max( 0.0, min( 1.0, $threshold ) );

            $max_posts = min( 100, max( 2, (int) ( $req->get_param( 'max_posts' ) ?? 50 ) ) );

            $query = new WP_Query( [
                'post_type'      => $post_types,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ] );

            $posts = $query->posts;
            $count = count( $posts );

            // Circuit breaker: breek af vóór de kwadratische LCS-loops beginnen
            if ( $count > $max_posts ) {
                return new WP_Error( 'limit_exceeded',
                    sprintf(
                        'Te veel posts (%d) voor veilige vergelijking — limiet is %d. Gebruik ?max_posts=N (max 100) of beperk post_types.',
                        $count,
                        $max_posts
                    ),
                    [ 'status' => 422, 'posts_found' => $count, 'max_posts' => $max_posts ]
                );
            }

            if ( $count < 2 ) {
                return [
                    'status'  => 'ok',
                    'message' => 'Minder dan 2 posts gevonden — geen vergelijking mogelijk.',
                    'pairs'   => [],
                ];
            }

            // Bouw per post een tag-reeks
            $sequences = [];
            foreach ( $posts as $post ) {
                $content = get_post_field( 'post_content', $post->ID, 'raw' );
                $sequences[ $post->ID ] = [
                    'title' => $post->post_title,
                    'type'  => $post->post_type,
                    'tags'  => aspera_tag_sequence( $content ),
                ];
            }

            // Vergelijk alle paren — O(n²) LCS-berekeningen
            $pairs = [];
            $ids   = array_keys( $sequences );

            for ( $i = 0; $i < $count - 1; $i++ ) {
                for ( $j = $i + 1; $j < $count; $j++ ) {
                    $id_a = $ids[$i];
                    $id_b = $ids[$j];
                    $sim  = aspera_sequence_similarity(
                        $sequences[$id_a]['tags'],
                        $sequences[$id_b]['tags']
                    );

                    if ( $sim >= $threshold ) {
                        $pairs[] = [
                            'similarity'  => round( $sim * 100 ),
                            'post_a'      => [ 'id' => $id_a, 'title' => $sequences[$id_a]['title'], 'tag_count' => count( $sequences[$id_a]['tags'] ) ],
                            'post_b'      => [ 'id' => $id_b, 'title' => $sequences[$id_b]['title'], 'tag_count' => count( $sequences[$id_b]['tags'] ) ],
                        ];
                    }
                }
            }

            // Sorteer op similarity aflopend
            usort( $pairs, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );

            return [
                'status'          => empty( $pairs ) ? 'ok' : 'candidates_found',
                'posts_compared'  => $count,
                'threshold_pct'   => round( $threshold * 100 ),
                'candidate_count' => count( $pairs ),
                'pairs'           => $pairs,
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/wpb/validate/{id}
     * Controleert WPBakery shortcodes van één post op beleidsschendingen.
     * Zie aspera_wpb_validate_post() voor de volledige regelset.
     */
    register_rest_route( 'aspera/v1', '/wpb/validate/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            $id   = (int) $req['id'];
            $post = get_post( $id );

            if ( ! $post || ! $post->post_content ) {
                return new WP_Error( 'not_found', 'Post niet gevonden of leeg.', [ 'status' => 404 ] );
            }

            $result = aspera_wpb_validate_post( $post );

            if ( empty( $result['violations'] ) ) {
                return [
                    'status'             => 'ok',
                    'post_type'          => $result['post_type'],
                    'shortcodes_scanned' => $result['shortcode_count'],
                ];
            }

            return [
                'status'             => 'violations_found',
                'post_type'          => $result['post_type'],
                'shortcodes_scanned' => $result['shortcode_count'],
                'violation_count'    => count( $result['violations'] ),
                'violations'         => $result['violations'],
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/acf/validate/slugs
     * Valideert ACF veldsluggen site-wide op naamgevingsconventies.
     *
     * Context wordt bepaald uit de locatieregels van de field group:
     * - options_page regel aanwezig        → option_page context → opt_{naam}_{n} verwacht
     * - post_type niet page/post/attachment → cpt context       → _cpt_{naam}_{n} verwacht
     * - overig                             → page context       → _p_{fieldgroup}_{n} verwacht
     *
     * Gecontroleerde regels:
     * - missing_number          : slug eindigt niet op _\d+
     * - wrong_opt_format        : option page veld zonder opt_ prefix
     * - wrong_cpt_format        : CPT veld zonder _cpt_ infix (enkelvoudig gebruik)
     * - wrong_page_format       : paginaveld zonder _p_ infix (enkelvoudig gebruik)
     * - wrong_cpt_format_multi  : CPT veld zonder _cpt_ infix, maar cross-context (slug in meerdere field groups) — observation
     * - wrong_page_format_multi : paginaveld zonder _p_ infix, maar cross-context (slug in meerdere field groups) — observation
     *
     * Tab-velden worden altijd overgeslagen.
     */
    register_rest_route( 'aspera/v1', '/acf/validate/slugs', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            if ( ! function_exists( 'acf_get_field_groups' ) ) {
                return new WP_Error( 'acf_missing', 'ACF is niet actief.', [ 'status' => 500 ] );
            }

            $groups         = acf_get_field_groups();
            $issues         = [];
            $fields_scanned = 0;
            $groups_scanned = 0;

            // Pre-scan: tel hoe vaak elke slug-naam voorkomt over alle field groups.
            // Slugs in meer dan één field group zijn cross-context (multi-usage).
            $slug_group_map = [];
            foreach ( $groups as $g ) {
                $gf = acf_get_fields( $g['key'] );
                if ( ! $gf ) continue;
                foreach ( $gf as $f ) {
                    if ( ( $f['type'] ?? '' ) === 'tab' ) continue;
                    $sn = $f['name'] ?? '';
                    if ( $sn === '' ) continue;
                    $slug_group_map[ $sn ][] = $g['ID'];
                }
            }
            $slug_usage_count = [];
            foreach ( $slug_group_map as $sn => $ids ) {
                $slug_usage_count[ $sn ] = count( array_unique( $ids ) );
            }

            // Post types die geen CPT zijn — worden als page-context behandeld
            $builtin_types = [ 'page', 'post', 'attachment', 'us_content_template',
                               'us_page_block', 'us_header', 'us_grid_layout' ];

            foreach ( $groups as $group ) {
                $fields = acf_get_fields( $group['key'] );
                if ( ! $fields ) continue;

                $groups_scanned++;

                // Detecteer context uit locatieregels
                $context      = 'page'; // default
                $context_name = '';

                foreach ( (array) ( $group['location'] ?? [] ) as $or_group ) {
                    foreach ( (array) $or_group as $rule ) {
                        $param = $rule['param'] ?? '';
                        $value = $rule['value'] ?? '';

                        if ( $param === 'options_page' ) {
                            $context      = 'option_page';
                            $context_name = $value;
                            break 2;
                        }

                        if ( $param === 'post_type'
                             && ! in_array( $value, $builtin_types, true ) ) {
                            $context      = 'cpt';
                            // Verwijder _cpt suffix voor de context_name
                            $context_name = preg_replace( '/_cpt$/', '', $value );
                            break 2;
                        }
                    }
                }

                foreach ( $fields as $field ) {
                    // Tab-velden volgen geen slug-conventie
                    if ( ( $field['type'] ?? '' ) === 'tab' ) continue;

                    $slug = $field['name'] ?? '';
                    if ( $slug === '' ) continue;

                    $fields_scanned++;

                    $base = [
                        'field_group_id'    => $group['ID'],
                        'field_group_title' => $group['title'],
                        'group_context'     => $context,
                        'context_name'      => $context_name,
                        'field_slug'        => $slug,
                        'field_type'        => $field['type'] ?? '',
                    ];

                    // Regel 1: slug moet eindigen op _\d+
                    if ( ! preg_match( '/_\d+$/', $slug ) ) {
                        $issues[] = $base + [
                            'rule'   => 'missing_number',
                            'detail' => 'Slug eindigt niet op een volgnummer (_1, _2, …)',
                        ];
                        continue; // Context-check zinloos zonder volgnummer
                    }

                    // Regel 2: context-specifieke infix
                    $is_multi = ( $slug_usage_count[ $slug ] ?? 1 ) > 1;

                    if ( $context === 'option_page' && ! preg_match( '/(^opt_|_opt_)/', $slug ) ) {
                        $issues[] = $base + [
                            'rule'   => 'wrong_opt_format',
                            'detail' => 'Option page veld verwacht opt_ prefix of _opt_ infix — bijv. opt_socials_1 of recipient_opt_forms_1',
                        ];
                    } elseif ( $context === 'cpt' && ! preg_match( '/_cpt_[a-z0-9_]+_\d+$/', $slug ) ) {
                        $rule = $is_multi ? 'wrong_cpt_format_multi' : 'wrong_cpt_format';
                        $issues[] = $base + [
                            'rule'   => $rule,
                            'detail' => $is_multi
                                ? 'Cross-context veld — slug in ' . ( $slug_usage_count[ $slug ] ?? 1 ) . ' field groups; post-type indicatie niet vereist'
                                : 'CPT veld verwacht {naam}_cpt_{cpt}_{n} — ontbreekt _cpt_ infix',
                        ];
                    } elseif ( $context === 'page' && ! preg_match( '/_p_[a-z0-9_]+_\d+$/', $slug ) ) {
                        $rule = $is_multi ? 'wrong_page_format_multi' : 'wrong_page_format';
                        $issues[] = $base + [
                            'rule'   => $rule,
                            'detail' => $is_multi
                                ? 'Cross-context veld — slug in ' . ( $slug_usage_count[ $slug ] ?? 1 ) . ' field groups; post-type indicatie niet vereist'
                                : 'Paginaveld verwacht {naam}_p_{fieldgroup}_{n} — ontbreekt _p_ infix',
                        ];
                    }
                }
            }

            $response = [
                'status'         => empty( $issues ) ? 'ok' : 'issues_found',
                'groups_scanned' => $groups_scanned,
                'fields_scanned' => $fields_scanned,
                'issue_count'    => count( $issues ),
            ];

            if ( ! empty( $issues ) ) {
                $response['issues'] = $issues;
            }

            return $response;
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/acf/validate/locations
     * Valideert locatieregels van ACF field groups.
     * Detecteert verwijderde taxonomy-terms, ongeregistreerde taxonomieën,
     * lege terms en volledig redundante field groups.
     */
    register_rest_route( 'aspera/v1', '/acf/validate/locations', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            $groups = get_posts( [
                'post_type'      => 'acf-field-group',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            ] );

            $violations = [];
            $orphaned_group_ids = [];

            foreach ( $groups as $group ) {
                $content = maybe_unserialize( $group->post_content );
                if ( ! is_array( $content ) || empty( $content['location'] ) ) continue;

                foreach ( $content['location'] as $rule_group ) {
                    foreach ( $rule_group as $rule ) {
                        if ( ( $rule['param'] ?? '' ) !== 'post_taxonomy' ) continue;
                        if ( ( $rule['operator'] ?? '' ) !== '==' ) continue;

                        $parts = explode( ':', $rule['value'] ?? '', 2 );
                        if ( count( $parts ) !== 2 ) continue;

                        $taxonomy  = $parts[0];
                        $term_slug = $parts[1];

                        if ( ! taxonomy_exists( $taxonomy ) ) {
                            $violations[] = [
                                'rule'     => 'orphaned_location_taxonomy',
                                'severity' => 'error',
                                'post_id'  => $group->ID,
                                'detail'   => '"' . $group->post_title . '" — gekoppeld aan verwijderde taxonomy "' . $taxonomy . '"; field group is ongebruikt',
                                'proposed_fix' => [
                                    'fixable'   => true,
                                    'action'    => 'delete_field_group',
                                    'post_id'   => $group->ID,
                                    'title'     => $group->post_title,
                                ],
                            ];
                            $orphaned_group_ids[] = $group->ID;
                            continue;
                        }

                        $term = get_term_by( 'slug', $term_slug, $taxonomy );
                        if ( ! $term || is_wp_error( $term ) ) {
                            $violations[] = [
                                'rule'     => 'orphaned_location_term',
                                'severity' => 'warning',
                                'post_id'  => $group->ID,
                                'detail'   => '"' . $group->post_title . '" — gekoppeld aan verwijderde term "' . $term_slug . '" in taxonomy "' . $taxonomy . '"; field group is ongebruikt',
                                'proposed_fix' => [
                                    'fixable'   => true,
                                    'action'    => 'delete_field_group',
                                    'post_id'   => $group->ID,
                                    'title'     => $group->post_title,
                                ],
                            ];
                            $orphaned_group_ids[] = $group->ID;
                            continue;
                        }

                        if ( (int) $term->count === 0 ) {
                            $violations[] = [
                                'rule'     => 'empty_location_term',
                                'severity' => 'observation',
                                'post_id'  => $group->ID,
                                'detail'   => '"' . $group->post_title . '" — term "' . $term_slug . '" in "' . $taxonomy . '" heeft geen gekoppelde posts',
                            ];
                            $orphaned_group_ids[] = $group->ID;
                        }
                    }
                }
            }

            // Redundantie-check: zijn alle velden van orphaned groups gedekt door andere groups?
            $orphaned_group_ids = array_unique( $orphaned_group_ids );
            if ( ! empty( $orphaned_group_ids ) ) {
                $active_group_ids = array_diff(
                    wp_list_pluck( $groups, 'ID' ),
                    $orphaned_group_ids
                );

                // Bouw slug-map van actieve groups
                $active_slugs = [];
                foreach ( $active_group_ids as $gid ) {
                    $fields = get_posts( [
                        'post_type'      => 'acf-field',
                        'post_status'    => 'publish',
                        'post_parent'    => (int) $gid,
                        'posts_per_page' => -1,
                    ] );
                    foreach ( $fields as $f ) {
                        if ( $f->post_excerpt === '' ) continue;
                        $active_slugs[ $f->post_excerpt ] = (int) $gid;
                    }
                }

                // Check per orphaned group
                foreach ( $orphaned_group_ids as $oid ) {
                    $fields = get_posts( [
                        'post_type'      => 'acf-field',
                        'post_status'    => 'publish',
                        'post_parent'    => (int) $oid,
                        'posts_per_page' => -1,
                    ] );
                    if ( empty( $fields ) ) continue;

                    $all_covered   = true;
                    $covered_by    = [];
                    $orphan_slugs  = [];
                    foreach ( $fields as $f ) {
                        if ( $f->post_excerpt === '' ) continue;
                        $orphan_slugs[] = $f->post_excerpt;
                        if ( isset( $active_slugs[ $f->post_excerpt ] ) ) {
                            $covered_by[ $active_slugs[ $f->post_excerpt ] ] = true;
                        } else {
                            $all_covered = false;
                        }
                    }

                    if ( ! empty( $orphan_slugs ) ) {
                        // Zoek de bestaande violation en voeg redundantie-info toe
                        foreach ( $violations as &$v ) {
                            if ( (int) ( $v['post_id'] ?? 0 ) !== (int) $oid ) continue;
                            $v['field_count']  = count( $orphan_slugs );
                            $v['redundant']    = $all_covered;
                            if ( $all_covered && ! empty( $covered_by ) ) {
                                $cover_titles = [];
                                foreach ( array_keys( $covered_by ) as $cid ) {
                                    $cover_titles[] = get_the_title( $cid ) . ' (ID ' . $cid . ')';
                                }
                                $v['covered_by'] = $cover_titles;
                            }
                            break;
                        }
                        unset( $v );
                    }
                }
            }

            return [
                'status'          => empty( $violations ) ? 'ok' : 'issues_found',
                'violations'      => $violations,
                'groups_scanned'  => count( $groups ),
                'orphaned_groups' => count( $orphaned_group_ids ),
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/site/passport
     * Geeft het site-paspoort terug. Genereert opnieuw als de stale-vlag gezet is.
     * Slaat het resultaat op in wp_options (autoload: no).
     */
    register_rest_route( 'aspera/v1', '/site/passport', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {
            $stale = get_option( 'aspera_passport_stale', '1' );
            if ( $stale === '1' ) {
                $passport = aspera_generate_passport();
                update_option( 'aspera_passport', wp_json_encode( $passport ), false );
                update_option( 'aspera_passport_stale', '0', false );
                return $passport;
            }
            $cached = get_option( 'aspera_passport', '' );
            if ( $cached ) {
                $decoded = json_decode( $cached, true );
                if ( is_array( $decoded ) ) return $decoded;
            }
            // Fallback: cache ontbreekt ondanks stale=0 — alsnog genereren
            $passport = aspera_generate_passport();
            update_option( 'aspera_passport', wp_json_encode( $passport ), false );
            return $passport;
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/site/passport/refresh
     * Forceert een volledige regeneratie van het paspoort, ongeacht de stale-vlag.
     */
    register_rest_route( 'aspera/v1', '/site/passport/refresh', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {
            $passport = aspera_generate_passport();
            update_option( 'aspera_passport', wp_json_encode( $passport ), false );
            update_option( 'aspera_passport_stale', '0', false );
            return $passport;
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/plugins/validate
     * Controleert of alle essentiële plugins aanwezig en actief zijn.
     * Rapporteert ontbrekende/inactieve essentiële plugins, extra plugins
     * en WooCommerce-specifieke vereisten.
     */
    register_rest_route( 'aspera/v1', '/plugins/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $all_plugins    = get_plugins();
            $active_plugins = (array) get_option( 'active_plugins', [] );

            // Slug uit plugin-bestandspad extraheren
            $installed = [];
            foreach ( $all_plugins as $file => $data ) {
                $slug              = strpos( $file, '/' ) !== false ? explode( '/', $file )[0] : str_replace( '.php', '', $file );
                $installed[ $slug ] = [
                    'name'    => $data['Name'],
                    'version' => $data['Version'],
                    'active'  => in_array( $file, $active_plugins, true ),
                    'file'    => $file,
                ];
            }

            // ─── Essentiële plugins ────────────────────────────────────────
            $essential = [
                'admin-menu-editor-pro'                  => 'Admin Menu Editor Pro',
                'advanced-custom-fields-pro'             => 'Advanced Custom Fields PRO',
                'ai-engine-pro'                          => 'AI Engine (Pro)',
                'mwai-content-parser'                    => 'Content Parser (AI Engine)',
                'all-in-one-wp-security-and-firewall'    => 'All-In-One Security (AIOS)',
                basename( dirname( __FILE__ ) )              => 'AsperAi Site Tools',
                'burst-pro'                              => 'Burst Pro',
                'webp-converter-for-media'               => 'Converter for Media',
                'disable-comments'                       => 'Disable Comments',
                'us-core'                                => 'UpSolution Core',
                'user-switching'                         => 'User Switching',
                'wp-fastest-cache'                       => 'WP Fastest Cache',
                'wp-mail-smtp'                           => 'WP Mail SMTP',
                'wp-optimize'                            => 'WP-Optimize - Clean, Compress, Cache',
                'js_composer'                            => 'WPBakery Page Builder',
                'redirection'                            => 'Redirection',
                'wpconsent-cookies-banner-privacy-suite' => 'WPConsent',
                'wordpress-seo'                          => 'Yoast SEO',
            ];

            // Whitelist: besproken plugins worden nooit geflagged (actief, inactief of afwezig)
            // Burst: gratis variant is ook acceptabel
            $whitelist_slugs   = array_keys( $essential );
            $whitelist_slugs[] = 'burst-statistics';

            $known_status = [];
            foreach ( $essential as $slug => $name ) {
                if ( $slug === 'burst-pro' ) {
                    $actual_slug = isset( $installed['burst-pro'] ) ? 'burst-pro'
                        : ( isset( $installed['burst-statistics'] ) ? 'burst-statistics' : null );
                    if ( $actual_slug ) {
                        $actual         = $installed[ $actual_slug ];
                        $known_status[] = [
                            'name'    => $actual['name'],
                            'slug'    => $actual_slug,
                            'version' => $actual['version'],
                            'status'  => $actual['active'] ? 'active' : 'inactive',
                        ];
                    } else {
                        $known_status[] = [ 'name' => $name, 'slug' => $slug, 'status' => 'not_installed' ];
                    }
                    continue;
                }

                if ( ! isset( $installed[ $slug ] ) ) {
                    $known_status[] = [ 'name' => $name, 'slug' => $slug, 'status' => 'not_installed' ];
                } else {
                    $p              = $installed[ $slug ];
                    $known_status[] = [
                        'name'    => $p['name'],
                        'slug'    => $slug,
                        'version' => $p['version'],
                        'status'  => $p['active'] ? 'active' : 'inactive',
                    ];
                }
            }

            // ─── Extra plugins (niet op de whitelist) ─────────────────────────
            $woo_slugs  = [ 'woocommerce', 'mollie-payments-for-woocommerce', 'woocommerce-pdf-invoices-packing-slips' ];
            $skip_slugs = array_merge( $whitelist_slugs, $woo_slugs );

            $extra = [];
            foreach ( $installed as $slug => $p ) {
                if ( in_array( $slug, $skip_slugs, true ) ) continue;
                $extra[] = [
                    'name'    => $p['name'],
                    'slug'    => $slug,
                    'version' => $p['version'],
                    'active'  => $p['active'],
                ];
            }

            // ─── WooCommerce ───────────────────────────────────────────────
            $woocommerce = null;
            if ( isset( $installed['woocommerce'] ) ) {
                $woo_required = [
                    'mollie-payments-for-woocommerce'          => 'Mollie Payments for WooCommerce',
                    'woocommerce-pdf-invoices-packing-slips'   => 'PDF Invoices & Packing Slips for WooCommerce',
                ];
                $woo_status = [];
                foreach ( $woo_required as $slug => $name ) {
                    if ( ! isset( $installed[ $slug ] ) ) {
                        $woo_status[] = [ 'name' => $name, 'slug' => $slug, 'status' => 'missing' ];
                    } else {
                        $p            = $installed[ $slug ];
                        $woo_status[] = [
                            'name'    => $p['name'],
                            'slug'    => $slug,
                            'version' => $p['version'],
                            'status'  => $p['active'] ? 'active' : 'inactive',
                        ];
                    }
                }
                $woocommerce = [
                    'version'          => $installed['woocommerce']['version'],
                    'active'           => $installed['woocommerce']['active'],
                    'required_plugins' => $woo_status,
                ];
            }

            $response = [
                'status'          => empty( $extra ) ? 'ok' : 'extra_plugins_found',
                'known_plugins'   => $known_status,
                'extra_plugins'   => $extra,
            ];

            if ( $woocommerce !== null ) {
                $response['woocommerce'] = $woocommerce;
            }

            return $response;
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/meta/validate
     * Detecteert verweesde ACF meta_key rijen in wp_postmeta.
     *
     * Scope: alleen meta_keys waarvan de bijbehorende _-referentie het patroon
     * 'field_*' volgt — dit garandeert ACF-herkomst. Meta van WPBakery, Impreza,
     * WooCommerce en andere plugins valt volledig buiten scope.
     *
     * Output per orphaned key:
     * - meta_key     : de veldslug
     * - field_key    : de ACF field key (field_xxx) die niet meer actief is
     * - rows         : aantal rijen in wp_postmeta
     * - in_templates : aanwezig in post_content/post_excerpt van template post types
     * - advies       : 'verwijderen na akkoord' of 'onderzoek vereist'
     */
    register_rest_route( 'aspera/v1', '/meta/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;

            // 1. Actieve ACF field keys ophalen uit post_name van acf-field posts
            //    (post_name = field key zoals field_62c1766c295ed;
            //     post_excerpt = veldslug zoals bu_cpt_links_1)
            $active_field_keys = $wpdb->get_col(
                "SELECT post_name
                 FROM {$wpdb->posts}
                 WHERE post_type = 'acf-field'
                   AND post_status = 'publish'
                   AND post_name LIKE 'field_%'"
            );
            $active_field_keys = array_values( array_filter( $active_field_keys ) );

            // 2. Alle meta_keys met ACF-herkomst ophalen:
            //    - niet _ prefixed
            //    - hebben een corresponderende _meta_key met waarde 'field_*'
            $rows = $wpdb->get_results(
                "SELECT DISTINCT pm1.meta_key, pm2.meta_value AS field_key
                 FROM {$wpdb->postmeta} pm1
                 INNER JOIN {$wpdb->postmeta} pm2
                     ON pm2.post_id    = pm1.post_id
                     AND pm2.meta_key  = CONCAT('_', pm1.meta_key)
                 WHERE pm1.meta_key NOT LIKE '\_%'
                   AND pm2.meta_value  LIKE 'field_%'
                 ORDER BY pm1.meta_key",
                ARRAY_A
            );

            // 3. Per key bepalen: actief ACF-veld of orphaned
            $orphaned       = [];
            $valid_count    = 0;
            $template_types = [
                'us_content_template', 'us_page_block',
                'us_grid_layout',      'us_header',
            ];

            foreach ( $rows as $row ) {
                $key       = $row['meta_key'];
                $field_key = $row['field_key'];

                // Actief ACF-veld → overslaan
                if ( in_array( $field_key, $active_field_keys, true ) ) {
                    $valid_count++;
                    continue;
                }

                // Orphaned: field_key bestaat niet meer in actieve ACF velden
                $row_count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
                    $key
                ) );

                // Check aanwezigheid in template post_content / post_excerpt
                $in_templates = false;
                foreach ( $template_types as $type ) {
                    $like  = '%' . $wpdb->esc_like( $key ) . '%';
                    $found = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->posts}
                         WHERE post_type   = %s
                           AND post_status = 'publish'
                           AND ( post_content LIKE %s OR post_excerpt LIKE %s )",
                        $type, $like, $like
                    ) );
                    if ( $found > 0 ) {
                        $in_templates = true;
                        break;
                    }
                }

                $orphaned[] = [
                    'meta_key'     => $key,
                    'field_key'    => $field_key,
                    'rows'         => $row_count,
                    'in_templates' => $in_templates,
                    'advies'       => $in_templates
                        ? 'onderzoek vereist — key gevonden in templates'
                        : 'verwijderen na akkoord',
                ];
            }

            return [
                'status'   => empty( $orphaned ) ? 'ok' : 'issues_found',
                'orphaned' => $orphaned,
                'summary'  => [
                    'orphaned_keys'    => count( $orphaned ),
                    'valid_keys'       => $valid_count,
                    'total_acf_fields' => count( $active_field_keys ),
                ],
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/options/validate
     * Detecteert verweesde ACF option page data in wp_options.
     *
     * Vergelijkt _options_opt_* referenties met actieve ACF field keys.
     * Keys waarvan de field_key niet meer bestaat zijn orphaned.
     * Groepeert per option page prefix en toont of de option page zelf nog actief is.
     */
    register_rest_route( 'aspera/v1', '/options/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;

            // 1. Actieve ACF field keys
            $active_field_keys = $wpdb->get_col(
                "SELECT post_name FROM {$wpdb->posts}
                 WHERE post_type = 'acf-field'
                   AND post_status = 'publish'
                   AND post_name LIKE 'field_%'"
            );
            $active_field_keys = array_values( array_filter( $active_field_keys ) );

            // 2. Actieve option page slugs (uit acf-ui-options-page posts)
            $active_slugs  = [];
            $option_pages  = $wpdb->get_results(
                "SELECT post_content FROM {$wpdb->posts}
                 WHERE post_type = 'acf-ui-options-page'
                   AND post_status = 'publish'"
            );
            foreach ( $option_pages as $op ) {
                $data = maybe_unserialize( $op->post_content );
                if ( is_array( $data ) && ! empty( $data['menu_slug'] ) ) {
                    $active_slugs[] = $data['menu_slug'];
                }
            }

            // 3. Alle _options_opt_* referenties met field_* waarden
            $refs = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options}
                 WHERE option_name LIKE '\\_options\\_opt\\_%'
                   AND option_value LIKE 'field_%'
                 ORDER BY option_name",
                ARRAY_A
            );

            // 4. Per referentie: actief of orphaned
            $orphaned    = [];
            $valid_count = 0;

            foreach ( $refs as $ref ) {
                $field_key  = $ref['option_value'];
                $value_name = substr( $ref['option_name'], 1 ); // strip leading _

                if ( in_array( $field_key, $active_field_keys, true ) ) {
                    $valid_count++;
                    continue;
                }

                // Prefix extraheren: options_opt_faq_2_0_text → opt_faq
                $bare   = preg_replace( '/^options_/', '', $value_name );
                $prefix = 'unknown';
                if ( preg_match( '/^(opt_[a-z]+)/', $bare, $m ) ) {
                    $prefix = $m[1];
                }

                $orphaned[] = [
                    'option_name' => $value_name,
                    'field_key'   => $field_key,
                    'prefix'      => $prefix,
                ];
            }

            // 5. Groeperen per prefix
            $groups = [];
            foreach ( $orphaned as $o ) {
                $pfx = $o['prefix'];
                if ( ! isset( $groups[ $pfx ] ) ) {
                    $groups[ $pfx ] = [
                        'prefix'             => $pfx,
                        'option_page_active' => in_array( $pfx, $active_slugs, true ),
                        'keys'               => 0,
                        'total_rows'         => 0,
                    ];
                }
                $groups[ $pfx ]['keys']++;
                $groups[ $pfx ]['total_rows'] += 2; // waarde + _referentie
            }

            return [
                'status'   => empty( $orphaned ) ? 'ok' : 'issues_found',
                'orphaned' => array_values( $groups ),
                'detail'   => $orphaned,
                'summary'  => [
                    'orphaned_keys'       => count( $orphaned ),
                    'orphaned_rows'       => count( $orphaned ) * 2,
                    'valid_keys'          => $valid_count,
                    'active_option_pages' => $active_slugs,
                ],
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/options/config/validate
     * Valideert ACF option page configuratie: menu_slug, position, menu_icon.
     */
    register_rest_route( 'aspera/v1', '/options/config/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;

            $violations  = [];
            $pages       = [];

            $option_pages = $wpdb->get_results(
                "SELECT ID, post_title, post_content FROM {$wpdb->posts}
                 WHERE post_type = 'acf-ui-options-page'
                   AND post_status = 'publish'
                 ORDER BY post_title"
            );

            // Expected icons per type keyword
            $icon_map = [
                'header'     => 'dashicons-table-row-before',
                'footer'     => 'dashicons-table-row-after',
                'widget'     => 'dashicons-table-col-before',
                'formulier'  => 'dashicons-email',
                'forms'      => 'dashicons-email',
                'social'     => 'dashicons-share',
                'socials'    => 'dashicons-share',
            ];

            foreach ( $option_pages as $op ) {
                $data = maybe_unserialize( $op->post_content );
                if ( ! is_array( $data ) ) continue;

                $slug     = $data['menu_slug'] ?? '';
                $position = $data['position'] ?? null;
                $icon     = '';

                // Extract icon from nested structure or string
                if ( isset( $data['menu_icon'] ) ) {
                    if ( is_array( $data['menu_icon'] ) ) {
                        $icon = $data['menu_icon']['value'] ?? '';
                    } else {
                        $icon = $data['menu_icon'];
                    }
                }

                $page_info = [
                    'post_id'   => (int) $op->ID,
                    'title'     => $op->post_title,
                    'menu_slug' => $slug,
                    'position'  => $position,
                    'icon'      => $icon,
                ];
                $pages[] = $page_info;

                // Check 1: menu_slug must start with opt_
                if ( $slug && strpos( $slug, 'opt_' ) !== 0 ) {
                    $violations[] = [
                        'rule'     => 'wrong_option_slug',
                        'severity' => 'warning',
                        'post_id'  => (int) $op->ID,
                        'detail'   => '"' . $op->post_title . '" — menu_slug "' . $slug . '" begint niet met "opt_"',
                    ];
                }

                // Check 2: position must be 20
                if ( $position !== null && (int) $position !== 20 ) {
                    $violations[] = [
                        'rule'     => 'wrong_option_position',
                        'severity' => 'warning',
                        'post_id'  => (int) $op->ID,
                        'detail'   => '"' . $op->post_title . '" — position is ' . $position . ', verwacht 20',
                    ];
                }

                // Check 3: icon must match expected per type
                $slug_lower = strtolower( $slug );
                foreach ( $icon_map as $keyword => $expected_icon ) {
                    if ( strpos( $slug_lower, $keyword ) !== false ) {
                        if ( $icon && $icon !== $expected_icon ) {
                            $violations[] = [
                                'rule'     => 'wrong_option_icon',
                                'severity' => 'warning',
                                'post_id'  => (int) $op->ID,
                                'detail'   => '"' . $op->post_title . '" — icoon is "' . $icon . '", verwacht "' . $expected_icon . '" (type: ' . $keyword . ')',
                            ];
                        }
                        break;
                    }
                }
            }

            return [
                'status'     => empty( $violations ) ? 'ok' : 'issues_found',
                'violations' => $violations,
                'pages'      => $pages,
                'summary'    => [
                    'pages_checked' => count( $option_pages ),
                    'violations'    => count( $violations ),
                ],
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/db/tables/validate
     * Detecteert databasetabellen van plugins die niet meer actief zijn.
     *
     * Bekende plugin-patronen:
     * - wpforms_*        → WPForms
     * - revslider_*      → Revolution Slider
     * - cf7dbplugin_*    → CF7 Database Plugin
     *
     * Onbekende tabellen (niet WordPress core, niet actieve plugin) worden
     * apart gerapporteerd voor analyse door de gebruiker.
     */
    register_rest_route( 'aspera/v1', '/db/tables/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            global $wpdb;

            // Bekende plugin-tabelpatronen (orphaned — plugin niet in standaardinstallatie)
            $known_patterns = [
                'wpforms'     => [ 'plugin' => 'WPForms',            'slug' => 'wpforms-lite' ],
                'revslider'   => [ 'plugin' => 'Revolution Slider',   'slug' => 'revslider' ],
                'cf7dbplugin' => [ 'plugin' => 'CF7 Database Plugin', 'slug' => 'contact-form-7-to-database-extension' ],
                'cky'         => [ 'plugin' => 'CookieYes',           'slug' => 'cookieyes-cookie-consent' ],
                'cli'         => [ 'plugin' => 'Cookie Law Info',     'slug' => 'cookie-law-info' ],
                'tribe'       => [ 'plugin' => 'The Events Calendar',              'slug' => 'the-events-calendar' ],
                'mollie'      => [ 'plugin' => 'Mollie Payments for WooCommerce',  'slug' => 'mollie-payments-for-woocommerce' ],
                'wcpdf'       => [ 'plugin' => 'PDF Invoices & Packing Slips',     'slug' => 'woocommerce-pdf-invoices-packing-slips' ],
                'spu'         => [ 'plugin' => 'Popup by Supsystic',               'slug' => 'popup-by-supsystic' ],
                'wppopups'    => [ 'plugin' => 'WP Popups',                        'slug' => 'wp-popups-lite' ],
                // installed_only: true — alleen flaggen als plugin volledig niet geïnstalleerd is (actief én inactief zijn OK)
                'wpconsent'   => [ 'plugin' => 'WPConsent', 'slug' => 'wpconsent-cookies-banner-privacy-suite', 'installed_only' => true ],
            ];

            // Tabelpatronen van bekende essentiële of veelgebruikte plugins — niet flaggen
            $essentials_patterns = [
                'aiowps_', 'burst_', 'wpmailsmtp_', 'wpo_',
                'yoast_', 'redirection_', 'mwai_', 'tm_',
                'actionscheduler_', 'us_filter_',
                'wc_', // WooCommerce HPOS + analytics tabellen
            ];

            // WordPress core tabellen (zonder prefix)
            $core_tables = [
                'commentmeta', 'comments', 'links', 'options', 'postmeta',
                'posts', 'term_relationships', 'term_taxonomy', 'termmeta', 'terms',
                'usermeta', 'users', 'blogs', 'blog_versions', 'registration_log',
                'signups', 'site', 'sitemeta', 'acf_meta',
                // WooCommerce core
                'woocommerce_sessions', 'woocommerce_api_keys', 'woocommerce_attribute_taxonomies',
                'woocommerce_downloadable_product_permissions', 'woocommerce_order_items',
                'woocommerce_order_itemmeta', 'woocommerce_tax_rates', 'woocommerce_tax_rate_locations',
                'woocommerce_shipping_zones', 'woocommerce_shipping_zone_locations',
                'woocommerce_shipping_zone_methods', 'woocommerce_payment_tokens',
                'woocommerce_payment_tokenmeta', 'woocommerce_log',
            ];

            // Actieve en geïnstalleerde plugin slugs ophalen
            $plugin_slugs    = aspera_get_plugin_slugs();
            $active_slugs    = $plugin_slugs['active'];
            $installed_slugs = $plugin_slugs['installed'];

            $prefix   = $wpdb->prefix;
            $tables   = $wpdb->get_col( 'SHOW TABLES' );
            $orphaned = [];
            $unknown  = [];

            foreach ( $tables as $table ) {
                if ( strpos( $table, $prefix ) !== 0 ) continue;

                $bare = substr( $table, strlen( $prefix ) );

                if ( in_array( $bare, $core_tables, true ) ) continue;

                // Essentiële plugin-tabel? Overslaan.
                $is_essential = false;
                foreach ( $essentials_patterns as $ep ) {
                    if ( strpos( $bare, $ep ) === 0 ) { $is_essential = true; break; }
                }
                if ( $is_essential ) continue;

                $matched = false;
                foreach ( $known_patterns as $pattern => $info ) {
                    if ( strpos( $bare, $pattern ) === 0 ) {
                        $matched      = true;
                        $check_slugs  = ! empty( $info['installed_only'] ) ? $installed_slugs : $active_slugs;
                        if ( ! in_array( $info['slug'], $check_slugs, true ) ) {
                            $orphaned[] = [
                                'table'         => $table,
                                'pattern'       => $pattern . '_*',
                                'plugin'        => $info['plugin'],
                                'plugin_active' => false,
                                'advies'        => 'verwijderen na akkoord',
                            ];
                        }
                        break;
                    }
                }

                if ( ! $matched ) {
                    $unknown[] = [
                        'table'  => $table,
                        'advies' => 'analyseer herkomst en vraag wat te doen',
                    ];
                }
            }

            // ── Bekende plugin post types — orphaned wanneer plugin inactief ──
            $known_post_types = [
                'wppopups'          => [ 'plugin' => 'WP Popups',              'slug' => 'wp-popups-lite' ],
                'popup'             => [ 'plugin' => 'Popup Maker',             'slug' => 'popup-maker' ],
                'spu'               => [ 'plugin' => 'Popup by Supsystic',      'slug' => 'popup-by-supsystic' ],
                'cookielawinfo'     => [ 'plugin' => 'Cookie Law Info',         'slug' => 'cookie-law-info' ],
                'shortcoder'        => [ 'plugin' => 'Shortcoder',              'slug' => 'shortcoder' ],
                'wpcode'            => [ 'plugin' => 'WPCode',                  'slug' => 'insert-headers-and-footers' ],
                'wpforms'           => [ 'plugin' => 'WPForms',                 'slug' => 'wpforms-lite' ],
                'tribe_events'      => [ 'plugin' => 'The Events Calendar',     'slug' => 'the-events-calendar' ],
                'elementor_library' => [ 'plugin' => 'Elementor',               'slug' => 'elementor' ],
            ];

            // Post types die altijd veilig zijn — nooit flaggen
            $safe_post_types = [
                'shop_order_placehold', // WooCommerce HPOS legacy placeholders
                'wpconsent_cookie',     // WPConsent cookie-definities
            ];

            $orphaned_post_types = [];
            foreach ( $known_post_types as $post_type => $info ) {
                if ( in_array( $post_type, $safe_post_types, true ) ) continue;
                if ( in_array( $info['slug'], $active_slugs, true ) ) continue;
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                    $post_type
                ) );
                if ( $count > 0 ) {
                    $orphaned_post_types[] = [
                        'post_type'     => $post_type,
                        'plugin'        => $info['plugin'],
                        'plugin_active' => false,
                        'count'         => $count,
                        'advies'        => 'verwijderen na akkoord',
                    ];
                }
            }

            // ── Bekende plugin option-prefixen — orphaned wanneer plugin inactief ──
            $known_option_prefixes = [
                'wppopups'      => [ 'plugin' => 'WP Popups',           'slug' => 'wp-popups-lite' ],
                'spu_'          => [ 'plugin' => 'Popup by Supsystic',  'slug' => 'popup-by-supsystic' ],
                'cookielawinfo_'=> [ 'plugin' => 'Cookie Law Info',      'slug' => 'cookie-law-info' ],
                'shortcoder_'   => [ 'plugin' => 'Shortcoder',           'slug' => 'shortcoder' ],
                'wpcode_'       => [ 'plugin' => 'WPCode',               'slug' => 'insert-headers-and-footers' ],
                'wpforms_'      => [ 'plugin' => 'WPForms',              'slug' => 'wpforms-lite' ],
                'tribe_'        => [ 'plugin' => 'The Events Calendar',  'slug' => 'the-events-calendar' ],
                'popup_maker_'  => [ 'plugin' => 'Popup Maker',          'slug' => 'popup-maker' ],
                'pys_'          => [ 'plugin' => 'PixelYourSite',       'slug' => 'pixelyoursite-pro' ],
            ];

            $orphaned_options = [];
            foreach ( $known_option_prefixes as $prefix => $info ) {
                if ( in_array( $info['slug'], $active_slugs, true ) ) continue;
                $like  = $wpdb->esc_like( $prefix ) . '%';
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like
                ) );
                if ( $count > 0 ) {
                    $examples = $wpdb->get_col( $wpdb->prepare(
                        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 5", $like
                    ) );
                    $orphaned_options[] = [
                        'prefix'        => $prefix,
                        'plugin'        => $info['plugin'],
                        'plugin_active' => false,
                        'count'         => $count,
                        'examples'      => $examples,
                        'advies'        => 'verwijderen na akkoord',
                    ];
                }
            }

            // ── Bekende plugin postmeta-prefixen — orphaned wanneer plugin inactief ──
            $known_meta_prefixes = [
                '_pys_'         => [ 'plugin' => 'PixelYourSite',       'slug' => 'pixelyoursite-pro' ],
            ];

            $orphaned_meta = [];
            foreach ( $known_meta_prefixes as $meta_prefix => $info ) {
                if ( in_array( $info['slug'], $active_slugs, true ) ) continue;
                $like  = $wpdb->esc_like( $meta_prefix ) . '%';
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like
                ) );
                if ( $count > 0 ) {
                    $examples = $wpdb->get_col( $wpdb->prepare(
                        "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 5", $like
                    ) );
                    $orphaned_meta[] = [
                        'prefix'        => $meta_prefix,
                        'plugin'        => $info['plugin'],
                        'plugin_active' => false,
                        'count'         => $count,
                        'examples'      => $examples,
                        'advies'        => 'verwijderen na akkoord',
                    ];
                }
            }

            return [
                'status'              => empty( $orphaned ) && empty( $unknown ) && empty( $orphaned_post_types ) && empty( $orphaned_options ) && empty( $orphaned_meta ) ? 'ok' : 'issues_found',
                'orphaned_tables'     => $orphaned,
                'unknown_tables'      => $unknown,
                'orphaned_post_types' => $orphaned_post_types,
                'orphaned_options'    => $orphaned_options,
                'orphaned_meta'       => $orphaned_meta,
                'summary'             => [
                    'orphaned_tables'     => count( $orphaned ),
                    'unknown_tables'      => count( $unknown ),
                    'orphaned_post_types' => count( $orphaned_post_types ),
                    'orphaned_options'    => count( $orphaned_options ),
                    'orphaned_meta'       => count( $orphaned_meta ),
                ],
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/forms/validate
     * Valideert alle us_cform shortcodes op beleidsschendingen.
     * Zoekt in alle gepubliceerde post types (geen revisies).
     *
     * Gecontroleerde regels:
     * - cform_inbound_disabled (site-wide: theme option uit terwijl formulieren actief)
     * - missing_receiver_email / hardcoded_receiver_email
     * - missing_button_text / hardcoded_button_text / empty_button_style
     * - missing_success_message / hardcoded_success_message
     * - missing_email_subject
     * - missing_email_message / missing_field_list
     * - missing_recaptcha
     * - missing_email_field / wrong_email_field_type
     * - missing_move_label
     *
     * Observaties (geen schending, altijd gerapporteerd):
     * - hide_form_after_sending (aan/uit)
     * - fields (label, type, required per veld)
     * - missing_recommended_fields (naam, email, onderwerp, bericht)
     */
    register_rest_route( 'aspera/v1', '/forms/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            global $wpdb;

            $posts = $wpdb->get_results(
                "SELECT ID, post_type, post_title, post_content
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                 AND post_type != 'revision'
                 AND post_content LIKE '%[us_cform%'"
            );

            // ─── Site-wide: us_cform_inbound actief? ──────────────────────
            $cform_inbound_active = post_type_exists( 'us_cform_inbound' );
            $theme_opts           = get_option( 'usof_options_Impreza', [] );
            $cform_inbound_option = ! empty( $theme_opts['cform_inbound_messages'] );

            if ( empty( $posts ) ) {
                return [
                    'cform_inbound_active' => $cform_inbound_active,
                    'forms_found'          => 0,
                    'forms_with_issues'    => 0,
                    'forms'                => [],
                ];
            }

            $results = [];

            foreach ( $posts as $post ) {

                if ( ! preg_match( '/\[us_cform((?:"[^"]*"|\'[^\']*\'|[^\]])*)\]/', $post->post_content, $m ) ) {
                    continue;
                }

                // ─── Attribuut parsing ──────────────────────────────────────
                $attrs = [];
                preg_match_all( '/(\w+)="([^"]*)"/', $m[1], $attr_matches, PREG_SET_ORDER );
                foreach ( $attr_matches as $a ) {
                    $attrs[ $a[1] ] = $a[2];
                }

                $violations   = [];
                $observations = [];

                // ─── Items decoderen (URL-encoded JSON) ────────────────────
                $fields = [];
                if ( ! empty( $attrs['items'] ) ) {
                    $decoded = json_decode( urldecode( $attrs['items'] ), true );
                    if ( is_array( $decoded ) ) {
                        $fields = $decoded;
                    }
                }

                // ─── success_message decoderen (base64 → urldecode) ────────
                $success_decoded = '';
                if ( ! empty( $attrs['success_message'] ) ) {
                    $success_decoded = urldecode( base64_decode( $attrs['success_message'] ) );
                }

                // ─── email_message decoderen (base64 → urldecode) ──────────
                $email_message_decoded = '';
                if ( ! empty( $attrs['email_message'] ) ) {
                    $email_message_decoded = urldecode( base64_decode( $attrs['email_message'] ) );
                }

                // ─── receiver_email ────────────────────────────────────────
                $receiver = $attrs['receiver_email'] ?? '';
                if ( $receiver === '' ) {
                    $violations[] = [ 'rule' => 'missing_receiver_email', 'detail' => 'receiver_email ontbreekt' ];
                } elseif ( ! preg_match( '/^\{\{option\/recipient_opt_/', $receiver ) ) {
                    $violations[] = [ 'rule' => 'hardcoded_receiver_email', 'detail' => 'receiver_email is niet via option page: "' . $receiver . '"' ];
                }

                // ─── button_text ───────────────────────────────────────────
                $button_text = $attrs['button_text'] ?? '';
                if ( $button_text === '' ) {
                    $violations[] = [ 'rule' => 'missing_button_text', 'detail' => 'button_text ontbreekt' ];
                } elseif ( ! preg_match( '/^\{\{option\/bl_opt_/', $button_text ) ) {
                    $violations[] = [ 'rule' => 'hardcoded_button_text', 'detail' => 'button_text is niet via option page: "' . $button_text . '"' ];
                }

                // ─── empty_button_style: button_style="" aanwezig ──────────
                if ( isset( $attrs['button_style'] ) && $attrs['button_style'] === '' ) {
                    $violations[] = [ 'rule' => 'empty_button_style', 'detail' => 'button_style="" — submit-button stijl was ingesteld maar het stijlobject bestaat niet meer in Impreza' ];
                }

                // ─── success_message ───────────────────────────────────────
                if ( empty( $attrs['success_message'] ) ) {
                    $violations[] = [ 'rule' => 'missing_success_message', 'detail' => 'success_message ontbreekt' ];
                } elseif ( ! preg_match( '/\{\{option\/submittext_opt_/', $success_decoded ) ) {
                    $violations[] = [ 'rule' => 'hardcoded_success_message', 'detail' => 'success_message verwijst niet naar option page veld (gedecodeerd: "' . $success_decoded . '")' ];
                }

                // ─── email_subject ─────────────────────────────────────────
                if ( empty( $attrs['email_subject'] ) ) {
                    $violations[] = [ 'rule' => 'missing_email_subject', 'detail' => 'email_subject ontbreekt' ];
                }

                // ─── email_message / field_list ────────────────────────────
                if ( empty( $attrs['email_message'] ) ) {
                    $violations[] = [ 'rule' => 'missing_email_message', 'detail' => 'email_message ontbreekt' ];
                } elseif ( strpos( $email_message_decoded, '[field_list]' ) === false ) {
                    $violations[] = [ 'rule' => 'missing_field_list', 'detail' => 'email_message bevat geen [field_list] (gedecodeerd: "' . $email_message_decoded . '")' ];
                }

                // ─── Veld-level checks ─────────────────────────────────────
                $has_recaptcha  = false;
                $has_email_type = false;
                $field_list     = [];

                foreach ( $fields as $field ) {
                    $type        = $field['type'] ?? '';
                    $label       = $field['label'] ?? '';
                    $required    = isset( $field['required'] ) && $field['required'] === '1';
                    $placeholder = $field['placeholder'] ?? '';
                    $move_label  = $field['move_label'] ?? '0';

                    if ( $type === 'reCAPTCHA' ) {
                        $has_recaptcha = true;
                        $field_list[]  = [ 'label' => 'reCAPTCHA', 'type' => 'reCAPTCHA', 'required' => false ];
                        continue;
                    }

                    if ( $type === 'email' ) {
                        $has_email_type = true;
                    }

                    // E-mailveld met verkeerd type
                    if ( $label !== '' && stripos( $label, 'email' ) !== false && $type !== 'email' ) {
                        $violations[] = [ 'rule' => 'wrong_email_field_type', 'detail' => 'Veld "' . $label . '" lijkt een e-mailveld maar heeft type "' . $type . '" in plaats van "email"' ];
                    }

                    // Placeholder zonder move_label
                    if ( $placeholder !== '' && $move_label !== '1' ) {
                        $violations[] = [ 'rule' => 'missing_move_label', 'detail' => 'Veld "' . $label . '" heeft een placeholder maar move_label is niet ingeschakeld' ];
                    }

                    $field_list[] = [
                        'label'    => $label ?: $type,
                        'type'     => $type,
                        'required' => $required,
                    ];
                }

                if ( ! $has_recaptcha ) {
                    $violations[] = [ 'rule' => 'missing_recaptcha', 'detail' => 'Geen reCAPTCHA veld aanwezig' ];
                }

                if ( ! $has_email_type ) {
                    $violations[] = [ 'rule' => 'missing_email_field', 'detail' => 'Geen veld met type "email" aanwezig' ];
                }

                // ─── Option page veldwaarden ophalen en controleren ────────
                $option_values = [];
                foreach ( [
                    'receiver_email' => $attrs['receiver_email'] ?? '',
                    'button_text'    => $attrs['button_text'] ?? '',
                    'success_message'=> $success_decoded,
                ] as $attr_key => $ref ) {
                    // Extraheer slug uit {{option/slug}}
                    if ( preg_match( '/^\{\{option\/(.+?)\}\}$/', $ref, $slug_match ) ) {
                        $slug  = $slug_match[1];
                        $value = get_option( 'options_' . $slug, null );
                        $option_values[ $slug ] = $value;
                        if ( $value === null || $value === '' ) {
                            $violations[] = [
                                'rule'   => 'empty_option_field',
                                'detail' => 'Option page veld "' . $slug . '" is leeg — formulier functioneert niet correct',
                            ];
                        }
                    }
                }

                // ─── Observaties ───────────────────────────────────────────
                $observations['hide_form_after_sending'] = isset( $attrs['hide_form_after_sending'] ) && $attrs['hide_form_after_sending'] === '1';
                $observations['option_values']           = $option_values;
                $observations['fields']                  = $field_list;

                // Aanbevolen velden (contactformulier minimum)
                $labels_lower        = array_map( 'strtolower', array_column( $field_list, 'label' ) );
                $recommended         = [
                    'naam'      => [ 'naam', 'voornaam', 'name' ],
                    'email'     => [ 'email', 'emailadres' ],
                    'onderwerp' => [ 'onderwerp', 'subject' ],
                    'bericht'   => [ 'bericht', 'message', 'tekst' ],
                ];
                $missing_recommended = [];
                foreach ( $recommended as $key => $patterns ) {
                    $found = false;
                    foreach ( $patterns as $p ) {
                        foreach ( $labels_lower as $l ) {
                            if ( strpos( $l, $p ) !== false ) {
                                $found = true;
                                break 2;
                            }
                        }
                    }
                    if ( ! $found ) {
                        $missing_recommended[] = $key;
                    }
                }
                if ( ! empty( $missing_recommended ) ) {
                    $observations['missing_recommended_fields'] = $missing_recommended;
                }

                $results[] = [
                    'post_id'      => (int) $post->ID,
                    'post_type'    => $post->post_type,
                    'post_title'   => $post->post_title,
                    'status'       => empty( $violations ) ? 'ok' : 'violations_found',
                    'violations'   => $violations,
                    'observations' => $observations,
                ];
            }

            // ─── WPForms detectie (site-wide, alle post types) ─────────────
            $wpforms_posts = $wpdb->get_results(
                "SELECT ID, post_type, post_title
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                 AND post_type != 'revision'
                 AND post_content LIKE '%[wpforms%'"
            );

            $wpforms_detected = [];
            foreach ( $wpforms_posts as $wp ) {
                $wpforms_detected[] = [
                    'post_id'    => (int) $wp->ID,
                    'post_type'  => $wp->post_type,
                    'post_title' => $wp->post_title,
                    'notice'     => 'deprecated: vervang [wpforms] door us_cform (Impreza formuliersysteem)',
                ];
            }

            // WPForms in custom fields (postmeta)
            $wpforms_meta = $wpdb->get_results(
                "SELECT DISTINCT p.ID, p.post_type, p.post_title
                 FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_value LIKE '%[wpforms%'
                 AND p.post_status = 'publish'
                 AND p.post_type != 'revision'"
            );
            foreach ( $wpforms_meta as $wp ) {
                $already = array_filter( $wpforms_detected, fn( $r ) => $r['post_id'] === (int) $wp->ID );
                if ( empty( $already ) ) {
                    $wpforms_detected[] = [
                        'post_id'    => (int) $wp->ID,
                        'post_type'  => $wp->post_type,
                        'post_title' => $wp->post_title,
                        'notice'     => 'deprecated (custom field): vervang [wpforms] door us_cform (Impreza formuliersysteem)',
                    ];
                }
            }

            // ─── Site-wide violation: inbound messages uitgeschakeld ──────
            $site_violations = [];
            if ( count( $results ) > 0 && ! $cform_inbound_option ) {
                $site_violations[] = [
                    'rule'   => 'cform_inbound_disabled',
                    'detail' => 'cform_inbound_messages staat op 0 in usof_options_Impreza terwijl er ' . count( $results ) . ' actieve formulieren zijn — inzendingen worden niet opgeslagen in de database',
                ];
            }

            return [
                'cform_inbound_active' => $cform_inbound_active,
                'cform_inbound_option' => $cform_inbound_option,
                'site_violations'      => $site_violations,
                'forms_found'          => count( $results ),
                'forms_with_issues'    => count( array_filter( $results, fn( $r ) => $r['status'] !== 'ok' ) ),
                'forms'                => $results,
                'wpforms_detected'     => $wpforms_detected,
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/grid/validate
     * Valideert alle us_grid_layout en us_header posts op configuratiefouten.
     *
     * Gecontroleerde regels:
     * - empty_style_attr         — style="" of *_style="" op elementen (stijlobject verwijderd)
     * - hardcoded_label          — label bevat hardcoded tekst zonder ACF-veldverwijzing
     * - hardcoded_image          — img= bevat hardcoded numeriek media-ID
     * - hardcoded_link           — link= bevat hardcoded URL (btn:*)
     * - missing_hide_empty       — post_custom_field:* waarbij hide_empty niet 1 is
     * - missing_color_link       — post_custom_field:* waarbij color_link niet 0 is
     * - missing_hide_with_empty_link — btn:* of text:* met link waarbij hide_with_empty_link niet 1 is
     * - css_forbidden            — css property aanwezig op een element (custom inline CSS)
     * - wrong_option_syntax      — {{option: in plaats van {{option/ in een elementwaarde
     * - missing_acf_link         — btn:* label verwijst naar {{bl_...}} maar link is geen custom_field
     * - wrong_link_field_prefix  — btn:* link verwijst naar opt_ veld zonder option/ prefix
     * - image_lazy_loading_enabled    — image:* (us_header) disable_lazy_loading != 1
     * - image_missing_homepage_link   — image:* (us_header) link is niet {"type":"homepage"}
     * - image_has_ratio               — image:* (us_header) has_ratio = 1
     * - image_has_style               — image:* (us_header) style is niet leeg
     * - image_wrong_size              — image:* (us_header) size is niet "full"
     */
    register_rest_route( 'aspera/v1', '/grid/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            global $wpdb;
            $post_ids = $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type IN ('us_grid_layout','us_header')
                 AND post_status = 'publish'"
            );

            $results = [];

            foreach ( $post_ids as $post_id ) {
                $post    = get_post( (int) $post_id );
                $data    = json_decode( $post->post_content, true );

                if ( ! is_array( $data ) || empty( $data['data'] ) ) continue;

                $violations = [];

                foreach ( $data['data'] as $element_key => $element ) {
                    if ( ! is_array( $element ) ) continue;

                    $element_type = (string) strstr( $element_key, ':', true );

                    // ─── empty_style_attr ─────────────────────────────────────
                    // Uitzondering: image:* en img:* — style="" is toegestaan
                    if ( ! in_array( $element_type, [ 'image', 'img' ], true ) ) {
                        foreach ( $element as $attr_key => $attr_val ) {
                            if ( ( $attr_key === 'style' || substr( $attr_key, -6 ) === '_style' )
                                 && $attr_val === '' ) {
                                $violations[] = [
                                    'element' => $element_key,
                                    'rule'    => 'empty_style_attr',
                                    'detail'  => $attr_key . '="" — stijl was ingesteld maar het stijlobject bestaat niet meer in Impreza',
                                ];
                            }
                        }
                    }

                    // ─── css_forbidden / design_css_forbidden / animate_detected (css-object) ──
                    // element.css kan zijn:
                    //  - leeg string ""
                    //  - legacy string (oude format)
                    //  - object { breakpoint: { property: value } } (Design-tab, nieuw format)
                    // Beleid:
                    //  - aspect-ratio: toegestaan (Impreza heeft de control hierheen verplaatst)
                    //  - animation-*: doorgeven aan animate_detected (severity observation)
                    //  - alle overige properties: design_css_forbidden (severity error)
                    $element_css = $element['css'] ?? null;

                    if ( is_string( $element_css ) && $element_css !== '' ) {
                        $violations[] = [
                            'element' => $element_key,
                            'rule'    => 'css_forbidden',
                            'detail'  => 'css property aanwezig (legacy string) — custom inline CSS buiten Impreza stijlsysteem',
                        ];
                    } elseif ( is_array( $element_css ) && ! empty( $element_css ) ) {
                        $design_props = [];
                        $anim_props   = [];

                        foreach ( $element_css as $bp => $bp_props ) {
                            if ( ! is_array( $bp_props ) ) continue;
                            foreach ( $bp_props as $prop => $val ) {
                                if ( $prop === 'aspect-ratio' ) {
                                    continue; // toegestaan
                                }
                                if ( strpos( (string) $prop, 'animation' ) === 0 ) {
                                    $anim_props[] = $bp . '.' . $prop;
                                    continue;
                                }
                                $design_props[] = $bp . '.' . $prop;
                            }
                        }

                        if ( ! empty( $design_props ) ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'design_css_forbidden',
                                'detail'  => 'Design-tab CSS overrides: ' . implode( ', ', $design_props ),
                            ];
                        }
                        if ( ! empty( $anim_props ) ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'animate_detected',
                                'detail'  => 'animation-properties in Design-tab: ' . implode( ', ', $anim_props ),
                            ];
                        }
                    }

                    // ─── wrong_option_syntax ─────────────────────────────────
                    foreach ( $element as $attr_key => $attr_val ) {
                        if ( is_string( $attr_val ) && strpos( $attr_val, '{{option:' ) !== false ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'wrong_option_syntax',
                                'detail'  => $attr_key . ' bevat {{option:...}} — gebruik {{option/...}}',
                            ];
                        }
                    }

                    // ─── hardcoded_label ──────────────────────────────────────
                    $label = $element['label'] ?? null;
                    if ( $label !== null && $label !== ''
                         && preg_match( '/[a-zA-Z]/', $label )
                         && strpos( $label, '{{' ) === false ) {
                        $violations[] = [
                            'element' => $element_key,
                            'rule'    => 'hardcoded_label',
                            'detail'  => 'label="' . $label . '" — hardcoded tekst; gebruik een ACF-veldverwijzing',
                        ];
                    }

                    // ─── hardcoded_image ──────────────────────────────────────
                    if ( in_array( $element_type, [ 'image', 'img' ], true ) ) {
                        $img = $element['img'] ?? null;
                        if ( $img !== null && $img !== '' && ctype_digit( (string) $img ) ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'hardcoded_image',
                                'detail'  => 'img=' . $img . ' — hardcoded media-ID; gebruik een ACF-veldverwijzing',
                            ];
                        }
                    }

                    // ─── image:* header-logo validatie (us_header only) ──────
                    if ( $element_type === 'image' && $post->post_type === 'us_header' ) {

                        // image_lazy_loading_enabled — disable_lazy_loading moet 1 zijn
                        if ( ( $element['disable_lazy_loading'] ?? 0 ) != 1 ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'image_lazy_loading_enabled',
                                'detail'  => 'disable_lazy_loading is niet ingesteld — logo staat boven de fold en moet direct laden',
                            ];
                        }

                        // image_missing_homepage_link — link moet {"type":"homepage"} zijn
                        $img_link_raw  = $element['link'] ?? null;
                        $img_link_data = is_string( $img_link_raw ) ? json_decode( urldecode( $img_link_raw ), true ) : null;
                        if ( ! is_array( $img_link_data ) || ( $img_link_data['type'] ?? '' ) !== 'homepage' ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'image_missing_homepage_link',
                                'detail'  => 'link is niet ingesteld op homepage — logo moet altijd naar de homepage linken',
                            ];
                        }

                        // image_has_ratio — has_ratio moet 0 zijn
                        if ( ( $element['has_ratio'] ?? 0 ) == 1 ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'image_has_ratio',
                                'detail'  => 'has_ratio=1 — aspect ratio is verboden op een header-logo',
                            ];
                        }

                        // image_has_style — style moet leeg of afwezig zijn
                        if ( isset( $element['style'] ) && $element['style'] !== '' ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'image_has_style',
                                'detail'  => 'style="' . $element['style'] . '" — image style is verboden op een header-logo',
                            ];
                        }

                        // image_wrong_size — size moet "full" zijn
                        if ( ( $element['size'] ?? '' ) !== 'full' ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'image_wrong_size',
                                'detail'  => 'size="' . ( $element['size'] ?? '' ) . '" — enige toegestane waarde is "full"',
                            ];
                        }
                    }

                    // ─── btn checks ──────────────────────────────────────────
                    if ( $element_type === 'btn' ) {
                        $link_raw  = $element['link'] ?? null;
                        $link_data = ( $link_raw !== null )
                            ? json_decode( urldecode( (string) $link_raw ), true )
                            : null;

                        // ─── hardcoded_link ───────────────────────────────────
                        if ( is_array( $link_data )
                             && ( ! isset( $link_data['type'] ) || $link_data['type'] !== 'custom_field' )
                             && ! empty( $link_data['url'] ) ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'hardcoded_link',
                                'detail'  => 'link bevat hardcoded URL "' . $link_data['url'] . '" — gebruik een ACF custom_field verwijzing',
                            ];
                        }

                        // ─── missing_acf_link ────────────────────────────────
                        $btn_label = $element['label'] ?? '';
                        if ( preg_match( '/\{\{bl_[\w_]+\}\}/', $btn_label ) ) {
                            if ( ! is_array( $link_data ) || ! isset( $link_data['type'] ) || $link_data['type'] !== 'custom_field' || empty( $link_data['custom_field'] ) ) {
                                $violations[] = [
                                    'element' => $element_key,
                                    'rule'    => 'missing_acf_link',
                                    'detail'  => 'label verwijst naar ACF bl_-veld maar link heeft geen custom_field verwijzing',
                                ];
                            }
                        }

                        // ─── wrong_link_field_prefix ─────────────────────────
                        if ( is_array( $link_data )
                             && isset( $link_data['type'] ) && $link_data['type'] === 'custom_field'
                             && ! empty( $link_data['custom_field'] )
                             && preg_match( '/^opt_/', $link_data['custom_field'] ) ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'wrong_link_field_prefix',
                                'detail'  => 'link verwijst naar "' . $link_data['custom_field'] . '" zonder option/ prefix — gebruik "option/' . $link_data['custom_field'] . '"',
                            ];
                        }

                        // ─── missing_hide_with_empty_link (btn) ──────────────
                        $has_link = is_array( $link_data ) && (
                            ( ! empty( $link_data['type'] ) && $link_data['type'] !== 'url' ) ||
                            ! empty( $link_data['url'] )
                        );
                        if ( $has_link && ( $element['hide_with_empty_link'] ?? 0 ) != 1 ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'missing_hide_with_empty_link',
                                'detail'  => 'hide_with_empty_link is niet ingeschakeld — element blijft zichtbaar wanneer de link leeg is',
                            ];
                        }
                    }

                    // ─── missing_hide_empty / missing_color_link (post_custom_field) ──
                    if ( $element_type === 'post_custom_field' ) {
                        if ( ( $element['hide_empty'] ?? 1 ) != 1 ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'missing_hide_empty',
                                'detail'  => 'hide_empty is niet ingeschakeld — lege veldwaarden worden zichtbaar weergegeven',
                            ];
                        }
                        if ( ( $element['color_link'] ?? 0 ) != 0 && aspera_acf_field_type( $element['key'] ?? '' ) !== 'image' ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'missing_color_link',
                                'detail'  => 'color_link staat aan — veldinhoud erft linkkleuren onnodig',
                            ];
                        }
                    }

                    // ─── missing_hide_with_empty_link (text) ─────────────────────────
                    if ( $element_type === 'text' ) {
                        $text_link_raw  = $element['link'] ?? null;
                        $text_link_data = is_string( $text_link_raw ) ? json_decode( urldecode( $text_link_raw ), true ) : null;
                        $has_link       = is_array( $text_link_data ) && (
                            ( ! empty( $text_link_data['type'] ) && $text_link_data['type'] !== 'url' ) ||
                            ! empty( $text_link_data['url'] )
                        );
                        if ( $has_link && ( $element['hide_with_empty_link'] ?? 0 ) != 1 ) {
                            $violations[] = [
                                'element' => $element_key,
                                'rule'    => 'missing_hide_with_empty_link',
                                'detail'  => 'hide_with_empty_link is niet ingeschakeld — element blijft zichtbaar wanneer de link leeg is',
                            ];
                        }
                    }

                    // ─── animate_detected: appear animatie (universeel) ──────────
                    $el_animate = $element['animate'] ?? null;
                    if ( $el_animate !== null && $el_animate !== '' ) {
                        $violations[] = [
                            'element' => $element_key,
                            'rule'    => 'animate_detected',
                            'detail'  => 'animate="' . $el_animate . '" — appear animatie aanwezig',
                        ];
                    }

                    // ─── responsive_hide_detected: verborgen op breakpoint (universeel) ──
                    $el_hide_bps = [];
                    foreach ( [ 'default', 'laptops', 'tablets', 'mobiles' ] as $bp ) {
                        if ( isset( $element['hide_on_' . $bp] ) && $element['hide_on_' . $bp] == 1 ) {
                            $el_hide_bps[] = $bp;
                        }
                    }
                    if ( ! empty( $el_hide_bps ) ) {
                        $violations[] = [
                            'element' => $element_key,
                            'rule'    => 'responsive_hide_detected',
                            'detail'  => 'verborgen op: ' . implode( ', ', $el_hide_bps ),
                        ];
                    }
                }

                $results[] = [
                    'post_id'    => (int) $post_id,
                    'post_type'  => $post->post_type,
                    'post_title' => $post->post_title,
                    'status'     => empty( $violations ) ? 'ok' : 'issues',
                    'violations' => $violations,
                ];
            }

            return [
                'grids_found'       => count( $post_ids ),
                'grids_with_issues' => count( array_filter( $results, fn( $r ) => $r['status'] !== 'ok' ) ),
                'grids'             => $results,
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/colors/validate
     * Scant alle Impreza post types en het actieve child theme op deprecated kleurwaarden.
     *
     * Scope:
     * - us_content_template, us_page_block : WPBakery shortcode kleurattributen
     * - us_header, us_grid_layout          : JSON keys die 'color' bevatten +
     *                                        element css-objecten (background-color etc.) +
     *                                        default.options.color_* tegel-instellingen
     * - Actief child theme custom.css + style.css : var(--color-*) referenties
     *
     * Regels (severity: error):
     * - deprecated_hex_var    : _bd795c — hex-code als CSS var-naam
     * - deprecated_custom_var : _cc1 / _rood — onbekende custom var
     * - hardcoded_hex_color   : #613912 — hardcoded hex (niet #fff/#000)
     * - deprecated_theme_var  : var(--color-ffffff) in custom.css
     * - unknown_theme_var     : var(--color-mijnkleur) — niet-Impreza var in CSS
     *
     * Regels (severity: observation):
     * - rgba_color : rgba(0,0,0,0.1) — native CSS kleur, mogelijk vervangbaar
     */
    register_rest_route( 'aspera/v1', '/colors/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            global $wpdb;

            $all_violations = [];
            $observations   = [];
            $scheme         = aspera_get_color_scheme();
            $whitelist      = $scheme['whitelist'];
            $hex_map        = $scheme['hex_map'];

            // ─── 1. WPBakery shortcodes: us_content_template + us_page_block ──
            $shortcode_posts = $wpdb->get_results(
                "SELECT ID, post_title, post_type, post_content
                 FROM {$wpdb->posts}
                 WHERE post_type IN ('us_content_template','us_page_block')
                 AND post_status = 'publish'"
            );

            foreach ( $shortcode_posts as $post ) {
                // Kleurattributen: naam is 'color' of eindigt op '_color'
                // color_scheme, color_link etc. vallen hier buiten door de regex
                preg_match_all( '/\b(\w+_color|color)="([^"]*)"/', $post->post_content, $matches, PREG_SET_ORDER );

                foreach ( $matches as $m ) {
                    $attr_name = $m[1];
                    $value     = $m[2];
                    $issue     = aspera_validate_color_value( $value, $attr_name, $whitelist, $hex_map );
                    if ( $issue === null ) continue;

                    $entry = [
                        'post_id'    => (int) $post->ID,
                        'post_type'  => $post->post_type,
                        'post_title' => $post->post_title,
                        'source'     => 'shortcode',
                        'attribute'  => $attr_name,
                        'value'      => $value,
                        'rule'       => $issue['rule'],
                        'detail'     => $issue['detail'],
                        'severity'   => $issue['severity'],
                    ];
                    if ( isset( $issue['suggestion'] ) ) {
                        $entry['suggestion'] = $issue['suggestion'];
                    }

                    if ( $issue['severity'] === 'observation' ) {
                        $observations[] = $entry;
                    } else {
                        $all_violations[] = $entry;
                    }
                }
            }

            // ─── 2. JSON post types: us_header + us_grid_layout ──────────────
            // Twee scanners per post:
            // a) aspera_find_color_violations_in_json  — recursief, alle keys met 'color' in naam
            // b) aspera_scan_grid_extended_colors      — element css-objecten (URL-encoded) + default.options.color_*
            $json_posts = get_posts( [
                'post_type'      => [ 'us_header', 'us_grid_layout' ],
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            ] );

            foreach ( $json_posts as $post ) {
                $data = json_decode( $post->post_content, true );
                if ( ! is_array( $data ) ) continue;
                aspera_find_color_violations_in_json( $data, $post, $all_violations, $observations, $whitelist, $hex_map );
                aspera_scan_grid_extended_colors( $data, $post, $all_violations, $observations, $whitelist, $hex_map );
            }

            // ─── 3. Child theme CSS: custom.css + style.css ──────────────────
            $theme_dir        = get_stylesheet_directory();
            $theme_violations = [];

            foreach ( [ 'custom.css', 'style.css' ] as $css_file ) {
                $css_path = $theme_dir . '/' . $css_file;
                if ( ! file_exists( $css_path ) ) continue;

                $lines = explode( "\n", file_get_contents( $css_path ) );

                foreach ( $lines as $line_idx => $line ) {
                    // ── var(--color-*) referenties ────────────────────────────
                    preg_match_all( '/var\(--color-([a-zA-Z0-9_-]+)\)/', $line, $css_m );

                    // Custom global color slugs (zonder leading _) als geldige CSS vars
                    $custom_color_slugs = [];
                    $cc_option = get_option( 'usof_options_Impreza', [] );
                    if ( ! empty( $cc_option['custom_colors'] ) && is_array( $cc_option['custom_colors'] ) ) {
                        foreach ( $cc_option['custom_colors'] as $cc ) {
                            if ( ! empty( $cc['slug'] ) ) {
                                $custom_color_slugs[] = ltrim( $cc['slug'], '_' );
                            }
                        }
                    }

                    foreach ( $css_m[1] as $var_raw ) {
                        // Hyphens → underscores voor whitelist-vergelijking
                        $var_name = str_replace( '-', '_', $var_raw );
                        if ( in_array( $var_name, $whitelist, true ) ) continue;
                        if ( in_array( $var_name, aspera_impreza_extra_vars(), true ) ) continue;
                        if ( in_array( $var_raw, $custom_color_slugs, true ) ) continue;

                        // Hex als var-naam vs. onbekende custom var
                        $rule = preg_match( '/^[0-9a-fA-F]{3,8}$/', $var_raw )
                            ? 'deprecated_theme_var'
                            : 'unknown_theme_var';

                        $theme_violations[] = [
                            'source'   => $css_file,
                            'line'     => $line_idx + 1,
                            'var'      => 'var(--color-' . $var_raw . ')',
                            'rule'     => $rule,
                            'detail'   => $css_file . ' regel ' . ( $line_idx + 1 ) . ': var(--color-' . $var_raw . ') is geen geldige Impreza CSS var',
                            'severity' => 'error',
                        ];
                    }

                    // ── Hardcoded hex in CSS-properties ──────────────────────
                    preg_match_all( '/:\s*(#[0-9a-fA-F]{3,8})\b/', $line, $hex_m );

                    foreach ( $hex_m[1] as $hex_raw ) {
                        if ( in_array( strtolower( $hex_raw ), [ '#fff', '#ffffff', '#000', '#000000' ], true ) ) continue;

                        $hex_key     = strtolower( $hex_raw );
                        $suggestions = isset( $hex_map[ $hex_key ] ) ? array_map( fn( $n ) => '_' . $n, $hex_map[ $hex_key ] ) : [];

                        $entry = [
                            'source'   => $css_file,
                            'line'     => $line_idx + 1,
                            'var'      => $hex_raw,
                            'rule'     => 'hardcoded_hex_color',
                            'detail'   => $css_file . ' regel ' . ( $line_idx + 1 ) . ': hardcoded hex ' . $hex_raw . '; gebruik een Impreza CSS var',
                            'severity' => 'error',
                        ];
                        if ( ! empty( $suggestions ) ) {
                            $entry['suggestion'] = implode( ' / ', $suggestions );
                        }
                        $theme_violations[] = $entry;
                    }
                }
            }

            $error_count = count( $all_violations )
                + count( array_filter( $theme_violations, fn( $t ) => $t['severity'] === 'error' ) );

            return [
                'status'            => $error_count === 0 ? 'ok' : 'violations_found',
                'violation_count'   => $error_count,
                'observation_count' => count( $observations ),
                'post_violations'   => $all_violations,
                'theme_violations'  => $theme_violations,
                'observations'      => $observations,
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/naming/validate
     * Valideert naamgevingsconventies van us_content_template en us_page_block posts.
     */
    register_rest_route( 'aspera/v1', '/naming/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;

            $violations  = [];
            $valid_count = 0;

            // --- us_content_template ---
            $templates = $wpdb->get_results(
                "SELECT ID, post_title FROM {$wpdb->posts}
                 WHERE post_type = 'us_content_template'
                   AND post_status = 'publish'
                 ORDER BY post_title"
            );

            $valid_template_prefixes = [ 'Page - ', 'CPT - ', 'TAX - ' ];

            foreach ( $templates as $t ) {
                $has_valid = false;
                foreach ( $valid_template_prefixes as $pfx ) {
                    if ( strpos( $t->post_title, $pfx ) === 0 ) {
                        $has_valid = true;
                        break;
                    }
                }
                if ( $has_valid ) {
                    $valid_count++;
                } else {
                    $violations[] = [
                        'rule'      => 'wrong_template_prefix',
                        'severity'  => 'warning',
                        'post_id'   => (int) $t->ID,
                        'post_type' => 'us_content_template',
                        'detail'    => '"' . $t->post_title . '" — verwacht prefix: Page - , CPT -  of TAX - ',
                    ];
                }
            }

            // --- us_page_block ---
            $blocks = $wpdb->get_results(
                "SELECT ID, post_title FROM {$wpdb->posts}
                 WHERE post_type = 'us_page_block'
                   AND post_status = 'publish'
                 ORDER BY post_title"
            );

            $block_exceptions = [ 'Footer', 'Titelbalk', 'Titlebar' ];

            foreach ( $blocks as $b ) {
                // Check exceptions first
                $is_exception = false;
                foreach ( $block_exceptions as $exc ) {
                    if ( stripos( $b->post_title, $exc ) !== false ) {
                        $is_exception = true;
                        break;
                    }
                }
                if ( $is_exception ) {
                    $valid_count++;
                    continue;
                }

                // Check deprecated term
                if ( stripos( $b->post_title, 'Page Block' ) !== false ) {
                    $violations[] = [
                        'rule'      => 'deprecated_page_block_term',
                        'severity'  => 'warning',
                        'post_id'   => (int) $b->ID,
                        'post_type' => 'us_page_block',
                        'detail'    => '"' . $b->post_title . '" — verouderde term "Page Block", moet "Reusable Block" zijn',
                    ];
                    continue;
                }

                // Check correct prefix
                if ( strpos( $b->post_title, 'Reusable Block - ' ) === 0 ) {
                    $valid_count++;
                } else {
                    $violations[] = [
                        'rule'      => 'wrong_block_prefix',
                        'severity'  => 'warning',
                        'post_id'   => (int) $b->ID,
                        'post_type' => 'us_page_block',
                        'detail'    => '"' . $b->post_title . '" — verwacht prefix "Reusable Block - " (of uitzondering: Footer, Titelbalk)',
                    ];
                }
            }

            return [
                'status'     => empty( $violations ) ? 'ok' : 'issues_found',
                'violations' => $violations,
                'summary'    => [
                    'templates_checked' => count( $templates ),
                    'blocks_checked'    => count( $blocks ),
                    'valid'             => $valid_count,
                    'violations'        => count( $violations ),
                ],
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/cpt/validate
     * Valideert ACF-geregistreerde custom post types:
     * - supports vs. publicly_queryable consistentie
     * - CPTUI leftover data in wp_options
     */
    register_rest_route( 'aspera/v1', '/cpt/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            $violations   = [];
            $observations = [];
            $cpt_overview = [];

            // ── 1. CPTUI leftover check ───────────────────────────────────
            $cptui_data     = get_option( 'cptui_post_types', null );
            $cptui_leftover = ! empty( $cptui_data );

            // ── 2. ACF-geregistreerde CPTs ophalen ────────────────────────
            $acf_posts = get_posts( [
                'post_type'      => 'acf-post-type',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            ] );

            $acf_slugs    = [];
            $icon_tracker = [];

            foreach ( $acf_posts as $acf_post ) {
                $config = maybe_unserialize( $acf_post->post_content );

                if ( ! is_array( $config ) ) continue;

                $slug                = $config['post_type'] ?? $acf_post->post_excerpt;
                $publicly_queryable  = ! empty( $config['publicly_queryable'] );
                $supports            = $config['supports'] ?? [];
                $menu_icon_raw       = $config['menu_icon'] ?? [];
                $menu_icon           = is_array( $menu_icon_raw ) ? ( $menu_icon_raw['value'] ?? '' ) : (string) $menu_icon_raw;
                $show_in_rest        = ! empty( $config['show_in_rest'] );
                $show_in_nav_menus   = ! empty( $config['show_in_nav_menus'] );
                $rename_capabilities = ! empty( $config['rename_capabilities'] );

                $acf_slugs[]     = $slug;
                $icon_tracker[]  = [ 'slug' => $slug, 'icon' => $menu_icon, 'type' => 'cpt' ];
                $post_violations  = [];
                $post_observations = [];

                // Regel: geen frontend → alleen 'title' in supports
                if ( ! $publicly_queryable ) {
                    $extra = array_diff( $supports, [ 'title' ] );
                    if ( ! empty( $extra ) ) {
                        $post_violations[] = [
                            'rule'   => 'unexpected_supports',
                            'detail' => 'publicly_queryable is false maar supports bevat: ' . implode( ', ', array_values( $extra ) ),
                        ];
                    }
                }

                // Regel: wél frontend → title altijd verplicht
                if ( $publicly_queryable && ! in_array( 'title', $supports, true ) ) {
                    $post_violations[] = [
                        'rule'   => 'missing_title_support',
                        'detail' => 'publicly_queryable is true maar title ontbreekt in supports',
                    ];
                }

                // Regel: standaard of leeg icoon is niet toegestaan
                if ( empty( $menu_icon ) || $menu_icon === 'dashicons-admin-post' ) {
                    $post_violations[] = [
                        'rule'   => 'default_icon',
                        'detail' => 'CPT gebruikt het standaard icoon (dashicons-admin-post) of heeft geen icoon — geef elk CPT een uniek herkenbaar icoon',
                    ];
                }

                // Regel: show_in_rest altijd verplicht
                if ( ! $show_in_rest ) {
                    $post_violations[] = [
                        'rule'   => 'missing_rest',
                        'detail' => 'show_in_rest is uitgeschakeld — REST API toegang is altijd vereist voor MCP en Claude',
                    ];
                }

                // Regel: show_in_nav_menus inconsistent met frontend
                if ( ! $publicly_queryable && $show_in_nav_menus ) {
                    $post_violations[] = [
                        'rule'   => 'nav_menus_no_frontend',
                        'detail' => 'show_in_nav_menus is aan maar publicly_queryable is false — CPT zonder frontend hoort niet in het nav menu',
                    ];
                }

                // Observatie: frontend aan maar nav_menus uit
                if ( $publicly_queryable && ! $show_in_nav_menus ) {
                    $post_observations[] = [
                        'rule'   => 'nav_menus_missing_frontend',
                        'detail' => 'show_in_nav_menus is uit terwijl publicly_queryable aan staat — controleer of dit bewust is',
                    ];
                }

                // Observatie: custom permissions ingeschakeld
                if ( $rename_capabilities ) {
                    $post_observations[] = [
                        'rule'   => 'custom_permissions',
                        'detail' => 'rename_capabilities is ingeschakeld — in 99% van de gevallen moet dit uit staan; controleer of dit bewust is',
                    ];
                }

                // ── Labels validatie ──────────────────────────────────────
                $labels   = $config['labels'] ?? [];
                $plural   = trim( $labels['name'] ?? '' );
                $singular = trim( $labels['singular_name'] ?? '' );

                // Regel: verplichte labels mogen niet leeg zijn
                $required_labels = [ 'name', 'singular_name', 'all_items', 'edit_item', 'view_item', 'add_new_item', 'search_items', 'not_found' ];
                $empty_labels    = [];
                foreach ( $required_labels as $lkey ) {
                    if ( empty( trim( $labels[ $lkey ] ?? '' ) ) ) {
                        $empty_labels[] = $lkey;
                    }
                }
                if ( ! empty( $empty_labels ) ) {
                    $post_violations[] = [
                        'rule'   => 'empty_labels',
                        'detail' => 'Verplichte labels zijn leeg: ' . implode( ', ', $empty_labels ) . ' — gebruik de ACF genereerknop',
                    ];
                }

                // Observatie: plural en singular zijn identiek
                if ( $plural !== '' && $singular !== '' && strtolower( $plural ) === strtolower( $singular ) ) {
                    $post_observations[] = [
                        'rule'   => 'plural_singular_identical',
                        'detail' => 'Plural ("' . $plural . '") en singular ("' . $singular . '") zijn identiek — controleer of dit grammaticaal gerechtvaardigd is',
                    ];
                }

                $cpt_entry = [
                    'slug'               => $slug,
                    'label'              => $config['labels']['name'] ?? $acf_post->post_title,
                    'publicly_queryable' => $publicly_queryable,
                    'supports'           => $supports,
                    'menu_icon'          => $menu_icon,
                    'show_in_rest'       => $show_in_rest,
                    'show_in_nav_menus'  => $show_in_nav_menus,
                ];

                if ( ! empty( $post_violations ) ) {
                    $cpt_entry['violations'] = $post_violations;
                    foreach ( $post_violations as $v ) {
                        $violations[] = array_merge( [ 'cpt' => $slug ], $v );
                    }
                }

                if ( ! empty( $post_observations ) ) {
                    $cpt_entry['observations'] = $post_observations;
                    foreach ( $post_observations as $o ) {
                        $observations[] = array_merge( [ 'cpt' => $slug ], $o );
                    }
                }

                $cpt_overview[] = $cpt_entry;
            }

            // ── 3. CPTUI-slugs die niet in ACF staan (echte leftover) ─────
            $cptui_orphans = [];
            if ( $cptui_leftover && is_array( $cptui_data ) ) {
                foreach ( array_keys( $cptui_data ) as $cptui_slug ) {
                    if ( ! in_array( $cptui_slug, $acf_slugs, true ) ) {
                        $cptui_orphans[] = $cptui_slug;
                    }
                }
            }

            // ── 4. Option page iconen toevoegen aan icon_tracker ──────────
            $opt_pages = get_posts( [
                'post_type'      => 'acf-ui-options-page',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            ] );
            foreach ( $opt_pages as $opt ) {
                $opt_config   = maybe_unserialize( $opt->post_content );
                if ( ! is_array( $opt_config ) ) continue;
                $opt_icon_raw = $opt_config['menu_icon'] ?? [];
                $opt_icon     = is_array( $opt_icon_raw ) ? ( $opt_icon_raw['value'] ?? '' ) : (string) $opt_icon_raw;
                if ( empty( $opt_icon ) ) continue;
                $opt_slug     = $opt_config['menu_slug'] ?? $opt->post_excerpt;
                $icon_tracker[] = [ 'slug' => $opt_slug, 'icon' => $opt_icon, 'type' => 'option_page' ];
            }

            // ── 5. Duplicate icoon check (CPTs + option pages) ────────────
            $governed_icons = [
                'opt_header'  => 'dashicons-table-row-before',
                'opt_footer'  => 'dashicons-table-row-after',
                'opt_widgets' => 'dashicons-table-col-before',
                'opt_forms'   => 'dashicons-email',
                'opt_socials' => 'dashicons-share',
                'nav_cpt'     => 'dashicons-menu-alt3',
                'links_cpt'   => 'dashicons-admin-links',
            ];

            $icon_map = [];
            foreach ( $icon_tracker as $item ) {
                if ( empty( $item['icon'] ) ) continue;
                $icon_map[ $item['icon'] ][] = $item['slug'];
            }
            foreach ( $icon_map as $icon => $slugs ) {
                if ( count( $slugs ) < 2 ) continue;
                foreach ( $slugs as $slug ) {
                    if ( isset( $governed_icons[ $slug ] ) && $governed_icons[ $slug ] === $icon ) continue;

                    $others           = array_diff( $slugs, [ $slug ] );
                    $governed_others  = array_filter( $others, function ( $o ) use ( $governed_icons, $icon ) {
                        return isset( $governed_icons[ $o ] ) && $governed_icons[ $o ] === $icon;
                    } );
                    $detail = 'Icoon ' . $icon . ' wordt ook gebruikt door: ' . implode( ', ', $others );
                    if ( ! empty( $governed_others ) ) {
                        $detail .= ' (vastgelegd door beleid voor ' . implode( ', ', $governed_others ) . ')';
                    }

                    $dup = [ 'rule' => 'duplicate_icon', 'detail' => $detail ];

                    foreach ( $cpt_overview as &$entry ) {
                        if ( $entry['slug'] !== $slug ) continue;
                        $entry['violations'][] = $dup;
                        break;
                    }
                    unset( $entry );

                    $violations[] = array_merge( [ 'cpt' => $slug ], $dup );
                }
            }

            $error_count = count( $violations ) + ( $cptui_leftover ? 1 : 0 );

            return [
                'status'            => $error_count === 0 ? 'ok' : 'violations_found',
                'violation_count'   => $error_count,
                'observation_count' => count( $observations ),
                'cptui_leftover'    => $cptui_leftover,
                'cptui_orphans'     => $cptui_orphans,
                'cpt_count'         => count( $cpt_overview ),
                'cpts'              => $cpt_overview,
                'violations'        => $violations,
                'observations'      => $observations,
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/site/audit
     * Geconsolideerde site-audit: aggregeert alle validatie-endpoints, berekent health score.
     *
     * Gebruikt rest_do_request() voor interne REST-aanroepen (geen HTTP overhead).
     * Categorieën: wpb, grid, colors, acf_slugs, forms, plugins, cpt, db_tables.
     *
     * Health score: max(0, 100 - sum_of_deductions)
     * Severity tiers: critical (3pt), error (2pt), warning (1pt), observation (0pt)
     * Per-categorie cap voorkomt dat één probleemgebied de gehele score domineert.
     *
     * Slaat resultaat op in wp_options voor toekomstige delta-rapportage:
     * - aspera_audit_score  (int)
     * - aspera_audit_date   (ISO 8601)
     * - aspera_audit_summary (json)
     */

    // ── /css/unused ──────────────────────────────────────────────────────
    // Detecteert ongebruikte custom CSS classes in het actieve child theme.
    // Vergelijkt classes uit style.css met post_content van de 5 relevante
    // post types. Impreza framework selectors worden overgeslagen.
    register_rest_route( 'aspera/v1', '/css/unused', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            global $wpdb;

            // ── 1. Lees style.css van het actieve child theme ────────────
            $theme_dir = get_stylesheet_directory();
            $css_path  = $theme_dir . '/style.css';

            if ( ! file_exists( $css_path ) ) {
                return new WP_Error( 'no_css', 'Geen style.css gevonden in het actieve theme.', [ 'status' => 404 ] );
            }

            $css_content = file_get_contents( $css_path );

            // Strip de theme header comment (alles vóór eerste */)
            $header_end = strpos( $css_content, '*/' );
            if ( $header_end !== false ) {
                $css_body = substr( $css_content, $header_end + 2 );
            } else {
                $css_body = $css_content;
            }

            // ── 2. Parse CSS: extraheer class-namen per regel ────────────
            // Bekende Impreza/WordPress framework prefixen — overslaan
            $framework_prefixes = [
                'l-',           // layout: l-section, l-footer, l-header, l-canvas, l-main
                'w-',           // componenten: w-nav, w-btn, w-grid, w-form, w-image
                'ush_',         // Impreza internals
                'us-',          // Impreza utility classes
                'type_',        // modifier
                'color_',       // modifier
                'pos_',         // modifier
                'level_',       // modifier
                'height_',      // modifier
                'hover_',       // modifier
                'no-touch',     // feature detection
                'menu-item',    // WordPress nav
                'full_height',  // Impreza modifier
                'g-cols',       // Impreza grid columns
                'align_',       // Impreza alignment
                'valign_',      // Impreza vertical alignment
                'state_',       // Impreza state
                'with_',        // Impreza modifier
                'at_',          // Impreza breakpoint
                'hidden',       // Impreza visibility
                'style_',       // Impreza style modifier
                'size_',        // Impreza size modifier
            ];

            // Exact-match klassen die volledig genegeerd worden (framework/3rd-party)
            $framework_exact = [
                'owl-dot',       // OWL Carousel
                'has_text_color', // WordPress block editor
            ];

            // Prefix-patronen die als observation gerapporteerd worden (niet als warning)
            $observation_prefixes = [
                'page-id-',     // WordPress body class per pagina-ID
            ];

            // Alle classes extraheren uit de CSS (zonder theme header)
            preg_match_all( '/\.([a-zA-Z_][a-zA-Z0-9_-]*)/', $css_body, $class_matches );
            $all_classes = array_unique( $class_matches[1] );

            // Classificeer: custom vs framework
            $custom_classes    = [];
            $framework_classes = [];

            foreach ( $all_classes as $class ) {
                // Exact-match framework klassen — volledig overslaan
                if ( in_array( $class, $framework_exact, true ) ) {
                    $framework_classes[] = $class;
                    continue;
                }
                // Prefix-check framework
                $is_framework = false;
                foreach ( $framework_prefixes as $prefix ) {
                    if ( strpos( $class, $prefix ) === 0 || $class === rtrim( $prefix, '-_' ) ) {
                        $is_framework = true;
                        break;
                    }
                }
                if ( $is_framework ) {
                    $framework_classes[] = $class;
                } else {
                    $custom_classes[] = $class;
                }
            }

            if ( empty( $custom_classes ) ) {
                return [
                    'status'              => 'ok',
                    'unused'              => [],
                    'used'                => [],
                    'framework_overrides' => count( $framework_classes ),
                    'total_custom'        => 0,
                ];
            }

            // ── 3. Zoek custom classes in post_content ───────────────────
            // Doorzoek de 5 relevante post types
            $post_types = [ 'us_header', 'us_content_template', 'us_page_block', 'us_grid_layout', 'page' ];
            $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

            $all_content = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_content FROM {$wpdb->posts}
                     WHERE post_type IN ({$placeholders})
                     AND post_status = 'publish'",
                    ...$post_types
                )
            );

            // Combineer alle post_content + post_excerpt (voor us_grid_layout)
            $all_excerpts = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_excerpt FROM {$wpdb->posts}
                     WHERE post_type IN ({$placeholders})
                     AND post_status = 'publish'
                     AND post_excerpt != ''",
                    ...$post_types
                )
            );

            $search_blob = implode( "\n", array_merge( $all_content, $all_excerpts ) );

            // ── 4. Per custom class: zoek in de gecombineerde content ────
            $unused = [];
            $used   = [];

            // Bouw per-class een regelnummer-referentie
            $css_lines = explode( "\n", $css_content );

            foreach ( $custom_classes as $class ) {
                // Zoek of de class voorkomt in post_content/post_excerpt
                if ( strpos( $search_blob, $class ) !== false ) {
                    $used[] = $class;
                } else {
                    // Vind het regelnummer in style.css
                    $line_num = null;
                    foreach ( $css_lines as $idx => $line ) {
                        if ( strpos( $line, '.' . $class ) !== false ) {
                            $line_num = $idx + 1;
                            break;
                        }
                    }

                    // Vind de volledige selector
                    $selector = null;
                    preg_match( '/([^{}]*\.' . preg_quote( $class, '/' ) . '[^{]*)\{/', $css_content, $sel_match );
                    if ( ! empty( $sel_match[1] ) ) {
                        $selector = trim( $sel_match[1] );
                    }

                    // Bepaal severity: observation voor bekende WordPress body-class patronen
                    $unused_severity = 'warning';
                    foreach ( $observation_prefixes as $obs_prefix ) {
                        if ( strpos( $class, $obs_prefix ) === 0 ) {
                            $unused_severity = 'observation';
                            break;
                        }
                    }

                    $unused[] = [
                        'class'    => $class,
                        'selector' => $selector,
                        'line'     => $line_num,
                        'severity' => $unused_severity,
                    ];
                }
            }

            // ── 5. Prefix check: custom classes moeten ag_ prefix hebben ─
            $deprecated_prefixes = [ 'qc_', 'sw_', 'lr_', 'jd_', 'ns_', 'vb_' ];
            $wrong_prefix = [];
            foreach ( $custom_classes as $class ) {
                if ( strpos( $class, 'ag_' ) === 0 ) continue;
                foreach ( $deprecated_prefixes as $dp ) {
                    if ( strpos( $class, $dp ) === 0 ) {
                        $line_num = null;
                        foreach ( $css_lines as $idx => $line ) {
                            if ( strpos( $line, '.' . $class ) !== false ) {
                                $line_num = $idx + 1;
                                break;
                            }
                        }
                        $wrong_prefix[] = [
                            'rule'     => 'wrong_css_prefix',
                            'severity' => 'warning',
                            'class'    => $class,
                            'prefix'   => $dp,
                            'line'     => $line_num,
                            'detail'   => '"' . $class . '" — site-specifiek prefix "' . $dp . '", verwacht "ag_"',
                        ];
                        break;
                    }
                }
            }

            $has_issues = ! empty( $unused ) || ! empty( $wrong_prefix );
            return [
                'status'              => $has_issues ? 'issues_found' : 'ok',
                'unused'              => $unused,
                'wrong_prefix'        => $wrong_prefix,
                'used'                => $used,
                'framework_overrides' => count( $framework_classes ),
                'total_custom'        => count( $custom_classes ),
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/nav/validate
     * Detecteert ongebruikte navigatiemenu's. Een menu is "in gebruik" als:
     *   1. De slug voorkomt in post_content van de 5 Impreza post types, OF
     *   2. Het menu is toegewezen aan een theme location (nav_menu_locations), OF
     *   3. Het menu is toegewezen aan een nav_menu widget, OF
     *   4. Het menu is het WPBakery-default (eerste alfabetisch) en er bestaan
     *      [us_additional_menu] shortcodes zonder source= attribuut.
     *      WPBakery slaat de default-keuze niet op als attribuut.
     */
    register_rest_route( 'aspera/v1', '/nav/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function () {
            global $wpdb;

            $menus = wp_get_nav_menus();
            if ( empty( $menus ) ) {
                return [ 'status' => 'ok', 'menus' => [], 'unused' => [] ];
            }

            // 1. Theme locations
            $locations     = get_nav_menu_locations();
            $located_ids   = is_array( $locations ) ? array_values( array_filter( $locations ) ) : [];

            // 2. Widget toewijzingen
            $widget_data   = get_option( 'widget_nav_menu', [] );
            $widget_ids    = [];
            if ( is_array( $widget_data ) ) {
                foreach ( $widget_data as $w ) {
                    if ( is_array( $w ) && ! empty( $w['nav_menu'] ) ) {
                        $widget_ids[] = (int) $w['nav_menu'];
                    }
                }
            }

            // 3. Post content search — zoek slug in de 5 relevante post types
            $post_types = [ 'us_header', 'us_content_template', 'us_page_block', 'us_grid_layout', 'page' ];
            $pt_in      = implode( ',', array_map( fn( $t ) => $wpdb->prepare( '%s', $t ), $post_types ) );

            // 4. WPBakery default-menu detectie: wp_get_nav_menus() retourneert
            //    alfabetisch gesorteerd — het eerste menu is de WPBakery default.
            //    Zoek of er [us_additional_menu ...] shortcodes bestaan ZONDER source= attribuut.
            $default_menu_slug = $menus[0]->slug;
            $has_sourceless    = false;

            $sourceless_posts = $wpdb->get_results(
                "SELECT ID, post_title, post_type, post_content
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type IN ({$pt_in})
                   AND post_content LIKE '%us_additional_menu%'"
            );

            $menu_slugs      = array_map( fn( $m ) => $m->slug, $menus );
            $default_used_in = [];
            $broken_refs     = [];

            foreach ( $sourceless_posts as $p ) {
                // Match [us_additional_menu ...] — met of zonder source= attribuut
                if ( preg_match_all( '/\[us_additional_menu([^\]]*)\]/', $p->post_content, $m ) ) {
                    foreach ( $m[1] as $attrs ) {
                        if ( strpos( $attrs, 'source=' ) === false ) {
                            // Geen source= → implicit default (eerste menu alfabetisch)
                            $has_sourceless = true;
                            $default_used_in[] = $p->post_type . ':' . $p->ID . ' (' . $p->post_title . ') [implicit default]';
                        } elseif ( preg_match( '/source="([^"]*)"/', $attrs, $src ) ) {
                            // source= aanwezig → controleer of het menu bestaat
                            $ref_slug = $src[1];
                            if ( ! in_array( $ref_slug, $menu_slugs, true ) ) {
                                $broken_refs[] = [
                                    'rule'      => 'broken_menu_reference',
                                    'slug'      => $ref_slug,
                                    'post_id'   => (int) $p->ID,
                                    'post_title'=> $p->post_title,
                                    'post_type' => $p->post_type,
                                    'detail'    => 'source="' . $ref_slug . '" verwijst naar niet-bestaand menu in ' . $p->post_type . ':' . $p->ID . ' (' . $p->post_title . ')',
                                ];
                            }
                        }
                    }
                }
            }

            // Ook us_header JSON: "source":"slug" in menu:* elementen
            $headers = $wpdb->get_results(
                "SELECT ID, post_title, post_content
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type = 'us_header'"
            );
            foreach ( $headers as $h ) {
                $hdata = json_decode( $h->post_content, true );
                if ( ! is_array( $hdata ) || ! isset( $hdata['data'] ) ) continue;
                foreach ( $hdata['data'] as $el_id => $el ) {
                    if ( strpos( $el_id, 'menu:' ) !== 0 && strpos( $el_id, 'additional_menu:' ) !== 0 ) continue;
                    $src_slug = $el['source'] ?? '';
                    if ( $src_slug !== '' && ! in_array( $src_slug, $menu_slugs, true ) ) {
                        $broken_refs[] = [
                            'rule'      => 'broken_menu_reference',
                            'slug'      => $src_slug,
                            'post_id'   => (int) $h->ID,
                            'post_title'=> $h->post_title,
                            'post_type' => 'us_header',
                            'element'   => $el_id,
                            'detail'    => 'source:"' . $src_slug . '" verwijst naar niet-bestaand menu in us_header:' . $h->ID . ' (' . $h->post_title . '), element ' . $el_id,
                        ];
                    }
                }
            }

            $result  = [];
            $unused  = [];

            foreach ( $menus as $menu ) {
                $slug = $menu->slug;
                $id   = (int) $menu->term_id;
                $used_in = [];

                // Theme location?
                if ( in_array( $id, $located_ids, true ) ) {
                    $loc_name = array_search( $id, $locations, true );
                    $used_in[] = 'theme_location:' . $loc_name;
                }

                // Widget?
                if ( in_array( $id, $widget_ids, true ) ) {
                    $used_in[] = 'widget';
                }

                // Post content? (slug kan voorkomen als source="slug" of "source":"slug")
                $found = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ID, post_title, post_type
                     FROM {$wpdb->posts}
                     WHERE post_status = 'publish'
                       AND post_type IN ({$pt_in})
                       AND post_content LIKE %s",
                    '%' . $wpdb->esc_like( $slug ) . '%'
                ) );

                foreach ( $found as $p ) {
                    $used_in[] = $p->post_type . ':' . $p->ID . ' (' . $p->post_title . ')';
                }

                // WPBakery implicit default?
                if ( $slug === $default_menu_slug && $has_sourceless ) {
                    $used_in = array_merge( $used_in, $default_used_in );
                }

                $entry = [
                    'term_id' => $id,
                    'name'    => $menu->name,
                    'slug'    => $slug,
                    'count'   => (int) $menu->count,
                    'used_in' => $used_in,
                ];

                $result[] = $entry;

                if ( empty( $used_in ) ) {
                    $unused[] = [
                        'term_id' => $id,
                        'name'    => $menu->name,
                        'slug'    => $slug,
                        'count'   => (int) $menu->count,
                        'rule'    => 'unused_nav_menu',
                        'detail'  => 'Menu "' . $menu->name . '" (slug: ' . $slug . ', ' . $menu->count . ' items) wordt nergens gebruikt',
                    ];
                }
            }

            // ── Naamgevingscontrole ──────────────────────────────────────
            $naming_issues = [];

            foreach ( $result as $entry ) {
                $name    = $entry['name'];
                $used_in = $entry['used_in'];

                // 1. invalid_menu_name — geen "Plaatsing - Naam" patroon
                if ( strpos( $name, ' - ' ) === false ) {
                    $naming_issues[] = [
                        'term_id' => $entry['term_id'],
                        'name'    => $name,
                        'slug'    => $entry['slug'],
                        'rule'    => 'invalid_menu_name',
                        'detail'  => 'Menu "' . $name . '" mist het patroon "Plaatsing - Naam" (bijv. "Header - Hoofdmenu", "Footer - Links")',
                    ];
                    continue; // Geen mismatch-check als naam al ongeldig is
                }

                // 2. mismatched_menu_placement — plaatsing in naam matcht niet met werkelijk gebruik
                if ( empty( $used_in ) ) continue; // Ongebruikt menu, mismatch niet relevant

                $placement = strtolower( trim( explode( ' - ', $name, 2 )[0] ) );

                // Bepaal waar het menu daadwerkelijk gebruikt wordt
                $in_header       = false;
                $in_footer_post  = false;
                foreach ( $used_in as $ref ) {
                    if ( strpos( $ref, 'us_header:' ) === 0 ) {
                        $in_header = true;
                    }
                    // Check of de post-titel "footer" bevat (case-insensitive)
                    if ( preg_match( '/\(([^)]+)\)/', $ref, $ref_m ) ) {
                        if ( stripos( $ref_m[1], 'footer' ) !== false ) {
                            $in_footer_post = true;
                        }
                    }
                }

                $mismatch = '';
                if ( $placement === 'header' && ! $in_header ) {
                    $mismatch = 'Menu heet "' . $name . '" (plaatsing: header) maar wordt niet gebruikt in een us_header';
                } elseif ( $placement === 'footer' && $in_header ) {
                    $mismatch = 'Menu heet "' . $name . '" (plaatsing: footer) maar wordt gebruikt in een us_header';
                } elseif ( $placement === 'sidebar' && ( $in_header || $in_footer_post ) ) {
                    $mismatch = 'Menu heet "' . $name . '" (plaatsing: sidebar) maar wordt gebruikt in een ' . ( $in_header ? 'us_header' : 'footer page block' );
                }

                if ( $mismatch !== '' ) {
                    $naming_issues[] = [
                        'term_id' => $entry['term_id'],
                        'name'    => $name,
                        'slug'    => $entry['slug'],
                        'rule'    => 'mismatched_menu_placement',
                        'detail'  => $mismatch,
                    ];
                }
            }

            // ── Menu items cache (één keer ophalen, meerdere secties gebruiken)
            $menu_items_cache = [];
            foreach ( $menus as $menu ) {
                $items = wp_get_nav_menu_items( $menu->term_id );
                $menu_items_cache[ $menu->term_id ] = is_array( $items ) ? $items : [];
            }

            // ── Externe links zonder target _blank ────────────────────
            $link_issues = [];
            $site_host   = wp_parse_url( home_url(), PHP_URL_HOST );

            foreach ( $menus as $menu ) {
                foreach ( $menu_items_cache[ $menu->term_id ] as $item ) {
                    $url = $item->url ?? '';
                    if ( $url === '' || $url === '#' ) continue;

                    $link_host = wp_parse_url( $url, PHP_URL_HOST );
                    if ( $link_host === null || $link_host === $site_host ) continue;

                    // Extern domein — target moet _blank zijn
                    if ( $item->target !== '_blank' ) {
                        $link_issues[] = [
                            'menu'    => $menu->name,
                            'item_id' => (int) $item->ID,
                            'title'   => $item->title,
                            'url'     => $url,
                            'rule'    => 'external_link_no_target_blank',
                            'detail'  => 'Menu-item "' . $item->title . '" in "' . $menu->name . '" linkt naar extern domein (' . $link_host . ') maar opent niet in nieuw tabblad',
                        ];
                    }
                }
            }

            // ── Pagina-controles ─────────────────────────────────────
            $page_issues = [];

            // Verzamel alle page IDs die in een menu voorkomen + custom labels
            $menu_page_ids = [];
            $label_checks  = [];

            foreach ( $menus as $menu ) {
                foreach ( $menu_items_cache[ $menu->term_id ] as $item ) {
                    if ( $item->object !== 'page' ) continue;
                    $page_id = (int) $item->object_id;
                    $menu_page_ids[ $page_id ] = true;

                    // Custom label check: post_title op nav_menu_item is de aangepaste titel
                    $custom_label = get_post_field( 'post_title', $item->ID, 'raw' );
                    if ( $custom_label !== '' ) {
                        $page_title = get_the_title( $page_id );
                        if ( $custom_label !== $page_title ) {
                            $label_checks[] = [
                                'menu'        => $menu->name,
                                'item_id'     => (int) $item->ID,
                                'page_id'     => $page_id,
                                'page_title'  => $page_title,
                                'menu_label'  => $custom_label,
                                'rule'        => 'custom_menu_label',
                                'detail'      => 'Menu-item in "' . $menu->name . '" toont "' . $custom_label . '" voor pagina "' . $page_title . '" (ID ' . $page_id . ')',
                            ];
                        }
                    }
                }
            }

            // Pagina's die niet in een menu voorkomen
            $all_pages = get_posts( [
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ] );

            $not_in_menu = [];
            foreach ( $all_pages as $pid ) {
                if ( ! isset( $menu_page_ids[ $pid ] ) ) {
                    $not_in_menu[] = [
                        'page_id'    => (int) $pid,
                        'page_title' => get_the_title( $pid ),
                        'rule'       => 'page_not_in_menu',
                        'detail'     => 'Pagina "' . get_the_title( $pid ) . '" (ID ' . $pid . ') komt niet voor in een navigatiemenu',
                    ];
                }
            }

            $page_issues = array_merge( $not_in_menu, $label_checks );

            $has_issues = ! empty( $unused ) || ! empty( $broken_refs ) || ! empty( $naming_issues ) || ! empty( $link_issues ) || ! empty( $page_issues );

            return [
                'status'           => $has_issues ? 'issues_found' : 'ok',
                'total_menus'      => count( $menus ),
                'unused'           => $unused,
                'broken_references'=> $broken_refs,
                'naming_issues'    => $naming_issues,
                'link_issues'      => $link_issues,
                'page_issues'      => $page_issues,
                'menus'            => $result,
            ];
        },
    ] );

    // ── Widget validatie ────────────────────────────────────────
    register_rest_route( 'aspera/v1', '/widgets/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function () {
            global $wpdb;

            $violations = [];

            // 1. Widgetised sidebar shortcodes in template post types
            $template_types = [ 'us_content_template', 'us_page_block', 'us_header', 'us_grid_layout', 'us_testimonial' ];
            $placeholders   = implode( ',', array_fill( 0, count( $template_types ), '%s' ) );
            $query          = $wpdb->prepare(
                "SELECT ID, post_title, post_type
                 FROM {$wpdb->posts}
                 WHERE post_type IN ({$placeholders})
                   AND post_status = 'publish'
                   AND post_content LIKE %s",
                array_merge( $template_types, [ '%vc_widget_sidebar%' ] )
            );
            $sidebar_posts = $wpdb->get_results( $query );

            foreach ( $sidebar_posts as $p ) {
                $violations[] = [
                    'rule'    => 'widgetised_sidebar_in_template',
                    'post_id' => (int) $p->ID,
                    'detail'  => "[vc_widget_sidebar] in {$p->post_type} \"{$p->post_title}\" (ID {$p->ID})",
                ];
            }

            // 2. Extra widget areas (us_widget_areas option)
            $widget_areas = get_option( 'us_widget_areas', [] );
            if ( ! is_array( $widget_areas ) ) {
                $widget_areas = [];
            }
            foreach ( $widget_areas as $area ) {
                $area_name = is_array( $area ) ? ( $area['name'] ?? $area['id'] ?? 'onbekend' ) : (string) $area;
                $violations[] = [
                    'rule'   => 'extra_widget_area',
                    'detail' => "Extra widget area: \"{$area_name}\" — moet verwijderd worden",
                ];
            }

            // 3. Default sidebar moet leeg zijn + actieve widgets in alle sidebars detecteren
            $sidebars = get_option( 'sidebars_widgets', [] );
            $default  = $sidebars['default_sidebar'] ?? [];

            if ( ! empty( $default ) ) {
                $violations[] = [
                    'rule'   => 'default_sidebar_not_empty',
                    'detail' => 'default_sidebar bevat ' . count( $default ) . ' actieve widget(s)',
                ];
            }

            // Helper: analyseer widgets in een sidebar
            $analyse_widgets = function ( array $widget_ids, string $sidebar_id ) use ( &$violations ) {
                $location = $sidebar_id === 'default_sidebar' ? '' : " in \"{$sidebar_id}\"";

                foreach ( $widget_ids as $widget_id ) {
                    if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $wm ) ) continue;
                    $type = $wm[1];
                    $num  = (int) $wm[2];

                    $widget_data = get_option( "widget_{$type}", [] );
                    $instance    = $widget_data[ $num ] ?? [];
                    $title       = trim( $instance['title'] ?? '' );
                    $title_info  = $title !== '' ? " Titel: \"{$title}\"." : '';

                    if ( $type === 'text' ) {
                        $preview  = mb_substr( strip_tags( $instance['text'] ?? '' ), 0, 100 );
                        $has_body = trim( $instance['text'] ?? '' ) !== '';
                        $violations[] = [
                            'rule'   => 'active_widget_text',
                            'detail' => "Text widget ({$widget_id}){$location}.{$title_info} Inhoud: \"{$preview}\". Vervanging: titel → text-field (H4) + [us_text], body → WYSIWYG + [us_post_custom_field]",
                        ];
                    } elseif ( $type === 'nav_menu' ) {
                        $menu_id   = $instance['nav_menu'] ?? 0;
                        $menu_obj  = wp_get_nav_menu_object( $menu_id );
                        $menu_name = $menu_obj ? $menu_obj->name : "ID {$menu_id}";
                        $violations[] = [
                            'rule'   => 'active_widget_nav_menu',
                            'detail' => "Navigation Menu widget ({$widget_id}){$location}.{$title_info} Menu: \"{$menu_name}\". Vervanging: titel → text-field + [us_text] (H4), menu → [us_additional_menu source=\"{$menu_name}\"]",
                        ];
                    } else {
                        $violations[] = [
                            'rule'   => 'active_widget_other',
                            'detail' => "Widget ({$widget_id}) type \"{$type}\"{$location}.{$title_info} Deprecated, verwijderen",
                        ];
                    }
                }
            };

            // Default sidebar widgets
            if ( ! empty( $default ) ) {
                $analyse_widgets( $default, 'default_sidebar' );
            }

            // 4. Actieve widgets in ANDERE sidebars
            foreach ( $sidebars as $sidebar_id => $widgets ) {
                if ( $sidebar_id === 'default_sidebar' || $sidebar_id === 'wp_inactive_widgets' || $sidebar_id === 'array_version' ) continue;
                if ( ! is_array( $widgets ) || empty( $widgets ) ) continue;
                $analyse_widgets( $widgets, $sidebar_id );
            }

            return [
                'status'          => empty( $violations ) ? 'ok' : 'issues_found',
                'violations'      => $violations,
                'violation_count' => count( $violations ),
                'widget_areas'    => array_keys( array_filter( $sidebars, function ( $v, $k ) {
                    return $k !== 'array_version' && $k !== 'wp_inactive_widgets' && is_array( $v );
                }, ARRAY_FILTER_USE_BOTH ) ),
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/wpb/modules/validate
     * Detecteert verboden WPBakery Custom CSS/JS op posts en actieve Module Manager modules.
     * Custom CSS en JS via WPBakery zijn ten strengste verboden — alle styling hoort in
     * het child theme, alle functionaliteit in plugins.
     */
    register_rest_route( 'aspera/v1', '/wpb/modules/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function () {
            global $wpdb;

            $violations = [];

            // 1. Per-post custom CSS (_wpb_post_custom_css)
            $css_posts = $wpdb->get_results(
                "SELECT p.ID, p.post_title, p.post_type, pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE pm.meta_key = '_wpb_post_custom_css'
                   AND pm.meta_value != ''
                   AND p.post_status = 'publish'"
            );
            foreach ( $css_posts as $row ) {
                $violations[] = [
                    'rule'      => 'wpb_post_custom_css',
                    'post_id'   => (int) $row->ID,
                    'post_title'=> $row->post_title,
                    'post_type' => $row->post_type,
                    'preview'   => mb_substr( $row->meta_value, 0, 200 ),
                    'detail'    => 'WPBakery Custom CSS op ' . $row->post_type . ':' . $row->ID . ' (' . $row->post_title . ')',
                ];
            }

            // 2. Per-post custom JS header + footer
            $js_posts = $wpdb->get_results(
                "SELECT p.ID, p.post_title, p.post_type, pm.meta_key, pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE pm.meta_key IN ('_wpb_post_custom_js_header', '_wpb_post_custom_js_footer')
                   AND pm.meta_value != ''
                   AND p.post_status = 'publish'"
            );
            foreach ( $js_posts as $row ) {
                $location = strpos( $row->meta_key, 'header' ) !== false ? 'header' : 'footer';
                $violations[] = [
                    'rule'      => 'wpb_post_custom_js',
                    'post_id'   => (int) $row->ID,
                    'post_title'=> $row->post_title,
                    'post_type' => $row->post_type,
                    'location'  => $location,
                    'preview'   => mb_substr( $row->meta_value, 0, 200 ),
                    'detail'    => 'WPBakery Custom JS (' . $location . ') op ' . $row->post_type . ':' . $row->ID . ' (' . $row->post_title . ')',
                ];
            }

            // 3. Module Manager — alle modules moeten uit staan
            $modules_raw = get_option( 'wpb_js_modules', '' );
            $modules     = is_string( $modules_raw ) ? json_decode( $modules_raw, true ) : [];
            $active_modules = [];

            if ( is_array( $modules ) ) {
                foreach ( $modules as $module => $enabled ) {
                    if ( $enabled === true ) {
                        $active_modules[] = $module;
                        $violations[] = [
                            'rule'   => 'wpb_module_active',
                            'module' => $module,
                            'detail' => 'WPBakery Module Manager: "' . $module . '" is actief — alle modules moeten uitgeschakeld zijn',
                        ];
                    }
                }
            }

            // 4. Beheerder-rol: post types moeten disabled zijn (vc_access_rules_post_types === false)
            $beheerder_role = null;
            if ( function_exists( 'wp_roles' ) ) {
                $all_roles = wp_roles()->roles ?? [];
                foreach ( $all_roles as $slug => $data ) {
                    $name = $data['name'] ?? '';
                    if ( strtolower( $slug ) === 'beheerder' || strtolower( $name ) === 'beheerder' ) {
                        $beheerder_role = [ 'slug' => $slug, 'name' => $name, 'capabilities' => $data['capabilities'] ?? [] ];
                        break;
                    }
                }
            }
            if ( is_array( $beheerder_role ) ) {
                $caps        = $beheerder_role['capabilities'];
                $pt_setting  = array_key_exists( 'vc_access_rules_post_types', $caps ) ? $caps['vc_access_rules_post_types'] : null;
                if ( $pt_setting !== false ) {
                    $shown = is_string( $pt_setting ) ? '"' . $pt_setting . '"' : ( $pt_setting === true ? 'true' : ( $pt_setting === null ? 'ontbreekt' : var_export( $pt_setting, true ) ) );
                    $violations[] = [
                        'rule'   => 'beheerder_post_types_not_disabled',
                        'role'   => $beheerder_role['slug'],
                        'detail' => 'Beheerder-rol "' . $beheerder_role['slug'] . '" (' . $beheerder_role['name'] . '): vc_access_rules_post_types = ' . $shown . ' (verwacht: false)',
                    ];
                }
            }

            return [
                'status'          => empty( $violations ) ? 'ok' : 'issues_found',
                'violations'      => $violations,
                'active_modules'  => $active_modules,
                'module_settings' => $modules,
                'beheerder_role'  => $beheerder_role !== null ? [ 'slug' => $beheerder_role['slug'], 'name' => $beheerder_role['name'] ] : null,
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/wpb/templates/validate
     * Detecteert opgeslagen WPBakery templates in wp_options (wpb_js_templates).
     * Vrijwel altijd onnodig — signaleer voor verwijdering.
     */
    register_rest_route( 'aspera/v1', '/wpb/templates/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function () {
            $raw       = get_option( 'wpb_js_templates', '' );
            $templates = is_string( $raw ) && ! empty( $raw ) ? maybe_unserialize( $raw ) : $raw;
            $count     = is_array( $templates ) ? count( $templates ) : 0;

            $violations = [];
            if ( $count > 0 ) {
                $violations[] = [
                    'rule'   => 'wpb_saved_templates',
                    'count'  => $count,
                    'detail' => $count . ' opgeslagen WPBakery template(s) gevonden in wpb_js_templates — verwijder via WPBakery Templates venster',
                ];
            }

            return [
                'status'     => empty( $violations ) ? 'ok' : 'issues_found',
                'violations' => $violations,
                'count'      => $count,
            ];
        },
    ] );

    // ── Theme breakpoint validatie ──────────────────────────────
    register_rest_route( 'aspera/v1', '/theme/breakpoints', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function () {
            $opts = get_option( 'usof_options_Impreza', [] );
            if ( ! is_array( $opts ) || empty( $opts ) ) {
                return new WP_Error( 'no_theme_options', 'usof_options_Impreza niet gevonden of ongeldig', [ 'status' => 404 ] );
            }

            $keys = [
                'site_content_width',
                'laptops_breakpoint',
                'tablets_breakpoint',
                'mobiles_breakpoint',
                'columns_stacking_width',
                'disable_effects_width',
            ];

            $values = [];
            foreach ( $keys as $k ) {
                $values[ $k ] = isset( $opts[ $k ] ) ? (int) $opts[ $k ] : null;
            }

            $issues = [];

            // Regel 1: mobile groep moet identiek zijn
            $mobile_bp   = $values['mobiles_breakpoint'];
            $col_stack   = $values['columns_stacking_width'];
            $disable_fx  = $values['disable_effects_width'];

            if ( $mobile_bp !== null ) {
                if ( $col_stack !== null && $col_stack !== $mobile_bp ) {
                    $issues[] = [
                        'rule'   => 'breakpoint_mobile_group_mismatch',
                        'detail' => "columns_stacking_width ({$col_stack}px) wijkt af van mobiles_breakpoint ({$mobile_bp}px)",
                    ];
                }
                if ( $disable_fx !== null && $disable_fx !== $mobile_bp ) {
                    $issues[] = [
                        'rule'   => 'breakpoint_mobile_group_mismatch',
                        'detail' => "disable_effects_width ({$disable_fx}px) wijkt af van mobiles_breakpoint ({$mobile_bp}px)",
                    ];
                }
            }

            // Regel 2: logische volgorde laptops > tablets > mobiles
            $laptops = $values['laptops_breakpoint'];
            $tablets = $values['tablets_breakpoint'];
            $mobiles = $values['mobiles_breakpoint'];

            if ( $laptops !== null && $tablets !== null && $laptops <= $tablets ) {
                $issues[] = [
                    'rule'   => 'breakpoint_order_invalid',
                    'detail' => "laptops_breakpoint ({$laptops}px) <= tablets_breakpoint ({$tablets}px)",
                ];
            }
            if ( $tablets !== null && $mobiles !== null && $tablets <= $mobiles ) {
                $issues[] = [
                    'rule'   => 'breakpoint_order_invalid',
                    'detail' => "tablets_breakpoint ({$tablets}px) <= mobiles_breakpoint ({$mobiles}px)",
                ];
            }

            // Regel 3: conventie-afwijking
            if ( $tablets !== null && $tablets !== 1100 ) {
                $issues[] = [
                    'rule'   => 'breakpoint_convention_deviation',
                    'detail' => "tablets_breakpoint is {$tablets}px (conventie: 1100px)",
                ];
            }
            if ( $mobiles !== null && $mobiles !== 900 ) {
                $issues[] = [
                    'rule'   => 'breakpoint_convention_deviation',
                    'detail' => "mobiles_breakpoint is {$mobiles}px (conventie: 900px)",
                ];
            }

            // Regel 4: breakpoints mogen niet hoger zijn dan site_content_width
            $content_w = $values['site_content_width'];
            if ( $content_w !== null ) {
                foreach ( [ 'laptops_breakpoint', 'tablets_breakpoint', 'mobiles_breakpoint' ] as $bp_key ) {
                    if ( $values[ $bp_key ] !== null && $values[ $bp_key ] > $content_w ) {
                        $issues[] = [
                            'rule'   => 'breakpoint_exceeds_content_width',
                            'detail' => "{$bp_key} ({$values[$bp_key]}px) > site_content_width ({$content_w}px)",
                        ];
                    }
                }

                // Regel 5: laptops_breakpoint moet gelijk zijn aan site_content_width
                if ( $laptops !== null && $laptops !== $content_w ) {
                    $issues[] = [
                        'rule'   => 'laptops_breakpoint_mismatch',
                        'detail' => "laptops_breakpoint ({$laptops}px) != site_content_width ({$content_w}px) — moet identiek zijn voor correct responsive gedrag",
                    ];
                }
            }

            return [
                'status'      => empty( $issues ) ? 'ok' : 'issues_found',
                'values'      => $values,
                'issues'      => $issues,
                'issue_count' => count( $issues ),
            ];
        },
    ] );

    /**
     * GET /wp-json/aspera/v1/taxonomy/validate
     * Detecteert verweesde taxonomieën: taxonomieën die in de database bestaan
     * maar niet meer geregistreerd zijn door een actieve plugin, theme of ACF.
     *
     * Per taxonomy wordt gecontroleerd:
     * 1. taxonomy_exists() — definitieve registratie-check via WordPress
     * 2. post_content referenties (templates, pages, blocks, headers, grids)
     * 3. post_excerpt referenties (us_grid_layout JSON)
     * 4. ACF field group locatieregels
     * 5. ACF taxonomy-type velden
     * 6. Nav menu items (links naar term archives)
     * 7. Theme bestanden (functions.php, style.css, custom.css)
     * 8. Term relationships → bestaan de gekoppelde posts nog?
     * 9. wp_termmeta (cleanup scope)
     *
     * Statussen:
     * - orphaned_safe: niet geregistreerd, geen referenties, geen bestaande posts
     * - orphaned_has_references: niet geregistreerd, maar ergens gerefereerd
     * - orphaned_has_posts: niet geregistreerd, maar gekoppelde posts bestaan nog
     */
    register_rest_route( 'aspera/v1', '/taxonomy/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            global $wpdb;

            // ── Core/interne taxonomieën — altijd overslaan ──────────────
            $skip_taxonomies = [
                'category', 'post_tag', 'post_format', 'nav_menu',
                'wp_theme', 'wp_template_part_area', 'wp_pattern_category',
                'link_category',
            ];

            // ── Taxonomieën overslaan zolang plugin geïnstalleerd is (actief of inactief) ──
            $skip_if_installed = [
                'wpconsent_category' => 'wpconsent-cookies-banner-privacy-suite',
            ];
            $tax_installed_slugs = aspera_get_plugin_slugs()['installed'];
            foreach ( $skip_if_installed as $tax => $plugin_slug ) {
                if ( in_array( $plugin_slug, $tax_installed_slugs, true ) ) {
                    $skip_taxonomies[] = $tax;
                }
            }

            // ── Bekende plugin-herkomst voor rapportage ──────────────────
            $known_plugins = [
                'cookielawinfo-category' => 'Cookie Law Info',
                'tribe_events_cat'       => 'The Events Calendar',
                'nt_wmc_folder'          => 'Media Folder Plugin',
                'block_pos_tax'          => 'Onbekend (block positioning)',
                'wpconsent_category'     => 'WPConsent',
            ];

            // ── ACF-interne post types — uitsluiten van referentie-checks ─
            $acf_internal_types = [
                'acf-taxonomy', 'acf-post-type', 'acf-field-group', 'acf-field',
                'acf-ui-options-page',
            ];

            // ── 1. Alle taxonomieën uit de database ──────────────────────
            $db_taxonomies = $wpdb->get_col(
                "SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}"
            );

            $results    = [];
            $violations = [];

            // Theme bestanden 1x inlezen voor referentie-checks (buiten de loop)
            $theme_dir          = get_stylesheet_directory();
            $theme_file_cache   = [];
            foreach ( [ 'style.css', 'functions.php', 'custom.css' ] as $tf ) {
                $tf_path = $theme_dir . '/' . $tf;
                $theme_file_cache[ $tf ] = file_exists( $tf_path ) ? file_get_contents( $tf_path ) : null;
            }

            foreach ( $db_taxonomies as $taxonomy ) {

                // Core overslaan
                if ( in_array( $taxonomy, $skip_taxonomies, true ) ) continue;

                // Actief geregistreerd? → geen probleem
                if ( taxonomy_exists( $taxonomy ) ) continue;

                // ── Taxonomy is NIET geregistreerd — referenties checken ─

                $references = [];
                $esc_tax    = $wpdb->esc_like( $taxonomy );
                $like       = '%' . $esc_tax . '%';

                // Check 1: post_content referenties (alle relevante post types)
                $content_refs = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ID, post_type, post_title FROM {$wpdb->posts}
                     WHERE post_status IN ('publish','draft','private')
                     AND post_content LIKE %s",
                    $like
                ) );
                foreach ( $content_refs as $ref ) {
                    if ( in_array( $ref->post_type, $acf_internal_types, true ) ) continue;
                    $references[] = [
                        'location'   => 'post_content',
                        'post_id'    => (int) $ref->ID,
                        'post_type'  => $ref->post_type,
                        'post_title' => $ref->post_title,
                    ];
                }

                // Check 2: post_excerpt (us_grid_layout)
                $excerpt_refs = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ID, post_title FROM {$wpdb->posts}
                     WHERE post_type = 'us_grid_layout'
                     AND post_excerpt LIKE %s",
                    $like
                ) );
                foreach ( $excerpt_refs as $ref ) {
                    $references[] = [
                        'location'   => 'post_excerpt (grid layout)',
                        'post_id'    => (int) $ref->ID,
                        'post_title' => $ref->post_title,
                    ];
                }

                // Check 3: ACF field group locatieregels
                $fg_refs = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ID, post_title FROM {$wpdb->posts}
                     WHERE post_type = 'acf-field-group'
                     AND post_content LIKE %s",
                    $like
                ) );
                foreach ( $fg_refs as $ref ) {
                    $references[] = [
                        'location'   => 'acf-field-group (location rule)',
                        'post_id'    => (int) $ref->ID,
                        'post_title' => $ref->post_title,
                    ];
                }

                // Check 4: ACF taxonomy-type velden
                $field_refs = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ID, post_title, post_excerpt FROM {$wpdb->posts}
                     WHERE post_type = 'acf-field'
                     AND post_content LIKE %s
                     AND post_content LIKE '%%\"taxonomy\"%%'",
                    $like
                ) );
                foreach ( $field_refs as $ref ) {
                    $references[] = [
                        'location'   => 'acf-field (taxonomy field type)',
                        'post_id'    => (int) $ref->ID,
                        'field_slug' => $ref->post_excerpt,
                        'post_title' => $ref->post_title,
                    ];
                }

                // Check 5: Nav menu items
                $nav_refs = $wpdb->get_results( $wpdb->prepare(
                    "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
                     JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE p.post_type = 'nav_menu_item'
                     AND pm.meta_key = '_menu_item_object'
                     AND pm.meta_value = %s",
                    $taxonomy
                ) );
                foreach ( $nav_refs as $ref ) {
                    $references[] = [
                        'location'   => 'nav_menu_item',
                        'post_id'    => (int) $ref->ID,
                        'post_title' => $ref->post_title,
                    ];
                }

                // Check 6: Theme bestanden (uit cache)
                $theme_files_hit = [];
                foreach ( $theme_file_cache as $file => $content ) {
                    if ( $content !== null && strpos( $content, $taxonomy ) !== false ) {
                        $theme_files_hit[] = $file;
                    }
                }
                if ( ! empty( $theme_files_hit ) ) {
                    $references[] = [
                        'location' => 'theme_files',
                        'files'    => $theme_files_hit,
                    ];
                }

                // ── Term info ophalen ────────────────────────────────────
                $terms = $wpdb->get_results( $wpdb->prepare(
                    "SELECT t.term_id, t.name, t.slug, tt.count, tt.term_taxonomy_id
                     FROM {$wpdb->term_taxonomy} tt
                     JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                     WHERE tt.taxonomy = %s",
                    $taxonomy
                ) );

                $term_count  = count( $terms );
                $unused      = 0;
                $term_ids    = [];
                $tt_ids      = [];
                $term_list   = [];

                foreach ( $terms as $term ) {
                    if ( (int) $term->count === 0 ) $unused++;
                    $term_ids[]  = (int) $term->term_id;
                    $tt_ids[]    = (int) $term->term_taxonomy_id;
                    $term_list[] = [
                        'name'  => $term->name,
                        'slug'  => $term->slug,
                        'count' => (int) $term->count,
                    ];
                }

                // ── Termmeta tellen ──────────────────────────────────────
                $termmeta_count = 0;
                if ( ! empty( $term_ids ) ) {
                    $ph = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
                    $termmeta_count = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id IN ($ph)",
                            ...$term_ids
                        )
                    );
                }

                // ── Term relationships — orphaned + bestaande posts ──────
                $orphaned_rels     = 0;
                $total_rels        = 0;
                $linked_posts      = [];

                if ( ! empty( $tt_ids ) ) {
                    $ph = implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) );

                    $total_rels = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->term_relationships}
                             WHERE term_taxonomy_id IN ($ph)",
                            ...$tt_ids
                        )
                    );

                    $orphaned_rels = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
                             LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                             WHERE tr.term_taxonomy_id IN ($ph)
                             AND p.ID IS NULL",
                            ...$tt_ids
                        )
                    );

                    $linked_posts = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_status
                             FROM {$wpdb->term_relationships} tr
                             JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                             WHERE tr.term_taxonomy_id IN ($ph)",
                            ...$tt_ids
                        )
                    );
                }

                // ── Status bepalen ───────────────────────────────────────
                $has_references  = ! empty( $references );
                $has_linked      = ! empty( $linked_posts );

                if ( $has_references ) {
                    $status = 'orphaned_has_references';
                } elseif ( $has_linked ) {
                    $status = 'orphaned_has_posts';
                } else {
                    $status = 'orphaned_safe';
                }

                // ── Entry opbouwen ───────────────────────────────────────
                $entry = [
                    'taxonomy'       => $taxonomy,
                    'registered'     => false,
                    'status'         => $status,
                    'probable_plugin'=> $known_plugins[ $taxonomy ] ?? null,
                    'terms'          => [
                        'count'  => $term_count,
                        'unused' => $unused,
                        'list'   => $term_list,
                    ],
                    'termmeta_rows'          => $termmeta_count,
                    'orphaned_relationships' => $orphaned_rels,
                    'cleanup_scope'          => [
                        'wp_terms'              => $term_count,
                        'wp_term_taxonomy'      => $term_count,
                        'wp_termmeta'           => $termmeta_count,
                        'wp_term_relationships' => $total_rels,
                    ],
                ];

                if ( $has_references ) {
                    $entry['references'] = $references;
                }

                if ( $has_linked ) {
                    $entry['linked_posts'] = array_map( function ( $p ) {
                        return [
                            'post_id'     => (int) $p->ID,
                            'post_type'   => $p->post_type,
                            'post_title'  => $p->post_title,
                            'post_status' => $p->post_status,
                        ];
                    }, $linked_posts );
                }

                $results[] = $entry;

                // Violation voor /site/audit integratie
                $violations[] = [
                    'taxonomy' => $taxonomy,
                    'rule'     => $status === 'orphaned_safe'
                                    ? 'orphaned_taxonomy'
                                    : 'orphaned_taxonomy_has_dependencies',
                    'detail'   => $taxonomy . ': ' . $term_count . ' terms'
                                  . ( $termmeta_count > 0 ? ', ' . $termmeta_count . ' termmeta' : '' )
                                  . ( $has_linked ? ', posts bestaan nog' : '' )
                                  . ( $has_references ? ', referenties gevonden' : '' ),
                ];
            }

            return [
                'status'               => empty( $results ) ? 'ok' : 'issues_found',
                'orphaned_taxonomies'  => $results,
                'violations'           => $violations,
                'summary'              => [
                    'total_orphaned'  => count( $results ),
                    'safe_to_remove'  => count( array_filter( $results, function ( $r ) {
                        return $r['status'] === 'orphaned_safe';
                    } ) ),
                    'needs_review'    => count( array_filter( $results, function ( $r ) {
                        return $r['status'] !== 'orphaned_safe';
                    } ) ),
                ],
            ];
        },
    ] );

    register_rest_route( 'aspera/v1', '/cache/validate', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function () {
            $violations = [];

            if ( ! function_exists( 'is_plugin_active' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $plugin_active = is_plugin_active( 'wp-fastest-cache/wpFastestCache.php' );
            if ( ! $plugin_active ) {
                return [
                    'violation_count' => 0,
                    'violations'      => [],
                    'config'          => [ 'plugin_active' => false ],
                ];
            }

            $main_raw = get_option( 'WpFastestCache' );
            $main = is_string( $main_raw ) ? json_decode( $main_raw, true ) : ( is_array( $main_raw ) ? $main_raw : [] );
            if ( ! is_array( $main ) ) { $main = []; }

            $on  = function ( $k ) use ( $main ) { return isset( $main[ $k ] ) && $main[ $k ] === 'on'; };
            $off = function ( $k ) use ( $main ) { return ! isset( $main[ $k ] ) || $main[ $k ] !== 'on'; };

            if ( $off( 'wpFastestCacheStatus' ) ) {
                $violations[] = [ 'rule' => 'cache_disabled', 'detail' => 'wpFastestCacheStatus != "on"' ];
            }

            $preload_required = [
                'wpFastestCachePreload'                 => 'cache_preload_disabled',
                'wpFastestCachePreload_homepage'        => 'cache_preload_homepage_missing',
                'wpFastestCachePreload_post'            => 'cache_preload_post_missing',
                'wpFastestCachePreload_page'            => 'cache_preload_page_missing',
                'wpFastestCachePreload_customposttypes' => 'cache_preload_cpt_missing',
            ];
            foreach ( $preload_required as $opt => $rule ) {
                if ( $off( $opt ) ) {
                    $violations[] = [ 'rule' => $rule, 'detail' => $opt . ' staat niet aan' ];
                }
            }
            $threads = $main['wpFastestCachePreload_number'] ?? '';
            if ( $threads === '' || $threads === null ) {
                $violations[] = [ 'rule' => 'cache_preload_threads_missing', 'detail' => 'wpFastestCachePreload_number leeg' ];
            }
            if ( $off( 'wpFastestCachePreload_restart' ) ) {
                $violations[] = [ 'rule' => 'cache_preload_restart_missing', 'detail' => 'Preload herstart na cache-clear staat uit' ];
            }
            if ( $off( 'wpFastestCacheNewPost' ) ) {
                $violations[] = [ 'rule' => 'cache_purge_on_new_post_missing', 'detail' => 'Cache wordt niet gewist bij nieuwe post' ];
            }
            if ( $off( 'wpFastestCacheUpdatePost' ) ) {
                $violations[] = [ 'rule' => 'cache_purge_on_update_post_missing', 'detail' => 'Cache wordt niet gewist bij update post' ];
            }
            if ( $off( 'wpFastestCacheMinifyHtml' ) ) { $violations[] = [ 'rule' => 'cache_minify_html_disabled',  'detail' => 'Minify HTML staat uit' ]; }
            if ( $off( 'wpFastestCacheMinifyCss' )  ) { $violations[] = [ 'rule' => 'cache_minify_css_disabled',   'detail' => 'Minify CSS staat uit'  ]; }
            if ( $off( 'wpFastestCacheCombineCss' ) ) { $violations[] = [ 'rule' => 'cache_combine_css_disabled',  'detail' => 'Combine CSS staat uit' ]; }
            if ( $on( 'wpFastestCacheMinifyJs' )  ) { $violations[] = [ 'rule' => 'cache_minify_js_enabled',  'detail' => 'Minify JS staat aan, moet uit' ]; }
            if ( $on( 'wpFastestCacheCombineJs' ) ) { $violations[] = [ 'rule' => 'cache_combine_js_enabled', 'detail' => 'Combine JS staat aan, moet uit' ]; }
            if ( $off( 'wpFastestCacheGzip' ) ) { $violations[] = [ 'rule' => 'cache_gzip_disabled',             'detail' => 'Gzip staat uit' ]; }
            if ( $off( 'wpFastestCacheLBC' )  ) { $violations[] = [ 'rule' => 'cache_browser_caching_disabled', 'detail' => 'Leverage Browser Caching staat uit' ]; }
            if ( $on( 'wpFastestCacheDisableEmojis' ) === false ) {
                $violations[] = [ 'rule' => 'cache_emojis_enabled', 'detail' => 'Disable Emojis staat uit (emojis worden geladen)' ];
            }
            if ( $on( 'wpFastestCacheMobileTheme' ) ) {
                $violations[] = [ 'rule' => 'cache_mobile_theme_enabled', 'detail' => 'Aparte mobiele cache staat aan' ];
            }
            if ( $on( 'wpFastestCacheLoggedInUser' ) ) {
                $violations[] = [ 'rule' => 'cache_logged_in_user_enabled', 'detail' => 'Cache voor ingelogde users staat aan' ];
            }

            // Cache timeout via WP cron
            $cron = function_exists( '_get_cron_array' ) ? ( _get_cron_array() ?: [] ) : [];
            $timeout_events = [];
            foreach ( $cron as $ts => $hooks ) {
                foreach ( $hooks as $hook => $payload ) {
                    if ( preg_match( '/^wp_fastest_cache_\d+$/', $hook ) ) {
                        foreach ( $payload as $sig => $event ) {
                            $timeout_events[] = [
                                'hook'     => $hook,
                                'schedule' => $event['schedule'] ?? null,
                                'interval' => $event['interval'] ?? null,
                                'args'     => $event['args'] ?? [],
                            ];
                        }
                    }
                }
            }
            if ( empty( $timeout_events ) ) {
                $violations[] = [ 'rule' => 'cache_timeout_missing', 'detail' => 'Geen wp_fastest_cache_* cron-event aanwezig' ];
            } else {
                $not_daily_details = [];
                $scope_partial_details = [];
                foreach ( $timeout_events as $ev ) {
                    if ( $ev['schedule'] !== 'onceaday' || (int) $ev['interval'] !== 86400 ) {
                        $not_daily_details[] = $ev['hook'] . ' (schedule=' . $ev['schedule'] . ', interval=' . $ev['interval'] . ')';
                    }
                    $args_json = $ev['args'][0] ?? null;
                    $args_arr  = is_string( $args_json ) ? json_decode( $args_json, true ) : null;
                    if ( is_array( $args_arr ) ) {
                        if ( ( $args_arr['prefix'] ?? '' ) !== 'all' || ( $args_arr['content'] ?? '' ) !== 'all' ) {
                            $scope_partial_details[] = $ev['hook'] . ' (prefix=' . ( $args_arr['prefix'] ?? '?' ) . ', content=' . ( $args_arr['content'] ?? '?' ) . ')';
                        }
                    }
                }
                if ( ! empty( $not_daily_details ) ) {
                    $violations[] = [ 'rule' => 'cache_timeout_not_daily', 'detail' => 'Verwacht onceaday/86400. Afwijkend: ' . implode( '; ', $not_daily_details ) ];
                }
                if ( ! empty( $scope_partial_details ) ) {
                    $violations[] = [ 'rule' => 'cache_timeout_scope_partial', 'detail' => 'Verwacht prefix=all en content=all. Afwijkend: ' . implode( '; ', $scope_partial_details ) ];
                }
            }

            $lang = $main['wpFastestCacheLanguage'] ?? '';
            if ( stripos( (string) $lang, 'en' ) !== 0 ) {
                $violations[] = [ 'rule' => 'cache_language_not_english', 'detail' => 'wpFastestCacheLanguage="' . $lang . '" (verwacht: en_*)' ];
            }

            $toolbar_raw = get_option( 'WpFastestCacheToolbarSettings' );
            $toolbar     = is_array( $toolbar_raw ) ? $toolbar_raw : ( is_string( $toolbar_raw ) ? maybe_unserialize( $toolbar_raw ) : [] );
            if ( ! is_array( $toolbar ) || ( $toolbar['wpfc_toolbar_beheerder'] ?? '' ) !== '1' ) {
                $violations[] = [ 'rule' => 'cache_toolbar_admin_only_missing', 'detail' => 'wpfc_toolbar_beheerder != "1"' ];
            }

            return [
                'violation_count' => count( $violations ),
                'violations'      => $violations,
                'config'          => [
                    'plugin_active'  => true,
                    'cache_status'   => $main['wpFastestCacheStatus'] ?? null,
                    'language'       => $lang,
                    'timeout_events' => count( $timeout_events ),
                ],
            ];
        },
    ] );

    register_rest_route( 'aspera/v1', '/site/audit', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            // ── Severity mapping per rule ─────────────────────────────────
            $severity_map = [
                // wpb/validate/all
                'hardcoded_label'             => 'error',
                'hardcoded_image'             => 'error',
                'hardcoded_link'              => 'error',
                'empty_style_attr'            => 'warning',
                'missing_hide_empty'          => 'warning',
                'missing_color_link'          => 'warning',
                'missing_hide_with_empty_link'=> 'warning',
                'css_forbidden'               => 'warning',
                'design_css_forbidden'        => 'error',
                'wrong_option_syntax'         => 'critical',
                'missing_acf_link'            => 'error',
                'wrong_link_field_prefix'     => 'critical',
                'missing_el_class'            => 'warning',
                'missing_remove_rows'         => 'error',
                'parent_row_with_siblings'    => 'error',
                'hardcoded_bg_image'          => 'error',
                'hardcoded_bg_video'          => 'error',
                'hardcoded_text'              => 'error',
                'empty_btn_style'             => 'warning',
                'scroll_effect_forbidden'     => 'warning',
                'vc_video_wrong_attribute'    => 'warning',
                'missing_columns_reverse'     => 'error',
                'unexpected_columns_reverse'  => 'error',
                'wpforms_deprecated'          => 'warning',
                'animate_detected'            => 'observation',
                'responsive_hide_detected'    => 'observation',

                // grid/validate — image:* (us_header only)
                'image_lazy_loading_enabled'  => 'error',
                'image_missing_homepage_link' => 'error',
                'image_has_ratio'             => 'error',
                'image_has_style'             => 'error',
                'image_wrong_size'            => 'error',

                // colors/validate
                'deprecated_hex_var'          => 'error',
                'deprecated_custom_var'       => 'error',
                'hardcoded_hex_color'         => 'error',
                'deprecated_theme_var'        => 'error',
                'unknown_theme_var'           => 'warning',
                'rgba_color'                  => 'observation',

                // acf/validate/slugs
                'missing_number'              => 'error',
                'wrong_opt_format'            => 'error',
                'wrong_cpt_format'            => 'error',
                'wrong_page_format'           => 'error',
                'wrong_cpt_format_multi'      => 'observation',
                'wrong_page_format_multi'     => 'observation',

                // forms/validate
                'cform_inbound_disabled'      => 'critical',
                'missing_receiver_email'      => 'critical',
                'hardcoded_receiver_email'    => 'error',
                'missing_button_text'         => 'warning',
                'hardcoded_button_text'       => 'error',
                'empty_button_style'          => 'warning',
                'missing_success_message'     => 'warning',
                'hardcoded_success_message'   => 'error',
                'missing_email_subject'       => 'warning',
                'missing_email_message'       => 'warning',
                'missing_field_list'          => 'warning',
                'missing_recaptcha'           => 'critical',
                'missing_email_field'         => 'error',
                'wrong_email_field_type'      => 'error',
                'missing_move_label'          => 'observation',
                'empty_option_field'          => 'error',

                // plugins/validate
                'extra_plugin'                => 'observation',

                // cpt/validate
                'missing_rest'                => 'critical',
                'default_icon'                => 'warning',
                'duplicate_icon'              => 'warning',
                'empty_labels'                => 'error',
                'unexpected_supports'         => 'warning',
                'missing_title_support'       => 'error',
                'nav_menus_no_frontend'       => 'warning',
                'cptui_leftover'              => 'warning',

                // db/tables/validate
                'orphaned_table'              => 'warning',
                'unknown_table'               => 'observation',
                'orphaned_post_type'          => 'warning',
                'orphaned_plugin_options'     => 'warning',
                'orphaned_plugin_meta'        => 'warning',

                // css/unused
                'unused_css_class'            => 'warning',
                'wrong_css_prefix'            => 'warning',

                // nav/validate
                'unused_nav_menu'             => 'warning',
                'broken_menu_reference'       => 'error',
                'invalid_menu_name'           => 'warning',
                'mismatched_menu_placement'   => 'warning',
                'external_link_no_target_blank' => 'warning',
                'page_not_in_menu'            => 'observation',
                'custom_menu_label'           => 'observation',

                // widgets/validate
                'widgetised_sidebar_in_template' => 'error',
                'extra_widget_area'              => 'error',
                'default_sidebar_not_empty'      => 'error',
                'active_widget_text'             => 'error',
                'active_widget_nav_menu'         => 'error',
                'active_widget_other'            => 'error',

                // wpb/modules/validate
                'wpb_post_custom_css'         => 'critical',
                'wpb_post_custom_js'          => 'critical',
                'beheerder_post_types_not_disabled' => 'critical',

                // wpb/templates/validate
                'wpb_saved_templates'         => 'warning',
                'wpb_module_active'           => 'critical',

                // theme/breakpoints
                'breakpoint_mobile_group_mismatch' => 'error',
                'breakpoint_order_invalid'         => 'error',
                'breakpoint_convention_deviation'   => 'observation',
                'breakpoint_exceeds_content_width'  => 'error',
                'laptops_breakpoint_mismatch'       => 'error',

                // taxonomy/validate
                'orphaned_taxonomy'                    => 'warning',
                'orphaned_taxonomy_has_dependencies'    => 'warning',

                // header/validate
                'custom_breakpoint_invalid_order'        => 'error',
                'custom_breakpoint_exceeds_content_width'=> 'warning',
                'custom_breakpoint_active'               => 'observation',
                'orientation_vertical_forbidden'         => 'error',
                'menu_mobile_always'                     => 'observation',
                'menu_mobile_exceeds_content_width'      => 'observation',
                'menu_mobile_exceeds_breakpoints'        => 'observation',

                // acf/validate/locations
                'orphaned_location_taxonomy'             => 'error',
                'orphaned_location_term'                 => 'warning',
                'empty_location_term'                    => 'observation',

                // acf/validate/all
                'missing_name'                           => 'error',
                'broken_conditional_reference'           => 'error',
                'mixed_choice_key_types'                 => 'warning',
                'wysiwyg_media_upload_enabled'           => 'warning',
                'wrong_group_name_prefix'                => 'warning',

                // meta/validate
                'orphaned_meta'                          => 'warning',
                'orphaned_meta_in_templates'             => 'error',

                // options/validate
                'orphaned_option'                        => 'warning',

                // naming/validate
                'wrong_template_prefix'                  => 'warning',
                'wrong_block_prefix'                     => 'warning',
                'deprecated_page_block_term'             => 'warning',

                // options/config/validate
                'wrong_option_slug'                      => 'warning',
                'wrong_option_position'                  => 'warning',
                'wrong_option_icon'                      => 'warning',

                // acf/validate/slugs
                'missing_number'                         => 'error',
                'wrong_opt_format'                       => 'error',
                'wrong_cpt_format'                       => 'error',
                'wrong_page_format'                      => 'error',
                'wrong_cpt_format_multi'                 => 'observation',
                'wrong_page_format_multi'                => 'observation',

                // theme/check
                'wrong_active_theme'                     => 'critical',
                'impreza_license_inactive'               => 'critical',
                'unauthorized_installed_theme'           => 'warning',
                'theme_recaptcha_site_key_missing'       => 'critical',
                'theme_recaptcha_secret_key_missing'     => 'critical',
                'search_engine_noindex'                  => 'critical',
                'missing_favicon'                        => 'warning',
                'permalink_structure_invalid'            => 'critical',
                'posts_per_page_invalid'                 => 'warning',
                'posts_per_rss_invalid'                  => 'warning',
                'homepage_on_latest_posts'               => 'critical',
                'homepage_missing'                       => 'critical',
                'homepage_unexpected_title'              => 'observation',
                'date_format_invalid'                    => 'warning',
                'timezone_invalid'                       => 'warning',
                'site_language_invalid'                  => 'warning',
                'start_of_week_invalid'                  => 'warning',
                'default_role_invalid'                   => 'warning',
                'users_can_register_enabled'             => 'warning',
                'admin_email_invalid'                    => 'warning',
                'php_version_critical'                   => 'critical',
                'php_version_outdated'                   => 'warning',
                'php_memory_limit_low'                   => 'warning',
                'orphaned_wpforms_scheduled_actions'     => 'warning',
                'scroll_breakpoint_not_1px'              => 'observation',
                'scroll_breakpoint_inconsistent'         => 'observation',
                'centering_missing'                      => 'warning',
                'centering_unexpected'                   => 'warning',
                'header_element_unused'                  => 'observation',
                'menu_mobile_behavior_not_label_and_arrow' => 'warning',
                'menu_mobile_icon_size_too_large'        => 'warning',
                'menu_mobile_icon_size_inconsistent'     => 'warning',
                'menu_align_edges_mismatch'              => 'warning',

                // cache/validate (WP Fastest Cache)
                'cache_disabled'                         => 'critical',
                'cache_preload_disabled'                 => 'warning',
                'cache_preload_homepage_missing'         => 'warning',
                'cache_preload_post_missing'             => 'warning',
                'cache_preload_page_missing'             => 'warning',
                'cache_preload_cpt_missing'              => 'warning',
                'cache_preload_threads_missing'          => 'warning',
                'cache_preload_restart_missing'          => 'critical',
                'cache_purge_on_new_post_missing'        => 'critical',
                'cache_purge_on_update_post_missing'     => 'critical',
                'cache_minify_html_disabled'             => 'warning',
                'cache_minify_css_disabled'              => 'warning',
                'cache_combine_css_disabled'             => 'warning',
                'cache_minify_js_enabled'                => 'critical',
                'cache_combine_js_enabled'               => 'critical',
                'cache_gzip_disabled'                    => 'warning',
                'cache_browser_caching_disabled'         => 'critical',
                'cache_emojis_enabled'                   => 'warning',
                'cache_mobile_theme_enabled'             => 'warning',
                'cache_logged_in_user_enabled'           => 'warning',
                'cache_timeout_missing'                  => 'critical',
                'cache_timeout_not_daily'                => 'warning',
                'cache_timeout_scope_partial'            => 'warning',
                'cache_language_not_english'             => 'warning',
                'cache_toolbar_admin_only_missing'       => 'critical',
            ];

            // ── Per-categorie caps ────────────────────────────────────────
            $category_caps = [
                'wpb'        => 15,
                'grid'       => 15,
                'colors'     => 10,
                'forms'      => 10,
                'plugins'    => 10,
                'cpt'        => 10,
                'db_tables'  =>  5,
                'css'        =>  5,
                'nav'        =>  5,
                'wpb_modules'      =>  10,
                'theme_breakpoints'=>   5,
                'widgets'          =>  10,
                'wpb_templates'    =>   5,
                'taxonomy'         =>   5,
                'header_config'    =>   5,
                'acf_fields'       =>  10,
                'meta_orphaned'    =>   5,
                'options_orphaned' =>   5,
                'naming'           =>   5,
                'options_config'   =>   5,
                'acf_slugs'        =>  10,
                'acf_locations'    =>   5,
                'theme_check'      =>   5,
                'wp_settings'      =>  10,
                'cache'            =>   5,
            ];

            $severity_points = [
                'critical'    => 3,
                'error'       => 2,
                'warning'     => 1,
                'observation' => 0,
            ];

            // ── Helper: interne REST call ─────────────────────────────────
            $call = function ( string $route, array $params = [] ): array {
                $request = new WP_REST_Request( 'GET', '/aspera/v1/' . $route );
                foreach ( $params as $k => $v ) {
                    $request->set_param( $k, $v );
                }
                // Bypass auth — we zijn al geauthenticeerd
                $request->set_param( 'aspera_key', aspera_get_secret_key() );
                $response = rest_do_request( $request );
                if ( $response->is_error() ) {
                    $error = $response->as_error();
                    return [ '_error' => $error->get_error_message() ];
                }
                return $response->get_data();
            };

            // ── Endpoints aanroepen ───────────────────────────────────────
            $categories = [];

            // 1. WPB validate (alle pagina's, max 100 per keer)
            $wpb = $call( 'wpb/validate/all', [ 'per_page' => 100, 'post_types' => 'us_content_template,us_page_block,page' ] );
            $wpb_violations = [];
            if ( ! isset( $wpb['_error'] ) ) {
                foreach ( $wpb['violations'] ?? [] as $v ) {
                    $rule     = $v['rule'] ?? 'unknown';
                    $sev      = $severity_map[ $rule ] ?? 'warning';
                    $entry = [
                        'rule'     => $rule,
                        'severity' => $sev,
                        'post_id'  => $v['post_id'] ?? null,
                        'detail'   => $v['detail'] ?? $v['snippet'] ?? '',
                    ];
                    if ( isset( $v['location'] ) )     $entry['location']     = $v['location'];
                    if ( isset( $v['proposed_fix'] ) ) $entry['proposed_fix'] = $v['proposed_fix'];
                    $wpb_violations[] = $entry;
                }
                // Paginering: als er meer pagina's zijn, ophalen
                $total_pages = $wpb['total_pages'] ?? 1;
                for ( $p = 2; $p <= $total_pages; $p++ ) {
                    $extra = $call( 'wpb/validate/all', [ 'per_page' => 100, 'page' => $p, 'post_types' => 'us_content_template,us_page_block,page' ] );
                    foreach ( $extra['violations'] ?? [] as $v ) {
                        $rule     = $v['rule'] ?? 'unknown';
                        $sev      = $severity_map[ $rule ] ?? 'warning';
                        $entry = [
                            'rule'     => $rule,
                            'severity' => $sev,
                            'post_id'  => $v['post_id'] ?? null,
                            'detail'   => $v['detail'] ?? $v['snippet'] ?? '',
                        ];
                        if ( isset( $v['location'] ) )     $entry['location']     = $v['location'];
                        if ( isset( $v['proposed_fix'] ) ) $entry['proposed_fix'] = $v['proposed_fix'];
                        $wpb_violations[] = $entry;
                    }
                }
            }
            $categories['wpb'] = [
                'violation_count' => count( $wpb_violations ),
                'violations'      => $wpb_violations,
                'error'           => $wpb['_error'] ?? null,
            ];

            // 2. Grid validate
            $grid = $call( 'grid/validate' );
            $grid_violations = [];
            if ( ! isset( $grid['_error'] ) ) {
                foreach ( $grid['grids'] ?? [] as $g ) {
                    foreach ( $g['violations'] ?? [] as $v ) {
                        $rule = $v['rule'] ?? 'unknown';
                        $sev  = $severity_map[ $rule ] ?? 'warning';
                        $grid_violations[] = [
                            'rule'     => $rule,
                            'severity' => $sev,
                            'post_id'  => $g['post_id'] ?? null,
                            'detail'   => $v['detail'] ?? '',
                        ];
                    }
                }
            }
            $categories['grid'] = [
                'violation_count' => count( $grid_violations ),
                'violations'      => $grid_violations,
                'error'           => $grid['_error'] ?? null,
            ];

            // 3. Colors validate
            $colors = $call( 'colors/validate' );
            $color_violations = [];
            if ( ! isset( $colors['_error'] ) ) {
                foreach ( $colors['post_violations'] ?? [] as $v ) {
                    $rule = $v['rule'] ?? 'unknown';
                    $sev  = $severity_map[ $rule ] ?? 'warning';
                    $color_violations[] = [
                        'rule'     => $rule,
                        'severity' => $sev,
                        'post_id'  => $v['post_id'] ?? null,
                        'detail'   => $v['detail'] ?? '',
                    ];
                }
                foreach ( $colors['theme_violations'] ?? [] as $v ) {
                    $rule = $v['rule'] ?? 'unknown';
                    $sev  = $severity_map[ $rule ] ?? 'warning';
                    $color_violations[] = [
                        'rule'     => $rule,
                        'severity' => $sev,
                        'detail'   => $v['detail'] ?? '',
                    ];
                }
            }
            $categories['colors'] = [
                'violation_count' => count( $color_violations ),
                'violations'      => $color_violations,
                'error'           => $colors['_error'] ?? null,
            ];

            // 4. Forms validate
            $forms = $call( 'forms/validate' );
            $form_violations = [];
            if ( ! isset( $forms['_error'] ) ) {
                // Site-wide violations (cform_inbound_disabled)
                foreach ( $forms['site_violations'] ?? [] as $v ) {
                    $rule = $v['rule'] ?? 'unknown';
                    $sev  = $severity_map[ $rule ] ?? 'warning';
                    $form_violations[] = [
                        'rule'     => $rule,
                        'severity' => $sev,
                        'detail'   => $v['detail'] ?? '',
                    ];
                }
                foreach ( $forms['forms'] ?? [] as $f ) {
                    foreach ( $f['violations'] ?? [] as $v ) {
                        $rule = $v['rule'] ?? 'unknown';
                        $sev  = $severity_map[ $rule ] ?? 'warning';
                        $form_violations[] = [
                            'rule'     => $rule,
                            'severity' => $sev,
                            'post_id'  => $f['post_id'] ?? null,
                            'detail'   => $v['detail'] ?? '',
                        ];
                    }
                }
            }
            $categories['forms'] = [
                'violation_count' => count( $form_violations ),
                'violations'      => $form_violations,
                'error'           => $forms['_error'] ?? null,
            ];

            // 6. Plugins validate (whitelist: alleen extra plugins flaggen)
            $plugins = $call( 'plugins/validate' );
            $plugin_violations = [];
            if ( ! isset( $plugins['_error'] ) ) {
                foreach ( $plugins['extra_plugins'] ?? [] as $p ) {
                    $plugin_violations[] = [
                        'rule'     => 'extra_plugin',
                        'severity' => 'observation',
                        'detail'   => $p['name'] ?? $p['slug'] ?? '',
                    ];
                }
            }
            $categories['plugins'] = [
                'violation_count' => count( $plugin_violations ),
                'violations'      => $plugin_violations,
                'error'           => $plugins['_error'] ?? null,
            ];

            // 7. CPT validate
            $cpt = $call( 'cpt/validate' );
            $cpt_violations = [];
            if ( ! isset( $cpt['_error'] ) ) {
                foreach ( $cpt['violations'] ?? [] as $v ) {
                    $rule = $v['rule'] ?? 'unknown';
                    $sev  = $severity_map[ $rule ] ?? 'warning';
                    $cpt_violations[] = [
                        'rule'     => $rule,
                        'severity' => $sev,
                        'detail'   => $v['detail'] ?? '',
                    ];
                }
                if ( ! empty( $cpt['cptui_leftover'] ) ) {
                    $cpt_violations[] = [
                        'rule'     => 'cptui_leftover',
                        'severity' => 'warning',
                        'detail'   => 'CPTUI data aanwezig in wp_options terwijl ACF leidend is',
                    ];
                }
            }
            $categories['cpt'] = [
                'violation_count' => count( $cpt_violations ),
                'violations'      => $cpt_violations,
                'error'           => $cpt['_error'] ?? null,
            ];

            // 8. DB tables validate
            $db = $call( 'db/tables/validate' );
            $db_violations = [];
            if ( ! isset( $db['_error'] ) ) {
                foreach ( $db['orphaned_tables'] ?? [] as $t ) {
                    $db_violations[] = [
                        'rule'     => 'orphaned_table',
                        'severity' => 'warning',
                        'detail'   => $t['table'] . ' (' . ( $t['plugin'] ?? 'onbekend' ) . ')',
                    ];
                }
                foreach ( $db['unknown_tables'] ?? [] as $t ) {
                    $db_violations[] = [
                        'rule'     => 'unknown_table',
                        'severity' => 'observation',
                        'detail'   => $t['table'],
                    ];
                }
                foreach ( $db['orphaned_post_types'] ?? [] as $pt ) {
                    $db_violations[] = [
                        'rule'     => 'orphaned_post_type',
                        'severity' => 'warning',
                        'detail'   => $pt['post_type'] . ' (' . $pt['plugin'] . ', ' . $pt['count'] . ' posts)',
                    ];
                }
                foreach ( $db['orphaned_options'] ?? [] as $oo ) {
                    $db_violations[] = [
                        'rule'     => 'orphaned_plugin_options',
                        'severity' => 'warning',
                        'detail'   => $oo['prefix'] . '* (' . $oo['plugin'] . ', ' . $oo['count'] . ' rijen)',
                    ];
                }
                foreach ( $db['orphaned_meta'] ?? [] as $om ) {
                    $db_violations[] = [
                        'rule'     => 'orphaned_plugin_meta',
                        'severity' => 'warning',
                        'detail'   => $om['prefix'] . '* (' . $om['plugin'] . ', ' . $om['count'] . ' rijen)',
                    ];
                }
            }
            $categories['db_tables'] = [
                'violation_count' => count( $db_violations ),
                'violations'      => $db_violations,
                'error'           => $db['_error'] ?? null,
            ];

            // 9. CSS unused + prefix check
            $css = $call( 'css/unused' );
            $css_violations = [];
            if ( ! isset( $css['_error'] ) ) {
                foreach ( $css['unused'] ?? [] as $u ) {
                    $css_violations[] = [
                        'rule'     => 'unused_css_class',
                        'severity' => $u['severity'] ?? 'warning',
                        'detail'   => '.' . $u['class'] . ' (regel ' . ( $u['line'] ?? '?' ) . ')',
                    ];
                }
                foreach ( $css['wrong_prefix'] ?? [] as $wp ) {
                    $css_violations[] = [
                        'rule'     => 'wrong_css_prefix',
                        'severity' => $severity_map['wrong_css_prefix'] ?? 'warning',
                        'detail'   => $wp['detail'] ?? '',
                    ];
                }
            }
            $categories['css'] = [
                'violation_count' => count( $css_violations ),
                'violations'      => $css_violations,
                'error'           => $css['_error'] ?? null,
            ];

            // 10. Nav menu validate
            $nav = $call( 'nav/validate' );
            $nav_violations = [];
            if ( ! isset( $nav['_error'] ) ) {
                foreach ( $nav['unused'] ?? [] as $u ) {
                    $nav_violations[] = [
                        'rule'     => 'unused_nav_menu',
                        'severity' => 'warning',
                        'detail'   => $u['detail'] ?? '',
                    ];
                }
                foreach ( $nav['broken_references'] ?? [] as $b ) {
                    $nav_violations[] = [
                        'rule'     => 'broken_menu_reference',
                        'severity' => 'error',
                        'post_id'  => $b['post_id'] ?? null,
                        'detail'   => $b['detail'] ?? '',
                    ];
                }
                foreach ( $nav['naming_issues'] ?? [] as $n ) {
                    $rule = $n['rule'] ?? 'invalid_menu_name';
                    $nav_violations[] = [
                        'rule'     => $rule,
                        'severity' => $severity_map[ $rule ] ?? 'warning',
                        'detail'   => $n['detail'] ?? '',
                    ];
                }
                foreach ( $nav['link_issues'] ?? [] as $l ) {
                    $nav_violations[] = [
                        'rule'     => 'external_link_no_target_blank',
                        'severity' => 'warning',
                        'detail'   => $l['detail'] ?? '',
                    ];
                }
                foreach ( $nav['page_issues'] ?? [] as $p ) {
                    $rule = $p['rule'] ?? 'page_not_in_menu';
                    $nav_violations[] = [
                        'rule'     => $rule,
                        'severity' => $severity_map[ $rule ] ?? 'observation',
                        'detail'   => $p['detail'] ?? '',
                    ];
                }
            }
            $categories['nav'] = [
                'violation_count' => count( $nav_violations ),
                'violations'      => $nav_violations,
                'error'           => $nav['_error'] ?? null,
            ];

            // 11. WPB modules validate
            $wpb_mod = $call( 'wpb/modules/validate' );
            $wpb_mod_violations = [];
            if ( ! isset( $wpb_mod['_error'] ) ) {
                foreach ( $wpb_mod['violations'] ?? [] as $v ) {
                    $rule = $v['rule'] ?? 'wpb_module_active';
                    $wpb_mod_violations[] = [
                        'rule'     => $rule,
                        'severity' => $severity_map[ $rule ] ?? 'critical',
                        'post_id'  => $v['post_id'] ?? null,
                        'detail'   => $v['detail'] ?? '',
                    ];
                }
            }
            $categories['wpb_modules'] = [
                'violation_count' => count( $wpb_mod_violations ),
                'violations'      => $wpb_mod_violations,
                'error'           => $wpb_mod['_error'] ?? null,
            ];

            // 12. Theme breakpoints
            $theme_bp = $call( 'theme/breakpoints' );
            $theme_bp_violations = [];
            if ( ! isset( $theme_bp['_error'] ) ) {
                foreach ( $theme_bp['issues'] ?? [] as $v ) {
                    $rule = $v['rule'] ?? 'unknown';
                    $sev  = $severity_map[ $rule ] ?? 'warning';
                    $theme_bp_violations[] = [
                        'rule'     => $rule,
                        'severity' => $sev,
                        'detail'   => $v['detail'] ?? '',
                    ];
                }
            }
            $categories['theme_breakpoints'] = [
                'violation_count' => count( $theme_bp_violations ),
                'violations'      => $theme_bp_violations,
                'error'           => $theme_bp['_error'] ?? null,
            ];

            // 13. Widgets validate
            $widgets = $call( 'widgets/validate' );
            $widget_violations = [];
            if ( ! isset( $widgets['_error'] ) ) {
                foreach ( $widgets['violations'] ?? [] as $v ) {
                    $rule = $v['rule'] ?? 'unknown';
                    $sev  = $severity_map[ $rule ] ?? 'error';
                    $widget_violations[] = [
                        'rule'     => $rule,
                        'severity' => $sev,
                        'post_id'  => $v['post_id'] ?? null,
                        'detail'   => $v['detail'] ?? '',
                    ];
                }
            }
            $categories['widgets'] = [
                'violation_count' => count( $widget_violations ),
                'violations'      => $widget_violations,
                'error'           => $widgets['_error'] ?? null,
            ];

            // 14. WPB templates validate
            $wpb_tpl = $call( 'wpb/templates/validate' );
            $wpb_tpl_violations = [];
            if ( ! isset( $wpb_tpl['_error'] ) ) {
                foreach ( $wpb_tpl['violations'] ?? [] as $v ) {
                    $rule = $v['rule'] ?? 'unknown';
                    $sev  = $severity_map[ $rule ] ?? 'warning';
                    $wpb_tpl_violations[] = [
                        'rule'     => $rule,
                        'severity' => $sev,
                        'detail'   => $v['detail'] ?? '',
                    ];
                }
            }
            $categories['wpb_templates'] = [
                'violation_count' => count( $wpb_tpl_violations ),
                'violations'      => $wpb_tpl_violations,
                'error'           => $wpb_tpl['_error'] ?? null,
            ];

            // 15. Taxonomy validate
            $tax = $call( 'taxonomy/validate' );
            $tax_violations = [];
            if ( ! isset( $tax['_error'] ) ) {
                foreach ( $tax['violations'] ?? [] as $v ) {
                    $rule = $v['rule'] ?? 'unknown';
                    $sev  = $severity_map[ $rule ] ?? 'warning';
                    $tax_violations[] = [
                        'rule'     => $rule,
                        'severity' => $sev,
                        'detail'   => $v['detail'] ?? '',
                    ];
                }
            }
            $categories['taxonomy'] = [
                'violation_count' => count( $tax_violations ),
                'violations'      => $tax_violations,
                'error'           => $tax['_error'] ?? null,
            ];

            // 16. Header config validate
            $hdr_val        = $call( 'header/validate' );
            $hdr_violations = [];
            if ( ! isset( $hdr_val['_error'] ) ) {
                foreach ( array_merge( $hdr_val['violations'] ?? [], $hdr_val['observations'] ?? [] ) as $v ) {
                    $rule = $v['rule'] ?? 'unknown';
                    $sev  = $severity_map[ $rule ] ?? 'observation';
                    $hdr_violations[] = [
                        'rule'     => $rule,
                        'severity' => $sev,
                        'post_id'  => $v['post_id'] ?? null,
                        'detail'   => $v['detail'] ?? '',
                    ];
                }
            }
            $categories['header_config'] = [
                'violation_count' => count( $hdr_violations ),
                'violations'      => $hdr_violations,
                'error'           => $hdr_val['_error'] ?? null,
            ];

            // 17. ACF field group validate (alle groups)
            $acf_val              = $call( 'acf/validate/all' );
            $acf_field_violations = [];
            if ( ! isset( $acf_val['_error'] ) ) {
                foreach ( $acf_val['violations'] ?? [] as $v ) {
                    $rule = $v['rule'] ?? 'unknown';
                    $sev  = $severity_map[ $rule ] ?? 'warning';
                    $acf_field_violations[] = [
                        'rule'     => $rule,
                        'severity' => $sev,
                        'post_id'  => $v['post_id'] ?? null,
                        'detail'   => $v['detail'] ?? '',
                    ];
                }
            }
            $categories['acf_fields'] = [
                'violation_count' => count( $acf_field_violations ),
                'violations'      => $acf_field_violations,
                'error'           => $acf_val['_error'] ?? null,
            ];

            // 18. Orphaned ACF meta
            $meta_val        = $call( 'meta/validate' );
            $meta_violations = [];
            if ( ! isset( $meta_val['_error'] ) ) {
                foreach ( $meta_val['orphaned'] ?? [] as $m ) {
                    $rule = $m['in_templates'] ? 'orphaned_meta_in_templates' : 'orphaned_meta';
                    $sev  = $severity_map[ $rule ] ?? 'warning';
                    $entry = [
                        'rule'     => $rule,
                        'severity' => $sev,
                        'detail'   => $m['meta_key'] . ' (' . $m['rows'] . ' rijen' . ( $m['in_templates'] ? ', gevonden in templates' : '' ) . ')',
                    ];
                    if ( ! $m['in_templates'] ) {
                        $entry['proposed_fix'] = [
                            'fixable'   => true,
                            'action'    => 'delete_orphaned_meta',
                            'meta_key'  => $m['meta_key'],
                            'rows'      => (int) $m['rows'],
                        ];
                    }
                    $meta_violations[] = $entry;
                }
            }
            $categories['meta_orphaned'] = [
                'violation_count' => count( $meta_violations ),
                'violations'      => $meta_violations,
                'error'           => $meta_val['_error'] ?? null,
            ];

            // 19. Orphaned option page data
            $opt_val        = $call( 'options/validate' );
            $opt_violations = [];
            if ( ! isset( $opt_val['_error'] ) ) {
                foreach ( $opt_val['orphaned'] ?? [] as $g ) {
                    $opt_violations[] = [
                        'rule'     => 'orphaned_option',
                        'severity' => $severity_map['orphaned_option'] ?? 'warning',
                        'detail'   => $g['prefix'] . ' (' . $g['keys'] . ' keys, ' . $g['total_rows'] . ' rijen'
                            . ( $g['option_page_active']
                                ? ', option page actief maar veld verwijderd'
                                : ', option page niet actief' )
                            . ')',
                    ];
                }
            }
            $categories['options_orphaned'] = [
                'violation_count' => count( $opt_violations ),
                'violations'      => $opt_violations,
                'error'           => $opt_val['_error'] ?? null,
            ];

            // ── 20. naming/validate ──────────────────────────────────────
            $naming_val = $call( 'naming/validate' );
            $naming_violations = [];
            if ( ! empty( $naming_val['violations'] ) ) {
                foreach ( $naming_val['violations'] as $nv ) {
                    $naming_violations[] = [
                        'rule'     => $nv['rule'],
                        'post_id'  => $nv['post_id'] ?? 0,
                        'severity' => $severity_map[ $nv['rule'] ] ?? 'warning',
                        'detail'   => $nv['detail'] ?? '',
                    ];
                }
            }
            $categories['naming'] = [
                'violation_count' => count( $naming_violations ),
                'violations'      => $naming_violations,
                'error'           => $naming_val['_error'] ?? null,
            ];

            // ── 21. options/config/validate ──────────────────────────────
            $optcfg_val = $call( 'options/config/validate' );
            $optcfg_violations = [];
            if ( ! empty( $optcfg_val['violations'] ) ) {
                foreach ( $optcfg_val['violations'] as $ov ) {
                    $optcfg_violations[] = [
                        'rule'     => $ov['rule'],
                        'post_id'  => $ov['post_id'] ?? 0,
                        'severity' => $severity_map[ $ov['rule'] ] ?? 'warning',
                        'detail'   => $ov['detail'] ?? '',
                    ];
                }
            }
            $categories['options_config'] = [
                'violation_count' => count( $optcfg_violations ),
                'violations'      => $optcfg_violations,
                'error'           => $optcfg_val['_error'] ?? null,
            ];

            // ── 22. acf/validate/slugs ───────────────────────────────────
            $slugs_val = $call( 'acf/validate/slugs' );
            $slugs_violations = [];
            if ( ! empty( $slugs_val['issues'] ) ) {
                foreach ( $slugs_val['issues'] as $sv ) {
                    $rule = $sv['rule'] ?? 'unknown';
                    $slugs_violations[] = [
                        'rule'     => $rule,
                        'post_id'  => $sv['field_group_id'] ?? 0,
                        'severity' => $severity_map[ $rule ] ?? 'warning',
                        'detail'   => ( $sv['field_group_title'] ?? '' ) . ': "' . ( $sv['field_slug'] ?? '' ) . '" — ' . ( $sv['detail'] ?? '' ),
                    ];
                }
            }
            $categories['acf_slugs'] = [
                'violation_count' => count( $slugs_violations ),
                'violations'      => $slugs_violations,
                'error'           => $slugs_val['_error'] ?? null,
            ];

            // ── 23. acf/validate/locations ────────────────────────────
            $loc_val = $call( 'acf/validate/locations' );
            $loc_violations = [];
            if ( ! empty( $loc_val['violations'] ) ) {
                foreach ( $loc_val['violations'] as $lv ) {
                    $rule = $lv['rule'] ?? 'unknown';
                    $entry = [
                        'rule'     => $rule,
                        'post_id'  => $lv['post_id'] ?? 0,
                        'severity' => $severity_map[ $rule ] ?? 'warning',
                        'detail'   => $lv['detail'] ?? '',
                    ];
                    if ( isset( $lv['redundant'] ) ) {
                        $entry['redundant']   = $lv['redundant'];
                        $entry['field_count'] = $lv['field_count'] ?? 0;
                        if ( ! empty( $lv['covered_by'] ) ) {
                            $entry['covered_by'] = $lv['covered_by'];
                        }
                    }
                    if ( isset( $lv['proposed_fix'] ) ) $entry['proposed_fix'] = $lv['proposed_fix'];
                    $loc_violations[] = $entry;
                }
            }
            $categories['acf_locations'] = [
                'violation_count' => count( $loc_violations ),
                'violations'      => $loc_violations,
                'error'           => $loc_val['_error'] ?? null,
            ];

            // ── 24. Theme check: Aspera child actief? ─────────────────
            $theme_violations = [];
            $stylesheet = get_stylesheet();
            $theme_name = wp_get_theme()->get( 'Name' );
            $is_aspera  = ( stripos( $stylesheet, 'aspera' ) !== false )
                       || ( stripos( $theme_name, 'aspera' ) !== false );
            if ( ! $is_aspera ) {
                $theme_violations[] = [
                    'rule'     => 'wrong_active_theme',
                    'severity' => 'critical',
                    'detail'   => 'Actief thema: ' . $theme_name . ' (' . $stylesheet . ')',
                ];
            }
            $license = get_option( 'us_license_activated' );
            if ( $license !== '1' && $license !== 1 ) {
                $theme_violations[] = [
                    'rule'     => 'impreza_license_inactive',
                    'severity' => 'critical',
                    'detail'   => 'Impreza licentie is niet geactiveerd',
                ];
            }
            // Geinstalleerde thema's: alleen aspera/impreza toegestaan
            $allowed_substrings = [ 'aspera', 'impreza' ];
            foreach ( wp_get_themes() as $installed_slug => $theme_obj ) {
                $installed_name = $theme_obj->get( 'Name' );
                $is_allowed = false;
                foreach ( $allowed_substrings as $needle ) {
                    if ( stripos( $installed_slug, $needle ) !== false || stripos( $installed_name, $needle ) !== false ) {
                        $is_allowed = true;
                        break;
                    }
                }
                if ( ! $is_allowed ) {
                    $theme_violations[] = [
                        'rule'     => 'unauthorized_installed_theme',
                        'severity' => 'warning',
                        'detail'   => 'Geinstalleerd thema "' . $installed_name . '" (' . $installed_slug . ') v' . $theme_obj->get( 'Version' ) . ' — niet toegestaan, verwijderen',
                    ];
                }
            }
            // reCAPTCHA-keys in Impreza theme — alleen checken als er een form met reCAPTCHA bestaat
            global $wpdb;
            $form_with_recaptcha_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_content LIKE '%us_cform%'
                   AND post_content LIKE '%reCAPTCHA%'"
            );
            if ( $form_with_recaptcha_count > 0 ) {
                $usof_raw     = get_option( 'usof_options_Impreza', [] );
                $usof_options = is_array( $usof_raw ) ? $usof_raw : ( is_string( $usof_raw ) ? maybe_unserialize( $usof_raw ) : [] );
                if ( ! is_array( $usof_options ) ) { $usof_options = []; }
                $site_key   = trim( (string) ( $usof_options['reCAPTCHA_site_key']   ?? '' ) );
                $secret_key = trim( (string) ( $usof_options['reCAPTCHA_secret_key'] ?? '' ) );
                if ( $site_key === '' ) {
                    $theme_violations[] = [
                        'rule'     => 'theme_recaptcha_site_key_missing',
                        'severity' => 'critical',
                        'detail'   => 'reCAPTCHA_site_key leeg in Impreza theme options (Theme Options > reCAPTCHA), terwijl ' . $form_with_recaptcha_count . ' formulier(en) reCAPTCHA gebruiken',
                    ];
                }
                if ( $secret_key === '' ) {
                    $theme_violations[] = [
                        'rule'     => 'theme_recaptcha_secret_key_missing',
                        'severity' => 'critical',
                        'detail'   => 'reCAPTCHA_secret_key leeg in Impreza theme options (Theme Options > reCAPTCHA), terwijl ' . $form_with_recaptcha_count . ' formulier(en) reCAPTCHA gebruiken',
                    ];
                }
            }
            $categories['theme_check'] = [
                'violation_count' => count( $theme_violations ),
                'violations'      => $theme_violations,
                'error'           => null,
            ];

            // ── 25. WP Settings ──────────────────────────────────────────
            $wp_settings_violations = [];
            if ( get_option( 'blog_public' ) === '0' ) {
                $wp_settings_violations[] = [
                    'rule'     => 'search_engine_noindex',
                    'severity' => 'critical',
                    'detail'   => 'Search engine visibility staat uit (Settings > Reading) — de site wordt niet geindexeerd door Google',
                ];
            }
            $site_icon_id = (int) get_option( 'site_icon', 0 );
            if ( $site_icon_id <= 0 || ! wp_attachment_is_image( $site_icon_id ) ) {
                $wp_settings_violations[] = [
                    'rule'     => 'missing_favicon',
                    'severity' => 'warning',
                    'detail'   => 'Geen favicon ingesteld (Settings > General > Site Icon)',
                ];
            }
            $permalink_structure = (string) get_option( 'permalink_structure', '' );
            $allowed_permalinks  = [ '/%postname%/', '/%category%/%postname%/' ];
            if ( ! in_array( $permalink_structure, $allowed_permalinks, true ) ) {
                $wp_settings_violations[] = [
                    'rule'     => 'permalink_structure_invalid',
                    'severity' => 'critical',
                    'detail'   => 'permalink_structure="' . $permalink_structure . '" (toegestaan: "/%postname%/" of "/%category%/%postname%/")',
                ];
            }
            $posts_per_page = (int) get_option( 'posts_per_page', 0 );
            if ( $posts_per_page !== 12 ) {
                $wp_settings_violations[] = [
                    'rule'     => 'posts_per_page_invalid',
                    'severity' => 'warning',
                    'detail'   => 'posts_per_page=' . $posts_per_page . ' (verwacht: 12)',
                ];
            }
            $posts_per_rss = (int) get_option( 'posts_per_rss', 0 );
            if ( $posts_per_rss !== 12 ) {
                $wp_settings_violations[] = [
                    'rule'     => 'posts_per_rss_invalid',
                    'severity' => 'warning',
                    'detail'   => 'posts_per_rss=' . $posts_per_rss . ' (verwacht: 12)',
                ];
            }
            $date_format_val = (string) get_option( 'date_format', '' );
            if ( $date_format_val !== 'j F Y' ) {
                $wp_settings_violations[] = [
                    'rule'     => 'date_format_invalid',
                    'severity' => 'warning',
                    'detail'   => 'date_format="' . $date_format_val . '" (verwacht: "j F Y")',
                ];
            }
            $timezone_val = (string) get_option( 'timezone_string', '' );
            if ( $timezone_val !== 'Europe/Amsterdam' ) {
                $wp_settings_violations[] = [
                    'rule'     => 'timezone_invalid',
                    'severity' => 'warning',
                    'detail'   => 'timezone_string="' . $timezone_val . '" (verwacht: "Europe/Amsterdam")',
                ];
            }
            $locale_val = function_exists( 'get_locale' ) ? get_locale() : (string) get_option( 'WPLANG', '' );
            if ( ! in_array( $locale_val, [ 'nl_NL', 'en_US', 'en_GB' ], true ) ) {
                $wp_settings_violations[] = [
                    'rule'     => 'site_language_invalid',
                    'severity' => 'warning',
                    'detail'   => 'site_language="' . $locale_val . '" (toegestaan: nl_NL, en_US, en_GB)',
                ];
            }
            $start_of_week_val = (int) get_option( 'start_of_week', -1 );
            if ( $start_of_week_val !== 1 ) {
                $wp_settings_violations[] = [
                    'rule'     => 'start_of_week_invalid',
                    'severity' => 'warning',
                    'detail'   => 'start_of_week=' . $start_of_week_val . ' (verwacht: 1 / maandag)',
                ];
            }
            $default_role_val = (string) get_option( 'default_role', '' );
            if ( $default_role_val !== 'subscriber' ) {
                $wp_settings_violations[] = [
                    'rule'     => 'default_role_invalid',
                    'severity' => 'warning',
                    'detail'   => 'default_role="' . $default_role_val . '" (verwacht: "subscriber")',
                ];
            }
            $users_can_register_val = (string) get_option( 'users_can_register', '0' );
            if ( $users_can_register_val !== '0' && $users_can_register_val !== 0 ) {
                $wp_settings_violations[] = [
                    'rule'     => 'users_can_register_enabled',
                    'severity' => 'warning',
                    'detail'   => 'users_can_register="' . $users_can_register_val . '" (verwacht: 0 / membership uit)',
                ];
            }
            $admin_email_val = (string) get_option( 'admin_email', '' );
            if ( $admin_email_val !== 'wp@asperagrafica.nl' ) {
                $wp_settings_violations[] = [
                    'rule'     => 'admin_email_invalid',
                    'severity' => 'warning',
                    'detail'   => 'admin_email="' . $admin_email_val . '" (verwacht: "wp@asperagrafica.nl")',
                ];
            }
            $php_version = PHP_VERSION;
            if ( version_compare( $php_version, '8.0', '<' ) ) {
                $wp_settings_violations[] = [
                    'rule'     => 'php_version_critical',
                    'severity' => 'critical',
                    'detail'   => 'PHP versie ' . $php_version . ' (minimaal 8.0 vereist, voorkeur 8.4)',
                ];
            } elseif ( version_compare( $php_version, '8.4', '<' ) ) {
                $wp_settings_violations[] = [
                    'rule'     => 'php_version_outdated',
                    'severity' => 'warning',
                    'detail'   => 'PHP versie ' . $php_version . ' (minimaal 8.4 aanbevolen)',
                ];
            }
            $memory_limit_raw   = ini_get( 'memory_limit' );
            $memory_limit_bytes = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( $memory_limit_raw ) : (int) $memory_limit_raw;
            if ( $memory_limit_bytes > 0 && $memory_limit_bytes < 128 * 1024 * 1024 ) {
                $wp_settings_violations[] = [
                    'rule'     => 'php_memory_limit_low',
                    'severity' => 'warning',
                    'detail'   => 'memory_limit=' . $memory_limit_raw . ' (minimaal 128M vereist)',
                ];
            }
            // Orphaned WPForms scheduled actions: alleen als WPForms inactief
            $wpforms_active_check = false;
            foreach ( (array) get_option( 'active_plugins', [] ) as $ap ) {
                if ( stripos( $ap, 'wpforms' ) !== false ) { $wpforms_active_check = true; break; }
            }
            if ( ! $wpforms_active_check ) {
                global $wpdb;
                $as_table_name = $wpdb->prefix . 'actionscheduler_actions';
                $as_exists     = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $as_table_name ) );
                if ( $as_exists ) {
                    $wpforms_action_count = (int) $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$as_table_name} WHERE hook LIKE 'wpforms%'"
                    );
                    if ( $wpforms_action_count > 0 ) {
                        $wp_settings_violations[] = [
                            'rule'         => 'orphaned_wpforms_scheduled_actions',
                            'severity'     => 'warning',
                            'detail'       => $wpforms_action_count . ' WPForms scheduled action(s) gevonden terwijl WPForms inactief is — kunnen verwijderd worden',
                            'proposed_fix' => [
                                'fixable' => true,
                                'action'  => 'delete_wpforms_scheduled_actions',
                                'count'   => $wpforms_action_count,
                            ],
                        ];
                    }
                }
            }
            $show_on_front  = (string) get_option( 'show_on_front', 'posts' );
            $page_on_front  = (int) get_option( 'page_on_front', 0 );
            if ( $show_on_front !== 'page' ) {
                $wp_settings_violations[] = [
                    'rule'     => 'homepage_on_latest_posts',
                    'severity' => 'critical',
                    'detail'   => 'show_on_front="' . $show_on_front . '" (verwacht: "page" / static homepage)',
                ];
            } else {
                $front_post = $page_on_front > 0 ? get_post( $page_on_front ) : null;
                if ( ! $front_post || $front_post->post_status !== 'publish' ) {
                    $wp_settings_violations[] = [
                        'rule'     => 'homepage_missing',
                        'severity' => 'critical',
                        'detail'   => 'show_on_front="page" maar page_on_front=' . $page_on_front . ( $front_post ? ' (status=' . $front_post->post_status . ')' : ' (pagina bestaat niet)' ),
                    ];
                } else {
                    $title_lc = strtolower( trim( $front_post->post_title ) );
                    if ( ! in_array( $title_lc, [ 'home', 'homepage' ], true ) ) {
                        $wp_settings_violations[] = [
                            'rule'     => 'homepage_unexpected_title',
                            'severity' => 'observation',
                            'detail'   => 'Homepage-pagina (ID ' . $page_on_front . ') heeft titel "' . $front_post->post_title . '" (verwacht: "Home" of "Homepage")',
                        ];
                    }
                }
            }
            $categories['wp_settings'] = [
                'violation_count' => count( $wp_settings_violations ),
                'violations'      => $wp_settings_violations,
                'error'           => null,
            ];

            // ── Cache validate (WP Fastest Cache) ─────────────────────────
            $cache = $call( 'cache/validate' );
            $cache_violations = [];
            if ( ! isset( $cache['_error'] ) ) {
                foreach ( $cache['violations'] ?? [] as $v ) {
                    $rule = $v['rule'] ?? 'unknown';
                    $cache_violations[] = [
                        'rule'     => $rule,
                        'severity' => $severity_map[ $rule ] ?? 'warning',
                        'detail'   => $v['detail'] ?? '',
                    ];
                }
            }
            $categories['cache'] = [
                'violation_count' => count( $cache_violations ),
                'violations'      => $cache_violations,
                'error'           => $cache['_error'] ?? null,
            ];

            // ── Uitzonderingen laden en markeren ─────────────────────────
            $exc_raw   = get_option( 'aspera_audit_exceptions', [] );
            $exc_index = [];
            if ( is_array( $exc_raw ) ) {
                foreach ( $exc_raw as $e ) {
                    $exc_index[ $e['id'] ] = true;
                }
            }
            // ── Migratie exception hashes v1 → v2 (detail in hash) ──────
            if ( ! empty( $exc_raw ) && (int) get_option( 'aspera_exception_hash_version', 1 ) < 2 ) {
                $old_to_new = [];
                foreach ( $categories as $ck => $cd ) {
                    foreach ( $cd['violations'] as $v ) {
                        $pid      = (int) ( $v['post_id'] ?? 0 );
                        $old_hash = md5( $ck . '|' . ( $v['rule'] ?? '' ) . '|' . $pid );
                        $new_hash = md5( $ck . '|' . ( $v['rule'] ?? '' ) . '|' . $pid . '|' . ( $v['detail'] ?? '' ) );
                        if ( $old_hash !== $new_hash ) {
                            $old_to_new[ $old_hash ][ $new_hash ] = true;
                        }
                    }
                }
                $new_exc = [];
                $seen    = [];
                foreach ( $exc_raw as $e ) {
                    if ( isset( $old_to_new[ $e['id'] ] ) ) {
                        foreach ( array_keys( $old_to_new[ $e['id'] ] ) as $nh ) {
                            if ( ! isset( $seen[ $nh ] ) ) {
                                $new_exc[]   = [ 'id' => $nh ];
                                $seen[ $nh ] = true;
                            }
                        }
                    } elseif ( ! isset( $seen[ $e['id'] ] ) ) {
                        $new_exc[]           = $e;
                        $seen[ $e['id'] ]    = true;
                    }
                }
                update_option( 'aspera_audit_exceptions', $new_exc );
                update_option( 'aspera_exception_hash_version', 2 );
                $exc_raw   = $new_exc;
                $exc_index = [];
                foreach ( $exc_raw as $e ) {
                    $exc_index[ $e['id'] ] = true;
                }
            }

            // Voeg exception_id toe aan elke violation; markeer genegeerde
            foreach ( $categories as $cat_key => &$cat_ref ) {
                $active = 0;
                foreach ( $cat_ref['violations'] as &$vref ) {
                    $pid  = (int) ( $vref['post_id'] ?? 0 );
                    $eid  = md5( $cat_key . '|' . ( $vref['rule'] ?? '' ) . '|' . $pid . '|' . ( $vref['detail'] ?? '' ) );
                    $vref['exception_id'] = $eid;
                    if ( isset( $exc_index[ $eid ] ) ) {
                        $vref['is_excepted'] = true;
                    } else {
                        $active++;
                    }
                }
                unset( $vref );
                $cat_ref['violation_count'] = $active;
            }
            unset( $cat_ref );

            // ── Health score berekenen ────────────────────────────────────
            $total_deductions = 0;
            $category_scores  = [];

            foreach ( $categories as $cat_key => $cat_data ) {
                if ( ! empty( $cat_data['error'] ) ) {
                    // Endpoint faalde — tel als cap (worst case)
                    $cap        = $category_caps[ $cat_key ] ?? 10;
                    $deductions = $cap;
                } else {
                    $deductions = 0;
                    foreach ( $cat_data['violations'] as $v ) {
                        if ( $v['is_excepted'] ?? false ) continue; // genegeerd — niet aftrekken
                        $sev = $v['severity'] ?? 'warning';
                        $deductions += $severity_points[ $sev ] ?? 1;
                    }
                    $cap        = $category_caps[ $cat_key ] ?? 10;
                    $deductions = min( $deductions, $cap );
                }

                $category_scores[ $cat_key ] = [
                    'deductions'      => $deductions,
                    'cap'             => $cap,
                    'violation_count' => $cat_data['violation_count'],
                ];

                $total_deductions += $deductions;
            }

            $health_score = max( 0, 100 - $total_deductions );

            // ── Stoplicht ─────────────────────────────────────────────────
            if ( $health_score >= 80 ) {
                $traffic_light = 'green';
            } elseif ( $health_score >= 50 ) {
                $traffic_light = 'yellow';
            } else {
                $traffic_light = 'red';
            }

            // ── Severity summary (alleen actieve violations) ──────────────
            $severity_counts = [ 'critical' => 0, 'error' => 0, 'warning' => 0, 'observation' => 0 ];
            foreach ( $categories as $cat_data ) {
                foreach ( $cat_data['violations'] as $v ) {
                    if ( $v['is_excepted'] ?? false ) continue;
                    $sev = $v['severity'] ?? 'warning';
                    if ( isset( $severity_counts[ $sev ] ) ) {
                        $severity_counts[ $sev ]++;
                    }
                }
            }

            $total_violations = array_sum( array_column( $categories, 'violation_count' ) );

            // ── Token-efficiënt: strip violations als alles ok ────────────
            $categories_output = [];
            foreach ( $categories as $cat_key => $cat_data ) {
                $entry = [
                    'status'          => $cat_data['violation_count'] === 0 && empty( $cat_data['error'] ) ? 'ok' : 'issues',
                    'violation_count' => $cat_data['violation_count'],
                    'deductions'      => $category_scores[ $cat_key ]['deductions'],
                ];
                if ( ! empty( $cat_data['error'] ) ) {
                    $entry['error'] = $cat_data['error'];
                }
                if ( ! empty( $cat_data['violations'] ) ) {
                    $entry['violations'] = $cat_data['violations'];
                }
                $categories_output[ $cat_key ] = $entry;
            }

            // ── Snapshot opslaan ──────────────────────────────────────────
            $audit_date = gmdate( 'c' );

            // Delta-engine: bewaar vorige summary in history voor we overschrijven (max 7 entries, FIFO).
            $prev_summary_raw = get_option( 'aspera_audit_summary' );
            if ( $prev_summary_raw ) {
                $prev_date    = get_option( 'aspera_audit_date' );
                $history      = get_option( 'aspera_audit_history', [] );
                if ( ! is_array( $history ) ) $history = [];
                $history[]    = [ 'date' => $prev_date, 'summary' => $prev_summary_raw ];
                if ( count( $history ) > 7 ) $history = array_slice( $history, -7 );
                update_option( 'aspera_audit_history', $history, false );
            }

            update_option( 'aspera_audit_score', $health_score, false );
            update_option( 'aspera_audit_date', $audit_date, false );
            update_option( 'aspera_audit_summary', wp_json_encode( [
                'score'           => $health_score,
                'traffic_light'   => $traffic_light,
                'total_violations'=> $total_violations,
                'severity_counts' => $severity_counts,
                'category_scores' => $category_scores,
            ] ), false );
            // Volledig snapshot met violations — gebruikt door dashboard widget
            update_option( 'aspera_audit_snapshot', wp_json_encode( $categories_output ), false );

            return [
                'health_score'     => $health_score,
                'traffic_light'    => $traffic_light,
                'total_violations' => $total_violations,
                'severity_counts'  => $severity_counts,
                'category_scores'  => $category_scores,
                'categories'       => $categories_output,
                'audit_date'       => $audit_date,
            ];
        },
    ] );

    // ── ACF Fields endpoints ─────────────────────────────────────────────────

    /**
     * Formatteert een ACF-veld naar compact JSON.
     */
    function aspera_format_acf_field( array $f ): array {
        $props_whitelist = [
            'key', 'name', 'label', 'type', 'instructions',
            'required', 'default_value', 'placeholder',
            'choices', 'conditional_logic', 'wrapper',
            'min', 'max', 'step', 'prepend', 'append',
            'min_val', 'max_val',
            'rows', 'maxlength', 'new_lines',
            'return_format', 'display_format',
            'post_type', 'taxonomy', 'field_type', 'allow_null',
            'multiple', 'ui', 'ajax',
            'layouts', 'button_label', 'min_rows', 'max_rows',
            'sub_fields',
            'media_upload', 'toolbar', 'tabs', 'delay',
            'library', 'mime_types', 'preview_size', 'min_width', 'max_width', 'min_height', 'max_height', 'min_size', 'max_size',
            'layout', 'collapsed',
        ];

        $out = [];
        foreach ( $props_whitelist as $prop ) {
            if ( ! isset( $f[ $prop ] ) ) continue;
            $val = $f[ $prop ];
            if ( $val === '' || $val === [] || $val === 0 || $val === false || $val === null ) continue;
            $out[ $prop ] = $val;
        }

        if ( ! empty( $f['sub_fields'] ) ) {
            $out['sub_fields'] = array_map( 'aspera_format_acf_field', $f['sub_fields'] );
        }
        if ( ! empty( $f['layouts'] ) ) {
            $out['layouts'] = array_map( function ( $layout ) {
                $l = [
                    'key'        => $layout['key'] ?? '',
                    'name'       => $layout['name'] ?? '',
                    'label'      => $layout['label'] ?? '',
                    'display'    => $layout['display'] ?? 'block',
                    'min'        => $layout['min'] ?? '',
                    'max'        => $layout['max'] ?? '',
                ];
                if ( ! empty( $layout['sub_fields'] ) ) {
                    $l['sub_fields'] = array_map( 'aspera_format_acf_field', $layout['sub_fields'] );
                }
                return array_filter( $l, function ( $v ) { return $v !== '' && $v !== []; } );
            }, $f['layouts'] );
        }

        $out['key']  = $f['key'] ?? '';
        $out['name'] = $f['name'] ?? '';
        $out['type'] = $f['type'] ?? '';

        return $out;
    }

    /**
     * GET /wp-json/aspera/v1/acf/fields
     * Geeft field groups met hun volledige configuratie en velden terug.
     * Optionele filters: ?post_type=casino_cpt  ?group_id=123
     */
    register_rest_route( 'aspera/v1', '/acf/fields', [
        'methods'             => 'GET',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
                return new WP_Error( 'acf_missing', 'ACF is niet actief.', [ 'status' => 500 ] );
            }

            $filter_post_type = $req->get_param( 'post_type' );
            $filter_group     = $req->get_param( 'group_id' );

            if ( $filter_group ) {
                $identifier = is_numeric( $filter_group ) ? (int) $filter_group : $filter_group;
                $group_obj  = acf_get_field_group( $identifier );
                if ( ! $group_obj ) {
                    return new WP_Error( 'not_found', 'Field group niet gevonden.', [ 'status' => 404 ] );
                }
                $groups = [ $group_obj ];
            } else {
                $groups = acf_get_field_groups();
            }

            $output = [];

            foreach ( $groups as $group ) {
                $location = $group['location'] ?? [];

                if ( $filter_post_type ) {
                    $matches = false;
                    foreach ( $location as $or_group ) {
                        foreach ( (array) $or_group as $rule ) {
                            if ( ( $rule['param'] ?? '' ) === 'post_type'
                                 && ( $rule['operator'] ?? '==' ) === '=='
                                 && $rule['value'] === $filter_post_type ) {
                                $matches = true;
                                break 2;
                            }
                        }
                    }
                    if ( ! $matches ) continue;
                }

                $fields = acf_get_fields( $group['key'] );

                $group_data = [
                    'id'              => $group['ID'],
                    'key'             => $group['key'],
                    'title'           => $group['title'],
                    'active'          => (bool) $group['active'],
                    'style'           => $group['style'] ?? 'default',
                    'position'        => $group['position'] ?? 'normal',
                    'label_placement' => $group['label_placement'] ?? 'top',
                    'location'        => $location,
                    'menu_order'      => $group['menu_order'] ?? 0,
                    'field_count'     => $fields ? count( $fields ) : 0,
                    'fields'          => $fields ? array_map( 'aspera_format_acf_field', $fields ) : [],
                ];

                $output[] = $group_data;
            }

            return [
                'group_count' => count( $output ),
                'groups'      => $output,
            ];
        },
    ] );

    /**
     * POST /wp-json/aspera/v1/acf/fields/update
     * Voegt velden toe of wijzigt bestaande velden in een field group.
     * Ondersteunt dry_run=true om wijzigingen te previeuwen zonder opslaan.
     *
     * Body JSON:
     * {
     *   "group_id": 123,
     *   "group_settings": { "title": "...", "label_placement": "left" },  // optioneel
     *   "fields": [
     *     { "key": "field_abc123", "label": "Nieuw label", "type": "text", "name": "slug_1" },
     *     { "name": "new_field_1", "label": "Nieuw veld", "type": "select", "choices": {"a":"A"} }
     *   ]
     * }
     */
    register_rest_route( 'aspera/v1', '/acf/fields/update', [
        'methods'             => 'POST',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_update_field' ) ) {
                return new WP_Error( 'acf_missing', 'ACF is niet actief.', [ 'status' => 500 ] );
            }

            $body     = $req->get_json_params();
            $group_id = (int) ( $body['group_id'] ?? 0 );
            $dry_run  = filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );

            if ( ! $group_id ) {
                return new WP_Error( 'missing_group', 'group_id is verplicht.', [ 'status' => 400 ] );
            }

            $group = acf_get_field_group( $group_id );
            if ( ! $group ) {
                return new WP_Error( 'not_found', 'Field group niet gevonden.', [ 'status' => 404 ] );
            }

            $results = [];

            // Group-level settings update
            $group_settings = $body['group_settings'] ?? null;
            if ( is_array( $group_settings ) && ! empty( $group_settings ) ) {
                $allowed_group_props = [ 'title', 'style', 'position', 'label_placement',
                                         'instruction_placement', 'active', 'menu_order', 'location' ];
                $changes = [];
                foreach ( $group_settings as $prop => $value ) {
                    if ( ! in_array( $prop, $allowed_group_props, true ) ) continue;
                    if ( isset( $group[ $prop ] ) && $group[ $prop ] === $value ) continue;
                    $changes[ $prop ] = [ 'from' => $group[ $prop ] ?? null, 'to' => $value ];
                    $group[ $prop ] = $value;
                }
                if ( ! empty( $changes ) ) {
                    if ( ! $dry_run ) {
                        acf_update_field_group( $group );
                    }
                    $results[] = [
                        'action'  => 'group_updated',
                        'changes' => $changes,
                    ];
                }
            }

            // Field-level updates
            $fields_input = $body['fields'] ?? [];
            if ( ! is_array( $fields_input ) ) {
                $fields_input = [];
            }

            $existing_fields = acf_get_fields( $group['key'] );
            $existing_by_key  = [];
            $existing_by_name = [];
            foreach ( $existing_fields as $ef ) {
                $existing_by_key[ $ef['key'] ]   = $ef;
                $existing_by_name[ $ef['name'] ] = $ef;
            }

            foreach ( $fields_input as $field_input ) {
                $field_key  = $field_input['key'] ?? '';
                $field_name = $field_input['name'] ?? '';

                // Zoek bestaand veld op key of name
                $existing = null;
                if ( $field_key && isset( $existing_by_key[ $field_key ] ) ) {
                    $existing = $existing_by_key[ $field_key ];
                } elseif ( $field_name && isset( $existing_by_name[ $field_name ] ) ) {
                    $existing = $existing_by_name[ $field_name ];
                }

                if ( $existing ) {
                    $changes = [];
                    foreach ( $field_input as $prop => $value ) {
                        if ( $prop === 'key' || $prop === 'ID' ) continue;
                        $old = $existing[ $prop ] ?? null;
                        if ( $old !== $value ) {
                            $changes[ $prop ] = [ 'from' => $old, 'to' => $value ];
                            $existing[ $prop ] = $value;
                        }
                    }

                    if ( ! empty( $changes ) ) {
                        if ( ! $dry_run ) {
                            acf_update_field( $existing );
                        }
                        $results[] = [
                            'action'  => 'field_updated',
                            'key'     => $existing['key'],
                            'name'    => $existing['name'],
                            'changes' => $changes,
                        ];
                    }
                } else {
                    // Nieuw veld
                    if ( empty( $field_input['name'] ) || empty( $field_input['type'] ) ) {
                        $results[] = [
                            'action' => 'skipped',
                            'reason' => 'Nieuw veld vereist name en type.',
                            'input'  => $field_input,
                        ];
                        continue;
                    }

                    $new_field = $field_input;
                    $new_field['parent'] = $group['key'];
                    if ( empty( $new_field['key'] ) ) {
                        $new_field['key'] = 'field_' . uniqid();
                    }

                    if ( ! $dry_run ) {
                        $saved = acf_update_field( $new_field );
                        $results[] = [
                            'action' => 'field_created',
                            'key'    => $saved['key'],
                            'name'   => $saved['name'],
                            'type'   => $saved['type'],
                        ];
                    } else {
                        $results[] = [
                            'action' => 'field_would_create',
                            'key'    => $new_field['key'],
                            'name'   => $new_field['name'],
                            'type'   => $new_field['type'],
                        ];
                    }
                }
            }

            return [
                'dry_run'    => $dry_run,
                'group_id'   => $group_id,
                'group_title'=> $group['title'],
                'actions'    => $results,
            ];
        },
    ] );

    /**
     * POST /wp-json/aspera/v1/acf/fields/clone
     * Dupliceert een field group met nieuwe keys en slug-prefix.
     *
     * Body JSON:
     * {
     *   "source_group_id": 123,
     *   "new_title": "CPT - nieuwe_cpt",
     *   "slug_find": "_cpt_vestiging_",
     *   "slug_replace": "_cpt_nieuwe_"
     * }
     */
    register_rest_route( 'aspera/v1', '/acf/fields/clone', [
        'methods'             => 'POST',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            if ( ! function_exists( 'acf_get_field_group' ) || ! function_exists( 'acf_update_field' ) ) {
                return new WP_Error( 'acf_missing', 'ACF is niet actief.', [ 'status' => 500 ] );
            }

            $body            = $req->get_json_params();
            $source_id       = (int) ( $body['source_group_id'] ?? 0 );
            $new_title       = $body['new_title'] ?? '';
            $slug_find       = $body['slug_find'] ?? '';
            $slug_replace    = $body['slug_replace'] ?? '';
            $dry_run         = filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );

            if ( ! $source_id || ! $new_title ) {
                return new WP_Error( 'missing_params', 'source_group_id en new_title zijn verplicht.', [ 'status' => 400 ] );
            }

            $source = acf_get_field_group( $source_id );
            if ( ! $source ) {
                return new WP_Error( 'not_found', 'Bron field group niet gevonden.', [ 'status' => 404 ] );
            }

            $source_fields = acf_get_fields( $source['key'] );
            if ( ! $source_fields ) {
                return new WP_Error( 'empty_group', 'Bron field group heeft geen velden.', [ 'status' => 400 ] );
            }

            // Key mapping: oud -> nieuw (voor conditional logic referenties)
            $key_map = [];

            // Genereer nieuwe keys voor alle velden (inclusief sub_fields recursief)
            $map_keys_recursive = function ( array $fields ) use ( &$map_keys_recursive, &$key_map ) {
                foreach ( $fields as $f ) {
                    $old_key = $f['key'] ?? '';
                    if ( $old_key ) {
                        $key_map[ $old_key ] = 'field_' . uniqid( '', true );
                    }
                    if ( ! empty( $f['sub_fields'] ) ) {
                        $map_keys_recursive( $f['sub_fields'] );
                    }
                    if ( ! empty( $f['layouts'] ) ) {
                        foreach ( $f['layouts'] as $layout ) {
                            $layout_key = $layout['key'] ?? '';
                            if ( $layout_key ) {
                                $key_map[ $layout_key ] = 'layout_' . uniqid( '', true );
                            }
                            if ( ! empty( $layout['sub_fields'] ) ) {
                                $map_keys_recursive( $layout['sub_fields'] );
                            }
                        }
                    }
                }
            };
            $map_keys_recursive( $source_fields );

            // Nieuwe group key
            $new_group_key          = 'group_' . uniqid( '', true );
            $key_map[ $source['key'] ] = $new_group_key;

            // Kloon velden met nieuwe keys en slugs
            $clone_fields_recursive = function ( array $fields, string $parent_key ) use ( &$clone_fields_recursive, &$key_map, $slug_find, $slug_replace ) {
                $cloned = [];
                foreach ( $fields as $f ) {
                    $new_field = $f;
                    unset( $new_field['ID'], $new_field['id'] );

                    $new_field['key']    = $key_map[ $f['key'] ] ?? ( 'field_' . uniqid( '', true ) );
                    $new_field['parent'] = $parent_key;

                    if ( $slug_find && $slug_replace && ! empty( $new_field['name'] ) ) {
                        $new_field['name'] = str_replace( $slug_find, $slug_replace, $new_field['name'] );
                    }

                    // Update conditional logic referenties
                    if ( ! empty( $new_field['conditional_logic'] ) && is_array( $new_field['conditional_logic'] ) ) {
                        foreach ( $new_field['conditional_logic'] as &$or_group ) {
                            foreach ( $or_group as &$rule ) {
                                if ( isset( $rule['field'] ) && isset( $key_map[ $rule['field'] ] ) ) {
                                    $rule['field'] = $key_map[ $rule['field'] ];
                                }
                            }
                        }
                    }

                    if ( ! empty( $new_field['sub_fields'] ) ) {
                        $new_field['sub_fields'] = $clone_fields_recursive( $new_field['sub_fields'], $new_field['key'] );
                    }

                    if ( ! empty( $new_field['layouts'] ) ) {
                        $new_layouts = [];
                        foreach ( $new_field['layouts'] as $layout ) {
                            $new_layout = $layout;
                            $new_layout['key'] = $key_map[ $layout['key'] ] ?? ( 'layout_' . uniqid( '', true ) );
                            if ( ! empty( $new_layout['sub_fields'] ) ) {
                                $new_layout['sub_fields'] = $clone_fields_recursive( $new_layout['sub_fields'], $new_layout['key'] );
                            }
                            $new_layouts[] = $new_layout;
                        }
                        $new_field['layouts'] = $new_layouts;
                    }

                    $cloned[] = $new_field;
                }
                return $cloned;
            };

            $cloned_fields = $clone_fields_recursive( $source_fields, $new_group_key );

            if ( $dry_run ) {
                return [
                    'dry_run'        => true,
                    'source_group'   => $source['title'],
                    'new_title'      => $new_title,
                    'new_group_key'  => $new_group_key,
                    'field_count'    => count( $cloned_fields ),
                    'slug_mapping'   => $slug_find ? [ $slug_find => $slug_replace ] : null,
                    'key_mapping'    => $key_map,
                    'fields_preview' => array_map( 'aspera_format_acf_field', $cloned_fields ),
                ];
            }

            // Maak de nieuwe field group aan
            $new_group = $source;
            unset( $new_group['ID'], $new_group['id'] );
            $new_group['key']   = $new_group_key;
            $new_group['title'] = $new_title;

            $saved_group = acf_update_field_group( $new_group );

            // Sla alle velden op
            $saved_fields = [];
            $save_recursive = function ( array $fields ) use ( &$save_recursive, &$saved_fields ) {
                foreach ( $fields as $field ) {
                    $subs    = $field['sub_fields'] ?? [];
                    $layouts = $field['layouts'] ?? [];
                    unset( $field['sub_fields'], $field['layouts'] );

                    $saved = acf_update_field( $field );
                    $saved_fields[] = [
                        'key'  => $saved['key'],
                        'name' => $saved['name'],
                        'type' => $saved['type'],
                    ];

                    if ( ! empty( $subs ) ) {
                        foreach ( $subs as &$sub ) {
                            $sub['parent'] = $saved['key'];
                        }
                        $save_recursive( $subs );
                    }

                    if ( ! empty( $layouts ) ) {
                        foreach ( $layouts as $layout ) {
                            if ( ! empty( $layout['sub_fields'] ) ) {
                                foreach ( $layout['sub_fields'] as &$lsub ) {
                                    $lsub['parent'] = $layout['key'];
                                }
                                $save_recursive( $layout['sub_fields'] );
                            }
                        }
                    }
                }
            };
            $save_recursive( $cloned_fields );

            return [
                'dry_run'       => false,
                'source_group'  => $source['title'],
                'new_group_id'  => $saved_group['ID'],
                'new_group_key' => $new_group_key,
                'new_title'     => $new_title,
                'field_count'   => count( $saved_fields ),
                'fields'        => $saved_fields,
            ];
        },
    ] );

    /**
     * POST /wp-json/aspera/v1/impreza/colors/migrate
     * Vervangt Custom Global Color slug-referenties in Impreza theme options
     * door directe kleurwaarden (hex, gradient, rgba) of thema-kleurrollen.
     *
     * Leest custom_colors uit usof_options_Impreza, bouwt een slug-naar-waarde mapping,
     * en vervangt alle referenties in color_* keys. Resolvet ook slugs binnen gradients.
     * Met prefer_roles=true worden waarden waar mogelijk vervangen door thema-kleurrollen
     * (bijv. _content_link, _content_bg) in plaats van directe hex-waarden.
     * Ondersteunt dry_run=true.
     */
    register_rest_route( 'aspera/v1', '/impreza/colors/migrate', [
        'methods'             => 'POST',
        'permission_callback' => 'aspera_check_key',
        'callback'            => function ( WP_REST_Request $req ) {

            $dry_run       = filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );
            $prefer_roles  = filter_var( $req->get_param( 'prefer_roles' ), FILTER_VALIDATE_BOOLEAN );
            $option_name   = 'usof_options_Impreza';
            $raw           = get_option( $option_name );

            if ( ! is_array( $raw ) ) {
                return new WP_Error( 'invalid_option', 'usof_options_Impreza is geen array.', [ 'status' => 500 ] );
            }

            $custom_colors = $raw['custom_colors'] ?? [];
            if ( empty( $custom_colors ) || ! is_array( $custom_colors ) ) {
                return new WP_Error( 'no_colors', 'Geen Custom Global Colors gevonden.', [ 'status' => 404 ] );
            }

            // Bouw slug → waarde mapping
            $slug_map = [];
            foreach ( $custom_colors as $cc ) {
                $slug  = $cc['slug'] ?? '';
                $color = $cc['color'] ?? '';
                if ( $slug && $color ) {
                    $slug_map[ $slug ] = $color;
                }
            }

            // Bouw hex → thema-kleurrol mapping uit bestaande color_* top-level keys
            // Prioriteit: content_ rollen eerst, dan alt_content_, dan footer_, dan header_
            $role_map = [];
            if ( $prefer_roles ) {
                $priority = [ 'color_content_', 'color_alt_content_', 'color_footer_', 'color_header_' ];
                foreach ( $priority as $prefix ) {
                    foreach ( $raw as $key => $value ) {
                        if ( strpos( $key, $prefix ) !== 0 ) continue;
                        if ( ! is_string( $value ) || $value === '' ) continue;
                        $role = '_' . substr( $key, 6 );
                        if ( ! isset( $role_map[ $value ] ) ) {
                            $role_map[ $value ] = $role;
                        }
                    }
                }
            }

            // Resolve functie: vervangt slugs door directe waarden
            $resolve = function ( string $value ) use ( $slug_map ): ?string {
                if ( $value === '' ) return null;

                if ( isset( $slug_map[ $value ] ) ) {
                    $resolved = $slug_map[ $value ];
                    foreach ( $slug_map as $s => $v ) {
                        $resolved = str_replace( $s, $v, $resolved );
                    }
                    return $resolved;
                }

                $changed = $value;
                foreach ( $slug_map as $s => $v ) {
                    $changed = str_replace( $s, $v, $changed );
                }
                return $changed !== $value ? $changed : null;
            };

            // Resolve met thema-kleurrollen: custom slug of hex → rol
            $resolve_role = function ( string $value ) use ( $slug_map, $role_map ): ?string {
                if ( $value === '' ) return null;

                if ( isset( $slug_map[ $value ] ) ) {
                    $hex = $slug_map[ $value ];
                    if ( isset( $role_map[ $hex ] ) ) {
                        return $role_map[ $hex ];
                    }
                    return $hex;
                }

                if ( isset( $role_map[ $value ] ) ) {
                    return $role_map[ $value ];
                }

                return null;
            };

            $changes = [];

            foreach ( $raw as $key => $value ) {
                if ( strpos( $key, 'color_' ) !== 0 ) continue;
                if ( ! is_string( $value ) || $value === '' ) continue;

                $new_value = $resolve( $value );
                if ( $new_value !== null && $new_value !== $value ) {
                    $changes[] = [
                        'key'  => $key,
                        'from' => $value,
                        'to'   => $new_value,
                    ];
                    if ( ! $dry_run ) {
                        $raw[ $key ] = $new_value;
                    }
                }
            }

            // Buttons (array van button-stijlen met color_* properties)
            $btn_resolve    = $prefer_roles ? $resolve_role : $resolve;
            $button_changes = [];
            if ( ! empty( $raw['buttons'] ) && is_array( $raw['buttons'] ) ) {
                foreach ( $raw['buttons'] as $idx => &$button ) {
                    if ( ! is_array( $button ) ) continue;
                    foreach ( $button as $bk => $bv ) {
                        if ( strpos( $bk, 'color_' ) !== 0 ) continue;
                        if ( ! is_string( $bv ) || $bv === '' ) continue;

                        $new_bv = $btn_resolve( $bv );
                        if ( $new_bv !== null && $new_bv !== $bv ) {
                            $button_changes[] = [
                                'button' => $idx,
                                'name'   => $button['name'] ?? '',
                                'key'    => $bk,
                                'from'   => $bv,
                                'to'     => $new_bv,
                            ];
                            if ( ! $dry_run ) {
                                $button[ $bk ] = $new_bv;
                            }
                        }
                    }
                }
                unset( $button );
            }

            // Input fields (array van input-stijlen met color_* properties)
            $input_changes = [];
            if ( ! empty( $raw['input_fields'] ) && is_array( $raw['input_fields'] ) ) {
                foreach ( $raw['input_fields'] as $idx => &$input ) {
                    if ( ! is_array( $input ) ) continue;
                    foreach ( $input as $ik => $iv ) {
                        if ( strpos( $ik, 'color_' ) !== 0 ) continue;
                        if ( ! is_string( $iv ) || $iv === '' ) continue;

                        $new_iv = $btn_resolve( $iv );
                        if ( $new_iv !== null && $new_iv !== $iv ) {
                            $input_changes[] = [
                                'input'  => $idx,
                                'name'   => $input['name'] ?? '',
                                'key'    => $ik,
                                'from'   => $iv,
                                'to'     => $new_iv,
                            ];
                            if ( ! $dry_run ) {
                                $input[ $ik ] = $new_iv;
                            }
                        }
                    }
                }
                unset( $input );
            }

            // Style schemes (usof_style_schemes_Impreza)
            $schemes_name    = 'usof_style_schemes_Impreza';
            $schemes_raw     = get_option( $schemes_name );
            $scheme_changes  = [];

            if ( is_array( $schemes_raw ) ) {
                foreach ( $schemes_raw as $scheme_key => &$scheme ) {
                    if ( ! is_array( $scheme ) ) continue;
                    foreach ( $scheme as $sk => $sv ) {
                        if ( strpos( $sk, 'color_' ) !== 0 ) continue;
                        if ( ! is_string( $sv ) || $sv === '' ) continue;

                        $new_sv = $resolve( $sv );
                        if ( $new_sv !== null && $new_sv !== $sv ) {
                            $scheme_changes[] = [
                                'scheme' => $scheme_key,
                                'key'    => $sk,
                                'from'   => $sv,
                                'to'     => $new_sv,
                            ];
                            if ( ! $dry_run ) {
                                $scheme[ $sk ] = $new_sv;
                            }
                        }
                    }
                }
                unset( $scheme );
            }

            $has_changes = ! empty( $changes ) || ! empty( $button_changes ) || ! empty( $input_changes ) || ! empty( $scheme_changes );

            if ( ! $dry_run && $has_changes ) {
                if ( ! empty( $changes ) || ! empty( $button_changes ) || ! empty( $input_changes ) ) {
                    update_option( $option_name, $raw );
                }
                if ( ! empty( $scheme_changes ) ) {
                    update_option( $schemes_name, $schemes_raw );
                }
            }

            // Verwijder custom_colors array als alle referenties zijn gemigreerd
            $remove_colors  = filter_var( $req->get_param( 'remove_custom_colors' ), FILTER_VALIDATE_BOOLEAN );
            $colors_removed = false;
            $blocking_refs  = [];
            if ( $remove_colors ) {
                $still_pending = ! empty( $changes ) || ! empty( $button_changes ) || ! empty( $input_changes ) || ! empty( $scheme_changes );
                if ( $still_pending ) {
                    return new WP_Error(
                        'pending_migrations',
                        'Er zijn nog onverwerkte kleurverwijzingen. Voer eerst de migratie uit zonder remove_custom_colors.',
                        [ 'status' => 409 ]
                    );
                }

                // Check post_content op custom color slug referenties
                global $wpdb;
                foreach ( $slug_map as $slug => $color ) {
                    $esc_slug = $wpdb->esc_like( $slug ) . '%';
                    $found = $wpdb->get_results( $wpdb->prepare(
                        "SELECT ID, post_title, post_type FROM {$wpdb->posts}
                         WHERE post_status = 'publish'
                         AND post_content LIKE %s
                         LIMIT 5",
                        '%' . $esc_slug
                    ) );
                    foreach ( $found as $row ) {
                        $blocking_refs[] = [
                            'slug'      => $slug,
                            'source'    => 'post_content',
                            'post_id'   => (int) $row->ID,
                            'post_type' => $row->post_type,
                            'title'     => $row->post_title,
                        ];
                    }

                    // Check postmeta
                    $meta_count = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
                        '%' . $esc_slug
                    ) );
                    if ( $meta_count > 0 ) {
                        $blocking_refs[] = [
                            'slug'   => $slug,
                            'source' => 'postmeta',
                            'count'  => $meta_count,
                        ];
                    }
                }

                // Check child theme CSS bestanden
                $theme_dir = get_stylesheet_directory();
                foreach ( [ 'style.css', 'custom.css' ] as $css_file ) {
                    $css_path = $theme_dir . '/' . $css_file;
                    if ( ! file_exists( $css_path ) ) continue;
                    $css_content = file_get_contents( $css_path );
                    foreach ( $slug_map as $slug => $color ) {
                        $css_var = 'var(--color-' . ltrim( $slug, '_' ) . ')';
                        if ( strpos( $css_content, $css_var ) !== false ) {
                            $blocking_refs[] = [
                                'slug'   => $slug,
                                'source' => $css_file,
                                'var'    => $css_var,
                            ];
                        }
                    }
                }

                if ( ! empty( $blocking_refs ) ) {
                    if ( ! $dry_run ) {
                        return new WP_Error(
                            'refs_in_use',
                            'Custom color slugs worden nog gebruikt buiten theme options. Verwijdering geblokkeerd.',
                            [ 'status' => 409, 'blocking_refs' => $blocking_refs ]
                        );
                    }
                } elseif ( ! $dry_run ) {
                    $raw_fresh = get_option( $option_name );
                    $raw_fresh['custom_colors'] = [];
                    update_option( $option_name, $raw_fresh );
                    $colors_removed = true;
                }
            }

            $response = [
                'dry_run'        => $dry_run,
                'prefer_roles'   => $prefer_roles,
                'slug_map'       => $slug_map,
                'theme_options'  => $changes,
                'buttons'        => $button_changes,
                'input_fields'   => $input_changes,
                'style_schemes'  => $scheme_changes,
                'totals'         => [
                    'theme_options' => count( $changes ),
                    'buttons'      => count( $button_changes ),
                    'input_fields' => count( $input_changes ),
                    'style_schemes' => count( $scheme_changes ),
                ],
            ];
            if ( $prefer_roles ) {
                $response['role_map'] = $role_map;
            }
            if ( $remove_colors ) {
                $response['custom_colors_removed'] = $colors_removed;
                if ( ! empty( $blocking_refs ) ) {
                    $response['blocking_refs'] = $blocking_refs;
                }
            }
            return $response;
        },
    ] );

} );
