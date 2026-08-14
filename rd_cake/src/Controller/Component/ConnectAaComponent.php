<?php

namespace App\Controller\Component;

use Cake\Controller\Component;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;


class ConnectAaComponent extends Component {


	public function initialize(array $config):void{
        $this->PermanentUsers  = TableRegistry::getTableLocator()->get('PermanentUsers');
    }
    
    public function userForToken(){
        return $this->_check_if_valid(false);
    }
       
    private function _check_if_valid($with_cloud = true){

        $controller = $this->getController();
        $request    = $controller->getRequest();
        $r_data     = $request->getData();
        $q_data     = $request->getQuery();
        $token      = $r_data['token'] ?? $q_data['token'] ?? false;

        // Check if the token is present and valid
        if (!$token) {
            return $this->_fail_response(__('Token missing'));
        }

        if (strlen($token) !== 36) {
            return $this->_fail_response(__('Token in wrong format'));
        }

        // Find the owner of the token
        $user = $this->_find_token_owner($token);
        if (!$user) {
            return $this->_fail_response('Permanent User User Not Found');
        }
        
        return $user;
    }
    
    private function _find_token_owner($token){       
        $PermanentUsers = TableRegistry::getTableLocator()->get('PermanentUsers');     
        $pu = $PermanentUsers->find()
            ->where(['token'    => $token])
            ->contain(['Realms' => 'RealmSsids','RealmVlans'])
            ->first();            
        if($pu){     
            $data['qr_available'] = false;
            if($pu->real_realm){
                $first_ssid = array_shift($pu->real_realm->realm_ssids);
                if($first_ssid){
                    $pu->qr_available   = true; 
                    $pu->ssid           = $first_ssid->name; 
                    $pu->qr_value       = "WIFI:T:WPA;S:".$pu->ssid.";P:".$pu->ppsk.";;";          
                }          
            }            
            if($pu->realm_vlan){ 
                $pu->vlan       = $pu->realm_vlan->vlan;   
            }
            unset($pu->password);
            unset($pu->real_realm);
            unset($pu->realm_vlan);
            return $pu;                
        }
        return false;
    }
    
    // Helper method to handle failure responses
    private function _fail_response($message){

        $controller = $this->getController();
        $controller->set([
            'success' => false,
            'message' => $message,
        ]);
        $controller->viewBuilder()->setOption('serialize', true);
        return false;
    }


}
