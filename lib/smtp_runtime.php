<?php
declare(strict_types=1);

function smtp_is_configured(array $config): bool
{
    $host = trim((string) ($config['smtp_host'] ?? $config['host'] ?? ''));
    $port = (int) ($config['smtp_port'] ?? $config['port'] ?? 0);
    $username = trim((string) ($config['smtp_username'] ?? $config['username'] ?? ''));
    $password = (string) ($config['smtp_password'] ?? $config['password'] ?? '');
    $encryption = strtolower(trim((string) ($config['smtp_encryption'] ?? $config['encryption'] ?? 'ssl')));

    // A partially filled form must never be treated as an operational mail
    // service. Callers use this check to decide whether password recovery and
    // security notifications can be offered.
    return $host !== ''
        && $port >= 1
        && $port <= 65535
        && $username !== ''
        && $password !== ''
        && in_array($encryption, ['ssl', 'tls'], true);
}

function smtp_send_message(array $config, string $recipient, string $subject, string $body, bool $html = false): void
{
    $host = trim((string) ($config['smtp_host'] ?? $config['host'] ?? ''));
    $port = (int) ($config['smtp_port'] ?? $config['port'] ?? 0);
    $user = trim((string) ($config['smtp_username'] ?? $config['username'] ?? ''));
    $password = (string) ($config['smtp_password'] ?? $config['password'] ?? '');
    $encryption = (string) ($config['smtp_encryption'] ?? $config['encryption'] ?? 'ssl');
    if ($host === '' || $port < 1 || $port > 65535 || $user === '' || $password === '' || !in_array($encryption, ['ssl', 'tls'], true) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('SMTP 配置或收件人邮箱无效。');
    }

    $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($target, $errno, $error, 10, STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) throw new RuntimeException('无法连接 SMTP 服务器：' . $error);
    stream_set_timeout($socket, 10);
    $read = static function () use ($socket): string {
        $line = '';
        do {
            $part = fgets($socket, 1024);
            if ($part === false) throw new RuntimeException('SMTP 服务器无响应。');
            $line .= $part;
        } while (isset($part[3]) && $part[3] === '-');
        return $line;
    };
    $command = static function (string $line, array $codes) use ($socket, $read): void {
        fwrite($socket, $line . "\r\n");
        $reply = $read();
        if (!in_array((int) substr($reply, 0, 3), $codes, true)) throw new RuntimeException('SMTP 错误：' . trim($reply));
    };
    try {
        $hello = $read();
        if ((int) substr($hello, 0, 3) !== 220) throw new RuntimeException('SMTP 握手失败。');
        $command('EHLO picapi.local', [250]);
        if ($encryption === 'tls') {
            $command('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP TLS 启动失败。');
            $command('EHLO picapi.local', [250]);
        }
        $command('AUTH LOGIN', [334]);
        $command(base64_encode($user), [334]);
        $command(base64_encode($password), [235]);
        $command('MAIL FROM:<' . $user . '>', [250]);
        $command('RCPT TO:<' . $recipient . '>', [250, 251]);
        $command('DATA', [354]);
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $safeBody = str_replace("\n.", "\n..", str_replace("\r\n", "\n", $body));
        $contentType = $html ? 'text/html' : 'text/plain';
        $message = "Subject: {$encodedSubject}\r\nTo: {$recipient}\r\nFrom: {$user}\r\nMIME-Version: 1.0\r\nContent-Type: {$contentType}; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . str_replace("\n", "\r\n", $safeBody);
        $command($message . "\r\n.", [250]);
        fwrite($socket, "QUIT\r\n");
    } finally {
        fclose($socket);
    }
}

function smtp_test_message(array $config, string $recipient): void
{
    smtp_send_message($config, $recipient, 'PicAPI SMTP Test', 'PicAPI 邮件配置验证成功。');
}
