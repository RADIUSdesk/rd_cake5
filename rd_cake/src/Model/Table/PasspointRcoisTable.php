<?php

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class PasspointRcoisTable extends Table {

    public function initialize(array $config):void{  
        $this->addBehavior('Timestamp');
        $this->belongsTo('PasspointProfiles');            
    }    
    public function validationDefault(Validator $validator):Validator{

        $validator
            ->notEmptyString('name', 'A name is required')
            ->add('rcoi_id', [ 
                'nameUnique' => [
                    'message'   => 'The RCOI ID you provided is already taken. Please provide another one.',
                    'rule'    => ['validateUnique', ['scope' => 'passpoint_profile_id']],
                    'provider'  => 'table'
                ]
            ])
            ->add('name', [ 
                'nameUnique' => [
                    'message'   => 'The name you provided is already taken. Please provide another one.',
                    'rule'    => ['validateUnique', ['scope' => 'passpoint_profile_id']],
                    'provider'  => 'table'
                ]
            ]);           
        return $validator;
    }      
          
}

