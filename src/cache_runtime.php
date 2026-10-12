<?php
declare(strict_types=1);
require_once __DIR__ . '/app.php';

/** Small RESP client so optional Redis does not require a PHP extension. */
function redis_command(array $config, array $arguments): mixed
{
    $host = trim((string) ($config['host'] ?? '127.0.0.1'));
    if ($host === '' || preg_match('/[\s\x00-\x1f\/]/', $host)) throw new InvalidArgumentException('Redis 主机地址无效。');
    $address = parse_url('tcp://' . $host);
    if (!is_array($address) || empty($address['host']) || isset($address['user']) || isset($address['pass'])) throw new InvalidArgumentException('Redis 主机地址无效。');
    $port = $address['port'] ?? 6379;
    $socket = @stream_socket_client('tcp://' . $address['host'] . ':' . $port, $errno, $error, 1);
    if ($socket === false) throw new RuntimeException('Redis 连接失败，请检查主机地址与端口。');
    stream_set_timeout($socket, 1);
    $exchange = static function (array $values) use ($socket): mixed {
        $request = '*' . count($values) . "\r\n";
        foreach ($values as $value) { $value = (string) $value; $request .= '$' . strlen($value) . "\r\n" . $value . "\r\n"; }
        $offset = 0;
        while ($offset < strlen($request)) {
            $written = fwrite($socket, substr($request, $offset));
            if (!$written) throw new RuntimeException('Redis 写入失败。');
            $offset += $written;
        }
        $line = fgets($socket, 4096);
        if ($line === false) throw new RuntimeException('Redis 未响应。');
        $value = substr($line, 1, -2);
        if ($line[0] === '+') return $value;
        if ($line[0] === ':') return (int) $value;
        if ($line[0] === '$') {
            $length = (int) $value;
            if ($length === -1) return null;
            if ($length < 0 || $length > 32 * 1048576) throw new RuntimeException('Redis 响应大小超出限制。');
            $data = '';
            while (strlen($data) < $length + 2) {
                $chunk = fread($socket, min(65536, $length + 2 - strlen($data)));
                if ($chunk === false || $chunk === '') throw new RuntimeException('Redis 响应不完整。');
                $data .= $chunk;
            }
            return substr($data, 0, $length);
        }
        throw new RuntimeException('Redis 命令失败，请检查认证与服务状态。');
    };
    try {
        if (($config['password'] ?? '') !== '') $exchange(['AUTH', $config['password']]);
        return $exchange($arguments);
    } finally { fclose($socket); }
}

function test_setup_redis(array $input): void
{
    if (empty($input['redis_enabled'])) return;
    $reply = redis_command(['host'=>$input['redis_host']??'127.0.0.1','password'=>$input['redis_password']??''], ['PING']);
    if ($reply !== 'PONG') throw new RuntimeException('Redis 测试失败。');
}

function image_redis_cache(string $operation, ?array $index = null): ?array
{
    static $failed = false;
    $config = installation_config()['redis'] ?? [];
    if ($failed || empty($config['enabled'])) return null;
    $key = 'picapi:' . hash('sha256', APP_ROOT) . ':image-index';
    try {
        if ($operation === 'get') {
            $value = redis_command($config, ['GET',$key]);
            $decoded = is_string($value) ? json_decode($value,true) : null;
            return is_array($decoded) ? $decoded : null;
        }
        if ($operation === 'set') redis_command($config,['SET',$key,json_encode($index,JSON_THROW_ON_ERROR), 'EX',30]);
        if ($operation === 'delete') redis_command($config,['DEL',$key]);
    } catch (Throwable) { $failed = true; }
    return null;
}
