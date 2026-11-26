# 舞小枫 · 舞立方助手（Web 版）

舞小枫 Web 版是一个独立的 PHP 网站。它直接对接舞立方官方接口，提供登录、个人信息、战力分析、分数列表、战队、机台查询、评价、留言、公告、后台管理和模板图片生成等功能。

---

## 1. 功能概览

### 账号与数据

- 微信扫码登录舞立方
- 手机号 + 图形验证码 + 短信验证码登录
- 登录凭证保存在服务端 `data/state.php`
- 个人信息：战力、积分、全连率、全国排名、地区、金币、游玩次数、天梯段位
- 战力分析：B15 / R15、B30、AP30
- 分数列表：按歌曲等级分页，每页 30 首
- 我的战队：战队资料、成员列表、成员排序
- 数据统计：个人胜率、团队胜率、游玩年龄、游戏段位、游玩次数、通过歌曲、累计加币
- 兑换自制谱兑换码

### 创作者（谱师）

- 创作者信息：创作等级、创作经验、创作金币、创作者认证状态（普通账号会提示尚未开通）
- 我的自制谱：已发布作品列表，支持翻页，可直接为某一首生成兑换码
- 批量生成兑换码：为自己的谱面生成 1~50 个兑换码，页面提供一键复制
- 兑换码全家桶：为所有已发布自制谱各生成 1 个兑换码（最多 30 首）
- 兑换码属于敏感信息，页面会提示仅可使用一次、请勿公开分享

### 查找机台

- 按地区查询附近舞立方机台
- 展示场地图片 Img1 / Img2
- 每个场地支持 1~5 星评价与文字评论
- 评价最多附带 3 张现场照片
- 普通用户可以提交场地排除申请，填写理由后等待管理员审核
- 管理员可以直接排除 / 恢复场地，也可以审核通过 / 驳回申请
- 已排除场地折叠到列表底部的「排除列表」，并显示排除理由
- 支持显示 / 隐藏机台照片

### 站点与互动

- 站点公告：纯文本、保留换行、可设置用户是否可关闭
- 站点开关：关闭后非管理员只能看公告、登录和退出
- 留言板：登录用户可发言，管理员可删除
- 后台管理：
  - 后台密码设置 / 修改 / 锁定
  - 站点开关
  - 公告发布 / 清除
  - 已登录用户列表
  - Token 管理与默认 Token
  - 留言删除
  - 场地评价删除
  - 场地排除审核 / 直接排除 / 恢复
  - 清理登录状态

### 界面

- 亮色 / 暗色主题，本地记忆
- 中文 / English 切换，本地记忆
- 手机端底部导航，可横向拖动 / 滑动，按当前用户组权限列出全部可用功能
- 电脑端左侧栏、手机端底部导航；默认进入「个人信息」，不再有「更多 / 功能总览」页
- 个人信息页下方提供「刷新信息」「退出登录」
- 每个功能页底部显示当前 Web 版本号
- Web 与 App 手机端保持相近的顶部品牌区、页面标题和底部导航结构

### 图片生成

- 战力分析图片（B15 / B30 / AP30）
- 分数列表图片
- 个人信息图片
- PNG 输出，1200×2700 / 605×900
- 成品图短缓存、ETag、浏览器缓存
- 封面 / 头像 / 头像框 / 称号 / 徽章并发预取与本地缓存

---

## 2. 环境要求

### 推荐环境

```text
PHP 7.4+
IIS 10 / Apache / Nginx
Windows Server 或 Linux
```

### PHP 扩展

必须启用：

```text
gd
curl
json
mbstring
openssl
```

GD 需要：

```text
FreeType
PNG
JPEG
```

WebP 不是必须的。舞立方 OSS 的部分 WebP 资源会通过 OSS 图片处理参数转成 PNG；PHP 7.4 的 bundled GD 对部分 WebP 支持不稳定，因此代码会跳过不安全的 WebP。

### 目录权限

PHP 进程需要对以下目录有写权限：

```text
data/
assets/machine_reviews/
data/image_cache/
data/ratio_image_cache/
```

`download/` 目录只需要读权限。

### 推荐

- 使用 HTTPS；
- 关闭 `debug`；
- 把 `data/`、`config.php` 放在 Web 可访问范围之外，或确保服务器禁止直接访问；
- 定期备份 `data/`。

---

## 3. 快速开始

### 3.1 上传文件

把整个 `web/` 目录作为站点根目录上传。

典型结构：

```text
web/
├── index.php
├── api.php
├── bootstrap.php
├── config.php
├── app-update.json
├── include/
├── assets/
├── data/
├── download/
├── static/
└── template/
```

### 3.2 配置 `config.php`

至少检查：

```php
'site_name' => '舞小枫 · 舞立方助手',
'timezone'  => 'Asia/Shanghai',
'debug'     => false,
```

然后按需配置：

```php
'dfb_gaode_key'  => '',
'cors_origin'    => '',
'admin_enabled'  => true,
'admin_user_ids' => '',
```

### 3.3 创建数据目录

确保 `data/` 存在且可写。首次访问会自动创建部分缓存目录。

扫码登录不需要客户端 ID：后端会调用不带 `id` 的 `GetQrCode` 自动创建二维码会话，并用返回的 `ID` 作为临时的 `client_id`。

### 3.4 访问页面

```text
https://your-domain/
```

### 3.5 进入后台

1. 用 `admin_user_ids` 中配置的舞立方账号登录；
2. 打开「后台管理」；
3. 首次进入设置后台密码；
4. 之后每次进入输入密码；
5. 可点击「锁定后台」立即退出管理状态。

后台密码只保存带随机盐的哈希，不保存明文。

---

## 4. 配置说明

`config.php` 返回一个 PHP 数组。常用配置如下。

### 站点

| 配置 | 说明 | 默认 |
| --- | --- | --- |
| `site_name` | 浏览器标题 / 页面站点名 | `舞小枫 · 舞立方助手` |
| `timezone` | 时区 | `Asia/Shanghai` |
| `debug` | 是否在页面显示 PHP 错误 | `false` |

### 舞立方接口

| 配置 | 说明 | 默认 |
| --- | --- | --- |
| `dfb_timeout` | 接口超时秒数 | `20` |
| `dfb_ratio_cache_ttl` | 战力数据缓存秒数，0 为不缓存 | `45` |
| `dfb_gaode_key` | 高德地图 Web 服务 Key，查找机台需要 | 空 |

### 图片缓存

| 配置 | 说明 | 默认 |
| --- | --- | --- |
| `dfb_image_cache_ttl` | 成品图片缓存秒数 | `180` |

说明：

- 成品图缓存在 `data/image_cache/`；
- 默认 180 秒内重复导出直接复用；
- 可通过 `config.php` 调整，范围会被限制在 30~3600 秒；
- 图片接口同时返回 `ETag`，浏览器再次请求可得到 `304 Not Modified`。

### CORS

如果使用 uni-app H5 或其他独立前端，需要配置 `cors_origin`：

```php
'cors_origin' => '*',
```

或：

```php
'cors_origin' => 'https://app.example.com,http://localhost:8080',
```

App（非 H5）不受浏览器 CORS 限制，不需要配置。

### 后台管理

| 配置 | 说明 | 默认 |
| --- | --- | --- |
| `admin_enabled` | 是否开放后台管理 | `true` |
| `admin_user_ids` | 允许进入后台的舞立方 ID，逗号 / 空格分隔或数组 | 空 |
| `admin_password_min_length` | 后台密码最小长度 | `8` |
| `admin_unlock_ttl` | 解锁保持秒数 | `1800` |
| `admin_max_attempts` | 连续错误次数 | `5` |
| `admin_lock_seconds` | 锁定时长秒数 | `600` |
| `admin_secret_file` | 自定义后台密码哈希文件路径 | 空 |

后台密码文件默认：

```text
data/admin.php
```

文件内容只有密码哈希，没有明文密码。若把文件放到站点目录外，可设置 `admin_secret_file`。

---

## 5. 目录结构

```text
web/
├── index.php                     Web 页面入口
├── api.php                       JSON / PNG 接口入口
├── bootstrap.php                 启动、常量、配置、FORMHASH
├── config.php                    站点配置
├── app-update.json               App 版本 / 下载地址
├── include/
│   ├── dfb_core.php              核心：配置、JSON、登录状态、后台、工具
│   ├── dfb_cube.php              舞立方数据抓取与解析
│   ├── dfb_cube_ext.php          机台、兑换码等扩展业务
│   ├── dfb_creator.php           创作者信息 / 自制谱 / 兑换码生成
│   ├── dfb_actions.php           接口动作表与处理函数
│   ├── dfb_announcement.php      公告
│   ├── dfb_site.php              站点开关
│   ├── dfb_messages.php          留言板
│   ├── dfb_changelog.php         更新日志
│   ├── dfb_machine_reviews.php   场地评价与评价图片
│   ├── dfb_machine_exclusions.php 场地排除申请 / 审核
│   ├── dfb_ratio_image.php       战力图片、图片缓存、字体、素材
│   ├── dfb_user_image.php        个人信息图片
│   └── dfb_level_image.php       分数列表图片
├── static/
│   ├── app.js                    Web 前端逻辑
│   ├── i18n.js                   前端语言切换 / DOM 翻译
│   ├── style.css                 主样式
│   └── standalone.css            独立版补充样式
├── template/
│   ├── index.htm                 页面模板
│   └── lang/
│       ├── zh_cn.php             Web 中文语言包
│       └── en_us.php             Web English 语言包
├── assets/
│   ├── ratio/                    战力 / 信息图模板素材与字体
│   ├── user/                     个人信息图背景
│   └── machine_reviews/          评价上传图片
├── data/                         运行数据（禁止直接访问）
└── download/                     APK 下载目录
```

---

## 6. 数据文件

### 6.1 目录保护

`data/` 目录下包含登录凭证、缓存和后台密码哈希，必须禁止 Web 直接访问。

项目内已包含：

```text
data/.htaccess
data/web.config（如果部署环境需要）
```

IIS 部署时请确认 `data/` 下的 `web.config` / 请求过滤规则生效。

### 6.2 主要文件

| 文件 | 内容 |
| --- | --- |
| `data/state.php` | 登录凭证、会话、后台解锁状态 |
| `data/announcement.php` | 站点公告 |
| `data/site.php` | 站点开关 |
| `data/messages.php` | 留言板 |
| `data/machine_reviews.php` | 场地评价与评价图片 URL |
| `data/machine_exclusions.php` | 场地排除申请 / 审核记录 |
| `data/admin.php` | 后台密码哈希 |
| `data/ratio_cache.php` | 战力数据短缓存 |
| `data/covers.php` | 封面索引缓存 |
| `data/image_cache/` | 成品图短缓存 |
| `data/ratio_image_cache/` | 远程头像 / 封面缓存 |

### 6.3 文件格式

大部分数据文件采用：

```php
<?php exit; ?>
{ ... JSON ... }
```

首行是访问保护，直接请求不会输出内容。

除纯 JSON 文件外，数据文件使用 2 空格缩进的 pretty JSON 写入，便于人工查看。

### 6.4 备份

建议定期备份：

```text
data/state.php
data/announcement.php
data/site.php
data/messages.php
data/machine_reviews.php
data/machine_exclusions.php
data/admin.php
config.php
```

图片缓存可以删除，删除后会自动重建。

---

## 7. 接口约定

### 7.1 入口

```text
GET  /api.php?act=bootstrap&dfb_web_id=...
POST /api.php?act=message.post&formhash=...
```

浏览器身份通过 `dfb_web_id` Cookie / 参数区分。

### 7.2 公共参数

| 参数 | 说明 |
| --- | --- |
| `act` | 动作名 |
| `formhash` | CSRF 令牌，来自 `bootstrap` |
| `dfb_web_id` | 浏览器身份 ID |

POST 动作使用 JSON 请求体：

```http
Content-Type: application/json
```

```json
{
  "formhash": "xxxxxxxx",
  "content": "hello"
}
```

### 7.3 功能分组

接口动作按 `feature` 分为：

| feature | 说明 |
| --- | --- |
| `query` | 机台查询、兑换码、场地评价等查询类 |
| `data` | 个人信息、战力、分数、战队、创作者、留言、更新日志等 |
| `admin` | 后台管理动作，需要管理员资格与密码解锁 |

### 7.4 主要动作

#### 基础

```text
bootstrap
state
help
changelog.list
```

#### 登录

```text
login.qrcode.start
login.qrcode.poll
login.phone.start
login.phone.sms
login.phone.verify
logout
```

#### 用户数据

```text
user.info
ratio.b15
ratio.b30
ratio.ap30
level.scores
team.detail
reply.item
```

#### 留言 / 公告

```text
message.list
message.post
```

#### 机台

```text
machine.list
machine.review.list
machine.review.post
machine.review.upload
machine.exclude.request
```

#### 兑换与 Token

```text
gain.code
token.show
token.default.show
token.refresh
```

#### 创作者（谱师）

```text
maker.info        创作者信息（等级 / 经验 / 金币 / 认证状态）
maker.music       已发布自制谱（?page=N）
maker.code        生成兑换码（POST musicId / count）
maker.family      兑换码全家桶（POST）
```

#### 图片（PNG 二进制）

```text
ratio.image?mode=b15|b30|ap30
level.image?level=N&page=N
user.image?target=ID
```

图片接口返回：

```http
Content-Type: image/png
Cache-Control: private, max-age=180
ETag: "..."
X-Image-Cache: HIT|MISS
```

#### 管理员

```text
admin.unlock
admin.password.setup
admin.password.change
admin.lock
admin.announcement.publish
admin.announcement.clear
admin.site.set
admin.users.list
admin.message.delete
admin.machine.review.delete
admin.machine.exclusions
admin.machine.exclude.review
admin.machine.exclude.set
admin.token.set
admin.tokens.save
admin.tokens.load
admin.update.ids
admin.clear.login
```

详细参数以 `include/dfb_actions.php` 中的动作表为准。

### 7.5 返回格式

普通接口返回 JSON：

```json
{
  "ok": true,
  "message": "..."
}
```

错误返回：

```json
{
  "detail": "错误说明",
  "loginRequired": true
}
```

登录过期时返回 `loginRequired: true`，前端会引导重新登录。

---

## 8. 图片生成与缓存

### 8.1 生成流程

1. 校验登录凭证；
2. 读取舞立方数据；
3. 预取头像、头像框、称号、徽章和歌曲封面；
4. GD 合成背景、头像、文字、歌曲网格；
5. PNG 编码；
6. 写入成品图缓存；
7. 输出图片并设置 ETag / 浏览器缓存。

### 8.2 缓存层次

| 缓存 | 位置 | 说明 |
| --- | --- | --- |
| 战力数据 | `data/ratio_cache.php` | B15/B30/AP30 共用短缓存 |
| 远程图片 | `data/ratio_image_cache/` | 头像、封面等，默认 7 天 |
| 封面索引 | `data/covers.php` | 官方封面列表 |
| 成品图 | `data/image_cache/` | 默认 180 秒 |
| 浏览器 | 客户端 | `max-age=180` + ETag |

### 8.3 速度优化

- PNG 压缩级别使用 3，而不是 6；
- 背景图与目标尺寸一致时使用 `imagecopy`，避免整图重采样；
- 多张封面使用 curl multi 并发预取；
- 成品图命中时不再拉数据、下载封面或重绘；
- ETag 未变化时返回 304；
- 重复导出在缓存期内基本无需等待。

首次生成仍可能较慢，主要取决于舞立方接口响应和首次封面下载。

### 8.4 清理缓存

可以安全删除：

```text
data/image_cache/*
data/ratio_image_cache/*
data/ratio_cache.php
data/covers.php
```

删除后下一次访问会重新生成。

---

## 9. 安全设计

- 所有数据目录文件带 `<?php exit; ?>` 或服务器访问限制；
- 接口使用 `formhash` 防止跨站请求；
- 后台需要管理员资格 + 密码解锁；
- 后台密码使用带随机盐的哈希；
- 后台连续错误锁定；
- 登录凭证保存在服务端；
- 上传评价图片仅接受 JPEG / PNG，服务端 GD 压缩并限制尺寸；
- 图片接口不返回本地文件路径；
- 站点关闭时非管理员只能访问公告、登录和退出；
- CORS 默认关闭，按需配置白名单；
- 生产环境保持 `debug => false`。

### 关于 `download/` 目录

`download/` 是公开下载目录，当前通过 `download/web.config` 开启了 IIS 目录浏览：

```text
/download/
```

该目录只应放置 APK 等公开文件，不要放置配置、日志、Token 或用户数据。

---

## 10. 多语言

Web 语言包位于：

```text
template/lang/zh_cn.php
template/lang/en_us.php
```

- 中文为源语言；
- English 语言包与中文语言包都返回数组；
- 页面入口会把语言包注入 `window.DFB.i18n`；
- `static/i18n.js` 负责切换、DOM 翻译和持久化；
- 用户昵称、公告正文、留言内容、评价内容、场地名称等用户数据不做自动翻译。

App 语言资源位于：

```text
../app/utils/i18n.js
../app/utils/i18n-extra.js
```

---

## 11. 手机端与 App 协作

Web 手机端：

- 底部导航固定；
- 导航项可横向拖动 / 滑动；
- 开屏默认 `/user-info`；
- 顶部品牌与页面标题区；
- 内容区预留底部安全距离。

App：

- 与 Web 共用接口；
- `pages.json` 首屏为 `pages/user-info/user-info`；
- 全局底部导航 `components/BottomNav.vue`；
- 全局顶部 `components/MobileHeader.vue`；
- 自定义导航栏，页面标题由 `utils/i18n.js` 提供；
- 登录成功后回到个人信息页。

App 的 APK 下载地址由 `app-update.json` 和 `download/` 目录共同维护。

---

## 12. 部署检查清单

- [ ] PHP 7.4+ 与 GD/curl/mbstring/openssl 已启用
- [ ] GD FreeType、PNG、JPEG 可用
- [ ] `data/` 可写且禁止 Web 直接访问
- [ ] `assets/machine_reviews/` 可写
- [ ] `data/image_cache/` 可写
- [ ] `data/ratio_image_cache/` 可写
- [ ] `config.php` 中 `site_name`、`timezone` 正确
- [ ] `admin_user_ids` 已配置
- [ ] `dfb_gaode_key` 已配置（需要查找机台时）
- [ ] `debug => false`
- [ ] 后台密码已设置
- [ ] HTTPS 已启用
- [ ] `download/` 只放公开 APK
- [ ] 已备份 `data/`

---

## 13. 常见问题

### 页面 403

检查：

- 站点根目录是否正确指向 `web/`；
- `index.php` 是否存在；
- IIS 是否加载 PHP FastCGI；
- `data/` 与 `download/` 的 `web.config` 是否被正确读取；
- 目录浏览是否只对 `download/` 开启。

### 图片接口 500

检查：

- GD 是否启用；
- 字体文件是否在 `assets/ratio/fonts/`；
- 背景图是否完整；
- `data/image_cache/` 是否可写；
- PHP 错误日志。

### 图片生成很慢

- 首次生成受舞立方接口和封面下载影响；
- 检查 `data/ratio_image_cache/` 是否可写；
- 检查 `data/image_cache/` 是否可写；
- 确认没有禁用 curl；
- 可适当调大 `dfb_timeout`；
- 不要频繁清理图片缓存。

### 登录提示「登录身份过期」

- 重新扫码 / 短信登录；
- 检查 `data/state.php` 是否可写；
- 检查系统时间 / 时区；
- 检查 Token 是否被舞立方侧失效。

### 扫码登录没有二维码

- 扫码登录使用动态会话 ID，无需客户端 ID；
- 检查 `api.php` 是否能访问舞立方接口；
- 检查 `dfb_timeout`。

### 查找机台没有结果

- 检查 `dfb_gaode_key`；
- 检查高德 Web 服务 Key 是否启用；
- 检查服务器是否能访问高德与舞立方接口；
- 检查地区名是否过长。

### 后台管理入口不显示

- 当前登录的舞立方 ID 是否在 `admin_user_ids`；
- `admin_enabled` 是否为 `true`；
- 是否已经通过后台密码验证；
- 站点关闭状态下是否使用管理员账号。

### 修改配置不生效

- PHP 是否启用了 opcache；
- 修改 `config.php` 后刷新页面；
- 如修改时区 / 扩展，重启 PHP / IIS 应用池；
- 检查是否修改了错误的 `config.php`。

---

## 14. 开发与验证

### 语法检查

```bash
php -l index.php
php -l api.php
php -l bootstrap.php
php -l config.php
find include -name '*.php' -print0 | xargs -0 -n1 php -l
```

### 基础接口检查

```bash
curl -s 'https://your-domain/api.php?act=bootstrap&dfb_web_id=test'
```

正常应返回包含 `formhash`、`version`、`siteClosed` 的 JSON。

### 图片接口检查

```bash
curl -I 'https://your-domain/api.php?act=user.image&formhash=...&dfb_web_id=...'
```

正常应看到：

```text
Content-Type: image/png
X-Image-Cache: HIT|MISS
ETag: ...
```

### 数据目录保护检查

直接访问以下地址应被拒绝或返回空白：

```text
/data/state.php
/data/admin.php
/data/machine_exclusions.php
```

---

## 16. 说明

- 本项目仅用于个人 / 团队内部部署与学习交流；
- 请遵守舞立方官方服务条款；
- 不要将 `data/`、`config.php`、后台密码文件暴露到公网；
- 不要将用户 Token、Cookie 或后台密码提交到代码仓库。
