# CMS Content Dashboard 外部 Agent 接入指南 (AGENT.md)

本模块专为外部 AI Agent 与自动化系统设计，用于在 Drupal 站点中精准观测内容变化引发的“蝴蝶效应”（如 SEO 排名异动、页面流量突变、外链与收录波动）。

为了让各类智能体开箱即用，本模块同时提供：
1. **标准化技能文件**：[`SKILL.md`](./SKILL.md)（专供支持 Skill 扩展机制的智能体直接安装）；
2. **通用操作指引**（如下文所述，供通用 Agent 直接阅读并执行）。

---

## 1. 快速接入方式：安装本模块专属 Skill

如果你的 Agent 环境支持 Agent Skills 规范（例如 Antigravity、Claude Code、Cursor 或其他 Agent 编排框架），**强烈建议直接将本模块提供的 `SKILL.md` 部署到你的技能库中**：

### 方式 A：本地项目内直接挂载 / 软链接
在项目工作区根目录下执行：
```bash
mkdir -p .agents/skills/cms-content-dashboard
cp web/modules/custom/cms_content_dashboard/SKILL.md .agents/skills/cms-content-dashboard/SKILL.md
```

### 方式 B：外部远程 Agent 下载安装
外部环境的 Agent 可直接通过 HTTP/Git 从仓库读取本文件并保存到自己的 skills 目录下：
```bash
# 复制或下载模块下的 SKILL.md
curl -s -o ~/.agent/skills/cms-content-dashboard/SKILL.md "<YOUR_REPO_RAW_URL>/web/modules/custom/cms_content_dashboard/SKILL.md"
```

> **挂载优势**：一旦安装此 Skill，Agent 将自动在需要“分析 SEO 异动原因”、“排查内容变更”、“生成内容周报”等场景下**按需懒加载**，无需在每次会话中浪费 Prompt 上下文。

---

## 2. API Key 配置引导流程（Agent 行动规范）

在调用接口前，外部 Agent 应遵循以下标准自检步骤：

### 步骤 1：检查本地鉴权配置
检查当前环境是否已注入以下参数：
- 站点基础 URL：`CMS_DASHBOARD_BASE_URL`（本地环境默认为 `https://genehub.ddev.site`）
- API 访问密钥：`CMS_DASHBOARD_API_KEY`

### 步骤 2：若未配置，主动引导用户
如果检测到密钥为空，Agent **不得自行伪造测试 Key 发起盲测**，而应使用以下标准话术引导用户：
> *"我需要 CMS Content Dashboard API Key 才能连接到 Drupal 站点查询内容变更数据。请在 Drupal 后台 `/admin/config/services/cms-content-dashboard` 获取或配置密钥，或者通过 Drush 命令 `ddev drush cset cms_content_dashboard.settings api_key <your_key> -y` 设置，并提供给我。"*

### 步骤 3：单行连通性探活
获取密钥后，执行一次轻量探活验证有效性：
```bash
curl -k -s -o /dev/null -w "%{http_code}\n" -H "X-API-KEY: <API_KEY>" "<BASE_URL>/api/v1/content-dashboard/updates?limit=1"
```
- 返回 `200`：鉴权成功，进入业务执行；
- 返回 `401`：提示用户 Key 错误，需重新核对；
- 返回 `403`：提示管理员在后台勾选启用该 API。

---

## 3. 核心业务常识与规则

外部 Agent 应当理解系统的数据过滤逻辑：
- **主要改动 (`is_major: true`)**：系统已利用本地数学差分引擎完成判定，日常排查必须默认使用 `only_major=true` 过滤噪音。包含：
  - 首发创建 (`created`)、状态发布/撤回 (`status`)；
  - 标题实质变更 (`title`)、H1 发生改动 (`h1`)；
  - 正文大幅改写 (`body_major`，变动率 $\ge 15\%$ 或 增删 $\ge 50$ 词)。
- **次要微调 (`is_major: false`)**：仅改动标点符号、空格排版、或修改少于 5 个单词的局部拼写纠错。
- **摘要格式 (`summary`)**：返回为标准的换行 Markdown List（带加粗语义标签，例如 `- **Title**: ...\n- **Content**: ...`），外部 Agent 可直接呈现在给用户的最终分析报告中。
- **资源定位**：
  - `url`：公开可直接点击的完整绝对链接；
  - `slug`：干净的语义标识符；
  - `path`：站内相对路径。

---

## 4. 常用 API 接口速查

详细参数与返回结构请直接参阅同目录下的 [`SKILL.md`](./SKILL.md)。

| 端点 | 方法 | 说明 | 推荐使用场景 |
| :--- | :--- | :--- | :--- |
| `/api/v1/content-dashboard/updates` | `GET` | 最近主要更新列表（支持 `since` 时间戳增量拉取、`bundle` 筛选与分页） | 全站定时轮询、监控大屏、周报生成 |
| `/api/v1/content-dashboard/nodes/{nid}/history` | `GET` | 单节点主要更新历史时间线（已排除次要微调） | 特定页面流量波动、SEO 排名归因排查 |
| `/api/v1/content-dashboard/nodes/{nid}/diff/{vid}` | `GET` | 指定版本详细逐字前后对比与新旧摘要 | 深入分析某次改动的核心差异 |

---

## 5. 配套 Drush 运维命令
```bash
# 增量扫描并建立历史版本日志索引（支持断点续跑）
ddev drush cms-content-dashboard:backfill

# 别名简写方式，支持 --force 强制全量重算
ddev drush cd-backfill --force -y
```
