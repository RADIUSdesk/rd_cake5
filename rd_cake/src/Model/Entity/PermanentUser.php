<?php 
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

////--use Cake\Auth\DefaultPasswordHasher; ////--old in CakePHPv4

use Authentication\PasswordHasher\DefaultPasswordHasher; // New in CakePHPv5
use Cake\Utility\Text;

/**
 * PermanentUser Entity.
 */
class PermanentUser extends Entity 
{
    // Hash the user's password before saving it to the database.
    protected function _setPassword(string $password): ?string 
    {
        $this->set('cleartext_password', $password);
        if (strlen($password) > 0) {
            return (new DefaultPasswordHasher())->hash($password);
        }
        return null;
    }
    
    //Generate new value if empty
    protected function _setToken(?string $value): ?string
    {
        if ($value === '') { 
            return Text::uuid();
        }
        return $value;
    }

    // Stel datums na null as 'always_active' gekies is
    protected function _setAlwaysActive(?string $value): ?string
    {
        if ($value === 'always_active') {
            $this->set('from_date', null); 
            $this->set('to_date', null);
        }
        return $value;
    }
}

