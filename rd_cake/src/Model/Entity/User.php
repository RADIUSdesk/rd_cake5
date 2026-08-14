<?php

namespace App\Model\Entity;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\ORM\Entity;
use Cake\Utility\Text;

class User extends Entity{

    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    protected array $_hidden = [
        'password',
    ];

    protected function _setPassword(string $password): ?string{

        if (strlen($password) > 0) {
            return (new DefaultPasswordHasher())->hash($password);
        }

        return null;
    }

    protected function _setToken($value){
        if ($value === '') {
            return Text::uuid();
        }

        return $value;
    }
}
