<?php

namespace App\Model\Table;
use Cake\ORM\Table;
use Cake\Validation\Validator;

use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;


class WireguardServersTable extends Table{

    public function initialize(array $config):void{
        
        $this->addBehavior('Timestamp');  
        $this->belongsTo('Clouds');       
        $this->hasOne('WireguardStats',['dependent' => true]);
        $this->hasMany('WireguardInstances',['dependent' => true]);
    }
    
    public function validationDefault(Validator $validator):Validator{
        $validator
            ->notEmptyString('name', 'A name is required')
            ->add('name', [ 
                'nameUnique' => [
                    'message'   => 'The name you provided is already taken. Please provide another one.',
                    'rule'    => ['validateUnique', ['scope' => 'cloud_id']],
                    'provider'  => 'table'
                ]
            ])           
            ->notEmptyString('mac', 'A MAC is required')
            ->add('mac', [ 
                'macUnique' => [
                    'message' => 'The MAC you provided is already taken. Please provide another one',
                    'rule' => 'validateUnique', 
                    'provider' => 'table'
                ]
            ]);                     
        return $validator;
    }
    
}
