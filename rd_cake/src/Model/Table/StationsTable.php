<?php

namespace App\Model\Table;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class StationsTable extends Table{
    public function initialize(array $config):void{
        $this->addBehavior('Timestamp');  
        $this->hasMany('StationAliases');        
    }  
}
