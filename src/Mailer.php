<?php
namespace WakeOnStorage;

class Mailer
{
    /**
     * Send an email using a very small SMTP client. Returns true on success.
     * $cfg keys: host, port, secure (ssl|tls|null), username, password,
     * novalid_cert (bool), bcc.
     */
    public static function send(array $cfg, string $fromName, string $fromEmail,
        string $to, string $subject, string $body): bool
    {
        $host = $cfg['host'] ?? 'localhost';
        $port = (int)($cfg['port'] ?? 25);
        $secure = $cfg['secure'] ?? '';
        $username = $cfg['username'] ?? null;
        $password = $cfg['password'] ?? null;
        $allowSelfSigned = !empty($cfg['novalid_cert']);
        $bcc = $cfg['bcc'] ?? null;

        $contextOpts = [];
        if ($allowSelfSigned) {
            $contextOpts['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ];
        }
        $transport = $secure === 'ssl' ? 'ssl://' . $host : $host;
        $fp = @stream_socket_client(
            $transport . ':' . $port,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $contextOpts ? stream_context_create($contextOpts) : null
        );
        if (!$fp) {
            return false;
        }
        $read = function() use ($fp) {
            $data = '';
            while (!feof($fp)) {
                $line = fgets($fp, 515);
                $data .= $line;
                if (strlen($line) < 4 || $line[3] != '-') break;
                if (preg_match('/^\d{3} /', $line)) break;
            }
            return $data;
        };
        $write = function($cmd) use ($fp) {
            fwrite($fp, $cmd . "\r\n");
        };
        $read();
        $write('EHLO localhost');
        $read();
        if ($secure === 'tls') {
            $write('STARTTLS');
            $read();
            stream_socket_enable_crypto(
                $fp,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            $write('EHLO localhost');
            $read();
        }
        if ($username && $password) {
            $write('AUTH LOGIN');
            $read();
            $write(base64_encode($username));
            $read();
            $write(base64_encode($password));
            $read();
        }
        $write('MAIL FROM:<' . $fromEmail . '>');
        $read();
        $write('RCPT TO:<' . $to . '>');
        $read();
        if ($bcc) {
            $write('RCPT TO:<' . $bcc . '>');
            $read();
        }
        $write('DATA');
        $read();
        $headers = "From: " . $fromName . " <" . $fromEmail . ">\r\n" .
                   "To: <" . $to . ">\r\n" .
                   "Subject: " . $subject . "\r\n" .
                   "MIME-Version: 1.0\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n";
        if ($bcc) {
            $headers .= "Bcc: <" . $bcc . ">\r\n";
        }
        $msg = $headers . "\r\n" . $body . "\r\n.";
        $write($msg);
        $read();
        $write('QUIT');
        fclose($fp);
        return true;
    }
}
