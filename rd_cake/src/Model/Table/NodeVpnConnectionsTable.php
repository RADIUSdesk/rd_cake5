<?php

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class ApVpnConnectionsTable extends Table
{
    public function initialize(array $config):void{
        $this->addBehavior('Timestamp');  
        $this->belongsTo('Aps');       
    }
    
    public function validationDefault(Validator $validator): Validator{
        $validator
            ->notEmptyString('name', 'A name is required')
            ->add('name', [ 
                'nameUnique' => [
                    'message'   => 'The name you provided is already taken. Please provide another one.',
                    'rule'    => ['validateUnique', ['scope' => 'ap_id']],
                    'provider'  => 'table'
                ]
            ]);           
        return $validator;
    }
}
