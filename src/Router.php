<?php
namespace WakeOnStorage;

class Router
{
    public static function ping(string $host, int $count = 1): bool
    {
        if (!$host) {
            return false;
        }
        $cmd = sprintf('ping -c %d %s 2>&1', $count, escapeshellarg($host));
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
