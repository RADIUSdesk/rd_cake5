<?php
/** 
 * Edited by G-edit.
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

use App\Model\Table\WireguardServersTable;
use App\Model\Table\WireguardInstancesTable;
use App\Model\Table\WireguardPeersTable;
use App\Model\Table\WireguardArrivalsTable;

class WireguardServersController extends AppController{

    protected $main_model   = 'WireguardServers';
    protected  $fields  	= [
        'sessions'  => 'sum(WireguardStats.sessions_active)',
    ];
    protected   $deadAfter = 600; //600 seconds
    
    protected WireguardServersTable $WireguardServers;
    protected WireguardInstancesTable $WireguardInstances;
    protected WireguardPeersTable $WireguardPeers;
    protected WireguardArrivalsTable $WireguardArrivals;
    
    public function initialize():void{  
        parent::initialize();

        $this->WireguardServers      = $this->fetchTable('WireguardServers');
        $this->WireguardInstances    = $this->fetchTable('WireguardInstances');
        $this->WireguardPeers        = $this->fetchTable('WireguardPeers');
        $this->WireguardArrivals     = $this->fetchTable('WireguardArrivals');
    
        $this->loadComponent('Aa');
        $this->loadComponent('GridButtonsFlat');
        $this->loadComponent('CommonQueryFlat', [ //Very important to specify the Model
            'model' => 'WireguardServers'
        ]);        
         $this->loadComponent('JsonErrors'); 
         $this->loadComponent('TimeCalculations');
         $this->loadComponent('Unknowns');
         $this->Authentication->allowUnauthenticated(['getConfigForServer','submitReport']);          
    }
    
    public function getConfigForServer(){ 
    
        $req_q    = $this->request->getQuery(); //q_data is the query data   
        if(isset($req_q['mac'])){
            $mac       = $this->request->getQuery('mac');
            $ent_srv   = $this->{$this->main_model}->find()->where([$this->main_model.'.mac' => $mac])->contain(['WireguardInstances' => 'WireguardPeers'])->first();
            if($ent_srv){
                
                $config = $this->_return_config($ent_srv);          
                $this->_update_fetched_info($ent_srv);              
                
                $this->set([
                    'data'      => $config,
                    'success'   => true
                ]);
                $this->viewBuilder()->setOption('serialize', true);
                  
            }else{
                $this->Unknowns->RecordUnknownWireguard();
            }   
                      
        }else{
            $this->JsonErrors->errorMessage("MAC Address of server not specified",'error');
        }
    }
    
    public function submitReport(){ 
    
        $req_d = $this->request->getData();
        
        $reply_data = [];
              
        if(isset($req_d['mac'])){
            $mac = $req_d['mac'];            
            $w_s = $this->WireguardServers->find()->where(['WireguardServers.mac' => $mac])->first();       
            if($w_s){                                        
                $w_s->last_contact = DateTime::now();
                $w_s->last_contact_from_ip = $this->request->clientIp();
                
                //See if the restart_service_flag is set and clear it
                if($w_s->restart_flag){
                    $reply_data['restart_service'] = true;
                    $w_s->restart_flag = false;         
                }                
                $this->WireguardServers->save($w_s);                  
            }                       
        }
                    
        if(isset($req_d['info'])){
            foreach(array_keys($req_d['info']) as $instance){
                $i_public = $req_d['info'][$instance]['public_key'];
                $wg_i = $this->WireguardInstances->find()->where(['WireguardInstances.public_key' => $i_public])->first();
                if($wg_i){
                    foreach($req_d['info'][$instance]['peers'] as $peer){
                        $wg_p = $this->WireguardPeers->find()->where(['WireguardPeers.public_key' => $peer['public_key']])->first();
                        if($wg_p){
                            if($peer['latest_handshake']>0){
                                $dateTime = DateTime::createFromTimestamp($peer['latest_handshake']);
                                $wg_p->last_handshake_ts = $dateTime;
                            }
                            $wg_p->tx_bytes = $peer['tx_bytes'];
                            $wg_p->rx_bytes = $peer['rx_bytes'];
                            $this->WireguardPeers->save($wg_p);
                        }                   
                    }                   
                }            
            }               
        }
                              
        $this->set([
            'success'   => true,
            'data'      => $reply_data
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }
   
	public function index(){
	
		$user = $this->_ap_right_check();
        if (!$user) {
            return;
        }
        
        $dead_after = $this->deadAfter;
    
    	$req_q    = $this->request->getQuery(); //q_data is the query data
        $cloud_id = $req_q['cloud_id'];
        $query 	  = $this->{$this->main_model}->find()->contain(['WireguardStats','WireguardInstances']);      
        $this->CommonQueryFlat->build_cloud_query($query,$cloud_id);
        
        $ft_fresh = DateTime::now();
        $ft_fresh = $ft_fresh->subSeconds($this->deadAfter);//Below 10 minutes is fresh
        
        
        if((isset($req_q['only_online']))&&($req_q['only_online'] =='true')){
            $query->where(['WireguardServers.last_contact >=' => $ft_fresh ]);
        }
        
        
        $limit  = 50;   //Defaults
        $page   = 1;
        $offset = 0;
        if(isset($req_q['limit'])){
            $limit  = $req_q['limit'];
            $page   = $req_q['page'];
            $offset = $req_q['start'];
        }
       
           
        $query->page($page);
        $query->limit($limit);
        $query->offset($offset);
        
        $total  = $query->count();       
        $q_r    = $query->all();
        $items  = [];

        foreach($q_r as $i){               
			$i->update		= true;
			$i->delete		= true;		
			$i->state		= 'up';

			$i->modified_in_words = $this->TimeCalculations->time_elapsed_string($i->modified);
			$i->created_in_words = $this->TimeCalculations->time_elapsed_string($i->created);
			
			if($i->config_fetched == null){
			    $i->config_state= 'never';
			}else{
			    $i->config_fetched_human = $this->TimeCalculations->time_elapsed_string($i->config_fetched);
                if ($i->config_fetched <= $ft_fresh) {
                    $i->config_state = 'down';
                } else {
                    $i->config_state = 'up';
                }		
			}
			
			if($i->last_contact == null){
			    $i->state= 'never';
			}else{
			    $i->last_contact_human = $this->TimeCalculations->time_elapsed_string($i->last_contact);
                if ($i->last_contact <= $ft_fresh) {
                    $i->state = 'down';
                } else {
                    $i->state = 'up';
                }		
			}
						
			if($i->wireguard_stat){
			    $i->sessions_active = $i->wireguard_stat->sessions_active;
			    $i->uptime = $i->wireguard_stat->uptime;
			    $i->wireguard_stat->core = json_decode($i->wireguard_stat->core);
			    $i->wireguard_stat->sessions = json_decode($i->wireguard_stat->sessions);
			    $i->wireguard_stat->pppoe = json_decode($i->wireguard_stat->pppoe);
			    $i->wireguard_stat->radius1 = json_decode($i->wireguard_stat->radius1);
			    $i->wireguard_stat->radius2 = json_decode($i->wireguard_stat->radius2);		    
			}else{
			    $i->sessions_active = 0;
			    $i->uptime = 0;
			}	
					
            array_push($items,$i);
        }
        
        $t_q    = $query->select($this->fields)->first();
        
        $this->set([
            'items' => $items,
            'success' => true,
            'totalCount' => $total,
            'metaData'		=> [
            	'count'	    => $total,
            	'sessions'  => $t_q->sessions
            ]
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }

    public function add(){
    	$user = $this->_ap_right_check();
        if (!$user) {
            return;
        }
        $this->_addOrEdit('add');   
    }
    
    public function edit(){
    	$user = $this->_ap_right_check();
        if (!$user) {
            return;
        }
        $this->_addOrEdit('edit');      
    }
     
    private function _addOrEdit($type= 'add') {
    
    	$req_d  = $this->request->getData();
         
        if($type == 'add'){ 
            $entity = $this->{$this->main_model}->newEntity($req_d);
        }
       
        if($type == 'edit'){
            $entity = $this->{$this->main_model}->get($this->request->getData('id'));
            $this->{$this->main_model}->patchEntity($entity, $req_d);
        }
              
        if ($this->{$this->main_model}->save($entity)) {
            $this->set([
                'success' 	=> true,
                'data'		=> $entity
            ]);
            $this->viewBuilder()->setOption('serialize', true);
            //Delete (if there are any) WireguardArrivals with that MAC Address
            $this->{'WireguardArrivals'}->deleteAll(['WireguardArrivals.mac' => $entity->mac]);
            
            
        } else {
            $message = 'Error';           
            $errors = $entity->getErrors();
            $a = [];
            foreach(array_keys($errors) as $field){
                $detail_string = '';
                $error_detail =  $errors[$field];
                foreach(array_keys($error_detail) as $error){
                    $detail_string = $detail_string." ".$error_detail[$error];   
                }
                $a[$field] = $detail_string;
            }
            
            $this->set([
                'errors'    => $a,
                'success'   => false,
                'message'   => __('Could not create item'),
            ]);
            $this->viewBuilder()->setOption('serialize', true);
        }
	}
	
	 public function restart($id = null) {
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}
		
		$user = $this->_ap_right_check();
        if (!$user) {
            return;
        }
        		
		$req_d		= $this->request->getData();
			
	    if(isset($req_d['id'])){   //Single item delete       
            $entity     = $this->{$this->main_model}->get($req_d['id']);
            if($entity->restart_flag == 0){
                $entity->restart_flag = 1;
            }else{
                $entity->restart_flag = 0;
            }
            $entity->setDirty('modified', true);
            $this->{$this->main_model}->save($entity);
        }else{
            foreach($req_d as $d){
                $entity     = $this->{$this->main_model}->get($d['id']);  
                if($entity->restart_flag == 0){
                    $entity->restart_flag = 1;
                }else{
                    $entity->restart_flag = 0;
                }
                $entity->setDirty('modified', true);
                $this->{$this->main_model}->save($entity);
            }
        }         
        $this->set([
            'success' => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
	}
	
   	public function delete($id = null) {
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}
		
		$user = $this->_ap_right_check();
        if (!$user) {
            return;
        }
        		
		$req_d		= $this->request->getData();
			
	    if(isset($req_d['id'])){   //Single item delete       
            $entity     = $this->{$this->main_model}->get($req_d['id']);   
            $this->{$this->main_model}->delete($entity);

        }else{
            foreach($req_d as $d){
                $entity     = $this->{$this->main_model}->get($d['id']);  
                $this->{$this->main_model}->delete($entity);
            }
        }         
        $this->set([
            'success' => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
	}
   
    public function menuForGrid(){
    
    	$user = $this->_ap_right_check();
        if(!$user){
            return;
        }
        
        $user = $this->Aa->user_for_token($this);
        if(!$user){   //If not a valid user
            return;
        }
        
        $menu = $this->GridButtonsFlat->returnButtons(false,'wireguardServers');
        $this->set([
            'items'         => $menu,
            'success'       => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }
    
    private function _update_fetched_info($ent_srv){
        //--Update the fetched info--
        $data = [];
		$data['id'] 			        = $ent_srv->id;
		$data['config_fetched']         = DateTime::now();
		$data['last_contact_from_ip']   = $this->getRequest()->clientIp();
        $this->{'WireguardServers'}->patchEntity($ent_srv, $data);
        $this->{'WireguardServers'}->save($ent_srv);      
    }
    
    private function _return_config($ent_srv){
    
        $config     = [];      
        $upstream   = $ent_srv->uplink_interface;        
        $instances  = [];
        
        
        foreach($ent_srv->wireguard_instances as $instance){
            
            $wg_if       = 'wg'.$instance->interface_number;
            $private_key = $instance->private_key;            
            $interface   = [
                'PrivateKey'    => $instance->private_key,
                'ListenPort'    => $instance->listen_port,
                'SaveConfig'    => false,
            ];
            
            //--Address--
            $address     = [];
            if($instance->ipv4_enabled){
                $address[] = $instance->ipv4_address.'/'.$instance->ipv4_mask;
            }
            if($instance->ipv6_enabled){
                $address[] = $instance->ipv6_address.'/'.$instance->ipv6_prefix;
            }
            $interface['Address'] = $address;
            
            
            if($instance->nat_enabled){

                $post_up   = ["ufw route allow in on $wg_if out on $upstream"];
                $post_down = ["ufw route delete allow in on $wg_if out on $upstream"];
                if($instance->ipv4_enabled){
                    $post_up[]   = "iptables  -t nat -I POSTROUTING -o $upstream -j MASQUERADE";
                    $post_down[] = "iptables  -t nat -D POSTROUTING -o $upstream -j MASQUERADE";
                }
                if($instance->ipv6_enabled){
                    $post_up[]   = "ip6tables -t nat -I POSTROUTING -o $upstream -j MASQUERADE";
                    $post_down[] = "ip6tables -t nat -D POSTROUTING -o $upstream -j MASQUERADE";
                }
                if($instance->sqm_enabled){
                    $post_up[]   = "/usr/local/sbin/cake-wg.sh $wg_if start $instance->upload_mb".'mbit '.$instance->download_mb.'mbit';
                    $post_down[] = "/usr/local/sbin/cake-wg.sh $wg_if stop";
                }
                $interface['PostUp']   = $post_up;
                $interface['PostDown'] = $post_down;
            }
            $peers = [];
            foreach($instance->wireguard_peers as $wireguardPeer){
                $peer               = [];
                $peer['PublicKey']  = $wireguardPeer->public_key;
                $allowed_ips = [];
                if($wireguardPeer->ipv4_enabled){
                    $allowed_ips[] = $wireguardPeer->ipv4_address."/32";
                }
                if($wireguardPeer->ipv6_enabled){
                    $allowed_ips[] = $wireguardPeer->ipv6_address."/128";
                } 
                $peer['AllowedIps'] = $allowed_ips;
                $peers[] = $peer;
            }
        
            $instances[] = [
                'Name'      => $wg_if,          
                'Interface' => $interface,
                'Peers'     => $peers
            ];
        
        }
        
        if($instances){
            return $config['wireguardInstances'] = $instances;
        }   
        return $config;   
    }
    
}

?>
