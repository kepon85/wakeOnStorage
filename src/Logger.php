<?php
namespace WakeOnStorage;

use PDO;

class Logger
{
    public static function logEvent(PDO $pdo, string $host, string $action, ?string $user = null): void
    {
        $stmt = $pdo->prepare("INSERT INTO events (host, action, user, ip) VALUES (?,?,?,?)");
        $stmt->execute([$host, $action, $user, $_SERVER['REMOTE_ADDR'] ?? '']);
    }
}
