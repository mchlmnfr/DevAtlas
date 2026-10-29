<?php

/**
 * Filename: Crypto.php
 * Purpose: Provide crypto processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/**
 * DevAtlas :: Crypto.php
 * Application-level encryption for the Server Credentials Vault, using
 * AES-256-CBC with a random IV per value. This protects stored passwords
 * against a raw database dump/leak; it is NOT a substitute for a proper
 * secrets manager (HashiCorp Vault, AWS Secrets Manager, etc.) — for a
 * local-first single-database tool, this is the practical middle ground.
 *
 * Key comes from config.php's APP_ENCRYPTION_KEY. Losing/changing that
 * key makes previously-encrypted credentials permanently undecryptable —
 * back it up somewhere safe, separate from the database itself.
 */

class Crypto
{
    private const CIPHER = 'aes-256-cbc';

    private static function Key(): string
    {
        // Stretch the configured secret into a proper 256-bit key regardless
        // of the raw string's length, via a one-way hash (not reversible).
        return hash('sha256', APP_ENCRYPTION_KEY, true);
    }

    public static function Encrypt(string $plaintext): string
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::Key(), OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false)
        {
            throw new RuntimeException('Encryption failed.');
        }
        // Store IV alongside the ciphertext (IV isn't secret, just needs to be unique per value).
        return base64_encode($iv . $ciphertext);
    }

    public static function Decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false)
        {
            throw new RuntimeException('Stored value is not valid — cannot decrypt.');
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($raw, 0, $ivLength);
        $ciphertext = substr($raw, $ivLength);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::Key(), OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false)
        {
            throw new RuntimeException('Decryption failed — the encryption key may have changed since this was stored.');
        }
        return $plaintext;
    }
}
