<?php
declare(strict_types=1);

namespace App;

/**
 * Симметричное шифрование значений в БД: libsodium secretbox (XSalsa20-Poly1305).
 * Формат: "v1:" . base64(nonce . ciphertext). Poly1305 ловит любую подмену данных.
 *
 * Ключ: 32 случайных байта в base64, генерирует bin/generate_key.php.
 * Храните его вне базы (переменная окружения APP_ENCRYPTION_KEY): утечка дампа БД без ключа
 * не раскрывает зашифрованные поля.
 */
final class Crypto
{
    private const PREFIX = 'v1:';

    private string $key;

    public function __construct(string $base64Key)
    {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('PHP extension "sodium" is required for encryption');
        }
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Encryption key must be 32 bytes in base64 (run bin/generate_key.php)');
        }
        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new \RuntimeException('Unknown ciphertext format');
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Corrupted ciphertext');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed: wrong key or tampered data');
        }
        return $plain;
    }

    public function __destruct()
    {
        sodium_memzero($this->key);
    }
}
