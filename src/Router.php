<?php
namespace WakeOnStorage;

class Router
{
    public static function ping(string $host, int $count = 1, int $timeout = 1): bool
    {
        if (!$host) {
            return false;
        }
        $timeout = max(1, $timeout);
        $cmd = sprintf('ping -c %d -W %d %s 2>&1', $count, $timeout, escapeshellarg($host));
        exec($cmd, $out, $code);
        return $code === 0;
    }

    public static function nextSchedule(array $times): ?string
    {
        if (!$times) {
            return null;
        }
        $now = time();
        $today = date('Y-m-d ');
        $next = null;
        foreach ($times as $t) {
            $ts = strtotime($today . $t);
            if ($ts === false) {
                continue;
            }
            if ($ts > $now) {
                $next = $ts;
                break;
            }
        }
        if ($next === null) {
            $ts = strtotime('+1 day ' . $times[0]);
            if ($ts !== false) {
                $next = $ts;
            }
        }
        return $next ? date('H:i', $next) : null;
    }
}
