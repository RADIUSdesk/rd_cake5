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
use Cake\I18n\Time;
use Cake\Http\Client;

use App\Model\Table\ApsTable;
use App\Model\Table\NodesTable;


class MqttController extends AppController{
    
    protected	$node_action_add = 'http://127.0.0.1/cake4/rd_cake/node-actions/add.json';
    protected	$ap_action_add = 'http://127.0.0.1/cake4/rd_cake/ap-actions/add.json';
    
    protected ApsTable $Aps;
    protected NodesTable $Nodes;
    
    public function initialize():void{  
        parent::initialize();    
        $this->Aps     = $this->fetchTable('Aps');
        $this->Nodes   = $this->fetchTable('Nodes');
    }
    
    public function startSpeedtest(){
    
        $queryData  = $this->request->getQuery();
        $req_d 		= $this->request->getData();
        
        if(isset($req_d['dev_mode'])&& ($req_d['dev_mode'] == 'ap')){
            $this->apSpeedTest($req_d['dev_id']);          
        }
        
        $this->set([
            'data'      => [],
            'success'   => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);      
    }
    
    private function apSpeedTest($ap_id){  
    
        $ap         = $this->Aps->find()->where(['Aps.id' => $ap_id])->contain(['ApProfiles'])->first();       
        $queryData  = $this->request->getQuery();   	  	
    	if($ap){ 
    	    $cloud_id  = $ap->ap_profile->cloud_id;	   	
    		$command   = 'cd /etc/MESHdesk/utils ; ./util_speedtest2mqtt.lua';
            $a_data    = [
            	'ap_id' 	=> $ap_id,
            	'command' 	=> $command, 
            	'action'	=> 'execute',
				'cloud_id'	=> $cloud_id,
				'token'		=> $queryData['token'],
				'sel_language'	=> '4_4'
          	];                  	
          	$http 		= new Client();
			$response 	= $http->post(
			  $this->ap_action_add,
			  json_encode($a_data),
			  ['type' => 'json']
			);   	
    	}      
    }
    
    
    
    
}
