<?php

require_once __DIR__ . '/common.php';

admin_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_upload_audit_logs') {
    admin_require_csrf();
    admin_clear_upload_logs();
    admin_set_flash('success', '上传审计记录已清空。');
    admin_redirect('logs.php');
}

$flash = admin_pull_flash();
$accessPage = admin_query_int('access_page', 1, 1);
$uploadPage = admin_query_int('upload_page', 1, 1);
$accessPerPage = admin_query_per_page('access_per_page', 25);
$uploadPerPage = admin_query_per_page('upload_per_page', 25);

$accessOverview = admin_build_access_overview($projectRoot, 14, 1200);
$uploadHistory = admin_read_upload_logs(1200);
$accessPagination = admin_paginate_array($accessOverview['recent'], $accessPage, $accessPerPage);
$uploadPagination = admin_paginate_array($uploadHistory, $uploadPage, $uploadPerPage);
$pagedAccessLogs = $accessPagination['items'];
$pagedUploadLogs = $uploadPagination['items'];
$todayKey = date('Y-m-d');
$todayStats = $accessOverview['daily'][$todayKey] ?? ['hits' => 0, 'bandwidth' => 0, 'visitor_count' => 0];

admin_render_layout_start('日志中心', 'logs.php', [
    'headline' => '日志中心',
    'description' => '分析系统吞吐性能，追溯接口调用链路与切片上传合并账册。',
    'badges' => ['审计日志', '运行明细'],
    'auto_refresh_seconds' => 20,
    'mini_stats' => [
        [
            'label' => '今日访客',
            'value' => number_format((int) $todayStats['visitor_count']),
            'note' => number_format((int) $todayStats['hits']) . ' 次请求调用',
        ],
        [
            'label' => '今日下行流量',
            'value' => image_api_format_file_size((int) $todayStats['bandwidth']),
            'note' => '响应图片吞吐量估算',
        ],
    ],
]);
?>
<?php if ($flash !== null): ?>
    <div class="notice <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="content-stack" id="logs-content">
    <section class="panel section">
        <div class="section-head">
            <div class="eyebrow">DAILY SUMMARY</div>
            <h2 class="section-title" style="margin-top: 4px;">近 14 天吞吐指标</h2>
            <div class="brand-copy" style="margin-top: 6px;">按天度量调用分布及带宽走势，用以评估系统的并发分布与负载状况。</div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>日期</th>
                        <th>总请求</th>
                        <th>独立访客 IP</th>
                        <th>带宽占用</th>
                        <th>根接口(Root)</th>
                        <th>PC 接口</th>
                        <th>手机接口(Mobile)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($accessOverview['daily'])): ?>
                        <tr><td colspan="7" class="empty">无最近 14 天可解析的访问日志汇总数据。</td></tr>
                    <?php else: ?>
                        <?php foreach ($accessOverview['daily'] as $date => $daily): ?>
                            <tr>
                                <td class="mono"><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="mono"><?= number_format((int) $daily['hits']) ?></td>
                                <td class="mono"><?= number_format((int) $daily['visitor_count']) ?></td>
                                <td class="mono"><?= htmlspecialchars(image_api_format_file_size((int) $daily['bandwidth']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="mono"><?= number_format((int) $daily['root_hits']) ?></td>
                                <td class="mono"><?= number_format((int) $daily['pc_hits']) ?></td>
                                <td class="mono"><?= number_format((int) $daily['m_hits']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="content-stack" id="logs">
        <!-- 上传审计记录放在上方 -->
        <div class="panel section">
            <div class="section-head">
                <div class="eyebrow">UPLOAD HISTORY</div>
                <h2 class="section-title" style="margin-top: 4px;">上传审计记录</h2>
                <div class="brand-copy" style="margin-top: 6px;">跟踪合并切片后入库的所有媒体资源，便于回溯归档。</div>
            </div>
            <form method="post" onsubmit="return window.confirm('确定要清空全部上传审计记录吗？此操作不可恢复。');" style="margin: -4px 0 16px; display: flex; justify-content: flex-end;">
                <input type="hidden" name="action" value="clear_upload_audit_logs">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="secondary">清空上传记录</button>
            </form>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 160px;">上传时间</th>
                            <th>源文件名与保存名</th>
                            <th style="width: 100px;">库目录</th>
                            <th style="width: 140px;">几何分辨率</th>
                            <th style="width: 120px;">文件大小</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pagedUploadLogs)): ?>
                            <tr><td colspan="5" class="empty">无已提交的上传审计事务。</td></tr>
                        <?php else: ?>
                            <?php foreach ($pagedUploadLogs as $upload): ?>
                                <tr>
                                    <td class="mono"><?= htmlspecialchars((string) ($upload['uploaded_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <div style="font-weight: 600; font-size: 13px;"><?= htmlspecialchars((string) ($upload['original_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="mono" style="color: var(--muted); margin-top: 2px;"><?= htmlspecialchars((string) ($upload['saved_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <span class="badge" style="padding: 2px 6px; font-size: 11px; font-weight: normal;"><?= htmlspecialchars((string) ($upload['library_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td class="mono"><?= (int) ($upload['width'] ?? 0) ?> px x <?= (int) ($upload['height'] ?? 0) ?> px</td>
                                    <td class="mono"><?= htmlspecialchars(image_api_format_file_size((int) ($upload['size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination-toolbar">
                <div class="toolbar">
                    <div class="soft" style="font-size: 13px;">流水呈现第 <?= number_format($uploadPagination['page']) ?> 页，共 <?= number_format($uploadPagination['total_items']) ?> 条审计事务。</div>
                    <?php admin_render_per_page_selector('upload_per_page', $uploadPerPage, 'upload-per-page'); ?>
                </div>
                <?php admin_render_pagination('upload_page', $uploadPagination); ?>
            </div>
        </div>

        <!-- 最近访问流水放在下方 -->
        <div class="panel section">
            <div class="section-head">
                <div class="eyebrow">IP ACCESS FLOW</div>
                <h2 class="section-title" style="margin-top: 4px;">最近访问流水</h2>
                <div class="brand-copy" style="margin-top: 6px;">追溯接口调用的源 IP 地址、传输模式及对应出站静态资源路径。</div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 160px;">时间</th>
                            <th style="width: 80px;">来源</th>
                            <th style="width: 160px;">IP 物理地址</th>
                            <th>静态资源路径</th>
                            <th style="width: 70px;">方法</th>
                            <th style="width: 70px;">类型</th>
                            <th style="width: 100px;">吞吐</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pagedAccessLogs)): ?>
                            <tr><td colspan="7" class="empty">当前日志管道无活跃数据。</td></tr>
                        <?php else: ?>
                            <?php foreach ($pagedAccessLogs as $entry): ?>
                                <tr>
                                    <td class="mono"><?= htmlspecialchars($entry['timestamp'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="badge" style="padding: 2px 6px; font-size: 11px; font-weight: normal;"><?= htmlspecialchars(strtoupper($entry['source']), ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td class="mono"><?= htmlspecialchars($entry['ip'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="mono" style="max-width: 320px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;" title="<?= htmlspecialchars($entry['image_path'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($entry['image_path'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="mono"><?= htmlspecialchars($entry['method'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="mono"><?= htmlspecialchars($entry['type'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="mono"><?= htmlspecialchars(image_api_format_file_size((int) $entry['bandwidth']), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination-toolbar">
                <div class="toolbar">
                    <div class="soft" style="font-size: 13px;">日志呈现第 <?= number_format($accessPagination['page']) ?> 页，共 <?= number_format($accessPagination['total_items']) ?> 条明细。</div>
                    <?php admin_render_per_page_selector('access_per_page', $accessPerPage, 'access-per-page'); ?>
                </div>
                <?php admin_render_pagination('access_page', $accessPagination); ?>
            </div>
        </div>
    </div>
</div>

<script>
async function fetchLogsContent(url, options = {}) {
    const { pushState = true } = options;
    const currentSection = document.getElementById('logs-content');
    if (!currentSection) {
        window.location.href = url;
        return;
    }

    currentSection.style.opacity = '0.5';

    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const html = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const nextSection = doc.getElementById('logs-content');
        if (!nextSection) {
            throw new Error('Missing logs content section');
        }

        currentSection.outerHTML = nextSection.outerHTML;
        if (pushState) {
            window.history.pushState({ logsContent: true }, '', url);
        }

        bindLogPagination();
        const replacedSection = document.getElementById('logs-content');
        if (replacedSection) {
            window.requestAnimationFrame(() => {
                replacedSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }
    } catch (error) {
        window.location.href = url;
    }
}

function bindLogPagination() {
    const section = document.getElementById('logs-content');
    if (!section) {
        return;
    }

    section.querySelectorAll('.per-page-select').forEach((select) => {
        select.dataset.asyncPagination = '1';
        select.addEventListener('change', () => {
            const url = new URL(window.location.href);
            const queryKey = select.dataset.queryKey || '';
            if (queryKey === '') {
                return;
            }

            url.searchParams.set(queryKey, select.value);
            if (queryKey === 'access_per_page') {
                url.searchParams.set('access_page', '1');
            }
            if (queryKey === 'upload_per_page') {
                url.searchParams.set('upload_page', '1');
            }

            fetchLogsContent(url.toString());
        });
    });

    section.querySelectorAll('.pagination .page-link:not(.disabled)').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const href = link.getAttribute('href');
            if (href) {
                fetchLogsContent(href);
            }
        });
    });
}

window.addEventListener('popstate', () => {
    if (document.getElementById('logs-content')) {
        fetchLogsContent(window.location.href, { pushState: false });
    }
});

bindLogPagination();
</script>
<?php admin_render_layout_end(); ?>
