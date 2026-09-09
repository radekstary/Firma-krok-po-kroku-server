<?php

declare(strict_types=1);

namespace Firma;

/**
 * Wyjatek niosacy kod HTTP i maszynowy kod bledu. Router lapie go i zamienia na odpowiedz JSON,
 * dzieki czemu logika (auth, walidacja, role) moze przerwac zadanie `throw new HttpError(...)`
 * bez recznego skladania odpowiedzi.
 */
final class HttpError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
