<?php

declare(strict_types=1);

namespace App\Controller\Component;

use Cake\Controller\Component;
use InvalidArgumentException;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\TableLocator;

/**
 * SubnetPlannerComponent
 *
 * Computes "next" subnets for IPv4/IPv6 without external deps.
 * - Accepts CIDR strings like "10.12.0.0/24" or "10.12.0.5/24".
 * - Normalizes to the network before advancing.
 * - Preserves the prefix length on the returned subnet.
 */
class SubnetPlannerComponent extends Component {


    /**
     * Find the first non-overlapping subnet for a server.
     *
     * @param int         $serverId   wireguard_server_id
     * @param 'ipv4'|'ipv6' $family   Which family to allocate
     * @param int         $prefix     Desired prefix length (e.g. 24 or 64)
     * @param string|null $seedCidr   Where to start scanning; if null and $poolCidr set,
     *                                will start at pool's network with the same $prefix
     * @param string|null $poolCidr   Constrain search to this supernet (e.g. "10.12.0.0/16")
     * @param int[]       $excludeIds Instance IDs to ignore (e.g., updating an existing row)
     * @return string                 CIDR string of first free subnet, e.g. "10.12.3.0/24"
     */
    public function nextFreeSubnet(
        int $serverId,
        string $family,
        int $prefix,
        ?string $seedCidr = null,
        ?string $poolCidr = null,
        array $excludeIds = []
    ): string {
        if ($family !== 'ipv4' && $family !== 'ipv6') {
            throw new InvalidArgumentException("family must be 'ipv4' or 'ipv6'");
        }

        // Determine address size
        $bits = ($family === 'ipv4') ? 32 : 128;

        // Build the occupied list (as CIDRs) from DB
        $occupied = $this->collectOccupiedCidrs($serverId, $family, $excludeIds);

        // Choose starting point
        if ($seedCidr !== null) {
            // normalize seed to $prefix
            [$seedAddr, , $seedBits] = $this->parseCidr($seedCidr);
            if ($seedBits !== $bits) {
                throw new InvalidArgumentException("Seed family mismatch for {$family}");
            }
            $start = $this->inetNtop(
                $this->networkAddress($seedAddr, $prefix),
                $bits
            ) . '/' . $prefix;
        } elseif ($poolCidr !== null) {
            // start at pool's network, then force to our target prefix (first child subnet)
            [$poolAddr, $poolPrefix, $poolBits] = $this->parseCidr($poolCidr);
            if ($poolBits !== $bits) {
                throw new InvalidArgumentException("Pool family mismatch for {$family}");
            }
            if ($prefix < $poolPrefix) {
                throw new InvalidArgumentException("Desired /{$prefix} is larger than pool /{$poolPrefix}");
            }
            $start = $this->inetNtop($this->networkAddress($poolAddr, $poolPrefix), $bits) . '/' . $prefix;
        } else {
            throw new InvalidArgumentException('Provide at least $seedCidr or $poolCidr.');
        }

        // If a pool is set, keep candidates inside it
        $pool = $poolCidr;

        // Walk forward until you find a non-overlapping subnet
        $candidate = $start;
        $maxSteps  = 1 << 16; // guardrail
        for ($i = 0; $i < $maxSteps; $i++) {
            // Pool constraint check
            if ($pool && !$this->subnetWithin($candidate, $pool)) {
                throw new InvalidArgumentException("No free /{$prefix} remains inside {$pool}");
            }

            // Overlap check
            $overlaps = false;
            foreach ($occupied as $cidr) {
                if ($this->cidrOverlaps($candidate, $cidr)) {
                    $overlaps = true;
                    break;
                }
            }
            if (!$overlaps) {
                return $candidate;
            }
            $candidate = $this->nextSubnet($candidate); // same prefix step forward
        }

        throw new InvalidArgumentException('Exhausted scan for next free subnet.');
    }

    /**
     * Collect existing subnets (as CIDR strings) for a server from wireguard_instances.
     * Only includes rows where that family is enabled and address/prefix are not null.
     *
     * @return string[] e.g. ["10.12.0.0/24", "10.12.1.0/24", ...]
     */
    private function collectOccupiedCidrs(int $serverId, string $family, array $excludeIds = []): array
    {
        /** @var \Cake\ORM\Table $Instances */
        $Instances = (new TableLocator())->get('WireguardInstances');

        $conditions = ['wireguard_server_id' => $serverId];
        if ($excludeIds) {
            $conditions['id NOT IN'] = $excludeIds;
        }

        if ($family === 'ipv4') {
            $q = $Instances->find()
                ->select(['ipv4_enabled','ipv4_address','ipv4_mask'])
                ->where($conditions)
                ->andWhere(['ipv4_enabled' => 1])
                ->andWhere(['ipv4_address IS NOT' => null, 'ipv4_mask IS NOT' => null]);
            $rows = $q->all();
            $list = [];
            foreach ($rows as $r) {
                $cidr = "{$r->ipv4_address}/{$r->ipv4_mask}";
                // normalize to network (in case stored address isn't already the network)
                [$addrBin, $pfx, $bits] = $this->parseCidr($cidr);
                $net = $this->inetNtop($this->networkAddress($addrBin, $pfx), $bits);
                $list[] = "{$net}/{$pfx}";
            }
            return array_values(array_unique($list));
        } else {
            $q = $Instances->find()
                ->select(['ipv6_enabled','ipv6_address','ipv6_prefix'])
                ->where($conditions)
                ->andWhere(['ipv6_enabled' => 1])
                ->andWhere(['ipv6_address IS NOT' => null, 'ipv6_prefix IS NOT' => null]);
            $rows = $q->all();
            $list = [];
            foreach ($rows as $r) {
                $cidr = "{$r->ipv6_address}/{$r->ipv6_prefix}";
                [$addrBin, $pfx, $bits] = $this->parseCidr($cidr);
                $net = $this->inetNtop($this->networkAddress($addrBin, $pfx), $bits);
                $list[] = "{$net}/{$pfx}";
            }
            return array_values(array_unique($list));
        }
    }

    /**
     * Return true if two CIDRs overlap (same family).
     */
    private function cidrOverlaps(string $a, string $b): bool
    {
        [$ab, $ap, $abits] = $this->parseCidr($a);
        [$bb, $bp, $bbits] = $this->parseCidr($b);
        if ($abits !== $bbits) {
            return false; // families differ
        }
        $m = min($ap, $bp);
        $an = $this->networkAddress($ab, $m);
        $bn = $this->networkAddress($bb, $m);
        return $this->compareBigEndian($an, $bn) === 0;
    }

    /**
     * Is CIDR A entirely inside CIDR B?
     */
    private function subnetWithin(string $a, string $b): bool
    {
        [$ab, $ap, $abits] = $this->parseCidr($a);
        [$bb, $bp, $bbits] = $this->parseCidr($b);
        if ($abits !== $bbits) { return false; }
        if ($ap < $bp) { return false; } // child must be narrower or equal
        $bn = $this->networkAddress($bb, $bp);
        $an = $this->networkAddress($ab, $bp);
        return $this->compareBigEndian($an, $bn) === 0;
    }

    /**
     * Compute the next subnet for a given CIDR (IPv4 or IPv6).
     * Example:
     *   nextSubnet("10.12.0.0/24") -> "10.12.1.0/24"
     *   nextSubnet("fd00:12::/64") -> "fd00:12::1:0/64"
     */
    public function nextSubnet(string $cidr): string
    {
        [$addrBin, $prefix, $bits] = $this->parseCidr($cidr);
        $net = $this->networkAddress($addrBin, $prefix);

        $block = $this->blockSizeBytes($bits, $prefix);
        $next  = $this->addBigEndian($net, $block);

        // Overflow check: if adding block wrapped around (i.e., smaller than net)
        if ($this->compareBigEndian($next, $net) < 0) {
            throw new InvalidArgumentException("Subnet overflow: no next /$prefix available after {$this->inetNtop($net, $bits)}");
        }

        return $this->inetNtop($next, $bits) . '/' . $prefix;
    }

    /**
     * Convenience wrappers that enforce family.
     */
    public function nextSubnetV4(string $cidr): string
    {
        [$addrBin, $prefix, $bits] = $this->parseCidr($cidr);
        if ($bits !== 32) {
            throw new InvalidArgumentException("Expected IPv4 CIDR, got: {$cidr}");
        }
        return $this->nextSubnet($cidr);
    }

    public function nextSubnetV6(string $cidr): string
    {
        [$addrBin, $prefix, $bits] = $this->parseCidr($cidr);
        if ($bits !== 128) {
            throw new InvalidArgumentException("Expected IPv6 CIDR, got: {$cidr}");
        }
        return $this->nextSubnet($cidr);
    }

    /**
     * Optional helpers if you ever need "next IP" within the address space
     * (not bounded by a subnet).
     */
    public function nextIp(string $ip): string
    {
        $addrBin = @inet_pton($ip);
        if ($addrBin === false) {
            throw new InvalidArgumentException("Invalid IP: {$ip}");
        }
        $bits = strlen($addrBin) === 4 ? 32 : (strlen($addrBin) === 16 ? 128 : 0);
        if ($bits === 0) {
            throw new InvalidArgumentException("Unknown address length for: {$ip}");
        }

        $one  = $this->intToBytes(1, $bits);
        $next = $this->addBigEndian($addrBin, $one);

        // Overflow check
        if ($this->compareBigEndian($next, $addrBin) < 0) {
            throw new InvalidArgumentException("IP overflow: {$ip} has no next address.");
        }
        return $this->inetNtop($next, $bits);
    }

    public function prevIp(string $ip): string
    {
        $addrBin = @inet_pton($ip);
        if ($addrBin === false) {
            throw new InvalidArgumentException("Invalid IP: {$ip}");
        }
        $bits = strlen($addrBin) === 4 ? 32 : (strlen($addrBin) === 16 ? 128 : 0);
        if ($bits === 0) {
            throw new InvalidArgumentException("Unknown address length for: {$ip}");
        }

        $one  = $this->intToBytes(1, $bits);
        $prev = $this->subBigEndian($addrBin, $one);

        // Underflow check
        if ($this->compareBigEndian($prev, $addrBin) > 0) {
            throw new InvalidArgumentException("IP underflow: {$ip} has no previous address.");
        }
        return $this->inetNtop($prev, $bits);
    }

    // -----------------------
    // Internal helpers
    // -----------------------

    /**
     * @return array{0:string,1:int,2:int} [addrBin, prefixLen, totalBits]
     */
    private function parseCidr(string $cidr): array
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("CIDR must be in 'addr/prefix' form. Got: {$cidr}");
        }
        [$addr, $prefixStr] = $parts;

        $addrBin = @inet_pton($addr);
        if ($addrBin === false) {
            throw new InvalidArgumentException("Invalid IP in CIDR: {$addr}");
        }
        $bits = strlen($addrBin) === 4 ? 32 : (strlen($addrBin) === 16 ? 128 : 0);
        if ($bits === 0) {
            throw new InvalidArgumentException("Unknown IP length in CIDR: {$cidr}");
        }

        if (!ctype_digit($prefixStr)) {
            throw new InvalidArgumentException("Prefix must be integer. Got: {$prefixStr}");
        }
        $prefix = (int)$prefixStr;
        $max    = $bits;
        if ($prefix < 0 || $prefix > $max) {
            throw new InvalidArgumentException("Prefix /{$prefix} out of range for {$bits}-bit address.");
        }

        return [$addrBin, $prefix, $bits];
    }

    /**
     * Apply a prefix mask to an address (network address).
     */
    private function networkAddress(string $addrBin, int $prefix): string
    {
        $bits  = strlen($addrBin) * 8;
        $bytes = strlen($addrBin);

        if ($prefix === 0) {
            return str_repeat("\x00", $bytes);
        }
        if ($prefix === $bits) {
            return $addrBin;
        }

        $fullBytes   = intdiv($prefix, 8);
        $remaining   = $prefix % 8;

        $out = '';
        if ($fullBytes > 0) {
            $out .= substr($addrBin, 0, $fullBytes);
        }

        if ($remaining > 0) {
            $mask = (~((1 << (8 - $remaining)) - 1)) & 0xFF;
            $byte = ord($addrBin[$fullBytes]) & $mask;
            $out .= chr($byte);
            $fullBytes++;
        }

        if ($fullBytes < $bytes) {
            $out .= str_repeat("\x00", $bytes - $fullBytes);
        }

        return $out;
    }

    /**
     * Block size in bytes for (bits,prefix), as a binary big-endian string.
     * e.g., IPv4 /24 -> 256 -> "\x00\x00\x01\x00"
     */
    private function blockSizeBytes(int $bits, int $prefix): string
    {
        $hostBits = $bits - $prefix;
        if ($hostBits < 0) {
            throw new InvalidArgumentException("Invalid prefix: {$prefix} for {$bits}-bit space.");
        }
        // 2^(hostBits)
        // Build as big-endian byte string of length bits/8
        $len  = intdiv($bits, 8);
        $out  = array_fill(0, $len, 0);

        // set bit hostBits at position (counted from LSB)
        // Equivalent to 1 << hostBits as big int.
        // We'll add 1 at byte index from the end.
        // But hostBits might be >= 8; so place a single '1' followed by hostBits zeros in binary value = 2^(hostBits)
        // Implementation: since 2^n has a single 1-bit, we can set the corresponding byte.
        $bitIndexFromRight = $hostBits; // 0 means value 1
        $byteIndexFromRight = intdiv($bitIndexFromRight, 8);
        $bitInByte          = $bitIndexFromRight % 8;

        $targetIndex = $len - 1 - $byteIndexFromRight;
        if ($targetIndex < 0) {
            // For hostBits >= bits, this would overflow; but that can't happen because hostBits <= bits
            throw new InvalidArgumentException("Block size overflow.");
        }
        $out[$targetIndex] = 1 << $bitInByte;

        // Pack into bytes
        return pack('C*', ...$out);
    }

    /**
     * Add two big-endian binary strings (same length).
     */
    private function addBigEndian(string $a, string $b): string
    {
        $len = strlen($a);
        if ($len !== strlen($b)) {
            throw new InvalidArgumentException('Mismatched lengths for big-endian addition.');
        }
        $carry = 0;
        $out = str_repeat("\x00", $len);
        for ($i = $len - 1; $i >= 0; $i--) {
            $sum = ord($a[$i]) + ord($b[$i]) + $carry;
            $out[$i] = chr($sum & 0xFF);
            $carry = $sum >> 8;
        }
        // We intentionally drop overflow carry; caller should check wrap via compare
        return $out;
    }

    /**
     * Subtract b from a (big-endian). Assumes a >= b.
     */
    private function subBigEndian(string $a, string $b): string
    {
        $len = strlen($a);
        if ($len !== strlen($b)) {
            throw new InvalidArgumentException('Mismatched lengths for big-endian subtraction.');
        }
        $borrow = 0;
        $out = str_repeat("\x00", $len);
        for ($i = $len - 1; $i >= 0; $i--) {
            $ai = ord($a[$i]);
            $bi = ord($b[$i]) + $borrow;
            if ($ai >= $bi) {
                $out[$i] = chr($ai - $bi);
                $borrow = 0;
            } else {
                $out[$i] = chr(($ai + 256) - $bi);
                $borrow = 1;
            }
        }
        return $out;
    }

    /**
     * Compare big-endian binary strings: returns -1, 0, 1.
     */
    private function compareBigEndian(string $a, string $b): int
    {
        $len = strlen($a);
        if ($len !== strlen($b)) {
            throw new InvalidArgumentException('Mismatched lengths for compare.');
        }
        for ($i = 0; $i < $len; $i++) {
            $ai = ord($a[$i]);
            $bi = ord($b[$i]);
            if ($ai === $bi) {
                continue;
            }
            return ($ai < $bi) ? -1 : 1;
        }
        return 0;
    }

    /**
     * Convert integer (small) to big-endian bytes equal to address length.
     */
    private function intToBytes(int $value, int $bits): string
    {
        $len = intdiv($bits, 8);
        $out = str_repeat("\x00", $len);
        $i = $len - 1;
        $v = $value;
        while ($v > 0 && $i >= 0) {
            $out[$i] = chr($v & 0xFF);
            $v >>= 8;
            $i--;
        }
        return $out;
    }

    private function inetNtop(string $bin, int $bits): string
    {
        $s = @inet_ntop($bin);
        if ($s === false) {
            throw new InvalidArgumentException("inet_ntop failed for {$bits}-bit address.");
        }
        return $s;
    }
}




