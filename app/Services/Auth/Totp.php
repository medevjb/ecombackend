<?php

namespace App\Services\Auth;

use SensitiveParameter;

class Totp
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function encodeSecret(#[SensitiveParameter] string $secret): string
    {
        $encoded = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($secret) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
            }

            $buffer &= (1 << $bits) - 1;
        }

        if ($bits > 0) {
            $encoded .= self::ALPHABET[$buffer << (5 - $bits)];
        }

        return $encoded;
    }

    public function code(#[SensitiveParameter] string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $secret, true);
        $offset = ord($hash[19]) & 15;
        $number = ((ord($hash[$offset]) & 127) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public function matchStep(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code, int $timestamp, ?int $lastUsedStep): ?int
    {
        $step = intdiv($timestamp, 30);

        foreach ([$step, $step - 1, $step + 1] as $candidate) {
            if ($candidate >= 0 && ($lastUsedStep === null || $candidate > $lastUsedStep)
                && hash_equals($this->code($secret, $candidate), $code)) {
                return $candidate;
            }
        }

        return null;
    }
}
