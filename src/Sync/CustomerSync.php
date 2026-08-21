<?php
namespace Kitgenix\MultiStore\Sync;

use Kitgenix\MultiStore\Repository\CustomerRepository;
use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Support\Context;
use Kitgenix\MultiStore\Support\Logger;
use Kitgenix\MultiStore\Support\Settings;

final class CustomerSync {
    private MappingRepository $mappings;
    public function __construct(private CustomerRepository$customers,private Dispatcher$dispatcher,private Settings$settings,private Logger$logger){$this->mappings=new MappingRepository();}
    public function hooks():void{add_action('woocommerce_created_customer',array($this,'changed'),30,1);add_action('woocommerce_update_customer',array($this,'changed'),30,1);}
    public function changed(int$id):void{if(Context::is_remote()||'yes'!==$this->settings->get('sync_customers','no')||!$this->settings->is_connected())return;$c=new \WC_Customer($id);if(!$c->get_id())return;$network=$this->mappings->network_id('customer',$id,$c->get_email());$this->dispatcher->dispatch('customer_upsert','customer',$network,$this->payload($c,$network));}
    public function receive(array$p,string$source):array|\WP_Error{if(!$this->settings->is_control())return new \WP_Error('kitgenix_multistore_sync_for_woocommerce_customer_topology','Customer snapshots are accepted by the control store only.');$this->customers->upsert($p,$source);return array('local_id'=>(int)($p['customer_id']??0),'object_key'=>(string)($p['email']??''));}
    private function payload(\WC_Customer$c,string$n):array{$billing=array();$shipping=array();foreach(array('first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone')as$f){$g='get_billing_'.$f;if(method_exists($c,$g))$billing[$f]=$c->{$g}();$g='get_shipping_'.$f;if(method_exists($c,$g))$shipping[$f]=$c->{$g}();}return apply_filters('kitgenix_multistore/customer/payload',array('network_id'=>$n,'customer_id'=>$c->get_id(),'email'=>$c->get_email(),'display_name'=>$c->get_display_name(),'first_name'=>$c->get_first_name(),'last_name'=>$c->get_last_name(),'billing'=>$billing,'shipping'=>$shipping,'date_created'=>$c->get_date_created()?$c->get_date_created()->date('c'):null,'date_modified'=>$c->get_date_modified()?$c->get_date_modified()->date('c'):null),$c);}
}
