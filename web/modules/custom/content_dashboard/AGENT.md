# Content Dashboard 模块 Agent 使用指南 (AGENT.md)

本模块专为外部系统与 AI Agent 设计，提供高效、低噪声的 Drupal Node 内容变更追踪与 Dashboard REST API 服务，旨在精准观测内容变动引起的“蝴蝶效应”（如 SEO 排名异动、爬虫重搜、流量突变等）。

---

## 1. 核心设计与判定机制 (Major vs Minor)

为避免日常微小修补产生大量无效噪声，模块内置了 100% PHP 本地高性能量化检测引擎（单次分析耗时仅 1~5ms）：

| 变更类型 | 次要改动 (Minor: `is_major = 0`) | 主要改动 (Major: `is_major = 1`) |
| :--- | :--- | :--- |
| **标点/空格** | 仅修改中英文标点符号、空格、无语义换行（自动抹除后完全一致） | 任何去除标点后的实质词汇变更 |
| **标题 Title** | 仅修正中英文标点或空格 | 去除标点后存在实质词汇增删或改写 (`title`) |
| **页面 H1** | 仅修正标点 | 独立字段或正文首个 `<h1>` 文本发生实质替换 (`h1`) |
| **正文 Content** | 变动词数 $\le 5$ 词且变动比例 $< 10\%$（仅改一两个错别字） | 变动比例 $\ge 15\%$ **或** 绝对变动词数 $\ge 50$ 词 (`body_major`) |
| **首发创建** | - | 节点新创建并发布 (`created`) |
| **状态发布** | 内部草稿修改 | 草稿变为发布，或下架撤回 (`status`) |

---

## 2. 鉴权与环境配置

API 受密钥保护，客户端或 Agent 调用时需提供 API Key。

### 2.1 鉴权方式
- **Header 鉴权 (推荐)**：在请求头中携带 `X-API-KEY: <token>`
- **Query 参数 (备用)**：在 URL 后追加 `?api_key=<token>`

### 2.2 本地与生产配置
- **本地默认开发 Key**：`cd_secret_token_live`
- **后台可视化配置路径**：`/admin/config/services/content-dashboard`
- **Drush 命令行修改 Key**：
  ```bash
  ddev drush cset content_dashboard.settings api_key "<your_secret_key>" -y
  ```

---

## 3. RESTful API 接口规范

所有接口均返回标准 JSON 结构，统一使用 UTF-8 编码。

### 3.1 获取最近更新列表 (Recent Updates Feed)
用于 Agent 定时轮询、增量同步或在外部仪表盘中列出更新内容。

- **URL**: `GET /api/v1/content-dashboard/updates`
- **HTTP 方法**: `GET`
- **请求参数**:

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
| :--- | :--- | :--- | :--- | :--- |
| `only_major` | boolean | 否 | `true` | 是否仅返回主要改动。传 `false` 返回包含标点微调的全量历史 |
| `limit` | integer | 否 | `20` | 每页数量，最大 100 |
| `page` | integer | 否 | `0` | 分页页码，从 0 开始 |
| `bundle` | string | 否 | 无 | 按内容类型过滤（如 `article` 或 `article,page`） |
| `langcode` | string | 否 | 无 | 按语言过滤（如 `en`, `zh-hans`） |
| `change_type` | string | 否 | 无 | 按变更标签过滤（`title`, `h1`, `body_major`, `created`, `status`） |
| `since` | integer | 否 | 无 | UNIX 时间戳，仅获取该时间戳之后发生的变更（增量拉取首选） |

#### cURL 示例：
```bash
curl -k -s -H "X-API-KEY: cd_secret_token_live" \
  "https://genehub.ddev.site/api/v1/content-dashboard/updates?only_major=true&limit=10"
```

#### 返回示例：
```json
{
  "status": "success",
  "data": [
    {
      "log_id": 73,
      "nid": 6,
      "vid": 220,
      "prev_vid": 219,
      "bundle": "article",
      "langcode": "en",
      "title": "Comprehensive Guide to Cell Isolation",
      "slug": "cell-isolation-guide",
      "url": "https://genehub.ddev.site/i/cell-isolation-guide",
      "path": "/i/cell-isolation-guide",
      "is_major": true,
      "change_flags": ["title", "h1", "body_major"],
      "summary": "- **Title**: Updated from 'Old Guide' to 'Comprehensive Guide to Cell Isolation'\n- **H1**: Page heading changed\n- **Content**: Substantive body modification (28.0% changed, +21 / -0 words)",
      "metrics": {
        "title_changed": true,
        "title_old": "Old Guide",
        "title_new": "Comprehensive Guide to Cell Isolation",
        "h1_changed": true,
        "body_metrics": {
          "is_substantive": true,
          "old_word_count": 75,
          "new_word_count": 96,
          "words_added": 21,
          "words_deleted": 0,
          "delta_words": 21,
          "diff_ratio": 0.28
        }
      },
      "updated_at": "2026-11-09T16:06:33+08:00",
      "timestamp": 1794211593,
      "author_uid": 1
    }
  ],
  "pagination": {
    "total": 97,
    "page": 0,
    "limit": 10,
    "has_more": true
  }
}
```

---

### 3.2 获取特定 Node 的变更历史 (Node History Timeline)
用于分析某一篇特定内容随时间演变的重大转折点，可直接与外部监控中的 SEO 排名或流量曲线对齐。

- **URL**: `GET /api/v1/content-dashboard/nodes/{nid}/history`
- **HTTP 方法**: `GET`
- **路径参数**:
  - `nid` (integer): Node ID
- **查询参数**:
  - `only_major` (boolean, 可选, 默认 `true`): 过滤次要标点修改
  - `limit` (integer, 可选, 默认 `50`): 最大返回版本数

#### cURL 示例：
```bash
curl -k -s -H "X-API-KEY: cd_secret_token_live" \
  "https://genehub.ddev.site/api/v1/content-dashboard/nodes/6/history?only_major=true"
```

#### 返回示例：
```json
{
  "status": "success",
  "node": {
    "nid": 6,
    "bundle": "article",
    "current_title": "Comprehensive Guide to Cell Isolation",
    "slug": "cell-isolation-guide",
    "url": "https://genehub.ddev.site/i/cell-isolation-guide",
    "path": "/i/cell-isolation-guide",
    "is_published": true,
    "created_at": "2026-08-13T10:47:56+08:00",
    "updated_at": "2026-11-09T16:06:33+08:00"
  },
  "history": [
    {
      "log_id": 73,
      "nid": 6,
      "vid": 220,
      "prev_vid": 219,
      "is_major": true,
      "change_flags": ["title", "h1", "body_major"],
      "summary": "- **Title**: Updated from 'Old Guide' to 'Comprehensive Guide to Cell Isolation'\n- **H1**: Page heading changed\n- **Content**: Substantive body modification (28.0% changed, +21 / -0 words)",
      "timestamp": 1794211593,
      "updated_at": "2026-11-09T16:06:33+08:00",
      "author_uid": 1
    },
    {
      "log_id": 70,
      "nid": 6,
      "vid": 14,
      "prev_vid": 0,
      "is_major": true,
      "change_flags": ["created"],
      "summary": "- **Created**: Initial content creation and publication.",
      "timestamp": 1786589311,
      "updated_at": "2026-08-13T10:48:31+08:00",
      "author_uid": 1
    }
  ]
}
```

---

### 3.3 获取版本详细差分对比 (Detailed Diff)
用于 Agent 深度分析某两个版本之间具体改了哪些句段或词汇。

- **URL**: `GET /api/v1/content-dashboard/nodes/{nid}/diff/{vid}`
- **HTTP 方法**: `GET`
- **路径参数**:
  - `nid` (integer): Node ID
  - `vid` (integer): Revision ID

#### cURL 示例：
```bash
curl -k -s -H "X-API-KEY: cd_secret_token_live" \
  "https://genehub.ddev.site/api/v1/content-dashboard/nodes/6/diff/220"
```

#### 返回结构：
```json
{
  "status": "success",
  "data": {
    "nid": 6,
    "vid": 220,
    "prev_vid": 219,
    "is_major": true,
    "change_flags": ["title", "h1", "body_major"],
    "summary": "- **Title**: ...\n- **Content**: ...",
    "metrics": { ... },
    "title_diff": {
      "old": "Old Guide",
      "new": "Comprehensive Guide to Cell Isolation",
      "changed": true
    },
    "h1_diff": {
      "old": "Old Guide",
      "new": "Comprehensive Guide to Cell Isolation",
      "changed": true
    },
    "body_diff": {
      "old_length": 413,
      "new_length": 579,
      "old_excerpt": "Previous textual content excerpt...",
      "new_excerpt": "Updated new textual content excerpt..."
    }
  }
}
```

---

## 4. 配套 Drush 运维命令

模块提供了高性能的 Drush 命令行工具，便于历史数据处理与维护。

### 4.1 历史版本回填 (Backfill)
一键批量扫描并建立全量历史版本的分析日志索引：

```bash
# 1. 增量回填（默认断点续跑，自动跳过已索引的版本）
ddev drush content-dashboard:backfill

# 2. 强制全量重构（清空 content_change_log 表并重新计算所有 revisions）
ddev drush cd-backfill --force -y

# 3. 指定仅回填某个内容类型（如 article）
ddev drush cd-backfill --bundle=article
```

> **实测基准性能**：在 DDEV 容器内，全量扫描分析 223 个历史版本仅需 **1.15 秒**。

---

## 5. Agent 推荐工作流实践

### 场景 A：定时监控与蝴蝶效应关联排查
1. Agent 记录上一次同步的时间戳 `last_sync_timestamp`；
2. 每小时或每天调用一次 `GET /api/v1/content-dashboard/updates?only_major=true&since={last_sync_timestamp}`；
3. 获取增量改动列表；若存在 `is_major = true` 的页面，在外部监控看板（如 Google Search Console / Google Analytics 时间线）上打上变更标记；
4. 当某个页面流量异动时，调用 `GET /nodes/{nid}/history` 查看该页面历次改动时间点，实现秒级根因定位。

### 场景 B：内容更新周报/月报自动生成
1. 调用 `GET /updates?only_major=true&since={start_of_week}`；
2. 提取每项变更中的 `summary`（已经是标准 Markdown List 列表）；
3. 结合 `url` 和 `title`，由 LLM 直接汇总输出内容迭代周报。
