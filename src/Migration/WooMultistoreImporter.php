<?php
namespace Kitgenix\MultiStore\Migration;

use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Support\Logger;

final class WooMultistoreImporter {
    public function __construct(private MappingRepository$mappings,private Logger$logger){}
    public function detect():array{global$wpdb;$count=(int)$wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE '_woonet_%'");return array('detected'=>$count>0,'products'=>$count,'settings'=>(bool)get_option('woonet_options'));}
    public function import():array{global$wpdb;$ids=$wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE '_woonet_%' ORDER BY post_id ASC");$products=0;$variations=0;foreach($ids as$id){$post=get_post((int)$id);if(!$post)continue;if('product'===$post->post_type){$p=wc_get_product($post->ID);if($p){$this->mappings->network_id('product',$p->get_id(),(string)$p->get_sku());update_post_meta($p->get_id(),'_kitgenix_multistore_sync_for_woocommerce_sync_enabled','yes');$products++;}}elseif('product_variation'===$post->post_type){$p=wc_get_product($post->ID);if($p){$this->mappings->network_id('variation',$p->get_id(),(string)$p->get_sku());$variations++;}}}$result=array('products'=>$products,'variations'=>$variations,'note'=>'Local WooMultistore relationships were converted to stable Kitgenix network IDs. Pair remote stores before running a full product sync.');$this->logger->info('WooMultistore migration completed.',$result);update_option('kitgenix_multistore_sync_for_woocommerce_woomultistore_migrated_at',current_time('mysql',true),false);return$result;}
}
