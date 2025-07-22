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

        $now = new \DateTimeImmutable('now');
        $future = [];

        foreach ($times as $t) {
            // Build a date for today with the provided time
            $dt = \DateTimeImmutable::createFromFormat('H:i', trim($t));
            if (!$dt) {
                continue;
            }
            $candidate = $dt->setDate(
                (int)$now->format('Y'),
                (int)$now->format('m'),
                (int)$now->format('d')
            );
            if ($candidate <= $now) {
                $candidate = $candidate->modify('+1 day');
            }
            $future[] = $candidate;
        }

        if (!$future) {
            return null;
        }

        usort($future, function ($a, $b) {
            return $a <=> $b;
        });

        return $future[0]->format('H:i');
    }
}
