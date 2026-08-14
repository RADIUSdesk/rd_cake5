<?php

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class RealmPmksTable extends Table
{
    public function initialize(array $config):void{
        $this->addBehavior('Timestamp');  
        $this->belongsTo('Realms');
        $this->belongsTo('RealmSsids');
    }
    
    public function validationDefault(Validator $validator): Validator{
        $validator
            ->notEmptyString('ppsk', 'A ppsk is required');
        return $validator;
    }
           
}
