# PicAPI 图片接口系统

PicAPI 是一个可自行部署的 PHP 图片接口与管理后台。它按请求入口返回 PC、手机或自适应图片，并提供分类、上传、资源管理、访问统计、日志、IP 访问控制、邮件提醒和 API 文档页面。

## 功能概览

- `GET /`：根据屏幕宽度或 User-Agent 自动选择 PC / 手机图片。
- `GET /pc/`：只返回 PC 图片；`GET /m/`：只返回手机图片。
- 支持直接返回图片、返回图片 URL、返回 JSON 数据和分类请求。
- 支持多文件、文件夹、拖放及分片上传，图片会根据宽高自动归类至 PC 或手机图库。
- 提供 PNG 缩略图、数据看板、系统日志、API 配置、IP 黑白名单、邮件提醒和站点设置。
- 初始化向导支持 SQLite、MySQL、PostgreSQL 与 SQL Server；管理员账号保存至数据库。

## 环境要求

- PHP 8.1 或更高版本。
- `PDO` 与所选数据库对应的 PDO 驱动，默认本地部署使用 `pdo_sqlite`。
- PHP 进程必须可写入 `database/`、`pc/img/`、`m/img/`、`log/`、`pc/log/` 和 `m/log/`。
- 生产环境建议启用 HTTPS，并将站点根目录指向本项目根目录。

## 服务器兼容性

- 支持 Windows、Linux 与 macOS 上的 PHP 8.1+；项目内不调用操作系统 Shell 命令，文件路径通过 PHP 的目录分隔符处理。
- 支持 Apache、Nginx、IIS、宝塔及常见共享主机。Apache 使用根目录 `.htaccess`；IIS 使用根目录 `web.config`；Nginx 请将站点根目录指向本项目根目录，并将 `index.php` 设为默认首页。
- 无论运行在哪种服务器，均需让 Web 服务账户拥有上述运行目录的读写权限，并启用所选数据库的 PDO 驱动。SQLite 仅需 `pdo_sqlite`，无需安装数据库服务。
- 受限主机启用 `open_basedir` 时，程序只使用项目内的运行目录；历史目录不在授权范围会被安全跳过，不会显示 PHP Warning。

本地快速运行：

```powershell
php -S 127.0.0.1:8000
```

### 反向代理

反向代理部署时，请将实际代理 IP 写入 `PICAPI_TRUSTED_PROXIES`（逗号分隔）。只有这些代理发出的 `X-Forwarded-*` 头才会被用于识别 HTTPS 与客户端 IP。

随后访问 `http://127.0.0.1:8000/`。如果部署在子目录或反向代理之后，可设置环境变量 `PICAPI_BASE_URL` 为完整公开地址，使 JSON 与 URL 输出使用正确的域名。

## 首次初始化

1. 打开 `http://你的域名/admin/setup.php`。
2. 选择数据库并测试连接。选择 SQLite 时，数据库会创建在 `database/picapi.sqlite`。
3. 填写管理员用户名、邮箱与密码。密码至少 8 位，登录时可使用用户名或邮箱。
4. 配置 SMTP，并通过测试邮件验证邮箱服务。
5. 完成后自动登录后台；以后访问 `admin/` 会进入登录页面，不会重复进入初始化。

不要把已初始化后的 `database/installation.php`、`database/picapi.sqlite`、安全记录和邮件配置提交到公开仓库或提供给他人。

## 接口使用

以下示例以 `https://example.com` 为站点地址。

| 目标 | 直接图片 | JSON | 图片 URL |
| --- | --- | --- | --- |
| 自适应 | `https://example.com/` | `https://example.com/?type=json` | `https://example.com/?type=url` |
| PC | `https://example.com/pc/` | `https://example.com/pc/?type=json` | `https://example.com/pc/?type=url` |
| 手机 | `https://example.com/m/` | `https://example.com/m/?type=json` | `https://example.com/m/?type=url` |

接口只接受 `GET` 与 `HEAD`。默认或未知的 `type` 均按直接图片处理；是否允许直接图片、JSON 和 URL 输出可在后台 API 配置中分别开关。

### 自适应规则

- `?screen_width=768` 及以下返回手机图片，`769` 及以上返回 PC 图片。
- 未传 `screen_width` 时通过 User-Agent 判断移动设备；未识别为移动设备时返回 PC 图片。
- 根接口可使用 `?device=pc`、`?device=mobile` 或 `?device=m` 强制指定图片库。

### 分类请求

分类由显示名称和请求别名组成。显示名称可使用中文；别名使用英文、数字、下划线或连字符，例如 `wallpaper`、`anime_4k`。默认分类别名为 `default`。

```text
# 返回指定分类的图片
https://example.com/?type=wallpaper
https://example.com/pc/?type=wallpaper
https://example.com/m/?type=wallpaper

# 指定分类并返回 JSON 或 URL
https://example.com/?category=wallpaper&type=json
https://example.com/pc/?category=wallpaper&type=url
https://example.com/m/?category=wallpaper&type=json
```

创建分类后，系统会自动建立 `pc/img/分类别名/` 与 `m/img/分类别名/`，并在各目录内创建分类入口。比如 `pc/img/wallpaper/` 固定返回 PC 分类图片，`m/img/wallpaper/` 固定返回手机分类图片。

## 返回与状态码

- 直接图片：响应体为原图二进制内容，并使用原图片 MIME 类型。
- `?type=url`：返回纯文本图片 URL。
- `?type=json`：返回根据后台 JSON 模板生成的数据，默认包含图片 URL、文件大小、宽度与高度等信息。
- `403`：当前输出方式被后台 API 配置关闭。
- `405`：请求方法不是 `GET` 或 `HEAD`。
- `429`：IP 被频率策略或管理员封禁；JSON 响应含中英文消息和解封信息。
- `503`：当前请求范围内没有可用图片。

浏览器对 `/favicon.ico` 的自动请求会返回站点图标或空响应，不会被计入图片接口调用或接口统计。

## 后台入口

| 地址 | 用途 |
| --- | --- |
| `/admin/login.php` | 登录、忘记密码与验证码重置 |
| `/admin/dashboard.php` | 数据看板与实时统计 |
| `/admin/upload.php` | 多文件、文件夹、拖放与分片上传 |
| `/admin/media.php` | 图片资源、缩略图预览、批量操作与移动分类 |
| `/admin/folders.php` | 分类显示名称与请求别名管理 |
| `/admin/logs.php` | 访问流水、上传审计和日志导出 |
| `/admin/custom_api.php` | 三个公开接口的输出策略和 JSON 模板 |
| `/admin/ip_control.php` | 请求黑名单、登录黑名单与白名单 |
| `/admin/settings.php` | 网站名称、图标、主题、SMTP、邮件模板、提醒策略与访问日志保留 |

公开 API 使用文档在 `/docs/`。其样式、字体、自定义 CSS、HTML 片段和 JavaScript 可以从后台 API 文档编辑入口管理。

文档中的访问统计会每 5 秒通过同页 `GET /docs/?stats=1` 自动更新；只刷新今日、昨日、总访问量及自适应 / PC / 手机接口统计，不会刷新整个页面或改变阅读位置。该统计请求不计入图片接口调用次数。

## 目录与运行数据

```text
pc/img/<分类别名>/                 PC 原图与分类入口
m/img/<分类别名>/                  手机原图与分类入口
database/thumbnails/pc/...         PC PNG 缩略图
database/thumbnails/mobile/...     手机 PNG 缩略图
database/chunks/                   未完成的分片上传临时文件
database/picapi.sqlite             默认 SQLite 数据库与运行状态
database/counters.json             请求计数兼容副本（主记录存入数据库状态表）
database/categories.json           分类元数据的首次迁移来源
database/api_config.json           API 输出与抽图策略
database/api-docs.json             API 文档页面定制数据
database/site-settings.json        站点与主题设置
database/logs/YYYY/MM/DD.jsonl     结构化访问日志
database/upload-logs/YYYY/MM/DD.jsonl 上传审计日志
database/security/                 登录限流、IP 黑白名单与频率策略的兼容迁移来源
log/                               自适应接口的本地请求计数与访问日志
pc/log/                            PC 接口的本地请求计数与访问日志
m/log/                             手机接口的本地请求计数与访问日志
```

无论有多少图片分类，请求次数只按入口汇总为自适应、PC、手机三类。每次请求会写入数据库状态表，同时更新对应入口的本地 `count.log`、按日备份和访问日志；不会为每个分类额外创建计数文件。

图片上传完成后会生成供后台资源管理使用的 PNG 缩略图。通过 SSH 等方式直接放入图片目录的原图，如果没有缩略图，在资源管理页发现时会请求生成并保存到 `database/thumbnails/`。

## 安全与维护

- 管理员会话在 7 天未登录后过期；持续登录会自动续期。
- 登录失败会按次数逐步锁定来源 IP，可通过 SMTP 发送爆破提醒；达到最高锁定周期后继续失败会进入登录黑名单。
- API 请求频率策略可自动封禁异常来源，白名单不受自动封禁影响。
- 可在系统设置开启访问日志自动清理，并选择 1 个月至 5 年的保留周期；仅删除到期访问流水，不会删除总访问次数、每日计数或接口计数文件。
- 可独立设置上传审计日志自动清理；清理仅移除到期审计记录，不会删除原图、缩略图或分类文件夹。
- 反向代理部署时，只有在 `PICAPI_TRUSTED_PROXIES` 中配置了代理 IP，系统才会信任 `X-Forwarded-For`。
- 定期备份 `database/`、`pc/img/`、`m/img/` 以及根目录、PC、手机目录下的 `log/` 文件夹。
- 分类元数据、登录限流、IP 黑白名单、请求频率状态和顺序抽图游标会在数据库可用时自动迁移至 `picapi_runtime_states`；迁移后的 JSON 文件仅保留为兼容备份。
- 升级或迁移前先备份运行数据；程序会尝试把旧版 `storage/` 和历史私有目录迁移到项目内的 `database/`。

## Nginx 部署保护

Nginx 不会读取 `.htaccess`。使用 Nginx、宝塔 Nginx 或反向代理部署时，必须把 [nginx-security.conf.example](docs/nginx-security.conf.example) 中的规则放入站点的 `server {}` 配置，以禁止公开访问数据库、日志和版本控制目录。

## 开发检查

修改 PHP 文件后可运行：

```powershell
php -l index.php
php -l pc/index.php
php -l m/index.php
php -l admin/index.php
```

项目没有 Composer 依赖。使用外部 Web 服务器部署时，请保留 PHP 文件执行能力，并限制对敏感运行数据的非必要外部访问。
