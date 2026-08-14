<?php
//-- CakePHPv5 ready --
declare(strict_types=1);

namespace App\Model\Table;
use Cake\ORM\Table;
use Cake\Database\Schema\TableSchemaInterface;

class AuditLogsTable extends Table {
    public function initialize(array $config):void{
        parent::initialize($config);
        $this->addBehavior('Timestamp'); 
        $this->belongsTo('Users', [
            'className'     => 'Users',
            'foreignKey'    => 'user_id'
        ]);
        
        // Force CakePHP to convert the database string into a PHP array automatically
        $this->getSchema()->setColumnType('changes', 'json');        
    }

}
