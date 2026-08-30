<?php
namespace Kitgenix\MultiStore;

use Kitgenix\MultiStore\Admin\Admin;
use Kitgenix\MultiStore\Admin\BulkProductTools;
use Kitgenix\MultiStore\Admin\ProductPanel;
use Kitgenix\MultiStore\Automation\Engine;
use Kitgenix\MultiStore\Cli\Commands;
use Kitgenix\MultiStore\Database\Schema;
use Kitgenix\MultiStore\Diagnostics\NetworkDoctor;
use Kitgenix\MultiStore\Http\Client;
use Kitgenix\MultiStore\Integration\Registry;
use Kitgenix\MultiStore\Integration\ImportBridge;
use Kitgenix\MultiStore\Media\NetworkMedia;
use Kitgenix\MultiStore\Multisite\NativeNetwork;
use Kitgenix\MultiStore\Orders\MyAccount;
use Kitgenix\MultiStore\Orders\OrderExporter;
use Kitgenix\MultiStore\Orders\OrderImporter;
use Kitgenix\MultiStore\Orders\OrderNumbers;
use Kitgenix\MultiStore\Privacy\Privacy;
use Kitgenix\MultiStore\Repository\AutomationRepository;
use Kitgenix\MultiStore\Repository\ConflictRepository;
use Kitgenix\MultiStore\Repository\CustomerRepository;
use Kitgenix\MultiStore\Repository\EventRepository;
use Kitgenix\MultiStore\Repository\InventoryRepository;
use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Repository\OrderRepository;
use Kitgenix\MultiStore\Repository\StoreRepository;
use Kitgenix\MultiStore\Rest\Routes;
use Kitgenix\MultiStore\Support\Access;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;
use Kitgenix\MultiStore\Sync\CouponSync;
use Kitgenix\MultiStore\Sync\CustomerSync;
use Kitgenix\MultiStore\Sync\Dispatcher;
use Kitgenix\MultiStore\Sync\EventProcessor;
use Kitgenix\MultiStore\Sync\InventorySync;
use Kitgenix\MultiStore\Sync\OrderSync;
use Kitgenix\MultiStore\Sync\ProductApplier;
use Kitgenix\MultiStore\Sync\ProductPayload;
use Kitgenix\MultiStore\Sync\ProductSync;
use Kitgenix\MultiStore\Sync\BulkJob;
use Kitgenix\MultiStore\Sync\Queue;
use Kitgenix\MultiStore\Sync\Reconciler;
use Kitgenix\MultiStore\Sync\ReviewSync;
use Kitgenix\MultiStore\Sync\StorePolicy;

final class Plugin {
    private static ?self $instance = null;
    /** @var array<string,mixed> */ private array $services = array();
    public static function instance(): self { return self::$instance ??= new self(); }

    public function boot(): void {
        if ( (string) get_option( 'kitgenix_multistore_sync_for_woocommerce_db_version', '' ) !== KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_DB_VERSION ) Schema::install();
        ( new Privacy() )->hooks();
        add_action( 'wp_initialize_site', static function( $site ): void { if ( ! is_object( $site ) || empty( $site->blog_id ) ) return; switch_to_blog( (int) $site->blog_id ); Schema::install(); restore_current_blog(); }, 20 );

        $settings = new Settings(); $logger = new Logger(); $stores = new StoreRepository(); $mappings = new MappingRepository(); $events = new EventRepository();
        $inventory = new InventoryRepository(); $orders = new OrderRepository(); $customers = new CustomerRepository(); $conflicts = new ConflictRepository(); $automations = new AutomationRepository();
        $access = new Access( $settings ); $media = new NetworkMedia( $settings, $logger ); $integrations = new Registry( $logger, $settings ); $import_bridge = new ImportBridge( $settings ); $native = new NativeNetwork( $stores, $settings, $logger );
        $client = new Client( $stores, $logger ); $policy = new StorePolicy( $settings, $mappings );
        $queue = new Queue( $events, $conflicts, $stores, $client, $mappings, $logger, $settings, $policy ); $dispatcher = new Dispatcher( $events, $stores, $queue, $settings, $logger );
        $payload = new ProductPayload( $mappings, $settings ); $applier = new ProductApplier( $mappings, $settings, $logger, $media );
        $inventory_sync = new InventorySync( $inventory, $mappings, $dispatcher, $stores, $client, $logger, $settings );
        $product_sync = new ProductSync( $mappings, $payload, $dispatcher, $inventory_sync, $settings, $logger );
        $order_importer = new OrderImporter( $mappings, $settings, $logger ); $order_numbers = new OrderNumbers( $settings, $stores, $client, $logger );
        $order_sync = new OrderSync( $orders, $mappings, $dispatcher, $stores, $client, $settings, $logger, $order_importer );
        $coupon_sync = new CouponSync( $mappings, $dispatcher, $settings, $logger ); $customer_sync = new CustomerSync( $customers, $dispatcher, $settings, $logger );
        $review_sync = new ReviewSync( $mappings, $dispatcher, $settings, $logger );
        $processor = new EventProcessor( $events, $conflicts, $stores, $applier, $inventory_sync, $order_sync, $coupon_sync, $customer_sync, $review_sync, $product_sync, $dispatcher, $settings, $logger );
        $reconciler = new Reconciler( $inventory, $mappings, $stores, $client, $dispatcher, $logger, $product_sync ); $doctor = new NetworkDoctor( $stores, $client, $events, $inventory, $logger, $conflicts ); $engine = new Engine( $automations, $mappings, $logger );
        $bulk_job = new BulkJob( $product_sync, $inventory, $mappings, $stores, $client, $dispatcher, $logger );
        $my_account = new MyAccount( $orders, $stores, $settings, $client ); $order_exporter = new OrderExporter( $orders, $stores ); $bulk_tools = new BulkProductTools( $product_sync );

        $this->services = compact( 'settings','logger','stores','mappings','events','inventory','orders','customers','conflicts','automations','access','media','integrations','import_bridge','native','client','policy','queue','dispatcher','payload','applier','inventory_sync','product_sync','order_importer','order_numbers','order_sync','coupon_sync','customer_sync','review_sync','processor','reconciler','doctor','engine','my_account','order_exporter','bulk_tools','bulk_job' );

        ( new Routes( $stores, $client, $processor, $inventory, $mappings, $orders, $order_sync, $order_numbers, $doctor, $logger ) )->hooks();
        $access->hooks(); $media->hooks(); $integrations->hooks(); $import_bridge->hooks(); $native->hooks(); $queue->hooks(); $product_sync->hooks(); $inventory_sync->hooks(); $order_sync->hooks(); $order_importer->hooks(); $order_numbers->hooks();
        $coupon_sync->hooks(); $customer_sync->hooks(); $review_sync->hooks(); $reconciler->hooks(); $engine->hooks(); $my_account->hooks(); $bulk_tools->hooks(); $bulk_job->hooks();
        ( new ProductPanel( $stores, $mappings, $product_sync, $settings ) )->hooks(); ( new Admin( $this ) )->hooks();
        if ( defined( 'WP_CLI' ) && WP_CLI ) Commands::register( $this );
        do_action( 'kitgenix_multistore_loaded', $this );
    }

    public function get( string $id ): mixed { return $this->services[ $id ] ?? null; }
}
