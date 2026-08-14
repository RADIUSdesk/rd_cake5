<?php
/**
 * Created by G-edit.
 * User: dirkvanderwalt
 * Date: 08-AUG-2026
 * Time: 00:00
 */

namespace App\Controller;
use App\Controller\AppController;

use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

use Cake\Utility\Inflector;
use Cake\I18n\DateTime;

use App\Model\Table\NlbwApStatsTable;
use App\Model\Table\ApsTable;
use App\Model\Table\NodesTable;
use App\Model\Table\ApProfileExitsTable;
use App\Model\Table\MeshExitsTable;
use App\Model\Table\MacAddressesTable;
use App\Model\Table\MacAliasesTable;
use App\Model\Table\TimezonesTable;

class BandwidthReportsController extends AppController{

    protected $main_model   = 'NlbwApStats';
    protected $time_zone    = 'UTC'; //Default for timezone
    protected $span         = 'hour'; //hour, day or week
     
    protected $base_search  = false;
    protected $base_search_no_mac = false;

    protected $graph_item   = 'ap'; //interface or (**node** with **device** or **protocol** ) or (**ap** with **ap_device** or **ap_protocol**)
    protected $mac          = false;
    protected $mac_protocol = false;
    protected $mac_address_id = false;
    protected $graph_name   = '';
    
    protected $client_count= 0;
    protected $proto_count = 0;
    
    protected $protocol     = false;    
    protected $protocol_mac_id = false;
    
    protected int $ap_profile_id; 
    protected $base_search_no_protocol;
    
    protected $fields = [
        'data_in'       => 'COALESCE(SUM(tx_bytes), 0)',
        'data_out'      => 'COALESCE(SUM(rx_bytes), 0)',
        'data_total'    => 'COALESCE(SUM(tx_bytes), 0) + COALESCE(SUM(rx_bytes), 0)',
        'packets_in'    => 'COALESCE(SUM(tx_pkts), 0)',
        'packets_out'   => 'COALESCE(SUM(rx_pkts), 0)',
        'packets_total' => 'COALESCE(SUM(tx_pkts), 0) + COALESCE(SUM(rx_pkts), 0)',
    ];
    
    protected NlbwApStatsTable $NlbwApStats;
    protected ApsTable $Aps;
    protected NodesTable $Nodes;
    protected ApProfileExitsTable $ApProfileExits;
    protected MeshExitsTable $MeshExits;
    protected MacAddressesTable $MacAddresses;
    protected MacAliasesTable $MacAliases;
    protected TimezonesTable $Timezones;

   
    public function initialize():void{  
        parent::initialize();
        
        $this->NlbwApStats       = $this->fetchTable('NlbwApStats');
        $this->Aps               = $this->fetchTable('Aps');
        $this->Nodes             = $this->fetchTable('Nodes');
        $this->ApProfileExits    = $this->fetchTable('ApProfileExits');
        $this->MeshExits         = $this->fetchTable('MeshExits');
        $this->MacAddresses      = $this->fetchTable('MacAddresses');
        $this->MacAliases        = $this->fetchTable('MacAliases');
        $this->Timezones         = $this->fetchTable('Timezones');
        
        $this->loadComponent('Aa');
        $this->loadComponent('JsonErrors'); 
        $this->loadComponent('TimeCalculations');          
    }
    
    public function indexInterfaces(){
        
        $user = $this->Aa->user_for_token($this);
        if (!$user) {   //If not a valid user
            return;
        }
        
        $items  = [];
        $req_q  = $this->request->getQuery();  
          
        if(isset($req_q['all_option'])&&($req_q['all_option']==='true')){
            $items[]=[
                'id'  	=> -1, 
                'name'  => 'All interfaces where enabled',
                'type'  => 'special'
            ];       
        }
        
        $mode       =  $this->request->getQuery('dev_mode');
        $device_id  =  $this->request->getQuery('dev_id');
        if($mode == 'ap'){
            $device_id = 
            $ap = $this->Aps->find()->where(['Aps.id' => $device_id])->first();
            if($ap){
                $interfaces = $this->ApProfileExits->find()
                    ->where([
                        'ApProfileExits.ap_profile_id' => $ap->ap_profile_id,
                        'ApProfileExits.collect_network_stats' => true
                    ])
                    ->contain(['ApProfileExitApProfileEntries.ApProfileEntries'])
                    ->orderBy('vlan') //Order by VLAN
                    ->all();
                foreach($interfaces as $interface){                

                    $exit_entries = [];
                    foreach($interface->ap_profile_exit_ap_profile_entries as $ap_e_ent){
                        if($ap_e_ent->ap_profile_entry_id > 0){
                            array_push($exit_entries, ['name' => $ap_e_ent->ap_profile_entry->name]);
                        }
                        if($ap_e_ent->ap_profile_entry_id == 0){
                            array_push($exit_entries, ['name' => 'LAN (If Hardware Supports It)']);
                        } 
                        //OCT 2022
                        if(preg_match('/^-9/',$ap_e_ent->ap_profile_entry_id)){ 	
                        	$dynamic_vlan = $ap_e_ent->ap_profile_entry_id;
                        	$dynamic_vlan = str_replace("-9","",$dynamic_vlan);
                        	array_push($exit_entries, ['name' => "Dynamic VLAN $dynamic_vlan"]);                      
                        }
                    }

                    $items[] = [
                        'id'            => $interface->id,
                        'ap_profile_id' => $interface->ap_profile_id,
                        'type'          => $interface->type,
                        'vlan'          => intval($interface->vlan),
                        'connects_with' => $exit_entries
                    ];
                 
                }
            }        
        }                        
        $this->set([
            'items'     => $items,
            'success'   => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }
    
    public function index(){
    
        $user = $this->Aa->user_for_token($this);
        if (!$user) {   //If not a valid user
            return;
        }
        
        //set the time_zone ; span and base_search      
        $this->_setTimeZone();
        $this->span         = $this->request->getQuery('span');      
        $this->base_search  = $this->_formulate_base_search();
            
        $data               = [];
        
        //---- GRAPHS ----- 
        $ft_now = DateTime::now();

        
               
        if($this->span == 'hour'){
            $ft_start       = $ft_now->subHours(1);
        }
        if($this->span == 'day'){
            $ft_start    = $ft_now->subHours(24);
        }
        if($this->span == 'week'){
            $ft_start    = $ft_now->subHours((24*7));
        }
        
        //--- Data over time graph ---
        $graph      = $this->_buildGraph($ft_start, $ft_now);
        
        //---- TOP Traffic -----        
        $top_traffic= $this->_getTopTraffic($ft_start, $ft_now);
        
        //---- TOP Protocols -----        
        $top_protocol  = $this->_getTopProtocol($ft_start, $ft_now);
        
        //---- Summary DATA ----
        $summary    = $this->_getSummary($ft_start, $ft_now);
         
        $data['graph']       = $graph;           
        $data['top_traffic'] = $top_traffic;
        $data['top_protocol'] = $top_protocol;
        $data['summary']     = $summary;
                 
        $this->set([
            'data'      => $data,
            'success'   => true
        ]);
        $this->viewBuilder()->setOption('serialize', true); 
    }
       
    private function _getSummary($ft_start,$ft_now){
          
        $data               = $this->_getTotals($ft_start,$ft_now);   
        $data->date         =  $ft_now->setTimezone($this->time_zone)->format('D, d M Y');
        $data->time         =  $ft_now->setTimezone($this->time_zone)->i18nFormat('HH:mm');
        $data->timespan     =  ucfirst($this->span);
        $data->client_count = $this->client_count;
        $data->proto_count  = $this->proto_count;
        $data->graph_item   = $this->graph_item;
        $data->graph_name   = $this->graph_name;     
        return $data;  
    }
    
    private function _getTotals($ft_start,$ft_end){    
        $where  = $this->base_search;       
        if(($this->graph_item == 'ap')||($this->graph_item == 'ap_device')||($this->graph_item == 'ap_protocol')){
            $table = 'NlbwApStats';
        }     
        array_push($where, ["created >=" => $ft_start]);
        array_push($where, ["created <=" => $ft_end]);      
        $totals  = $this->{$table}->find()->select($this->fields)->where($where)->first();
        return $totals;   
    }
      
    private function _getTopTraffic($ft_start,$ft_end){
         
        $top        = [];
        $limit      = 100000;
        $where      = $this->base_search_no_mac;
        
       // if(($this->mac)||($this->protocol)){
        if($this->protocol){
            $where      = $this->base_search;
        }
        
        //print_r($where);
        
        $table      = 'NlbwApStats'; //By default use this table
        
        $req_q      = $this->request->getQuery();    
       	$cloud_id   = $req_q['cloud_id'];
       	$mesh_id    = $this->request->getQuery('mesh_id');
       	$ap_profile_id  = false;
        
        if(($this->graph_item == 'ap')||($this->graph_item == 'ap_device')){
            $table = 'NlbwApStats';
        }

        array_push($where, ["$table.created >=" => $ft_start]);
        array_push($where, ["$table.created <=" => $ft_end]);
        
        $fields = [
            'mac_address_id',
            'ip',
            'mac'           => 'MacAddresses.mac',
            'data_in'       => 'sum(tx_bytes)',
            'data_out'      => 'sum(rx_bytes)',
            'data_total'    => 'sum(tx_bytes) + sum(rx_bytes)'      
        ];
        
        $data = [];
        
        $clients_q = $this->{$table}->find()->select($fields)
            ->where($where)
            ->orderBy(['data_total' => 'DESC'])
            ->groupBy(['mac_address_id', 'MacAddresses.mac'])
            ->contain(['MacAddresses'])
            ->limit($limit);
                    
        $clients            = $clients_q->all();
        $this->client_count = $clients_q->count();
        
        if($this->mac){
            $this->client_count = '----';
        }
        
        if($this->protocol_mac_id){
            $this->client_count  = $this->_findMacAddress($this->protocol_mac_id);
        }   
        
        foreach($clients as $client){
            $alias_name    = $this->_findAlias($client->mac_address_id);
            $client->alias = $alias_name;      
            $data[] = $client;
      
        }
        return $data;  
    }
    
    private function _getTopProtocol($ft_start,$ft_end){
         
        $top        = [];
        $where      = $this->base_search_no_mac;
        
        if($this->mac){
            $where      = $this->base_search;
        }
        
        $table      = 'NlbwApStats'; //By default use this table
                
        if(($this->graph_item == 'ap')||($this->graph_item == 'ap_device')||($this->graph_item == 'ap_protocol')){
            $table = 'NlbwApStats';
        }

        array_push($where, ["$table.created >=" => $ft_start]);
        array_push($where, ["$table.created <=" => $ft_end]);
        
        $fields = [
            'layer7',
            'family',
            'proto',
            'port',
            'data_in'       => 'sum(tx_bytes)',
            'data_out'      => 'sum(rx_bytes)',
            'data_total'    => 'sum(tx_bytes) + sum(rx_bytes)'      
        ];
        
        $data = [];
              
        $protocols_q = $this->{$table}->find()->select($fields)
            ->where($where)
            ->orderBy(['data_total' => 'DESC'])
            ->groupBy(['layer7']);
      
        $protocols          = $protocols_q->all();
        $this->proto_count  = $protocols_q->count();
        
        if($this->protocol){
            $this->proto_count = '----';
        }  
        
        if($this->mac_protocol){
            $this->proto_count  = $this->mac_protocol;
        }
        
        return $protocols;  
    }
        
           
    private function _buildGraph($slot_start, $ft_now){

        $items       = [];
        $id          = 1;
        $base_search = $this->base_search;
        $table       = 'NlbwApStats';
        
        $dispTz      = $this->time_zone; // e.g. 'Africa/Johannesburg'

        while ($slot_start < $ft_now) {
            // Decide step + label format
            if ($this->span === 'hour') {
                $slot_next = $slot_start->addMinutes(10);
                $labelFmt  = "E\nHH:mm";
            } elseif ($this->span === 'day') {
                $slot_next = $slot_start->addHours(1);
                $labelFmt  = "E\nHH:mm";
            } elseif ($this->span == 'week') { // week         
                $slot_next = $slot_start->addDays(1);
                $labelFmt  = "dd E\nHH:mm";
            }

            // Labels in display TZ
            $label = $slot_start->setTimezone($dispTz)->i18nFormat($labelFmt, $dispTz);

            // Half-open range [start, next) in DB TZ
            $where = $base_search + [
                'created >=' => $slot_start,
                'created <'  => $slot_next,
            ];
            
            $row = $this->{$table}->find()
                ->select($this->fields) // with COALESCE(...) as you have
                ->where($where)
                ->first();

            // Always push a record; fill zeros if none
            $items[] = [
                'id'            => $id,
                'time_unit'     => $label,
                'data_in'       => $row->data_in       ?? 0,
                'data_out'      => $row->data_out      ?? 0,
                'data_total'    => $row->data_total    ?? 0,
                'packets_in'    => $row->packets_in    ?? 0, // <-- fixed key
                'packets_out'   => $row->packets_out   ?? 0,
                'packets_total' => $row->packets_total ?? 0,
                'slot_start_txt'=> $slot_start->setTimezone($dispTz)->i18nFormat('yyyy-MM-dd HH:mm:ss', $dispTz),
            ];

            // Advance to next bin
            $slot_start = $slot_next;
            $id++;
        }
        
        return ['items' => $items];
    }
    
   
    public function apUsageForSsid(){
    
        //Try to determine the timezone if it might have been set ....       
        $this->_setTimeZone();
        $span       = $this->request->getQuery('span');
        $this->span = $span;  
        
        $this->Aps      = $this->fetchTable('Aps');
        $ap_id          = $this->request->getQuery('ap_id');
        $mac            = $this->request->getQuery('mac');
        $mac_address_id = $this->_findMacAddressId($mac);
        $ap_entry_id    = $this->request->getQuery('ap_entry_id');
        
        //FOR APdesk we add the AP as a start 
        $where_clause   = ['ap_id' =>$ap_id];
        
        $this->graph_item = 'ap';
           
        $q_ap  = $this->{'Aps'}->find()
            ->where(['Aps.id' => $ap_id])
            ->contain(['ApProfiles' => 'ApProfileEntries'])->first();    
        if($q_ap){
        	$this->ap_profile_id = $q_ap->ap_profile_id;      
            $ap_profile_entries_list = [];
            foreach($q_ap->ap_profile->ap_profile_entries as $e){
                if($ap_entry_id == -1){ //Everyone
                    $this->ssid = "** ALL SSIDs **";
                    array_push($ap_profile_entries_list,['ap_profile_entry_id' =>$e->id]);
                }else{
                    if($ap_entry_id == $e->id){ //Only the selected one 
                        $this->ssid = $e->name;
                        array_push($ap_profile_entries_list,['ap_profile_entry_id' =>$e->id]);
                        break;
                    }  
                }     
            }
            array_push($where_clause,['OR' => $ap_profile_entries_list]);
            $this->base_search_no_mac = $this->base_search = $where_clause;
            
            //IS this for a device
            if($mac !=='false'){
                $this->graph_item   = 'ap_device';
                //$this->mac          = $mac;
                $this->mac_address_id   = $mac_address_id;
                array_push($where_clause,['mac_address_id' =>$mac_address_id]);
            }       
        }
        $this->base_search = $where_clause;
        
        //print_r($this->base_search); 
        
        //---- GRAPHS ----- 
        $ft_now = DateTime::now();
        $graph_items = []; 
        if($span == 'hour'){
            $graph_items    = $this->_getHourlyGraph($ft_now);
            $ft_start       = $ft_now->subHours(1);
        }
        if($span == 'day'){
            $graph_items = $this->_getDailyGraph($ft_now);
            $ft_start    = $ft_now->subHours(24);
        }
        if($span == 'week'){
            $graph_items = $this->_getWeeklyGraph($ft_now);
            $ft_start    = $ft_now->subHours((24*7));
        }
        
        //---- TOP TEN -----
        $top_ten    = $this->_getTopTen($ft_start,$ft_now);
        
        //---- TOTAL DATA ----
        $totals     = $this->_getTotals($ft_start,$ft_now);
        
        $data               = [];
        $data['graph']      = $graph_items;              
        $data['top_ten']    = $top_ten;
        $data['totals']     = $totals;
        
        if($this->graph_item == 'ap_device'){ 
            $data['device_info'] = $this->_device_info();
        }

        $this->set([
            'data'          => $data,
            'success'       => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);    
    }
      
    private function _setTimezone(){ 
        //New way of doing things by including the timezone_id
        if($this->request->getQuery('timezone_id') != null){
            $tz_id = $this->request->getQuery('timezone_id');
            $ent = $this->{'Timezones'}->find()->where(['Timezones.id' => $tz_id])->first();
            if($ent){
                $this->time_zone = $ent->name;
            }
        }
    }
    
    private function _getFields($q){
        
        $fields     = [
            'requests_acct' => $q->newExpr(
                "SUM(CASE WHEN srvidentifier = 'radiator-radius_acct' THEN requests ELSE 0 END)"
            ),
            'requests_coa' => $q->newExpr(
                "SUM(CASE WHEN srvidentifier = 'radiator-radius_proxy_coa' THEN requests ELSE 0 END)"
            ),
            'requests_auth' => $q->newExpr(
                "SUM(CASE WHEN srvidentifier = 'radiator-radius_auth' THEN requests ELSE 0 END)"
            ),
            // Optional: per-bucket average response time
            'avg_rtt_acct' => $q->newExpr(
                "AVG(CASE WHEN srvidentifier = 'radiator-radius_acct' THEN responsetime END)"
            ),
            'avg_rtt_coa' => $q->newExpr(
                "AVG(CASE WHEN srvidentifier = 'radiator-radius_proxy_coa' THEN responsetime END)"
            ),
            'avg_rtt_auth' => $q->newExpr(
                "AVG(CASE WHEN srvidentifier = 'radiator-radius_auth' THEN responsetime END)"
            ),
        ];        
        return $fields;           
    }
    
    private function _formulate_base_search(){

        $dev_mode   = $this->request->getQuery('dev_mode');
        $dev_id     = $this->request->getQuery('dev_id');
        $exit_id    = $this->request->getQuery('exit_id');
        
        $mac        = $this->request->getQuery('mac');
        $protocol   = $this->request->getQuery('protocol');
        

        $mac_protocol       = $this->request->getQuery('mac_protocol');        
        $protocol_mac_id    = $this->request->getQuery('protocol_mac_id'); 
        
        $where_clause = [];

        if($dev_mode == 'ap'){
       
              
            $where_clause[]   = ['ap_id' => $dev_id];
            $ap = $this->Aps->find()
                ->where(['Aps.id' => $dev_id])
                ->contain(['ApProfiles' => 'ApProfileExits'])->first();  
            if($ap){
            	$this->ap_profile_id = $ap->ap_profile_id;      
                $exits_list = [];
                foreach($ap->ap_profile->ap_profile_exits as $e){
                    if($exit_id == -1){ //Everyone
                        $this->graph_name = "All Where Enabled";
                        if($e->collect_network_stats){
                            array_push($exits_list,['exit_id' =>$e->id]);
                        }
                    }else{
                        if(($e->id == $exit_id)&&($e->collect_network_stats)){ 
                        
                            if($e->vlan > 0){
                                $this->graph_name = "VLAN ".$e->vlan." (".strtoupper($e->type).")";
                            }else{
                                $this->graph_name = strtoupper($e->type);
                            }                                                        
                            array_push($exits_list,['exit_id' =>$e->id]);
                            break;
                        }  
                    }     
                }
                $where_clause[] = ['OR' => $exits_list];
                
                //---- NO PROTOCOL AND NO MAC FILTER ----
                $this->base_search_no_mac = $this->base_search_no_protocol =  $where_clause;
                
                $this->graph_item   = 'ap';
                              
                //IS this for a device
                if($mac !=='false'){
                    $this->graph_item   = 'ap_device';
                    $this->graph_name   = $mac; //FIXME LATER check for alias / name
                    $this->mac          = $mac;
                    $this->mac_address_id   = $this->request->getQuery('mac_address_id');
                    array_push($where_clause,['mac_address_id' => $this->mac_address_id]);
                    
                    //Also allow for mac protocol
                    if($mac_protocol !== 'false'){                    
                        array_push($where_clause,['layer7' => $mac_protocol]);
                        $this->mac_protocol = $mac_protocol;
                    }                                       
                }
                
                //IS this for a protocol
                if($protocol !== 'false'){
                
                    $this->graph_item   = 'ap_protocol';
                    $this->graph_name   = $protocol;
                    $this->protocol     = $protocol;
                    array_push($where_clause,['layer7' => $protocol]);
                    
                    //Also allow for protocol mac_id
                    if($protocol_mac_id !== 'false'){                    
                        array_push($where_clause,['mac_address_id' => $protocol_mac_id]);
                        $this->protocol_mac_id = $protocol_mac_id;
                    }                                       
                }                
                            
            }
        }  
        return $where_clause;
    }
    
    private function _findMacAddressId($mac){ 
        $macAddress = $this->MacAddresses->find()->where(['MacAddresses.mac' => $mac])->first();
        if($macAddress){
            return $macAddress->id;
        }    
    }
    
    private function _findMacAddress($mac_id){ 
        $mac = $this->MacAddresses->find()->where(['MacAddresses.id' => $mac_id])->first();
        if($mac){
            return $mac->mac;
        }    
    }
    
    private function _findAlias($mac_address_id){
    
    	$req_q    = $this->request->getQuery();    
       	$cloud_id = $req_q['cloud_id'];
      
        $alias = false;
        $qr = $this->{'MacAliases'}->find()->where(['MacAliases.mac_address_id' => $mac_address_id,'MacAliases.cloud_id'=> $cloud_id])->first();
        if($qr){
        	$alias = $qr->alias;
        } 
        return $alias;
    }
       
}

?>
