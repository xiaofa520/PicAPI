# PicAPI

## 项目用途

PicAPI 用来把自己保存的图片变成一个可管理、可调用的图片服务。它解决的是“图片文件散落在服务器上，网站还要自己找文件、维护图片链接”的问题：你把 PC 和手机图片放进图库并按分类整理，PicAPI 提供固定的 HTTP 地址；每次请求时服务按设备或分类选出一张图片并返回。接入方不需要知道文件名，也不必自己实现随机抽图逻辑。

常见用法是给博客、个人主页、壁纸页面、社群机器人或其他应用提供随机配图。调用方可以直接把接口地址作为图片地址，也可以请求 JSON 或图片 URL，再由自己的程序处理。管理员通过后台上传、筛选和管理图片，查看请求统计与访问日志，并设置限流、黑白名单等访问控制。服务由你部署和管理，图片库、站点设置与日志留在自己的服务器上。

最简单的使用方式是在网页中把接口当作图片源：

```html
<img src="https://你的域名/" alt="随机图片">
```

需要固定设备或分类时，改用 `/pc/`、`/m/` 或添加 `category` 参数；需要在程序中读取图片信息时，使用 `?type=json`。具体示例见下方“图片与 API 使用”。

## 主要功能

- 自适应、PC、手机图片接口；支持随机图片、分类、图片链接或 JSON 响应。
- 管理后台提供图库与分类管理、分批浏览、访问控制、访问和登录日志、统计、站点及 API 设置。
- 初始化向导支持 SQLite、MySQL、PostgreSQL、SQL Server；邮件和 Redis 可按需配置。
- 提供公开接口文档，并支持在后台编辑文档内容。

## 项目目录

| 路径 | 用途 |
| --- | --- |
| `index.php` | 根目录统一入口。将 `/admin/`、`/docs/`、`/pc/`、`/m/` 等公开地址安全地转发到 `public/`。 |
| `public/` | 对外页面、路由控制器和静态资源；也可按服务器示例配置为网站根目录。 |
| `public/admin/` | 后台页面、管理 API、上传、账号和设置功能。 |
| `public/docs/` | 公开 API 文档页面。 |
| `public/pc/`、`public/m/` | PC 和手机图库资源及接口入口。图片按分类目录存放。 |
| `src/` | PHP 业务逻辑，包括 API、图库、缓存、安全、设置和邮件服务。 |
| `templates/admin/` | 后台登录、初始化和工作台页面模板。 |
| `storage/` | 运行时数据：数据库、安装配置、会话、日志、安全状态和缓存。不要公开或提交其中的数据。 |
| `config/` | Nginx 与 Apache 的项目根目录、`public/` 根目录部署示例。 |
| `bin/` | 维护命令及计划任务脚本，例如每日邮件摘要。 |

根目录与 `public/` 下的 `.htaccess`、`web.config` 分别用于 Apache、IIS 的访问控制与 URL 重写。Apache 2.4 需启用 `mod_rewrite` 并允许相应目录读取 `.htaccess`；Nginx 不读取 `.htaccess`，请使用 `config/` 中的 Nginx 示例。`public/admin/assets/vendor/` 内含第三方前端库及其各自的许可证文件；这些文件应与对应库一起保留。

## 架构概览

PicAPI 是一个单体 PHP 应用，代码按功能分布在路由与页面、业务运行时以及数据库和文件存储逻辑中。它还没有独立的存储适配层，也不是由多个服务组成的微服务系统。部署时可以把项目根目录或 `public/` 设为网站根目录：前者由根目录 `index.php` 校验并转发公开路径，后者由 Web 服务器直接处理 `public/` 中的入口。

```mermaid
flowchart LR
    C[浏览器或 API 调用方] --> W[Web 服务器]
    W --> M{网站根目录配置}
    M -->|项目根目录| R[index.php 路由与路径校验]
    M -->|public/ 目录| P[public/ 页面与控制器]
    R --> P
    P --> API[图片 API]
    P --> ADM[管理页面与 JSON 接口]
    P --> DOC[公开 API 文档]
    API --> SRC[src/ 业务运行时]
    ADM --> SRC
    DOC --> SRC
    SRC --> DB[(PDO 数据库)]
    SRC --> ST[(storage/ 运行数据)]
    SRC --> IMG[(PC 与手机图库文件)]
    SRC -. 可选 .-> REDIS[(Redis 图片索引缓存)]
    SRC -. 邮件 .-> SMTP[SMTP 服务]
```

主要职责如下：

- **入口与界面**：`index.php`、`public/` 和 `templates/admin/` 处理 URL、后台页面、公开文档及管理接口；后台交互由 `public/admin/assets/` 中的 JavaScript 和 CSS 完成。
- **业务模块**：`src/public_api_runtime.php` 负责公开图片请求；`src/image_runtime.php` 管理图片索引、访问统计和日志；`src/media_management.php` 处理图库分类；`src/security_runtime.php`、`src/settings_runtime.php`、`src/setup_runtime.php` 等分别处理安全策略、站点配置和初始化。
- **持久化与外部服务**：PDO 数据库存储管理员、配置文档、分类及运行记录；`storage/` 保存 SQLite 数据库、安装配置、会话、缓存、日志和上传暂存数据；图库图片保存在 `public/pc/img/` 与 `public/m/img/`。Redis 和 SMTP 分别用于可选缓存和邮件发送。

增加功能时，通常在 `public/` 增加受控入口，在 `src/` 实现对应业务逻辑，并按数据性质选择数据库、`storage/` 或图库文件系统。当前运行时模块仍以 PHP 函数和 `require_once` 组合，部分业务编排与存储操作彼此耦合；若后续要扩展或多人并行维护，可以逐步抽出数据库、文件系统和缓存适配层，无需一次性重写现有 URL 与页面。

## 环境要求

- PHP 8.1 或更高版本，启用 PDO、OpenSSL、Fileinfo，以及所选数据库对应的 PDO 驱动（SQLite 使用 `pdo_sqlite`）。
- 支持 SQLite、MySQL、PostgreSQL、SQL Server。SQLite 最便于单机使用。
- PHP-FPM/Apache 的 PHP 运行账号需要读写 `storage/` 和 `public/pc/img/`、`public/m/img/`；Nginx/Apache 静态服务账号需要读取图库目录和图片文件。Linux 上两者可能使用不同账号。
- 首次初始化或重置时，PHP 进程还需短暂写入项目根目录，以创建并删除 `setup.txt`。初始化完成后可撤销项目根目录的 PHP 写权限。
- Linux 文件系统区分大小写；部署和更新时保留仓库文件名及目录名的大小写。
- GD 用于缩略图，Redis 用于可选缓存；未启用时使用文件缓存。
- 正式网站应启用 HTTPS，并为 PHP 配置合适的上传大小和执行时间限制。

## 本机启动

在项目根目录执行：

```sh
php -S 127.0.0.1:8768 index.php
```

然后打开：

- 后台：<http://127.0.0.1:8768/admin/>
- 初始化：首次安装时会自动进入初始化向导，也可访问 <http://127.0.0.1:8768/admin/setup.php>
- 公开文档：<http://127.0.0.1:8768/docs/>
- 网站地图：<http://127.0.0.1:8768/sitemap.xml>
- 图片接口：<http://127.0.0.1:8768/>

启动命令末尾的 `index.php` 是必要的路由器。仅运行 `php -S 127.0.0.1:8768` 会让 PHP 内置服务器直接按文件路径查找 `/docs/`，根目录没有 `docs` 文件夹时就会返回资源不存在。80 端口通常需要管理员权限，开发时建议使用 8768 等高位端口。PHP 内置服务器只适用于开发和本机测试，不适合作为生产 Web 服务器。

## 首次初始化

1. 打开 `/admin/`，从项目根目录的 `setup.txt` 复制验证码并填入向导，再填写数据库信息。验证码通过后输入内容会保留；完成初始化后会自动删除 `setup.txt`，该文件不通过网站路由提供。SQLite 无需填写账号信息。
2. “测试连接”只检查数据库；启用了 Redis 才会一并检查 Redis。验证码由单独的验证码步骤校验。邮件服务请到邮件设置页面使用单独的测试按钮。
3. 创建管理员账号。密码最少 8 位；请使用独立且强度足够的密码。
4. 邮件服务可在初始化时配置，也可跳过后到系统设置中配置。未设置邮件时，依赖邮件的验证码、找回密码和通知功能无法使用。
5. 完成向导后使用管理员账号登录。初始化草稿暂存在当前浏览器，并显示最近保存时间。

SQLite 数据库默认放在 `storage/database/`。数据库、安装配置、会话、密钥、日志和缓存都是本地运行数据，不应提交到代码仓库。登录凭证默认保存 7 天，可在系统设置中调整，最大 30 天。

## 图片与 API 使用

将图片放入对应图库分类目录，或登录后台使用上传功能：

- PC 图片：`public/pc/img/<分类名>/`
- 手机图片：`public/m/img/<分类名>/`

管理后台可创建和管理分类。默认图库是系统保留分类。生产环境更新代码前请备份图库和 `storage/` 中需要保留的配置数据。

```text
GET /                         自适应随机图片
GET /pc/                      PC 随机图片
GET /m/                       手机随机图片
GET /?type=json               返回图片 JSON
GET /?type=url                返回图片 URL
GET /pc/?category=default     从指定分类取图
GET /?device=mobile           本次请求指定使用手机图库
GET /?screen_width=390        按屏幕宽度选择图库
```

`type=json` 与 `type=url` 也可用于 `/pc/` 和 `/m/`。分类名称通过 `category` 参数指定；实际可用名称以后台分类管理中显示的接口名称为准。接口支持 GET 和 HEAD。限流、封禁、允许的响应类型等访问策略可在后台访问控制中设置。

首次安装默认填入站点描述、页面标题、搜索摘要和关键词，可在后台“设置 → 站点信息”中修改。`/sitemap.xml` 默认列出公开接口文档页面，`/robots.txt` 会提供对应的网站地图地址；请在后台填写正式站点地址，以生成正确的绝对链接。

## 部署到宝塔或其他 Web 服务器

生产环境使用 Nginx 或 Apache 配合 PHP-FPM，不要将 PHP 内置开发服务器用作公网服务。默认推荐把 `public/` 设为站点根目录；项目根目录模式用于无法更改站点目录的主机。两种模式都不要把网站根目录指向 `storage/`、`src/` 或 `templates/`。

### 选择 Web 根目录

- **Nginx，推荐 `public/` 根目录**：将站点根目录设为项目的 `public/`，参考 [config/nginx.conf.example](config/nginx.conf.example)。把 `fastcgi_pass` 改为主机上的 PHP-FPM socket 或 TCP 地址，并把示例路径和域名替换成实际值。Nginx 不读取 `.htaccess`，请在站点配置中保留示例里的入口重写、敏感路径屏蔽和 PHP 转发规则。
- **Nginx，项目根目录**：参考 [config/nginx-root.conf.example](config/nginx-root.conf.example)，所有公开 URL 经项目根目录 `index.php` 路由。
- **Apache，推荐 `public/` 根目录**：DocumentRoot 指向项目的 `public/`，启用 Apache 2.4 的 `mod_rewrite`，并让该目录允许读取 `.htaccess`。可参考 [config/apache-public-directory.example.conf](config/apache-public-directory.example.conf)；PHP-FPM 处理器由主机面板配置。
- **Apache，项目根目录**：使用根目录 `.htaccess` 和 [config/apache-root-directory.example.conf](config/apache-root-directory.example.conf)。该模式依赖 Apache 2.4 的 `[END]` 重写标记。
- **IIS**：`public/` 根目录使用 `public/web.config`，项目根目录使用根目录 `web.config`；IIS 需安装 URL Rewrite 和 PHP FastCGI。

### 宝塔面板部署步骤

以下以 Linux、域名 `example.com`、项目目录 `/www/wwwroot/example.com` 为例；按实际目录和 PHP 版本替换示例值。宝塔菜单会随版本略有调整，可参考官方的[添加 PHP 站点](https://docs.bt.cn/user-guide/site/php/create-web/)、[网站目录与运行目录](https://docs.bt.cn/user-guide/site/php/site-config/website-directory/)和[站点配置文件](https://docs.bt.cn/user-guide/site/php/site-config/config-file/)说明。

1. 在“网站 → PHP项目 → 添加站点”添加域名和 PHP 版本；数据库可按需创建，SQLite 无需在宝塔另建数据库。上传并解压整个项目，保留隐藏文件（例如 `.htaccess`），不要只上传 `public/`。
2. 点击站点域名进入“网站目录”，让最终 Web 根目录指向 `/www/wwwroot/example.com/public`。如果面板把“网站目录”和“运行目录”分开，网站目录填写 `/www/wwwroot/example.com`、运行目录选 `/public`；若可直接指定站点根目录，就直接填 `.../public`。这是 Nginx 和 Apache 共用的推荐布局。
3. 在站点的 PHP 设置中选择 PHP 8.1 或更高版本，再到该 PHP 版本的扩展管理启用 `fileinfo`、`pdo_sqlite`；需要缩略图时启用 GD。若用 MySQL 等数据库，启用对应 PDO 扩展。
4. 查看站点 PHP-FPM 池的 `user`/`group`（宝塔常见为 `www`，以实际配置为准），再将运行数据和图库授权给该账号。下面的命令把 `www:www` 替换成实际账号：

   ```sh
   chown -R www:www /www/wwwroot/example.com/storage
   chown -R www:www /www/wwwroot/example.com/public/pc/img /www/wwwroot/example.com/public/m/img
   find /www/wwwroot/example.com/storage -type d -exec chmod 750 {} +
   find /www/wwwroot/example.com/storage -type f -exec chmod 640 {} +
   find /www/wwwroot/example.com/public/pc/img /www/wwwroot/example.com/public/m/img -type d -exec chmod 755 {} +
   find /www/wwwroot/example.com/public/pc/img /www/wwwroot/example.com/public/m/img -type f -exec chmod 644 {} +
   ```

   `storage/` 仅供 PHP 运行账号使用；图库是公开静态资源，目录需可遍历、图片需可读取。应用在 Linux 上新建图库目录时使用 `755`，新上传图片使用 `644`。不要对整个项目执行 `chmod -R 777`。
5. 首次初始化时，PHP 还要在项目根目录创建并删除 `setup.txt`。若 PHP-FPM 不能写项目根目录，可临时把项目根目录所有者改为 PHP 运行账号并设为 `750`；完成初始化后恢复项目代码目录的部署所有者和只读权限。`storage/` 与图库目录的 PHP 写权限需持续保留。
6. 按 Web 服务器类型配置站点：
   - **宝塔 Nginx**：点击站点域名进入“配置文件”，套用 `config/nginx.conf.example` 的 server 规则，保留宝塔生成的 PHP-FPM 配置或将 `fastcgi_pass` 改为该站点 PHP 版本显示的 socket。确认 `root` 指向 `.../public`；图库 PHP 拦截规则要放在通用 PHP handler 前面。保存后用 `nginx -t` 检查，再重载 Nginx。
   - **宝塔 Apache**：确认 Apache 2.4 的 `mod_rewrite` 已启用，并在站点设置中允许 `public/.htaccess` 生效；可将 `config/apache-public-directory.example.conf` 中的 `<Directory>` 配置加入站点配置。保留宝塔生成的 PHP-FPM/Handler 规则。
7. 在“网站 → 设置 → SSL”申请或上传证书并启用 HTTPS。PHP 设置中建议关闭 `display_errors`、开启 `log_errors`；配置 `upload_max_filesize=55M`、`post_max_size=60M`、`max_execution_time=120`、`memory_limit=256M`，保存后重启站点使用的 PHP-FPM。Nginx 示例的 `client_max_body_size` 应不小于 `55m`。
8. 打开 `https://example.com/admin/` 完成初始化并登录。若遇到 `Permission denied`，先检查报错路径的所有者是否是 PHP-FPM 用户、父目录是否可遍历，以及图库静态服务用户是否可读。

宝塔的 PHP CLI 与 PHP-FPM 可能使用不同版本和 `php.ini`。计划任务若运行 `bin/daily-summary.php`，应选择与站点相同的 PHP 版本，例如 `/www/server/php/81/bin/php /www/wwwroot/example.com/bin/daily-summary.php`。

宝塔的“防跨站攻击”会通过 `open_basedir` 限制 PHP 可访问路径。PicAPI 的 PHP 文件在 `public/` 外层的 `src/`、`templates/` 和 `storage/` 中；启用该功能时，允许路径必须包含整个项目目录和 PHP 临时目录，不能只允许 `public/`，否则会出现文件包含或读写权限错误。

### 反向代理与域名

通过反向代理或负载均衡访问时，应让代理覆盖并传递浏览器访问的域名和协议，例如 Nginx 代理可设置：

```nginx
proxy_set_header Host $http_host;
proxy_set_header X-Forwarded-Host $http_host;
proxy_set_header X-Forwarded-Proto $scheme;
```

代理到应用的 Nginx 配置也要把这两个头转交给 PHP（示例文件已经包含对应的 FastCGI 参数）。可信代理 IP 通过 `PICAPI_TRUSTED_PROXIES` 配置为逗号分隔列表。安装验证码、会话与 CSRF 校验按实际访问域名绑定；若上游把 Host 改为 `localhost`，必须传递公开域名头，不能用固定站点地址替代浏览器域名。

`PICAPI_BASE_URL` 只用于生成网站地图等规范绝对链接，不用于验证码、会话或 CSRF 域名绑定。环境变量由服务器或 PHP-FPM 提供；项目不会自动读取 `.env` 文件。配置环境变量后重启 PHP-FPM。

## 维护与备份

- 备份数据库（SQLite 文件或数据库服务器数据）、`public/pc/img/`、`public/m/img/`，以及需要保留的站点设置和安装配置。
- 不要清理运行中的 `storage/`。日志和缓存可按维护策略清理；清除数据库或安装配置会导致站点数据丢失或重新进入安装流程。
- `php bin/daily-summary.php` 可由计划任务定时运行；使用前在后台配置邮件服务、收件人和摘要开关。

## 开源协议

PicAPI 项目代码采用 **GNU GPL-3.0-only**。分发本项目或其衍生版本时，须遵守 GPL-3.0，包括按该协议提供对应源代码。授权说明见根目录 `LICENSE`，完整协议文本见 [GNU GPL 第 3 版](https://www.gnu.org/licenses/gpl-3.0.html)。第三方组件按其各自附带的许可证授权，不因本项目协议而改变。
