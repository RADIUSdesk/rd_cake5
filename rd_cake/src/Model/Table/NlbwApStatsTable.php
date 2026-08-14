<?php

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class NlbwApStatsTable extends Table {

    public function initialize(array $config):void{  
        $this->addBehavior('Timestamp');
        $this->belongsTo('Aps'); 
        $this->belongsTo('MacAddresses'); 
        $this->belongsTo('ApProfileExits', [
            'foreignKey' => 'exit_id'
        ]);           
    }
}

