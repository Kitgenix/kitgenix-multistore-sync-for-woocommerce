<?php
namespace Kitgenix\MultiStore\Repository;

use Kitgenix\MultiStore\Support\Revision;
use Kitgenix\MultiStore\Support\Uuid;

final class InventoryRepository {
    private string $table;
    private string $ledger;
    public function __construct(){global $wpdb;$this->table=$wpdb->prefix.'kitgenix_multistore_sync_for_woocommerce_inventory';$this->ledger=$wpdb->prefix.'kitgenix_multistore_sync_for_woocommerce_inventory_ledger';}
    public function get(string $network_id):?array{global $wpdb;$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE network_id=%s LIMIT 1",$network_id),ARRAY_A);return $r?:null;}
    public function set(string $network_id,?float $qty,string $status,string $source,int $revision=0):array{
        global $wpdb;$revision=$revision?:Revision::next();$now=current_time('mysql',true);$q=null===$qty?null:(string)$qty;
        $sql="INSERT INTO {$this->table} (network_id,quantity,stock_status,revision,source_store_uuid,updated_at) VALUES (%s,%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE quantity=IF(VALUES(revision)>=revision,VALUES(quantity),quantity),stock_status=IF(VALUES(revision)>=revision,VALUES(stock_status),stock_status),source_store_uuid=IF(VALUES(revision)>=revision,VALUES(source_store_uuid),source_store_uuid),updated_at=IF(VALUES(revision)>=revision,VALUES(updated_at),updated_at),revision=GREATEST(revision,VALUES(revision))";
        $wpdb->query($wpdb->prepare($sql,$network_id,$q,$status,$revision,$source,$now));
        return $this->get($network_id)?:array();
    }
    /** Apply an absolute authoritative inventory quantity and append an idempotent ledger entry. */
    public function apply_set(string $network_id,float $quantity,string $status,string $source,string $event_uuid,string $reason='inventory_set',string $reference=''):array{
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try{
            $existing=$wpdb->get_row($wpdb->prepare("SELECT quantity_after,revision FROM {$this->ledger} WHERE event_uuid=%s LIMIT 1",$event_uuid),ARRAY_A);
            if($existing){$wpdb->query('COMMIT');$current=$this->get($network_id);return array('quantity'=>(float)$existing['quantity_after'],'revision'=>(int)$existing['revision'],'stock_status'=>(string)($current['stock_status']??$status),'duplicate'=>true);}
            $current=$wpdb->get_row($wpdb->prepare("SELECT quantity,stock_status FROM {$this->table} WHERE network_id=%s LIMIT 1 FOR UPDATE",$network_id),ARRAY_A);
            $before=$current&&null!==$current['quantity']?(float)$current['quantity']:0.0;
            $revision=Revision::next();$now=current_time('mysql',true);$resolved_status=$quantity>0?'instock':$status;
            $sql="INSERT INTO {$this->table} (network_id,quantity,stock_status,revision,source_store_uuid,updated_at)
                  VALUES (%s,%f,%s,%d,%s,%s)
                  ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),stock_status=VALUES(stock_status),revision=VALUES(revision),source_store_uuid=VALUES(source_store_uuid),updated_at=VALUES(updated_at)";
            $ok=$wpdb->query($wpdb->prepare($sql,$network_id,$quantity,$resolved_status,$revision,$source,$now));
            if(false===$ok)throw new \RuntimeException('Unable to set authoritative inventory: '.$wpdb->last_error);
            $ok=$wpdb->insert($this->ledger,array('entry_uuid'=>Uuid::make(),'network_id'=>$network_id,'event_uuid'=>$event_uuid,'source_store_uuid'=>$source,'delta'=>$quantity-$before,'quantity_after'=>$quantity,'reason'=>sanitize_key($reason),'reference'=>sanitize_text_field($reference),'revision'=>$revision,'created_at'=>$now));
            if(false===$ok)throw new \RuntimeException('Unable to append authoritative inventory ledger entry: '.$wpdb->last_error);
            $wpdb->query('COMMIT');
            return array('quantity'=>$quantity,'revision'=>$revision,'stock_status'=>$resolved_status);
        }catch(\Throwable$e){$wpdb->query('ROLLBACK');throw$e;}
    }

    public function apply_delta(string $network_id,float $delta,?float $base,string $status,string $source,string $event_uuid,string $reason='stock_change',string $reference=''):array{
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try{
            $existing=$wpdb->get_row($wpdb->prepare("SELECT quantity_after,revision FROM {$this->ledger} WHERE event_uuid=%s LIMIT 1",$event_uuid),ARRAY_A);
            if($existing){$wpdb->query('COMMIT');$current=$this->get($network_id);return array('quantity'=>(float)$existing['quantity_after'],'revision'=>(int)$existing['revision'],'stock_status'=>(string)($current['stock_status']??$status),'duplicate'=>true);}
            $revision=Revision::next();$now=current_time('mysql',true);$base=null===$base?0.0:$base;$insert_qty=$base+$delta;
            $sql="INSERT INTO {$this->table} (network_id,quantity,stock_status,revision,source_store_uuid,updated_at)
                  VALUES (%s,%f,%s,%d,%s,%s)
                  ON DUPLICATE KEY UPDATE quantity=COALESCE(quantity,%f)+%f,revision=VALUES(revision),source_store_uuid=VALUES(source_store_uuid),updated_at=VALUES(updated_at)";
            $ok=$wpdb->query($wpdb->prepare($sql,$network_id,$insert_qty,$status,$revision,$source,$now,$base,$delta));
            if(false===$ok)throw new \RuntimeException('Unable to update authoritative inventory: '.$wpdb->last_error);
            $row=$this->get($network_id);$after=(float)($row['quantity']??$insert_qty);
            $resolved_status=$after>0?'instock':$status;
            if($row&&$resolved_status!==$row['stock_status']){$ok=$wpdb->update($this->table,array('stock_status'=>$resolved_status),array('network_id'=>$network_id));if(false===$ok)throw new \RuntimeException('Unable to update authoritative stock status: '.$wpdb->last_error);}
            $ok=$wpdb->insert($this->ledger,array('entry_uuid'=>Uuid::make(),'network_id'=>$network_id,'event_uuid'=>$event_uuid,'source_store_uuid'=>$source,'delta'=>$delta,'quantity_after'=>$after,'reason'=>sanitize_key($reason),'reference'=>sanitize_text_field($reference),'revision'=>$revision,'created_at'=>$now));
            if(false===$ok)throw new \RuntimeException('Unable to append inventory ledger: '.$wpdb->last_error);
            $wpdb->query('COMMIT');
            return array('quantity'=>$after,'revision'=>$revision,'stock_status'=>$resolved_status);
        }catch(\Throwable$e){$wpdb->query('ROLLBACK');throw$e;}
    }
    public function all(int $limit=100,int $offset=0):array{global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} ORDER BY updated_at DESC LIMIT %d OFFSET %d",$limit,$offset),ARRAY_A)?:array();}
    public function count():int{global $wpdb;return(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");}
    public function low_stock_count(float $threshold=5):int{global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE quantity IS NOT NULL AND quantity <= %f",$threshold));}
}
