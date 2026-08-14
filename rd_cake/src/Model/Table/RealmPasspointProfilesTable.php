<?php

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class RealmPasspointProfilesTable extends Table{

    public function initialize(array $config):void{
        $this->addBehavior('Timestamp');  
        $this->belongsTo('Realms');
        $this->hasMany('RealmPasspointNaiRealms',['dependent' => true]); 
        $this->hasMany('RealmPasspointRcois',['dependent' => true]); 
    }
       
    public function validationDefault(Validator $validator): Validator{
        $validator
            ->notEmptyString('name', 'A name is required')
        return $validator;
    }
               
}
