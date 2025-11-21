<?php
namespace WakeOnStorage;

class Auth
{
    public static function checkFile(string $path, string $user, string $pass): bool
    {
        if (!$path || !file_exists($path)) {
            return false;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($u, $p) = array_map('trim', explode(':', $line, 2));
                if ($u === $user) {
                    if (password_verify($pass, $p) || $p === $pass) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public static function checkImap(array $cfg, string $user, string $pass): bool
    {
        if (!extension_loaded('imap')) {
            return false;
        }
        $server = $cfg['imap']['server'] ?? 'localhost';
        $port = $cfg['imap']['port'] ?? 143;
        $secure = $cfg['imap']['secure'] ?? '';
        $mailbox = '{' . $server . ':' . $port;
        if ($secure === 'ssl') {
            $mailbox .= '/ssl';
        } elseif ($secure === 'tls') {
            $mailbox .= '/tls';
        }
        $mailbox .= '}INBOX';
        $imap = @imap_open($mailbox, $user, $pass);
        if ($imap) {
            imap_close($imap);
            return true;
        }
        return false;
    }

    public static function checkUniq(array $cfg, string $pass): bool
    {
        if (!empty($cfg['uniq']['password_hash'])) {
            return password_verify($pass, $cfg['uniq']['password_hash']);
        }
        return $pass === ($cfg['uniq']['password'] ?? '');
    }
}
