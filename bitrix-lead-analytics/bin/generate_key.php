<?php
declare(strict_types=1);

/**
 * Печатает новый ключ шифрования для APP_ENCRYPTION_KEY (config.php → security.encryption_key).
 *
 *   php bin/generate_key.php
 *
 * Смена ключа делает уже зашифрованные поля нечитаемыми: после смены запустите
 * php bin/sync_leads.php --full, он перезапишет их новым ключом.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

echo App\Crypto::generateKey(), "\n";
