# 网络安全学生考核平台

> 二次开发版 1.2：在 1.1 安全修复基础上新增全题库顺序学习、判断题、填空题自评、错题/收藏/待复习、复习节奏与 CSV 题库导入。

面向 1 名管理员和小规模学生团队的 LNMP 私有考核平台。包含两个完全独立的题库：

1. **考核题库**：理论主观题、靶场实践题、截图提交、参考答案、管理员人工评分。
2. **理论刷题题库**：单选/多选/判断/填空、CSV 导入、完整顺序学习、模拟练习、错题/收藏/待复习和轮次统计。
3. **顺序学习**：按 `source_order` 从第 1 题刷到最后一题，每轮生成固定题目快照，中途退出后从第一道未完成题继续。

## 运行要求

- Linux + Nginx
- PHP 8.2/8.3，扩展：PDO MySQL、GD、Fileinfo、Mbstring、JSON、Session
- MySQL 8.0
- HTTPS 证书

## 目录

```text
app/                    PHP 应用代码、控制器、核心安全组件和页面
bin/console             数据库迁移及管理员 Key 初始化命令
config/app.php          配置映射
database/migrations/    MySQL 数据结构
database/*.csv          理论题导入模板与示例数据
deploy/                 Nginx 与 PHP 生产配置示例
public/                  唯一 Web 根目录
storage/uploads/         实践截图，禁止直接对外暴露
storage/logs/            应用日志预留目录
```

## 1. 安装系统依赖

Ubuntu/Debian 示例：

```bash
sudo apt update
sudo apt install nginx mysql-server php8.3-fpm php8.3-mysql php8.3-gd php8.3-mbstring php8.3-cli
```

若系统提供的是 PHP 8.2，请把命令和 Nginx Socket 中的 `8.3` 改为 `8.2`。

## 2. 创建数据库

```sql
CREATE DATABASE cyber_exam CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cyber_exam'@'127.0.0.1' IDENTIFIED BY '替换为高强度数据库密码';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
ON cyber_exam.* TO 'cyber_exam'@'127.0.0.1';
FLUSH PRIVILEGES;
```

## 3. 配置环境变量

```bash
cd /var/www/cyber-assessment-platform
cp .env.example .env
php bin/console secrets:generate
```

将命令生成的两行分别填入 `.env`。必须确保：

- `STUDENT_KEY_SECRET` 与 `ADMIN_KEY_SECRET` 不相同。
- 学生 Secret 至少 32 字节，管理员 Secret 至少 64 字节。
- `.env` 权限设置为部署用户可读，绝不放入 Git 或 Web 根目录。

然后填写 MySQL 连接、域名和时区。

内网 HTTP 部署时另外设置：

```ini
APP_URL=http://你的内网IP
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=Lax
```

HTTPS 部署时将 `SESSION_SECURE_COOKIE` 设置为 `true`。不要关闭 CSRF 中间件。

## 4. 初始化数据库和管理员

```bash
php bin/console migrate
php bin/console admin:create "系统管理员"
php bin/console qbank:import
```

第二条命令会输出唯一管理员 Key，格式为 `adm2_...`，仅显示一次。立即保存到密码管理器。

管理员 Key 遗失或泄露时，在服务器本机运行：

```bash
php bin/console admin:rotate 1
```

轮换管理员或学生 Key 会同步提升账号的 `session_version`，使该账号此前建立的会话立即失效。停用学生也会立即使其现有会话失效。

## 诊断与自检

部署或升级后执行：

```bash
php bin/console migrate
php bin/console diagnose
php bin/console self-test
```

`diagnose` 检查 PHP 扩展、Secret 长度和短指纹、数据库连接、核心数据表、会话版本字段及有效管理员 Key 记录，不输出完整 Secret 或 Key。`self-test` 检查两套 Key 算法隔离、Key 格式、摘要长度、POST 路由 CSRF 和管理员/学生权限中间件。

应用错误会记录在：

```text
storage/logs/app.log
```

页面只显示随机错误编号，不显示数据库信息、Secret、Key 或调用栈。可以用该编号在日志中定位真实原因。

## 从旧版升级到 1.2

1. 备份数据库、项目 `.env` 和 `storage/uploads/`。
2. 用修复版覆盖程序文件，但保留服务器现有 `.env` 和上传目录。
3. 不要重新执行 `secrets:generate`，否则现有 Key 会全部失效。
4. 恢复 `public/index.php` 中的 `$csrf = fn() => Csrf::middleware();`。
5. 执行 `php bin/console migrate`，应用 `002_login_audit_index.sql` 和 `003_study_mode.sql`。
6. 执行 `php bin/console diagnose` 和 `php bin/console self-test`。
7. 确保 `storage/logs`、`storage/uploads` 对 PHP-FPM 用户可写，然后重启 PHP-FPM、重载 Nginx。

升级后旧浏览器会话会被要求重新登录一次，这是新增会话版本校验的预期行为；现有 Key 本身不会因为迁移而变化。

## 5. 设置文件权限

```bash
sudo chown -R root:www-data /var/www/cyber-assessment-platform
sudo find /var/www/cyber-assessment-platform -type d -exec chmod 750 {} \;
sudo find /var/www/cyber-assessment-platform -type f -exec chmod 640 {} \;
sudo chmod 750 bin/console
sudo chown -R www-data:www-data storage/uploads storage/logs
```

## 6. 配置 Nginx

复制 `deploy/nginx.conf`，修改域名、项目路径和 PHP-FPM Socket：

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/cyber-exam
sudo ln -s /etc/nginx/sites-available/cyber-exam /etc/nginx/sites-enabled/cyber-exam
sudo nginx -t
sudo systemctl reload nginx
```

生产环境必须配置 HTTPS，并将 HTTP 跳转到 HTTPS。

## 登录入口

- 学生：`https://你的域名/login/student`
- 管理员：`https://你的域名/login/admin`

两个入口不会互相接受对方的 Key。

## 理论题 CSV 导入

推荐使用新版 17 列模板 `database/practice_questions_template.csv`：

```text
type,title,prompt,option_a,option_b,option_c,option_d,option_e,option_f,correct_answers,explanation,difficulty,tags,source_ref,source_order,section,chapter
```

`type` 支持 `single`、`multiple`、`judge`、`fill`；判断题答案使用 `T/F`，填空多个可接受答案使用 `|` 分隔。解析可留空。旧版 13 列模板仍可通过管理员页面导入。

题库请按模板自行整理，本仓库不附带任何真实题库数据。部署时使用命令行导入：

```bash
php bin/console qbank:import
```

该命令按 `source_ref` 去重，可重复执行，并在导入后按题型统计数量。

## 顺序学习与复习

学生首页的“全题库顺序学习”与原模拟练习互相独立。每一轮开始时把当前所有可用理论题按 `source_order,id` 固定成快照；未完成本轮时不能跳过第一道未完成题，退出后再次进入会自动续刷。

客观题提交后立即显示正确/错误与标准答案；判断题使用“对/错”按钮。填空题先提交自己的答案，再查看题库标准答案并自评“我答对了 / 需要复习”。错题历史永久保留，答对后只改变当前掌握状态，不删除曾经答错的事实。

“我的复习”提供未掌握错题、全部历史错题、待复习、今日到期和收藏题。错题自动进入复习队列，后续正确作答按 3/7/15 天节奏逐步延长，最终移出待复习队列；用户也可以手动标记或取消待复习。

## 上线检查

1. `public/` 是唯一 Nginx 根目录。
2. `storage/uploads/` 不在 Web 根目录内。
3. PHP 已启用 GD、Fileinfo、PDO MySQL、Mbstring。
4. `APP_DEBUG=false`，全站 HTTPS。
5. 靶场与考核平台至少分离域名、进程权限和数据库账号。
6. 每日同时备份 MySQL 和 `storage/uploads/`，并进行恢复演练。

## 关键代码位置

- `app/Core/KeyManager.php`：管理员/学生两套 Key 生成与摘要算法。
- `app/Core/Auth.php`：两个独立登录验证流程和服务端角色会话。
- `app/Core/UploadService.php`：JPG/PNG/GIF 白名单、MIME、解码、像素限制及安全重编码。
- `app/Controllers/AdminController.php`：学生、双题库、组卷、发布、阅卷、刷题开放和网站设置。
- `app/Controllers/StudentController.php`：正式考试、自动保存、截图、提交、刷题、答案解析和继续练习。
- `database/migrations/001_initial.sql`：基础 MySQL 表结构。
- `database/migrations/003_study_mode.sql`：顺序学习、题目进度、复习历史与题型扩展。
- `public/index.php`：前端路由和管理员/学生权限中间件。
- `deploy/nginx.conf`：LNMP 部署入口与 PHP 执行限制。
