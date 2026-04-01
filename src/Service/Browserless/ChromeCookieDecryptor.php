<?php

declare(strict_types=1);

namespace WsFramework\Service\Browserless;

/**
 * Decrypts Chrome cookie values from SQLite storage.
 *
 * Chrome on Linux (without keyring) uses v10 encryption:
 * AES-128-CBC with PBKDF2('peanuts', 'saltysalt', iterations=1, keylen=16)
 * IV: 16 bytes of 0x20 (space character)
 */
class ChromeCookieDecryptor
{
    private readonly string $key;

    public function __construct()
    {
        $this->key = hash_pbkdf2('sha1', 'peanuts', 'saltysalt', 1, 16, true);
    }

    /**
     * @param string $encryptedValue Raw encrypted_value from Chrome Cookies SQLite
     * @return string Decrypted cookie value, or empty string on failure
     */
    public function decrypt(string $encryptedValue): string
    {
        if (\strlen($encryptedValue) <= 3) {
            return '';
        }

        $prefix = substr($encryptedValue, 0, 3);
        if ($prefix !== 'v10') {
            return $encryptedValue;
        }

        $ciphertext = substr($encryptedValue, 3);
        $iv = str_repeat(' ', 16);

        $decrypted = openssl_decrypt($ciphertext, 'aes-128-cbc', $this->key, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false ? $decrypted : '';
    }
}
