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

use App\Model\Table\WireguardPeersTable;
use App\Model\Table\WireguardInstancesTable;

class WireguardPeersController extends AppController{

    protected $main_model  = 'WireguardPeers';
    
    protected $v4PoolsStart = '10.5.0.0/16';
    protected $v6PoolsStart = 'fd00:12::/64';
    protected $maxIps       = 200;
    
    protected WireguardPeersTable $WireguardPeers;
    protected WireguardInstancesTable $WireguardInstances;
      
    public function initialize():void{  
        parent::initialize();
        $this->WireguardPeers        = $this->fetchTable('WireguardPeers');
        $this->WireguardInstances    = $this->fetchTable('WireguardInstances');
        $this->loadComponent('Aa');
        $this->loadComponent('GridButtonsFlat');         
        $this->loadComponent('JsonErrors'); 
        $this->loadComponent('TimeCalculations');
        $this->loadComponent('SubnetPlanner');
        $this->loadComponent('Formatter');
        $this->Authentication->allowUnauthenticated(['gooiHom']);              
    }
         
	public function index(){
	
		$user = $this->_ap_right_check();
        if (!$user) {
            return;
        }
        
    	$req_q          = $this->request->getQuery(); //q_data is the query data
        $instance_id    = $req_q['instance_id'];
        $query 	        = $this->{$this->main_model}->find()
                            ->where(['WireguardPeers.wireguard_instance_id' => $instance_id])
                            ->contain(['WireguardInstances']);      
                   
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
			$cut_off        = DateTime::now()->subMinutes(15);		
			if($i->last_handshake_ts < $cut_off){		
			   $i->state		= 'down';
			}
			if($i->last_handshake_ts > $cut_off){		
			   $i->state		= 'up';
			}
			if($i->last_handshake_ts == null){
			    $i->state		= 'never';
			}
			
			$i->modified_in_words= $this->TimeCalculations->time_elapsed_string($i->modified);
			$i->created_in_words = $this->TimeCalculations->time_elapsed_string($i->created);
			$i->last_seen        = $this->TimeCalculations->time_elapsed_string($i->last_handshake_ts);
			$i->tx_human         = $this->Formatter->formatted_bytes($i->tx_bytes);
			$i->rx_human         = $this->Formatter->formatted_bytes($i->rx_bytes); 			
            array_push($items,$i);
        }
        
        $this->set([
            'items' => $items,
            'success' => true,
            'totalCount' => $total,
            'metaData'		=> [
            	'count'	    => $total
            ]
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }
   
    public function view(){
        $user = $this->_ap_right_check();
        if (!$user) {
            return;
        }
        
        $req_q    = $this->request->getQuery(); //q_data is the query data     
        $instance = $this->{$this->main_model}->find()
                    ->where(['WireguardInstances.id' => $req_q['id']])
                    ->first();                   
        $data     = [];    
                    
        if($instance){
            $data = $instance;     
        }
        $this->set([
            'success' 	=> true,
            'data'		=> $data
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
    
    	$req_d		= $this->request->getData();	
    	$check_items = [
			'gen_keys',
			'ipv4_enabled',
			'ipv4_next_subnet',
			'ipv6_enabled',
			'ipv6_next_subnet'
		];
		
        foreach($check_items as $i){
            if(isset($req_d[$i])){
            	if($req_d[$i] == 'null'){
                	$req_d[$i] = 0;
                }else{
                	$req_d[$i] = 1;
                }  
            }else{
                $req_d[$i] = 0;
            }
        }
        
        $instance_id = $req_d['wireguard_instance_id'];
                
        //-- Gen Keys if needed --
        if($req_d['gen_keys'] === 1){
            $req_d['private_key'] = '';
            $req_d['prublic_key'] = '';
        }
        
        $gwInstance = $this->WireguardInstances->find()->where(['WireguardInstances.id' => $instance_id])->first();
        
        if($gwInstance->ipv4_enabled){
            $ipv4_start = $gwInstance->ipv4_address;
            $ipv4_mask  = $gwInstance->ipv4_mask;
        }
        
        if($gwInstance->ipv6_enabled){
            $ipv6_start     = $gwInstance->ipv6_address;
            $ipv6_prefix    = $gwInstance->ipv6_prefix;
        }
                    
        //-- Subnet and IP ---
        if($req_d['ipv4_enabled'] === 1){
            $next_ipv4 = $this->_getNextAvailableIp($ipv4_start);
            $req_d['ipv4_address']  = $next_ipv4;
            $req_d['ipv4_mask']     = $ipv4_mask;        
        }
        
        if($req_d['ipv6_enabled'] === 1){
            $next_ipv6 = $this->_getNextAvailableIp($ipv6_start,true);      
            $req_d['ipv6_address']  = $next_ipv6;
            $req_d['ipv6_prefix']   = $ipv6_prefix;        
        }     
                	     
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
	
	
	public function peerConfig(){

        $this->request->allowMethod(['get']);

        $token     = $this->request->getQuery('token');
        $peerId    = $this->request->getQuery('peer_id');
        $cloudId   = $this->request->getQuery('cloud_id');

        $peer     = $this->WireguardPeers
            ->find()
            ->where([
                'WireguardPeers.id' => $peerId
            ])
            ->contain(['WireguardInstances' => ['WireguardServers']])
            ->first();

        if (!$peer) {
            throw new \Cake\Http\Exception\NotFoundException(__('Invalid peer/token'));
        }
        
        $ip_address = '';
        
        if($peer->ipv4_enabled){
            $ip_address = $peer->ipv4_address.'/32';//.$peer->ipv4_mask;
        }
        
        if(strlen($ip_address)>0){
            $ip_address = $ip_address.',';
        }
                
        if($peer->ipv6_enabled){
            $ip_address = $ip_address.$peer->ipv6_address.'/'.$peer->ipv6_prefix;
        }     

        // Example build of config body
        $cfg = "[Interface]
PrivateKey = {$peer->private_key}
Address    = {$ip_address}

[Peer]
PublicKey  = {$peer->wireguard_instance->public_key}
Endpoint   = {$peer->wireguard_instance->wireguard_server->ip_address}:{$peer->wireguard_instance->listen_port}
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = {$peer->persistent_keepalive}
";

        // === 2) Create Text Response ===
        $peer_name  = str_replace(' ', '_', strtolower( $peer->name ) );
        $filename   = "wg_peer_{$peer_name}.conf";
       
        $response = $this->response
            ->withType('text/plain') // correct MIME for wireguard config
            ->withStringBody($cfg);
            //->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
   }

	public function qrcodeConfig(){
	
	    $this->request->allowMethod(['get']);

        $peerId    = $this->request->getQuery('peer_id');
        $cloudId   = $this->request->getQuery('cloud_id');

        $peer     = $this->WireguardPeers
            ->find()
            ->where([
                'WireguardPeers.id' => $peerId
            ])
            ->contain(['WireguardInstances' => ['WireguardServers']])
            ->first();

        if (!$peer) {
            throw new \Cake\Http\Exception\NotFoundException(__('Invalid peer/token'));
        }
        
        $ip_address = '';
        
        if($peer->ipv4_enabled){
            $ip_address = $peer->ipv4_address.'/32';//.$peer->ipv4_mask;
        }
        
        if(strlen($ip_address)>0){
            $ip_address = $ip_address.',';
        }
                
        if($peer->ipv6_enabled){
            $ip_address = $ip_address.$peer->ipv6_address.'/'.$peer->ipv6_prefix;
        }
        
        $cfg = "[Interface]
PrivateKey = {$peer->private_key}
Address    = {$ip_address}

[Peer]
PublicKey  = {$peer->wireguard_instance->public_key}
Endpoint   = {$peer->wireguard_instance->wireguard_server->ip_address}:{$peer->wireguard_instance->listen_port}
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = {$peer->persistent_keepalive}
";     
        
        $data = [
            'config'    => $cfg,
            'metaData'  => [
                'name'  => $peer->name,
                'description' => $peer->description,
            ]
        ];

         $this->set([
            'data'         => $data,
            'success'       => true
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
        $menu = $this->GridButtonsFlat->returnButtons(false,'wireguardPeers');
        $this->set([
            'items'         => $menu,
            'success'       => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }
    
    private function _getNextAvailableIp($ip,$v6=false){
        $next_ip = $ip;
        $counter = 0;
        while($counter <= $this->maxIps){
            $next_ip = $this->SubnetPlanner->nextIp($next_ip);
            $counter++;
            $found_count = 0;
            if($v6){                
                $found_count  = $this->{$this->main_model}->find()->where(['WireguardPeers.ipv6_address' => $next_ip])->count();       
            }else{               
                $found_count  = $this->{$this->main_model}->find()->where(['WireguardPeers.ipv4_address' => $next_ip])->count();       
            }
            if($found_count === 0){ //No trigger
                return $next_ip;
            }
        }      
    }
    
}

?>
