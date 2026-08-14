<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;
use Cake\ORM\RulesChecker;

use Cake\Event\EventInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ConnectionManager;
use PDO;

class WireguardInstancesTable extends Table {

    public function initialize(array $config): void {

        parent::initialize($config);

        $this->setTable('wireguard_instances');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp'); // uses created/modified
        $this->belongsTo('WireguardServers', [
            'foreignKey'=> 'wireguard_server_id',
            'joinType'  => 'INNER',
         ]);
         $this->hasMany('WireguardPeers',['dependent' => true]);
    }

    public function validationDefault(Validator $v): Validator {
        $v
            ->integer('wireguard_server_id')->requirePresence('wireguard_server_id')->notEmptyString('wireguard_server_id')

            ->scalar('name')->maxLength('name', 128)->requirePresence('name')->notEmptyString('name')

            ->integer('listen_port')->range('listen_port', [1, 65535], 'Port must be 1–65535')->requirePresence('listen_port')->notEmptyString('listen_port')

            ->scalar('private_key')->lengthBetween('private_key', [44, 44], 'Private key must be 44 chars base64')
            ->allowEmptyString('private_key') // allow '', mutator will generate

            ->scalar('public_key')->lengthBetween('public_key', [44, 44], 'Public key must be 44 chars base64')
            ->allowEmptyString('public_key') // set by private_key mutator typically

            ->allowEmptyString('preshared_key') // '' to auto-generate; NULL if you want it optional
            ->add('preshared_key', 'len', [
                'rule' => function ($value, $context) {
                    // allow null/empty to trigger generator; if provided, enforce 44-char base64
                    if ($value === null || $value === '') { return true; }
                    return \is_string($value) && \strlen($value) === 44;
                },
                'message' => 'PSK must be 44 chars (base64) or omitted.',
            ])

            ->boolean('ipv4_enabled')->requirePresence('ipv4_enabled')
            ->allowEmptyString('ipv4_address')
            ->integer('ipv4_mask')->range('ipv4_mask', [0, 32])

            ->boolean('ipv6_enabled')->requirePresence('ipv6_enabled')
            ->allowEmptyString('ipv6_address')
            ->integer('ipv6_prefix')->range('ipv6_prefix', [0, 128])

            ->boolean('nat_enabled')->requirePresence('nat_enabled')
            ->boolean('sqm_enabled')->requirePresence('sqm_enabled')

            ->nonNegativeInteger('upload_mb')
            ->nonNegativeInteger('download_mb')
            
            ->add('name', [ 
                'nameUnique' => [
                    'message'   => 'The name you provided is already taken. Please provide another one.',
                    'rule'    => ['validateUnique', ['scope' => 'wireguard_server_id']],
                    'provider'  => 'table'
                ]
            ]); 
            
           // ->nonNegativeInteger('interface_number')->requirePresence('interface_number', 'create');

        return $v;
    }

    public function buildRules(RulesChecker $rules): RulesChecker {
    
        // If you have a servers table:
        // $rules->add($rules->existsIn(['wireguard_server_id'], 'WireguardServers'));

        // Enforce unique listen_port within a server (optional)
        $rules->add($rules->isUnique(
            ['listen_port', 'wireguard_server_id'],
            'This port is already used on the selected server.'
        ));
        
        // Unique per server
        $rules->add($rules->isUnique(
            ['interface_number','wireguard_server_id'],
            'Interface number already used on this server.'
        ));

        return $rules;
    }
      
    public function beforeSave(EventInterface $event, EntityInterface $entity, $options): void {

        if ($entity->isNew() && $entity->get('interface_number') === null) {
            /** @var int $serverId */
            $serverId = (int)$entity->get('wireguard_server_id');
            $entity->set('interface_number', $this->nextFreeInterfaceNumber($serverId));
        }
    }

    private function nextFreeInterfaceNumber(int $serverId, int $retries = 1): int {

        $conn = ConnectionManager::get('default');

        while (true) {
            $conn->begin();

            try {
                // Lock existing rows for this server to avoid picking the same gap concurrently
                $stmt = $conn->prepare(
                    'SELECT interface_number
                       FROM wireguard_instances
                      WHERE wireguard_server_id = :sid
                      ORDER BY interface_number
                      FOR UPDATE'
                );
                $stmt->bindValue('sid', $serverId, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

                // Find smallest missing non-negative integer
                $expect = 0;
                foreach ($rows as $num) {
                    $n = (int)$num;
                    if ($n === $expect) {
                        $expect++;
                        continue;
                    }
                    if ($n > $expect) {
                        break; // gap found at $expect
                    }
                }

                $conn->commit();
                return $expect;

            } catch (\Throwable $e) {
                $conn->rollback();
                if ($retries-- > 0) {
                    // Brief pause can help in heavy contention, optional:
                    // usleep(20000);
                    continue;
                }
                throw $e;
            }
        }
    }
        
}