<?php
namespace Kitgenix\MultiStore\Cli;

use Kitgenix\MultiStore\Plugin;

final class Commands {
    private static ?Plugin $plugin=null;
    public static function register(Plugin$plugin):void{self::$plugin=$plugin;\WP_CLI::add_command('kitgenix-multistore status',array(__CLASS__,'status'));\WP_CLI::add_command('kitgenix-multistore reconcile',array(__CLASS__,'reconcile'));\WP_CLI::add_command('kitgenix-multistore sync-product',array(__CLASS__,'sync_product'));\WP_CLI::add_command('kitgenix-multistore doctor',array(__CLASS__,'doctor'));\WP_CLI::add_command('kitgenix-multistore index-orders',array(__CLASS__,'index_orders'));}
    public static function status():void{$p=self::$plugin;\WP_CLI::line('Kitgenix MultiStore '.KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION);\WP_CLI::line('Role: '.$p->get('settings')->role());\WP_CLI::line('Stores: '.count($p->get('stores')->all()));\WP_CLI::line('Products: '.$p->get('mappings')->count('product'));\WP_CLI::line('Inventory: '.$p->get('inventory')->count());$counts=$p->get('events')->counts();foreach($counts as$k=>$v)\WP_CLI::line(ucfirst($k).': '.$v);}
    public static function reconcile():void{$r=self::$plugin->get('reconciler')->run();\WP_CLI::success(sprintf('Checked %d records; drift %d; repairs %d.',$r['checked'],$r['drift'],$r['repaired']));}
    public static function sync_product(array$args):void{$id=(int)($args[0]??0);if(!$id)\WP_CLI::error('Provide a WooCommerce product ID.');$r=self::$plugin->get('product_sync')->sync_now($id);if(is_wp_error($r))\WP_CLI::error($r->get_error_message());\WP_CLI::success('Product sync queued.');}
    public static function index_orders(array$args,array$assoc_args):void{$days=max(1,min(3650,(int)($assoc_args['days']??90)));$count=self::$plugin->get('order_sync')->schedule_backfill($days);\WP_CLI::success(sprintf('%d orders queued for indexing from the last %d days.',$count,$days));}
    public static function doctor():void{$checks=self::$plugin->get('doctor')->local_checks();foreach($checks as$name=>$c)\WP_CLI::line(sprintf('%s %s: %s',$c['ok']?'✓':'✕',$name,$c['value']));}
}
