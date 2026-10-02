<?php

namespace App\Controller\Component;

use Cake\Log\Log;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

use Cake\Controller\Component;
use Cake\ORM\TableRegistry;


use App\Model\Table\ProfilesTable;
use App\Model\Table\RealmsTable;
use App\Model\Table\ApProfileExitCaptivePortalsTable;

class ConnectAndRedirectComponent extends Component {

    protected ProfilesTable $Profiles;
    protected RealmsTable   $Realms;
    protected ApProfileExitCaptivePortalsTable $ApProfileExitCaptivePortals;
    
	public function initialize(array $config):void{
        $this->Profiles = TableRegistry::getTableLocator()->get('Profiles');
        $this->Realms 	= TableRegistry::getTableLocator()->get('Realms'); 
        $this->ApProfileExitCaptivePortals = TableRegistry::getTableLocator()->get('ApProfileExitCaptivePortals');
    }
        
    public function updateApCaptivePortal($ent_cp,$cloud_id){
    
        Configure::load('ConnectAndRedirect');     
        $special_uam_url = Configure::read('Cnr.special_uam_url'); 
         
        if($ent_cp->connect_and_redirect){
            $ent_cp->uam_url = $special_uam_url;
            $this->_completeApSetup($ent_cp,$cloud_id);
        }else{
            $this->_teardownApSetup($ent_cp);
        }   
		$this->ApProfileExitCaptivePortals->save($ent_cp);  
    }
    
    private function _completeApSetup($ent_cp,$cloud_id){
        $ap_profile_exits   = TableRegistry::getTableLocator()->get('ApProfileExits');
        $realms             = TableRegistry::getTableLocator()->get('Realms');
        $profiles           = TableRegistry::getTableLocator()->get('Profiles');     
        $exit_id            = $ent_cp->ap_profile_exit_id;
        $exit               = $ap_profile_exits->find()->where(['ApProfileExits.id' => $exit_id])->first();
        if($exit){
            $ap_profile_id  = $exit->ap_profile_id;
            $realm_id       = intval($exit->realm_list);
            if($realm_id){
                Log::info("Found Realm ID " . $realm_id);
                //Check if there is a profile called
                $start_with = Configure::read('Cnr.start_with');
                $name = $start_with.'_a_'.$ap_profile_id.'_'.$exit_id;
                Log::info("Look for a Profile with name of :" . $name); 
                $profile = $profiles->find()
                    ->where([
                        'Profiles.name' => $name,
                        'Profiles.cloud_id' => $cloud_id                    
                    ])
                    ->first();
                if(!$profile){
                    Log::info("Profile with name of :" . $name . ' not found Add one'); 
                }                                          
            }
        }
    }
    
    private function _teardownApSetup($ent_cp){
    
    
    }
    

}
