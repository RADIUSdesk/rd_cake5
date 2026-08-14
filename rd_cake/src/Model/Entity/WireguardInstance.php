<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class WireguardInstance extends Entity
{
    /**
     * Mass-assignment
     */
    protected array $_accessible = [
        '*'  => true,
        'id' => false,
    ];

    /**
     * Hide secrets from JSON (Not in our case)
     */
    protected array $_hidden = [
      //  'private_key',
      //  'preshared_key',
    ];

    /**
     * Auto-generate keys when fields are set to '' (empty string) or null.
     * - private_key: generates private+public, and sets public_key on the entity
     * - preshared_key: generates random PSK (base64 44 chars)
     *
     * Tip: When creating/saving, pass '' to trigger a fresh value.
     */

    protected function _setPrivateKey(?string $value): ?string
    {
        if ($value === '' || $value === null) {
            [$priv, $pub] = self::generateKeypair();
            // Also set the public key on the entity
            $this->set('public_key', $pub);
            return $priv;
        }
        return $value;
    }

    protected function _setPresharedKey(?string $value): ?string
    {
        // If you want PSK optional, allow NULL; if you want always present, generate on ''/NULL
        if ($value === '' || $value === null) {
            return self::generatePresharedKey();
        }
        return $value;
    }

    /**
     * ===== Keygen helpers (libsodium preferred) =====
     */
    private static function generateKeypair(): array
    {
        if (extension_loaded('sodium')) {
            // Generate 32-byte random private key and clamp for X25519 (RFC 7748)
            $sk = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES); // 32
            $sk = self::x25519Clamp($sk);
            $pk = sodium_crypto_scalarmult_base($sk);

            // WireGuard uses standard base64 with '=' padding; 32 bytes => 44 chars
            $priv = sodium_bin2base64($sk, SODIUM_BASE64_VARIANT_ORIGINAL);
            $pub  = sodium_bin2base64($pk, SODIUM_BASE64_VARIANT_ORIGINAL);
            return [$priv, $pub];
        }

        // ----- Fallback via `wg` binary (optional) -----
        $priv = trim(shell_exec('wg genkey 2>/dev/null') ?? '');
        $pub  = '';
        if ($priv !== '') {
            // pipe private key to wg pubkey
            $pub = trim(shell_exec('echo ' . escapeshellarg($priv) . ' | wg pubkey 2>/dev/null') ?? '');
        }
        if ($priv === '' || $pub === '') {
            throw new \RuntimeException('WireGuard key generation failed (libsodium not loaded and `wg` not found).');
        }
        return [$priv, $pub];
    }

    private static function generatePresharedKey(): string
    {
        if (extension_loaded('sodium')) {
            $pskRaw = random_bytes(32);
            return sodium_bin2base64($pskRaw, SODIUM_BASE64_VARIANT_ORIGINAL); // 44 chars
        }
        $psk = trim(shell_exec('wg genpsk 2>/dev/null') ?? '');
        if ($psk === '') {
            throw new \RuntimeException('WireGuard PSK generation failed (libsodium not loaded and `wg` not found).');
        }
        return $psk;
    }

    private static function x25519Clamp(string $sk): string
    {
        // Clamp per RFC 7748 (X25519)
        $bytes = array_values(unpack('C*', $sk));
        $bytes[0]  &= 248;
        $bytes[31] &= 127;
        $bytes[31] |= 64;
        return pack('C*', ...$bytes);
    }
}
