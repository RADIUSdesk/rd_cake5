<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * QmiInfo Entity
 *
 * @property int $id
 * @property string $name
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class QmiInfoEntity extends Entity
{
    /**
     * Fields that can be mass assigned
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => true,
    ];
}
