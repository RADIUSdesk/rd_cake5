<?php

namespace App\Model\Table;

use Cake\ORM\Table;

class PasspointNaiRealmPasspointEapMethodsTable extends Table {

    public function initialize(array $config):void{  
        $this->addBehavior('Timestamp');       
        $this->belongsTo('PasspointNaiRealms', [
                'className' => 'PasspointNaiRealms',
                'foreignKey' => 'passpoint_nai_realm_id'
            ]);
        $this->belongsTo('PasspointEapMethods', [
                'className' => 'PasspointEapMethods',
                'foreignKey' => 'passpoint_eap_method_id'
            ]);          
    }      
}

