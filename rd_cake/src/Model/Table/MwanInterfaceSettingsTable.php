<?php

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class MwanInterfaceSettingsTable extends Table {

    public function initialize(array $config):void{  
        $this->addBehavior('Timestamp');  
        $this->belongsTo('MwanInterfaces');        
    }
      
    public function validationDefault(Validator $validator):Validator{
        $validator
            ->notEmptyString('name', 'A name is required')
            ->notEmptyString('value', 'A value is required')
            ->notEmptyString('grouping', 'A grouping is required');        
        return $validator;
    }      
}

