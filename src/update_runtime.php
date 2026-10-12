<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

const PICAPI_UPDATE_OWNER = 'xiaofa520';
const PICAPI_UPDATE_REPOSITORY = 'PicAPI';
const PICAPI_UPDATE_MAX_ARCHIVE_BYTES = 100_000_000;
const PICAPI_UPDATE_MAX_UNPACKED_BYTES = 250_000_000;

final class PicApiUpdateException extends RuntimeException {}

function picapi_update_version_tuple(string $version): ?array
{
    if (preg_match('/^v?(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?$/', trim($version), $match) !== 1) return null;
    return [(int) $match[1], (int) $match[2], (int) $match[3], (string) ($match[4] ?? '')];
}

function picapi_update_version_is_newer(string $candidate, string $current = APP_VERSION): bool
{
    $a = picapi_update_version_tuple($candidate);
    $b = picapi_update_version_tuple($current);
    if ($a === null || $b === null) return false;
    for ($index = 0; $index < 3; $index++) {
        if ($a[$index] !== $b[$index]) return $a[$index] > $b[$index];
    }
    if ($a[3] === $b[3]) return false;
    if ($a[3] === '') return true;
    if ($b[3] === '') return false;
    return version_compare($a[3], $b[3], '>');
}

/** @return array{version:string,url:string,notes:string,published_at:string} */
function picapi_update_latest_release(): array
{
    $base = 'https://api.github.com/repos/' . PICAPI_UPDATE_OWNER . '/' . PICAPI_UPDATE_REPOSITORY;
    try {
        $release = picapi_update_github_json($base . '/releases/latest');
        $version = (string) ($release['tag_name'] ?? '');
        if (picapi_update_version_tuple($version) === null) throw new PicApiUpdateException('GitHub 最新发布缺少有效的版本号。');
        return ['version' => $version, 'url' => picapi_update_archive_url($version), 'notes' => picapi_update_limit_text(strip_tags((string) ($release['body'] ?? '')), 4000), 'published_at' => (string) ($release['published_at'] ?? '')];
    } catch (PicApiUpdateException $error) {
        if (!str_contains($error->getMessage(), '404')) throw $error;
    }

    $tags = picapi_update_github_json($base . '/tags?per_page=100');
    if (!array_is_list($tags)) throw new PicApiUpdateException('无法读取 GitHub 版本标签。');
    $candidates = [];
    foreach ($tags as $tag) {
        if (!is_array($tag)) continue;
        $version = (string) ($tag['name'] ?? '');
        if (picapi_update_version_tuple($version) === null) continue;
        $candidates[] = ['version' => $version, 'url' => picapi_update_archive_url($version), 'notes' => '', 'published_at' => ''];
    }
    usort($candidates, static fn(array $left, array $right): int => version_compare(ltrim($right['version'], 'vV'), ltrim($left['version'], 'vV')));
    if ($candidates === []) throw new PicApiUpdateException('仓库还没有可用的正式版本。');
    return $candidates[0];
}

/** @return array<string,mixed> */
function picapi_update_github_json(string $url): array
{
    if (!extension_loaded('curl')) throw new PicApiUpdateException('服务器没有启用 cURL，暂时无法连接 GitHub。');
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'api.github.com') throw new PicApiUpdateException('GitHub 更新地址无效。');
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'PicAPI-Updater/' . APP_VERSION,
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'],
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (!is_string($body)) throw new PicApiUpdateException('连接 GitHub 失败，请检查服务器网络后重试。');
    if ($status === 404) throw new PicApiUpdateException('GitHub 返回 404。');
    if ($status === 403) throw new PicApiUpdateException('GitHub 请求次数已达上限，请稍后重试。');
    if ($status < 200 || $status >= 300) throw new PicApiUpdateException('GitHub 返回异常（HTTP ' . $status . '）。');
    try { $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
    catch (Throwable) { throw new PicApiUpdateException('GitHub 返回的数据无法读取。'); }
    if (!is_array($data)) throw new PicApiUpdateException('GitHub 返回的数据格式无效。');
    return $data;
}

function picapi_update_archive_url(string $version): string
{
    if (picapi_update_version_tuple($version) === null) throw new PicApiUpdateException('更新版本标签无效。');
    return 'https://codeload.github.com/' . PICAPI_UPDATE_OWNER . '/' . PICAPI_UPDATE_REPOSITORY . '/zip/refs/tags/' . rawurlencode($version);
}

function picapi_update_limit_text(string $text, int $limit): string
{
    if (function_exists('mb_substr')) return mb_substr($text, 0, $limit);
    $limited = function_exists('iconv_substr') ? iconv_substr($text, 0, $limit, 'UTF-8') : false;
    return is_string($limited) ? $limited : substr($text, 0, $limit);
}

/** @return array{current:string,latest:string,available:bool,notes:string,published_at:string} */
function picapi_update_check(): array
{
    $latest = picapi_update_latest_release();
    return ['current' => APP_VERSION, 'latest' => $latest['version'], 'available' => picapi_update_version_is_newer($latest['version']), 'notes' => $latest['notes'], 'published_at' => $latest['published_at']];
}

/** @return array{updated:bool,current:string,latest:string,backup:string} */
function picapi_update_install_latest(): array
{
    if (!extension_loaded('phar')) throw new PicApiUpdateException('服务器未启用 Phar，无法解压更新包。');
    if (!is_writable(APP_ROOT) || !is_writable(STORAGE_DIR)) throw new PicApiUpdateException('项目目录或 storage 目录不可写，无法自动更新。');
    $latest = picapi_update_latest_release();
    if (!picapi_update_version_is_newer($latest['version'])) return ['updated' => false, 'current' => APP_VERSION, 'latest' => $latest['version'], 'backup' => ''];
    if (function_exists('set_time_limit')) @set_time_limit(240);

    $updateRoot = STORAGE_DIR . '/updates';
    $backupRoot = $updateRoot . '/backups';
    ensure_directory($backupRoot);
    $lockPath = $updateRoot . '/install.lock';
    $lock = @fopen($lockPath, 'x');
    if ($lock === false) throw new PicApiUpdateException('已有更新正在执行，请稍后刷新页面查看结果。');
    $work = $updateRoot . '/stage-' . bin2hex(random_bytes(8));
    $backup = $backupRoot . '/' . gmdate('Ymd-His') . '-' . substr(hash('sha256', $latest['version'] . random_bytes(16)), 0, 8);
    $changed = [];
    try {
        ensure_directory($work);
        ensure_directory($backup);
        $zipPath = $work . '/source.zip';
        picapi_update_download($latest['url'], $zipPath);
        $staged = picapi_update_stage_archive($zipPath, $work . '/files');
        if ($staged === []) throw new PicApiUpdateException('更新包中没有可安装的 PicAPI 文件。');

        foreach ($staged as $relative => $source) {
            $destination = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            picapi_update_assert_safe_target($destination);
            if (is_file($destination)) {
                $backupFile = $backup . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                ensure_directory(dirname($backupFile));
                if (!copy($destination, $backupFile)) throw new PicApiUpdateException('创建更新备份失败，尚未修改程序文件。');
                @chmod($backupFile, fileperms($destination) & 0777);
            }
        }

        foreach ($staged as $relative => $source) {
            $destination = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            ensure_directory(dirname($destination));
            $temporary = $destination . '.picapi-update-' . bin2hex(random_bytes(5));
            if (!copy($source, $temporary)) throw new PicApiUpdateException('写入更新临时文件失败。');
            $existed = is_file($destination);
            $permissions = $existed ? (fileperms($destination) & 0777) : 0640;
            if ($existed && !unlink($destination)) { @unlink($temporary); throw new PicApiUpdateException('无法替换程序文件，更新已停止。'); }
            if (!rename($temporary, $destination)) {
                @unlink($temporary);
                if ($existed) @copy($backup . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), $destination);
                throw new PicApiUpdateException('启用更新文件失败，正在恢复备份。');
            }
            @chmod($destination, $permissions);
            if (str_ends_with(strtolower($destination), '.php') && function_exists('opcache_invalidate')) @opcache_invalidate($destination, true);
            $changed[$relative] = $existed;
        }
        picapi_update_prune_backups($backupRoot, 3);
        return ['updated' => true, 'current' => APP_VERSION, 'latest' => $latest['version'], 'backup' => basename($backup)];
    } catch (Throwable $error) {
        picapi_update_rollback($changed, $backup);
        if ($error instanceof PicApiUpdateException) throw $error;
        throw new PicApiUpdateException('自动更新失败，已尝试恢复更新前文件。请检查服务器权限和更新日志。');
    } finally {
        picapi_update_remove_tree($work);
        fclose($lock);
        @unlink($lockPath);
    }
}

function picapi_update_download(string $url, string $destination): void
{
    $parts = parse_url($url);
    $pathPrefix = '/' . PICAPI_UPDATE_OWNER . '/' . PICAPI_UPDATE_REPOSITORY . '/zip/refs/tags/';
    $path = (string) ($parts['path'] ?? '');
    $tag = str_starts_with($path, $pathPrefix) ? rawurldecode(substr($path, strlen($pathPrefix))) : '';
    if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'codeload.github.com'
        || picapi_update_version_tuple($tag) === null || $path !== $pathPrefix . rawurlencode($tag)) {
        throw new PicApiUpdateException('更新源码包地址不属于 PicAPI 官方仓库。');
    }
    $stream = @fopen($destination, 'wb');
    if ($stream === false) throw new PicApiUpdateException('无法创建更新临时文件。');
    $bytes = 0;
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'PicAPI-Updater/' . APP_VERSION,
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($stream, &$bytes): int {
            $length = strlen($chunk);
            if ($bytes + $length > PICAPI_UPDATE_MAX_ARCHIVE_BYTES) return 0;
            $written = fwrite($stream, $chunk);
            if ($written === false) return 0;
            $bytes += $written;
            return $written;
        },
    ]);
    $ok = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    fclose($stream);
    if ($ok === false || $status !== 200 || $bytes < 100) {
        @unlink($destination);
        if ($bytes >= PICAPI_UPDATE_MAX_ARCHIVE_BYTES) throw new PicApiUpdateException('更新包超过 100 MB 限制。');
        throw new PicApiUpdateException('下载官方更新包失败，请检查服务器是否能访问 GitHub。');
    }
}

/** @return array<string,string> staged app paths mapped to temporary files */
function picapi_update_stage_archive(string $zipPath, string $stageRoot): array
{
    ensure_directory($stageRoot);
    try { $archive = new PharData($zipPath); }
    catch (Throwable) { throw new PicApiUpdateException('GitHub 更新包损坏或无法解压。'); }
    $files = [];
    $total = 0;
    $count = 0;
    $zipMarker = basename($zipPath) . '/';
    foreach (new RecursiveIteratorIterator($archive) as $entry) {
        if (!$entry instanceof SplFileInfo || $entry->isDir()) continue;
        $archiveName = str_replace('\\', '/', $entry->getPathName());
        $markerAt = strpos($archiveName, $zipMarker);
        if ($markerAt === false) throw new PicApiUpdateException('更新包目录结构无效。');
        $inside = substr($archiveName, $markerAt + strlen($zipMarker));
        $slash = strpos($inside, '/');
        if ($slash === false) continue;
        $relative = substr($inside, $slash + 1);
        if (!picapi_update_path_is_installable($relative)) continue;
        if (str_contains($relative, "\0") || preg_match('~^(?:/|[A-Za-z]:)|(?:^|/)\.{1,2}(?:/|$)|//~', $relative) === 1) throw new PicApiUpdateException('更新包中检测到不安全路径。');
        if (($entry->getPerms() & 0170000) === 0120000) throw new PicApiUpdateException('更新包中不允许包含符号链接。');
        $size = $entry->getSize();
        if ($size < 0 || $size > 25_000_000 || ($total += $size) > PICAPI_UPDATE_MAX_UNPACKED_BYTES || ++$count > 5000) throw new PicApiUpdateException('更新包内容超出安全限制。');
        $target = $stageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        ensure_directory(dirname($target));
        $content = $entry->getContent();
        if (!is_string($content) || strlen($content) !== $size || file_put_contents($target, $content, LOCK_EX) !== $size) throw new PicApiUpdateException('无法暂存更新文件。');
        $files[$relative] = $target;
    }
    return $files;
}

function picapi_update_path_is_installable(string $relative): bool
{
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($relative === 'index.php') return true;
    if (!str_starts_with($relative, 'src/') && !str_starts_with($relative, 'public/') && !str_starts_with($relative, 'templates/')) return false;
    $parts = explode('/', $relative);
    if (in_array('.git', $parts, true) || in_array('storage', $parts, true)) return false;
    if (preg_match('~^public/(?:pc|m)/(?:img|log)(?:/|$)~', $relative) === 1) return false;
    if (preg_match('~(?:^|/)\.htaccess$~i', $relative) === 1) return false;
    return true;
}

function picapi_update_assert_safe_target(string $path): void
{
    $root = rtrim(APP_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($path, $root)) throw new PicApiUpdateException('更新目标路径超出项目目录。');
    $cursor = dirname($path);
    while ($cursor !== APP_ROOT && str_starts_with($cursor, $root)) {
        if (is_link($cursor)) throw new PicApiUpdateException('更新路径包含符号链接，已停止安装。');
        $cursor = dirname($cursor);
    }
    if (is_link($path)) throw new PicApiUpdateException('更新目标是符号链接，已停止安装。');
}

function picapi_update_rollback(array $changed, string $backup): void
{
    foreach (array_reverse(array_keys($changed)) as $relative) {
        $target = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!$changed[$relative]) { if (is_file($target)) @unlink($target); continue; }
        $saved = $backup . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($saved)) {
            $temporary = $target . '.restore-' . bin2hex(random_bytes(4));
            if (@copy($saved, $temporary)) { @unlink($target); @rename($temporary, $target); }
        }
    }
}

function picapi_update_prune_backups(string $backupRoot, int $keep): void
{
    $backups = glob($backupRoot . '/*', GLOB_ONLYDIR) ?: [];
    usort($backups, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    foreach (array_slice($backups, $keep) as $old) picapi_update_remove_tree($old);
}

function picapi_update_remove_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) return;
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname()); else @unlink($item->getPathname()); }
    @rmdir($path);
}
