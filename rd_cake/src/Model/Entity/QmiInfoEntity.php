<?php 

namespace App\Model\Entity;

use Cake\ORM\Entity;

class QmiInfoEntity extends Entity{

    // Define accessible fields if needed
    protected $_accessible = [
        '*' => true,  // Allow all fields to be mass assigned
    ];
}

