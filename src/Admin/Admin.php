<?php
namespace Kitgenix\MultiStore\Admin;

use Kitgenix\MultiStore\Migration\WooMultistoreImporter;
use Kitgenix\MultiStore\Plugin;
use Kitgenix\MultiStore\Support\Settings;
use Kitgenix\MultiStore\Support\Access;
use Kitgenix\MultiStore\Support\ErrorCatalog;
use Kitgenix\MultiStore\Multisite\NativeNetwork;
use Kitgenix\MultiStore\Support\Uuid;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
// -- Every dynamic value in this file's admin-screen markup is escaped at one of a
// small number of points: esc_html()/esc_attr()/esc_url()/esc_textarea() applied
// directly, WordPress's own checked()/selected()/disabled() helpers, wp_kses_post()
// for WooCommerce-generated HTML (wc_price(), paginate_links()), or one of this
// class's own self-escaping render helpers (icon(): static trusted SVG only;
// metric()/integration()/checks_table()/toggle(): esc_html()/esc_attr()/
// wp_kses_post() applied internally to every argument). Badge/status variables
// built via match()/ternary (e.g. $badge, $status_badge, $state) are drawn from a
// fixed, developer-controlled literal set, never from user input. Variables like
// $versions/$table_html/$category_cell are pre-built entirely from already-escaped
// fragments and must not be re-escaped, which would corrupt their embedded markup.
// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// -- Every state-changing action handler in this class (registered as admin_post_*
// callbacks in hooks(), see the $a loop below) verifies both capability and nonce
// before touching $_POST/$_GET, either via the private guard() helper (which calls
// Access::can_manage() and check_admin_referer()) or an inline
// `Access::can_manage() || wp_verify_nonce(...)`/`check_admin_referer(...)` check –
// the sniff can't trace either pattern back to the request-data access it gates.
// The remaining $_GET reads (render()'s ?view=, assets()'s ?post_type=/?post=, the
// ?kitgenix_multistore_sync_for_woocommerce_notice= redirect flag) are read-only
// view/asset routing with no state-changing effect, so a nonce is not applicable.
final class Admin {
    public function __construct(private Plugin$plugin){}
    public function hooks():void{
        add_action('admin_menu',array($this,'menu'),20);add_action('admin_enqueue_scripts',array($this,'assets'));add_filter('plugin_action_links_'.KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_BASENAME,array($this,'links'));
        foreach(array('save_settings','factory_reset','generate_pair','connect_store','delete_store','sync_product','create_automation','delete_automation','remote_order_status','migrate_woomultistore','retry_delivery','create_store_group','delete_store_group','save_store_pricing_rules','save_store_policies','toggle_store','export_orders','bulk_order_status','resolve_conflict','remote_order_note','remote_order_refund','index_orders','bulk_job_start','bulk_job_pause','bulk_job_resume','bulk_job_stop','clear_logs')as$a){add_action('admin_post_kitgenix_multistore_sync_for_woocommerce_'.$a,array($this,$a));}
        add_action('wp_ajax_kitgenix_multistore_sync_for_woocommerce_bulk_job_poll',array($this,'bulk_job_poll'));
    }
    public function menu():void{add_submenu_page('kitgenix','Kitgenix MultiStore','MultiStore',Access::CAP,'kitgenix-multistore-sync-for-woocommerce',array($this,'render'));}
    public function assets(string$hook):void{
        wp_register_style('kitgenix-hub',KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_URL.'assets/css/kitgenix-hub.css',array(),KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION);
        wp_register_style('kitgenix-multistore-sync-for-woocommerce-admin-ui',KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_URL.'assets/css/kitgenix-admin-ui.css',array('kitgenix-hub'),KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION);
        wp_register_style('kitgenix-multistore-sync-for-woocommerce-admin',KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_URL.'assets/css/admin.css',array('kitgenix-multistore-sync-for-woocommerce-admin-ui'),KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION);
        $is_kitgenix_page=str_contains($hook,'kitgenix');
        $post_type=(string)($_GET['post_type']??(!empty($_GET['post'])?get_post_type((int)$_GET['post']):''));
        $is_product_screen=in_array($hook,array('edit.php','post.php','post-new.php'),true)&&'product'===$post_type;
        if(!$is_kitgenix_page&&!$is_product_screen)return;
        wp_register_script('kitgenix-admin-tabs',KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_URL.'assets/js/kitgenix-admin-tabs.js',array(),KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION,true);
        wp_register_script('kitgenix-admin-components',KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_URL.'assets/js/kitgenix-admin-components.js',array(),KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION,true);
        wp_enqueue_style('kitgenix-hub');wp_enqueue_style('kitgenix-multistore-sync-for-woocommerce-admin-ui');wp_enqueue_style('kitgenix-multistore-sync-for-woocommerce-admin');
        if($is_kitgenix_page){
            wp_enqueue_script('kitgenix-admin-tabs');
            wp_enqueue_script('kitgenix-admin-components');
            wp_enqueue_script('kitgenix-multistore-sync-for-woocommerce-admin',KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_URL.'assets/js/admin.js',array('kitgenix-admin-tabs','kitgenix-admin-components'),KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION,true);
        }
    }
    public function links(array$links):array{array_unshift($links,'<a href="'.esc_url(admin_url('admin.php?page=kitgenix-multistore-sync-for-woocommerce&view=settings')).'">'.esc_html__('Settings','kitgenix-multistore-sync-for-woocommerce').'</a>');return$links;}
    public function render():void{if(!Access::can_manage())wp_die(esc_html__('You do not have permission to access this page.','kitgenix-multistore-sync-for-woocommerce'));$view=sanitize_key($_GET['view']??'dashboard');$allowed=array('dashboard','stores','products','inventory','orders','customers','coupons','sync','automations','analytics','integrations','doctor','migration','logs','settings','support');if(!in_array($view,$allowed,true))$view='dashboard';
        $assets_url=KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_URL.'assets/';$social_base=$assets_url.'images/social-media/';
        echo '<div class="wrap kitgenix-admin-app kitgenix-multistore-sync-for-woocommerce" id="kitgenix-multistore-sync-for-woocommerce-admin-app">';
        echo '<div class="kitgenix-topbar">';
        echo '<div class="kitgenix-topbar-left">';
        echo '<a class="kitgenix-topbar-brand" href="'.esc_url(admin_url('admin.php?page=kitgenix')).'" title="Kitgenix"><img class="kitgenix-topbar-logo" src="'.esc_url($assets_url.'images/logos/kitgenix-primary-favicon.svg').'" alt="Kitgenix" width="28" height="28" /></a>';
        echo '<span class="kitgenix-topbar-divider" aria-hidden="true"></span>';
        echo '<div class="kitgenix-topbar-plugin-info"><span class="kitgenix-topbar-title">'.esc_html__('MultiStore for WooCommerce','kitgenix-multistore-sync-for-woocommerce').'</span><span class="kitgenix-topbar-version">v'.esc_html(KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION).'</span></div>';
        echo '</div>';
        echo '<div class="kitgenix-topbar-center">';
        echo '<ul class="kitgenix-topbar-menu" role="tablist" aria-label="'.esc_attr__('MultiStore navigation','kitgenix-multistore-sync-for-woocommerce').'">';
        $this->tabs($view);
        echo '</ul>';
        echo '</div>';
        echo '<div class="kitgenix-topbar-right" aria-label="Topbar actions">';
        echo '<div class="kitgenix-topbar-search">';
        echo '<button type="button" class="kitgenix-topbar-icon-btn kitgenix-search-toggle" aria-label="'.esc_attr__('Search settings','kitgenix-multistore-sync-for-woocommerce').'" aria-expanded="false"><svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.5232 13.4627L17.7355 16.6742L16.6742 17.7355L13.4627 14.5232C12.2678 15.4812 10.7815 16.0022 9.25 16C5.524 16 2.5 12.976 2.5 9.25C2.5 5.524 5.524 2.5 9.25 2.5C12.976 2.5 16 5.524 16 9.25C16.0022 10.7815 15.4812 12.2678 14.5232 13.4627ZM13.0187 12.9062C13.9706 11.9274 14.5021 10.6153 14.5 9.25C14.5 6.349 12.1502 4 9.25 4C6.349 4 4 6.349 4 9.25C4 12.1502 6.349 14.5 9.25 14.5C10.6153 14.5021 11.9274 13.9706 12.9062 13.0187L13.0187 12.9062V12.9062Z" fill="currentColor"></path></svg></button>';
        echo '<div class="kitgenix-search-panel">';
        echo '<span class="kitgenix-search-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.5232 13.4627L17.7355 16.6742L16.6742 17.7355L13.4627 14.5232C12.2678 15.4812 10.7815 16.0022 9.25 16C5.524 16 2.5 12.976 2.5 9.25C2.5 5.524 5.524 2.5 9.25 2.5C12.976 2.5 16 5.524 16 9.25C16.0022 10.7815 15.4812 12.2678 14.5232 13.4627ZM13.0187 12.9062C13.9706 11.9274 14.5021 10.6153 14.5 9.25C14.5 6.349 12.1502 4 9.25 4C6.349 4 4 6.349 4 9.25C4 12.1502 6.349 14.5 9.25 14.5C10.6153 14.5021 11.9274 13.9706 12.9062 13.0187L13.0187 12.9062V12.9062Z" fill="currentColor"></path></svg></span>';
        echo '<input type="search" class="kitgenix-topbar-search-input" placeholder="'.esc_attr__('Search settings…','kitgenix-multistore-sync-for-woocommerce').'" aria-label="'.esc_attr__('Search settings','kitgenix-multistore-sync-for-woocommerce').'" autocomplete="off" />';
        echo '<kbd class="kitgenix-search-kbd" title="Press / or ⌘K to search">/</kbd>';
        echo '<button type="button" class="kitgenix-search-clear" aria-label="'.esc_attr__('Clear search','kitgenix-multistore-sync-for-woocommerce').'" style="display:none;">&times;</button>';
        echo '</div>';
        echo '</div>';
        echo '<button type="button" class="kitgenix-topbar-icon-btn kitgenix-theme-toggle" aria-label="'.esc_attr__('Toggle color theme','kitgenix-multistore-sync-for-woocommerce').'" title="'.esc_attr__('Toggle color theme','kitgenix-multistore-sync-for-woocommerce').'"><svg class="kitgenix-theme-icon-light" width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="3.25" stroke="currentColor" stroke-width="1.3"></circle><path d="M10 2.5v2M10 15.5v2M17.5 10h-2M4.5 10h-2M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4M15.3 15.3l-1.4-1.4M6.1 6.1 4.7 4.7" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path></svg><svg class="kitgenix-theme-icon-dark" width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16.5 12.3A6.5 6.5 0 0 1 7.7 3.5a6.5 6.5 0 1 0 8.8 8.8Z" fill="currentColor"></path></svg></button>';
        echo '<a class="kitgenix-topbar-icon-btn" href="'.esc_url(admin_url('admin.php?page=kitgenix')).'" title="'.esc_attr__('Kitgenix Hub','kitgenix-multistore-sync-for-woocommerce').'" aria-label="Kitgenix Hub"><svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.25 3.25H9.25V9.25H3.25V3.25ZM10.75 3.25H16.75V9.25H10.75V3.25ZM3.25 10.75H9.25V16.75H3.25V10.75ZM10.75 10.75H16.75V16.75H10.75V10.75Z" fill="currentColor"></path></svg></a>';
        echo '<button type="button" class="kitgenix-topbar-hamburger" aria-label="'.esc_attr__('Toggle navigation','kitgenix-multistore-sync-for-woocommerce').'"><span></span><span></span><span></span></button>';
        echo '</div>';
        echo '</div>';
        $notice=sanitize_text_field($_GET['kitgenix_multistore_sync_for_woocommerce_notice']??'');if($notice){echo '<div class="kitgenix-settings-layout"><div class="kitgenix-notice kitgenix-notice-success" role="status"><span class="kitgenix-notice-icon" aria-hidden="true">'.$this->icon('success').'</span><div class="kitgenix-notice-body"><p class="kitgenix-notice-text">'.esc_html($notice).'</p></div></div></div>';}
        echo '<div class="kitgenix-settings-layout"><div class="kitgenix-settings-content" id="kitgenix-settings-content" tabindex="-1"><main class="kitgenix-multistore-sync-for-woocommerce-content">';$method='view_'.$view;$this->{$method}();echo '</main></div>';$this->render_sidebar($view);echo '</div></div>';}
    private function render_sidebar(string$view=''):void{
        $social_base=KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_URL.'assets/images/social-media/';
        echo '<aside class="kitgenix-settings-sidebar" aria-label="'.esc_attr__('Help and links','kitgenix-multistore-sync-for-woocommerce').'">';
        echo '<div class="kitgenix-card"><div class="kitgenix-card-body">'
            .'<h2>'.esc_html__('Need Help?','kitgenix-multistore-sync-for-woocommerce').'</h2>'
            .'<p>'.esc_html__('Open the documentation for setup guidance or send us a support request if you need help configuring the plugin.','kitgenix-multistore-sync-for-woocommerce').'</p>'
            .'<div class="kitgenix-button-group kitgenix-button-group-stack">'
            .'<a class="button button-secondary" href="'.esc_url('https://kitgenix.com/plugins/kitgenix-multistore-sync-for-woocommerce/documentation/').'" target="_blank" rel="noopener noreferrer">'.esc_html__('Documentation','kitgenix-multistore-sync-for-woocommerce').'</a>'
            .'<a class="button button-primary" href="'.esc_url('https://kitgenix.com/plugins/kitgenix-multistore-sync-for-woocommerce/support').'" target="_blank" rel="noopener noreferrer">'.esc_html__('Request Support','kitgenix-multistore-sync-for-woocommerce').'</a>'
            .'<a class="button button-secondary" href="'.esc_url('https://roadmap.kitgenix.com/?product=multistore-sync-for-woocommerce').'" target="_blank" rel="noopener noreferrer">'.esc_html__('Request a Feature','kitgenix-multistore-sync-for-woocommerce').'</a>'
            .'</div>'
            .'</div></div>';
        echo '<div class="kitgenix-card"><div class="kitgenix-card-body">'
            .'<h2>'.esc_html__('Visit Our Official Facebook Group','kitgenix-multistore-sync-for-woocommerce').'</h2>'
            .'<p>'.esc_html__('Join the Kitgenix community to ask questions, share feedback, and keep up with product updates.','kitgenix-multistore-sync-for-woocommerce').'</p>'
            .'<div class="kitgenix-button-group kitgenix-button-group-stack">'
            .'<a class="button button-secondary" href="'.esc_url('https://www.facebook.com/groups/kitgenix').'" target="_blank" rel="noopener noreferrer">'.esc_html__('Join Group','kitgenix-multistore-sync-for-woocommerce').'</a>'
            .'</div>'
            .'</div></div>';
        echo '<div class="kitgenix-card"><div class="kitgenix-card-body">'
            .'<h2>'.esc_html__('Follow Us','kitgenix-multistore-sync-for-woocommerce').'</h2>'
            .'<p>'.esc_html__('Keep up with new releases, tutorials, and product news across our channels.','kitgenix-multistore-sync-for-woocommerce').'</p>'
            .'<div class="kitgenix-sidebar-social-grid">'
            .'<a class="kitgenix-sidebar-social-link" href="https://kitgenix.com" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="'.esc_url($social_base.'globe-solid.svg').'" alt="" width="16" height="16" aria-hidden="true" /></a>'
            .'<a class="kitgenix-sidebar-social-link" href="https://www.facebook.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><img src="'.esc_url($social_base.'facebook-solid.svg').'" alt="" width="16" height="16" aria-hidden="true" /></a>'
            .'<a class="kitgenix-sidebar-social-link" href="https://www.instagram.com/kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram"><img src="'.esc_url($social_base.'instagram-solid.svg').'" alt="" width="16" height="16" aria-hidden="true" /></a>'
            .'<a class="kitgenix-sidebar-social-link" href="https://www.youtube.com/@Kitgenix" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube"><img src="'.esc_url($social_base.'youtube-solid.svg').'" alt="" width="16" height="16" aria-hidden="true" /></a>'
            .'<a class="kitgenix-sidebar-social-link" href="https://www.reddit.com/r/Kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Reddit" title="Reddit"><img src="'.esc_url($social_base.'reddit-solid.svg').'" alt="" width="16" height="16" aria-hidden="true" /></a>'
            .'<a class="kitgenix-sidebar-social-link" href="https://www.linkedin.com/company/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><img src="'.esc_url($social_base.'linkedin-solid.svg').'" alt="" width="16" height="16" aria-hidden="true" /></a>'
            .'<a class="kitgenix-sidebar-social-link" href="https://x.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="X" title="X"><img src="'.esc_url($social_base.'x-solid.svg').'" alt="" width="16" height="16" aria-hidden="true" /></a>'
            .'<a class="kitgenix-sidebar-social-link" href="https://www.tiktok.com/@kitgenix" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok"><img src="'.esc_url($social_base.'tiktok-solid.svg').'" alt="" width="16" height="16" aria-hidden="true" /></a>'
            .'<a class="kitgenix-sidebar-social-link" href="https://github.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="GitHub"><img src="'.esc_url($social_base.'github-solid.svg').'" alt="" width="16" height="16" aria-hidden="true" /></a>'
            .'</div>'
            .'</div></div>';
        echo '</aside>';
    }
    private function tabs(string$current):void{
        $link=function(string$k)use($current):array{return array('key'=>$k,'url'=>admin_url('admin.php?page=kitgenix-multistore-sync-for-woocommerce&view='.$k),'active'=>$current===$k);};
        $flat_item=function(array$link,string$label):void{
            echo '<li class="kitgenix-menu-item '.($link['active']?'kitgenix-active':'').'"><a class="kitgenix-menu-link '.($link['active']?'kitgenix-active':'').'" href="'.esc_url($link['url']).'">'.esc_html($label).'</a></li>';
        };
        $submenu_item=function(array$link,string$icon,string$title,string$desc):string{
            return '<a class="kitgenix-submenu-item" href="'.esc_url($link['url']).'"><span class="kitgenix-submenu-icon">'.$icon.'</span><span class="kitgenix-submenu-text"><span class="kitgenix-submenu-title">'.esc_html($title).'</span><span class="kitgenix-submenu-desc">'.esc_html($desc).'</span></span></a>';
        };
        $dropdown=function(string$label,array$member_keys,array$items)use($current):void{
            $active=in_array($current,$member_keys,true);
            echo '<li class="kitgenix-menu-item kitgenix-has-dropdown '.($active?'kitgenix-active':'').'">'
                .'<button type="button" class="kitgenix-menu-link kitgenix-menu-toggle" aria-haspopup="true" aria-expanded="false">'.esc_html($label).'<span class="kitgenix-menu-caret"><svg width="12" height="12" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9.99999 10.879L13.7125 7.1665L14.773 8.227L9.99999 13L5.22699 8.227L6.28749 7.1665L9.99999 10.879Z" fill="currentColor"></path></svg></span></button>'
                .'<div class="kitgenix-submenu">'.implode('',$items).'</div>'
                .'</li>';
        };

        $icon_products='<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2.5 16.5 6v8L10 17.5 3.5 14V6L10 2.5Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"></path><path d="M3.5 6 10 9.5 16.5 6M10 9.5V17.5" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"></path></svg>';
        $icon_inventory='<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3.5" y="4" width="13" height="4" rx="1" stroke="currentColor" stroke-width="1.3"></rect><rect x="3.5" y="10" width="13" height="4" rx="1" stroke="currentColor" stroke-width="1.3"></rect><path d="M6 6h.01M6 12h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"></path></svg>';
        $icon_coupons='<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9.17489 2.57495L16.5991 3.6362L17.6596 11.0612L10.7656 17.9552C10.625 18.0958 10.4343 18.1748 10.2354 18.1748C10.0365 18.1748 9.84578 18.0958 9.70514 17.9552L2.28014 10.5302C2.13953 10.3896 2.06055 10.1988 2.06055 9.99995C2.06055 9.80108 2.13953 9.61035 2.28014 9.4697L9.17489 2.57495ZM9.70514 4.16645L3.87089 9.99995L10.2354 16.3637L16.0689 10.5302L15.2739 4.96145L9.70514 4.16645ZM11.2951 8.93945C11.0138 8.65799 10.8557 8.27629 10.8558 7.87831C10.8559 7.68125 10.8947 7.48613 10.9701 7.30409C11.0456 7.12204 11.1561 6.95664 11.2955 6.81733C11.4349 6.67801 11.6003 6.56751 11.7824 6.49213C11.9645 6.41675 12.1596 6.37797 12.3567 6.37801C12.7546 6.37808 13.1363 6.53624 13.4176 6.8177C13.699 7.09916 13.857 7.48087 13.857 7.87884C13.8569 8.27682 13.6987 8.65846 13.4173 8.93983C13.1358 9.22119 12.7541 9.37921 12.3561 9.37914C11.9581 9.37907 11.5765 9.22091 11.2951 8.93945Z" fill="currentColor"></path></svg>';
        $icon_orders='<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 3.5h1.5l1.7 9.3a1 1 0 0 0 1 .82h6.66a1 1 0 0 0 .98-.8L16 7H5.4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"></path><circle cx="8.2" cy="16.3" r="1" fill="currentColor"></circle><circle cx="13.5" cy="16.3" r="1" fill="currentColor"></circle></svg>';
        $icon_customers='<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="7.5" cy="7" r="2.5" stroke="currentColor" stroke-width="1.3"></circle><path d="M2.8 16.5c.6-2.8 2.4-4.5 4.7-4.5s4.1 1.7 4.7 4.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path><circle cx="14" cy="7.5" r="2" stroke="currentColor" stroke-width="1.3"></circle><path d="M12.8 12.3c1.8.2 3.2 1.7 3.7 4.2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path></svg>';
        $icon_analytics='<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.5 16.5V11M8 16.5V7M12.5 16.5V9.5M17 16.5V4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>';
        $icon_sync='<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.5313 12.3274C16.0179 11.1711 16.1299 9.89137 15.8516 8.66813C15.5732 7.4449 14.9185 6.33966 13.9795 5.5078C13.0404 4.67595 11.8643 4.15931 10.6164 4.03053C9.36853 3.90175 8.11167 4.16731 7.02253 4.78986L6.27853 3.48711C7.41675 2.83657 8.70567 2.49589 10.0167 2.49905C11.3277 2.5022 12.615 2.8491 13.75 3.50511C17.1175 5.44911 18.4075 9.61161 16.8378 13.0826L17.8443 13.6631L14.7205 15.3236L14.5968 11.7881L15.5313 12.3274V12.3274ZM4.46878 7.67286C3.98214 8.82914 3.87012 10.1089 4.14847 11.3321C4.42682 12.5553 5.08154 13.6606 6.02058 14.4924C6.95963 15.3243 8.13577 15.8409 9.38365 15.9697C10.6315 16.0985 11.8884 15.8329 12.9775 15.2104L13.7215 16.5131C12.5833 17.1637 11.2944 17.5043 9.98338 17.5012C8.67237 17.498 7.38511 17.1511 6.25003 16.4951C2.88253 14.5511 1.59253 10.3886 3.16228 6.91761L2.15503 6.33786L5.27878 4.67736L5.40253 8.21286L4.46803 7.67361L4.46878 7.67286Z" fill="currentColor"></path></svg>';
        $icon_automations='<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11 2.5 4.5 11.5h4.2L8 17.5l7-9.5H10.8L11 2.5Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"></path></svg>';
        $icon_integrations='<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 4v3M13 4v3M5.5 7h9v2.5a4.5 4.5 0 0 1-9 0V7Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"></path><path d="M10 14v3.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path></svg>';
        $icon_doctor='<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2.5 10h3l1.5-4 2.5 8 2-6 1.5 2h4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"></path></svg>';

        $flat_item($link('dashboard'),'Dashboard');
        $flat_item($link('stores'),'Stores');
        $dropdown('Catalog',array('products','inventory','coupons'),array(
            $submenu_item($link('products'),$icon_products,'Products','Network products with stable UUID mappings'),
            $submenu_item($link('inventory'),$icon_inventory,'Inventory','Authoritative stock levels across every store'),
            $submenu_item($link('coupons'),$icon_coupons,'Coupons','Coupons synchronised from the control store'),
        ));
        $dropdown('Sales',array('orders','customers','analytics'),array(
            $submenu_item($link('orders'),$icon_orders,'Orders','Search, export and manage orders network-wide'),
            $submenu_item($link('customers'),$icon_customers,'Customers','Unique customer records across connected stores'),
            $submenu_item($link('analytics'),$icon_analytics,'Analytics','30-day network sales, split by currency'),
        ));
        $dropdown('Sync',array('sync','automations','integrations','doctor'),array(
            $submenu_item($link('sync'),$icon_sync,'Sync Centre','Events, deliveries and open conflicts'),
            $submenu_item($link('automations'),$icon_automations,'Automations','Rules that react to stock and order changes'),
            $submenu_item($link('integrations'),$icon_integrations,'Integrations','Adapter status for WooCommerce and extensions'),
            $submenu_item($link('doctor'),$icon_doctor,'Network Doctor','Diagnose connectivity and delivery health'),
        ));
        $flat_item($link('settings'),'Settings');
        $flat_item($link('logs'),__('Log','kitgenix-multistore-sync-for-woocommerce'));
        $flat_item($link('support'),__('Support','kitgenix-multistore-sync-for-woocommerce'));
    }

    /* ─────────────────────────────────────────────────────────────────
       Small inline icon set reused across cards / empty states / notices.
       Purely presentational – returns raw (trusted, hand-authored) SVG.
       ───────────────────────────────────────────────────────────────── */
    private function icon(string$name):string{
        $icons=array(
            'success'=>'<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 10.5 8 14.5 16 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
            'warn'=>'<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 3 2.5 16.5h15L10 3Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"></path><path d="M10 8.2v3.2M10 14h.01" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"></path></svg>',
            'store'=>'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 7l1-4h12l1 4M3 7v9a1 1 0 0 0 1 1h3v-5h6v5h3a1 1 0 0 0 1-1V7M3 7h14" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"></path></svg>',
            'link'=>'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8.5 11.5a3 3 0 0 0 4 .25l2-2a3 3 0 0 0-4.24-4.24l-1 1M11.5 8.5a3 3 0 0 0-4-.25l-2 2a3 3 0 0 0 4.24 4.24l1-1" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path></svg>',
            'box'=>'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2.5 16.5 6v8L10 17.5 3.5 14V6L10 2.5Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"></path><path d="M3.5 6 10 9.5 16.5 6M10 9.5V17.5" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"></path></svg>',
            'gear'=>'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="2.6" stroke="currentColor" stroke-width="1.3"></circle><path d="M10 3v2M10 15v2M17 10h-2M5 10H3M14.9 5.1l-1.4 1.4M6.5 13.5l-1.4 1.4M14.9 14.9l-1.4-1.4M6.5 6.5 5.1 5.1" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path></svg>',
            'clock'=>'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.3"></circle><path d="M10 6v4l3 2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
            'users'=>'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="7.5" cy="7" r="2.5" stroke="currentColor" stroke-width="1.3"></circle><path d="M2.8 16.5c.6-2.8 2.4-4.5 4.7-4.5s4.1 1.7 4.7 4.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path><circle cx="14" cy="7.5" r="2" stroke="currentColor" stroke-width="1.3"></circle><path d="M12.8 12.3c1.8.2 3.2 1.7 3.7 4.2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path></svg>',
            'coupon'=>'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9.17 2.57 16.6 3.64l1.06 7.42-6.9 6.9a.83.83 0 0 1-1.17 0L2.28 10.53a.83.83 0 0 1 0-1.17l6.89-6.79Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"></path><circle cx="12.35" cy="7.88" r="1.5" fill="currentColor"></circle></svg>',
            'heart'=>'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 17s-6.5-4-6.5-9A3.5 3.5 0 0 1 10 5.8 3.5 3.5 0 0 1 16.5 8c0 5-6.5 9-6.5 9Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"></path></svg>',
            'doctor'=>'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2.5 10h3l1.5-4 2.5 8 2-6 1.5 2h4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
            'sync'=>'<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16 10a6 6 0 0 1-10.6 3.8M4 10a6 6 0 0 1 10.6-3.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M4 13.8V17h3.2M16 6.2V3h-3.2" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
            'tools'=>'<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12.5 4.5a3 3 0 0 0-3.9 3.9l-6.1 6.1 2 2 6.1-6.1a3 3 0 0 0 3.9-3.9l-2.1 2.1-2-2 2.1-2.1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
            'check'=>'<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 10.5 8 14.5 16 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'logs'=>'<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3.5" y="3" width="13" height="14" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 7h7M6.5 10h7M6.5 13h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'audit'=>'<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 3.5h7l3 3V16a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7 9h6M7 12h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'chat'=>'<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.5 5.5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H9l-3.5 3v-3a2 2 0 0 1-2-2v-6Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
            'star'=>'<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 3.2l2.02 4.1 4.53.66-3.28 3.2.78 4.52L10 13.5l-4.05 2.13.78-4.52-3.28-3.2 4.53-.66L10 3.2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
            'copy'=>'<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="7" y="7" width="9.5" height="9.5" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M13 7V4.5A1.5 1.5 0 0 0 11.5 3h-7A1.5 1.5 0 0 0 3 4.5v7A1.5 1.5 0 0 0 4.5 13H7" stroke="currentColor" stroke-width="1.5"/></svg>',
            'download'=>'<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 3v9.5M6 9l4 4 4-4M4 16.5h12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"></path></svg>',
            'search'=>'<svg width="26" height="26" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.3"></circle><path d="m17 17-3.5-3.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path></svg>',
            'shield'=>'<svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2.5l6 2.2v4.4c0 4.2-2.6 7.4-6 8.4-3.4-1-6-4.2-6-8.4V4.7l6-2.2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7.3 10.2l1.9 1.9 3.5-3.9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'paypal'=>'<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6.7 15.5H4.9a.3.3 0 0 1-.3-.35L6.5 3.8a.5.5 0 0 1 .5-.4h4.4c2 0 3.4 1.2 3.1 3.2-.3 2.4-2.1 3.7-4.3 3.7H8.6a.5.5 0 0 0-.5.4l-.7 3.9a.5.5 0 0 1-.5.4Z" fill="currentColor" opacity=".55"/><path d="M9.2 15.5H7.4a.3.3 0 0 1-.3-.35L9 3.8a.5.5 0 0 1 .5-.4h4.4c2 0 3.4 1.2 3.1 3.2-.3 2.4-2.1 3.7-4.3 3.7h-1.6a.5.5 0 0 0-.5.4l-.7 3.9a.5.5 0 0 1-.5.4Z" fill="currentColor"/></svg>',
        );
        return$icons[$name]??'';
    }

    private function view_dashboard():void{
        $stores=$this->plugin->get('stores')->all();$events=$this->plugin->get('events')->counts();$inventory=$this->plugin->get('inventory');$products=$this->plugin->get('mappings')->count('product');$orders=$this->plugin->get('orders')->count();$active=count(array_filter($stores,fn($s)=>'active'===$s['status']));$offline=count(array_filter($stores,fn($s)=>'offline'===$s['status']));
        echo '<div class="kitgenix-stat-grid">'
            .$this->metric('Connected stores',$active,$offline?'Offline: '.$offline:'All healthy')
            .$this->metric('Network products',$products,'Stable UUID mappings')
            .$this->metric('Inventory records',$inventory->count(),$inventory->low_stock_count().' low / out')
            .$this->metric('Remote orders',$orders,'Central order snapshots')
            .'</div>';
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('gear').'</span><div class="kitgenix-card-head-text"><h2>Sync engine</h2><p>Live counts across every queued delivery.</p></div></div><div class="kitgenix-card-head-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=kitgenix-multistore-sync-for-woocommerce&view=sync')).'">View Sync Centre</a></div></div><div class="kitgenix-card-body"><div class="kitgenix-stat-grid" style="margin-bottom:0">';
        foreach(array('pending'=>'Pending','processing'=>'Processing','retrying'=>'Retrying','completed'=>'Completed','failed'=>'Failed')as$k=>$label)echo $this->metric($label,(int)($events[$k]??0));
        echo '</div></div></div>';
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('link').'</span><div class="kitgenix-card-head-text"><h2>Architecture status</h2></div></div></div><div class="kitgenix-card-body"><p>Server-to-server HMAC authentication, persistent deliveries, idempotent event IDs, inventory revisions and automatic retry/reconciliation are active. <strong>'.esc_html((string)$this->plugin->get('conflicts')->open_count()).'</strong> sync conflicts currently require review.</p></div></div>';
    }

    private function view_stores(): void {
        $settings = $this->plugin->get('settings'); $stores = $this->plugin->get('stores')->all();
        echo '<div class="kitgenix-stat-grid">';
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('store').'</span><div class="kitgenix-card-head-text"><h2>Network role</h2></div></div></div><div class="kitgenix-card-body">';
        echo '<div class="kitgenix-settings-group">'
            .'<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label>Role</label></div><div class="kitgenix-setting-row-control"><span class="kitgenix-badge brand">'.esc_html(ucfirst($settings->role())).'</span></div></div>'
            .'<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label>Mode</label></div><div class="kitgenix-setting-row-control">'.esc_html('native_multisite'===$settings->get('network_mode','paired')?'Native WordPress Multisite':'Paired stores').'</div></div>'
            .'<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label>Site identifier</label></div><div class="kitgenix-setting-row-control"><code>'.esc_html(Uuid::site()).'</code></div></div>'
            .'</div>';
        if($settings->is_connected()&&'native_multisite'!==$settings->get('network_mode','paired')){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_generate_pair">';
            wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_generate_pair');
            echo '<div class="kitgenix-button-group">';submit_button('Generate one-time pairing code','secondary','submit',false);echo '</div></form>';
            if(!empty($_GET['pair_code']))echo '<div class="kitgenix-notice kitgenix-notice-info" role="status"><span class="kitgenix-notice-icon" aria-hidden="true">'.$this->icon('success').'</span><div class="kitgenix-notice-body"><p class="kitgenix-notice-title">'.esc_html(sanitize_text_field($_GET['pair_code'])).'</p><p class="kitgenix-notice-text">Expires in 10 minutes.</p></div></div>';
        }
        echo '</div></div>';
        if($settings->is_control()&&'native_multisite'!==$settings->get('network_mode','paired')){
            echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('link').'</span><div class="kitgenix-card-head-text"><h2>Connect a store</h2></div></div></div><div class="kitgenix-card-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_connect_store">';
            wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_connect_store');
            echo '<div class="kitgenix-settings-group">'
                .'<div class="kitgenix-setting-row kitgenix-setting-row-stacked"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_store_url">Store URL</label></div><div class="kitgenix-setting-row-control"><input type="url" id="kitgenix_field_store_url" name="store_url" placeholder="https://store.example.com" required></div></div>'
                .'<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_pair_code">Pairing code</label></div><div class="kitgenix-setting-row-control"><input type="text" id="kitgenix_field_pair_code" name="pair_code" required></div></div>'
                .'</div><div class="kitgenix-button-group kitgenix-button-group-end">';
            submit_button('Connect store','primary','submit',false);
            echo '</div></form></div></div>';
        }
        echo '</div>';

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('store').'</span><div class="kitgenix-card-head-text"><h2>Connected stores</h2><p>Pause a store to keep its connection and mappings without sending new deliveries.</p></div></div></div><div class="kitgenix-card-body">';
        if($stores){
            $status_meta=array(
                'active'=>array('badge'=>'success','label'=>'Connected','hint'=>'Reachable, authenticated, and running compatible versions.'),
                'warning'=>array('badge'=>'warning','label'=>'Warning','hint'=>'Reachable and authenticated, but running an outdated WordPress/WooCommerce version or missing Action Scheduler.'),
                'offline'=>array('badge'=>'danger','label'=>'Offline','hint'=>'The last health check could not reach this store.'),
                'auth_failed'=>array('badge'=>'danger','label'=>'Authentication Failed','hint'=>'The store responded, but rejected the signed request – the pairing secret may be out of date.'),
                'version_incompatible'=>array('badge'=>'danger','label'=>'Version Incompatible','hint'=>'The other store is running a Kitgenix MultiStore major version that is not compatible with this one.'),
                'disabled'=>array('badge'=>'neutral','label'=>'Paused','hint'=>'Deliveries are held until this store is resumed.'),
                'unknown'=>array('badge'=>'neutral','label'=>'Unknown','hint'=>'No health check has completed yet.'),
            );
            echo '<div class="kitgenix-table-wrap"><table class="kitgenix-table"><thead><tr><th>Store</th><th>Role</th><th>Status</th><th>Versions</th><th>Last contact</th><th class="kitgenix-table-actions-col">Actions</th></tr></thead><tbody>';
            foreach($stores as$st){
                $paused='disabled'===$st['status'];
                $toggle=wp_nonce_url(admin_url('admin-post.php?action=kitgenix_multistore_sync_for_woocommerce_toggle_store&uuid='.rawurlencode($st['uuid']).'&status='.($paused?'active':'disabled')),'kitgenix_multistore_sync_for_woocommerce_toggle_store_'.$st['uuid']);
                $meta=$status_meta[$st['status']]??$status_meta['unknown'];
                $caps=$this->plugin->get('stores')->capabilities($st);
                $versions='<code title="Kitgenix MultiStore plugin version">v'.esc_html($caps['kitgenix']??'–').'</code><br><span class="kitgenix-setting-row-desc" style="margin:0">WP '.esc_html($caps['wordpress']??'–').' · WC '.esc_html($caps['woocommerce']??'–').'</span>';
                echo '<tr><td><strong>'.esc_html($st['name']).'</strong><br><a href="'.esc_url($st['url']).'" target="_blank" rel="noopener">'.esc_html($st['url']).'</a><br><code title="Store ID" style="font-size:11px">'.esc_html($st['uuid']).'</code></td>';
                echo '<td>'.esc_html(ucfirst($st['role'])).'</td>';
                echo '<td><span class="kitgenix-badge '.esc_attr($meta['badge']).' kitgenix-badge-dot" title="'.esc_attr($meta['hint']).'">'.esc_html($meta['label']).'</span></td>';
                echo '<td>'.$versions.'</td>';
                echo '<td>'.esc_html($st['last_seen']?:'–').'</td>';
                echo '<td><div class="kitgenix-table-actions"><a class="button" href="'.esc_url($toggle).'">'.($paused?'Resume':'Pause').'</a> <a class="button-link-delete" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=kitgenix_multistore_sync_for_woocommerce_delete_store&uuid='.rawurlencode($st['uuid'])),'kitgenix_multistore_sync_for_woocommerce_delete_store')).'" data-kitgenix-multistore-sync-for-woocommerce_confirm="Disconnect this store?">Disconnect</a></div></td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<div class="kitgenix-empty-state"><span class="kitgenix-empty-state-icon" aria-hidden="true">'.$this->icon('store').'</span><h3 class="kitgenix-empty-state-title">No stores connected yet</h3><p class="kitgenix-empty-state-desc">Generate a pairing code above, or connect a store once you have one.</p></div>';
        }
        echo '</div></div>';

        if(!$settings->is_control())return;
        $connected=array_values(array_filter($stores,static fn($st)=>'connected'===($st['role']??'')));
        if($connected){
            $pricing=$settings->pricing_rules();
            echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('gear').'</span><div class="kitgenix-card-head-text"><h2>Store pricing rules</h2><p>Choose a percentage, fixed amount, or exchange-multiplier adjustment per store, an optional rounding strategy, and how sale prices should be handled. Rules are always calculated fresh from the source price on every sync – they never compound.</p></div></div></div><div class="kitgenix-card-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_save_store_pricing_rules');
            $mode_labels=array('percentage'=>'Percentage','fixed'=>'Fixed amount','exchange'=>'Exchange multiplier');
            $rounding_labels=array('none'=>'None','nearest_whole'=>'Nearest whole number','nearest_0_50'=>'Nearest 0.50','charm_95'=>'Charm pricing (X.95)','charm_99'=>'Charm pricing (X.99)');
            $sale_labels=array('same_adjustment'=>'Apply the same rule to sale price','maintain_discount_percent'=>'Keep the original discount %','source'=>'Leave sale price as-is (regular price only)','clear'=>'Never send a sale price');
            echo '<input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_save_store_pricing_rules"><div class="kitgenix-table-wrap"><table class="kitgenix-table"><thead><tr><th>Store</th><th>Enabled</th><th>Mode</th><th>Percentage</th><th>Fixed amount</th><th>Multiplier</th><th>Rounding</th><th>Sale price</th><th>Example</th></tr></thead><tbody>';
            foreach($connected as$st){
                $r=$pricing[$st['uuid']]??$settings->pricing_rule('');$uuid=esc_attr($st['uuid']);
                $mode=(string)($r['mode']??'percentage');$pct=(float)($r['percentage']??0);$fixed=(float)($r['fixed_amount']??0);$mult=(float)($r['multiplier']??1);$rounding=(string)($r['rounding']??'none');$sale_mode=(string)($r['sale_mode']??'same_adjustment');
                $example=\Kitgenix\MultiStore\Pricing\PriceCalculator::apply_for_store(array('regular_price'=>'100','sale_price'=>'80'),$r,array(),$st['uuid']);
                echo '<tr><td><strong>'.esc_html($st['name']).'</strong></td>';
                echo '<td><label class="kitgenix-toggle"><input type="checkbox" class="kitgenix-toggle-input" name="pricing_enabled['.$uuid.']" value="yes" '.checked(($r['enabled']??'no'),'yes',false).'><span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span></label></td>';
                echo '<td><select name="pricing_mode['.$uuid.']">';
                foreach($mode_labels as$k=>$label)echo '<option value="'.esc_attr($k).'" '.selected($mode,$k,false).'>'.esc_html($label).'</option>';
                echo '</select></td>';
                echo '<td><input type="number" step="0.01" min="-100" max="1000" name="pricing_percentage['.$uuid.']" value="'.esc_attr((string)$pct).'" style="max-width:90px"> %</td>';
                echo '<td><input type="number" step="0.01" name="pricing_fixed_amount['.$uuid.']" value="'.esc_attr((string)$fixed).'" style="max-width:90px"></td>';
                echo '<td><input type="number" step="0.0001" min="0" name="pricing_multiplier['.$uuid.']" value="'.esc_attr((string)$mult).'" style="max-width:90px">×</td>';
                echo '<td><select name="pricing_rounding['.$uuid.']">';
                foreach($rounding_labels as$k=>$label)echo '<option value="'.esc_attr($k).'" '.selected($rounding,$k,false).'>'.esc_html($label).'</option>';
                echo '</select></td>';
                echo '<td><select name="pricing_sale_mode['.$uuid.']">';
                foreach($sale_labels as$k=>$label)echo '<option value="'.esc_attr($k).'" '.selected($sale_mode,$k,false).'>'.esc_html($label).'</option>';
                echo '</select></td>';
                echo '<td><code>£100 / £80 → '.esc_html($example['regular_price']).' / '.esc_html('' === $example['sale_price'] ? '–' : $example['sale_price']).'</code></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div><p style="margin-top:14px"><label class="kitgenix-toggle"><input type="checkbox" class="kitgenix-toggle-input" name="resync_products" value="yes" checked><span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span><span class="kitgenix-toggle-label">Queue all products after saving</span></label></p><div class="kitgenix-button-group kitgenix-button-group-end">';
            submit_button('Save pricing rules','primary','submit',false);
            echo '</div></form></div></div>';

            $policies=$settings->policies();
            echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('gear').'</span><div class="kitgenix-card-head-text"><h2>Per-store publish &amp; update policies</h2><p>Choose exactly which fields a store receives on first publish and on later updates.</p></div></div></div><div class="kitgenix-card-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_save_store_policies');
            echo '<input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_save_store_policies">';
            foreach($connected as$st){
                $policy=$policies[$st['uuid']]??$settings->default_policy();
                echo '<div class="kitgenix-card" data-kitgenix-collapsible><button type="button" class="kitgenix-collapsible-trigger" aria-expanded="true"><span class="kitgenix-card-head-main"><span class="kitgenix-collapsible-title">'.esc_html($st['name']).'</span></span><svg class="kitgenix-collapsible-chevron" aria-hidden="true" width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 7.5 10 12.5 15 7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg></button><div class="kitgenix-collapsible-panel"><div class="kitgenix-table-wrap"><table class="kitgenix-table"><thead><tr><th>Field</th><th>Initial publish</th><th>Later updates</th></tr></thead><tbody>';
                foreach(Settings::policy_fields()as$key=>$label){
                    $row=$policy['fields'][$key]??array('publish'=>'yes','update'=>'yes');
                    echo '<tr><td>'.esc_html($label).'</td><td><label class="kitgenix-toggle"><input type="checkbox" class="kitgenix-toggle-input" name="policy['.esc_attr($st['uuid']).']['.esc_attr($key).'][publish]" value="yes" '.checked(($row['publish']??'yes'),'yes',false).'><span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span></label></td><td><label class="kitgenix-toggle"><input type="checkbox" class="kitgenix-toggle-input" name="policy['.esc_attr($st['uuid']).']['.esc_attr($key).'][update]" value="yes" '.checked(($row['update']??'yes'),'yes',false).'><span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span></label></td></tr>';
                }
                echo '</tbody></table></div></div></div>';
            }
            echo '<p style="margin-top:14px"><label class="kitgenix-toggle"><input type="checkbox" class="kitgenix-toggle-input" name="resync_products" value="yes"><span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span><span class="kitgenix-toggle-label">Queue products after saving policies</span></label></p><div class="kitgenix-button-group kitgenix-button-group-end">';
            submit_button('Save store policies','primary','submit',false);
            echo '</div></form></div></div>';
        }

        $groups=$settings->groups();
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('store').'</span><div class="kitgenix-card-head-text"><h2>Store groups</h2></div></div></div><div class="kitgenix-card-body"><div class="kitgenix-stat-grid" style="margin-bottom:0"><div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_create_store_group">';
        wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_create_store_group');
        echo '<div class="kitgenix-settings-group"><div class="kitgenix-setting-row kitgenix-setting-row-stacked"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_group_name">Group name</label></div><div class="kitgenix-setting-row-control"><input type="text" id="kitgenix_field_group_name" name="group_name" required></div></div><div class="kitgenix-setting-row kitgenix-setting-row-stacked"><div class="kitgenix-setting-row-label"><label>Stores</label></div><div class="kitgenix-setting-row-control"><span class="kitgenix-multistore-sync-for-woocommerce-checklist">';
        foreach($connected as$st)echo '<label><input type="checkbox" name="group_stores[]" value="'.esc_attr($st['uuid']).'"> '.esc_html($st['name']).'</label>';
        echo '</span></div></div></div><div class="kitgenix-button-group">';
        submit_button('Create group','secondary','submit',false);
        echo '</div></form></div><div>';
        foreach($groups as$gid=>$group)echo '<div class="kitgenix-multistore-sync-for-woocommerce-rule"><div><strong>'.esc_html($group['name']).'</strong><small>'.esc_html(count($group['stores']).' stores').'</small></div><a class="button-link-delete" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=kitgenix_multistore_sync_for_woocommerce_delete_store_group&group='.rawurlencode($gid)),'kitgenix_multistore_sync_for_woocommerce_delete_store_group_'.$gid)).'">Delete</a></div>';
        if(!$groups)echo '<div class="kitgenix-empty-state"><p class="kitgenix-empty-state-title">No store groups yet</p></div>';
        echo '</div></div></div></div>';
    }

    private function view_products():void{
        $rows=$this->plugin->get('mappings')->for_store(Uuid::site(),'product',100);
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('box').'</span><div class="kitgenix-card-head-text"><h2>Network products</h2><p>Products use stable network IDs rather than WordPress post IDs or SKU alone.</p></div></div><div class="kitgenix-card-head-actions"><a class="button" href="'.esc_url(admin_url('edit.php?post_type=product')).'">Open WooCommerce products</a> <a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=kitgenix-multistore-sync-for-woocommerce&view=sync#kitgenix-bulk-operations')).'">Bulk sync all products &rarr;</a></div></div><div class="kitgenix-card-body">';
        $has_rows=false;
        ob_start();
        echo '<div class="kitgenix-table-wrap"><table class="kitgenix-table"><thead><tr><th>Product</th><th>SKU</th><th>Network ID</th><th>Revision</th><th class="kitgenix-table-actions-col">Action</th></tr></thead><tbody>';
        foreach($rows as$r){
            $p=wc_get_product((int)$r['object_id']);if(!$p)continue;$has_rows=true;
            echo '<tr><td><a href="'.esc_url(get_edit_post_link($p->get_id())).'"><strong>'.esc_html($p->get_name()).'</strong></a></td><td>'.esc_html($p->get_sku()?:'–').'</td><td><code>'.esc_html($r['network_id']).'</code></td><td>'.esc_html($r['last_revision']).'</td><td><div class="kitgenix-table-actions"><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=kitgenix_multistore_sync_for_woocommerce_sync_product&product_id='.$p->get_id()),'kitgenix_multistore_sync_for_woocommerce_sync_product_'.$p->get_id())).'">Sync now</a></div></td></tr>';
        }
        echo '</tbody></table></div>';
        $table_html=ob_get_clean();
        if($has_rows)echo $table_html;
        else echo '<div class="kitgenix-empty-state"><span class="kitgenix-empty-state-icon" aria-hidden="true">'.$this->icon('box').'</span><h3 class="kitgenix-empty-state-title">No network products yet</h3><p class="kitgenix-empty-state-desc">Products gain a stable network ID the first time they are synchronised.</p></div>';
        echo '</div></div>';
    }

    private function view_inventory():void{
        $rows=$this->plugin->get('inventory')->all(100);
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('box').'</span><div class="kitgenix-card-head-text"><h2>Authoritative inventory</h2><p>The control store maintains a revisioned inventory state and repairs drift automatically every hour in the background. Run it on demand – with progress and an optional dry run – from Bulk Operations in the Sync Centre.</p></div></div><div class="kitgenix-card-head-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=kitgenix-multistore-sync-for-woocommerce&view=sync#kitgenix-bulk-operations')).'">Reconcile now &rarr;</a></div></div><div class="kitgenix-card-body">';
        if($rows){
            echo '<div class="kitgenix-table-wrap"><table class="kitgenix-table"><thead><tr><th>Network ID</th><th>Quantity</th><th>Status</th><th>Revision</th><th>Source</th><th>Updated</th></tr></thead><tbody>';
            foreach($rows as$r)echo '<tr><td><code>'.esc_html($r['network_id']).'</code></td><td><strong>'.esc_html(null===$r['quantity']?'–':wc_format_localized_decimal($r['quantity'])).'</strong></td><td>'.esc_html($r['stock_status']).'</td><td>'.esc_html($r['revision']).'</td><td><code>'.esc_html(substr($r['source_store_uuid'],0,8)).'…</code></td><td>'.esc_html($r['updated_at']).'</td></tr>';
            echo '</tbody></table></div>';
        } else {
            echo '<div class="kitgenix-empty-state"><span class="kitgenix-empty-state-icon" aria-hidden="true">'.$this->icon('box').'</span><h3 class="kitgenix-empty-state-title">No inventory records yet</h3></div>';
        }
        echo '</div></div>';
    }

    private function view_orders(): void {
        $repo = $this->plugin->get( 'orders' );
        $page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        $status = sanitize_key( $_GET['status'] ?? '' );
        $store = sanitize_text_field( $_GET['store_uuid'] ?? '' );
        $result = $repo->query( array( 'limit' => 50, 'page' => $page, 'search' => $search, 'status' => $status, 'store_uuid' => $store ) );
        $rows = $result['rows'];
        $gateway = 'yes' === $this->plugin->get( 'settings' )->get( 'allow_remote_gateway_refunds', 'no' );

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('users').'</span><div class="kitgenix-card-head-text"><h2>Central network orders</h2><p>Search, export and manage orders across every indexed store.</p></div></div><div class="kitgenix-card-head-actions"><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kitgenix_multistore_sync_for_woocommerce_index_orders' ), 'kitgenix_multistore_sync_for_woocommerce_index_orders' ) ) . '">Index last 90 days</a></div></div><div class="kitgenix-card-body">';

        echo '<form method="get" class="kitgenix-search-bar"><input type="hidden" name="page" value="kitgenix-multistore-sync-for-woocommerce"><input type="hidden" name="view" value="orders"><div class="kitgenix-search-bar-input"><span class="kitgenix-search-bar-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.3"></circle><path d="m17 17-3.5-3.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path></svg></span><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Order, customer, email or network ID"></div><div class="kitgenix-search-bar-filters"><select name="status"><option value="">All statuses</option>';
        foreach ( wc_get_order_statuses() as $k => $label ) { $v = preg_replace( '/^wc-/', '', $k ); echo '<option value="' . esc_attr( $v ) . '" ' . selected( $status, $v, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select><select name="store_uuid"><option value="">All stores</option>';
        foreach ( $this->plugin->get( 'stores' )->all() as $st ) echo '<option value="' . esc_attr( $st['uuid'] ) . '" ' . selected( $store, $st['uuid'], false ) . '>' . esc_html( $st['name'] ) . '</option>';
        echo '</select></div><div class="kitgenix-search-bar-actions"><button type="submit" class="button">Filter</button></div></form>';

        $base_args = array( 's' => $search, 'status' => $status, 'store_uuid' => $store );
        echo '<div class="kitgenix-card-head" style="border-bottom:0;margin-bottom:12px;padding-bottom:0"><div><strong>' . esc_html( (string) $result['total'] ) . ' indexed orders</strong></div><div class="kitgenix-button-group"><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'kitgenix_multistore_sync_for_woocommerce_export_orders', 'format' => 'csv' ), $base_args ), admin_url( 'admin-post.php' ) ), 'kitgenix_multistore_sync_for_woocommerce_export_orders' ) ) . '">Export CSV</a> <a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'kitgenix_multistore_sync_for_woocommerce_export_orders', 'format' => 'xlsx' ), $base_args ), admin_url( 'admin-post.php' ) ), 'kitgenix_multistore_sync_for_woocommerce_export_orders' ) ) . '">Export XLSX</a></div></div>';

        echo '<form id="kitgenix-multistore-sync-for-woocommerce-order-bulk" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_bulk_order_status">';
        wp_nonce_field( 'kitgenix_multistore_sync_for_woocommerce_bulk_order_status' );
        echo '<div class="kitgenix-button-group" style="margin-bottom:14px"><select name="status" required><option value="">Bulk status…</option>';
        foreach ( wc_get_order_statuses() as $k => $label ) { $v = preg_replace( '/^wc-/', '', $k ); echo '<option value="' . esc_attr( $v ) . '">' . esc_html( $label ) . '</option>'; }
        echo '</select><button type="submit" class="button">Apply</button></div></form>';

        if ( $rows ) {
        echo '<div class="kitgenix-table-wrap"><table class="kitgenix-table"><thead><tr><th><input type="checkbox" data-kitgenix-multistore-sync-for-woocommerce_check-all></th><th>Order</th><th>Store</th><th>Customer</th><th>Status</th><th>Total</th><th>Date</th><th>Documents</th><th class="kitgenix-table-actions-col">Actions</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $st = $this->plugin->get( 'stores' )->get( $r['store_uuid'] );
            $payload = (array) ( $r['payload_array'] ?? array() );
            $status_badge = match(true){
                in_array($r['status'],array('completed','processing'),true)=>'success',
                in_array($r['status'],array('cancelled','failed','refunded'),true)=>'danger',
                in_array($r['status'],array('pending','on-hold'),true)=>'warning',
                default=>'neutral',
            };
            echo '<tr><td><input form="kitgenix-multistore-sync-for-woocommerce-order-bulk" type="checkbox" name="network_ids[]" value="' . esc_attr( $r['network_id'] ) . '"></td><td><strong>#' . esc_html( $r['order_number'] ) . '</strong><br><code>' . esc_html( substr( $r['network_id'], 0, 13 ) ) . '…</code></td><td>' . esc_html( $st['name'] ?? substr( $r['store_uuid'], 0, 8 ) . '…' ) . '</td><td>' . esc_html( $r['customer_name'] ) . '<br><small>' . esc_html( $r['customer_email'] ) . '</small></td><td><span class="kitgenix-badge ' . $status_badge . ' kitgenix-badge-dot">' . esc_html( wc_get_order_status_name( $r['status'] ) ) . '</span></td><td>' . wp_kses_post( wc_price( (float) $r['total'], array( 'currency' => $r['currency'] ) ) ) . '</td><td>' . esc_html( $r['order_created_at'] ?: $r['synced_at'] ) . '</td><td>';
            $documents = (array) ( $payload['pdf_documents'] ?? array() );
            if ( $documents ) {
                foreach ( $documents as $type => $doc ) {
                    if ( empty( $doc['url'] ) ) continue;
                    echo '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( $doc['url'] ) . '">' . esc_html( $doc['title'] ?? strtoupper( (string) $type ) ) . '</a> ';
                }
            } else echo '–';
            echo '</td><td><details><summary class="button">Manage</summary><div class="kitgenix-multistore-sync-for-woocommerce-order-actions"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=kitgenix-multistore-sync-for-woocommerce&view=sync' ) ) . '">Sync history</a>';

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_remote_order_note"><input type="hidden" name="network_id" value="' . esc_attr( $r['network_id'] ) . '">';
            wp_nonce_field( 'kitgenix_multistore_sync_for_woocommerce_remote_order_note_' . $r['network_id'] );
            echo '<div class="kitgenix-settings-group"><div class="kitgenix-setting-row-stacked"><label>Order note</label><textarea name="note" rows="2" required></textarea></div><label class="kitgenix-toggle" style="margin:8px 0"><input type="checkbox" class="kitgenix-toggle-input" name="customer_note" value="1"><span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span><span class="kitgenix-toggle-label">Customer-visible</span></label></div><button type="submit" class="button">Add note</button></form>';

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_remote_order_refund"><input type="hidden" name="network_id" value="' . esc_attr( $r['network_id'] ) . '">';
            wp_nonce_field( 'kitgenix_multistore_sync_for_woocommerce_remote_order_refund_' . $r['network_id'] );
            echo '<div class="kitgenix-settings-group"><div class="kitgenix-setting-row-stacked"><label>Refund amount</label><input type="number" step="0.01" min="0.01" max="' . esc_attr( (string) $r['total'] ) . '" name="amount" required></div><div class="kitgenix-setting-row-stacked"><label>Reason</label><input type="text" name="reason"></div>';
            if ( $gateway ) echo '<label class="kitgenix-toggle" style="margin:8px 0"><input type="checkbox" class="kitgenix-toggle-input" name="refund_payment" value="1"><span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span><span class="kitgenix-toggle-label">Refund through gateway</span></label>';
            echo '</div><button type="submit" class="button">Create refund</button></form></div></details></td></tr>';
        }
        echo '</tbody></table></div>';
        } else {
            echo '<div class="kitgenix-empty-state"><span class="kitgenix-empty-state-icon" aria-hidden="true">'.$this->icon('search').'</span><h3 class="kitgenix-empty-state-title">No matching orders</h3><p class="kitgenix-empty-state-desc">Try a different search or index recent orders above.</p></div>';
        }
        if ( $result['pages'] > 1 ) echo '<div class="kitgenix-multistore-sync-for-woocommerce-pagination">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $result['pages'], 'type' => 'plain' ) ) ) . '</div>';
        echo '</div></div>';
    }

    private function view_customers():void{
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('users').'</span><div class="kitgenix-card-head-text"><h2>Network customers</h2><p>Customer synchronisation is privacy-sensitive and disabled by default. When enabled, connected stores send customer snapshots only to the control store.</p></div></div></div><div class="kitgenix-card-body"><div class="kitgenix-stat-grid" style="margin-bottom:0">'.$this->metric('Unique customer records',(string)$this->plugin->get('customers')->count(),'Across every connected store').'</div></div></div>';
    }

    private function view_coupons():void{
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('coupon').'</span><div class="kitgenix-card-head-text"><h2>Network coupons</h2><p>Core WooCommerce coupon settings created or updated on the control store are synchronised to connected stores.</p></div></div><div class="kitgenix-card-head-actions"><a class="button button-primary" href="'.esc_url(admin_url('edit.php?post_type=shop_coupon')).'">Manage coupons</a></div></div></div>';
    }

    /**
     * Resumable bulk product-sync / reconciliation with progress, dry-run,
     * pause/resume/stop, and a clear report – replacing the previous
     * synchronous, single-click "Sync all products" / "Run reconciliation"
     * buttons that offered none of those and risked a request timeout on a
     * large catalogue. No AJAX transport is used: while a job is running this
     * page auto-refreshes itself to show the latest persisted progress.
     */
    private function render_bulk_operations():void{
        $bulk_job=$this->plugin->get('bulk_job');
        $bulk_job->nudge_if_stalled();
        $job=$bulk_job->progress();
        $running='running'===$job['status'];
        $paused='paused'===$job['status'];
        $has_job=''!==$job['type'];

        echo '<div class="kitgenix-card" id="kitgenix-bulk-operations"'
            .($running?' data-kitgenix-bulk-poll-url="'.esc_attr(admin_url('admin-ajax.php')).'" data-kitgenix-bulk-poll-nonce="'.esc_attr(wp_create_nonce('kitgenix_multistore_sync_for_woocommerce_bulk_job_poll')).'"':'')
            .'><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('sync').'</span><div class="kitgenix-card-head-text"><h2>Bulk Operations</h2><p>Full product sync or full inventory reconciliation, processed in small resumable batches so it never times out – with an optional dry run first.</p></div></div></div><div class="kitgenix-card-body">';

        if($has_job){
            $type_label='reconcile'===$job['type']?'Inventory reconciliation':'Product sync';
            $status_badge=match($job['status']){'running'=>'warning','paused'=>'neutral','completed'=>'success',default=>'neutral'};
            echo '<div class="kitgenix-stat-grid" style="margin-bottom:16px">'
                .$this->metric('Job',($job['dry_run']?'Dry run – ':'').$type_label)
                .$this->metric('Status','<span id="kitgenix-bulk-status-badge" class="kitgenix-badge '.$status_badge.' kitgenix-badge-dot">'.esc_html(ucfirst((string)$job['status'])).'</span>',$job['percent'].'% complete',true,'','kitgenix-bulk-status-sub')
                .$this->metric('Processed',(int)$job['processed'].' / '.(int)$job['total'],'',false,'kitgenix-bulk-processed')
                .$this->metric('Repaired / Excluded',(int)$job['repaired'].' / '.(int)$job['excluded'],(int)$job['drift'].' drift detected',false,'kitgenix-bulk-repaired','kitgenix-bulk-drift')
                .'</div>';
            echo '<div class="kitgenix-progress" id="kitgenix-bulk-progressbar" role="progressbar" aria-valuenow="'.(int)$job['percent'].'" aria-valuemin="0" aria-valuemax="100" style="height:8px;background:var(--kitgenix-border-color,#ddd);border-radius:4px;overflow:hidden;margin-bottom:8px"><div id="kitgenix-bulk-progressbar-fill" style="height:100%;width:'.(int)$job['percent'].'%;background:var(--kitgenix-brand,#2271b1);transition:width .4s ease"></div></div>';
            echo '<p id="kitgenix-bulk-current" class="kitgenix-setting-row-desc" style="margin:0 0 16px'.(empty($job['current'])?';display:none':'').'">Syncing now: <strong id="kitgenix-bulk-current-label">'.(empty($job['current'])?'':esc_html((string)$job['current']['label'])).'</strong></p>';
            echo '<div id="kitgenix-bulk-errors-wrap">';
            if(!empty($job['errors'])){
                echo '<details style="margin-bottom:16px"><summary class="button"><span id="kitgenix-bulk-errors-count">'.count($job['errors']).'</span> error(s) – view</summary><div class="kitgenix-table-wrap"><table class="kitgenix-table"><tbody id="kitgenix-bulk-errors-body">';
                foreach(array_slice($job['errors'],-20)as$err)echo '<tr><td>'.esc_html(gmdate('Y-m-d H:i:s',(int)$err['time'])).'</td><td>'.esc_html($err['message']).'</td></tr>';
                echo '</tbody></table></div></details>';
            }
            echo '</div>';
            echo '<div class="kitgenix-button-group">';
            if($running){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_bulk_job_pause">';wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_bulk_job_pause');submit_button('Pause','secondary','submit',false);echo '</form>';
            } elseif($paused){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_bulk_job_resume">';wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_bulk_job_resume');submit_button('Resume','primary','submit',false);echo '</form>';
            }
            if($running||$paused){
                echo ' <form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_bulk_job_stop">';wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_bulk_job_stop');submit_button('Stop','secondary','submit',false);echo '</form>';
            }
            echo '</div>';
            if('completed'===$job['status'])echo '<p class="kitgenix-setting-row-desc">Job finished. Starting a new job below replaces this report.</p>';
        } else {
            echo '<div class="kitgenix-empty-state"><p class="kitgenix-empty-state-title">No bulk job has run yet</p></div>';
        }

        if(!$running&&!$paused){
            echo '<hr style="margin:20px 0;border:none;border-top:1px solid var(--kitgenix-border-color,#ddd)" />';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_bulk_job_start">';
            wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_bulk_job_start');
            echo '<div class="kitgenix-settings-group">'
                .'<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_bulk_job_type">Operation</label></div><div class="kitgenix-setting-row-control"><select id="kitgenix_bulk_job_type" name="job_type"><option value="product_sync">Sync all products</option><option value="reconcile">Reconcile inventory</option></select></div></div>'
                .'<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_bulk_dry_run">Dry run</label><p class="kitgenix-setting-row-desc">Report what would happen – drift found, items excluded – without changing or sending anything.</p></div><div class="kitgenix-setting-row-control"><label class="kitgenix-toggle"><input type="checkbox" class="kitgenix-toggle-input" id="kitgenix_bulk_dry_run" name="dry_run" value="1"><span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span></label></div></div>'
                .'</div><div class="kitgenix-button-group kitgenix-button-group-end">';
            submit_button('Start','primary','submit',false);
            echo '</div></form>';
        }

        echo '</div></div>';
    }

    /**
     * Rolling feed of the last few products the running job has touched -
     * name, thumbnail, price, outcome - refreshed by the same live-progress
     * poll as render_bulk_operations(). Kept as its own card/state (job.recent
     * in BulkJob) rather than a single "current item" line, because a single
     * line goes blank the instant a 50-product batch finishes and blanks
     * again between batches; this never empties mid-job.
     */
    private function render_bulk_recent():void{
        $job=$this->plugin->get('bulk_job')->progress();
        $recent=(array)($job['recent']??array());
        if('running'!==$job['status']&&!$recent)return;

        $placeholder=function_exists('wc_placeholder_img_src')?wc_placeholder_img_src('thumbnail'):'';
        echo '<div class="kitgenix-card" id="kitgenix-bulk-recent" data-kitgenix-bulk-placeholder-image="'.esc_attr($placeholder).'"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('sync').'</span><div class="kitgenix-card-head-text"><h2>Recently synced products</h2><p>The most recent products this job has touched, updated live.</p></div></div></div><div class="kitgenix-card-body">';
        echo '<div id="kitgenix-bulk-recent-list">';
        if($recent){foreach($recent as$item)echo $this->bulk_recent_item($item,$placeholder);}
        else echo '<div class="kitgenix-empty-state" id="kitgenix-bulk-recent-empty"><p class="kitgenix-empty-state-title">Waiting for the first product…</p></div>';
        echo '</div></div></div>';
    }

    /** @param array<string,mixed> $item */
    private function bulk_recent_item(array$item,string$placeholder):string{
        $tone=match((string)($item['outcome']??'repaired')){'error'=>'danger','excluded'=>'neutral',default=>'success'};
        $label=match((string)($item['outcome']??'repaired')){'error'=>'Error','excluded'=>'Excluded',default=>'Synced'};
        $img=(string)($item['image']??'')?:$placeholder;
        return '<div class="kitgenix-bulk-recent-item" style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--kitgenix-border-color,#eee)">'
            .'<img src="'.esc_url($img).'" alt="" width="40" height="40" style="width:40px;height:40px;object-fit:cover;border-radius:4px;background:var(--kitgenix-border-color,#eee);flex:none" />'
            .'<div style="flex:1;min-width:0">'
            .'<div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'.esc_html((string)($item['label']??'')).'</div>'
            .'<div style="color:var(--kitgenix-text-muted,#666);font-size:12px">'.esc_html((string)($item['price']??'')).'</div>'
            .'</div>'
            .'<span class="kitgenix-badge '.$tone.'">'.esc_html($label).'</span>'
            .'</div>';
    }

    private function view_sync():void{
        $events=$this->plugin->get('events')->recent(50);$deliveries=$this->plugin->get('events')->recent_deliveries(100);$conflicts=$this->plugin->get('conflicts')->recent(50,'open');

        $this->render_bulk_operations();
        $this->render_bulk_recent();

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('warn').'</span><div class="kitgenix-card-head-text"><h2>Open conflicts</h2><p>Errors that need human review stay visible until resolved or ignored.</p></div></div><div class="kitgenix-card-head-actions"><span class="kitgenix-badge '.($conflicts?'warning':'success').'">'.esc_html((string)count($conflicts)).' open</span></div></div><div class="kitgenix-card-body">';
        if($conflicts){
            echo '<div class="kitgenix-table-wrap"><table class="kitgenix-table"><thead><tr><th>Time</th><th>Type</th><th>Category</th><th>Entity</th><th>Message</th><th class="kitgenix-table-actions-col"></th></tr></thead><tbody>';
            foreach($conflicts as$c){
                $category_note=ErrorCatalog::get_category_and_note((string)$c['conflict_type']);
                $badge=ErrorCatalog::is_recoverable((string)$c['conflict_type'])?'warning':'danger';
                echo '<tr><td>'.esc_html($c['created_at']).'</td><td><code>'.esc_html($c['conflict_type']).'</code></td><td><span class="kitgenix-badge '.$badge.'" title="'.esc_attr($category_note['note']).'">'.esc_html($category_note['category']).'</span></td><td>'.esc_html($c['entity_type']).'<br><code>'.esc_html(substr($c['network_id'],0,13)).'…</code></td><td>'.esc_html($c['message']).'</td><td><div class="kitgenix-table-actions"><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=kitgenix_multistore_sync_for_woocommerce_resolve_conflict&uuid='.rawurlencode($c['conflict_uuid'])),'kitgenix_multistore_sync_for_woocommerce_resolve_conflict_'.$c['conflict_uuid'])).'">Mark resolved</a></div></td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<div class="kitgenix-empty-state"><span class="kitgenix-empty-state-icon" aria-hidden="true">'.$this->icon('success').'</span><h3 class="kitgenix-empty-state-title">No open conflicts</h3></div>';
        }
        echo '</div></div>';

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('clock').'</span><div class="kitgenix-card-head-text"><h2>Recent events</h2></div></div></div><div class="kitgenix-card-body">';
        if($events){
            echo '<div class="kitgenix-table-wrap"><table class="kitgenix-table"><thead><tr><th>Time</th><th>Event</th><th>Entity</th><th>Revision</th><th>Status</th></tr></thead><tbody>';
            foreach($events as$e){
                $badge=match($e['status']){'completed'=>'success','failed'=>'danger','pending','processing','retrying'=>'warning',default=>'neutral'};
                echo '<tr><td>'.esc_html($e['created_at']).'</td><td><strong>'.esc_html($e['event_type']).'</strong><br><code>'.esc_html(substr($e['event_uuid'],0,13)).'…</code></td><td>'.esc_html($e['entity_type']).'<br><code>'.esc_html(substr($e['network_id'],0,13)).'…</code></td><td>'.esc_html($e['revision']).'</td><td><span class="kitgenix-badge '.$badge.' kitgenix-badge-dot">'.esc_html($e['status']).'</span></td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<div class="kitgenix-empty-state"><p class="kitgenix-empty-state-title">No events yet</p></div>';
        }
        echo '</div></div>';

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('link').'</span><div class="kitgenix-card-head-text"><h2>Deliveries</h2></div></div></div><div class="kitgenix-card-body">';
        if($deliveries){
            echo '<div class="kitgenix-table-wrap"><table class="kitgenix-table"><thead><tr><th>Event</th><th>Store</th><th>Status</th><th>Attempts</th><th>Error</th><th>Next attempt</th><th class="kitgenix-table-actions-col"></th></tr></thead><tbody>';
            foreach($deliveries as$d){
                $badge=match($d['status']){'completed'=>'success','failed'=>'danger','pending','processing','retrying'=>'warning',default=>'neutral'};
                echo '<tr><td><code>'.esc_html(substr($d['event_uuid'],0,13)).'…</code></td><td><code>'.esc_html(substr($d['store_uuid'],0,13)).'…</code></td><td><span class="kitgenix-badge '.$badge.' kitgenix-badge-dot">'.esc_html($d['status']).'</span></td><td>'.esc_html($d['attempts']).'</td><td>'.esc_html($d['last_error']?:'–').'</td><td>'.esc_html($d['next_attempt']?:'–').'</td><td>';
                if('failed'===$d['status'])echo '<div class="kitgenix-table-actions"><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=kitgenix_multistore_sync_for_woocommerce_retry_delivery&event='.rawurlencode($d['event_uuid']).'&store='.rawurlencode($d['store_uuid'])),'kitgenix_multistore_sync_for_woocommerce_retry_delivery_'.$d['event_uuid'].'_'.$d['store_uuid'])).'">Retry</a></div>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<div class="kitgenix-empty-state"><p class="kitgenix-empty-state-title">No deliveries yet</p></div>';
        }
        echo '</div></div>';
    }

    private function view_automations():void{
        $rules=$this->plugin->get('automations')->all();
        echo '<div class="kitgenix-stat-grid">';
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('gear').'</span><div class="kitgenix-card-head-text"><h2>Add inventory automation</h2></div></div></div><div class="kitgenix-card-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_create_automation">';
        wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_create_automation');
        echo '<div class="kitgenix-settings-group">'
            .'<div class="kitgenix-setting-row-stacked"><label for="kitgenix_field_automation_name">Name</label><input type="text" id="kitgenix_field_automation_name" name="name" required placeholder="Pause low-stock products"></div>'
            .'<div class="kitgenix-setting-row-stacked"><label for="kitgenix_field_automation_trigger">Trigger</label><select id="kitgenix_field_automation_trigger" name="trigger"><option value="stock_below">Stock falls below</option><option value="stock_above">Stock rises above</option></select></div>'
            .'<div class="kitgenix-setting-row-stacked"><label for="kitgenix_field_automation_threshold">Threshold</label><input type="number" step="0.0001" id="kitgenix_field_automation_threshold" name="threshold" value="1"></div>'
            .'<div class="kitgenix-setting-row-stacked"><label for="kitgenix_field_automation_status">Set product status</label><select id="kitgenix_field_automation_status" name="status"><option value="draft">Draft</option><option value="publish">Published</option><option value="private">Private</option></select></div>'
            .'</div><div class="kitgenix-button-group">';
        submit_button('Create automation','primary','submit',false);
        echo '</div></form></div></div>';

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('gear').'</span><div class="kitgenix-card-head-text"><h2>Active rules</h2></div></div></div><div class="kitgenix-card-body">';
        foreach($rules as$r)echo '<div class="kitgenix-multistore-sync-for-woocommerce-rule"><div><strong>'.esc_html($r['name']).'</strong><small>'.esc_html($r['trigger_type'].' → '.$r['action_type']).'</small></div><a class="button-link-delete" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=kitgenix_multistore_sync_for_woocommerce_delete_automation&id='.(int)$r['id']),'kitgenix_multistore_sync_for_woocommerce_delete_automation_'.(int)$r['id'])).'">Delete</a></div>';
        if(!$rules)echo '<div class="kitgenix-empty-state"><p class="kitgenix-empty-state-title">No automations yet</p></div>';
        echo '</div></div>';
        echo '</div>';
    }

    private function view_analytics():void{
        $rows=$this->plugin->get('orders')->totals_by_currency(30);
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('gear').'</span><div class="kitgenix-card-head-text"><h2>30-day network sales</h2><p>Totals are separated by currency rather than incorrectly combining GBP, EUR or other currencies.</p></div></div></div><div class="kitgenix-card-body">';
        if($rows){
            echo '<div class="kitgenix-stat-grid" style="margin-bottom:0">';
            foreach($rows as$r)echo $this->metric($r['currency'].' revenue',wc_price((float)$r['revenue'],array('currency'=>$r['currency'])),(int)$r['orders'].' remote orders',true);
            echo '</div>';
        } else {
            echo '<div class="kitgenix-empty-state"><p class="kitgenix-empty-state-title">No remote order data yet</p></div>';
        }
        echo '</div></div>';
    }

    private function view_integrations(): void {
        $registry=$this->plugin->get('integrations');$statuses=$registry->statuses();$square_status=defined('WC_SQUARE_PLUGIN_VERSION')?'Active '.WC_SQUARE_PLUGIN_VERSION:'Not detected';if(defined('WC_SQUARE_PLUGIN_VERSION')&&function_exists('wc_square')){try{$h=wc_square()->get_settings_handler();if($h&&method_exists($h,'is_system_of_record_square'))$square_status.=$h->is_system_of_record_square()?' · Square authoritative':' · WooCommerce authoritative';}catch(\Throwable$e){$this->plugin->get('logger')->warning('Could not read WooCommerce Square system-of-record setting.',array('code'=>'kitgenix_multistore_sync_for_woocommerce_square_status_exception','error'=>$e->getMessage()));}}
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('link').'</span><div class="kitgenix-card-head-text"><h2>Integration status</h2><p><strong>Integrated</strong> means Kitgenix has an active adapter; detected alone never implies compatibility.</p></div></div></div></div>';
        echo '<div class="kitgenix-stat-grid">';
        echo $this->integration('WooCommerce',defined('WC_VERSION')?'Integrated · '.WC_VERSION:'Missing',defined('WC_VERSION'));
        echo $this->integration('WooCommerce Square',$square_status.' · Integrated',defined('WC_SQUARE_PLUGIN_VERSION'));
        foreach($statuses as$name=>$row){
            if('WooCommerce Square'===$name)continue;
            $state=!empty($row['detected'])?'Detected':'Not detected';$state.=' · '.(!empty($row['integrated'])?'Integrated':'No adapter');if(!empty($row['note']))$state.=' · '.$row['note'];
            echo $this->integration($name,$state,!empty($row['integrated']));
        }
        echo '</div>';
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('gear').'</span><div class="kitgenix-card-head-text"><h2>Generic compatibility layer</h2></div></div></div><div class="kitgenix-card-body"><p>Configured product meta keys and taxonomies are synchronised through the same signed product payload and can be used for extensions without a dedicated adapter.</p></div></div>';
    }

    private function view_doctor():void{
        $network=$this->plugin->get('doctor')->network_checks();
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('doctor').'</span><div class="kitgenix-card-head-text"><h2>Network Doctor</h2><p>Checks the local runtime and every paired store without changing commerce data.</p></div></div><div class="kitgenix-card-head-actions"><span class="kitgenix-stat-tile-value">'.esc_html((string)$this->plugin->get('doctor')->score($network['local'])).'</span><span class="kitgenix-setting-row-desc" style="margin:0">/100 local</span></div></div><div class="kitgenix-card-body">'.$this->checks_table($network['local']).'</div></div>';
        foreach($network['stores']as$uuid=>$checks){
            echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('store').'</span><div class="kitgenix-card-head-text"><h2>Store <code>'.esc_html(substr($uuid,0,8)).'…</code></h2></div></div></div><div class="kitgenix-card-body">';
            if(isset($checks['error']))echo '<div class="kitgenix-notice kitgenix-notice-error" role="alert"><span class="kitgenix-notice-icon" aria-hidden="true">'.$this->icon('warn').'</span><div class="kitgenix-notice-body"><p class="kitgenix-notice-text">'.esc_html($checks['error']).'</p></div></div>';
            else echo $this->checks_table($checks);
            echo '</div></div>';
        }
    }

    private function view_migration():void{
        $importer=new WooMultistoreImporter($this->plugin->get('mappings'),$this->plugin->get('logger'));$d=$importer->detect();
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('download').'</span><div class="kitgenix-card-head-text"><h2>WooMultistore migration</h2></div></div></div><div class="kitgenix-card-body">';
        echo '<div class="kitgenix-notice kitgenix-notice-'.($d['detected']?'info':'neutral').'" role="status"><span class="kitgenix-notice-icon" aria-hidden="true">'.$this->icon($d['detected']?'success':'warn').'</span><div class="kitgenix-notice-body"><p class="kitgenix-notice-text">'.($d['detected']?'Existing <code>_woonet_*</code> metadata was detected.':'No WooMultistore metadata was detected.').'</p></div></div>';
        echo '<p>Detected local objects: <strong>'.esc_html((string)$d['products']).'</strong></p>';
        if($d['detected']){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_migrate_woomultistore">';
            wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_migrate_woomultistore');
            echo '<div class="kitgenix-button-group">';submit_button('Import relationships','primary','submit',false);echo '</div></form>';
        }
        echo '</div></div>';
    }

    private function view_logs():void{
        global$wpdb;$rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}kitgenix_multistore_sync_for_woocommerce_logs ORDER BY id DESC LIMIT 200",ARRAY_A)?:array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned custom log table with no WordPress core object-cache group; a direct $wpdb call is the only way to interact with it.
        $level_badge=static function(string$level):string{$level=strtolower($level);if(in_array($level,array('error','critical'),true))return'danger';if($level==='warning')return'warning';if($level==='info')return'info';return'neutral';};
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div><h2 class="kitgenix-support-subheading">'.esc_html__('Activity Log','kitgenix-multistore-sync-for-woocommerce').'</h2><p class="description">'.esc_html__('A record of recent network sync events, newest first.','kitgenix-multistore-sync-for-woocommerce').'</p></div><div class="kitgenix-card-head-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_clear_logs">';
        wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_clear_logs');
        echo '<button type="submit" class="button" data-kitgenix-multistore-sync-for-woocommerce_confirm="'.esc_attr__('This permanently deletes every log entry on this site. Continue?','kitgenix-multistore-sync-for-woocommerce').'">'.esc_html__('Clear log','kitgenix-multistore-sync-for-woocommerce').'</button></form></div></div><div class="kitgenix-card-body">';
        if($rows){
            echo '<div class="kitgenix-search-bar"><div class="kitgenix-search-bar-input"><span class="kitgenix-search-bar-icon" aria-hidden="true">'.$this->icon('search').'</span><input type="search" placeholder="'.esc_attr__('Search log…','kitgenix-multistore-sync-for-woocommerce').'" data-kitgenix-table-search data-kitgenix-table-search-target="#kitgenix-multistore-sync-for-woocommerce-log-table" /></div></div>';
            echo '<div class="kitgenix-table-wrap" id="kitgenix-multistore-sync-for-woocommerce-log-table" data-kitgenix-table-paginate="25"><table class="kitgenix-table"><thead><tr><th>'.esc_html__('Time','kitgenix-multistore-sync-for-woocommerce').'</th><th>'.esc_html__('Level','kitgenix-multistore-sync-for-woocommerce').'</th><th>'.esc_html__('Category','kitgenix-multistore-sync-for-woocommerce').'</th><th>'.esc_html__('Message','kitgenix-multistore-sync-for-woocommerce').'</th><th>'.esc_html__('Context','kitgenix-multistore-sync-for-woocommerce').'</th></tr></thead><tbody>';
            foreach($rows as$r){
                $context=json_decode((string)$r['context'],true);$code=is_array($context)&&!empty($context['code'])?(string)$context['code']:'';
                $category_note=ErrorCatalog::get_category_and_note($code);
                $category_cell=''!==$code?'<span class="kitgenix-badge '.(ErrorCatalog::is_recoverable($code)?'warning':($category_note['category']==='unmapped'?'neutral':'danger')).'" title="'.esc_attr($category_note['note']).'">'.esc_html($category_note['category']).'</span>':'–';
                echo '<tr data-kitgenix-table-row><td>'.esc_html($r['created_at']).'</td><td><span class="kitgenix-badge '.esc_attr($level_badge((string)$r['level'])).'">'.esc_html($r['level']).'</span></td><td>'.$category_cell.'</td><td>'.esc_html($r['message']).'</td><td><code>'.esc_html(wp_html_excerpt((string)$r['context'],180,'…')).'</code></td></tr>';
            }
            echo '</tbody></table><div class="kitgenix-empty-state" data-kitgenix-table-empty style="display:none;"><p class="kitgenix-empty-state-title">'.esc_html__('No matching log entries','kitgenix-multistore-sync-for-woocommerce').'</p></div></div>';
        } else {
            echo '<div class="kitgenix-empty-state"><span class="kitgenix-empty-state-icon" aria-hidden="true">'.$this->icon('logs').'</span><h3 class="kitgenix-empty-state-title">'.esc_html__('No log entries yet','kitgenix-multistore-sync-for-woocommerce').'</h3><p class="kitgenix-empty-state-desc">'.esc_html__('Sync activity will appear here as it happens.','kitgenix-multistore-sync-for-woocommerce').'</p></div>';
        }
        echo '</div></div>';
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div><h2 class="kitgenix-support-subheading">'.esc_html__('What the categories mean','kitgenix-multistore-sync-for-woocommerce').'</h2><p class="description">'.esc_html__('Recoverable categories are usually not a problem – they explain themselves and clear up on their own. Everything else is worth a look.','kitgenix-multistore-sync-for-woocommerce').'</p></div></div><div class="kitgenix-card-body"><div class="kitgenix-table-wrap"><table class="kitgenix-table"><thead><tr><th>'.esc_html__('Category','kitgenix-multistore-sync-for-woocommerce').'</th><th>'.esc_html__('What it means','kitgenix-multistore-sync-for-woocommerce').'</th><th>'.esc_html__('Action needed?','kitgenix-multistore-sync-for-woocommerce').'</th></tr></thead><tbody>';
        foreach(ErrorCatalog::category_reference()as$row){
            echo '<tr><td><code>'.esc_html($row['category']).'</code></td><td>'.esc_html($row['meaning']).'</td><td>'.esc_html($row['action']).'</td></tr>';
        }
        echo '</tbody></table></div></div></div>';
    }

    private function view_settings(): void {
        $s=$this->plugin->get('settings');$a=$s->all();
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_save_settings">';
        wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_save_settings');

        if(is_multisite()){
            echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('store').'</span><div class="kitgenix-card-head-text"><h2>Network mode</h2></div></div></div><div class="kitgenix-card-body"><div class="kitgenix-settings-group">';
            echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_network_mode">Network mode</label><p class="kitgenix-setting-row-desc">Native Multisite discovers sites automatically and shares connection credentials; paired mode works across separate WordPress installations.</p></div><div class="kitgenix-setting-row-control"><select id="kitgenix_field_network_mode" name="network_mode"><option value="paired" '.selected($a['network_mode'],'paired',false).'>Paired stores</option><option value="native_multisite" '.selected($a['network_mode'],'native_multisite',false).'>Native WordPress Multisite</option></select></div></div>';
            echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_native_control_blog_id">Native control site</label><p class="kitgenix-setting-row-desc">The authoritative site when Native Multisite mode is enabled.</p></div><div class="kitgenix-setting-row-control"><select id="kitgenix_field_native_control_blog_id" name="native_control_blog_id">';
            foreach(get_sites(array('number'=>0))as$site){switch_to_blog((int)$site->blog_id);$name=get_bloginfo('name');restore_current_blog();echo '<option value="'.esc_attr((string)$site->blog_id).'" '.selected((int)$a['native_control_blog_id'],(int)$site->blog_id,false).'>'.esc_html($name.' (#'.$site->blog_id.')').'</option>';}
            echo '</select></div></div></div></div></div>';
        }

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('store').'</span><div class="kitgenix-card-head-text"><h2>Network role</h2></div></div></div><div class="kitgenix-card-body"><div class="kitgenix-settings-group">';
        echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_role">Network role</label><p class="kitgenix-setting-row-desc">Use one control store and any number of connected stores.</p></div><div class="kitgenix-setting-row-control"><select id="kitgenix_field_role" name="role"><option value="control" '.selected($a['role'],'control',false).'>Control store</option><option value="connected" '.selected($a['role'],'connected',false).'>Connected store</option></select></div></div>';
        echo '</div></div></div>';

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('gear').'</span><div class="kitgenix-card-head-text"><h2>Synchronisation</h2><p>Choose which data types are kept in sync across the network.</p></div></div></div><div class="kitgenix-card-body"><div class="kitgenix-settings-group">';
        $this->toggle('sync_products','Product synchronisation','Synchronise product content and merchandising data.',$a);
        $this->toggle('sync_inventory','Inventory synchronisation','Use revisioned stock events and automatic reconciliation.',$a);
        $this->toggle('sync_orders','Order synchronisation','Index orders in the Control Store.',$a);
        $this->toggle('sync_coupons','Coupon synchronisation','Control-store coupons sync to connected stores.',$a);
        $this->toggle('sync_customers','Customer synchronisation','Off by default; sends customer snapshots to Control.',$a);
        $this->toggle('sync_images','Image synchronisation','Synchronise featured/gallery/variation/category media and ALT data.',$a);
        $this->toggle('sync_reviews','Product review synchronisation','Synchronise actual WooCommerce reviews and ratings.',$a);
        $this->toggle('sync_rest_products','Synchronise REST/API product writes','Enable products created or updated through REST/import integrations to enter the sync queue.',$a);
        $this->toggle('sync_custom_tabs','Custom tabs &amp; document synchronisation','If Kitgenix Custom Tabs is active, sync product-specific tabs. Documents referenced by a [kitgenix_document_manager_document] shortcode inside a synced tab are synced too when Kitgenix Document Manager is active. Also controlled per store under Store Policies.',$a);
        echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_review_direction">Review direction</label><p class="kitgenix-setting-row-desc">Network mode accepts reviews from connected stores and redistributes them; Control-only publishes Control reviews outward.</p></div><div class="kitgenix-setting-row-control"><select id="kitgenix_field_review_direction" name="review_direction"><option value="network" '.selected($a['review_direction'],'network',false).'>Network reviews</option><option value="control_only" '.selected($a['review_direction'],'control_only',false).'>Control → connected only</option></select></div></div>';
        echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_media_mode">Media mode</label><p class="kitgenix-setting-row-desc">Local copy is safest. Shared Network Media creates lightweight local attachment records that reference the authoritative remote image URL.</p></div><div class="kitgenix-setting-row-control"><select id="kitgenix_field_media_mode" name="media_mode"><option value="local_copy" '.selected($a['media_mode'],'local_copy',false).'>Local copy (recommended)</option><option value="shared_remote" '.selected($a['media_mode'],'shared_remote',false).'>Shared Network Media</option></select></div></div>';
        echo '</div></div></div>';

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('box').'</span><div class="kitgenix-card-head-text"><h2>Product &amp; catalog behaviour</h2></div></div></div><div class="kitgenix-card-body"><div class="kitgenix-settings-group">';
        $this->toggle('sku_assisted_linking','SKU-assisted linking','If a destination already has the same SKU, adopt it into the network UUID mapping instead of creating a duplicate.',$a);
        $this->toggle('auto_publish_new_products','Auto-publish new products','When off, incoming published products are created as drafts.',$a);
        $this->toggle('checkout_inventory_validation','Checkout inventory validation','Connected stores validate against authoritative inventory.',$a);
        $this->toggle('inventory_fail_closed','Fail checkout closed on validation errors','Block checkout if Control cannot validate inventory.',$a);
        echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_product_direction">Product direction</label><p class="kitgenix-setting-row-desc">Control-only is safer; bidirectional accepts connected-store product writes upstream.</p></div><div class="kitgenix-setting-row-control"><select id="kitgenix_field_product_direction" name="product_direction"><option value="control_only" '.selected($a['product_direction'],'control_only',false).'>Control authoritative</option><option value="bidirectional" '.selected($a['product_direction'],'bidirectional',false).'>Bidirectional</option></select></div></div>';
        echo '</div></div></div>';

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('users').'</span><div class="kitgenix-card-head-text"><h2>Orders</h2></div></div></div><div class="kitgenix-card-body"><div class="kitgenix-settings-group">';
        echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_order_import_mode">Central order storage</label><p class="kitgenix-setting-row-desc">Snapshots avoid duplicates. Optional WooCommerce import creates tagged Control-Store orders without stock reduction/emails.</p></div><div class="kitgenix-setting-row-control"><select id="kitgenix_field_order_import_mode" name="order_import_mode"><option value="snapshot" '.selected($a['order_import_mode'],'snapshot',false).'>Network snapshots (recommended)</option><option value="woocommerce" '.selected($a['order_import_mode'],'woocommerce',false).'>Import as WooCommerce orders</option></select></div></div>';
        $this->toggle('clone_products_on_order_import','Clone missing order products','When importing a remote order, create hidden draft placeholders for missing SKUs.',$a);
        $this->toggle('network_my_account_orders','Show network orders in My Account','Customers can see read-only orders from other indexed stores when their email matches.',$a);
        $this->toggle('sequential_order_numbers','Network sequential order numbers','Allocate display order numbers centrally across stores.',$a);
        echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_order_number_prefix">Order number prefix / start</label><p class="kitgenix-setting-row-desc">Applied only to new numbers after the feature is enabled.</p></div><div class="kitgenix-setting-row-control"><span style="display:flex;gap:8px;align-items:center;width:100%"><input type="text" id="kitgenix_field_order_number_prefix" name="order_number_prefix" value="'.esc_attr((string)$a['order_number_prefix']).'" placeholder="KITGENIX-"><input type="number" min="1" name="order_number_start" value="'.esc_attr((string)$a['order_number_start']).'"></span></div></div>';
        echo '</div></div></div>';

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('gear').'</span><div class="kitgenix-card-head-text"><h2>Custom fields</h2><p>Only explicitly listed keys are synchronised.</p></div></div></div><div class="kitgenix-card-body"><div class="kitgenix-settings-group">';
        echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_custom_meta_keys">Custom product metadata</label><p class="kitgenix-setting-row-desc">One meta key per line or comma separated.</p></div><div class="kitgenix-setting-row-control"><textarea id="kitgenix_field_custom_meta_keys" name="custom_meta_keys" rows="5">'.esc_textarea(implode("\n",$s->custom_meta_keys())).'</textarea></div></div>';
        echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_custom_taxonomies">Custom product taxonomies</label><p class="kitgenix-setting-row-desc">One registered taxonomy slug per line or comma separated. The taxonomy must be registered on every destination store.</p></div><div class="kitgenix-setting-row-control"><textarea id="kitgenix_field_custom_taxonomies" name="custom_taxonomies" rows="5">'.esc_textarea(implode("\n",$s->custom_taxonomies())).'</textarea></div></div>';
        echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_term_meta_keys">Term metadata</label><p class="kitgenix-setting-row-desc">Optional taxonomy term meta keys to copy with categories, attributes and configured custom taxonomies.</p></div><div class="kitgenix-setting-row-control"><textarea id="kitgenix_field_term_meta_keys" name="term_meta_keys" rows="4">'.esc_textarea(implode("\n",$s->term_meta_keys())).'</textarea></div></div>';
        echo '</div></div></div>';

        $roles=wp_roles()->roles;
        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('users').'</span><div class="kitgenix-card-head-text"><h2>Permissions &amp; safety</h2></div></div></div><div class="kitgenix-card-body"><div class="kitgenix-settings-group">';
        echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label>MultiStore permissions</label><p class="kitgenix-setting-row-desc">Select roles that can manage the network. Administrators always retain access.</p></div><div class="kitgenix-setting-row-control"><span class="kitgenix-multistore-sync-for-woocommerce-checklist">';
        foreach($roles as$key=>$role)echo '<label><input type="checkbox" name="allowed_roles[]" value="'.esc_attr($key).'" '.checked(in_array($key,$s->allowed_roles(),true),true,false).' '.disabled($key,'administrator',false).'> '.esc_html($role['name']).'</label>';
        echo '</span></div></div>';
        $this->toggle('allow_non_production_outbound','Allow staging outbound writes','Keep off unless intentionally testing a staging/local network.',$a);
        $this->toggle('allow_remote_gateway_refunds','Allow remote gateway refunds','Off by default because this can invoke a real payment gateway.',$a);
        $this->toggle('remove_data_on_uninstall','Remove all MultiStore data on uninstall','Off by default. When enabled, deleting this plugin also permanently removes its stores, mappings, events, inventory ledger, remote order snapshots, customers, conflicts and logs. Your actual WooCommerce products and orders are never deleted either way.',$a);
        echo '</div></div></div>';

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('clock').'</span><div class="kitgenix-card-head-text"><h2>Reliability</h2></div></div></div><div class="kitgenix-card-body"><div class="kitgenix-settings-group">';
        echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_max_retries">Maximum retries</label><p class="kitgenix-setting-row-desc">Failed deliveries use exponential backoff.</p></div><div class="kitgenix-setting-row-control"><input type="number" min="1" max="20" id="kitgenix_field_max_retries" name="max_retries" value="'.esc_attr((string)$a['max_retries']).'"></div></div>';
        echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_request_timeout">Request timeout</label><p class="kitgenix-setting-row-desc">Seconds for signed store-to-store requests.</p></div><div class="kitgenix-setting-row-control"><input type="number" min="5" max="60" id="kitgenix_field_request_timeout" name="request_timeout" value="'.esc_attr((string)$a['request_timeout']).'"></div></div>';
        echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_log_retention_days">Log retention</label><p class="kitgenix-setting-row-desc">Days to keep technical logs.</p></div><div class="kitgenix-setting-row-control"><select id="kitgenix_field_log_retention_days" name="log_retention_days"><option value="7" '.selected((int)$a['log_retention_days'],7,false).'>7 days</option><option value="14" '.selected((int)$a['log_retention_days'],14,false).'>14 days</option><option value="30" '.selected((int)$a['log_retention_days'],30,false).'>30 days</option></select></div></div>';
        echo '</div></div></div>';

        echo '<div class="kitgenix-button-group kitgenix-button-group-end">';
        submit_button('Save settings');
        echo '</div></form>';

        echo '<div class="kitgenix-card"><div class="kitgenix-card-head"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('warn').'</span><div class="kitgenix-card-head-text"><h2>Danger zone</h2><p>Start over on this site as though the plugin was just installed. This cannot be undone.</p></div></div></div><div class="kitgenix-card-body">';
        echo '<p>Disconnects every store and permanently deletes this site&rsquo;s stores, mappings, events, deliveries, inventory ledger, remote order snapshots, customers, conflicts, automations, logs, site identifier, and every MultiStore setting &mdash; on <strong>this site only</strong>. Your actual WooCommerce products and orders are never touched. If another store is paired with this one, it will show this site as disconnected until you pair again.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('kitgenix_multistore_sync_for_woocommerce_factory_reset');
        echo '<input type="hidden" name="action" value="kitgenix_multistore_sync_for_woocommerce_factory_reset">';
        echo '<div class="kitgenix-setting-row kitgenix-setting-row-stacked"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_confirm_reset">Type RESET to confirm</label></div><div class="kitgenix-setting-row-control"><input type="text" id="kitgenix_field_confirm_reset" name="confirm_reset" autocomplete="off" placeholder="RESET" required></div></div>';
        echo '<div class="kitgenix-button-group kitgenix-button-group-end"><button type="submit" class="button button-link-delete" data-kitgenix-multistore-sync-for-woocommerce_confirm="This permanently deletes all MultiStore data on this site and cannot be undone. Continue?">Factory reset this site</button></div>';
        echo '</form></div></div>';

        echo '<p><a href="'.esc_url(admin_url('admin.php?page=kitgenix-multistore-sync-for-woocommerce&view=migration')).'">WooMultistore migration tools</a> · <a href="'.esc_url(admin_url('admin.php?page=kitgenix-multistore-sync-for-woocommerce&view=logs')).'">Technical logs</a></p>';
    }

    public function save_settings(): void {
        $this->guard('kitgenix_multistore_sync_for_woocommerce_save_settings');$role=sanitize_key($_POST['role']??'control');if(!in_array($role,array('control','connected'),true))$role='control';$dir=sanitize_key($_POST['product_direction']??'control_only');if(!in_array($dir,array('control_only','bidirectional'),true))$dir='control_only';$network_mode=sanitize_key($_POST['network_mode']??'paired');if(!in_array($network_mode,array('paired','native_multisite'),true))$network_mode='paired';$media=sanitize_key($_POST['media_mode']??'local_copy');if(!in_array($media,array('local_copy','shared_remote'),true))$media='local_copy';$reviews=sanitize_key($_POST['review_direction']??'network');if(!in_array($reviews,array('network','control_only'),true))$reviews='network';$order_mode=sanitize_key($_POST['order_import_mode']??'snapshot');if(!in_array($order_mode,array('snapshot','woocommerce'),true))$order_mode='snapshot';
        $parse_list=static function($raw):array{$parts=preg_split('/[\r\n,]+/',wp_unslash((string)$raw))?:array();return array_values(array_unique(array_filter(array_map('sanitize_key',$parts))));};
        $roles=array_values(array_filter(array_map('sanitize_key',(array)($_POST['allowed_roles']??array()))));if(!in_array('administrator',$roles,true))$roles[]='administrator';
        $retention=(int)($_POST['log_retention_days']??30);if(!in_array($retention,array(7,14,30),true))$retention=30;
        $values=array('role'=>$role,'network_mode'=>$network_mode,'native_control_blog_id'=>absint($_POST['native_control_blog_id']??0),'product_direction'=>$dir,'media_mode'=>$media,'review_direction'=>$reviews,'order_import_mode'=>$order_mode,'order_number_prefix'=>sanitize_text_field(wp_unslash($_POST['order_number_prefix']??'')),'order_number_start'=>max(1,(int)($_POST['order_number_start']??1000)),'custom_meta_keys'=>$parse_list($_POST['custom_meta_keys']??''),'custom_taxonomies'=>$parse_list($_POST['custom_taxonomies']??''),'term_meta_keys'=>$parse_list($_POST['term_meta_keys']??''),'allowed_roles'=>$roles,'max_retries'=>max(1,min(20,(int)($_POST['max_retries']??8))),'request_timeout'=>max(5,min(60,(int)($_POST['request_timeout']??20))),'log_retention_days'=>$retention);
        foreach(array('sync_products','sync_inventory','sync_orders','sync_coupons','sync_customers','sync_images','sync_reviews','sync_rest_products','sync_custom_tabs','sku_assisted_linking','auto_publish_new_products','checkout_inventory_validation','inventory_fail_closed','allow_non_production_outbound','allow_remote_gateway_refunds','clone_products_on_order_import','network_my_account_orders','sequential_order_numbers','remove_data_on_uninstall')as$k)$values[$k]=isset($_POST[$k])?'yes':'no';
        $this->plugin->get('settings')->set($values);if(is_multisite()){if('native_multisite'===$network_mode)NativeNetwork::enable($values['native_control_blog_id']?:get_main_site_id());else NativeNetwork::disable();}$this->plugin->get('access')->sync_roles();$this->redirect('settings','Settings saved.');
    }

    /**
     * "Start fresh" without the delete-plugin/reinstall round trip: the
     * uninstall.php cleanup only runs on actual plugin deletion, and only
     * when opted in, so a stale store connection otherwise survives a
     * delete+reinstall by design. This performs the same wipe on demand,
     * gated by capability, nonce, and a typed "RESET" confirmation since it
     * is destructive and irreversible.
     */
    public function factory_reset(): void {
        $this->guard('kitgenix_multistore_sync_for_woocommerce_factory_reset');
        if('RESET'!==trim((string)wp_unslash($_POST['confirm_reset']??'')))$this->redirect('settings','Factory reset cancelled: you must type RESET to confirm.');
        \Kitgenix\MultiStore\Support\Reset::factory_reset();
        $this->redirect('settings','This site has been fully reset. Reconnect any stores to continue.');
    }

    private function view_support():void{
        $donate_url='https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2';
        $plugin_page_url='https://kitgenix.com/plugins/kitgenix-multistore-sync-for-woocommerce/';
        $docs_url='https://kitgenix.com/plugins/kitgenix-multistore-sync-for-woocommerce/documentation/';
        $review_url='https://wordpress.org/support/plugin/kitgenix-multistore-sync-for-woocommerce/reviews/#new-post';
        $copy_onclick="if(window.navigator&&navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(".wp_json_encode($plugin_page_url).");}else{window.prompt(".wp_json_encode(__('Copy plugin link:','kitgenix-multistore-sync-for-woocommerce')).", ".wp_json_encode($plugin_page_url).");}return false;";

        echo '<div class="kitgenix-card kitgenix-support-hero"><div class="kitgenix-support-hero-inner">';
        echo '<span class="kitgenix-support-hero-icon" aria-hidden="true">'.$this->icon('heart').'</span>';
        echo '<p class="kitgenix-support-hero-eyebrow">'.esc_html__('Support Kitgenix','kitgenix-multistore-sync-for-woocommerce').'</p>';
        echo '<h2>'.esc_html__('Help us keep building','kitgenix-multistore-sync-for-woocommerce').'</h2>';
        echo '<p class="kitgenix-support-hero-body">'.esc_html__('This plugin keeps every connected store on one shared catalogue – products, stock, orders, coupons and customers stay in step through a signed queue instead of manual re-entry, with no premium tiers or feature gates anywhere. If it saves you the trouble of updating several stores by hand, a donation helps keep it built, tested and supported.','kitgenix-multistore-sync-for-woocommerce').'</p>';
        echo '<a class="kitgenix-support-hero-button" href="'.esc_url($donate_url).'" target="_blank" rel="noopener noreferrer">'.$this->icon('paypal').esc_html__('Donate with PayPal','kitgenix-multistore-sync-for-woocommerce').'</a>';
        echo '<p class="kitgenix-support-hero-caption">'.$this->icon('shield').esc_html__('Donations are entirely optional. Kitgenix plugins keep working whether you donate or not.','kitgenix-multistore-sync-for-woocommerce').'</p>';
        echo '</div>';

        echo '<div class="kitgenix-support-links">';
        echo '<a class="kitgenix-support-link" href="'.esc_url($review_url).'" target="_blank" rel="noopener noreferrer">'.$this->icon('star').esc_html__('Leave a review','kitgenix-multistore-sync-for-woocommerce').'</a>';
        echo '<a class="kitgenix-support-link" href="'.esc_url($docs_url).'" target="_blank" rel="noopener noreferrer">'.$this->icon('logs').esc_html__('Read the docs','kitgenix-multistore-sync-for-woocommerce').'</a>';
        echo '<button type="button" class="kitgenix-support-link" onclick="'.esc_attr($copy_onclick).'">'.$this->icon('copy').esc_html__('Copy plugin link','kitgenix-multistore-sync-for-woocommerce').'</button>';
        echo '</div>';
        echo '</div>';
    }

    public function generate_pair():void{$this->guard('kitgenix_multistore_sync_for_woocommerce_generate_pair');$code=strtoupper(wp_generate_password(8,false,false));set_transient('kitgenix_multistore_sync_for_woocommerce_pair_code',$code,10*MINUTE_IN_SECONDS);wp_safe_redirect(admin_url('admin.php?page=kitgenix-multistore-sync-for-woocommerce&view=stores&pair_code='.rawurlencode($code)));exit;}
    public function connect_store():void{$this->guard('kitgenix_multistore_sync_for_woocommerce_connect_store');$r=$this->plugin->get('client')->pair(esc_url_raw(wp_unslash($_POST['store_url']??'')),sanitize_text_field(wp_unslash($_POST['pair_code']??'')));$this->redirect('stores',is_wp_error($r)?'Connection failed: '.$r->get_error_message():'Store connected successfully.');}
    public function delete_store():void{$this->guard('kitgenix_multistore_sync_for_woocommerce_delete_store');$uuid=sanitize_text_field($_GET['uuid']??'');$this->plugin->get('stores')->delete($uuid);$settings=$this->plugin->get('settings');$rules=$settings->pricing_rules();unset($rules[$uuid]);$settings->save_pricing_rules($rules);$policies=$settings->policies();unset($policies[$uuid]);$settings->save_policies($policies);$groups=$settings->groups();foreach($groups as&$group)$group['stores']=array_values(array_diff((array)$group['stores'],array($uuid)));unset($group);$settings->save_groups($groups);$this->redirect('stores','Store disconnected.');}
    public function sync_product():void{$id=(int)($_GET['product_id']??0);if(!current_user_can('edit_post',$id)||!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']??''),'kitgenix_multistore_sync_for_woocommerce_sync_product_'.$id))wp_die('Invalid request.');$r=$this->plugin->get('product_sync')->sync_now($id);$this->redirect('products',is_wp_error($r)?$r->get_error_message():'Product queued for synchronisation.');}
    /**
     * Start a resumable, progress-tracked bulk job (full product sync or full
     * inventory reconciliation). Replaces the previous one-shot synchronous
     * "Run reconciliation" button, which risked a request timeout on a large
     * catalogue/network and offered no progress, dry-run, or resume support.
     * The automatic hourly background reconciliation (Reconciler::run(), via
     * Action Scheduler/WP-Cron) is unaffected and keeps running as before.
     */
    public function bulk_job_start():void{
        $this->guard('kitgenix_multistore_sync_for_woocommerce_bulk_job_start');
        $type=sanitize_key((string)($_POST['job_type']??'product_sync'));
        $dry_run=!empty($_POST['dry_run']);
        $this->plugin->get('bulk_job')->start($type,$dry_run);
        $this->redirect('sync',($dry_run?'Dry run':'Job').' started – see Bulk Operations below for live progress.');
    }
    public function bulk_job_pause():void{$this->guard('kitgenix_multistore_sync_for_woocommerce_bulk_job_pause');$this->plugin->get('bulk_job')->pause();$this->redirect('sync','Bulk job paused.');}
    public function bulk_job_resume():void{$this->guard('kitgenix_multistore_sync_for_woocommerce_bulk_job_resume');$this->plugin->get('bulk_job')->resume();$this->redirect('sync','Bulk job resumed.');}
    public function bulk_job_stop():void{$this->guard('kitgenix_multistore_sync_for_woocommerce_bulk_job_stop');$this->plugin->get('bulk_job')->stop();$this->redirect('sync','Bulk job stopped and reset.');}
    /**
     * Polled by the Sync page every ~1.5s while a job is running, instead of
     * the old whole-page reload every 4s: returns current job state as JSON
     * and pokes the next batch forward on every call (see BulkJob::poke()),
     * so progress keeps advancing back-to-back without waiting on a page
     * reload to give WP-Cron/Action Scheduler a chance to run.
     */
    public function bulk_job_poll():void{
        if(!Access::can_manage()||!check_ajax_referer('kitgenix_multistore_sync_for_woocommerce_bulk_job_poll','nonce',false)){wp_send_json_error(array('message'=>'Invalid request.'),403);}
        $bulk_job=$this->plugin->get('bulk_job');
        $bulk_job->poke();
        wp_send_json_success($bulk_job->progress());
    }
    public function create_automation():void{$this->guard('kitgenix_multistore_sync_for_woocommerce_create_automation');$trigger=sanitize_key($_POST['trigger']??'stock_below');if(!in_array($trigger,array('stock_below','stock_above'),true))$trigger='stock_below';$status=sanitize_key($_POST['status']??'draft');if(!in_array($status,array('draft','publish','private'),true))$status='draft';$this->plugin->get('automations')->create(sanitize_text_field(wp_unslash($_POST['name']??'Inventory rule')),$trigger,array('threshold'=>(float)($_POST['threshold']??1)),'set_product_status',array('status'=>$status));$this->redirect('automations','Automation created.');}
    public function delete_automation():void{$id=(int)($_GET['id']??0);if(!Access::can_manage()||!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']??''),'kitgenix_multistore_sync_for_woocommerce_delete_automation_'.$id))wp_die('Invalid request.');$this->plugin->get('automations')->delete($id);$this->redirect('automations','Automation deleted.');}
    public function remote_order_status():void{$network=sanitize_text_field(wp_unslash($_POST['network_id']??''));if(!Access::can_manage()||!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']??''),'kitgenix_multistore_sync_for_woocommerce_remote_order_status_'.$network))wp_die('Invalid request.');$r=$this->plugin->get('order_sync')->update_remote_status($network,sanitize_key($_POST['status']??''));$this->redirect('orders',is_wp_error($r)?$r->get_error_message():'Remote order status updated.');}
    public function migrate_woomultistore():void{$this->guard('kitgenix_multistore_sync_for_woocommerce_migrate_woomultistore');$i=new WooMultistoreImporter($this->plugin->get('mappings'),$this->plugin->get('logger'));$r=$i->import();$this->redirect('migration',sprintf('Imported %d products and %d variations.',$r['products'],$r['variations']));}
    public function clear_logs():void{$this->guard('kitgenix_multistore_sync_for_woocommerce_clear_logs');$this->plugin->get('logger')->clear();$this->redirect('logs','Log cleared.');}
    public function index_orders():void{$this->guard('kitgenix_multistore_sync_for_woocommerce_index_orders');$count=$this->plugin->get('order_sync')->schedule_backfill(90);$this->redirect('orders',sprintf('%d orders queued for indexing.',$count));}
    public function remote_order_note():void{$network=sanitize_text_field(wp_unslash($_POST['network_id']??''));if(!Access::can_manage()||!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']??''),'kitgenix_multistore_sync_for_woocommerce_remote_order_note_'.$network))wp_die('Invalid request.');$r=$this->plugin->get('order_sync')->update_remote_note($network,(string)wp_unslash($_POST['note']??''),!empty($_POST['customer_note']));$this->redirect('orders',is_wp_error($r)?$r->get_error_message():'Order note added on originating store.');}
    public function remote_order_refund():void{$network=sanitize_text_field(wp_unslash($_POST['network_id']??''));if(!Access::can_manage()||!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']??''),'kitgenix_multistore_sync_for_woocommerce_remote_order_refund_'.$network))wp_die('Invalid request.');$r=$this->plugin->get('order_sync')->update_remote_refund($network,(string)wp_unslash($_POST['amount']??''),(string)wp_unslash($_POST['reason']??''),!empty($_POST['refund_payment']),false,array());$this->redirect('orders',is_wp_error($r)?$r->get_error_message():'Refund created on originating store.');}
    public function resolve_conflict():void{$uuid=sanitize_text_field($_GET['uuid']??'');if(!Access::can_manage()||!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']??''),'kitgenix_multistore_sync_for_woocommerce_resolve_conflict_'.$uuid))wp_die('Invalid request.');$this->plugin->get('conflicts')->resolve($uuid);$this->redirect('sync','Conflict marked as resolved.');}
    public function save_store_pricing_rules():void{
        $this->guard('kitgenix_multistore_sync_for_woocommerce_save_store_pricing_rules');
        if(!$this->plugin->get('settings')->is_control())$this->redirect('stores','Pricing rules can only be managed from the Control Store.');
        $active=array();foreach($this->plugin->get('stores')->all()as$store){if('connected'===($store['role']??''))$active[(string)$store['uuid']]=true;}
        $enabled=(array)($_POST['pricing_enabled']??array());
        $modes=(array)($_POST['pricing_mode']??array());
        $percentages=(array)($_POST['pricing_percentage']??array());
        $fixed_amounts=(array)($_POST['pricing_fixed_amount']??array());
        $multipliers=(array)($_POST['pricing_multiplier']??array());
        $roundings=(array)($_POST['pricing_rounding']??array());
        $sale_modes=(array)($_POST['pricing_sale_mode']??array());
        $numeric=static function($raw,$default=0.0):float{$raw=is_scalar($raw)?wp_unslash((string)$raw):'';return is_numeric($raw)?(float)$raw:$default;};
        $rules=array();
        foreach($active as$uuid=>$_){
            $rules[$uuid]=array(
                'enabled'=>isset($enabled[$uuid])?'yes':'no',
                'mode'=>sanitize_key((string)($modes[$uuid]??'percentage')),
                'percentage'=>$numeric($percentages[$uuid]??0),
                'fixed_amount'=>$numeric($fixed_amounts[$uuid]??0),
                'multiplier'=>$numeric($multipliers[$uuid]??1,1.0),
                'rounding'=>sanitize_key((string)($roundings[$uuid]??'none')),
                'sale_mode'=>sanitize_key((string)($sale_modes[$uuid]??'same_adjustment')),
            );
        }
        $this->plugin->get('settings')->save_pricing_rules($rules);
        $count=0;if(!empty($_POST['resync_products']))$count=$this->plugin->get('product_sync')->schedule_all();
        $message='Store pricing rules saved.';if($count)$message.=' '.$count.' products queued for synchronisation.';
        $this->redirect('stores',$message);
    }
    public function save_store_policies(): void {$this->guard('kitgenix_multistore_sync_for_woocommerce_save_store_policies');if(!$this->plugin->get('settings')->is_control())$this->redirect('stores','Policies can only be managed on the Control Store.');$input=(array)($_POST['policy']??array());$policies=array();foreach($this->plugin->get('stores')->all()as$st){if('connected'!==($st['role']??''))continue;$uuid=(string)$st['uuid'];$fields=array();foreach(Settings::policy_fields()as$key=>$_label){$fields[$key]=array('publish'=>isset($input[$uuid][$key]['publish'])?'yes':'no','update'=>isset($input[$uuid][$key]['update'])?'yes':'no');}$policies[$uuid]=array('fields'=>$fields);}$this->plugin->get('settings')->save_policies($policies);$count=!empty($_POST['resync_products'])?$this->plugin->get('product_sync')->schedule_all():0;$this->redirect('stores','Store policies saved.'.($count?' '.$count.' products queued.':''));}
    public function toggle_store(): void {$uuid=sanitize_text_field($_GET['uuid']??'');$status=sanitize_key($_GET['status']??'disabled');if(!Access::can_manage()||!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']??''),'kitgenix_multistore_sync_for_woocommerce_toggle_store_'.$uuid))wp_die('Invalid request.');$status='disabled'===$status?'disabled':'active';$this->plugin->get('stores')->set_status($uuid,$status);$resumed='active'===$status?$this->plugin->get('queue')->resume_store($uuid):0;$this->redirect('stores','Store '.('disabled'===$status?'paused.':'resumed.'.($resumed?' '.$resumed.' paused deliveries requeued.':'')));}
    public function export_orders(): void {if(!Access::can_manage()||!check_admin_referer('kitgenix_multistore_sync_for_woocommerce_export_orders'))wp_die('Invalid request.');$args=array('search'=>sanitize_text_field(wp_unslash($_GET['s']??'')),'status'=>sanitize_key($_GET['status']??''),'store_uuid'=>sanitize_text_field($_GET['store_uuid']??''));$this->plugin->get('order_exporter')->download($args,sanitize_key($_GET['format']??'csv'));}
    public function bulk_order_status(): void {$this->guard('kitgenix_multistore_sync_for_woocommerce_bulk_order_status');$status=sanitize_key($_POST['status']??'');$ids=array_values(array_unique(array_filter(array_map('sanitize_text_field',(array)($_POST['network_ids']??array())))));$ok=0;$failed=0;foreach(array_slice($ids,0,250)as$network){$r=$this->plugin->get('order_sync')->update_remote_status($network,$status);is_wp_error($r)?$failed++:$ok++;}$this->redirect('orders',$ok.' orders updated'.($failed?' · '.$failed.' failed.':'.'));}
    public function create_store_group():void{$this->guard('kitgenix_multistore_sync_for_woocommerce_create_store_group');$name=sanitize_text_field(wp_unslash($_POST['group_name']??''));if(!$name)$this->redirect('stores','A group name is required.');$allowed=array_column(array_values(array_filter($this->plugin->get('stores')->all(),static fn($s)=>'connected'===($s['role']??''))),'uuid');$stores=array_values(array_intersect($allowed,array_map('sanitize_text_field',(array)($_POST['group_stores']??array()))));$groups=$this->plugin->get('settings')->groups();$base=sanitize_title($name)?:'group';$id=$base;$n=2;while(isset($groups[$id])){$id=$base.'-'.$n++;}$groups[$id]=array('name'=>$name,'stores'=>$stores);$this->plugin->get('settings')->save_groups($groups);$this->redirect('stores','Store group created.');}
    public function delete_store_group():void{$id=sanitize_key($_GET['group']??'');if(!Access::can_manage()||!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']??''),'kitgenix_multistore_sync_for_woocommerce_delete_store_group_'.$id))wp_die('Invalid request.');$groups=$this->plugin->get('settings')->groups();unset($groups[$id]);$this->plugin->get('settings')->save_groups($groups);$this->redirect('stores','Store group deleted.');}
    public function retry_delivery():void{$event=sanitize_text_field($_GET['event']??'');$store=sanitize_text_field($_GET['store']??'');if(!Access::can_manage()||!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']??''),'kitgenix_multistore_sync_for_woocommerce_retry_delivery_'.$event.'_'.$store))wp_die('Invalid request.');$this->plugin->get('queue')->retry($event,$store);$this->redirect('sync','Delivery queued for retry.');}
    private function guard(string$action):void{if(!Access::can_manage()||!check_admin_referer($action))wp_die('Invalid request.');}
    private function redirect(string$view,string$notice):never{wp_safe_redirect(admin_url('admin.php?page=kitgenix-multistore-sync-for-woocommerce&view='.$view.'&kitgenix_multistore_sync_for_woocommerce_notice='.rawurlencode($notice)));exit;}
    private function metric(string$label,string|int$value,string$sub='',bool$html=false,string$value_id='',string$sub_id=''):string{$sub_id_attr=''!==$sub_id?' id="'.esc_attr($sub_id).'"':'';$trend=''!==$sub?'<div class="kitgenix-stat-tile-trend kitgenix-trend-neutral"><span'.$sub_id_attr.'>'.esc_html($sub).'</span></div>':'';$value_id_attr=''!==$value_id?' id="'.esc_attr($value_id).'"':'';return '<div class="kitgenix-stat-tile"><div class="kitgenix-stat-tile-head"><span class="kitgenix-stat-tile-label">'.esc_html($label).'</span></div><div class="kitgenix-stat-tile-value"'.$value_id_attr.'>'.($html?wp_kses_post((string)$value):esc_html((string)$value)).'</div>'.$trend.'</div>';}
    private function integration(string$name,string$status,bool$ok):string{$badge=$ok?'success':'danger';return '<div class="kitgenix-card"><div class="kitgenix-card-body"><div class="kitgenix-card-head-main"><span class="kitgenix-card-icon" aria-hidden="true">'.$this->icon('link').'</span><div class="kitgenix-card-head-text"><h3>'.esc_html($name).'</h3><p>'.esc_html($status).'</p></div></div><p><span class="kitgenix-badge '.$badge.' kitgenix-badge-dot">'.($ok?esc_html__('Integrated','kitgenix-multistore-sync-for-woocommerce'):esc_html__('No adapter','kitgenix-multistore-sync-for-woocommerce')).'</span></p></div></div>';}
    private function checks_table(array$checks):string{$rows='';foreach($checks as$name=>$c){$ok=!empty($c['ok']);$rows.='<tr><td><strong>'.esc_html(ucwords(str_replace('_',' ',$name))).'</strong></td><td><span class="kitgenix-badge '.($ok?'success':'danger').' kitgenix-badge-dot">'.($ok?esc_html__('OK','kitgenix-multistore-sync-for-woocommerce'):esc_html__('Fail','kitgenix-multistore-sync-for-woocommerce')).'</span></td><td>'.esc_html((string)($c['value']??'')).'</td></tr>';}return '<div class="kitgenix-table-wrap"><table class="kitgenix-table"><tbody>'.$rows.'</tbody></table></div>';}
    private function toggle(string$key,string$title,string$desc,array$a):void{echo '<div class="kitgenix-setting-row"><div class="kitgenix-setting-row-label"><label for="kitgenix_field_'.esc_attr($key).'">'.esc_html($title).'</label><p class="kitgenix-setting-row-desc">'.esc_html($desc).'</p></div><div class="kitgenix-setting-row-control"><label class="kitgenix-toggle"><input type="checkbox" id="kitgenix_field_'.esc_attr($key).'" class="kitgenix-toggle-input" name="'.esc_attr($key).'" value="yes" '.checked($a[$key]??'no','yes',false).'><span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span></label></div></div>';}
}
