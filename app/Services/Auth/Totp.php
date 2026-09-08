<?php

namespace App\Services\Auth;

/**
 * Time-based one-time passwords (RFC 6238 / RFC 4226), dependency-free.
 *
 * Standard authenticator apps (Google Authenticator, 1Password, Authy, …)
 * generate the same 6-digit codes from the shared Base32 secret, so this
 * interoperates with any of them via the otpauth:// provisioning URI.
 */
class Totp
{
    private const PERIOD = 30;   // seconds per code
    private const DIGITS = 6;
    private const ALGO = 'sha1'; // the near-universal authenticator default

    /** Generate a new Base32 secret (default 160 bits, the RFC-recommended size). */
    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    /**
     * Verify a code against the secret, allowing a ±$window step drift so a
     * clock a little out of sync still works.
     */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = (int) floor(time() / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->codeAt($secret, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    /** The otpauth:// URI an authenticator app scans or imports. */
    public function otpauthUri(string $secret, string $account, string $issuer = 'Spi'): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGO),
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);

        return "otpauth://totp/{$label}?{$params}";
    }

    /** The 6-digit code for a given time counter (HOTP truncation). */
    private function codeAt(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binCounter = pack('N*', 0).pack('N*', $counter); // 8-byte big-endian
        $hash = hash_hmac(self::ALGO, $binCounter, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($data) as $char) {
            $value = ($value << 8) | ord($char);
            $bits += 8;
            while ($bits >= 5) {
                $out .= $alphabet[($value >> ($bits - 5)) & 0x1F];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $out .= $alphabet[($value << (5 - $bits)) & 0x1F];
        }

        return $out;
    }

    private function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($b32) as $char) {
            $value = ($value << 5) | strpos($alphabet, $char);
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($value >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }

        return $out;
    }
}
