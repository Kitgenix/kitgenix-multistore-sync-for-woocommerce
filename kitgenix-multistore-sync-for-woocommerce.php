<?php
/**
 * Plugin Name: Kitgenix MultiStore Sync for WooCommerce
 * Plugin URI: https://kitgenix.com/
 * Description: Secure, queue-backed multi-store product, inventory, order, customer and coupon synchronisation for WooCommerce.
 * Version: 2.1.2
 * Author: Kitgenix
 * Author URI: https://kitgenix.com/
 * Text Domain: kitgenix-multistore-sync-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.8
 * Tested up to: 7.1
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 10.0
 * WC tested up to: 11.0.0
 * Network: true
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION', '2.1.2' );
define( 'KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_DB_VERSION', '1.0.0' );
define( 'KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_FILE', __FILE__ );
define( 'KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_DIR', plugin_dir_path( __FILE__ ) );
define( 'KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_URL', plugin_dir_url( __FILE__ ) );
define( 'KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_BASENAME', plugin_basename( __FILE__ ) );

// -----------------------------------------------------------------------------
// Shared Kitgenix admin menu (top-level) helper.
// Each Kitgenix plugin may call this; it is safe to call multiple times.
// -----------------------------------------------------------------------------
if ( ! function_exists( 'kitgenix_get_admin_menu_icon' ) ) {
    function kitgenix_get_admin_menu_icon( string $plugin_file ): string {
        $plugin_dir = dirname( $plugin_file ) . '/';
        $icon_paths = [
            $plugin_dir . 'assets/images/logos/kitgenix-wordpress-admin-menu-favicon.svg',
            $plugin_dir . 'assets/images/logos/kitgenix-black-favicon.svg',
        ];

        foreach ( $icon_paths as $icon_path ) {
            if ( ! is_readable( $icon_path ) ) {
                continue;
            }

            $svg = file_get_contents( $icon_path );
            if ( false !== $svg && '' !== trim( $svg ) ) {
                return 'data:image/svg+xml;base64,' . base64_encode( $svg );
            }
        }

        return 'dashicons-admin-generic';
    }
}

if ( ! function_exists( 'kitgenix_ensure_admin_menu' ) ) {
    function kitgenix_ensure_admin_menu(): void {
        if ( ! is_admin() ) {
            return;
        }

        global $admin_page_hooks;
        $slug = 'kitgenix';

        if ( isset( $admin_page_hooks[ $slug ] ) ) {
            return;
        }

        // Prefer WooCommerce managers when WooCommerce is active, otherwise admins.
        $capability = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
        if ( ! current_user_can( $capability ) && current_user_can( 'manage_options' ) ) {
            $capability = 'manage_options';
        }

        $icon_url = kitgenix_get_admin_menu_icon( __FILE__ );

        add_menu_page(
            __( 'Kitgenix', 'kitgenix-multistore-sync-for-woocommerce' ),
            __( 'Kitgenix', 'kitgenix-multistore-sync-for-woocommerce' ),
            $capability,
            $slug,
            'kitgenix_render_admin_page',
            $icon_url,
            58
        );
    }
}

// Ensure the shared Kitgenix top-level menu exists.
add_action( 'admin_menu', 'kitgenix_ensure_admin_menu', 5 );

if ( ! function_exists( 'kitgenix_hub_get_wporg_active_installs' ) ) {
    /**
     * Fetch WP.org active install counts for a set of plugin slugs.
     * Cached to avoid repeated network calls.
     *
     * @param string[] $slugs
     * @return array<string,int> Map of slug => active_installs
     */
    function kitgenix_hub_get_wporg_active_installs( array $slugs ): array {
        if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
            return [];
        }

        $clean_slugs = [];
        foreach ( $slugs as $slug ) {
            $slug = is_string( $slug ) ? $slug : '';
            $slug = function_exists( 'sanitize_key' ) ? sanitize_key( $slug ) : strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $slug ) );
            if ( $slug ) {
                $clean_slugs[] = $slug;
            }
        }

        $clean_slugs = array_values( array_unique( $clean_slugs ) );
        if ( empty( $clean_slugs ) ) {
            return [];
        }

        $cache_key = 'kitgenix_hub_wporg_active_installs_v1';
        $cached    = get_transient( $cache_key );
        $cached    = is_array( $cached ) ? $cached : [];

        $missing = [];
        foreach ( $clean_slugs as $slug ) {
            if ( ! array_key_exists( $slug, $cached ) ) {
                $missing[] = $slug;
            }
        }

        if ( ! empty( $missing ) ) {
            if ( ! function_exists( 'plugins_api' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }

            foreach ( $missing as $slug ) {
                $info = plugins_api(
                    'plugin_information',
                    [
                        'slug'   => $slug,
                        'fields' => [
                            'active_installs'   => true,
                            'short_description' => false,
                            'description'       => false,
                            'sections'          => false,
                            'versions'          => false,
                            'banners'           => false,
                            'rating'            => false,
                            'ratings'           => false,
                            'downloaded'        => false,
                            'last_updated'      => false,
                            'added'             => false,
                            'tags'              => false,
                            'requires'          => false,
                            'requires_php'      => false,
                            'tested'            => false,
                            'homepage'          => false,
                            'donate_link'       => false,
                        ],
                    ]
                );

                if ( function_exists( 'is_wp_error' ) && is_wp_error( $info ) ) {
                    continue;
                }

                if ( is_object( $info ) && isset( $info->active_installs ) ) {
                    $cached[ $slug ] = (int) $info->active_installs;
                }
            }

            $ttl = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
            set_transient( $cache_key, $cached, $ttl );
        }

        $result = [];
        foreach ( $clean_slugs as $slug ) {
            if ( isset( $cached[ $slug ] ) && (int) $cached[ $slug ] > 0 ) {
                $result[ $slug ] = (int) $cached[ $slug ];
            }
        }

        return $result;
    }
}

if ( ! function_exists( 'kitgenix_hub_get_wporg_ratings' ) ) {
    /**
     * Fetch WP.org ratings (percentage) for a set of plugin slugs.
     *
     * @param array<int,string> $slugs Plugin slugs.
     * @return array<string,int> Map of slug => rating percentage (0-100)
     */
    function kitgenix_hub_get_wporg_ratings( array $slugs ): array {
        $slugs = array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
        if ( empty( $slugs ) ) {
            return [];
        }

        $cache_key = 'kitgenix_hub_wporg_ratings_v1';
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            $missing = array_diff( $slugs, array_keys( $cached ) );
            if ( empty( $missing ) ) {
                return $cached;
            }
        } else {
            $cached = [];
            $missing = $slugs;
        }

        if ( ! function_exists( 'plugins_api' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }

        foreach ( $missing as $slug ) {
            $info = plugins_api(
                'plugin_information',
                [
                    'slug'   => $slug,
                    'fields' => [
                        'rating' => true,
                    ],
                ]
            );

            if ( is_object( $info ) && isset( $info->rating ) ) {
                $cached[ $slug ] = (int) $info->rating;
            }
        }

        set_transient( $cache_key, $cached, DAY_IN_SECONDS );

        return $cached;
    }
}

if ( ! function_exists( 'kitgenix_hub_get_wporg_media' ) ) {
    /**
     * Fetch WP.org banner or icon artwork for a set of plugin slugs.
     *
     * @param array<int,string> $slugs Plugin slugs.
     * @return array<string,array{url:string,type:string}> Map of slug => media payload.
     */
    function kitgenix_hub_get_wporg_media( array $slugs ): array {
        if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
            return [];
        }

        $slugs = array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
        if ( empty( $slugs ) ) {
            return [];
        }

        $cache_key = 'kitgenix_hub_wporg_media_v1';
        $cached    = get_transient( $cache_key );
        $cached    = is_array( $cached ) ? $cached : [];
        $missing   = array_diff( $slugs, array_keys( $cached ) );

        if ( ! empty( $missing ) ) {
            if ( ! function_exists( 'plugins_api' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }

            foreach ( $missing as $slug ) {
                $info = plugins_api(
                    'plugin_information',
                    [
                        'slug'   => $slug,
                        'fields' => [
                            'icons'             => true,
                            'banners'           => true,
                            'active_installs'   => false,
                            'rating'            => false,
                            'ratings'           => false,
                            'short_description' => false,
                            'description'       => false,
                            'sections'          => false,
                            'versions'          => false,
                            'downloaded'        => false,
                            'last_updated'      => false,
                            'added'             => false,
                            'tags'              => false,
                            'requires'          => false,
                            'requires_php'      => false,
                            'tested'            => false,
                            'homepage'          => false,
                            'donate_link'       => false,
                        ],
                    ]
                );

                if ( function_exists( 'is_wp_error' ) && is_wp_error( $info ) ) {
                    continue;
                }

                $media_url  = '';
                $media_type = '';

                if ( is_object( $info ) && isset( $info->banners ) ) {
                    $banners = is_object( $info->banners ) ? get_object_vars( $info->banners ) : ( is_array( $info->banners ) ? $info->banners : [] );
                    foreach ( [ 'high', 'low' ] as $key ) {
                        if ( ! empty( $banners[ $key ] ) && is_string( $banners[ $key ] ) ) {
                            $media_url  = $banners[ $key ];
                            $media_type = 'banner';
                            break;
                        }
                    }
                }

                if ( '' === $media_url && is_object( $info ) && isset( $info->icons ) ) {
                    $icons = is_object( $info->icons ) ? get_object_vars( $info->icons ) : ( is_array( $info->icons ) ? $info->icons : [] );
                    foreach ( [ 'svg', '2x', '1x', 'default' ] as $key ) {
                        if ( ! empty( $icons[ $key ] ) && is_string( $icons[ $key ] ) ) {
                            $media_url  = $icons[ $key ];
                            $media_type = 'icon';
                            break;
                        }
                    }
                }

                $cached[ $slug ] = $media_url ? [
                    'url'  => $media_url,
                    'type' => $media_type,
                ] : [];
            }

            $ttl = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
            set_transient( $cache_key, $cached, $ttl );
        }

        $result = [];
        foreach ( $slugs as $slug ) {
            if ( ! empty( $cached[ $slug ]['url'] ) ) {
                $result[ $slug ] = [
                    'url'  => (string) $cached[ $slug ]['url'],
                    'type' => ! empty( $cached[ $slug ]['type'] ) ? (string) $cached[ $slug ]['type'] : 'icon',
                ];
            }
        }

        return $result;
    }
}

if ( ! function_exists( 'kitgenix_render_admin_page' ) ) {
    function kitgenix_render_admin_page(): void {
        $allowed = current_user_can( 'manage_options' ) || ( class_exists( 'WooCommerce' ) && current_user_can( 'manage_woocommerce' ) );
        if ( ! $allowed ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'kitgenix-multistore-sync-for-woocommerce' ) );
        }

        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins_data = function_exists( 'get_plugins' ) ? get_plugins() : [];

        $plugins = [
            [
                'id'       => 'multistore',
                'name'     => __( 'MultiStore for WooCommerce', 'kitgenix-multistore-sync-for-woocommerce' ),
                'slug'     => 'kitgenix-multistore-sync-for-woocommerce',
                'file'     => 'kitgenix-multistore-sync-for-woocommerce/kitgenix-multistore-sync-for-woocommerce.php',
                'page'     => 'kitgenix-multistore-sync-for-woocommerce',
                'requires' => __( 'Sync WooCommerce products, prices, media, and metadata between multiple stores with a secure master-child architecture.', 'kitgenix-multistore-sync-for-woocommerce' ),
            ],
            [
                'id'       => 'turnstile',
                'name'     => __( 'CAPTCHA for Cloudflare Turnstile', 'kitgenix-multistore-sync-for-woocommerce' ),
                'slug'     => 'kitgenix-captcha-for-cloudflare-turnstile',
                'file'     => 'kitgenix-captcha-for-cloudflare-turnstile/kitgenix-captcha-for-cloudflare-turnstile.php',
                'page'     => 'kitgenix-captcha-for-cloudflare-turnstile',
                'requires' => __( 'Add Cloudflare Turnstile CAPTCHA to WordPress, WooCommerce, Elementor, and popular form plugins.', 'kitgenix-multistore-sync-for-woocommerce' ),
            ],
            [
                'id'       => 'custom_tabs',
                'name'     => __( 'Custom Tabs for WooCommerce', 'kitgenix-multistore-sync-for-woocommerce' ),
                'slug'     => 'kitgenix-custom-tabs-for-woocommerce',
                'file'     => 'kitgenix-custom-tabs-for-woocommerce/kitgenix-custom-tabs-for-woocommerce.php',
                'page'     => 'kitgenix-custom-tabs-for-woocommerce',
                'requires' => __( 'Add custom WooCommerce product tabs with per-product content, global tabs, and lightweight controls.', 'kitgenix-multistore-sync-for-woocommerce' ),
            ],
            [
                'id'       => 'documents',
                'name'     => __( 'Document Manager', 'kitgenix-multistore-sync-for-woocommerce' ),
                'slug'     => 'kitgenix-document-manager',
                'file'     => 'kitgenix-document-manager/kitgenix-document-manager.php',
                'page'     => 'kitgenix-document-manager',
                'requires' => __( 'Manage document downloads with stable links, version history, and private file access.', 'kitgenix-multistore-sync-for-woocommerce' ),
            ],
            [
                'id'       => 'tracking',
                'name'     => __( 'Order Tracking for WooCommerce', 'kitgenix-multistore-sync-for-woocommerce' ),
                'slug'     => 'kitgenix-order-tracking-for-woocommerce',
                'file'     => 'kitgenix-order-tracking-for-woocommerce/kitgenix-order-tracking-for-woocommerce.php',
                'page'     => 'kitgenix-order-tracking-for-woocommerce-analytics',
                'requires' => __( 'Add WooCommerce order tracking, multi-shipment support, email tracking links, and a public customer tracking page.', 'kitgenix-multistore-sync-for-woocommerce' ),
            ],
            [
                'id'       => 'pdf',
                'name'     => __( 'PDF Invoicing for WooCommerce', 'kitgenix-multistore-sync-for-woocommerce' ),
                'slug'     => 'kitgenix-pdf-invoicing-for-woocommerce',
                'file'     => 'kitgenix-pdf-invoicing-for-woocommerce/kitgenix-pdf-invoicing-for-woocommerce.php',
                'page'     => 'kitgenix-pdf-invoicing-settings',
                'requires' => __( 'Generate WooCommerce PDF invoices, receipts, packing slips, and credit notes with secure downloads and configurable email attachments.', 'kitgenix-multistore-sync-for-woocommerce' ),
            ],
            [
                'id'       => 'stock',
                'name'     => __( 'Stock Sync for WooCommerce', 'kitgenix-multistore-sync-for-woocommerce' ),
                'slug'     => 'kitgenix-stock-sync-for-woocommerce',
                'file'     => 'kitgenix-stock-sync-for-woocommerce/kitgenix-stock-sync-for-woocommerce.php',
                'page'     => 'kitgenix-stock-sync-for-woocommerce',
                'requires' => __( 'Sync WooCommerce stock between stores with secure master-child inventory updates and signed REST requests.', 'kitgenix-multistore-sync-for-woocommerce' ),
            ],
            [
                'id'       => 'image_optimizer',
                'name'     => __( 'Image Optimizer', 'kitgenix-multistore-sync-for-woocommerce' ),
                'slug'     => 'kitgenix-image-optimizer',
                'file'     => 'kitgenix-image-optimizer/kitgenix-image-optimizer.php',
                'page'     => 'kitgenix-image-optimizer',
                'requires' => __( 'Optimize, compress, and resize images in your WordPress media library with automatic on-upload processing and bulk optimization tools.', 'kitgenix-multistore-sync-for-woocommerce' ),
            ],
            [
                'id'       => 'affiliate',
                'name'     => __( 'Affiliate Link Manager', 'kitgenix-multistore-sync-for-woocommerce' ),
                'slug'     => 'kitgenix-affiliate-link-manager',
                'file'     => 'kitgenix-affiliate-link-manager/kitgenix-affiliate-link-manager.php',
                'page'     => 'kitgenix-affiliate-link-manager',
                'requires' => __( 'Manage affiliate short links, branded redirects, and click tracking from one WordPress dashboard.', 'kitgenix-multistore-sync-for-woocommerce' ),
            ],
            [
                'id'       => 'database_cleaner',
                'name'     => __( 'Database Cleaner', 'kitgenix-multistore-sync-for-woocommerce' ),
                'slug'     => 'kitgenix-database-cleaner',
                'file'     => 'kitgenix-database-cleaner/kitgenix-database-cleaner.php',
                'page'     => 'kitgenix-database-cleaner',
                'requires' => __( 'Scan and clean your WordPress database safely, with plugin/theme attribution for every item found and a built-in recycle bin so nothing is ever deleted without a way back.', 'kitgenix-multistore-sync-for-woocommerce' ),
            ],
        ];

        $slugs = [];
        foreach ( $plugins as $plugin ) {
            if ( ! empty( $plugin['slug'] ) ) {
                $slugs[] = (string) $plugin['slug'];
            }
        }
        $wporg_active_installs = kitgenix_hub_get_wporg_active_installs( $slugs );
        $wporg_ratings        = kitgenix_hub_get_wporg_ratings( $slugs );
        $wporg_media          = kitgenix_hub_get_wporg_media( $slugs );
        $logo_url             = plugins_url( 'assets/images/logos/kitgenix-primary-favicon.svg', __FILE__ );

        echo '<div class="wrap kitgenix-admin-app plugin-install-php">'
            . '<div class="kitgenix-hub-header">'
            . '<div class="kitgenix-hub-brand">'
            . '<span class="kitgenix-topbar-brand">'
            . '<img class="kitgenix-hub-logo" src="' . esc_url( $logo_url ) . '" alt="' . esc_attr__( 'Kitgenix', 'kitgenix-multistore-sync-for-woocommerce' ) . '" width="30" height="30" />'
            . '</span>'
            . '<span class="kitgenix-topbar-divider" aria-hidden="true"></span>'
            . '<div class="kitgenix-hub-brand-copy">'
            . '<h1 class="kitgenix-hub-title">' . esc_html__( 'Kitgenix', 'kitgenix-multistore-sync-for-woocommerce' ) . '</h1>'
            . '<p class="kitgenix-hub-description">' . esc_html__( 'Install, activate, open, and review Kitgenix plugins.', 'kitgenix-multistore-sync-for-woocommerce' ) . '</p>'
            . '</div>'
            . '</div>'
            . '<div class="kitgenix-hub-social-links">'
            . '<a href="https://kitgenix.com" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="' . esc_url( plugins_url( 'assets/images/social-media/globe-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Website</span></a>'
            . '<a href="https://www.facebook.com/groups/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook Community" title="Facebook Community"><img src="' . esc_url( plugins_url( 'assets/images/social-media/facebook-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook Community</span></a>'
            . '<a href="https://www.facebook.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><img src="' . esc_url( plugins_url( 'assets/images/social-media/facebook-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook</span></a>'
            . '<a href="https://www.instagram.com/kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram"><img src="' . esc_url( plugins_url( 'assets/images/social-media/instagram-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Instagram</span></a>'
            . '<a href="https://www.youtube.com/@Kitgenix" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube"><img src="' . esc_url( plugins_url( 'assets/images/social-media/youtube-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">YouTube</span></a>'
            . '<a href="https://www.reddit.com/r/Kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Reddit" title="Reddit"><img src="' . esc_url( plugins_url( 'assets/images/social-media/reddit-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Reddit</span></a>'
            . '<a href="https://www.linkedin.com/company/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><img src="' . esc_url( plugins_url( 'assets/images/social-media/linkedin-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">LinkedIn</span></a>'
            . '<a href="https://x.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="X" title="X"><img src="' . esc_url( plugins_url( 'assets/images/social-media/x-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">X</span></a>'
            . '<a href="https://www.tiktok.com/@kitgenix" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok"><img src="' . esc_url( plugins_url( 'assets/images/social-media/tiktok-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">TikTok</span></a>'
            . '<a href="https://github.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="GitHub"><img src="' . esc_url( plugins_url( 'assets/images/social-media/github-solid.svg', __FILE__ ) ) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">GitHub</span></a>'
            . '</div>'
            . '</div>'
            . '<div class="kitgenix-hub-wrap">'
            . '<div class="kitgenix-hub">'
            . '<div class="kitgenix-hub-grid">';
        foreach ( $plugins as $p ) {
            $id = (string) $p['id'];
            $file = (string) $p['file'];
            $installed = isset( $plugins_data[ $file ] );
            $active = false;
            if ( $installed && function_exists( 'is_plugin_active' ) ) {
                $active = is_plugin_active( $file ) || ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $file ) );
            }

            $version_badge = '';
            if ( $installed && ! empty( $plugins_data[ $file ]['Version'] ) ) {
                $version_badge = '<span class="kitgenix-badge muted">v' . esc_html( (string) $plugins_data[ $file ]['Version'] ) . '</span>';
            }

            $installs_badge = '';
            $slug = ! empty( $p['slug'] ) ? (string) $p['slug'] : '';
            if ( $slug && ! empty( $wporg_active_installs[ $slug ] ) ) {
                $count = (int) $wporg_active_installs[ $slug ];
                $count_text = function_exists( 'number_format_i18n' ) ? number_format_i18n( $count ) : (string) $count;
                /* translators: %s is the number of active installs and may include a thousands separator, e.g. "1,234". The "+" suffix is literal. */
                $installs_badge = '<span class="kitgenix-badge muted">' . esc_html( sprintf( __( '%s+ installs', 'kitgenix-multistore-sync-for-woocommerce' ), $count_text ) ) . '</span>';
            }

            $rating_badge = '';
            if ( $slug && isset( $wporg_ratings[ $slug ] ) && (int) $wporg_ratings[ $slug ] > 0 ) {
                $rating_percent = (int) $wporg_ratings[ $slug ];
                $stars = ( $rating_percent / 100 ) * 5;
                $stars_text = function_exists( 'number_format_i18n' ) ? number_format_i18n( $stars, 1 ) : number_format( $stars, 1 );
                /* translators: %s is the average rating out of 5 with one decimal place, e.g. "4.5". The star symbol (★) precedes the number. */
                $rating_badge = '<span class="kitgenix-badge muted">' . esc_html( sprintf( __( '★ %s/5', 'kitgenix-multistore-sync-for-woocommerce' ), $stars_text ) ) . '</span>';
            }

            $status_badge = '';
            if ( ! $installed ) {
                $status_badge = '<span class="kitgenix-badge muted">' . esc_html__( 'Not installed', 'kitgenix-multistore-sync-for-woocommerce' ) . '</span>';
            } elseif ( $active ) {
                $status_badge = '<span class="kitgenix-badge ok">' . esc_html__( 'Active', 'kitgenix-multistore-sync-for-woocommerce' ) . '</span>';
            } else {
                $status_badge = '<span class="kitgenix-badge warn">' . esc_html__( 'Installed (Inactive)', 'kitgenix-multistore-sync-for-woocommerce' ) . '</span>';
            }

            $card_media = '';
            if ( $slug && ! empty( $wporg_media[ $slug ]['url'] ) ) {
                $media_type = ( ! empty( $wporg_media[ $slug ]['type'] ) && 'banner' === (string) $wporg_media[ $slug ]['type'] ) ? 'banner' : 'icon';
                $card_media = '<div class="kitgenix-card-media kitgenix-card-media-' . esc_attr( $media_type ) . '"><img class="kitgenix-card-media-image" src="' . esc_url( (string) $wporg_media[ $slug ]['url'] ) . '" alt="" loading="lazy" /></div>';
            }

            $actions = '';
            if ( ! $installed ) {
                if ( current_user_can( 'install_plugins' ) ) {
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wp_nonce_url() generates a nonce-protected URL
                    $install_url = wp_nonce_url(
                        admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( (string) $p['slug'] ) ),
                        'install-plugin_' . (string) $p['slug']
                    );
                } else {
                    $install_url = admin_url( 'plugin-install.php?s=' . rawurlencode( 'kitgenix' ) . '&tab=search&type=term' );
                }
                $actions .= '<a class="button button-primary" href="' . esc_url( $install_url ) . '">' . esc_html__( 'Install', 'kitgenix-multistore-sync-for-woocommerce' ) . '</a>';
            } elseif ( ! $active ) {
                if ( current_user_can( 'activate_plugins' ) ) {
                    $activate_url = wp_nonce_url(
                        admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) . '&plugin_status=all&paged=1&s=' ),
                        'activate-plugin_' . $file
                    );
                    $actions .= '<a class="button button-primary" href="' . esc_url( $activate_url ) . '">' . esc_html__( 'Activate', 'kitgenix-multistore-sync-for-woocommerce' ) . '</a>';
                } else {
                    $actions .= '<span class="description">' . esc_html__( 'You do not have permission to activate plugins.', 'kitgenix-multistore-sync-for-woocommerce' ) . '</span>';
                }
            } else {
                $open_url = ! empty( $p['page'] ) ? admin_url( 'admin.php?page=' . rawurlencode( (string) $p['page'] ) ) : '';
                if ( $open_url ) {
                    $actions .= '<a class="button button-primary" href="' . esc_url( $open_url ) . '">' . esc_html__( 'Open', 'kitgenix-multistore-sync-for-woocommerce' ) . '</a>';
                }
            }

            $info_url = admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( (string) $p['slug'] ) . '&TB_iframe=true&width=600&height=550' );
            $actions .= ' <a class="button button-secondary thickbox open-plugin-details-modal" href="' . esc_url( $info_url ) . '">' . esc_html__( 'Details', 'kitgenix-multistore-sync-for-woocommerce' ) . '</a>';
            if ( $slug ) {
                $review_url  = 'https://wordpress.org/support/plugin/' . rawurlencode( $slug ) . '/reviews/#new-post';
                $support_url = 'https://wordpress.org/support/plugin/' . rawurlencode( $slug ) . '/';
                $actions    .= ' <a class="button button-secondary" href="' . esc_url( $review_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Review', 'kitgenix-multistore-sync-for-woocommerce' ) . '</a>';
                $actions    .= ' <a class="button button-secondary" href="' . esc_url( $support_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support Forum', 'kitgenix-multistore-sync-for-woocommerce' ) . '</a>';
            }

            $kitgenix_hub_allowed_html = [
                'a'    => [
                    'class' => true,
                    'href'  => true,
                    'target' => true,
                    'rel' => true,
                ],
                'span' => [
                    'class' => true,
                ],
            ];

            $card_media_allowed_html = [
                'div' => [ 'class' => true ],
                'img' => [ 'class' => true, 'src' => true, 'alt' => true, 'loading' => true ],
            ];

            echo '<div class="kitgenix-card" data-kitgenix-plugin="' . esc_attr( sanitize_key( $id ) ) . '">'
                . wp_kses( $card_media, $card_media_allowed_html )
                . '<div class="kitgenix-card-body">'
                . '<div class="kitgenix-card-badges">' . wp_kses( trim( $status_badge . ' ' . $version_badge . ' ' . $rating_badge . ' ' . $installs_badge ), $kitgenix_hub_allowed_html ) . '</div>'
                . '<p class="kitgenix-card-title">' . esc_html( (string) $p['name'] ) . '</p>'
                . '<p class="kitgenix-card-desc">' . esc_html( (string) $p['requires'] ) . '</p>'
                . '</div>'
                . '<div class="kitgenix-card-actions">' . wp_kses( $actions, $kitgenix_hub_allowed_html ) . '</div>'
                . '</div>';
        }

        echo '</div>'; // .kitgenix-hub-grid

        echo '<div class="kitgenix-hub-feature-banner">'
            . '<div class="kitgenix-hub-feature-banner-copy">'
            . '<h2>' . esc_html__( 'Have an idea for a new feature?', 'kitgenix-multistore-sync-for-woocommerce' ) . '</h2>'
            . '<p>' . esc_html__( 'Browse the roadmap, vote on ideas, and submit feature requests for any Kitgenix plugin.', 'kitgenix-multistore-sync-for-woocommerce' ) . '</p>'
            . '</div>'
            . '<a class="button button-primary" href="' . esc_url( 'https://roadmap.kitgenix.com' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Request a Feature', 'kitgenix-multistore-sync-for-woocommerce' ) . '</a>'
            . '</div>';

        echo '</div></div></div>'; // .kitgenix-hub, .kitgenix-hub-wrap, .wrap
    }
}

if ( ! function_exists( 'kitgenix_multistore_register_admin_ui_style' ) ) {
    function kitgenix_multistore_register_admin_ui_style(): void {
        if ( ! is_admin() ) {
            return;
        }

        if ( function_exists( 'wp_style_is' ) && wp_style_is( 'kitgenix-multistore-sync-for-woocommerce-admin-ui', 'registered' ) ) {
            return;
        }

        $ver      = KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION;
        $css_file = plugin_dir_path( __FILE__ ) . 'assets/css/kitgenix-admin-ui.css';
        $css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : $ver;

        wp_register_style( 'kitgenix-multistore-sync-for-woocommerce-admin-ui', plugins_url( 'assets/css/kitgenix-admin-ui.css', __FILE__ ), [], $css_ver );
    }
}
add_action( 'admin_enqueue_scripts', 'kitgenix_multistore_register_admin_ui_style', 5 );

/**
 * Enqueue Kitgenix hub styles on the top-level Kitgenix page.
 */
function kitgenix_multistore_enqueue_hub_assets( string $hook_suffix ): void {
    // Prefer checking the `page` query arg so assets load reliably across installs.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( 'kitgenix' !== $page && 'toplevel_page_kitgenix' !== $hook_suffix ) {
        return;
    }

    add_thickbox();
    wp_enqueue_style( 'plugin-install' );

    if ( function_exists( 'wp_style_is' ) && ( wp_style_is( 'kitgenix-hub', 'enqueued' ) || wp_style_is( 'kitgenix-hub', 'registered' ) ) ) {
        return;
    }

    $ver = KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION;
    wp_register_style( 'kitgenix-hub', plugins_url( 'assets/css/kitgenix-hub.css', __FILE__ ), [], $ver );
    wp_enqueue_style( 'kitgenix-hub' );

    wp_register_style( 'kitgenix-multistore-sync-for-woocommerce-admin-ui', plugins_url( 'assets/css/kitgenix-admin-ui.css', __FILE__ ), [], $ver );
    wp_enqueue_style( 'kitgenix-multistore-sync-for-woocommerce-admin-ui' );
}
add_action( 'admin_enqueue_scripts', 'kitgenix_multistore_enqueue_hub_assets' );

add_action(
    'before_woocommerce_init',
    static function (): void {
        if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);

spl_autoload_register(
    static function ( string $class ): void {
        $prefix = 'Kitgenix\\MultiStore\\';
        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }
        $relative = substr( $class, strlen( $prefix ) );
        $file     = KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
);

register_activation_hook( __FILE__, array( 'Kitgenix\\MultiStore\\Database\\Schema', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Kitgenix\\MultiStore\\Database\\Schema', 'deactivate' ) );

add_action(
    'plugins_loaded',
    static function (): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action(
                'admin_notices',
                static function (): void {
                    if ( current_user_can( 'activate_plugins' ) ) {
                        echo '<div class="notice notice-error"><p><strong>Kitgenix MultiStore:</strong> WooCommerce must be active.</p></div>';
                    }
                }
            );
            return;
        }

        if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
            return;
        }

        // boot() runs on plugins_loaded, before WordPress finishes bootstrapping -
        // an uncaught error here (a missing/broken class file from a partial
        // upload or deploy, for example) would otherwise be a fatal that takes
        // down the entire site, including wp-admin, not just this plugin. Fail
        // safe instead: log it, show an actionable admin notice, and let the
        // rest of the site keep running.
        try {
            Kitgenix\MultiStore\Plugin::instance()->boot();
        } catch ( \Throwable $e ) {
            error_log( sprintf( 'Kitgenix MultiStore for WooCommerce failed to start: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: this is the only viable channel this early, before the plugin's own DB-backed Logger has booted.
            add_action(
                'admin_notices',
                static function () use ( $e ): void {
                    if ( current_user_can( 'activate_plugins' ) ) {
                        echo '<div class="notice notice-error"><p><strong>Kitgenix MultiStore for WooCommerce</strong> failed to start and has been disabled for this request: '
                            . esc_html( $e->getMessage() )
                            . '. This usually means the plugin files are incomplete on this server - try reinstalling the full plugin, or contact support if it persists.</p></div>';
                    }
                }
            );
        }
    },
    20
);
