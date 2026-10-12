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

/** SSL context options for SMTP connections. */
function smtp_ssl_context_options(): array
{
    return [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
    ];
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

    $context = stream_context_create(['ssl' => smtp_ssl_context_options()]);
    $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($target, $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
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
        // Keep every MIME line well below RFC 5321's 998-octet limit. A raw
        // HTML template can contain very long minified lines, so sending it
        // as 8bit text makes otherwise valid messages get rejected by strict
        // SMTP servers. Base64 MIME transfer wraps the payload at 76 chars.
        $subjectWords = [];
        $subjectBytes = base64_encode($subject);
        foreach (str_split($subjectBytes, 45) as $chunk) {
            $subjectWords[] = '=?UTF-8?B?' . $chunk . '?=';
        }
        $encodedSubject = implode("\r\n ", $subjectWords);
        $encodedBody = chunk_split(base64_encode($body), 76, "\r\n");
        $contentType = $html ? 'text/html' : 'text/plain';
        $message = "Subject: {$encodedSubject}\r\nTo: {$recipient}\r\nFrom: {$user}\r\nMIME-Version: 1.0\r\nContent-Type: {$contentType}; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $encodedBody;
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
