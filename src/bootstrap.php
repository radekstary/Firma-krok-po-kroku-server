<?php

declare(strict_types=1);

/**
 * Wspolny bootstrap: autoloader klas `Firma\` (PSR-4 wzgledem katalogu src/) oraz — jesli jest —
 * autoloader composera z vendor/ (firebase/php-jwt). Vendor moze nie istniec w fazie 1 (szkielet
 * bez zaleznosci), wiec ladowanie jest warunkowe.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Firma\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}
