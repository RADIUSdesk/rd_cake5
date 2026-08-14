<?php
/**
 * Created by G-edit.
 * User: Mbazooka 
 * Date: 03/Oct/2024
 * Time: 00:00
 */
/** 
 * Edited by G-edit.
 * User: dirkvanderwalt
 * Date: 08-AUG-2026
 * Time: 00:00
 */
namespace App\Controller;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

use App\Model\Table\MultiWanProfilesTable;
use App\Model\Table\MwanInterfacesTable;
use App\Model\Table\MwanInterfaceSettingsTable;
use App\Model\Table\UsersTable;

class MultiWanProfilesController extends AppController {

    protected $main_model   = 'MultiWanProfiles';
    
    protected MultiWanProfilesTable $MultiWanProfiles;
    protected MwanInterfacesTable $MwanInterfaces;
    protected MwanInterfaceSettingsTable $MwanInterfaceSettings;
    protected UsersTable $Users;
    
    public function initialize():void{
        parent::initialize();  
        
        $this->MultiWanProfiles        = $this->fetchTable('MultiWanProfiles');
        $this->MwanInterfaces          = $this->fetchTable('MwanInterfaces');
        $this->MwanInterfaceSettings   = $this->fetchTable('MwanInterfaceSettings');
        $this->Users                   = $this->fetchTable('Users');
        
	    $this->loadComponent('CommonQueryFlat', [ //Very important to specify the Model
		    'model'     => 'MultiWanProfiles',
		    'sort_by'   => 'MultiWanProfiles.name'
		]);       
        $this->loadComponent('Aa');
        $this->loadComponent('GridButtonsFlat');    
        $this->loadComponent('JsonErrors');
    }
    
    public function indexCombo(){
        // Authentication + Authorization
        $user = $this->_ap_right_check();
        if (!$user) {
            return;
        }

        $req_q      = $this->request->getQuery();
        $cloud_id   = $req_q['cloud_id'] ?? null;

        $query      = $this->{$this->main_model}->find();
        $this->CommonQueryFlat->cloud_with_system($query, $cloud_id, []);


        $limit      = $req_q['limit'] ?? 50; // Default limit to 50 if not set
        $page       = $req_q['page'] ?? 1;
        $offset     = $req_q['start'] ?? 0;

        $query->page($page)
              ->limit($limit)
              ->offset($offset);

        $total      = $query->count();
        $items      = [];

        // Include all option if requested
        if (!empty($req_q['include_all_option'])) {
             if($req_q['include_all_option'] == true){
		    	$items[] = ['id' => 0, 'name' => '**All Multi-WAN Profiles**'];    
		    }         
        }

        // Fetch results and build items array
        foreach ($query->all() as $i) {
            $items[] = ['id' => $i->id, 'name' => $i->name];
        }

        // Final response
        $this->set([
            'items'      => $items,
            'success'    => true,
            'totalCount' => $total
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }
    
    public function indexDataView(){
        //__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if (!$user) {
            return;
        }

        $req_q    = $this->request->getQuery();      
       	$cloud_id = $req_q['cloud_id'];
        $query 	  = $this->{$this->main_model}->find();      
        $this->CommonQueryFlat->cloud_with_system($query,$cloud_id,['MwanInterfaces' => ['MwanInterfaceSettings','SqmProfiles']]);
        
        if(isset($req_q['id'])){
        	if($req_q['id'] > 0){
        		$query->where(['MultiWanProfiles.id' => $req_q['id']]);
        	}	   
        }

        //===== PAGING (MUST BE LAST) ======
        $limit = 50;   //Defaults
        $page = 1;
        $offset = 0;
        if (isset($req_qy['limit'])) {
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

        foreach ($q_r as $i) {
       
            $row            = [];       
			$row['id']      = $i->id.'_0'; //Signifies Multi-WAN Profile
			$row['name']	= $i->name;
			$row['type']    = 'multi_wan_profile';
			$row['multi_wan_profile_id'] = $i->id;
			$row['multi_wan_profile_name'] = $i->name;
			
			$for_system = false;
            if($i->cloud_id == -1){
            	$for_system = true;
            }
            $row['for_system']  = $for_system;
			
			$active_total = 0;
			$standby_total= 0;
										
			if(count($i->mwan_interfaces) == 0){
			    $row['reminder_flag']       = true;
			    $row['reminder_message']    = 'Reminder: Ensure you add at least one interface to activate this Multi-WAN profile.';
			}else{
			    //Check if at least one interface has polico_active set
			    $policy_active  = false;
                $policy_mode    = 'load_balance';
			    foreach($i->mwan_interfaces as $mwanInterface){
			        if($mwanInterface->policy_active == true){		         
			            $policy_active = true;
			            if($mwanInterface->policy_role == 'standby'){
			                $policy_mode = 'fail_over';
			                $standby_total = $standby_total +  $mwanInterface->policy_ratio;  
			              //  print_r("STANDBY ".$standby_total);
			            }else{
			                $active_total = $active_total +  $mwanInterface->policy_ratio; 
			               // print_r("ACTIVE ".$active_total."HH".$mwanInterface->policy_ratio);
			            }
			        }
			    }
			    if(!$policy_active){
			        $row['reminder_flag']       = true;
			        $row['reminder_message']    = 'Reminder: Ensure you enable at least one interface using the Policy Editor (Botton with chain icon)';
			    }			    
			}
			
			$items[] = $row;
			
			//Now the interfaces
			foreach($i->mwan_interfaces as $mwanInterface){
			    $mwanInterface->con_type = $mwanInterface->type;
			    $mwanInterface->type = 'mwan_interface';
			    $mwanInterface->multi_wan_profile_name = $i->name;
			    $mwanInterface->policy_mode = $policy_mode;
			    if($mwanInterface->policy_role == 'standby'){
			        if($standby_total > 0){
			            $mwanInterface->percent_ratio = round(($mwanInterface->policy_ratio / $standby_total)* 100);
			        }
			    }else{
			        if($active_total > 0){
			            $mwanInterface->percent_ratio = round(($mwanInterface->policy_ratio / $active_total )* 100);
			        }
			    }			    
			    $items[] = $mwanInterface;		
			}
			
							
			$items[] = [ 'id' => '0_'.$i->id, 'type'	=> 'add','name' => 'Multi WAN Connection', 'multi_wan_profile_id' =>  $i->id, 'multi_wan_profile_name' => $i->name, 'for_system' =>  $for_system ];			
        }
        
        //___ FINAL PART ___
        $this->set([
            'items'         => $items,
            'success'       => true,
            'totalCount'    => $total
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }
               
    public function add(){
     
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }
           
        if ($this->request->is('post')) {         
        	$req_d	  = $this->request->getData();       	        	      
        	if($this->request->getData('for_system')){
        		$req_d['cloud_id'] = -1;
		    }
		               
            $entity = $this->{$this->main_model}->newEntity($req_d); 
            if ($this->{$this->main_model}->save($entity)) {
                $this->set([
                    'success' => true
                ]);
                $this->viewBuilder()->setOption('serialize', true);
            } else {
                $message = __('Could not update item');
                $this->JsonErrors->entityErros($entity,$message);
            }    
        }
    }
    
    public function delete() {
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}

        //__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }

        $fail_flag 	= false;
        $req_d 		= $this->request->getData();
        $ap_flag 	= true;		
		if($user['group_name'] == Configure::read('group.admin')){
			$ap_flag = false; //clear if admin
		}

	    if(isset($req_d['id'])){   //Single item delete
            $entity     = $this->{$this->main_model}->get($req_d['id']);
            
            if(($entity->cloud_id == -1)&&($ap_flag == true)){
	    		$this->set([
					'message' 	=> 'Not enough rights for action',
					'success'	=> false
				]);
				$this->viewBuilder()->setOption('serialize', true);
				return;
	    	} 
	    	            
            $this->{$this->main_model}->delete($entity);

        }else{                          //Assume multiple item delete
            foreach($req_d as $d){
                $entity     = $this->{$this->main_model}->get($d['id']);
                
                 if(($entity->cloud_id == -1)&&($ap_flag == true)){
					$this->set([
							'message' 	=> 'Not enough rights for action',
							'success'	=> false
						]);
						$this->viewBuilder()->setOption('serialize', true);
					return;
				}  
                  
                $this->{$this->main_model}->delete($entity);
            }
        }

        if($fail_flag == true){
            $this->set([
                'success'   => false,
                'message'   => __('Could not delete some items'),
            ]);
            $this->viewBuilder()->setOption('serialize', true);
        }else{
            $this->set([
                'success' => true
            ]);
            $this->viewBuilder()->setOption('serialize', true);
        }
	}
    
    public function edit(){
	   
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}

        //__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }
        
        $ap_flag 	= true;	
		if($user['group_name'] == Configure::read('group.admin')){
			$ap_flag = false; //clear if admin
		}
				   
        if ($this->request->is('post')) { 
            $req_d  = $this->request->getData();
                    
		    if($this->request->getData('for_system')){
        		$req_d['cloud_id'] = -1;
		    }
		    		    		    
            $ids            = explode("_", $this->request->getData('id'));  
            $req_d['id']    = $ids[0];
            $entity         = $this->{$this->main_model}->find()->where(['id' => $req_d['id']])->first();
            
            if($entity){
            
            	if($ap_flag && ($entity->cloud_id == -1)){
            		$this->JsonErrors->errorMessage('Not enough rights for action');
					return;          	
            	}                    
                $this->{$this->main_model}->patchEntity($entity, $req_d); 
                if ($this->{$this->main_model}->save($entity)) {
                    $this->set([
                        'success' => true
                    ]);
                    $this->viewBuilder()->setOption('serialize', true);
                } else {
                    $message = __('Could not update item');
                    $this->JsonErrors->entityErros($entity,$message);
                }   
            }
        }
    }
    
    public function interfaceView(){
    
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }
               
        $req_q  = $this->request->getQuery();
        $id     = $req_q['interface_id'];  
        $data   = [];
        $entity = $this->{'MwanInterfaces'}->find()->where(['MwanInterfaces.id' => $id])->contain(['MwanInterfaceSettings'])->first();
        if($entity){ 
        
            $entity->{'method'} = 'dhcp';
            $entity->{'enable_monitor'} = false;
              
            foreach($entity->mwan_interface_settings as $mwanInterfaceSetting){
                if($mwanInterfaceSetting->grouping == 'wbw_setting'){
                    $entity->{'wbw_'.$mwanInterfaceSetting->name} = $mwanInterfaceSetting->value;  
                }
                
                if($mwanInterfaceSetting->grouping == 'qmi_setting'){
                    $entity->{'qmi_'.$mwanInterfaceSetting->name} = $mwanInterfaceSetting->value;  
                }
                
                if($mwanInterfaceSetting->grouping == 'static_setting'){
                    $entity->{'method'} = 'static';
                    $entity->{'static_'.$mwanInterfaceSetting->name} = $mwanInterfaceSetting->value;  
                }
                
                if($mwanInterfaceSetting->grouping == 'pppoe_setting'){
                    $entity->{'method'} = 'pppoe';
                    $entity->{'pppoe_'.$mwanInterfaceSetting->name} = $mwanInterfaceSetting->value;  
                }
                
                if($mwanInterfaceSetting->grouping == 'ethernet_setting'){
                    $entity->{'ethernet_'.$mwanInterfaceSetting->name} = $mwanInterfaceSetting->value;  
                }
                
                if($mwanInterfaceSetting->grouping == 'monitor'){
                    $entity->{'enable_monitor'} = true;
                    if($mwanInterfaceSetting->type == 'option'){
                        $entity->{'mon_'.$mwanInterfaceSetting->name} = $mwanInterfaceSetting->value;
                    }
                    if($mwanInterfaceSetting->type == 'list'){
                        if(!isset($entity->{'mon_'.$mwanInterfaceSetting->name.'[]'})){
                            $entity->{'mon_'.$mwanInterfaceSetting->name.'[]'} = [$mwanInterfaceSetting->value];
                        }else{
                            array_push($entity->{'mon_'.$mwanInterfaceSetting->name.'[]'},$mwanInterfaceSetting->value);
                        }
                    }  
                }
                             
            }
            
            if(isset($entity->{'mon_track_ip[]'})){
                $entity->{'mon_track_ip'} = $entity->{'mon_track_ip[]'};
                unset($entity->{'mon_track_ip[]'});           
            }                    
            unset($entity->mwan_interface_settings);
                
            $data =  $entity;                    
        }
        $this->set([
            'data'      => $data,
            'success'   => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);        
    }
    
    public function interfaceAddEdit(){
	   
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}

        //__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }
        
        $ap_flag 	= true;	
		if($user['group_name'] == Configure::read('group.admin')){
			$ap_flag = false; //clear if admin
		}
				   
        if ($this->request->is('post')) { 
            $req_d  = $this->request->getData();
            
            $check_items = ['apply_sqm_profile','enable_monitor'];
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
            
            
            if($req_d['id'] === "0"){
            
                unset($req_d['id']);
                
                $metric = 1;
                //-- Get the highest metric and add one to it for this
                $ifMetric = $this->MwanInterfaces->find()
                    ->where(['MwanInterfaces.multi_wan_profile_id' => $req_d['multi_wan_profile_id']])
                    ->orderBy(['MwanInterfaces.metric DESC'])
                    ->first();
                if($ifMetric){
                    $req_d['metric'] =   $ifMetric->metric + 1;
                }                             
            
                //New MwanInterface 
                $mwanInterface = $this->MwanInterfaces->newEntity($req_d); 
                if ($this->MwanInterfaces->save($mwanInterface)) {
                    $new_id = $mwanInterface->id;
                    $this->_addInterfaceSettings($new_id);
                    $this->set([
                        'success' => true
                    ]);
                    $this->viewBuilder()->setOption('serialize', true);
                } else {
                    $message = __('Could not update item');
                    $this->JsonErrors->entityErros($mwanInterface,$message);
                }          
            }else{
            
                $mwanInterface = $this->MwanInterfaces->find()->contain([])->where(['MwanInterfaces.id' => $this->request->getData('id')])->first();
                if($mwanInterface){
                    $this->{$this->main_model}->patchEntity($mwanInterface, $req_d);
                    if ($this->MwanInterfaces->save($mwanInterface)) {
                        $this->MwanInterfaceSettings->deleteAll(['MwanInterfaceSettings.mwan_interface_id' => $mwanInterface->id]);
                        $this->_addInterfaceSettings($mwanInterface->id);  
                    }                              
                }         
            }          
                       
             $this->set([
                'success' => true
            ]);
            $this->viewBuilder()->setOption('serialize', true);                         
        }
    }
    
    private function _addInterfaceSettings($mwan_interface_id){
    
        $cdata = $this->request->getData();    

        foreach(array_keys($cdata) as $key){
            if(preg_match('/^wbw_/',$key)){             
                $d      = [];
                $d['mwan_interface_id'] = $mwan_interface_id;
                $d['grouping']  = 'wbw_setting';
                $d['name']      = preg_replace('/^wbw_/', '', $key);
                $d['value']     = $cdata["$key"];                                  
                $e = $this->{'MwanInterfaceSettings'}->newEntity($d);  
                $this->{'MwanInterfaceSettings'}->save($e);    
            }
        }   

        foreach(array_keys($cdata) as $key){
            if(preg_match('/^qmi_/',$key)){             
                $d      = [];
                $d['mwan_interface_id'] = $mwan_interface_id;
                $d['grouping']  = 'qmi_setting';
                $d['name']      = preg_replace('/^qmi_/', '', $key);
                $d['value']     = $cdata["$key"];                                  
                $e = $this->{'MwanInterfaceSettings'}->newEntity($d);  
                $this->{'MwanInterfaceSettings'}->save($e);    
            }
        } 
     
        foreach(array_keys($cdata) as $key){
            if(preg_match('/^pppoe_/',$key)){             
                $d      = [];
                $d['mwan_interface_id'] = $mwan_interface_id;
                $d['grouping']  = 'pppoe_setting';
                $d['name']      = preg_replace('/^pppoe_/', '', $key);
                $d['value']     = $cdata["$key"];                                  
                $e = $this->{'MwanInterfaceSettings'}->newEntity($d);  
                $this->{'MwanInterfaceSettings'}->save($e);    
            }
        }
        
        foreach(array_keys($cdata) as $key){
            if(preg_match('/^static_/',$key)){             
                $d      = [];
                $d['mwan_interface_id'] = $mwan_interface_id;
                $d['grouping']  = 'static_setting';
                $d['name']      = preg_replace('/^static_/', '', $key);
                $d['value']     = $cdata["$key"];                                  
                $e = $this->{'MwanInterfaceSettings'}->newEntity($d);  
                $this->{'MwanInterfaceSettings'}->save($e);    
            }
        }
        
        foreach(array_keys($cdata) as $key){
            if(preg_match('/^ethernet_/',$key)){             
                $d      = [];
                $d['mwan_interface_id'] = $mwan_interface_id;
                $d['grouping']  = 'ethernet_setting';
                $d['name']      = preg_replace('/^ethernet_/', '', $key);
                $d['value']     = $cdata["$key"];                                  
                $e = $this->{'MwanInterfaceSettings'}->newEntity($d);  
                $this->{'MwanInterfaceSettings'}->save($e);    
            }
        }
        
        //-- Monitor is a bit more complicated :-)
        if(isset($cdata['enable_monitor'])){
            foreach(array_keys($cdata) as $key){
                if(preg_match('/^mon_/',$key)){
                    if(preg_match('/^mon_track_ip/',$key)){
                        $d      = [];
                        $d['mwan_interface_id'] = $mwan_interface_id;
                        $d['grouping']  = 'monitor';
                        $d['type']      = 'list';
                        $d['name']      = 'track_ip';
                        $d['value']     = $cdata["$key"];                                  
                        $e = $this->{'MwanInterfaceSettings'}->newEntity($d);  
                        $this->{'MwanInterfaceSettings'}->save($e);                                        
                    }elseif($key == 'mon_flush_conntrack'){
                        foreach($cdata["mon_flush_conntrack"] as $conntrack){
                            $d      = [];
                            $d['mwan_interface_id'] = $mwan_interface_id;
                            $d['grouping']  = 'monitor';
                            $d['type']      = 'list';
                            $d['name']      = 'flush_conntrack';
                            $d['value']     = $conntrack;                                  
                            $e = $this->{'MwanInterfaceSettings'}->newEntity($d);
                            $this->{'MwanInterfaceSettings'}->save($e);                         
                        }
                                       
                    }else{
                        $d      = [];
                        $d['mwan_interface_id'] = $mwan_interface_id;
                        $d['grouping']  = 'monitor';
                        $d['name']      = preg_replace('/^mon_/', '', $key);
                        $d['value']     = $cdata["$key"];                                  
                        $e = $this->{'MwanInterfaceSettings'}->newEntity($d);  
                        $this->{'MwanInterfaceSettings'}->save($e);                       
                    } 
                }
            }
        }   
   
    }
    
    public function interfaceDelete() {
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}

        //__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }

        $fail_flag 	= false;
        $req_d 		= $this->request->getData();

	    if(isset($req_d['id'])){   //Single item delete
            $message = "Single item ".$req_d['id'];       
            $entity     = $this->{'MwanInterfaces'}->get($req_d['id']);   
            $this->{'MwanInterfaces'}->delete($entity);
             
        }else{                          //Assume multiple item delete
            foreach($req_d as $d){
                $entity     = $this->{'MwanInterfaces'}->get($d['id']);     
                $this->{'MwanInterfaces'}->delete($entity);                
            }
        }

        if($fail_flag == true){
            $this->set([
                'success'   => false,
                'message'   => __('Could not delete some items'),
            ]);
            $this->viewBuilder()->setOption('serialize', true);
        }else{
            $this->set([
                'success' => true
            ]);
            $this->viewBuilder()->setOption('serialize', true);
        }
	}
	
	public function policyView(){
	
	    $user = $this->Aa->user_for_token($this);
        if(!$user){   //If not a valid user
            return;
        }
        
        $req_q  = $this->request->getQuery();
        
        $mwanInterfaces = [];
        
        if(isset($req_q['id'])){                 
            $mwanInterfaces = $this->MwanInterfaces->find()->where(['MwanInterfaces.multi_wan_profile_id' => $req_q['id']])->all();
        }
        
        $mode   = 'load_balance';
        foreach($mwanInterfaces as $mwanInterface){
            if(($mwanInterface->policy_role =='standby')&&($mwanInterface->policy_active)){
                $mode = 'fail_over';
                break;
            }
        }
        
        $last_resort = 'unreachable';
        
        $multiWanProfile = $this->MultiWanProfiles->find()->where(['MultiWanProfiles.id' => $req_q['id']])->first();
        if($multiWanProfile){
            $last_resort = $multiWanProfile->last_resort;
        }
        
                         
         $this->set([
            'data'         => [
                'mode'          => $mode,
                'interfaces'    => $mwanInterfaces,
                'last_resort'   => $last_resort            
            ],
            'success'      => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
	
	}
	
	 public function policyEdit(){
	   
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}

        //__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }
        
        $ap_flag 	= true;	
		if($user['group_name'] == Configure::read('group.admin')){
			$ap_flag = false; //clear if admin
		}
		
		$multi_wan_profile_id = false;
		
		// Define a pattern to match keys that start with 'if_' followed by a number and then an underscore and some text
        $pattern        = '/^if_(\d+)_(\w+)/';
        $filteredArray  = [];		
		$requestData    = $this->request->getData();
		
		foreach ($requestData as $key => $value) {
            // Check if the key matches the pattern
            if (preg_match($pattern, $key, $matches)) {
                // $matches[1] will contain the number and $matches[2] will contain the "something" part
                $filteredArray[$matches[1]][$matches[2]] = $value;
            }
        }
        
        
        foreach ($filteredArray as $key => $value) {      
            if(isset($filteredArray[$key]['policy_active'])){
                $filteredArray[$key]['policy_active'] = 1;   
            }else{
                $filteredArray[$key]['policy_active'] = 0;
            }        
        }
        
        //One active check
        $activeCount = 0;
        $foActive    = 0;
        $foStandby   = 0;
        foreach ($filteredArray as $key => $value) { 
            if($filteredArray[$key]['policy_active'] == 1){
                $activeCount++;
            }
            if($filteredArray[$key]['policy_role'] == 'active'){
                $foActive++;
            }else{
                $foStandby++; 
            }    
        }
        
        if($activeCount == 0){        
            $message = __('At least ONE interface needs to be selected');
            $this->set([
                'message'   => $message,
                'success'   => false
            ]);
            $this->viewBuilder()->setOption('serialize', true);
            return;
        }
        
        if($requestData['mode'] == 'load_balance'){
            foreach ($filteredArray as $key => $value) { 
                $ifData         = $value;
                $ifData['id']   = $key;
                $ifData['policy_role'] = 'active'; //reset it to active
                $mwanInterface = $this->MwanInterfaces->find()->where(['MwanInterfaces.id' => $key])->first();
                if($mwanInterface){
                    $multi_wan_profile_id = $mwanInterface->multi_wan_profile_id;
                    $this->MwanInterfaces->patchEntity($mwanInterface, $ifData); 
                    $this->MwanInterfaces->save($mwanInterface);
                }   
            }       
        }
        
        if($requestData['mode'] == 'fail_over'){
            $found_error = false;
                      
            if($foActive == 0){
                $found_error = true;           
                $message = __('At least ONE interface needs to be set to Active');               
            }
            
            if($foStandby == 0){
                $found_error = true;           
                $message = __('At least ONE interface needs to be set to Standby');               
            }
            
            if($activeCount < 2){ 
                $found_error = true;           
                $message = __('At least TWO interfaces needs to be selected');                                 
            }
            
            if($found_error){           
                $this->set([
                    'message'   => $message,
                    'success'   => false
                ]);
                $this->viewBuilder()->setOption('serialize', true);
                return;
            }else{
                foreach ($filteredArray as $key => $value) { 
                    $ifData         = $value;
                    $ifData['id']   = $key;
                    $mwanInterface  = $this->MwanInterfaces->find()->where(['MwanInterfaces.id' => $key])->first();
                    if($mwanInterface){
                        $multi_wan_profile_id = $mwanInterface->multi_wan_profile_id;
                        $this->MwanInterfaces->patchEntity($mwanInterface, $ifData); 
                        $this->MwanInterfaces->save($mwanInterface);
                    }
                }
                $saved = true;           
            }            
        }
                
        if($multi_wan_profile_id){
            $multiWanProfile = $this->MultiWanProfiles->find()->where(['MultiWanProfiles.id' => $multi_wan_profile_id])->first();
            if($multiWanProfile){
                $multiWanProfile->last_resort = $requestData['last_resort'];
                $this->MultiWanProfiles->save($multiWanProfile); 
            }    
        }
                           	
		$this->set([  
            'success'   => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
	
	}
          	    	
    public function menuForGrid(){
        $user = $this->Aa->user_for_token($this);
        if(!$user){   //If not a valid user
            return;
        }
        
        $menu = $this->GridButtonsFlat->returnButtons(false,'MwanProfiles');
        $this->set([
            'items'         => $menu,
            'success'       => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }   
}
