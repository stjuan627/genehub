---
name: cms-content-dashboard
description: 追踪和观测 Drupal 网站内容变动引起的“蝴蝶效应”（如 SEO 排名剧烈变动、流量异常、爬虫重搜、内容大幅重构）。当需要检查网站最近主要更新、分析某页面为何流量变动、或获取版本前后比对时使用此 skill。
---

# CMS 内容变更与蝴蝶效应观测技能 (CMS Content Dashboard)

本技能帮助你在 Drupal 站点中精准观测实质性的内容改动，过滤掉修改标点、错别字等无意义的微小噪音，聚焦高价值的内容演变。

## 1. 启动检查与 Key 引导（首要动作）

在执行任何查询前，必须确认以下鉴权与环境信息：
- **站点地址 (`BASE_URL`)**：优先使用环境变量 `CMS_DASHBOARD_BASE_URL`，本地默认 `https://genehub.ddev.site`。
- **密钥 (`API_KEY`)**：优先从环境变量 `CMS_DASHBOARD_API_KEY` 或上下文配置中读取。

> ⚠️ **如果未配置 API Key**：
> 不要自行伪造 Key 发起无效请求！必须主动向用户输出以下引导提示：
> *"我需要 CMS Content Dashboard API Key 才能查询内容变更记录。您可以在 Drupal 后台 `/admin/config/services/cms-content-dashboard` 查看或配置密钥，或者通过 Drush 命令 `ddev drush cset cms_content_dashboard.settings api_key <your_key>` 设置。请将您的 Key 提供给我。"*

在获取到 Key 后，可执行单行探活验证连通性：
```bash
curl -k -s -o /dev/null -w "%{http_code}\n" -H "X-API-KEY: $API_KEY" "$BASE_URL/api/v1/content-dashboard/updates?limit=1"
# 返回 200 说明密钥有效且网络连通
```

---

## 2. 核心业务常识（如何理解返回数据）

- **主要更新 (`is_major: true`)**：系统已自动识别重大改动，日常排查必须默认带上 `only_major=true` 过滤噪音。包括：
  - `created`：节点首次创建发布；
  - `status`：发布状态切换（如从未发布变为发布，或下架撤回）；
  - `title`：去除标点后存在实质词汇替换或增删；
  - `h1`：页面首个 `<h1>` 或独立 H1 字段发生实质改动；
  - `body_major`：正文大幅重构改写（变动率 $\ge 15\%$ 或 增删词数 $\ge 50$ 词）。
- **次要更新 (`is_major: false`)**：仅修改标点符号、空格或改动少于 5 个单词的局部小错字修正。
- **摘要格式 (`summary`)**：返回为标准的 Markdown List 列表（以 `- **Type**: ...` 换行展示），你可以直接在给用户的报告中呈现该 Markdown 列表，无需二次解析转换。
- **路由标识**：
  - `url`：公开可访问的绝对完整 URL；
  - `path`：站点相对路径；
  - `slug`：干净的语义标识符。

---

## 3. 标准操作指令（按场景调用）

外部调用时，推荐使用请求头 `X-API-KEY: {token}`。

### 场景 A：排查 SEO / 流量突然波动的页面（单页面溯源）
外部监控发现某页面（已知 `nid`）流量或排名出现异动，查询该页面历史上的所有重大转折点：
```bash
curl -k -s -H "X-API-KEY: $API_KEY" "$BASE_URL/api/v1/content-dashboard/nodes/{nid}/history?only_major=true"
```
**分析建议**：
1. 对比流量波动的日期与返回列表中的 `updated_at` 时间戳；
2. 提取最接近波动起始日的 revision，查看其 `summary` 和 `change_flags`。

### 场景 B：增量监控全站最近发布（全站时间线）
拉取指定时间戳之后发生的所有主要改动（例如每天/每周巡检）：
```bash
curl -k -s -H "X-API-KEY: $API_KEY" "$BASE_URL/api/v1/content-dashboard/updates?only_major=true&since={timestamp}&limit=20"
```
**参数支持**：
- `bundle`：按类型过滤，如 `article` 或 `page`；
- `change_type`：按特定变更类型过滤，如 `title`, `h1`, `body_major`；
- `limit` & `page`：标准分页支持。

### 场景 C：深度对比具体某次版本改动详情
需要深入了解某次更新究竟修改了哪些段落时：
```bash
curl -k -s -H "X-API-KEY: $API_KEY" "$BASE_URL/api/v1/content-dashboard/nodes/{nid}/diff/{vid}"
```
返回中包含 `title_diff`、`h1_diff` 以及 `body_diff`（含新旧版本字数统计与文本摘要摘录）。

---

## 4. 常见错误自愈机制

- **HTTP 401 Unauthorized**：
  - 原因：请求头缺少 `X-API-KEY` 或密钥与服务器配置不符；
  - 处置：主动向用户报错，提示检查配置并重新提供有效 Key。
- **HTTP 404 Not Found**：
  - 原因：指定的 `nid` 节点不存在，或指定的 `vid` 版本不存在；
  - 处置：向用户确认 Node ID 是否正确。
- **HTTP 403 Forbidden**：
  - 原因：Content Dashboard API 服务在后台被管理员临时关闭；
  - 处置：提示管理员在 `/admin/config/services/cms-content-dashboard` 勾选启用该 API。
