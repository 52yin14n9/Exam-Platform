# 二次开发 1.2 交付说明

## 已实现

- 理论题型扩展为：单选、多选、判断、填空；解析允许为空。
- 理论题库支持单选、多选、判断、填空四种题型，通过 CSV 导入。
- 新增全题库顺序学习：每轮固定快照、按 `source_order` 顺序、未完成前禁止跳过第一道未完成题、中途退出可续刷。
- 判断题与选择题提交后即时反馈；填空题展示标准答案后由学生自评。
- 新增错题历史、收藏、待复习、今日到期、1/3/7/15 天复习节奏。
- 新增复习中心：未掌握错题、全部历史错题、待复习、收藏、今日到期。
- 保留原有整组模拟练习和正式考核/实操模块；模拟练习也兼容新题型。
- 管理后台题库支持四种题型、17 列增强 CSV、官方题库完整性计数。
- 新增命令行 `qbank:import`，按 `source_ref` 去重并在导入后统计题型数量。

## 数据库升级

新增迁移：`database/migrations/003_study_mode.sql`

新增表：

- `study_rounds`
- `study_round_items`
- `student_question_progress`
- `student_question_answers`

升级已有表：

- `practice_questions`
- `practice_sets`
- `practice_attempt_questions`

## 部署顺序

升级旧站前请先备份数据库、`.env` 和 `storage/uploads/`。

```bash
php bin/console migrate
php bin/console qbank:import
php bin/console diagnose
php bin/console self-test
```

不要重新生成已有站点的 Key Secret。

## 本地自检结果

- PHP 代码语法检查通过。
- 项目自检通过（Key 隔离、CSRF、中间件、迁移、学习/复习路由、题库数量）。
- 题库 CSV 从原 PDF 重新生成后字节级一致。
- 当前执行环境没有 MySQL/PDO MySQL 服务，因此未在本环境实际执行数据库迁移和端到端 Web 流程；部署到具备 PHP 8.2/8.3 + PDO MySQL + MySQL 8.0 的目标环境后，应按上面的 `migrate -> qbank:import -> diagnose -> self-test` 顺序再次验证。
