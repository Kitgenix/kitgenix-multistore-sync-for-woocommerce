<?php
namespace Kitgenix\MultiStore\Cli;

use Kitgenix\MultiStore\Plugin;

final class Commands {
    private static ?Plugin $plugin=null;
    public static function register(Plugin$plugin):void{self::$plugin=$plugin;\WP_CLI::add_command('kitgenix-multistore-sync-for-woocommerce status',array(__CLASS__,'status'));\WP_CLI::add_command('kitgenix-multistore-sync-for-woocommerce reconcile',array(__CLASS__,'reconcile'));\WP_CLI::add_command('kitgenix-multistore-sync-for-woocommerce sync-product',array(__CLASS__,'sync_product'));\WP_CLI::add_command('kitgenix-multistore-sync-for-woocommerce doctor',array(__CLASS__,'doctor'));\WP_CLI::add_command('kitgenix-multistore-sync-for-woocommerce index-orders',array(__CLASS__,'index_orders'));\WP_CLI::add_command('kitgenix-multistore-sync-for-woocommerce bulk-sync',array(__CLASS__,'bulk_sync'));\WP_CLI::add_command('kitgenix-multistore-sync-for-woocommerce bulk-reconcile',array(__CLASS__,'bulk_reconcile'));}
    public static function status():void{$p=self::$plugin;\WP_CLI::line('Kitgenix MultiStore '.KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION);\WP_CLI::line('Role: '.$p->get('settings')->role());\WP_CLI::line('Stores: '.count($p->get('stores')->all()));\WP_CLI::line('Products: '.$p->get('mappings')->count('product'));\WP_CLI::line('Inventory: '.$p->get('inventory')->count());$counts=$p->get('events')->counts();foreach($counts as$k=>$v)\WP_CLI::line(ucfirst($k).': '.$v);}
    public static function reconcile():void{$r=self::$plugin->get('reconciler')->run();\WP_CLI::success(sprintf('Checked %d records; drift %d; repairs %d.',$r['checked'],$r['drift'],$r['repaired']));}
    public static function sync_product(array$args):void{$id=(int)($args[0]??0);if(!$id)\WP_CLI::error('Provide a WooCommerce product ID.');$r=self::$plugin->get('product_sync')->sync_now($id);if(is_wp_error($r))\WP_CLI::error($r->get_error_message());\WP_CLI::success('Product sync queued.');}
    public static function index_orders(array$args,array$assoc_args):void{$days=max(1,min(3650,(int)($assoc_args['days']??90)));$count=self::$plugin->get('order_sync')->schedule_backfill($days);\WP_CLI::success(sprintf('%d orders queued for indexing from the last %d days.',$count,$days));}
    public static function doctor():void{$checks=self::$plugin->get('doctor')->local_checks();foreach($checks as$name=>$c)\WP_CLI::line(sprintf('%s %s: %s',$c['ok']?'✓':'✕',$name,$c['value']));}

    /**
     * Bulk-sync products to every connected store, with a live progress bar,
     * an optional dry run, and an optional explicit product ID list ("selected
     * products" – omit for "all products"). Resumable: re-running the same
     * command after a partial run continues rather than restarting.
     *
     * ## OPTIONS
     *
     * [--products=<ids>]
     * : Comma-separated attachment... product IDs to sync. Omit for every product.
     *
     * [--dry-run]
     * : Report what would happen without changing or sending anything.
     *
     * ## EXAMPLES
     *
     *   wp kitgenix-multistore-sync-for-woocommerce bulk-sync
     *   wp kitgenix-multistore-sync-for-woocommerce bulk-sync --products=12,45,301
     *   wp kitgenix-multistore-sync-for-woocommerce bulk-sync --dry-run
     */
    public static function bulk_sync(array$args,array$assoc_args):void{
        $product_ids=isset($assoc_args['products'])?array_map('absint',explode(',',(string)$assoc_args['products'])):array();
        self::run_bulk_job('product_sync',isset($assoc_args['dry-run']),$product_ids);
    }

    /**
     * Reconcile authoritative inventory against every connected store, with a
     * live progress bar and an optional dry run. Resumable if interrupted.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report drift/exclusions without dispatching any repairs.
     *
     * ## EXAMPLES
     *
     *   wp kitgenix-multistore-sync-for-woocommerce bulk-reconcile
     *   wp kitgenix-multistore-sync-for-woocommerce bulk-reconcile --dry-run
     */
    public static function bulk_reconcile(array$args,array$assoc_args):void{
        self::run_bulk_job('reconcile',isset($assoc_args['dry-run']));
    }

    private static function run_bulk_job(string$type,bool$dry_run,array$product_ids=array()):void{
        $job=self::$plugin->get('bulk_job');
        $state=$job->start($type,$dry_run,$product_ids);
        $progress=\WP_CLI\Utils\make_progress_bar(($dry_run?'Dry run: ':'').($type==='reconcile'?'Reconciling inventory':'Syncing products'),max(1,(int)$state['total']));
        $seen=0;
        do{
            $job->tick(false);
            $state=$job->progress();
            $delta=(int)$state['processed']-$seen;
            if($delta>0){$progress->tick($delta);$seen=(int)$state['processed'];}
        } while('running'===$state['status']);
        $progress->finish();
        \WP_CLI::success(sprintf('%s. Processed %d of %d. Repaired/synced %d, excluded %d, drift %d, errors %d.',ucfirst((string)$state['status']),(int)$state['processed'],(int)$state['total'],(int)$state['repaired'],(int)$state['excluded'],(int)$state['drift'],count($state['errors'])));
    }
}
