<?php

namespace App\Controller\Component;

use Cake\Controller\Component;
use Cake\ORM\TableRegistry;

use App\Model\Table\SmsHistoriesTable;
use App\Model\Table\EmailHistoriesTable;

class RdLoggerComponent extends Component {

    protected SmsHistoriesTable $SmsHistories;
    protected EmailHistoriesTable $EmailHistories;

	public function initialize(array $config):void{
        $this->SmsHistories  	= TableRegistry::getTableLocator()->get('SmsHistories');
        $this->EmailHistories 	= TableRegistry::getTableLocator()->get('EmailHistories'); 
    }
        
    public function addSmsHistory($cloud_id,$to,$for,$message,$reply,$nr){
    	$d	= [
    		'cloud_id' 	=> $cloud_id,
    		'recipient'	=> $to,
    		'reason'	=> $for,
    		'message'	=> $message,
    		'reply'		=> $reply,
    		'sms_provider'	=> $nr		
    	];
    	$e 			= $this->{'SmsHistories'}->newEntity($d);
		$this->{'SmsHistories'}->save($e);
    
    }
    
    public function addEmailHistory($cloud_id,$to,$for,$message){
    	$d	= [
    		'cloud_id' 	=> $cloud_id,
    		'recipient'	=> $to,
    		'reason'	=> $for,
    		'message'	=> $message  		
    	];
    	$e 			= $this->{'EmailHistories'}->newEntity($d);
		$this->{'EmailHistories'}->save($e);
    
    }

}
