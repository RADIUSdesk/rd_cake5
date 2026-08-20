<?php
//-- CakePHPv5 ready --
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime;
use Cake\Datasource\EntityInterface;

use Cake\Log\Log;  // <-- Add this import
use ArrayObject;

class PermanentUsersTable extends Table{

    public function initialize(array $config): void{
        parent::initialize($config);

        $this->addBehavior('Timestamp');
        $this->addBehavior('FreeRadius', [
            'for_model' => 'PermanentUsers'
        ]);
        $this->addBehavior('Ppsk');

        $this->belongsTo('Clouds');
        $this->belongsTo('Countries');
        $this->belongsTo('Languages');
        $this->belongsTo('Profiles', ['propertyName' => 'real_profile']);
        $this->belongsTo('Realms', ['propertyName' => 'real_realm']);
        $this->belongsTo('RealmVlans');

        $this->hasMany('Devices', ['dependent' => true, 'cascadeCallbacks' => true]);
        $this->hasMany('TopUps', ['dependent' => true, 'cascadeCallbacks' => true]);

        $this->hasMany('Radchecks', [
            'dependent' => true,
            'cascadeCallbacks' => true,
            'foreignKey' => 'username',
            'bindingKey' => 'username'
        ]);

        $this->hasMany('Radreplies', [
            'dependent' => true,
            'cascadeCallbacks' => true,
            'foreignKey' => 'username',
            'bindingKey' => 'username'
        ]);

        $this->hasOne('PermanentUserOtps', ['dependent' => true]);
    }

    public function beforeMarshal(
        EventInterface $event,
        \ArrayObject $data,
        \ArrayObject $options
    ): void {
        if (!empty($data['mac_address'])) {
            $data['mac_address'] = strtoupper((string)$data['mac_address']);
        }
    }

    public function validationDefault(Validator $validator): Validator {
    
        $validator
            ->notEmptyString('username', 'A name is required')
            ->add('username', [
                'nameUnique' => [
                    'message' => 'The username you provided is already taken. Please provide another one.',
                    'rule' => 'validateUnique',
                    'provider' => 'table'
                ]
            ])
            ->allowEmptyString('static_ip')
            ->add('static_ip', [
                'nameUnique' => [
                    'message' => 'The Static IP Address is already taken',
                    'rule' => ['validateUnique', ['scope' => 'realm_id']],
                    'provider' => 'table'
                ]
            ])
            ->allowEmptyString('mac_address')
            ->add('mac_address', [
                'nameUnique' => [
                    'message' => 'The MAC Address is already taken',
                    'rule' => ['validateUnique'],
                    'provider' => 'table'
                ],
            ])
            ->add('mac_address', 'format', [
                'rule' => ['custom', '/^([0-9A-F]{2}-){5}[0-9A-F]{2}$/'],
                'message' => 'Invalid MAC address format'
            ])
            ->allowEmptyString('ppsk')
            ->add('ppsk', [
                'nameUnique' => [
                    'message' => 'The PPSK you provided is already taken. Please provide another one.',
                    'rule' => ['validateUnique', ['scope' => 'realm_id']],
                    'provider' => 'table'
                ]
            ]);

        return $validator;
    }

    public function beforeSave(
        EventInterface $event,
        EntityInterface $entity,
        \ArrayObject $options
    ): void {
        if ($entity->to_date instanceof DateTime) {
            if ($entity->to_date < DateTime::now()) {
                $entity->admin_state = 'expired';
            } elseif ($entity->admin_state === 'expired') {
                $entity->admin_state = 'active';
            }
        }
    }
    
    public function afterSaveCommit(EventInterface $event, EntityInterface $entity, ArrayObject $options): void {

        if ($entity->isNew()) {
            // Run the command - record is now committed to database
            $output = shell_exec('bin/cake re_auth_permanent_user ' . $entity->id . ' 2>&1');            
            if ($output) {
                Log::write('info', 'Command output: ' . $output);
            }
        }
        //--- if the user's admin_state == depleted and the temporary_access_until changed; re-auth
        //--- FreeRADIUS then also have to be adapted in order to give temp access to the user (typically it will be logged/accounted under a different/dedicated user
        else {
        
            if ($entity->isDirty('temporary_access_until')){ //We don't care what the value is, only need to know if it changed
                $output = shell_exec('bin/cake re_auth_permanent_user ' . $entity->id . ' 2>&1');           
                Log::write('info', sprintf(
                    'Temp-access re-auth for %s. Command feedback: %s', 
                    $entity->username, $output
                ));           
            }      
        }
             
        /*
        //-- Not needed since the after the user are created top-ups will cause re_auth's
        // 2. Existing records that's been updated
        else {
            //Check if 'admin_state' changed
            if ($entity->isDirty('admin_state')) {
                $new_value = $entity->get('admin_state'); // or $entity->admin_state
                $old_value = $entity->getOriginal('admin_state');
                
                // Only call re-auth if we moved from depleted to active
                if ($old_value === 'depleted' && $new_value === 'active') {
                    $output = shell_exec('bin/cake re_auth_permanent_user ' . $entity->id . ' 2>&1');
                    
                    Log::write('info', sprintf(
                        'User %d restored from %s to %s. Command feedback: %s', 
                        $entity->id, $old_value, $new_value, $output
                    ));
                }
            }
        }
        */
    }
        
}
