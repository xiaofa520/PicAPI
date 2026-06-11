<?php

require_once __DIR__ . '/common.php';

admin_require_login();

$flash = admin_pull_flash();
$todayStats = ['hits' => 0, 'bandwidth' => 0, 'visitor_count' => 0];
$counterSnapshot = ['root' => 0, 'pc' => 0, 'm' => 0, 'icon' => 0];
$librarySummary = ['pc' => ['count' => 0, 'bytes' => 0], 'm' => ['count' => 0, 'bytes' => 0]];

$accessOverview = admin_build_access_overview($projectRoot, 20, 40);
$counterSnapshot = admin_read_counter_snapshot($projectRoot);
$libraryItems = [];
foreach ($libraryConfigs as $libraryKey => $config) {
    $libraryItems[$libraryKey] = admin_scan_library($config['root_dir'], $libraryKey);
}

$librarySummary = admin_library_summary($libraryItems);
$todayKey = date('Y-m-d');
if (isset($accessOverview['daily'][$todayKey])) {
    $todayStats = $accessOverview['daily'][$todayKey];
}

$todayRootHits = (int) ($todayStats['root_hits'] ?? 0);
$todayPcHits = (int) ($todayStats['pc_hits'] ?? 0);
$todayMobileHits = (int) ($todayStats['m_hits'] ?? 0);
$totalLibraryCount = (int) (($librarySummary['pc']['count'] ?? 0) + ($librarySummary['m']['count'] ?? 0));
$totalLibraryBytes = (int) (($librarySummary['pc']['bytes'] ?? 0) + ($librarySummary['m']['bytes'] ?? 0));

$chartSeries = admin_prepare_chart_series($accessOverview['daily'], 20);
$maxHits = 1;
$maxBandwidth = 1;
foreach ($chartSeries as $point) {
    $maxHits = max($maxHits, (int) $point['hits']);
    $maxBandwidth = max($maxBandwidth, (int) $point['bandwidth']);
}

$chartWidth = 1040;
$chartHeight = 360;
$chartPaddingTop = 20;
$chartPaddingRight = 66;
$chartPaddingBottom = 62;
$chartPaddingLeft = 64;
$plotWidth = $chartWidth - $chartPaddingLeft - $chartPaddingRight;
$plotHeight = $chartHeight - $chartPaddingTop - $chartPaddingBottom;
$seriesCount = max(count($chartSeries), 1);
$slotWidth = $plotWidth / $seriesCount;
$gapWidth = min(8, $slotWidth * 0.12);
$barWidth = max(6, min(16, ($slotWidth - $gapWidth) / 2));
$groupWidth = ($barWidth * 2) + $gapWidth;
$barColumns = [];
$hitAxisTicks = [];
$bandwidthAxisTicks = [];

foreach ($chartSeries as $index => $point) {
    $groupX = $chartPaddingLeft + ($slotWidth * $index) + (($slotWidth - $groupWidth) / 2);
    $hitHeight = $maxHits > 0 ? ($point['hits'] / $maxHits) * $plotHeight : 0;
    $bandwidthHeight = $maxBandwidth > 0 ? ($point['bandwidth'] / $maxBandwidth) * $plotHeight : 0;

    $barColumns[] = [
        'date' => $point['date'],
        'hit_x' => $groupX,
        'hit_y' => $chartPaddingTop + ($plotHeight - $hitHeight),
        'hit_height' => max(2, $hitHeight),
        'bandwidth_x' => $groupX + $barWidth + $gapWidth,
        'bandwidth_y' => $chartPaddingTop + ($plotHeight - $bandwidthHeight),
        'bandwidth_height' => max(2, $bandwidthHeight),
        'hits' => (int) $point['hits'],
        'bandwidth' => (int) $point['bandwidth'],
        'label_x' => $groupX + ($groupWidth / 2),
    ];
}

for ($step = 0; $step <= 4; $step++) {
    $ratio = $step / 4;
    $hitAxisTicks[] = [
        'y' => $chartPaddingTop + ($plotHeight * $ratio),
        'value' => (int) round($maxHits - ($maxHits * $ratio)),
    ];
    $bandwidthAxisTicks[] = [
        'y' => $chartPaddingTop + ($plotHeight * $ratio),
        'value' => (int) round($maxBandwidth - ($maxBandwidth * $ratio)),
    ];
}

admin_render_layout_start('图片 API 控制台', 'index.php', [
    'headline' => '数据总览',
    'description' => '集中查看访问指标、流量趋势、图片库存量与各入口累计请求情况。',
    'badges' => ['Dashboard', 'Dual Bars', '20 Days', 'Counters'],
    'auto_refresh_seconds' => 20,
    'mini_stats' => [
        [
            'label' => '今日请求',
            'value' => number_format((int) $todayStats['hits']),
            'note' => number_format((int) $todayStats['visitor_count']) . ' 位访客，' . image_api_format_file_size((int) $todayStats['bandwidth']) . ' 流量',
        ],
        [
            'label' => '管理员',
            'value' => admin_current_username(),
            'note' => '数据库认证已启用',
        ],
    ],
]);
?>
<?php if ($flash !== null): ?>
    <div class="notice <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<style>
.traffic-chart-card {
    --chart-hit-bar: #111111;
    --chart-bandwidth-bar: #97a3b6;
    --chart-grid: #e6ebf1;
    --chart-axis: #d4dbe4;
    --chart-label: #6c7786;
    --chart-surface-top: #ffffff;
    --chart-surface-bottom: #f5f7fa;
}

.traffic-chart-card .curve-wrap {
    background: linear-gradient(180deg, var(--chart-surface-top), var(--chart-surface-bottom));
}

html[data-admin-theme="dark"] .traffic-chart-card {
    --chart-hit-bar: #eef2f7;
    --chart-bandwidth-bar: #7d8898;
    --chart-grid: #243041;
    --chart-axis: #334255;
    --chart-label: #a8b3c2;
    --chart-surface-top: #141c26;
    --chart-surface-bottom: #10161e;
}
</style>

<div class="content-stack">
    <section class="metric-grid">
        <div class="metric-card">
            <div class="eyebrow">Today Visitors</div>
            <div class="metric-value"><?= number_format((int) $todayStats['visitor_count']) ?></div>
            <div class="metric-note">按日志去重后的今日访问 IP 数</div>
        </div>
        <div class="metric-card">
            <div class="eyebrow">Today Hits</div>
            <div class="metric-value"><?= number_format((int) $todayStats['hits']) ?></div>
            <div class="metric-note">今日记录的 API 请求次数</div>
        </div>
        <div class="metric-card">
            <div class="eyebrow">Today Bandwidth</div>
            <div class="metric-value"><?= htmlspecialchars(image_api_format_file_size((int) $todayStats['bandwidth']), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="metric-note">按输出图片大小估算的带宽消耗</div>
        </div>
        <div class="metric-card">
            <div class="eyebrow">Library Assets</div>
            <div class="metric-value"><?= number_format($totalLibraryCount) ?></div>
            <div class="metric-note">当前托管中的图片总数</div>
        </div>
    </section>

    <section class="panel section traffic-chart-card">
        <div class="section-head">
            <div>
                <div class="eyebrow">Traffic Chart</div>
                <h2 class="section-title">近 20 天访问与流量双柱图</h2>
            </div>
            <div class="section-kicker">访问次数和流量消耗按天并列显示，左侧是访问次数，右侧是流量刻度，底部为日期。</div>
        </div>

        <div class="curve-wrap">
            <div class="curve-head">
                <div class="curve-key">
                    <span class="hits">访问次数</span>
                    <span class="bandwidth">流量消耗</span>
                </div>
                <div class="soft">访问峰值 <?= number_format($maxHits) ?>，流量峰值 <?= htmlspecialchars(image_api_format_file_size((int) $maxBandwidth), ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <svg class="curve-svg" viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" role="img" aria-label="近20天访问与流量双柱图">
                <?php foreach ($hitAxisTicks as $index => $tick): ?>
                    <line
                        x1="<?= $chartPaddingLeft ?>"
                        y1="<?= round($tick['y'], 2) ?>"
                        x2="<?= $chartWidth - $chartPaddingRight ?>"
                        y2="<?= round($tick['y'], 2) ?>"
                        style="stroke: var(--chart-grid);"
                        stroke-width="1"
                    ></line>
                    <text
                        x="<?= $chartPaddingLeft - 10 ?>"
                        y="<?= round($tick['y'] + 4, 2) ?>"
                        text-anchor="end"
                        font-size="11"
                        style="fill: var(--chart-label);"
                        font-family="JetBrains Mono, Consolas, monospace"
                    ><?= number_format($tick['value']) ?></text>
                    <text
                        x="<?= $chartWidth - $chartPaddingRight + 10 ?>"
                        y="<?= round($bandwidthAxisTicks[$index]['y'] + 4, 2) ?>"
                        text-anchor="start"
                        font-size="11"
                        style="fill: var(--chart-label);"
                        font-family="JetBrains Mono, Consolas, monospace"
                    ><?= htmlspecialchars(image_api_format_file_size((int) $bandwidthAxisTicks[$index]['value']), ENT_QUOTES, 'UTF-8') ?></text>
                <?php endforeach; ?>

                <line x1="<?= $chartPaddingLeft ?>" y1="<?= $chartPaddingTop ?>" x2="<?= $chartPaddingLeft ?>" y2="<?= $chartHeight - $chartPaddingBottom ?>" style="stroke: var(--chart-axis);" stroke-width="1.2"></line>
                <line x1="<?= $chartWidth - $chartPaddingRight ?>" y1="<?= $chartPaddingTop ?>" x2="<?= $chartWidth - $chartPaddingRight ?>" y2="<?= $chartHeight - $chartPaddingBottom ?>" style="stroke: var(--chart-axis);" stroke-width="1.2"></line>
                <line x1="<?= $chartPaddingLeft ?>" y1="<?= $chartHeight - $chartPaddingBottom ?>" x2="<?= $chartWidth - $chartPaddingRight ?>" y2="<?= $chartHeight - $chartPaddingBottom ?>" style="stroke: var(--chart-axis);" stroke-width="1.2"></line>

                <?php foreach ($barColumns as $column): ?>
                    <rect
                        x="<?= round($column['hit_x'], 2) ?>"
                        y="<?= round($column['hit_y'], 2) ?>"
                        width="<?= round($barWidth, 2) ?>"
                        height="<?= round($column['hit_height'], 2) ?>"
                        rx="4"
                        style="fill: var(--chart-hit-bar);"
                    >
                        <title><?= htmlspecialchars($column['date'], ENT_QUOTES, 'UTF-8') ?> | 访问次数 <?= number_format($column['hits']) ?></title>
                    </rect>
                    <rect
                        x="<?= round($column['bandwidth_x'], 2) ?>"
                        y="<?= round($column['bandwidth_y'], 2) ?>"
                        width="<?= round($barWidth, 2) ?>"
                        height="<?= round($column['bandwidth_height'], 2) ?>"
                        rx="4"
                        style="fill: var(--chart-bandwidth-bar);"
                    >
                        <title><?= htmlspecialchars($column['date'], ENT_QUOTES, 'UTF-8') ?> | 流量消耗 <?= htmlspecialchars(image_api_format_file_size((int) $column['bandwidth']), ENT_QUOTES, 'UTF-8') ?></title>
                    </rect>
                    <text
                        x="<?= round($column['label_x'], 2) ?>"
                        y="<?= $chartHeight - 18 ?>"
                        text-anchor="middle"
                        font-size="9.1"
                        style="fill: var(--chart-label);"
                        font-family="JetBrains Mono, Consolas, monospace"
                    ><?= htmlspecialchars(substr($column['date'], 5), ENT_QUOTES, 'UTF-8') ?></text>
                <?php endforeach; ?>
            </svg>
        </div>
    </section>

    <section class="card-grid">
        <div class="panel section">
            <div class="section-head">
                <div>
                    <div class="eyebrow">Counters</div>
                    <h2 class="section-title">接口累计计数</h2>
                </div>
                <div class="section-kicker">每个接口同时显示今日访问量和累计总访问量，方便查看当天波动和长期趋势。</div>
            </div>
            <div class="status-grid">
                <div class="status-box">
                    <div class="eyebrow">Adaptive API</div>
                    <div class="metric-value"><?= number_format($todayRootHits) ?></div>
                    <div class="metric-note">今日访问量</div>
                    <div class="soft" style="margin-top: 8px;">总访问量 <?= number_format((int) $counterSnapshot['root']) ?></div>
                </div>
                <div class="status-box">
                    <div class="eyebrow">PC</div>
                    <div class="metric-value"><?= number_format($todayPcHits) ?></div>
                    <div class="metric-note">今日访问量</div>
                    <div class="soft" style="margin-top: 8px;">总访问量 <?= number_format((int) $counterSnapshot['pc']) ?></div>
                </div>
                <div class="status-box">
                    <div class="eyebrow">Mobile</div>
                    <div class="metric-value"><?= number_format($todayMobileHits) ?></div>
                    <div class="metric-note">今日访问量</div>
                    <div class="soft" style="margin-top: 8px;">总访问量 <?= number_format((int) $counterSnapshot['m']) ?></div>
                </div>
                <div class="status-box">
                    <div class="eyebrow">Icon API</div>
                    <div class="metric-value"><?= number_format((int) $counterSnapshot['icon']) ?></div>
                    <div class="metric-note">累计总访问量</div>
                    <div class="soft" style="margin-top: 8px;">当前按独立计数文件统计</div>
                </div>
            </div>
        </div>

        <div class="panel section">
            <div class="section-head">
                <div>
                    <div class="eyebrow">Library Split</div>
                    <h2 class="section-title">图库库存分布</h2>
                </div>
                <div class="section-kicker">上传完成后会根据宽高自动分流到 PC 或手机图库。</div>
            </div>
            <div class="status-grid">
                <div class="status-box">
                    <div class="eyebrow">Total Images</div>
                    <div class="metric-value"><?= number_format($totalLibraryCount) ?></div>
                    <div class="metric-note"><?= htmlspecialchars(image_api_format_file_size($totalLibraryBytes), ENT_QUOTES, 'UTF-8') ?> 总占用</div>
                </div>
                <div class="status-box">
                    <div class="eyebrow">PC Library</div>
                    <div class="metric-value"><?= number_format((int) ($librarySummary['pc']['count'] ?? 0)) ?></div>
                    <div class="metric-note"><?= htmlspecialchars(image_api_format_file_size((int) ($librarySummary['pc']['bytes'] ?? 0)), ENT_QUOTES, 'UTF-8') ?> 占用</div>
                </div>
                <div class="status-box">
                    <div class="eyebrow">Mobile Library</div>
                    <div class="metric-value"><?= number_format((int) ($librarySummary['m']['count'] ?? 0)) ?></div>
                    <div class="metric-note"><?= htmlspecialchars(image_api_format_file_size((int) ($librarySummary['m']['bytes'] ?? 0)), ENT_QUOTES, 'UTF-8') ?> 占用</div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php admin_render_layout_end(); ?>
