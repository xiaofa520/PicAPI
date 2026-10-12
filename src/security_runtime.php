<?php
declare(strict_types=1);

require_once __DIR__ . '/settings_runtime.php';

/** The versioned policy is opt-in on migration; old installations keep enforcing old bans. */
function security_policy_active(): bool
{
    return database_document('security-policy-v2') !== null;
}

function security_policy_defaults(): array
{
    return [
        'api' => ['enabled' => true, 'stages' => array_map(static fn(int $minutes): array => ['limit' => (int) site_settings()['request_threshold'], 'duration' => $minutes, 'unit' => 'minutes'], [10, 30, 120, 1440])],
        'login' => ['enabled' => true, 'stages' => array_map(static fn(int $minutes): array => ['limit' => 5, 'duration' => $minutes, 'unit' => 'minutes'], [5, 30, 120, 1440])],
    ];
}

function security_policy_configuration(): array
{
    return database_document('security-policy-v2') ?? security_policy_defaults();
}

function security_policy_save(string $scope, array $input): void
{
    if (!in_array($scope, ['api', 'login'], true)) throw new InvalidArgumentException('策略范围无效。');
    if (count($input['stages'] ?? []) !== 4) throw new InvalidArgumentException('请完整配置四级规则。');
    $stages = [];
    foreach (array_values($input['stages']) as $index => $stage) {
        $limit = filter_var($stage['limit'] ?? null, FILTER_VALIDATE_INT);
        $duration = filter_var($stage['duration'] ?? null, FILTER_VALIDATE_INT);
        $unit = (string) ($stage['unit'] ?? '');
        if ($limit === false || $limit < 1 || $limit > ($scope === 'login' ? 1000 : 1000000)
            || !in_array($unit, $index === 3 ? ['minutes', 'hours', 'days', 'forever'] : ['minutes', 'hours', 'days'], true)
            || ($unit !== 'forever' && ($duration === false || $duration < 1 || $duration > 10000))) {
            throw new InvalidArgumentException('次数、时长或单位无效，仅第 4 级可永久封禁。');
        }
        $stages[] = ['limit' => $limit, 'duration' => $unit === 'forever' ? 1 : $duration, 'unit' => $unit];
    }
    // Import existing lists before activating the new enforcement path.
    security_state(static fn(array &$state): bool => true);
    $policies = security_policy_configuration();
    $policies[$scope] = ['enabled' => !empty($input['enabled']), 'stages' => $stages];
    if (!save_database_document('security-policy-v2', $policies)) throw new RuntimeException('无法保存访问策略。');
}

function security_state(callable $callback): mixed
{
    if (runtime_state_database() === null) throw new RuntimeException('安全策略数据库不可用。');
    $initial = ['api' => ['allow' => [], 'block' => [], 'counters' => []], 'login' => ['allow' => [], 'block' => [], 'counters' => []]];
    $exists = runtime_state_database()->prepare('SELECT 1 FROM picapi_runtime_states WHERE state_key = ?');
    $exists->execute(['security-v2']);
    if ($exists->fetchColumn() === false) {
    // Import once using an atomic runtime-state initializer. Existing state always wins.
    $legacy = ip_access_control_lists();
    foreach ($legacy['whitelist'] as $entry) {
        foreach (['api', 'login'] as $scope) $initial[$scope]['allow'][$entry['ip']] = ['ip' => $entry['ip'], 'reason' => '迁移的可信地址', 'created' => $entry['added_at'], 'expires' => null];
    }
    foreach ($legacy['blacklist'] as $entry) {
        $scope = in_array($entry['reason'], ['login', 'manual_login'], true) ? 'login' : 'api';
        $initial[$scope]['block'][$entry['ip']] = ['ip' => $entry['ip'], 'reason' => $entry['reason'], 'created' => $entry['added_at'], 'expires' => $entry['permanent'] ? null : $entry['until']];
    }
    $initial['legacy_login'] = with_login_throttle(static fn(array &$data): array => $data);
    }
    return with_database_runtime_state('security-v2', $initial, static function (array &$state) use ($callback): mixed {
        foreach (['api', 'login'] as $scope) {
            foreach ($state[$scope]['block'] as $ip => $entry) {
                if ($entry['expires'] !== null && $entry['expires'] <= time()) unset($state[$scope]['block'][$ip]);
            }
            foreach ($state[$scope]['counters'] as $ip => $counter) {
                if (($counter['level'] ?? 0) === 0 && ($counter['last'] ?? $counter['start']) < time() - 86400) unset($state[$scope]['counters'][$ip]);
            }
        }
        foreach ($state['legacy_login'] ?? [] as $key => $entry) if (($entry['locked_until'] ?? 0) <= time()) unset($state['legacy_login'][$key]);
        return $callback($state);
    });
}

function security_entries(): array
{
    return security_state(static function (array &$state): array {
        $entries = [];
        foreach (['api', 'login'] as $scope) foreach (['allow', 'block'] as $kind) {
            foreach ($state[$scope][$kind] as $ip => $entry) $entries[] = $entry + ['scope' => $scope, 'kind' => $kind, 'id' => $scope . '|' . $kind . '|' . $ip];
        }
        return $entries;
    });
}

function security_entry_change(array $input, bool $remove = false): void
{
    $scope = (string) ($input['scope'] ?? ''); $kind = (string) ($input['kind'] ?? '');
    $packed = @inet_pton(trim((string) ($input['ip'] ?? '')));
    if (!in_array($scope, ['api', 'login'], true) || !in_array($kind, ['allow', 'block'], true) || $packed === false) throw new InvalidArgumentException('名单范围或 IP 地址无效。');
    $ip = inet_ntop($packed); $reason = trim((string) ($input['reason'] ?? ''));
    if (strlen($reason) > 240) throw new InvalidArgumentException('备注过长。');
    $unit = (string) ($input['unit'] ?? 'minutes'); $duration = filter_var($input['duration'] ?? 30, FILTER_VALIDATE_INT);
    if (!$remove && $kind === 'block' && (!in_array($unit, ['minutes', 'hours', 'days', 'forever'], true) || ($unit !== 'forever' && ($duration === false || $duration < 1 || $duration > 10000)))) throw new InvalidArgumentException('封禁时长无效。');
    security_state(static function (array &$state) use ($scope, $kind, $ip, $reason, $unit, $duration, $remove): void {
        if ($remove) { unset($state[$scope][$kind][$ip]); return; }
        if (isset($state[$scope]['allow'][$ip]) || isset($state[$scope]['block'][$ip])) throw new InvalidArgumentException('此 IP 已在当前范围的名单中。');
        $seconds = ['minutes' => 60, 'hours' => 3600, 'days' => 86400][$unit] ?? 0;
        $state[$scope][$kind][$ip] = ['ip' => $ip, 'reason' => $reason, 'created' => time(), 'expires' => $kind === 'allow' || $unit === 'forever' ? null : time() + $duration * $seconds];
    });
    if (!security_policy_active() && !save_database_document('security-policy-v2', security_policy_defaults())) throw new RuntimeException('无法启用安全策略。');
}

function security_status(string $ip, string $scope, bool $incident = false): array
{
    $ip = @inet_pton($ip) === false ? $ip : inet_ntop(inet_pton($ip));
    $policy = security_policy_configuration()[$scope];
    return security_state(static function (array &$state) use ($ip, $scope, $incident, $policy): array {
        $empty = ['blocked' => false, 'until' => 0, 'permanent' => false, 'reason' => '', 'whitelisted' => false, 'newly_blocked' => false, 'count' => 0];
        $data =& $state[$scope];
        if (isset($data['allow'][$ip])) return array_replace($empty, ['whitelisted' => true]);
        if ($scope === 'login' && ($state['legacy_login'][hash('sha256', $ip)]['locked_until'] ?? 0) > time()) {
            return array_replace($empty, ['blocked' => true, 'until' => $state['legacy_login'][hash('sha256', $ip)]['locked_until'], 'reason' => '升级前的登录保护']);
        }
        $entry = $data['block'][$ip] ?? null;
        if ($entry) return array_replace($empty, ['blocked' => true, 'until' => $entry['expires'] ?? 0, 'permanent' => $entry['expires'] === null, 'reason' => $entry['reason']]);
        if (!$incident || !$policy['enabled']) return $empty;
        $counter = $data['counters'][$ip] ?? ['level' => 0, 'count' => 0, 'start' => time()];
        if ($scope === 'api' && $counter['start'] <= time() - 60) { $counter['count'] = 0; $counter['start'] = time(); }
        $counter['count']++;
        $counter['last'] = time();
        $stage = $policy['stages'][min(3, $counter['level'])];
        $triggered = $scope === 'api' ? $counter['count'] > $stage['limit'] : $counter['count'] >= $stage['limit'];
        $result = array_replace($empty, ['count' => $counter['count']]);
        if ($triggered) {
            $forever = $stage['unit'] === 'forever';
            $seconds = $stage['duration'] * (['minutes' => 60, 'hours' => 3600, 'days' => 86400][$stage['unit']] ?? 0);
            $reason = $scope === 'api' ? '每分钟请求次数超限' : '连续登录失败';
            $data['block'][$ip] = ['ip' => $ip, 'reason' => $reason, 'created' => time(), 'expires' => $forever ? null : time() + $seconds];
            $result = array_replace($result, ['blocked' => true, 'newly_blocked' => true, 'until' => $forever ? 0 : time() + $seconds, 'permanent' => $forever, 'reason' => $reason]);
            $counter['level'] = min(3, $counter['level'] + 1); $counter['count'] = 0; $counter['start'] = time();
        }
        $data['counters'][$ip] = $counter;
        return $result;
    });
}

function security_login_success(string $ip): void
{
    security_state(static function (array &$state) use ($ip): void {
        if (isset($state['login']['counters'][$ip])) $state['login']['counters'][$ip]['count'] = 0;
    });
}

/** Compatibility for existing administrative endpoints, using the active store. */
function security_legacy_add(string $ip, bool $allow, int $duration = 1800, string $reason = 'manual', bool $permanent = false): void
{
    if (!valid_access_control_ip($ip)) throw new InvalidArgumentException('请输入有效的 IP 地址。');
    $ip = inet_ntop(inet_pton($ip));
    security_state(static function (array &$state) use ($ip, $allow, $duration, $reason, $permanent): void {
        foreach ($allow ? ['api','login'] : [in_array($reason, ['login','manual_login'], true) ? 'login' : 'api'] as $scope) {
            if (!$allow && isset($state[$scope]['allow'][$ip])) throw new InvalidArgumentException('该 IP 已在白名单中。');
            $state[$scope][$allow?'allow':'block'][$ip] = ['ip'=>$ip,'reason'=>$allow?'可信地址':$reason,'created'=>time(),'expires'=>$allow||$permanent?null:time()+max(60,$duration)];
            if ($allow) { unset($state[$scope]['block'][$ip], $state[$scope]['counters'][$ip], $state['legacy_login'][hash('sha256',$ip)]); }
        }
    });
}

function security_legacy_remove(array $ips, string $list, ?string $scope): int
{
    ip_access_control_list_keys($list, $scope);
    $valid = [];
    foreach ($ips as $ip) if (is_string($ip) && valid_access_control_ip(trim($ip))) $valid[inet_ntop(inet_pton(trim($ip)))] = true;
    if (!$valid) throw new InvalidArgumentException('请选择有效的 IP 地址。');
    return security_state(static function (array &$state) use ($valid, $list, $scope): int {
        $removed = 0; $kind = $list === 'whitelist' ? 'allow' : 'block';
        foreach (array_keys($valid) as $ip) {
            $found = false;
            foreach ($scope === null || $kind === 'allow' ? ['api','login'] : [$scope === 'request' ? 'api' : 'login'] as $target) {
                if (isset($state[$target][$kind][$ip])) { unset($state[$target][$kind][$ip]); $found = true; }
                if ($target === 'login' && $kind === 'block') unset($state['legacy_login'][hash('sha256',$ip)]);
            }
            if ($found) $removed++;
        }
        return $removed;
    });
}

function security_legacy_lists(): array
{
    $result = ['whitelist'=>[], 'blacklist'=>[]]; $seen = [];
    foreach (security_entries() as $entry) {
        if ($entry['kind'] === 'allow') {
            if (!isset($seen[$entry['ip']])) $result['whitelist'][] = ['ip'=>$entry['ip'],'added_at'=>$entry['created']];
            $seen[$entry['ip']] = true;
        } else $result['blacklist'][] = ['ip'=>$entry['ip'],'added_at'=>$entry['created'],'until'=>$entry['expires']??0,'permanent'=>$entry['expires']===null,'reason'=>$entry['scope']==='login'?'manual_login':'manual'];
    }
    return $result;
}
