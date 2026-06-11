<?php

require_once __DIR__ . '/common.php';

admin_require_login();

function media_category_exists(array $summaries, string $category): bool
{
    foreach ($summaries as $summary) {
        if (($summary['name'] ?? '') === $category) {
            return true;
        }
    }

    return false;
}

function media_find_category_summary(array $summaries, string $category): ?array
{
    foreach ($summaries as $summary) {
        if (($summary['name'] ?? '') === $category) {
            return $summary;
        }
    }

    return null;
}

function media_filter_categories(array $summaries, string $search): array
{
    if ($search === '') {
        return $summaries;
    }

    $needle = admin_normalize_search_text($search);
    return array_values(array_filter($summaries, static function (array $summary) use ($needle): bool {
        $haystack = admin_normalize_search_text(implode(' ', [
            (string) ($summary['name'] ?? ''),
            (string) ($summary['note'] ?? ''),
        ]));

        return str_contains($haystack, $needle);
    }));
}

function media_filter_images(array $images, string $category, string $libraryFilter, string $search): array
{
    $items = admin_filter_images_by_category($images, $category);
    if ($libraryFilter !== 'all') {
        $items = array_values(array_filter($items, static function (array $item) use ($libraryFilter): bool {
            return (string) ($item['library'] ?? '') === $libraryFilter;
        }));
    }

    if ($search !== '') {
        $needle = admin_normalize_search_text($search);
        $items = array_values(array_filter($items, static function (array $image) use ($needle): bool {
            $haystack = admin_normalize_search_text(implode(' ', [
                (string) ($image['relative_path'] ?? ''),
                (string) ($image['category_relative_path'] ?? ''),
                (string) ($image['basename'] ?? ''),
                (string) ($image['library'] ?? ''),
                (string) ($image['width'] ?? 0),
                (string) ($image['height'] ?? 0),
                ((int) ($image['width'] ?? 0)) . 'x' . ((int) ($image['height'] ?? 0)),
            ]));

            return str_contains($haystack, $needle);
        }));
    }

    usort($items, static fn(array $left, array $right): int => (int) ($right['modified_at'] ?? 0) <=> (int) ($left['modified_at'] ?? 0));
    return $items;
}

function media_render_browser(
    string $view,
    array $pagination,
    string $search,
    int $perPage,
    array $categorySummaries,
    ?array $currentCategorySummary,
    string $currentCategory,
    string $libraryFilter
): void {
    ?>
    <section class="panel section" id="media-browser">
        <style>
        .folder-create-shell {
            margin-bottom: 18px;
            padding: 18px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: linear-gradient(180deg, color-mix(in srgb, var(--panel-strong) 92%, #fff 8%), var(--panel));
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
        }

        .folder-create-grid {
            display: grid;
            grid-template-columns: minmax(220px, 0.9fr) minmax(260px, 1.3fr) auto;
            gap: 12px;
            align-items: end;
        }

        .folder-create-shell .toolbar {
            margin-top: 12px;
            justify-content: flex-end;
        }

        .folder-create-shell .toolbar button {
            min-width: 120px;
            font-size: 0;
        }

        .folder-create-shell .toolbar button::before {
            content: "\65B0\5EFA";
            font-size: 14px;
        }

        .folder-create-shell label {
            display: none;
        }

        .category-grid {
            display: none;
        }

        .folder-table-wrap {
            border: 1px solid var(--line);
            border-radius: 20px;
            background: var(--panel);
            overflow: auto;
            max-height: 72vh;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.45);
        }

        .folder-table-wrap::-webkit-scrollbar {
            width: 12px;
            height: 12px;
        }

        .folder-table-wrap::-webkit-scrollbar-thumb {
            border-radius: 999px;
            background: color-mix(in srgb, var(--muted) 38%, transparent);
            border: 3px solid transparent;
            background-clip: padding-box;
        }

        .folder-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 980px;
        }

        .folder-table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            padding: 16px 14px;
            text-align: left;
            font-size: 12px;
            letter-spacing: 0.04em;
            color: var(--muted);
            background: color-mix(in srgb, var(--panel) 94%, var(--accent-soft) 6%);
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }

        .folder-table tbody td {
            padding: 14px;
            border-bottom: 1px solid color-mix(in srgb, var(--line) 82%, transparent);
            vertical-align: middle;
        }

        .folder-table tbody tr:hover {
            background: color-mix(in srgb, var(--panel-soft) 76%, transparent);
        }

        .folder-row {
            cursor: default;
        }

        .folder-row .folder-name {
            cursor: pointer;
        }

        .folder-table tbody tr:last-child td {
            border-bottom: none;
        }

        .folder-name-cell {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .folder-icon {
            position: relative;
            width: 22px;
            height: 16px;
            flex: 0 0 auto;
            border-radius: 4px 4px 6px 6px;
            background: linear-gradient(180deg, #ffd86f 0%, #f3b63b 100%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.42);
        }

        .folder-icon::before {
            content: "";
            position: absolute;
            left: 1px;
            top: -4px;
            width: 11px;
            height: 7px;
            border-radius: 4px 4px 0 0;
            background: #ffe39e;
        }

        .folder-name-stack {
            min-width: 0;
            display: grid;
            gap: 4px;
        }

        .folder-name {
            font-weight: 600;
            color: var(--ink);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .folder-sub {
            font-size: 12px;
            color: var(--muted);
        }

        .folder-note-input {
            width: 100%;
            min-width: 180px;
        }

        .folder-inline-form {
            margin: 0;
        }

        .folder-count {
            font-family: "JetBrains Mono", Consolas, monospace;
            font-size: 12px;
            color: var(--ink);
            white-space: nowrap;
        }

        .folder-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .folder-status-bar {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            padding-top: 16px;
        }

        .folder-empty {
            padding: 48px 20px;
            text-align: center;
            color: var(--muted);
        }

        @media (max-width: 960px) {
            .folder-create-grid {
                grid-template-columns: 1fr;
            }
        }
        </style>
        <div class="section-head">
            <div>
                <div class="eyebrow">BROWSER</div>
                <h2 class="section-title"><?= $view === 'categories' ? '分类文件夹' : '资源浏览' ?></h2>
            </div>
            <div class="section-kicker">
                <?= $view === 'categories'
                    ? '先浏览分类，再进入分类查看图片。支持备注搜索、无刷新分页与快速进入。'
                    : '当前分类下支持按端筛选、关键字搜索、无刷新分页、懒加载预览和批量删除。' ?>
            </div>
        </div>

        <div class="viewer-toolbar">
            <?php if ($view === 'images'): ?>
                <div class="media-bulk-bar">
                    <a class="secondary media-browser-link" href="media.php" style="text-decoration:none;display:inline-flex;align-items:center;">返回分类</a>
                    <button type="button" id="bulk-delete-button" class="secondary media-bulk-button" disabled>批量删除</button>
                    <div class="badge">已选 <span id="selected-count">0</span></div>
                </div>
            <?php else: ?>
                <div class="media-bulk-bar">
                    <div class="badge">默认分类固定保留</div>
                </div>
            <?php endif; ?>

            <div class="toolbar">
                <?php if ($view === 'categories'): ?>
                    <div class="badge">分类总数 <span id="media-total"><?= number_format(count($categorySummaries)) ?></span></div>
                <?php else: ?>
                    <div class="badge">当前分类 <?= htmlspecialchars($currentCategory, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="badge">总图片 <span id="media-total"><?= number_format((int) ($currentCategorySummary['total_count'] ?? 0)) ?></span></div>
                <?php endif; ?>
                <div class="badge">筛选结果 <span id="media-visible"><?= number_format((int) $pagination['total_items']) ?></span></div>
                <div class="badge">页码 <?= number_format((int) $pagination['page']) ?> / <?= number_format((int) $pagination['total_pages']) ?></div>
                <?php admin_render_per_page_selector('per_page', $perPage, 'media-per-page'); ?>
            </div>
        </div>

        <div class="toolbar" style="gap: 12px; flex-wrap: wrap;">
            <?php if ($view === 'images'): ?>
                <div class="field" style="min-width: 120px;">
                    <select id="library" name="library">
                        <option value="all"<?= $libraryFilter === 'all' ? ' selected' : '' ?>>全部</option>
                        <option value="pc"<?= $libraryFilter === 'pc' ? ' selected' : '' ?>>PC</option>
                        <option value="m"<?= $libraryFilter === 'm' ? ' selected' : '' ?>>手机</option>
                    </select>
                </div>
            <?php endif; ?>
            <div class="field" style="flex: 1; min-width: 200px;">
                <input id="q" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= $view === 'categories' ? '按分类名或备注搜索' : '按文件名、相对路径或尺寸搜索' ?>">
            </div>
        </div>

        <?php if ($view === 'categories'): ?>
            <div class="folder-table-wrap">
                <table class="folder-table">
                    <thead>
                        <tr>
                            <th style="width: 32%;">&#25991;&#20214;&#22841;</th>
                            <th style="width: 26%;">&#22791;&#27880;</th>
                            <th style="width: 9%;">PC</th>
                            <th style="width: 9%;">Mobile</th>
                            <th style="width: 9%;">Total</th>
                            <th style="width: 15%;">&#26356;&#26032;&#26102;&#38388;</th>
                            <th style="width: 180px; text-align: right;">&#25805;&#20316;</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagination['items'] as $summary): ?>
                            <?php
                            $updatedAt = strtotime((string) ($summary['updated_at'] ?? ''));
                            $updatedAtText = $updatedAt !== false && $updatedAt > 0 ? date('Y-m-d H:i', $updatedAt) : '-';
                            ?>
                            <tr class="folder-row" data-folder-href="media.php?category=<?= rawurlencode((string) $summary['name']) ?>">
                                <td>
                                    <div class="folder-name-cell">
                                        <span class="folder-icon" aria-hidden="true"></span>
                                        <div class="folder-name-stack">
                                            <div class="folder-name">
                                                <?= htmlspecialchars((string) $summary['name'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php if ((string) $summary['name'] === admin_default_category_name()): ?>
                                                    <span class="badge">default</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="folder-sub">pc/img / m/img</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <input
                                        class="folder-note-input"
                                        name="category_note"
                                        form="category-note-<?= htmlspecialchars((string) $summary['name'], ENT_QUOTES, 'UTF-8') ?>"
                                        value="<?= htmlspecialchars((string) ($summary['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        placeholder="note"
                                    >
                                </td>
                                <td class="folder-count"><?= number_format((int) $summary['pc_count']) ?></td>
                                <td class="folder-count"><?= number_format((int) $summary['m_count']) ?></td>
                                <td class="folder-count"><?= number_format((int) $summary['total_count']) ?></td>
                                <td class="folder-count"><?= htmlspecialchars($updatedAtText, ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <form method="post" id="category-note-<?= htmlspecialchars((string) $summary['name'], ENT_QUOTES, 'UTF-8') ?>" class="folder-inline-form">
                                        <input type="hidden" name="action" value="update_category_note">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="category_name" value="<?= htmlspecialchars((string) $summary['name'], ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="folder-actions">
                                            <button type="submit" class="secondary">&#20445;&#23384;</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($pagination['items'] === []): ?>
                    <div class="folder-empty">&#27809;&#26377;&#21305;&#37197;&#30340;&#20998;&#31867;</div>
                <?php endif; ?>
            </div>

            <div class="category-grid">
                <?php foreach ($pagination['items'] as $summary): ?>
                    <article class="category-card">
                        <div class="category-card-head">
                            <div>
                                <div class="category-name"><?= htmlspecialchars((string) $summary['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="category-note"><?= htmlspecialchars((string) (($summary['note'] ?? '') !== '' ? $summary['note'] : '暂无备注'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <span class="badge"><?= htmlspecialchars((string) $summary['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>

                        <div class="category-stats">
                            <span class="badge">PC <?= number_format((int) $summary['pc_count']) ?></span>
                            <span class="badge">手机 <?= number_format((int) $summary['m_count']) ?></span>
                            <span class="badge">总数 <?= number_format((int) $summary['total_count']) ?></span>
                        </div>

                        <form method="post" class="category-note-form">
                            <input type="hidden" name="action" value="update_category_note">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="category_name" value="<?= htmlspecialchars((string) $summary['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="field">
                                <label>备注</label>
                                <input name="category_note" value="<?= htmlspecialchars((string) ($summary['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="输入备注">
                            </div>
                            <div class="toolbar">
                                <button type="submit" class="secondary">保存备注</button>
                                <a class="media-browser-link" href="media.php?category=<?= rawurlencode((string) $summary['name']) ?>" style="text-decoration:none;display:inline-flex;align-items:center;">进入分类</a>
                            </div>
                        </form>
                    </article>
                <?php endforeach; ?>

                <?php if ($pagination['items'] === []): ?>
                    <div class="empty category-empty">没有匹配的分类。</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="category-summary-strip">
                <div class="badge">PC <?= number_format((int) ($currentCategorySummary['pc_count'] ?? 0)) ?></div>
                <div class="badge">手机 <?= number_format((int) ($currentCategorySummary['m_count'] ?? 0)) ?></div>
                <div class="badge">共 <?= number_format((int) ($currentCategorySummary['total_count'] ?? 0)) ?></div>
                <div class="soft"><?= htmlspecialchars((string) (($currentCategorySummary['note'] ?? '') !== '' ? $currentCategorySummary['note'] : '暂无备注'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <div class="table-wrap" style="max-height: 68vh;">
                <table>
                    <thead>
                        <tr>
                            <th class="media-select-cell"><input id="select-all-media" class="media-select-input" type="checkbox" aria-label="全选当前页"></th>
                            <th style="width: 80px;">预览</th>
                            <th>文件</th>
                            <th style="width: 100px;">端</th>
                            <th style="width: 120px;">尺寸</th>
                            <th style="width: 100px;">大小</th>
                            <th style="width: 140px;">修改时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagination['items'] as $image): ?>
                            <?php
                            $publicBase = $image['library'] === 'pc' ? '../pc/img/' : '../m/img/';
                            $publicSrc = $publicBase . image_api_encode_public_path((string) $image['relative_path']);
                            $previewSrc = admin_preview_url((string) $image['library'], (string) $image['relative_path'], (int) ($image['modified_at'] ?? 0));
                            $displayPath = (string) (($image['category_relative_path'] ?? '') !== '' ? $image['category_relative_path'] : ($image['basename'] ?? $image['relative_path']));
                            ?>
                            <tr class="media-entry" data-library="<?= htmlspecialchars((string) $image['library'], ENT_QUOTES, 'UTF-8') ?>" data-relative-path="<?= htmlspecialchars((string) $image['relative_path'], ENT_QUOTES, 'UTF-8') ?>">
                                <td class="media-select-cell"><input class="media-select-input media-row-checkbox" type="checkbox" aria-label="选择图片"></td>
                                <td>
                                    <button
                                        type="button"
                                        class="media-thumb-button preview-trigger"
                                        data-preview-src="<?= htmlspecialchars($publicSrc, ENT_QUOTES, 'UTF-8') ?>"
                                        data-preview-caption="<?= htmlspecialchars((string) $image['relative_path'], ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                        <img
                                            class="thumb lazy-preview-image"
                                            src="<?= htmlspecialchars('data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', ENT_QUOTES, 'UTF-8') ?>"
                                            data-lazy-src="<?= htmlspecialchars($previewSrc, ENT_QUOTES, 'UTF-8') ?>"
                                            alt="<?= htmlspecialchars($displayPath, ENT_QUOTES, 'UTF-8') ?>"
                                            loading="lazy"
                                            decoding="async"
                                        >
                                    </button>
                                </td>
                                <td class="mono"><?= htmlspecialchars($displayPath, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="badge"><?= htmlspecialchars((string) ($image['library'] === 'pc' ? 'PC' : '手机'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="mono"><?= (int) ($image['width'] ?? 0) ?> x <?= (int) ($image['height'] ?? 0) ?></td>
                                <td class="mono"><?= htmlspecialchars(image_api_format_file_size((int) ($image['size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="mono"><?= htmlspecialchars(date('Y-m-d H:i', (int) ($image['modified_at'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if ($pagination['items'] === []): ?>
                            <tr><td colspan="7" class="empty">当前分类下没有匹配的图片。</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="pagination-toolbar">
            <div class="soft">当前显示第 <?= number_format((int) $pagination['page']) ?> 页，每页 <?= number_format($perPage) ?> 条，共 <?= number_format((int) $pagination['total_items']) ?> 条。</div>
            <?php admin_render_pagination('page', $pagination); ?>
        </div>
    </section>
    <?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    admin_require_csrf();

    if ($action === 'create_category') {
        try {
            $name = admin_validate_category_name((string) ($_POST['category_name'] ?? ''));
            $note = trim((string) ($_POST['category_note'] ?? ''));
            admin_upsert_category($name, $note);
            foreach ($libraryConfigs as $config) {
                image_api_ensure_directory($config['root_dir'] . '/' . $name);
            }
            admin_set_flash('success', '分类已创建。');
            admin_redirect('media.php?category=' . rawurlencode($name));
        } catch (Throwable $throwable) {
            admin_set_flash('error', $throwable->getMessage());
            admin_redirect('media.php');
        }
    }

    if ($action === 'update_category_note') {
        try {
            $name = trim((string) ($_POST['category_name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('分类名称不能为空。');
            }

            if ($name !== admin_default_category_name()) {
                $name = admin_validate_category_name($name);
            }

            $note = trim((string) ($_POST['category_note'] ?? ''));
            admin_upsert_category($name, $note);
            admin_set_flash('success', '备注已保存。');
            admin_redirect('media.php?category=' . rawurlencode($name));
        } catch (Throwable $throwable) {
            admin_set_flash('error', $throwable->getMessage());
            admin_redirect('media.php');
        }
    }
}

$flash = admin_pull_flash();
$categories = admin_read_categories();
$libraryItems = [];
foreach ($libraryConfigs as $libraryKey => $config) {
    $libraryItems[$libraryKey] = admin_scan_library($config['root_dir'], $libraryKey);
}

$allImages = array_merge($libraryItems['pc'], $libraryItems['m']);
$categorySummaries = admin_build_category_summaries($libraryItems, $categories);
$librarySummary = admin_library_summary($libraryItems);
$currentCategory = trim((string) ($_GET['category'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));
$page = admin_query_int('page', 1, 1);
$perPage = admin_query_per_page('per_page', 25);
$libraryFilter = (string) ($_GET['library'] ?? 'all');
if (!in_array($libraryFilter, ['all', 'pc', 'm'], true)) {
    $libraryFilter = 'all';
}

if ($currentCategory !== '' && !media_category_exists($categorySummaries, $currentCategory)) {
    admin_set_flash('error', '分类不存在。');
    admin_redirect('media.php');
}

$browserView = $currentCategory === '' ? 'categories' : 'images';
$currentCategorySummary = $currentCategory === '' ? null : media_find_category_summary($categorySummaries, $currentCategory);
$browserItems = $browserView === 'categories'
    ? media_filter_categories($categorySummaries, $search)
    : media_filter_images($allImages, $currentCategory, $libraryFilter, $search);
$pagination = admin_paginate_array($browserItems, $page, $perPage);

admin_render_layout_start('图片管理', 'media.php', [
    'headline' => '上传与资源浏览',
    'description' => '支持分类上传、拖拽上传、文件夹上传、失败重试、无刷新分页、批量删除和图片全屏预览。',
    'badges' => ['Category Upload', 'Drag Upload', 'Folder Upload', 'Chunk Upload', 'Batch Delete'],
    'auto_refresh_seconds' => 0,
    'mini_stats' => [
        [
            'label' => 'PC 图片',
            'value' => number_format((int) ($librarySummary['pc']['count'] ?? 0)),
            'note' => image_api_format_file_size((int) ($librarySummary['pc']['bytes'] ?? 0)),
        ],
        [
            'label' => '手机图片',
            'value' => number_format((int) ($librarySummary['m']['count'] ?? 0)),
            'note' => image_api_format_file_size((int) ($librarySummary['m']['bytes'] ?? 0)),
        ],
        [
            'label' => '分类',
            'value' => number_format(count($categorySummaries)),
            'note' => '默认分类固定保留',
        ],
    ],
]);
?>
<?php if ($flash !== null): ?>
    <div class="notice <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<style>
.upload-workspace {
    display: grid;
    grid-template-columns: 1.12fr 0.88fr;
    gap: 20px;
}

.upload-panel,
.upload-summary,
.browser-create-form,
.category-note-form {
    display: grid;
    gap: 18px;
}

.upload-dropzone,
.browser-create,
.category-card {
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    background: var(--panel-strong);
}

.upload-dropzone {
    position: relative;
    padding: 28px;
    border-style: dashed;
    text-align: center;
    transition: border-color 140ms ease, background 140ms ease;
}

.upload-dropzone.dragover {
    border-color: var(--ink);
    background: var(--panel-soft);
}

.upload-dropzone-title {
    font-size: 20px;
    line-height: 1.2;
    margin: 10px 0 0;
    font-weight: 600;
}

.upload-dropzone-copy,
.upload-drop-hint,
.upload-recovery-note,
.category-note,
.browser-note {
    color: var(--muted);
    line-height: 1.65;
}

.upload-dropzone-copy {
    font-size: 13px;
    max-width: 500px;
    margin: 10px auto 0;
}

.upload-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    margin-top: 18px;
}

.upload-picker {
    flex: 1;
}

.upload-picker button {
    width: 100%;
}

.upload-ghost-input {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
}

.upload-drop-hint {
    margin-top: 16px;
    font-size: 11.5px;
    font-family: "JetBrains Mono", Consolas, monospace;
}

.upload-queue-shell {
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    background: var(--panel-strong);
    padding: 20px;
    display: grid;
    gap: 14px;
}

.upload-queue-head,
.viewer-toolbar,
.category-card-head,
.category-summary-strip {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.upload-queue {
    display: grid;
    gap: 8px;
    max-height: 320px;
    overflow: auto;
    padding-right: 4px;
}

.upload-item {
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr);
    gap: 12px;
    align-items: center;
    padding: 10px;
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    background: var(--panel-strong);
}

.upload-item-preview,
.thumb {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-sm);
    object-fit: cover;
    border: 1px solid var(--line);
    background: var(--placeholder-bg);
}

.upload-item-body {
    display: grid;
    gap: 6px;
    min-width: 0;
}

.upload-item-meta {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: center;
}

.upload-item-name {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ink);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.upload-item-sub,
.upload-status,
.mono {
    font-family: "JetBrains Mono", Consolas, monospace;
}

.upload-item-sub,
.upload-status {
    color: var(--muted);
    font-size: 11px;
}

.upload-total-progress,
.upload-progress {
    overflow: hidden;
    background: var(--progress-track);
}

.upload-total-progress {
    border-radius: 4px;
    height: 6px;
}

.upload-progress {
    border-radius: 3px;
    height: 4px;
}

.upload-total-progress > div,
.upload-progress > div {
    height: 100%;
    width: 0;
    background: var(--accent);
    transition: width 140ms ease;
}

.upload-total-meta {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    font-size: 11.5px;
    color: var(--muted);
    font-family: "JetBrains Mono", Consolas, monospace;
    margin-top: 8px;
}

.browser-create {
    padding: 18px;
    margin-bottom: 18px;
}

.category-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
}

.category-card {
    padding: 18px;
    display: grid;
    gap: 16px;
}

.category-name {
    font-size: 16px;
    font-weight: 600;
    color: var(--ink);
}

.category-note {
    font-size: 12px;
    margin-top: 6px;
}

.category-stats {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.category-summary-strip {
    margin-bottom: 16px;
}

.category-empty {
    grid-column: 1 / -1;
}

.media-bulk-bar {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.media-entry.is-selected {
    background: var(--selection-soft);
}

.media-select-cell {
    width: 42px;
    text-align: center;
}

.media-select-input {
    width: 16px;
    height: 16px;
}

.media-thumb-button,
.queue-preview-button {
    border: 0;
    padding: 0;
    background: transparent;
    cursor: pointer;
}

.table-wrap {
    overflow: auto;
}

.table-wrap table {
    width: 100%;
    border-collapse: collapse;
}

.table-wrap th,
.table-wrap td {
    padding: 12px 10px;
    border-bottom: 1px solid var(--line);
    text-align: left;
    vertical-align: middle;
}

.image-preview-modal {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 32px;
    background: rgba(10, 10, 10, 0.82);
    backdrop-filter: blur(12px);
}

.image-preview-modal.is-open {
    display: flex;
}

.image-preview-dialog {
    position: relative;
    width: min(96vw, 1400px);
    max-height: 92vh;
    display: grid;
    gap: 14px;
    justify-items: center;
}

.image-preview-close {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 42px;
    height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.22);
    background: rgba(16, 18, 24, 0.86);
    color: rgba(255, 255, 255, 0.96);
    font-size: 26px;
    font-weight: 600;
    line-height: 1;
    cursor: pointer;
}

.image-preview-stage {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: min(70vh, 720px);
    border-radius: 24px;
    background: radial-gradient(circle at top, rgba(255, 255, 255, 0.08), transparent 50%), rgba(20, 20, 20, 0.82);
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.35);
    overflow: hidden;
}

.image-preview-stage img {
    display: block;
    max-width: 100%;
    max-height: 82vh;
    object-fit: contain;
}

.image-preview-caption {
    max-width: min(92vw, 1200px);
    color: rgba(255, 255, 255, 0.88);
    font-size: 13px;
    line-height: 1.6;
    text-align: center;
    word-break: break-word;
}

/* === TWO-PANEL MEDIA LAYOUT === */
.media-shell {
    display: grid;
    grid-template-columns: 240px minmax(0, 1fr);
    gap: 20px;
    align-items: start;
}

.media-sidebar {
    position: sticky;
    top: 24px;
    max-height: calc(100vh - 48px);
    display: flex;
    flex-direction: column;
}

.media-sidebar-inner {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: calc(100vh - 48px);
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--scroll-thumb) var(--scroll-track);
}

.media-sidebar-inner::-webkit-scrollbar { width: 6px; }
.media-sidebar-inner::-webkit-scrollbar-track { background: transparent; }
.media-sidebar-inner::-webkit-scrollbar-thumb { background: var(--scroll-thumb); border-radius: 3px; }

/* New folder form */
.folder-new-shell {
    display: none;
}

.folder-new-shell.is-open {
    display: block;
}

.folder-new-form {
    display: grid;
    gap: 8px;
}

.folder-new-trigger {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    padding: 10px 14px;
    border: 1px dashed var(--line-strong);
    border-radius: var(--radius-md);
    background: transparent;
    color: var(--muted);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 120ms ease, border-color 120ms ease, color 120ms ease;
}

.folder-new-trigger:hover {
    background: var(--panel-soft);
    border-color: var(--accent);
    color: var(--ink);
}

/* Folder tree */
.folder-tree {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.folder-tree-head {
    padding: 2px 4px 6px;
}

.folder-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    background: transparent;
    text-decoration: none;
    color: var(--ink);
    transition: background 120ms ease, border-color 120ms ease;
    cursor: pointer;
}

.folder-link:hover {
    background: var(--accent-soft);
    border-color: var(--line);
}

.folder-link.active {
    background: var(--accent-soft);
    border-color: var(--line-strong);
    font-weight: 600;
}

.folder-link .folder-icon {
    position: relative;
    width: 20px;
    height: 15px;
    flex: 0 0 auto;
    border-radius: 3px 3px 5px 5px;
    background: linear-gradient(180deg, #ffd86f 0%, #f3b63b 100%);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.42);
}

.folder-link .folder-icon::before {
    content: "";
    position: absolute;
    left: 1px;
    top: -3px;
    width: 10px;
    height: 6px;
    border-radius: 3px 3px 0 0;
    background: #ffe39e;
}

.folder-link-name {
    flex: 1;
    min-width: 0;
    font-size: 13.5px;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 6px;
}

.folder-link-count {
    font-family: "JetBrains Mono", Consolas, monospace;
    font-size: 11px;
    color: var(--muted);
    flex: 0 0 auto;
}

.media-main {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.folder-back-link {
    font-size: 13px;
    font-weight: 600;
    color: var(--muted);
    padding: 6px 10px;
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    background: var(--panel-soft);
    transition: background 120ms ease, color 120ms ease;
}

.folder-back-link:hover {
    background: var(--accent-soft);
    color: var(--ink);
}

@media (max-width: 960px) {
    .media-shell {
        grid-template-columns: 1fr;
    }

    .media-sidebar {
        position: static;
        max-height: none;
    }

    .media-sidebar-inner {
        max-height: none;
        flex-direction: row;
        flex-wrap: wrap;
        padding: 12px;
        gap: 8px;
    }

    .folder-new-shell,
    .folder-new-trigger {
        display: none;
    }

    .folder-tree {
        flex-direction: row;
        flex-wrap: wrap;
        gap: 6px;
        max-height: none;
    }

    .folder-tree-head {
        width: 100%;
    }

    .folder-link {
        padding: 7px 10px;
    }

    .folder-link-count {
        display: none;
    }
}

@media (max-width: 1180px) {
    .upload-workspace {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 960px) {
    .folder-table {
        min-width: 680px;
    }
    .folder-table th:last-child,
    .folder-table td:last-child {
        position: sticky;
        right: 0;
        background: inherit;
        z-index: 2;
    }
}
</style>

<div class="content-stack">
    <section class="upload-workspace" id="upload">
        <div class="panel section upload-panel">
            <div class="section-head">
                <div>
                    <div class="eyebrow">UPLOAD</div>
                    <h2 class="section-title">资源上传</h2>
                </div>
                <div class="section-kicker">先手动选择分类，再按宽高自动进入 PC 或手机图片库。支持拖拽、文件夹上传、切片上传和重复图片自动跳过。</div>
            </div>

            <div class="form-grid" style="margin-bottom: 4px;">
                <div class="field">
                    <label for="upload-category">上传分类</label>
                    <select id="upload-category">
                        <?php foreach ($categorySummaries as $summary): ?>
                            <option value="<?= htmlspecialchars((string) $summary['name'], ENT_QUOTES, 'UTF-8') ?>"<?= (string) $summary['name'] === admin_default_category_name() ? ' selected' : '' ?>><?= htmlspecialchars((string) $summary['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>分类规则</label>
                    <div class="browser-note">宽图自动进入 PC，竖图自动进入手机；分类由上方手动选择。</div>
                </div>
            </div>

            <div class="upload-dropzone" id="upload-dropzone">
                <input id="chunk-files" class="upload-ghost-input" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,image/*" multiple>
                <input id="chunk-folder" class="upload-ghost-input" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,image/*" multiple webkitdirectory directory>

                <div>
                    <div class="upload-dropzone-title">拖拽图片或文件夹到这里</div>
                    <div class="upload-dropzone-copy">支持批量图片、文件夹导入、拖拽识别和失败后仅重试失败文件。</div>
                </div>

                <div class="upload-actions">
                    <div class="upload-picker">
                        <button type="button" id="pick-files">选择文件</button>
                    </div>
                    <div class="upload-picker">
                        <button type="button" id="pick-folder">选择文件夹</button>
                    </div>
                </div>

                <div class="upload-drop-hint">格式限制: JPEG, PNG, GIF, WEBP | 切片大小: <?= htmlspecialchars(image_api_format_file_size((int) ADMIN_CHUNK_SIZE), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <div class="upload-summary">
            <div class="upload-queue-shell">
                <div class="upload-queue-head" style="border-bottom: 1px solid var(--line); padding-bottom: 14px; margin-bottom: 14px;">
                    <div>
                        <div class="eyebrow">QUEUE</div>
                        <div style="margin-top: 4px; font-size: 15px; font-weight: 600;">已选资源</div>
                    </div>
                    <div class="badge"><span id="queue-count">0</span> 项</div>
                </div>

                <div id="upload-total-container" style="display: none; border-bottom: 1px solid var(--line); padding-bottom: 16px; margin-bottom: 16px;">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
                        <div>
                            <div class="eyebrow">PROGRESS</div>
                            <div style="margin-top: 4px; font-size: 14px; font-weight: 600;">总进度</div>
                        </div>
                        <div id="upload-total-percent" style="font-family:'JetBrains Mono',Consolas,monospace;font-size:22px;font-weight:600;">0%</div>
                    </div>
                    <div class="upload-total-progress" style="margin-top: 12px;">
                        <div id="upload-total-progress-bar"></div>
                    </div>
                    <div class="upload-total-meta">
                        <span id="upload-total-meta">0 / 0 FILES</span>
                        <span id="upload-total-size">0 B / 0 B</span>
                    </div>

                    <div style="margin-top: 16px;">
                        <button type="button" id="start-upload" style="width:100%;">开始批量上传</button>
                    </div>

                    <div id="upload-recovery-actions" style="display: none; margin-top: 12px;">
                        <div class="upload-actions" style="margin-top: 0;">
                            <div class="upload-picker">
                                <button type="button" id="retry-failed-button" class="secondary">重试失败文件</button>
                            </div>
                            <div class="upload-picker">
                                <button type="button" id="clear-reupload-button" class="secondary">清空重新上传</button>
                            </div>
                        </div>
                        <div class="upload-recovery-note">上传失败后，可以只重试失败文件，也可以清空后重新选择资源。</div>
                    </div>
                </div>

                <div id="upload-queue" class="upload-queue">
                    <div class="empty" id="upload-queue-empty" style="font-size: 13px; color: var(--muted); text-align: center; padding: 24px 0;">队列为空，请选择文件、文件夹或直接拖拽到左侧区域。</div>
                </div>
            </div>
        </div>
    </section>

    <div class="media-shell">
        <aside class="media-sidebar">
            <div class="media-sidebar-inner">

                <button type="button" class="folder-new-trigger" id="folder-new-trigger">+ 新建分类</button>

                <div class="folder-new-shell" id="folder-new-shell">
                    <form method="post" class="folder-new-form">
                        <input type="hidden" name="action" value="create_category">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="field">
                            <input
                                id="new-category-name"
                                name="category_name"
                                placeholder="wallpaper"
                                autocomplete="off"
                            >
                        </div>
                        <div class="toolbar" style="margin-top: 8px; gap: 6px;">
                            <button type="submit" class="secondary" style="min-width: 0; font-size: 12px; padding: 8px 10px;">新建</button>
                            <button type="button" class="folder-new-cancel secondary" style="min-width: 0; font-size: 12px; padding: 8px 10px;">取消</button>
                        </div>
                    </form>
                </div>

                <div class="folder-tree" id="folder-tree">
                    <div class="folder-tree-head">
                        <span class="eyebrow">分类</span>
                    </div>

                    <a class="folder-link <?= $browserView === 'categories' ? 'active' : '' ?>"
                       href="media.php"
                       data-folder-href="media.php">
                        <span class="folder-icon" aria-hidden="true"></span>
                        <span class="folder-link-name">全部分类</span>
                        <span class="folder-link-count"><?= number_format(count($categorySummaries)) ?></span>
                    </a>

                    <?php foreach ($categorySummaries as $summary): ?>
                        <?php $isActive = $currentCategory === (string) $summary['name']; ?>
                        <a class="folder-link <?= $isActive ? 'active' : '' ?>"
                           href="media.php?category=<?= rawurlencode((string) $summary['name']) ?>"
                           data-folder-href="media.php?category=<?= rawurlencode((string) $summary['name']) ?>">
                            <span class="folder-icon" aria-hidden="true"></span>
                            <span class="folder-link-name">
                                <?= htmlspecialchars((string) $summary['name'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if ((string) $summary['name'] === admin_default_category_name()): ?>
                                    <span class="badge" style="font-size: 9px; padding: 2px 5px;">default</span>
                                <?php endif; ?>
                            </span>
                            <span class="folder-link-count"><?= number_format((int) $summary['total_count']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>

        <div class="media-main">
            <?php media_render_browser($browserView, $pagination, $search, $perPage, $categorySummaries, $currentCategorySummary, $currentCategory, $libraryFilter); ?>
        </div>
    </div>
</div>

<div id="image-preview-modal" class="image-preview-modal" aria-hidden="true">
    <div class="image-preview-dialog" role="dialog" aria-modal="true" aria-label="图片预览">
        <button type="button" id="image-preview-close" class="image-preview-close" aria-label="关闭预览">&times;</button>
        <div class="image-preview-stage">
            <img id="image-preview-target" src="" alt="">
        </div>
        <div id="image-preview-caption" class="image-preview-caption"></div>
    </div>
</div>

<script>
const fileInput = document.getElementById('chunk-files');
const folderInput = document.getElementById('chunk-folder');
const pickFilesButton = document.getElementById('pick-files');
const pickFolderButton = document.getElementById('pick-folder');
const startButton = document.getElementById('start-upload');
const queue = document.getElementById('upload-queue');
const queueEmpty = document.getElementById('upload-queue-empty');
const queueCount = document.getElementById('queue-count');
const dropzone = document.getElementById('upload-dropzone');
const uploadCategorySelect = document.getElementById('upload-category');
const totalProgressBar = document.getElementById('upload-total-progress-bar');
const totalPercent = document.getElementById('upload-total-percent');
const totalMeta = document.getElementById('upload-total-meta');
const totalSize = document.getElementById('upload-total-size');
const totalContainer = document.getElementById('upload-total-container');
const recoveryActions = document.getElementById('upload-recovery-actions');
const retryFailedButton = document.getElementById('retry-failed-button');
const clearReuploadButton = document.getElementById('clear-reupload-button');
const imagePreviewModal = document.getElementById('image-preview-modal');
const imagePreviewTarget = document.getElementById('image-preview-target');
const imagePreviewCaption = document.getElementById('image-preview-caption');
const imagePreviewClose = document.getElementById('image-preview-close');
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const chunkSize = <?= ADMIN_CHUNK_SIZE ?>;
const maxUploadFileSize = <?= ADMIN_MAX_UPLOAD_FILE_SIZE ?>;
const maxImageWidth = <?= ADMIN_MAX_IMAGE_WIDTH ?>;
const maxImageHeight = <?= ADMIN_MAX_IMAGE_HEIGHT ?>;
const EMPTY_IMAGE_SRC = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

let filterTimer = null;
let selectedFilesList = [];
let uploadProgressState = [];
let isUploading = false;
let lazyImageObserver = null;
let libraryFilter = null;
let searchInput = null;
let bulkDeleteButton = null;
let selectedCountNode = null;

function formatBytes(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unitIndex = 0;
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
    }

    return `${size.toFixed(size >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

function isSupportedImageFile(file) {
    return file instanceof File
        && (file.type.startsWith('image/') || /\.(jpe?g|png|gif|webp)$/i.test(file.name));
}

function fileSignatureLooksValid(buffer) {
    const bytes = new Uint8Array(buffer);
    if (bytes.length < 12) {
        return false;
    }

    const isJpeg = bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff;
    const isPng = bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47;
    const isGif = bytes[0] === 0x47 && bytes[1] === 0x49 && bytes[2] === 0x46;
    const isWebp = bytes[0] === 0x52 && bytes[1] === 0x49 && bytes[2] === 0x46 && bytes[3] === 0x46
        && bytes[8] === 0x57 && bytes[9] === 0x45 && bytes[10] === 0x42 && bytes[11] === 0x50;

    return isJpeg || isPng || isGif || isWebp;
}

function readImageMeta(file) {
    return new Promise((resolve, reject) => {
        const objectUrl = URL.createObjectURL(file);
        const image = new Image();

        image.onload = () => {
            resolve({ width: image.naturalWidth, height: image.naturalHeight });
            URL.revokeObjectURL(objectUrl);
        };

        image.onerror = () => {
            URL.revokeObjectURL(objectUrl);
            reject(new Error('无法读取图片内容'));
        };

        image.src = objectUrl;
    });
}

async function validateFileBeforeUpload(file) {
    if (!isSupportedImageFile(file)) {
        throw new Error('仅支持 JPG、PNG、GIF、WEBP 图片');
    }

    if (!Number.isFinite(file.size) || file.size <= 0) {
        throw new Error('图片文件为空');
    }

    if (file.size > maxUploadFileSize) {
        throw new Error(`图片大小不能超过 ${formatBytes(maxUploadFileSize)}`);
    }

    const headerBuffer = await file.slice(0, 16).arrayBuffer();
    if (!fileSignatureLooksValid(headerBuffer)) {
        throw new Error('图片文件头校验失败');
    }

    const meta = await readImageMeta(file);
    if (meta.width <= 0 || meta.height <= 0) {
        throw new Error('图片尺寸无效');
    }

    if (meta.width > maxImageWidth || meta.height > maxImageHeight) {
        throw new Error(`图片尺寸不能超过 ${maxImageWidth}x${maxImageHeight}`);
    }
}

function setUploadControlsDisabled(disabled) {
    isUploading = disabled;
    startButton.disabled = disabled;
    pickFilesButton.disabled = disabled;
    pickFolderButton.disabled = disabled;
    fileInput.disabled = disabled;
    folderInput.disabled = disabled;
    if (uploadCategorySelect) {
        uploadCategorySelect.disabled = disabled;
    }
    updateRecoveryActions();
}

function mergeIncomingFiles(fileList) {
    const incoming = Array.from(fileList || []).filter((file) => file.type.startsWith('image/') || /\.(jpe?g|png|gif|webp)$/i.test(file.name));
    const seen = new Set(selectedFilesList.map((file) => `${file.name}__${file.size}__${file.lastModified}`));

    incoming.forEach((file) => {
        const key = `${file.name}__${file.size}__${file.lastModified}`;
        if (!seen.has(key)) {
            selectedFilesList.push(file);
            seen.add(key);
        }
    });

    uploadProgressState = selectedFilesList.map((file) => ({
        name: file.name,
        size: file.size,
        uploadedBytes: 0,
        complete: false,
        failed: false
    }));
}

function updateRecoveryActions() {
    if (!recoveryActions) {
        return;
    }

    const failedCount = uploadProgressState.filter((item) => item.failed).length;
    recoveryActions.style.display = failedCount > 0 ? 'block' : 'none';
    retryFailedButton.disabled = failedCount === 0 || isUploading;
    clearReuploadButton.disabled = isUploading;
}

function updateGlobalProgress() {
    const totalFiles = uploadProgressState.length;
    const completedFiles = uploadProgressState.filter((item) => item.complete).length;
    const uploadedBytes = uploadProgressState.reduce((sum, item) => sum + item.uploadedBytes, 0);
    const totalBytes = uploadProgressState.reduce((sum, item) => sum + item.size, 0);
    const percent = totalBytes > 0 ? Math.round((uploadedBytes / totalBytes) * 100) : 0;

    totalProgressBar.style.width = `${percent}%`;
    totalPercent.textContent = `${percent}%`;
    totalMeta.textContent = `${completedFiles} / ${totalFiles} FILES`;
    totalSize.textContent = `${formatBytes(uploadedBytes)} / ${formatBytes(totalBytes)}`;
    queueCount.textContent = String(totalFiles);
    totalContainer.style.display = totalFiles > 0 ? 'block' : 'none';
    updateRecoveryActions();
}

function clearUploadSelection(confirmBeforeClear = true) {
    if (isUploading) {
        return;
    }

    if (confirmBeforeClear && selectedFilesList.length > 0 && !window.confirm('确定要清空当前上传队列吗？')) {
        return;
    }

    selectedFilesList = [];
    uploadProgressState = [];
    queue.innerHTML = '';
    queue.appendChild(queueEmpty);
    queueEmpty.style.display = '';
    fileInput.value = '';
    folderInput.value = '';
    updateGlobalProgress();
}

function markUploadFailed(index, statusNode, message) {
    const normalizedMessage = String(message || 'Upload failed');

    if (uploadProgressState[index]) {
        uploadProgressState[index].failed = true;
        uploadProgressState[index].complete = false;
    }

    if (statusNode) {
        statusNode.style.color = 'var(--danger)';
        statusNode.textContent = `FAILED: ${normalizedMessage}`;
    }

    updateGlobalProgress();
}

function resetUploadItemState(index, nextLabel = 'WAITING') {
    const item = uploadProgressState[index];
    if (item) {
        item.failed = false;
        item.complete = false;
        item.uploadedBytes = 0;
    }

    const element = document.getElementById(`upload-item-${index}`);
    if (!element) {
        updateGlobalProgress();
        return;
    }

    const progressBar = element.querySelector('.progress-bar');
    const status = element.querySelector('.status');

    if (progressBar) {
        progressBar.style.width = '0%';
    }

    if (status) {
        status.style.color = '';
        status.textContent = nextLabel;
    }

    updateGlobalProgress();
}

function loadLazyImage(image) {
    if (!(image instanceof HTMLImageElement)) {
        return;
    }

    const source = image.dataset.lazySrc;
    if (!source || image.dataset.loaded === '1') {
        return;
    }

    image.src = source;
    image.dataset.loaded = '1';
}

function ensureLazyImageObserver() {
    if (lazyImageObserver || !('IntersectionObserver' in window)) {
        return;
    }

    lazyImageObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }

            loadLazyImage(entry.target);
            lazyImageObserver.unobserve(entry.target);
        });
    }, {
        root: null,
        rootMargin: '180px 0px',
        threshold: 0.01
    });
}

function bindLazyImages(scope = document) {
    const images = scope.querySelectorAll('img[data-lazy-src]');
    if (images.length === 0) {
        return;
    }

    if (!('IntersectionObserver' in window)) {
        images.forEach((image) => loadLazyImage(image));
        return;
    }

    ensureLazyImageObserver();
    images.forEach((image) => {
        if (image.dataset.loaded === '1') {
            return;
        }

        if (!image.getAttribute('src')) {
            image.setAttribute('src', EMPTY_IMAGE_SRC);
        }

        lazyImageObserver.observe(image);
    });
}

function openImagePreview(source, caption = '') {
    if (!source) {
        return;
    }

    imagePreviewTarget.src = source;
    imagePreviewCaption.textContent = caption;
    imagePreviewModal.classList.add('is-open');
    imagePreviewModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeImagePreview() {
    imagePreviewModal.classList.remove('is-open');
    imagePreviewModal.setAttribute('aria-hidden', 'true');
    imagePreviewTarget.src = '';
    imagePreviewCaption.textContent = '';
    document.body.style.overflow = '';
}

function bindPreviewTriggers(scope = document) {
    const triggers = scope.querySelectorAll('.preview-trigger');
    triggers.forEach((trigger) => {
        if (trigger.dataset.previewBound === '1') {
            return;
        }

        trigger.dataset.previewBound = '1';
        trigger.addEventListener('click', () => {
            const previewImage = trigger.querySelector('img');
            const source = trigger.dataset.previewSrc
                || previewImage?.dataset.lazySrc
                || (previewImage && previewImage.getAttribute('src') !== EMPTY_IMAGE_SRC ? previewImage.getAttribute('src') : '')
                || '';

            const caption = trigger.dataset.previewCaption || previewImage?.alt || '';
            if (previewImage?.dataset.lazySrc && previewImage.dataset.loaded !== '1') {
                loadLazyImage(previewImage);
            }

            openImagePreview(source || previewImage?.currentSrc || '', caption);
        });
    });
}

function normalizeDialogMessage(message) {
    return String(message || '')
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/p>\s*<p>/gi, '\n\n')
        .replace(/<[^>]+>/g, '')
        .replace(/\r\n/g, '\n')
        .trim();
}

function ensureFeedbackDialog() {
    let dialog = document.getElementById('feedback-dialog');
    if (dialog) {
        return dialog;
    }

    dialog = document.createElement('div');
    dialog.id = 'feedback-dialog';
    dialog.style.cssText = 'position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(8,12,18,.58);backdrop-filter:blur(10px);z-index:90;';
    dialog.innerHTML = `
        <div style="width:min(92vw,680px);display:grid;gap:14px;padding:22px;border:1px solid var(--line);border-radius:22px;background:var(--panel);box-shadow:0 30px 80px rgba(0,0,0,.24);">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
                <div>
                    <div class="eyebrow" id="feedback-dialog-kicker">DETAIL</div>
                    <h3 id="feedback-dialog-title" style="margin-top:6px;font-size:22px;line-height:1.2;">提示</h3>
                </div>
                <button type="button" id="feedback-dialog-close" class="secondary" style="width:auto;min-width:0;padding:10px 14px;">关闭</button>
            </div>
            <div class="browser-note">可以直接复制下面的内容。</div>
            <textarea id="feedback-dialog-body" readonly style="min-height:220px;resize:vertical;white-space:pre-wrap;"></textarea>
            <div style="display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;">
                <button type="button" id="feedback-dialog-copy-button" class="secondary" style="width:auto;">复制内容</button>
                <button type="button" id="feedback-dialog-ok" style="width:auto;">知道了</button>
            </div>
        </div>
    `;

    document.body.appendChild(dialog);

    const hide = () => {
        dialog.style.display = 'none';
    };

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            hide();
        }
    });

    dialog.querySelector('#feedback-dialog-close')?.addEventListener('click', hide);
    dialog.querySelector('#feedback-dialog-ok')?.addEventListener('click', hide);
    dialog.querySelector('#feedback-dialog-copy-button')?.addEventListener('click', async () => {
        const textarea = dialog.querySelector('#feedback-dialog-body');
        const text = textarea ? textarea.value : '';

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
            } else if (textarea) {
                textarea.focus();
                textarea.select();
                document.execCommand('copy');
            }
        } catch (error) {
            if (textarea) {
                textarea.focus();
                textarea.select();
            }
        }
    });

    return dialog;
}

function showFeedbackDialog(message, title = '提示', kicker = 'DETAIL') {
    const dialog = ensureFeedbackDialog();
    const text = normalizeDialogMessage(message) || '暂无更多信息。';
    const titleNode = dialog.querySelector('#feedback-dialog-title');
    const kickerNode = dialog.querySelector('#feedback-dialog-kicker');
    const bodyNode = dialog.querySelector('#feedback-dialog-body');

    if (titleNode) {
        titleNode.textContent = title;
    }
    if (kickerNode) {
        kickerNode.textContent = kicker;
    }
    if (bodyNode) {
        bodyNode.value = text;
        bodyNode.scrollTop = 0;
    }

    dialog.style.display = 'flex';
}

window.alert = (message) => {
    showFeedbackDialog(message, '提示', 'MESSAGE');
};

function renderSelectedFiles() {
    queue.innerHTML = '';

    if (selectedFilesList.length === 0) {
        queue.appendChild(queueEmpty);
        queueEmpty.style.display = '';
        updateGlobalProgress();
        return;
    }

    queueEmpty.style.display = 'none';

    selectedFilesList.forEach((file, index) => {
        const localUrl = URL.createObjectURL(file);
        const wrapper = document.createElement('div');
        wrapper.className = 'upload-item';
        wrapper.id = `upload-item-${index}`;

        wrapper.innerHTML = `
            <button type="button" class="queue-preview-button preview-trigger" data-preview-src="${localUrl}" data-preview-caption="${file.webkitRelativePath || file.name}">
                <img class="upload-item-preview lazy-preview-image" src="${EMPTY_IMAGE_SRC}" data-lazy-src="${localUrl}" alt="">
            </button>
            <div class="upload-item-body">
                <div class="upload-item-meta">
                    <div class="upload-item-name" title="${file.webkitRelativePath || file.name}">${file.webkitRelativePath || file.name}</div>
                    <div class="upload-item-sub">${formatBytes(file.size)}</div>
                </div>
                <div class="upload-progress"><div class="progress-bar"></div></div>
                <div class="upload-status status">WAITING</div>
            </div>
        `;

        queue.appendChild(wrapper);
    });

    bindLazyImages(queue);
    bindPreviewTriggers(queue);
    updateGlobalProgress();
}

function setSelectedFiles(fileList, append = false) {
    if (!append) {
        selectedFilesList = [];
    }

    mergeIncomingFiles(fileList);
    renderSelectedFiles();
    fileInput.value = '';
    folderInput.value = '';
}

async function uploadFileInChunks(file, index) {
    const element = document.getElementById(`upload-item-${index}`);
    if (!element) {
        return { duplicate: false };
    }

    const progressBar = element.querySelector('.progress-bar');
    const status = element.querySelector('.status');
    const totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
    const uploadId = `${Date.now()}-${Math.random().toString(16).slice(2)}-${file.name.length}-${index}`;
    let duplicateResult = false;

    try {
        for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
            const start = chunkIndex * chunkSize;
            const end = Math.min(file.size, start + chunkSize);
            const chunk = file.slice(start, end);
            const formData = new FormData();
            formData.append('chunk', chunk);
            formData.append('upload_id', uploadId);
            formData.append('file_name', file.name);
            formData.append('file_type', file.type || '');
            formData.append('chunk_index', String(chunkIndex));
            formData.append('total_chunks', String(totalChunks));
            formData.append('total_size', String(file.size));
            formData.append('category', uploadCategorySelect?.value || 'default');

            status.style.color = '';
            status.textContent = `UPLOADING ${chunkIndex + 1}/${totalChunks}`;

            const response = await fetch('upload_chunk.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: formData,
                credentials: 'same-origin'
            });

            const rawText = await response.text();
            let data = null;
            try {
                data = rawText === '' ? null : JSON.parse(rawText);
            } catch (error) {
                throw new Error(rawText || `Server returned invalid JSON (HTTP ${response.status})`);
            }

            if (data === null) {
                throw new Error(`Server returned empty response (HTTP ${response.status})`);
            }

            if (!response.ok || !data.ok) {
                throw new Error(data.detail ? `${data.message || `HTTP ${response.status}`}\n\n${data.detail}` : (data.message || `HTTP ${response.status}`));
            }

            uploadProgressState[index].uploadedBytes = end;
            progressBar.style.width = `${Math.round((end / Math.max(file.size, 1)) * 100)}%`;
            updateGlobalProgress();

            if (data.completed && data.record) {
                uploadProgressState[index].complete = true;
                uploadProgressState[index].uploadedBytes = file.size;
                uploadProgressState[index].failed = false;

                if (data.duplicate || data.skipped) {
                    duplicateResult = true;
                    status.style.color = 'var(--warn)';
                    status.textContent = `SKIPPED: ${data.message || '图片已存在'}`;
                } else {
                    status.style.color = 'var(--success)';
                    status.textContent = `SUCCESS: ${data.record.category} / ${data.record.library_label}`;
                }

                updateGlobalProgress();
            }
        }
    } catch (error) {
        markUploadFailed(index, status, error?.message || 'Upload failed');
        throw error;
    }

    return { duplicate: duplicateResult };
}

async function runUploadBatch(indexes) {
    let duplicateCount = 0;
    const errors = [];

    for (const index of indexes) {
        const file = selectedFilesList[index];
        if (!file) {
            continue;
        }

        try {
            const result = await uploadFileInChunks(file, index);
            if (result.duplicate) {
                duplicateCount++;
            }
        } catch (error) {
            errors.push({
                index,
                fileName: file.webkitRelativePath || file.name,
                message: error?.message || 'Upload failed'
            });
        }
    }

    return { duplicateCount, errors };
}

async function validatePendingFiles(indexes) {
    for (const index of indexes) {
        const file = selectedFilesList[index];
        if (!file) {
            continue;
        }

        try {
            await validateFileBeforeUpload(file);
        } catch (error) {
            const element = document.getElementById(`upload-item-${index}`);
            const status = element?.querySelector('.status');
            markUploadFailed(index, status || null, error?.message || '图片校验失败');
            throw new Error(`${file.webkitRelativePath || file.name}: ${error?.message || '图片校验失败'}`);
        }
    }
}

async function refreshBrowserAfterUpload() {
    clearUploadSelection(false);
    if (document.getElementById('media-browser')) {
        await fetchMediaBrowser(window.location.href, { pushState: false });
    }
}

async function startUpload() {
    if (selectedFilesList.length === 0) {
        alert('请先选择要上传的图片资源。');
        return;
    }

    const pendingIndexes = uploadProgressState
        .map((item, index) => (!item.complete ? index : -1))
        .filter((index) => index >= 0);

    if (pendingIndexes.length === 0) {
        alert('当前队列已经上传完成。');
        return;
    }

    setUploadControlsDisabled(true);

    try {
        await validatePendingFiles(pendingIndexes);
        const { duplicateCount, errors } = await runUploadBatch(pendingIndexes);

        if (selectedFilesList.length === 1 && duplicateCount === 1 && errors.length === 0) {
            alert('图片已存在');
            return;
        }

        if (errors.length > 0) {
            const summary = errors.length === 1
                ? `上传失败：${errors[0].fileName}\n${errors[0].message}`
                : `共有 ${errors.length} 个文件上传失败，可点击“重试失败文件”继续上传。`;
            alert(summary);
            return;
        }

        await refreshBrowserAfterUpload();
    } catch (error) {
        alert(error?.message || '上传前校验失败');
    } finally {
        setUploadControlsDisabled(false);
    }
}

async function retryFailedUploads() {
    if (isUploading) {
        return;
    }

    const failedIndexes = uploadProgressState
        .map((item, index) => item.failed ? index : -1)
        .filter((index) => index >= 0);

    if (failedIndexes.length === 0) {
        return;
    }

    failedIndexes.forEach((index) => resetUploadItemState(index, 'RETRYING'));
    setUploadControlsDisabled(true);

    try {
        await validatePendingFiles(failedIndexes);
        const { duplicateCount, errors } = await runUploadBatch(failedIndexes);

        if (failedIndexes.length === 1 && duplicateCount === 1 && errors.length === 0) {
            alert('图片已存在');
            return;
        }

        if (errors.length > 0) {
            const summary = errors.length === 1
                ? `重试失败：${errors[0].fileName}\n${errors[0].message}`
                : `仍有 ${errors.length} 个文件上传失败，你可以继续重试，或清空后重新上传。`;
            alert(summary);
            return;
        }

        await refreshBrowserAfterUpload();
    } catch (error) {
        alert(error?.message || '重试前校验失败');
    } finally {
        setUploadControlsDisabled(false);
    }
}

async function fetchMediaBrowser(url, options = {}) {
    const { pushState = true } = options;
    const currentSection = document.getElementById('media-browser');
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
        const nextSection = doc.getElementById('media-browser');
        if (!nextSection) {
            throw new Error('Missing media browser section');
        }

        currentSection.outerHTML = nextSection.outerHTML;
        if (pushState) {
            window.history.pushState({ mediaBrowser: true }, '', url);
        }
        bindMediaBrowserControls();
        syncFolderTreeActiveState();
        const replacedSection = document.getElementById('media-browser');
        if (replacedSection) {
            window.requestAnimationFrame(() => {
                replacedSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }
    } catch (error) {
        window.location.href = url;
    }
}

function syncFolderTreeActiveState() {
    const currentUrl = new URL(window.location.href);
    const currentCategory = currentUrl.searchParams.get('category');
    const folderLinks = document.querySelectorAll('.folder-link');
    folderLinks.forEach((link) => {
        const linkUrl = new URL(link.getAttribute('href') || '', window.location.origin);
        const linkCategory = linkUrl.searchParams.get('category');
        const isActive = (currentCategory === null && linkCategory === null)
            || (currentCategory !== null && currentCategory === linkCategory);
        link.classList.toggle('active', isActive);
    });
}

function refreshMediaPage(immediate = false) {
    const apply = () => {
        if (!searchInput && !libraryFilter) {
            return;
        }

        const url = new URL(window.location.href);
        const library = libraryFilter ? libraryFilter.value : 'all';
        const keyword = searchInput ? searchInput.value.trim() : '';

        if (library === 'all') {
            url.searchParams.delete('library');
        } else {
            url.searchParams.set('library', library);
        }

        if (keyword === '') {
            url.searchParams.delete('q');
        } else {
            url.searchParams.set('q', keyword);
        }

        url.searchParams.set('page', '1');
        fetchMediaBrowser(url.toString());
    };

    if (immediate) {
        apply();
        return;
    }

    window.clearTimeout(filterTimer);
    filterTimer = window.setTimeout(apply, 260);
}

function getSelectedMediaItems() {
    const mediaSection = document.getElementById('media-browser');
    if (!mediaSection) {
        return [];
    }

    return Array.from(mediaSection.querySelectorAll('.media-entry')).flatMap((row) => {
        const checkbox = row.querySelector('.media-row-checkbox');
        if (!checkbox || !checkbox.checked) {
            return [];
        }

        const library = row.dataset.library || '';
        const relativePath = row.dataset.relativePath || '';
        if (library === '' || relativePath === '') {
            return [];
        }

        return [{ library, relative_path: relativePath }];
    });
}

function updateSelectedCounter() {
    const mediaSection = document.getElementById('media-browser');
    if (!mediaSection) {
        return;
    }

    const selectAll = mediaSection.querySelector('#select-all-media');
    const rowCheckboxes = Array.from(mediaSection.querySelectorAll('.media-row-checkbox'));
    const checkedCount = rowCheckboxes.filter((checkbox) => checkbox.checked).length;

    bulkDeleteButton = mediaSection.querySelector('#bulk-delete-button');
    selectedCountNode = mediaSection.querySelector('#selected-count');

    if (selectedCountNode) {
        selectedCountNode.textContent = String(checkedCount);
    }

    if (bulkDeleteButton) {
        bulkDeleteButton.disabled = checkedCount === 0;
    }

    if (selectAll) {
        selectAll.checked = rowCheckboxes.length > 0 && checkedCount === rowCheckboxes.length;
        selectAll.indeterminate = checkedCount > 0 && checkedCount < rowCheckboxes.length;
    }

    mediaSection.querySelectorAll('.media-entry').forEach((row) => {
        const checkbox = row.querySelector('.media-row-checkbox');
        row.classList.toggle('is-selected', !!checkbox?.checked);
    });
}

async function handleBulkDelete() {
    const items = getSelectedMediaItems();
    if (items.length === 0) {
        return;
    }

    const confirmed = window.confirm(`确定要删除选中的 ${items.length} 张图片吗？此操作不可恢复。`);
    if (!confirmed) {
        return;
    }

    if (bulkDeleteButton) {
        bulkDeleteButton.disabled = true;
        bulkDeleteButton.textContent = '删除中...';
    }

    try {
        const response = await fetch('delete_media.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            credentials: 'same-origin',
            body: JSON.stringify({ items })
        });

        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.message || '批量删除失败');
        }

        if (Array.isArray(data.errors) && data.errors.length > 0) {
            alert(`${data.message}\n${data.errors.join('\n')}`);
        }

        await fetchMediaBrowser(window.location.href, { pushState: false });
    } catch (error) {
        alert(error.message || '批量删除失败');
    } finally {
        if (bulkDeleteButton) {
            bulkDeleteButton.textContent = '批量删除';
        }
    }
}

function bindMediaBrowserControls() {
    const mediaSection = document.getElementById('media-browser');
    if (!mediaSection) {
        return;
    }

    libraryFilter = mediaSection.querySelector('#library');
    searchInput = mediaSection.querySelector('#q');
    bulkDeleteButton = mediaSection.querySelector('#bulk-delete-button');
    selectedCountNode = mediaSection.querySelector('#selected-count');

    const perPageSelect = mediaSection.querySelector('.per-page-select');
    const paginationLinks = mediaSection.querySelectorAll('.pagination .page-link:not(.disabled)');
    const selectAll = mediaSection.querySelector('#select-all-media');
    const rowCheckboxes = mediaSection.querySelectorAll('.media-row-checkbox');
    const browserLinks = mediaSection.querySelectorAll('.media-browser-link');
    const folderRows = mediaSection.querySelectorAll('.folder-row');

    if (perPageSelect) {
        perPageSelect.addEventListener('change', () => {
            const url = new URL(window.location.href);
            const queryKey = perPageSelect.dataset.queryKey || 'per_page';
            url.searchParams.set(queryKey, perPageSelect.value);
            url.searchParams.set('page', '1');
            fetchMediaBrowser(url.toString());
        });
    }

    paginationLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const href = link.getAttribute('href');
            if (href) {
                fetchMediaBrowser(href);
            }
        });
    });

    browserLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const href = link.getAttribute('href');
            if (href) {
                fetchMediaBrowser(href);
            }
        });
    });

    folderRows.forEach((row) => {
        row.addEventListener('dblclick', (event) => {
            const target = event.target;
            if (target instanceof HTMLElement && target.closest('input, button, a, select, textarea, label')) {
                return;
            }

            const href = row.dataset.folderHref || '';
            if (href !== '') {
                fetchMediaBrowser(href);
            }
        });
    });

    if (libraryFilter) {
        libraryFilter.addEventListener('change', () => refreshMediaPage(true));
    }

    if (searchInput) {
        searchInput.addEventListener('input', () => refreshMediaPage(false));
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            rowCheckboxes.forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });
            updateSelectedCounter();
        });
    }

    rowCheckboxes.forEach((checkbox) => {
        checkbox.addEventListener('change', updateSelectedCounter);
    });

    if (bulkDeleteButton) {
        bulkDeleteButton.addEventListener('click', handleBulkDelete);
    }

    bindLazyImages(mediaSection);
    bindPreviewTriggers(mediaSection);
    updateSelectedCounter();
}

pickFilesButton.addEventListener('click', () => fileInput.click());
pickFolderButton.addEventListener('click', () => folderInput.click());
fileInput.addEventListener('change', (event) => setSelectedFiles(event.target.files));
folderInput.addEventListener('change', (event) => setSelectedFiles(event.target.files));
startButton.addEventListener('click', startUpload);
retryFailedButton.addEventListener('click', retryFailedUploads);
clearReuploadButton.addEventListener('click', () => clearUploadSelection(true));
imagePreviewClose.addEventListener('click', closeImagePreview);

['dragenter', 'dragover'].forEach((type) => {
    dropzone.addEventListener(type, (event) => {
        event.preventDefault();
        event.stopPropagation();
        dropzone.classList.add('dragover');
    });
});

['dragleave', 'drop'].forEach((type) => {
    dropzone.addEventListener(type, (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (type === 'dragleave' && dropzone.contains(event.relatedTarget)) {
            return;
        }
        dropzone.classList.remove('dragover');
    });
});

dropzone.addEventListener('drop', (event) => {
    const files = event.dataTransfer?.files;
    if (files && files.length > 0) {
        setSelectedFiles(files, true);
    }
});

window.addEventListener('popstate', () => {
    if (document.getElementById('media-browser')) {
        fetchMediaBrowser(window.location.href, { pushState: false });
    }
});

imagePreviewModal.addEventListener('click', (event) => {
    if (event.target === imagePreviewModal) {
        closeImagePreview();
    }
});

window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && imagePreviewModal.classList.contains('is-open')) {
        closeImagePreview();
    }
});

// Folder tree navigation (sidebar links)
document.addEventListener('click', (event) => {
    const link = event.target.closest('.folder-link');
    if (!link) return;
    event.preventDefault();
    const href = link.getAttribute('href');
    if (href) {
        fetchMediaBrowser(href);
    }
});

// Toggle "New Folder" inline form
const folderNewTrigger = document.getElementById('folder-new-trigger');
const folderNewShell = document.getElementById('folder-new-shell');
const folderNewCancel = document.querySelector('.folder-new-cancel');

if (folderNewTrigger && folderNewShell) {
    folderNewTrigger.addEventListener('click', () => {
        folderNewShell.classList.add('is-open');
        folderNewTrigger.style.display = 'none';
        const input = folderNewShell.querySelector('input[name="category_name"]');
        if (input) input.focus();
    });
    if (folderNewCancel) {
        folderNewCancel.addEventListener('click', () => {
            folderNewShell.classList.remove('is-open');
            folderNewTrigger.style.display = '';
            const input = folderNewShell.querySelector('input[name="category_name"]');
            if (input) input.value = '';
        });
    }
    const nameInput = folderNewShell.querySelector('input[name="category_name"]');
    if (nameInput) {
        nameInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                folderNewShell.querySelector('form')?.requestSubmit();
            }
            if (e.key === 'Escape') {
                folderNewShell.classList.remove('is-open');
                folderNewTrigger.style.display = '';
                nameInput.value = '';
            }
        });
    }
}

bindMediaBrowserControls();
bindPreviewTriggers(queue);
updateGlobalProgress();
</script>
<?php admin_render_layout_end(); ?>
