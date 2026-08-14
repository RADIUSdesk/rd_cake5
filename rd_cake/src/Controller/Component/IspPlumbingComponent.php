<?php

//----------------------------------------------------------
//---- Author: Dirk van der Walt
//---- License: GPL v3
//---- Description: 
//---- Date: 12-FEB-2025
//------------------------------------------------------------

namespace App\Controller\Component;
use Cake\Controller\Component;

use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

use Cake\ORM\TableRegistry;

class IspPlumbingComponent extends Component {

	protected $root_user_id	= 44;
	protected $disabled	    = false;
	protected array $components   = ['Kicker'];
   
    public function disconnectIfActive($entity){
    
        if($this->disabled){
	        return;
        }
        
        $locator        = TableRegistry::getTableLocator();              
        $this->Radaccts = $locator->get('Radaccts');
        $this->Users    = $locator->get('Users');      	
        $ent_root 		= $this->Users->find()->where(['Users.id' => $this->root_user_id])->first();
        $token	 		= $ent_root->token;
        $username		= $entity->username;
        $data			= [];   	
        $e_username 	= $this->{'Radaccts'}->find()->where(['Radaccts.username' => $username,'Radaccts.acctstoptime IS NULL'])->all();
        foreach($e_username as $ent){
	        $data = $this->Kicker->kick($ent,$token); 
        }
        return $data;	      
    }
}
