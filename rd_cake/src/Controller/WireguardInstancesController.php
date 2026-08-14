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
use App\Model\Table\WireguardInstancesTable;

class WireguardInstancesController extends AppController{

    protected $main_model  = 'WireguardInstances';
    
    protected $v4PoolsStart = '10.5.0.0/16';
    protected $v6PoolsStart = 'fd00:12::/64';
    protected WireguardInstancesTable $WireguardInstances;
      
    public function initialize():void{  
        parent::initialize();
        $this->WireguardInstances    = $this->fetchTable('WireguardInstances');
        $this->loadComponent('Aa');
        $this->loadComponent('GridButtonsFlat');         
        $this->loadComponent('JsonErrors'); 
        $this->loadComponent('TimeCalculations');
        $this->loadComponent('SubnetPlanner');
        $this->Authentication->allowUnauthenticated(['gooiHom']);              
    }
         
	public function index(){
	
		$user = $this->_ap_right_check();
        if (!$user) {
            return;
        }
        
    	$req_q      = $this->request->getQuery(); //q_data is the query data
        $server_id  = $req_q['server_id'];
        $query 	    = $this->{$this->main_model}->find()
                        ->where(['WireguardInstances.wireguard_server_id' => $server_id])
                        ->contain(['WireguardServers']);      
                   
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
    
    public function gooiHom(){
    
        $freeV4 = $this->SubnetPlanner->nextFreeSubnet(
            serverId: 1,
            family: 'ipv4',
            prefix: 24,
            seedCidr: null,
            poolCidr: $this->v4PoolsStart
        );
        [$ipv4_net,$ipv4_mask] = explode('/',$freeV4);
        $next_ipv4 = $this->SubnetPlanner->nextIp($ipv4_net);
        // e.g. returns "10.12.3.0/24"

        // Example: start scanning from a seed, no pool
        $freeV6 = $this->SubnetPlanner->nextFreeSubnet(
            1, 'ipv6', 64, $this->v6PoolsStart, null
        );
        [$ipv6_net,$ipv6_mask] = explode('/',$freeV6);
        $next_ipv6 = $this->SubnetPlanner->nextIp($ipv6_net);           
        
        $this->set([
            'success' 	=> true,
            'data'		=> ['4' => $freeV4, 'ip_v4' => $next_ipv4, '6' => $freeV6,'ip_v6' => $next_ipv6]
        ]);
        $this->viewBuilder()->setOption('serialize', true);
        
    /*
        // Minimal create: IPv4-only, auto-generate private/public + PSK
        $instance = $this->WireguardInstances->newEntity([
            'wireguard_server_id' => 1,
            'name'                => 'wg4',
            'listen_port'         => 51821,

            // trigger key generation:
            'private_key'         => '',       // -> generates private_key + public_key
            'preshared_key'       => '',       // -> generates preshared_key (optional)

            'ipv4_enabled'        => true,
            'ipv4_address'        => '10.12.0.1',
            'ipv4_mask'           => 24,

            'ipv6_enabled'        => true,
            'ipv6_address'        => 'fd00:12::1',
            'ipv6_prefix'         => 64,

            'nat_enabled'         => true,
            'sqm_enabled'         => true,

            'upload_mb'           => 120,
            'download_mb'         => 340,
        ]);
       
        if ($this->WireguardInstances->save($instance)) {

            $this->set([
                'success' 	=> true,
                'data'		=> $instance
            ]);
            $this->viewBuilder()->setOption('serialize', true);
        } else {
            $message = 'Error';           
            $errors  = $instance->getErrors();
            $a       = [];
            foreach(array_keys($errors) as $field){
                $detail_string = '';
                $error_detail =  $errors[$field];
                foreach(array_keys($error_detail) as $error){
                    $detail_string = $detail_string."".$error_detail[$error];   
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
        */
    	 
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
			'next_port',
			'gen_keys',
			'gen_preshared_key',
			'ipv4_enabled',
			'ipv4_next_subnet',
			'ipv6_enabled',
			'ipv6_next_subnet',
			'nat_enabled',
			'sqm_enabled'
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
        
        $server_id = $req_d['wireguard_server_id'];
         
        //-- Get the Port if needed --    
        if($req_d['next_port'] === 1){
            $req_d['listen_port'] = $this->_getNextListenPort($server_id);        
        }
        
        //-- Gen Keys if needed --
        if($req_d['gen_keys'] === 1){
            $req_d['private_key'] = '';
            $req_d['prublic_key'] = '';
        }
        
        //-- Gen Preshared if needed --
        if($req_d['gen_preshared_key'] === 1){
            $req_d['preshared_key'] = '';
        }
        
        //-- Speed limits if needed --
        if($req_d['sqm_enabled'] === 1){     
            if($req_d['limit_upload_unit'] == 'kbps'){
                $req_d['limit_upload_amount'] = $req_d['limit_upload_amount'] / 1024;    
            }
            if($req_d['limit_download_unit'] == 'kbps'){
                $req_d['limit_download_amount'] = $req_d['limit_download_amount'] / 1024;    
            }
            $req_d['upload_mb']   = $req_d['limit_upload_amount'];
            $req_d['download_mb'] = $req_d['limit_download_amount'];
        }
        
        //-- Subnet and IP ---
        if($req_d['ipv4_enabled'] === 1){
            $freeV4 = $this->SubnetPlanner->nextFreeSubnet(
                serverId: $server_id,
                family: 'ipv4',
                prefix: 24,
                seedCidr: null,
                poolCidr: $this->v4PoolsStart
            );
            [$ipv4_net,$ipv4_mask] = explode('/',$freeV4);
            $next_ipv4 = $this->SubnetPlanner->nextIp($ipv4_net);
            $req_d['ipv4_address']  = $next_ipv4;
            $req_d['ipv4_mask']     = $ipv4_mask;        
        }
        
        if($req_d['ipv6_enabled'] === 1){
            // Example: start scanning from a seed, no pool
            $freeV6 = $this->SubnetPlanner->nextFreeSubnet(
                $server_id, 'ipv6', 64, $this->v6PoolsStart, null
            );
            [$ipv6_net,$ipv6_prefix] = explode('/',$freeV6);
            $next_ipv6 = $this->SubnetPlanner->nextIp($ipv6_net);      
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
   
    public function menuForGrid(){
    
    	$user = $this->_ap_right_check();
        if(!$user){
            return;
        }
        
        $user = $this->Aa->user_for_token($this);
        if(!$user){   //If not a valid user
            return;
        }     
        $menu = $this->GridButtonsFlat->returnButtons(false,'wireguardInstances');
        $this->set([
            'items'         => $menu,
            'success'       => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }
    
    private function _getNextListenPort($server_id){
    
        $listen_port = 51820; //default to start with    
        $lastPort    = $this->{$this->main_model}->find()
            ->where(['WireguardInstances.wireguard_server_id' => $server_id])
            ->orderBy('listen_port DESC')
            ->first();
        if($lastPort){       
            $listen_port = $lastPort->listen_port + 1;
        }        
        return $listen_port;   
    }
    
}

?>
