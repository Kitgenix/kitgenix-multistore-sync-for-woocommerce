<?php
namespace Kitgenix\MultiStore\Automation;

use Kitgenix\MultiStore\Repository\AutomationRepository;
use Kitgenix\MultiStore\Repository\MappingRepository;
use Kitgenix\MultiStore\Support\Context;
use Kitgenix\MultiStore\Support\Logger;

final class Engine {
    public function __construct(private AutomationRepository$rules,private MappingRepository$mappings,private Logger$logger){}
    public function hooks():void{add_action('kitgenix_multistore/inventory_authoritative_changed',array($this,'inventory_changed'),20,3);}
    public function inventory_changed(string$network_id,float$qty,string$status):void{foreach(array('stock_below','stock_above')as$trigger){foreach($this->rules->enabled($trigger)as$rule){$cfg=json_decode((string)$rule['trigger_config'],true)?:array();$threshold=(float)($cfg['threshold']??0);$match='stock_below'===$trigger?$qty<$threshold:$qty>$threshold;if(!$match)continue;$action=json_decode((string)$rule['action_config'],true)?:array();if('set_product_status'===$rule['action_type']){$this->set_status($network_id,(string)($action['status']??'draft'));$this->rules->touch((int)$rule['id']);$this->logger->info('Automation executed.',array('automation'=>$rule['name'],'network_id'=>$network_id,'quantity'=>$qty));}}}}
    private function set_status(string$network_id,string$status):void{$id=$this->mappings->local_id($network_id,'product');if(!$id){$vid=$this->mappings->local_id($network_id,'variation');$id=$vid?(int)wp_get_post_parent_id($vid):0;}if(!$id)return;$allowed=array('publish','draft','private');if(!in_array($status,$allowed,true))return;$p=wc_get_product($id);if($p&&$p->get_status()!==$status){$p->set_status($status);$p->save();}}
}
