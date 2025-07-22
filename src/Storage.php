<?php
namespace WakeOnStorage;

class Storage
{
    /**
     * Perform an HTTP request and return array with 'body' and 'status'.
     */
    protected static function apiRequest(array $param)
    {
        $url = $param['url'] ?? '';
        if (!$url) {
            return false;
        }
        $method = strtoupper($param['method'] ?? 'GET');
        $headers = [];
        if (!empty($param['auth']['type'])) {
            if ($param['auth']['type'] === 'bearer' && !empty($param['auth']['tocken'])) {
                $headers[] = 'Authorization: Bearer ' . $param['auth']['tocken'];
            } elseif ($param['auth']['type'] === 'basic') {
                $user = $param['auth']['username'] ?? '';
                $pass = $param['auth']['password'] ?? '';
                $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
            }
        }
        if (!empty($param['headers'])) {
            foreach ($param['headers'] as $k => $v) {
                $headers[] = $k . ': ' . $v;
            }
        }
        $opts = [
            'http' => [
                'method'  => $method,
                'header'  => implode("\r\n", $headers),
                'timeout' => (int)($param['timeout'] ?? 5),
            ]
        ];
        if (!empty($param['body'])) {
            $opts['http']['content'] = $param['body'];
        }
        $context = stream_context_create($opts);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return false;
        }
        $status = 0;
        if (!empty($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
            $status = (int)$m[0];
        }
        return ['body' => $body, 'status' => $status];
    }

    /**
     * Check storage status. Returns 'up', 'down' or null if unknown.
     */
    public static function checkStatus(array $cfg): ?string
    {
        $method = $cfg['methode'] ?? '';
        $param  = $cfg['param'] ?? [];
        if ($method === 'port') {
            $host = $param['host'] ?? 'localhost';
            $port = (int)($param['port'] ?? 0);
            $timeout = (int)($param['timeout'] ?? 1);
            $fp = @fsockopen($host, $port, $eno, $estr, $timeout);
            if ($fp) {
                fclose($fp);
                return 'up';
            }
            return 'down';
        } elseif ($method === 'api') {
            $res = self::apiRequest($param);
            if ($res === false) {
                return null;
            }
            $json = json_decode($res['body'], true);
            if (is_array($json) && isset($json['status'])) {
                return $json['status'];
            }
            return null;
        }
        return null;
    }

    /**
     * Trigger an action (up/down) using API method.
     */
    public static function trigger(array $cfg): bool
    {
        $method = $cfg['methode'] ?? '';
        if ($method === 'api') {
            $param = $cfg['param'] ?? [];
            $res = self::apiRequest($param);
            if ($res === false) {
                return false;
            }
            $exp = $param['expected_result']['status_code'] ?? null;
            if ($exp && $res['status'] != $exp) {
                return false;
            }
            if (!empty($param['expected_result']['json'])) {
                $json = json_decode($res['body'], true);
                if ($json === null) {
                    return false;
                }
                $path = $param['expected_result']['json']['path'] ?? '';
                $expected = $param['expected_result']['json']['equals'] ?? null;
                if ($path !== '') {
                    $path = preg_replace('/^\$\.?/', '', $path);
                    $parts = explode('.', $path);
                    $val = $json;
                    foreach ($parts as $p) {
                        if (!is_array($val) || !array_key_exists($p, $val)) {
                            $val = null;
                            break;
                        }
                        $val = $val[$p];
                    }
                    if ($expected !== null && (string)$val !== (string)$expected) {
                        return false;
                    }
                }
            }
            return true;
        }
        return false;
    }
}
