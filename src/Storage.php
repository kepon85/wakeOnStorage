<?php
namespace WakeOnStorage;

class Storage
{
    /**
     * Perform an HTTP request and return array with 'body' and 'status'.
     */
    protected static function apiRequest(array $param, bool $debug = false, ?array &$log = null)
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
        if ($debug && is_array($log)) {
            $log[] = 'REQ ' . $method . ' ' . $url;
            if (!empty($headers)) {
                $log[] = 'HEADERS: ' . implode('; ', $headers);
            }
            if (isset($opts['http']['content'])) {
                $log[] = 'BODY: ' . $opts['http']['content'];
            }
        }
        $context = stream_context_create($opts);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            if ($debug && is_array($log)) {
                $log[] = 'HTTP ERROR';
            }
            return false;
        }
        $status = 0;
        if (!empty($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
            $status = (int)$m[0];
        }
        if ($debug && is_array($log)) {
            $log[] = 'HTTP ' . $status;
            $log[] = 'RESP: ' . $body;
        }
        return ['body' => $body, 'status' => $status];
    }

    /**
     * Check storage status. Returns 'up', 'down' or null if unknown.
     */
    public static function checkStatus(array $cfg, bool $debug = false, ?array &$log = null): ?string
    {
        $method = $cfg['methode'] ?? '';
        $param  = $cfg['param'] ?? [];
        if ($method === 'port') {
            $host = $param['host'] ?? 'localhost';
            $port = (int)($param['port'] ?? 0);
            $timeout = (int)($param['timeout'] ?? 1);
            if ($debug && is_array($log)) {
                $log[] = "CHECK PORT {$host}:{$port}";
            }
            $fp = @fsockopen($host, $port, $eno, $estr, $timeout);
            if ($fp) {
                fclose($fp);
                if ($debug && is_array($log)) {
                    $log[] = 'PORT OPEN';
                }
                return 'up';
            }
            if ($debug && is_array($log)) {
                $log[] = 'PORT CLOSED';
            }
            return 'down';
        } elseif ($method === 'api') {
            $res = self::apiRequest($param, $debug, $log);
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
    public static function trigger(array $cfg, bool $debug = false, ?array &$log = null, ?array &$rawResponse = null): bool
    {
        $method = $cfg['methode'] ?? '';
        if ($method === 'api') {
            $param = $cfg['param'] ?? [];
            $res = self::apiRequest($param, $debug, $log);
            if ($res === false) {
                if ($debug && is_array($log)) {
                    $log[] = 'REQUEST FAILED';
                }
                return false;
            }
            $json = json_decode($res['body'], true);
            if (func_num_args() >= 4) {
                $rawResponse = $json;
            }
            $exp = $param['expected_result']['status_code'] ?? null;
            if ($exp && $res['status'] != $exp) {
                if ($debug && is_array($log)) {
                    $log[] = 'UNEXPECTED STATUS ' . $res['status'];
                }
                return false;
            }
            if (!empty($param['expected_result']['json'])) {
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
