<?php

/** 
 * Edited by G-edit.
 * User: dirkvanderwalt
 * Date: 08-AUG-2026
 * Time: 00:00
 */

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class DevicesTable extends Table
{
    public function initialize(array $config):void{
        $this->addBehavior('Timestamp');
        $this->addBehavior('FreeRadius',
            [
                'for_model' => 'Devices'
            ]
        );

        $this->belongsTo('PermanentUsers');
        $this->belongsTo('Profiles',['propertyName'  => 'real_profile']);
        $this->hasMany('Radchecks',[
            'dependent' => true,
            'cascadeCallbacks' =>true,
            'foreignKey' => 'username',
            'bindingKey' => 'name'
        ]);
        $this->hasMany('Radreplies',[
            'dependent' => true,
            'cascadeCallbacks' =>true,
            'foreignKey' => 'username',
            'bindingKey' => 'name'
        ]);        
    }
    
    public function validationDefault(Validator $validator): Validator{
        $validator
            ->notEmptyString('name', 'A name is required')
            ->add('name', [ 
                'nameUnique' => [
                    'message' => 'The name you provided is already taken. Please provide another one.',
                    'rule' => 'validateUnique', 
                    'provider' => 'table'
                ]
            ]);
        return $validator;
    }
       
}
