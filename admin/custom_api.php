<?php

require_once __DIR__ . '/common.php';

admin_require_login();

function endpoint_page_images_for(string $endpointKey, array $libraryItems): array
{
    return match ($endpointKey) {
        'm' => $libraryItems['m'] ?? [],
        'pc' => $libraryItems['pc'] ?? [],
        default => array_merge($libraryItems['pc'] ?? [], $libraryItems['m'] ?? []),
    };
}

function endpoint_page_sample_image(string $endpointKey, array $libraryItems): ?array
{
    $items = endpoint_page_images_for($endpointKey, $libraryItems);
    if ($items === []) {
        return null;
    }

    usort(
        $items,
        static fn(array $left, array $right): int => (int) ($right['modified_at'] ?? 0) <=> (int) ($left['modified_at'] ?? 0)
    );

    return $items[0] ?? null;
}

$endpointKeys = ['root', 'm', 'pc'];
$settings = admin_read_endpoint_settings();
$selectedEndpoint = (string) ($_GET['endpoint'] ?? 'root');
if (!in_array($selectedEndpoint, $endpointKeys, true)) {
    $selectedEndpoint = 'root';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    admin_require_csrf();

    if ($action === 'save_endpoint') {
        $endpoint = (string) ($_POST['endpoint'] ?? 'root');
        if (!in_array($endpoint, $endpointKeys, true)) {
            $endpoint = 'root';
        }

        $mode = (string) ($_POST['mode'] ?? 'random');
        $template = trim((string) ($_POST['template'] ?? admin_default_endpoint_template()));
        if ($template === '') {
            $template = admin_default_endpoint_template();
        }

        $settings['endpoints'][$endpoint]['mode'] = in_array($mode, ['random', 'sequential'], true) ? $mode : 'random';
        $settings['endpoints'][$endpoint]['template'] = $template;

        admin_write_endpoint_settings($settings);
        admin_set_flash('success', html_entity_decode('&#24050;&#20445;&#23384;', ENT_QUOTES, 'UTF-8'));
        admin_redirect('custom_api.php?endpoint=' . rawurlencode($endpoint));
    }

    if ($action === 'save_shared_settings') {
        $typeParam = trim((string) ($_POST['type_param'] ?? 'type'));
        $typeValue = trim((string) ($_POST['type_value'] ?? 'json'));
        $categoryParam = trim((string) ($_POST['category_param'] ?? 'category'));

        $settings['shared']['type_param'] = $typeParam;
        $settings['shared']['type_value'] = $typeValue;
        $settings['shared']['category_param'] = $categoryParam;
        $settings['shared']['missing_category_behavior'] = 'legacy';

        admin_write_endpoint_settings($settings);
        admin_set_flash('success', html_entity_decode('&#24050;&#20445;&#23384;', ENT_QUOTES, 'UTF-8'));
        admin_redirect('custom_api.php?endpoint=' . rawurlencode($selectedEndpoint));
    }
}

$flash = admin_pull_flash();
$settings = admin_read_endpoint_settings();

$libraryItems = [];
foreach ($libraryConfigs as $libraryKey => $config) {
    $libraryItems[$libraryKey] = admin_scan_library($config['root_dir'], $libraryKey);
}

$categorySummaries = admin_build_category_summaries($libraryItems, admin_read_categories());
$librarySummary = admin_library_summary($libraryItems);
$sampleImage = endpoint_page_sample_image($selectedEndpoint, $libraryItems);
$template = (string) ($settings['endpoints'][$selectedEndpoint]['template'] ?? admin_default_endpoint_template());
$payload = admin_endpoint_preview_payload($settings, $selectedEndpoint, $sampleImage);
$preview = admin_endpoint_preview($template, $payload);
$sampleCategory = (string) ($payload['category'] ?? admin_default_category_name());
$sampleUrl = admin_endpoint_public_url($selectedEndpoint, $sampleCategory, $settings);
$typeParam = (string) ($settings['shared']['type_param'] ?? 'type');
$typeValue = (string) ($settings['shared']['type_value'] ?? 'json');
$categoryParam = (string) ($settings['shared']['category_param'] ?? 'category');
$sampleNote = $sampleImage !== null
    ? (string) ($sampleImage['relative_path'] ?? '') . ' / ' . (int) ($sampleImage['width'] ?? 0) . 'x' . (int) ($sampleImage['height'] ?? 0)
    : html_entity_decode('&#26242;&#26080;&#39044;&#35272;&#22270;', ENT_QUOTES, 'UTF-8');

admin_render_layout_start(
    html_entity_decode('&#25509;&#21475;&#35774;&#32622;', ENT_QUOTES, 'UTF-8'),
    'custom_api.php',
    [
    'headline' => html_entity_decode('&#25509;&#21475;&#35774;&#32622;', ENT_QUOTES, 'UTF-8'),
    'description' => '',
    'badges' => ['JSON', 'Preview'],
    'auto_refresh_seconds' => 0,
    'mini_stats' => [
        [
            'label' => 'PC',
            'value' => number_format((int) ($librarySummary['pc']['count'] ?? 0)),
            'note' => image_api_format_file_size((int) ($librarySummary['pc']['bytes'] ?? 0)),
        ],
        [
            'label' => 'Mobile',
            'value' => number_format((int) ($librarySummary['m']['count'] ?? 0)),
            'note' => image_api_format_file_size((int) ($librarySummary['m']['bytes'] ?? 0)),
        ],
        [
            'label' => html_entity_decode('&#20998;&#31867;', ENT_QUOTES, 'UTF-8'),
            'value' => number_format(count($categorySummaries)),
            'note' => admin_default_category_name(),
        ],
    ],
]);
?>
<?php if ($flash !== null): ?>
    <div class="notice <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<style>
.api-shell {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(320px, 0.95fr);
    gap: 20px;
}

.api-panel,
.api-preview,
.api-shared,
.api-categories {
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    background: var(--panel-strong);
    padding: 20px;
    display: grid;
    gap: 16px;
}

.api-preview-code,
.api-mini,
.api-token,
.api-example {
    font-family: "JetBrains Mono", Consolas, monospace;
}

.api-preview-code {
    margin: 0;
    padding: 16px;
    border-radius: var(--radius-md);
    border: 1px solid var(--line);
    background: var(--panel-soft);
    font-size: 12px;
    line-height: 1.7;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 460px;
    overflow: auto;
}

.api-preview-code[data-valid="0"] {
    color: var(--danger);
}

.api-row,
.api-preview-head,
.api-category-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.api-note {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.7;
    word-break: break-word;
}

.api-sample {
    display: grid;
    grid-template-columns: 96px minmax(0, 1fr);
    gap: 14px;
    align-items: center;
    padding: 14px;
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    background: var(--panel-soft);
}

.api-sample img,
.api-sample-empty {
    width: 96px;
    height: 96px;
    border-radius: var(--radius-md);
    object-fit: cover;
    border: 1px solid var(--line);
    background: var(--placeholder-bg);
}

.api-sample-empty {
    border-style: dashed;
}

.api-mini {
    color: var(--muted);
    font-size: 11px;
}

.api-token-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.api-token {
    display: inline-flex;
    align-items: center;
    padding: 6px 8px;
    border-radius: 999px;
    border: 1px solid var(--line);
    background: var(--panel-soft);
    font-size: 11px;
}

.api-category-list {
    display: grid;
    gap: 10px;
    max-height: 320px;
    overflow: auto;
    padding-right: 4px;
}

.api-category-item {
    padding: 12px 14px;
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    background: var(--panel-soft);
    display: grid;
    gap: 8px;
}

.api-example {
    display: block;
    padding: 12px 14px;
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    background: var(--panel-soft);
    font-size: 12px;
    line-height: 1.7;
    word-break: break-all;
}

.api-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 120px;
    text-decoration: none;
}

@media (max-width: 1120px) {
    .api-shell {
        grid-template-columns: 1fr;
    }

    .api-sample {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="content-stack">
    <section class="api-shell">
        <form method="post" class="api-panel">
            <input type="hidden" name="action" value="save_endpoint">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="endpoint" id="endpoint-hidden" value="<?= htmlspecialchars($selectedEndpoint, ENT_QUOTES, 'UTF-8') ?>">

            <div class="api-row">
                <div>
                    <div class="eyebrow">EDIT</div>
                    <h2 class="section-title">&#20837;&#21475;</h2>
                </div>
                <select id="endpoint-select" style="min-width: 180px;">
                    <?php foreach ($endpointKeys as $endpointKey): ?>
                        <option value="<?= htmlspecialchars($endpointKey, ENT_QUOTES, 'UTF-8') ?>"<?= $endpointKey === $selectedEndpoint ? ' selected' : '' ?>><?= htmlspecialchars(admin_endpoint_display_name($endpointKey), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="mode">&#27169;&#24335;</label>
                    <select id="mode" name="mode">
                        <option value="random"<?= (string) ($settings['endpoints'][$selectedEndpoint]['mode'] ?? 'random') === 'random' ? ' selected' : '' ?>>&#38543;&#26426;</option>
                        <option value="sequential"<?= (string) ($settings['endpoints'][$selectedEndpoint]['mode'] ?? '') === 'sequential' ? ' selected' : '' ?>>&#39034;&#24207;</option>
                    </select>
                </div>
                <div class="field">
                    <label>&#31034;&#20363;</label>
                    <a id="example-link" class="secondary api-link" href="<?= htmlspecialchars($sampleUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">&#25171;&#24320;</a>
                </div>
                <div class="field full">
                    <label>&#22320;&#22336;</label>
                    <div id="example-url" class="api-example"><?= htmlspecialchars($sampleUrl, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="field full">
                    <label for="template">JSON</label>
                    <textarea id="template" name="template" rows="16" spellcheck="false"><?= htmlspecialchars($template, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>

            <div class="api-token-wrap">
                <?php foreach (['{{url}}', '{{size}}', '{{width}}', '{{height}}', '{{category}}', '{{library}}', '{{path}}', '{{file_name}}', '{{mode}}', '{{timestamp}}'] as $token): ?>
                    <span class="api-token"><?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
            </div>

            <div class="toolbar">
                <button type="submit">Save</button>
            </div>
        </form>

        <section class="api-preview">
            <div class="api-preview-head">
                <div>
                    <div class="eyebrow">PREVIEW</div>
                    <h2 class="section-title" style="font-size: 18px;"><?= htmlspecialchars(admin_endpoint_display_name($selectedEndpoint), ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <span id="preview-status" class="badge"><?= $preview['is_valid_json'] ? '&#21487;&#29992;' : '&#38169;&#35823;' ?></span>
            </div>

            <div class="api-sample">
                <?php if ($sampleImage !== null): ?>
                    <img src="<?= htmlspecialchars(admin_preview_url((string) $sampleImage['library'], (string) $sampleImage['relative_path'], (int) ($sampleImage['modified_at'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" alt="">
                <?php else: ?>
                    <div class="api-sample-empty"></div>
                <?php endif; ?>

                <div style="display:grid;gap:8px;">
                    <div class="api-mini" id="preview-sample-note"><?= htmlspecialchars($sampleNote, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="api-mini">&#20998;&#31867;: <span id="preview-category"><?= htmlspecialchars($sampleCategory, ENT_QUOTES, 'UTF-8') ?></span></div>
                </div>
            </div>

            <pre id="preview-json" class="api-preview-code" data-valid="<?= $preview['is_valid_json'] ? '1' : '0' ?>"><?= htmlspecialchars((string) $preview['preview'], ENT_QUOTES, 'UTF-8') ?></pre>
        </section>
    </section>

    <section class="api-shell">
        <form method="post" class="api-shared">
            <input type="hidden" name="action" value="save_shared_settings">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

            <div>
                <div class="eyebrow">QUERY</div>
                <h2 class="section-title">&#35831;&#27714;</h2>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="type_param">&#35831;&#27714;&#38190;</label>
                    <input id="type_param" name="type_param" value="<?= htmlspecialchars($typeParam, ENT_QUOTES, 'UTF-8') ?>" placeholder="type">
                </div>
                <div class="field">
                    <label for="type_value">&#35831;&#27714;&#20540;</label>
                    <input id="type_value" name="type_value" value="<?= htmlspecialchars($typeValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="json">
                </div>
                <div class="field full">
                    <label for="category_param">&#20998;&#31867;&#38190;</label>
                    <input id="category_param" name="category_param" value="<?= htmlspecialchars($categoryParam, ENT_QUOTES, 'UTF-8') ?>" placeholder="category">
                </div>
            </div>

            <div id="shared-example" class="api-example"><?= htmlspecialchars(admin_endpoint_public_url('root', $sampleCategory, $settings), ENT_QUOTES, 'UTF-8') ?></div>

            <div class="toolbar">
                <button type="submit">Save</button>
            </div>
        </form>

        <section class="api-categories">
            <div>
                <div class="eyebrow">FOLDERS</div>
                <h2 class="section-title">&#20998;&#31867;</h2>
            </div>

            <div class="api-category-list">
                <?php foreach ($categorySummaries as $summary): ?>
                    <div class="api-category-item">
                        <div class="api-category-row">
                            <strong><?= htmlspecialchars((string) $summary['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span class="badge">&#20849; <?= number_format((int) $summary['total_count']) ?></span>
                        </div>
                        <div class="api-note"><?= htmlspecialchars((string) (($summary['note'] ?? '') !== '' ? $summary['note'] : '-'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </section>
</div>

<script>
const endpointSelect = document.getElementById('endpoint-select');
const endpointHidden = document.getElementById('endpoint-hidden');
const templateInput = document.getElementById('template');
const modeSelect = document.getElementById('mode');
const previewJson = document.getElementById('preview-json');
const previewStatus = document.getElementById('preview-status');
const exampleUrlNode = document.getElementById('example-url');
const exampleLink = document.getElementById('example-link');
const sharedExample = document.getElementById('shared-example');
const typeParamInput = document.getElementById('type_param');
const typeValueInput = document.getElementById('type_value');
const categoryParamInput = document.getElementById('category_param');

const previewPayload = <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function renderTemplate(template, payload) {
    let output = String(template || '');
    Object.entries(payload || {}).forEach(([key, value]) => {
        output = output.replaceAll(`{{${key}}}`, JSON.stringify(value));
    });
    return output;
}

function buildExampleUrl(endpointKey) {
    const typeParam = (typeParamInput?.value || 'type').trim() || 'type';
    const typeValue = (typeValueInput?.value || 'json').trim() || 'json';
    const categoryParam = (categoryParamInput?.value || 'category').trim() || 'category';
    const category = String(previewPayload.category || 'default');
    const base = endpointKey === 'm' ? '../m/' : endpointKey === 'pc' ? '../pc/' : '../';
    const query = new URLSearchParams();
    query.set(typeParam, typeValue);
    query.set(categoryParam, category);
    return `${base}?${query.toString()}`;
}

function updatePreview() {
    const rendered = renderTemplate(templateInput?.value || '', {
        ...previewPayload,
        mode: modeSelect?.value || 'random',
        endpoint: endpointSelect?.value || 'root',
        timestamp: new Date().toISOString()
    });

    let pretty = rendered;
    let valid = true;

    try {
        pretty = JSON.stringify(JSON.parse(rendered), null, 2);
    } catch (error) {
        valid = false;
    }

    if (previewJson) {
        previewJson.textContent = pretty;
        previewJson.dataset.valid = valid ? '1' : '0';
    }

    if (previewStatus) {
        previewStatus.textContent = valid ? '\u53ef\u7528' : '\u9519\u8bef';
    }

    const endpointKey = endpointSelect?.value || 'root';
    const exampleUrl = buildExampleUrl(endpointKey);

    if (exampleUrlNode) {
        exampleUrlNode.textContent = exampleUrl;
    }

    if (exampleLink) {
        exampleLink.setAttribute('href', exampleUrl);
    }

    if (sharedExample) {
        sharedExample.textContent = buildExampleUrl('root');
    }

    if (endpointHidden) {
        endpointHidden.value = endpointKey;
    }
}

if (endpointSelect) {
    endpointSelect.addEventListener('change', () => {
        const value = endpointSelect.value || 'root';
        window.location.href = `custom_api.php?endpoint=${encodeURIComponent(value)}`;
    });
}

[templateInput, modeSelect, typeParamInput, typeValueInput, categoryParamInput].forEach((node) => {
    if (!node) {
        return;
    }

    node.addEventListener('input', updatePreview);
    node.addEventListener('change', updatePreview);
});

updatePreview();
</script>

<?php admin_render_layout_end(); ?>
