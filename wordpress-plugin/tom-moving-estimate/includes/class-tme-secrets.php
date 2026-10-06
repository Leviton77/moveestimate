<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TME_Secrets
{
    private static function key(): string
    {
        return hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    }

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = self::key();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
            return 'sodium:' . base64_encode($nonce . $ciphertext);
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($ciphertext !== false) {
                return 'openssl:' . base64_encode($iv . $tag . $ciphertext);
            }
        }

        return '';
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        $key = self::key();
        if (str_starts_with($stored, 'sodium:') && function_exists('sodium_crypto_secretbox_open')) {
            $decoded = base64_decode(substr($stored, 7), true);
            if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return '';
            }
            $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
            return $plaintext === false ? '' : $plaintext;
        }

        if (str_starts_with($stored, 'openssl:') && function_exists('openssl_decrypt')) {
            $decoded = base64_decode(substr($stored, 8), true);
            if ($decoded === false || strlen($decoded) <= 28) {
                return '';
            }
            $iv = substr($decoded, 0, 12);
            $tag = substr($decoded, 12, 16);
            $ciphertext = substr($decoded, 28);
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plaintext === false ? '' : $plaintext;
        }

        return '';
    }
}

