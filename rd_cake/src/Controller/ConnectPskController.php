<?php
/**
 * Created by G-edit.
 * User: dirkvanderwalt
 * Date: 25/SEP/2025
 * Time: 00:01
 */
 
/** 
 * Edited by G-edit.
 * User: dirkvanderwalt
 * Date: 08-AUG-2026
 * Time: 00:00
 */
  
namespace App\Controller;

use Cake\Auth\DefaultPasswordHasher;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Utility\Text;

use Cake\I18n\DateTime;

use App\Model\Table\NlbwApStatsTable;
use App\Model\Table\TimezonesTable;
use App\Model\Table\ApsTable;
use App\Model\Table\NodesTable;
use App\Model\Table\MacAddressesTable;
use App\Model\Table\MacAliasesTable;
use App\Model\Table\PermanentUsersTable;
use App\Model\Table\DynamicDetailsTable;
use App\Model\Table\ApProfileEntriesTable;
use App\Model\Table\ApStationsTable;
use App\Model\Table\ApStationHourliesTable;

class ConnectPskController extends AppController {

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
    
    protected $fields = [
        'data_in'       => 'COALESCE(SUM(tx_bytes), 0)',
        'data_out'      => 'COALESCE(SUM(rx_bytes), 0)',
        'data_total'    => 'COALESCE(SUM(tx_bytes), 0) + COALESCE(SUM(rx_bytes), 0)',
        'packets_in'    => 'COALESCE(SUM(tx_pkts), 0)',
        'packets_out'   => 'COALESCE(SUM(rx_pkts), 0)',
        'packets_total' => 'COALESCE(SUM(tx_pkts), 0) + COALESCE(SUM(rx_pkts), 0)',
    ];
    
    protected $wifiFields   = [
        'tx_bytes'      => 'SUM(tx_bytes)',
        'rx_bytes'      => 'SUM(rx_bytes)',
        'signal_avg'    => 'AVG(signal_avg)'
    ];
    
    protected NlbwApStatsTable $NlbwApStats;
    protected TimezonesTable $Timezones;
    protected ApsTable $Aps;
    protected NodesTable $Nodes;
    protected MacAddressesTable $MacAddresses;
    protected MacAliasesTable $MacAliases;
    protected PermanentUsersTable $PermanentUsers;
    protected DynamicDetailsTable $DynamicDetails;
    protected ApProfileEntriesTable $ApProfileEntries;
    protected ApStationsTable $ApStations;
    protected ApStationHourliesTable $ApStationHourlies;
	
    public function initialize():void{
        parent::initialize();
        
        $this->NlbwApStats          = $this->fetchTable('NlbwApStats');
        $this->Timezones            = $this->fetchTable('Timezones');
        $this->Aps                  = $this->fetchTable('Aps');
        $this->Nodes                = $this->fetchTable('Nodes');
        $this->MacAddresses         = $this->fetchTable('MacAddresses');
        $this->MacAliases           = $this->fetchTable('MacAliases');
        $this->PermanentUsers       = $this->fetchTable('PermanentUsers');
        $this->DynamicDetails       = $this->fetchTable('DynamicDetails');
        $this->ApProfileEntries     = $this->fetchTable('ApProfileEntries');
        $this->ApStations           = $this->fetchTable('ApStations');
        $this->ApStationHourlies    = $this->fetchTable('ApStationHourlies');
                         
        $this->loadComponent('Aa');
        $this->loadComponent('TimeCalculations');
        $this->loadComponent('JsonErrors');
        $this->loadComponent('MacVendors');
        $this->loadComponent('ConnectAa');          
        $this->Authentication->allowUnauthenticated([
            'detail',
            'traffic',
            'data',
            'changePpsk',
            'editMacAlias',
            'connectInfoMac'
        ]);                  
    }
                    
    public function detail(){
        $data = $this->request->getQuery(); 
        
        if(isset($data['token'])){        
            $data = $this->_getDetail($data['token']);
        } 
             	
      	$this->set([
            'data' 	    => $data,
            'success' 	=> true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    } 
    
    public function traffic(){
    
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
    
    public function data(){  
        $data = $this->request->getQuery();      	
      	$this->set([
            'data' 	    => $data,
            'success' 	=> true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }
    
    public function changePpsk(){
    
        $data = [];
        if ($this->request->is('post')) {         
        	$req_d	= $this->request->getData();
        	$token  = $req_d['token'];
        	$pu     = $this->PermanentUsers->find()
                        ->where(['token' => $token])
                        ->first();
            if($pu){
            
                $ppsk = $req_d['ppsk'];
                $realm_id = $pu->realm_id;
                        
                $this->PermanentUsers->patchEntity($pu, ['ppsk' => $ppsk, 'realm_id' => $realm_id]);             
                if ($this->PermanentUsers->save($pu)) {
                    $this->set([
                        'success' => true
                    ]);
                    $this->viewBuilder()->setOption('serialize', true);
                } else {
                    $message = __('Could not update item');
                    $this->JsonErrors->entityErros($pu,$message);
                }
            }else{
                 $this->set([
                    'success' 	=> false
                ]);
                $this->viewBuilder()->setOption('serialize', true);           
            }         	   
        }else{
            $this->set([
                'success' 	=> false
            ]);
            $this->viewBuilder()->setOption('serialize', true);        
        }  
    }
    
    public function editMacAlias(){   
    
        $data = [];
        if ($this->request->is('post')) {         
        	$req_d	= $this->request->getData();
        	$token  = $req_d['token'];
        	$pu     = $this->PermanentUsers->find()
                        ->where(['token' => $token])
                        ->first();
            if($pu){
        
                $cloud_id	= $pu->cloud_id;

                //==== MacAliases.mac ====
                $mac_address_id = $this->_findMacAddressId($this->request->getData('mac'));
                $post_data                      = $this->request->getData();
                $post_data['mac_address_id']    = $mac_address_id;
                $post_data['cloud_id']          = $cloud_id;
                $macAlias  = $this->MacAliases->find()->where(['MacAliases.mac_address_id' => $mac_address_id,'MacAliases.cloud_id' => $cloud_id])->first();          
                if(isset($post_data['remove_alias'])&&($post_data['remove_alias']!== 'null')){

	                $this->{'MacAliases'}->delete($macAlias);
	                $this->set([
	                    'success' => true
	                ]);
	                $this->viewBuilder()->setOption('serialize', true); 
	                return;

                }	
                
                if($macAlias){
                    $this->MacAliases->patchEntity($macAlias, $post_data);
                }else{
                    $macAlias = $this->MacAliases->newEntity($post_data);
                }
                
                if ($this->MacAliases->save($macAlias)) {
                    $this->set(array(
                        'success' => true
                    ));
                    $this->viewBuilder()->setOption('serialize', true); 
                } else {
                    $message = __('Could not update item');
                    $this->JsonErrors->entityErros($entity,$message);
                }                
            }            
        }
    }
    
    public function connectInfoMac(){
    
        $user = $this->ConnectAa->userForToken($this);
        if(!$user){   
            return;
        }
        
        $basic = [];
        
        //If we have the SSID and the vlan and the cloud_id we can look for connection info for the mac
        if($user->ssid && $user->vlan){
            $entries = $this->ApProfileEntries->find()
                        ->where([
                            'ApProfileEntries.name' => $user->ssid,
                            'ApProfiles.cloud_id'   => $user->cloud_id
                        ])
                        ->contain(['ApProfiles'])
                        ->all();
               
            $mac        = $this->request->getQuery('mac'); 
            $mac_id     = $this->_findMacAddressId($mac);                    
            $entry_ids  = [];
                     
            foreach($entries as $entry){            
                $entry_ids[] = $entry->id;
            }
            
            $latestInfo = $this->_getLatestMacInfo($mac_id,$entry_ids);
            $modified 	= $this->_get_span();
            
            $t_bytes = 0;
            $r_bytes = 0;
            $q_t = $this->ApStations->find()
                ->select($this->wifiFields)
                ->where([
                    'mac_address_id'            => $mac_id,
                    'ap_profile_entry_id IN'    => $entry_ids,
                    'modified >='               => $modified
                ])
                ->first();                 
                
            if($q_t->signal_avg){

                $t_bytes    = $t_bytes + $q_t->tx_bytes;
                $r_bytes    = $r_bytes + $q_t->rx_bytes;
                $signal_avg = round($q_t->signal_avg);
                if ($signal_avg < -95) {
                    $signal_avg_bar = 0.01;
                }
                if (($signal_avg >= -95)&($signal_avg <= -35)) {
                        $p_val = 95-(abs($signal_avg));
                        $signal_avg_bar = round($p_val/60, 1);
                }
                if ($signal_avg > -35) {
                    $signal_avg_bar = 1;
                }
            }
            
            if($this->span !== 'hour'){
                $q_t_h = $this->ApStationHourlies->find()
                    ->select($this->wifiFields)
                    ->where([
                        'mac_address_id'            => $mac_id,
                        'ap_profile_entry_id IN'    => $entry_ids,
                        'modified >='               => $modified
                    ])
                    ->first();
                    
                if($q_t_h->signal_avg){
                
                    $t_bytes    = $t_bytes + $q_t_h->tx_bytes;
                    $r_bytes    = $r_bytes + $q_t_h->rx_bytes;
                    //-- Here we use the older ones for average
                    $signal_avg = round($q_t_h->signal_avg);
                    if ($signal_avg < -95) {
                        $signal_avg_bar = 0.01;
                    }
                    if (($signal_avg >= -95)&($signal_avg <= -35)) {
                            $p_val = 95-(abs($signal_avg));
                            $signal_avg_bar = round($p_val/60, 1);
                    }
                    if ($signal_avg > -35) {
                        $signal_avg_bar = 1;
                    }
                    
                }                  
            }
            //-- Now consolidate the data --
            $basic = [
             //   'id'                => $id,
              //  'name'              => $entryName,
              //  'ap_profile_entry_id'=> $apProfileEntryId,
                'mac'               => $mac,
                'mac_address_id'    => $mac_id,
                'vendor'            => $this->MacVendors->vendorFor($mac),
                'tx_bytes'          => $t_bytes,
                'rx_bytes'          => $r_bytes,
                'signal_avg'        => $signal_avg ,
                'signal_avg_bar'    => $signal_avg_bar,
                'ssid'              => $user->ssid,
                'vlan'              => $user->vlan            
            ];
            $basic      = (array_merge($basic,$latestInfo));
        
        }
    
        $this->set([
            'data'    => $basic,
            'success' => true
        ]);
        $this->viewBuilder()->setOption('serialize', true); 
      
    }
    
    private function _getDetail($token){
    
        $data = [];
        
        $pu = $this->PermanentUsers->find()
            ->where(['token'    => $token])
            ->contain(['Realms' => 'RealmSsids','RealmVlans'])
            ->first();
    
        if($pu){
            
            if(strlen($pu->ppsk)>=8){
                $data['ppsk'] = $pu->ppsk;
            }   
            
            $data['qr_available'] = false;
            if($pu->real_realm){
                $first_ssid = array_shift($pu->real_realm->realm_ssids);
                if($first_ssid){
                    $data['qr_available']   = true; 
                    $data['ssid']           = $first_ssid->name; 
                    $data['qr_value']       = "WIFI:T:WPA;S:".$data['ssid'].";P:".$data['ppsk'].";;";          
                }          
            }
            
            if($pu->realm_vlan){ 
                $data['vlan']       = $pu->realm_vlan->vlan;   
            }     
        }        
        return $data;
    
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
        
        if(($this->mac)||($this->protocol)){
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
            $client->alias = '';
            $alias_name = $this->_findAlias($client->mac_address_id);
            if($alias_name){
                $client->alias  = $alias_name;
            }
            $vendor         = $this->MacVendors->vendorFor($client->mac); 
            $client->vendor = $vendor;       
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
                            $this->graph_name = $e->type;
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
    
    private function _getLatestMacInfo($macAddressId,$entries_list){
      
         //Get the latest entry
        $lastCreated = $this->ApStations->find()->where([
                'mac_address_id' => $macAddressId,
                'ap_profile_entry_id IN' => $entries_list
            ])
            ->contain(['Aps'])
            ->orderBy(['ApStations.created' => 'desc'])
            ->first();

        $historical = false;          
        if(!$lastCreated){
            $historical = true;
            $lastCreated = $this->ApStationHourlies->find()->where([
                'mac_address_id'    => $macAddressId,
                'ap_profile_entry_id IN' => $entries_list
            ])
            ->contain(['Aps'])
            ->orderBy(['ApStationHourlies.created'   => 'desc'])
            ->first();
            
            if(!$lastCreated){
                return [];
            }   
        }
                      
        if($historical){
            $signal = $lastCreated->signal_avg;           
        }else{
            $signal = $lastCreated->signal_now;
        }      

        if ($signal < -95) {
            $signal_bar = 0.01;
        }
        if (($signal >= -95)&($signal <= -35)) {
                $p_val = 95-(abs($signal));
                $signal_bar = round($p_val/60, 1);
        }
        if ($signal > -35) {
            $signal_bar = 1;
        }
          
        return [
            'signal_bar'        => $signal_bar,
            'signal'            => $signal,
            'frequency_band'    => $lastCreated->frequency_band,
            'ap'                => $lastCreated->ap->name,
            'l_tx_bitrate'      => $lastCreated->tx_bitrate,
            'l_rx_bitrate'      => $lastCreated->rx_bitrate,
            'l_signal'          => $lastCreated->signal_now,
            'l_signal_avg'      => $lastCreated->signal_avg,
            'l_tx_failed'       => $lastCreated->tx_failed,
            'l_tx_retries'      => $lastCreated->tx_retries,
            'l_modified'        => $lastCreated->modified,
            'l_modified_human'  => $this->TimeCalculations->time_elapsed_string($lastCreated->modified),
            'l_tx_bytes'        => $lastCreated->tx_bytes,
            'l_rx_bytes'        => $lastCreated->rx_bytes    
        ];    
    }
    
    private function _get_span(){

		$hour   = (60*60);
        $day    = $hour*24;
        $week   = $day*7;

        $modified = date('Y-m-d H:i:s', time());

		$timespan = 'hour';  //Default
        if(null !== $this->request->getQuery('span')){
            $timespan = $this->request->getQuery('span');
        }

        if($timespan == 'hour'){
            //Get entries created modified during the past hour
            $modified = date("Y-m-d H:i:s", time()-$hour);
        }

        if($timespan == 'day'){
            //Get entries created modified during the past day
            $modified = date("Y-m-d H:i:s", time()-$day);
        }

        if($timespan == 'week'){
            //Get entries created modified during the past week
            $modified = date("Y-m-d H:i:s", time()-$week);
        }
        $this->span = $timespan;
		return $modified;
	} 
         
}
