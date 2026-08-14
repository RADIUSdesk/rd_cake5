<?php
// src/Model/Entity/AuditLog.php

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AuditLog extends Entity
{
    /**
     * @var array<string>
     */
    protected array $_virtual = [
        'summary',
        'changes_array'
    ];

    /**
     * Accessor for virtual field 'changes_array'
     *
     * @return array<array{field: string, old: mixed, new: mixed}>
     */
    // src/Model/Entity/AuditLog.php
    protected function _getChangesArray(): array
    {
        $result = [];

        // 1. Fetch the value safely
        $changes = $this->get('changes');

        // 2. If it's a JSON string (due to missing schema mapping), decode it on the fly
        if (is_string($changes)) {
            $changes = json_decode($changes, true);
        }

        // 3. Fallback to an empty array if null or invalid
        if (!is_array($changes)) {
            return [];
        }

        // 4. Loop through the array data safely
        foreach ($changes as $field => $values) {
            $result[] = [
                'field' => (string)$field,
                'old'   => $values['old'] ?? null,
                'new'   => $values['new'] ?? null,
            ];
        }

        return $result;
    }

}

