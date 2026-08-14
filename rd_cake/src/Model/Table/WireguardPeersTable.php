<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;


class WireguardPeersTable extends Table {

    public function initialize(array $config): void {

        parent::initialize($config);

        $this->setTable('wireguard_peers');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp'); // uses created/modified
        $this->belongsTo('WireguardInstances', [
            'foreignKey'=> 'wireguard_instance_id',
            'joinType'  => 'INNER',
         ]);
    }       
}