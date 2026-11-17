# GeneMedi 多语言本地化工作流

本模块管理 Drupal 源内容快照、多语言任务、worker 租约、生成结果和入库回执。外部 agent 完成目标市场调研与本地化写作，生成 worker 将结果回写；独立入库 worker 直接调用 Drupal 核心 JSON:API PATCH 目标翻译并发布。

## 安装与前置条件

在 GeneHub 项目根目录执行：

```sh
ddev drush en genemedi_i18n_workflow -y
ddev drush cr
```

依赖 GeneHub、JSON:API、Content Translation 和 Basic Auth。目标语言必须已在站点配置，目标内容类型支持翻译与 revision，发布状态字段必须可翻译。

现有 Generic/SOLIDEX 产品已经支持 revision；本次分别增加 `genehub_generic_update_11004()`、`genehub_solidex_update_11004()`，将产品发布状态改为可翻译。现有站点须按部署更新流程执行适用的更新，保留已有当前值和历史修订；新安装使用更新后的字段定义。未更新且发布状态仍共享时，入队会明确拒绝，避免创建目标草稿时下架源语言。

模块安装时将已有 `node.page.status` 字段覆盖配置设为可翻译，使 Basic page 的未发布目标草稿与原文发布状态相互独立；不修改现有内容值。卸载不反向恢复共享状态，避免抹平各语言已经独立的发布状态。

JSON:API 必须在站点现有配置中允许写操作，worker 账户还需拥有目标实体更新、相应字段/文本格式和发布权限。本模块不自动更改全站 JSON:API 读写配置，不创建账户、不自动授予角色权限。

当前支持：

- `node` 的 `page` bundle：本地化纯文本标题 `title`、纯文本 meta description `field_meta_description` 和正文 `body`，保留完整正文 HTML；其他字段保持原有范围。meta description 仅在字段存在且可翻译、可写时纳入。
- `product_generic`、`product_solidex`：按真实可翻译文本字段及多值顺序处理。
- 目标翻译不存在时，在入队事务中复制源字段建立未发布翻译；已有目标翻译及其发布状态保持不变，直至结果实际入库。
- 通过核对后自动覆盖指定目标字段、发布，并为每次实际成功执行的内容 PATCH 创建新 revision。其他语言及未指定字段保持不变。

任务快照是固定的。升级前只含 `title` / `body` 的 page 任务不会自动增加 meta description；字段映射变化会触发源版本保护。需要通过入队接口创建包含新映射的任务（已有同语言任务时可用 `regenerate: true`），不要修改旧快照或向旧结果追加字段。

## 后台入口与入队

- 任务概览：`/admin/content/genemedi-i18n-workflow`。
- 入队表单：`/admin/content/genemedi-i18n-workflow/queue`。按标题自动补全选择实体，切换实体类型时刷新候选范围；Basic page 仅检索 page 节点。目标语言使用与批量操作相同的复选框及市场配置，源语言保持单选。
- 支持的实体列表操作中提供 `Queue localization task`。

### 批量入队

产品列表保留 `/admin/content/products/generic`、`/admin/content/products/solidex` 原地址。对应 Views 为 `genehub_generic_products` 和 `genehub_solidex_products`；可筛选产品名称、Slug、目录号及发布状态，显示更新时间并默认倒序，数据列支持点击表头排序，每页 50 条，每个产品仅显示默认语言一行。SOLIDEX 的 Slug 从现有 `products_link` 提取显示，检索匹配该存储字段。勾选产品后沿用 Gin／Claro 底部悬浮批量操作栏。已有站点执行两产品模块 `11005`、`11006` 更新并重建缓存；更新只创建缺失的 View，不覆盖已有定制配置。

Generic 与 SOLIDEX 产品列表由 Views 渲染，保留现有地址及菜单。支持按产品名称、目录号与发布状态筛选，显示更新时间并默认按更新时间倒序，每页 50 项。两类产品分别执行 `genehub_generic_update_11005()`、`genehub_solidex_update_11005()` 对应更新安装缺失的 View，不覆盖已有同名 View。可在 Views UI 调整列表字段及筛选条件。

列表可勾选当前页的多个产品，选择 `Queue localization tasks` 并点击 `Apply to selected items`，进入统一配置表单：

1. 核对所选内容，指定源语言。
2. 多选目标语言，确认每种语言的市场（可修改预填值）。
3. 点击 `Queue localization tasks`，按“实体 × 目标语言”入队，并显示批处理进度。

进入配置表单及取消操作不会创建任务或翻译草稿。确认后才预建缺失的目标草稿；已有译文保持原状。重复提交默认复用匹配任务，批处理结束报告新建、复用数量及逐项失败原因；成功项不因其他实体失败而回滚。可重试失败项，原有防重逻辑避免重复任务。需要重新生成时显式勾选新代次选项。

同时提供 Drupal 核心 Action，Basic page 可从 `/admin/content` 的 Views 批量操作中使用相同流程。自定义 Views 若限制了可选操作，需要将 `Queue localization tasks` 加入允许列表。非 Basic page 内容不允许执行此 Action。无需安装 Views Bulk Operations 扩展。

已有站点执行 `genemedi_i18n_workflow_update_11001()` 对应数据库更新以注册 Action 配置，并重建缓存；新安装自动安装适用的配置。不覆盖现有 Views 配置。批量入口要求 `administer genemedi i18n workflow` 及各实体更新权限。当前产品列表按当前页勾选，不包含跨页“全选所有结果”。


表单选择实体类型、ID、源语言，并逐行指定目标语言及市场：

```text
de:DE
ja:JP
fr:FR
```

一个页面批次共享源快照，每个目标语言/市场有独立任务。同一批次不能让两个市场写入同一个 CMS 语言，也不能把源语言作为目标。相同源内容、目标语言和市场默认复用已有任务；主动重新生成时勾选 `Regenerate an existing completed task`，创建新任务代次并保留旧记录。

后台概览和列表 API 仅显示最近 100 条任务，供观察进度，不能作为 worker 发现全部待处理任务的依据。生成与入库分别使用各自的 claim API。

## 权限与认证

- `administer genemedi i18n workflow`：管理入口、入队、查看任务；入队还检查源实体更新权限。
- `execute genemedi i18n workflow`：领取、续期、回写结果、报告失败及入库操作。

所有 API 强制已认证账户，支持 Basic Auth 或 Cookie。Cookie 会话的写操作必须提供有效 `X-CSRF-Token`。JSON:API PATCH 保留核心实体与字段访问检查；工作流执行权限不会替代内容更新权限。

以下示例只描述请求结构，不包含凭证。由部署环境的 HTTP 客户端提供认证，并使用可信站点地址；不要将密码、令牌或领取凭证写入 URL、调研内容或日志。

## 源快照与结果格式

任务详情包含 `snapshot`、`result`、状态、生成/入库尝试计数及 revision 回执等信息。`snapshot.fields` 是原始 Drupal 字段项数组，不是拼接后的整页字符串。`snapshot.mapping` 说明各字段类型、允许本地化的属性与 HTML 属性；`snapshot.context` 提供不可随意修改的理解背景。

例如，源字段可能是：

```json
{
  "body": [
    {
      "value": "<p>Capacity: 10 mL.</p>",
      "summary": "",
      "format": "full_html"
    }
  ]
}
```

源值以实际快照为准。提交字段集合、每项属性集合、多值数量与顺序必须与快照一一对应；不得省略空字段、增加新内容项或更改 `format`。产品的 `genehub_section` 保留 `heading`、`body`、`format` 结构；`genehub_sales_unit` 仅 `label` 可本地化，SKU、规格、单位和价格原样保留。其他支持的文本字段按照快照映射处理，不能从页面外观猜字段。

`complete` 的结果结构：

```json
{
  "token": "<generation-claim-token>",
  "result": {
    "fields": {
      "body": [
        {
          "value": "<p>Kapazität: 10 mL.</p>",
          "summary": "",
          "format": "full_html"
        }
      ]
    },
    "research": {
      "queries": ["<actually executed target-market query>"],
      "sources": ["<actually inspected source URL>"],
      "decision": "<keyword decision and evidence>"
    },
    "checks": {
      "structure_passed": true,
      "facts_passed": true,
      "details_preserved": true
    }
  }
}
```

上述为格式示例，不能将占位研究内容作为真实证据提交。API 要求 `research` 为数组/对象，三个 `checks` 值为布尔类型；不以其真假或研究条目数量作为内容质量门槛。agent 仍须按 skill 完成真实调研和核对，通过后才提交并发布。

API 仅校验交付数据格式（字段名、值类型、字段项数量等）、受保护属性（SKU、价格、引用、文本格式等）和任务/写入安全（权限、目标语言、租约、源版本、幂等及回执）。不对可本地化正文执行数值/单位正则比较、DOM 相等或空值/非空值保留等内容硬校验。原结构、事实及细节保留仍是 agent 的写作与审阅要求；API 接受结果不能证明内容正确，checks 也不是独立事实审查。

## Worker API

除核心 JSON:API PATCH 外，写请求使用 `Content-Type: application/json`。表中 ID 为任务 ID，`token` 均放在请求体中。

| 方法 | 路径 | 请求体／用途 |
| --- | --- | --- |
| GET | `/api/genemedi-i18n/tasks` | 最近 100 条，管理员或执行账户可读 |
| GET | `/api/genemedi-i18n/tasks/{id}` | 指定任务、源快照、结果和状态 |
| POST | `/api/genemedi-i18n/tasks` | 管理员入队，见下例 |
| POST | `/api/genemedi-i18n/tasks/claim` | `worker_id`、可选 `lease_seconds`；领取生成任务 |
| POST | `/api/genemedi-i18n/imports/claim` | `worker_id`、可选 `lease_seconds`；领取待入库结果 |
| POST | `/api/genemedi-i18n/tasks/{id}/heartbeat` | `token`、可选 `lease_seconds`；续期当前阶段 |
| POST | `/api/genemedi-i18n/tasks/{id}/complete` | `token`、`result`；保存通过检查的生成结果 |
| POST | `/api/genemedi-i18n/tasks/{id}/resume` | worker_id、可选 lease_seconds、token；同任务继续保存或入库 |
| POST | `/api/genemedi-i18n/tasks/{id}/cancel` | 取消未完成任务，不删除快照/结果/历史 |
| POST | `/api/genemedi-i18n/tasks/{id}/fail` | `token`、`message`、布尔 `retryable`；报告当前阶段失败 |
| POST | `/api/genemedi-i18n/tasks/{id}/begin-import` | `worker_id`、可选 `lease_seconds`；领取指定任务的入库阶段 |
| POST | `/api/genemedi-i18n/tasks/{id}/acknowledge` | 入库 `token`；回读确认 revision、内容和发布状态 |

租约默认 300 秒，可设置 30–3600 秒。`worker_id` 为非空字符串，最多 128 字节。

入队示例：

```json
{
  "entity_type": "node",
  "entity_id": "123",
  "source_lang": "en",
  "targets": [
    {"langcode": "de", "market": "DE"},
    {"langcode": "ja", "market": "JP", "regenerate": false}
  ]
}
```

`entity_id` 使用字符串。响应为 `{"batch_id": "...", "tasks": [...]}`。已配置目标语言最多 50 个；任务中的实际实体标识和字段映射由模块读取，不由 agent 杜撰。

两种 claim 请求均使用：

```json
{"worker_id": "worker-example-1", "lease_seconds": 300}
```

响应为 `{"task": null}` 时暂无可领取工作，worker 应按自身轮询策略稍后再试。成功时 `task` 包含此次领取的明文 `token`；普通列表和详情不返回 token 或存储的 token hash。每次重新领取都会替换凭证，过期/旧凭证不能续期、提交结果或写入。

## 生成 worker

1. 轮询 `/tasks/claim`；有任务时保存任务 ID 和此次 token。
2. 将不可变快照、目标语言/市场和写作 skill 交给 agent。源内容或字段映射发生变化时不能静默改写输入。
3. 执行期间定时发送 `heartbeat`，例如 `{"token":"<token>","lease_seconds":300}`。应在到期前续期，不在到期后尝试恢复旧租约。
4. 完成调研、本地化及原文双向核对后，调用 `complete` 保存结果；成功进入 `ready_for_import`。
5. 生成失败时使用 `fail`，例如 `{"token":"<token>","message":"<sanitized reason>","retryable":true}`。

worker 不能伪造检查通过，也不能自行修改队列表状态。已提交结果的网络重试可以复用相同请求；任务被新代次取代时旧 worker 的结果会被拒绝。

## 入库 worker 与核心 JSON:API

独立入库 worker 轮询 `/imports/claim`，无需重新调研或生成文案。成功领取通常得到 `task.token` 和 `task.patch`：

```json
{
  "method": "PATCH",
  "url": "/jsonapi/<actual-resource-path>/<entity-uuid>",
  "headers": {
    "Content-Type": "application/vnd.api+json",
    "Accept": "application/vnd.api+json",
    "X-Genemedi-I18n-Task": "<task-id>",
    "X-Genemedi-I18n-Token": "<import-claim-token>"
  },
  "payload": {
    "data": {
      "type": "<actual-resource-type>",
      "id": "<entity-uuid>",
      "attributes": {"<actual-public-field-name>": "<validated-value>"}
    }
  }
}
```

上例仅表示结构。实际资源路径、公开字段名、字段值及发布状态均由模块生成；worker 必须原样使用响应中的 URL、headers 和 payload，不手工构造或增删属性。

1. 检查返回任务状态。若已恢复为 `published`，或变为终止失败而没有 `patch`，不要发送 PATCH。
2. 在可信站点地址下请求 `patch.url`，使用返回的 method/headers，将 `patch.payload` 作为 JSON 请求体，同时提供既有认证；Cookie 认证仍需 CSRF token。
3. 两个 `X-Genemedi-I18n-*` 头是必需的。模块凭有效入库租约选择精确目标翻译，拒绝篡改的结果文档，并在保存时强制创建 revision。不能省略这些头而依赖 URL 的默认语言回退。
4. 如入库耗时较长，对入库 token 使用同一 `heartbeat` 接口续期。
5. PATCH 成功后，调用 `/tasks/{id}/acknowledge`，请求体 `{"token":"<import-token>"}`。模块回读检查新 revision 回执、目标字段和已发布状态，确认后置为 `published`。
6. PATCH 失败时，使用同一入库 token 调用 `fail`，保留已保存结果。可重试的入库错误返回 `ready_for_import`，不会回到生成队列。

保存 revision 时会在同一数据库事务内记录 `applied_revision`。若请求超时、HTTP 回复丢失或 worker 崩溃，不要盲目再次 PATCH：有效租约内先尝试 acknowledge；租约过期后由下一次入库 claim 检查回执并恢复确认。已经应用的同一结果不能再 PATCH，否则会被拒绝，避免产生重复 revision。

## 状态与恢复

主流程：

```text
queued → running → ready_for_import → importing → published
```

生成与入库分别计数，最多各尝试 5 次；可重试错误使用有上限的指数退避。生成不可重试或重试耗尽为 `failed`；入库相应为 `import_failed`。生成 lease 到期可重新领取；入库 lease 到期先检查已应用回执再决定恢复或重新入库。主动重新生成可取代旧的未完成任务，旧状态变为 `superseded`。

源内容检查基于本地化字段、相关源上下文与映射的校验值，不仅比较实体 revision ID。预建其他语言草稿或正常导入其他语言会增加实体 revision，不能因此误判原文变化。真正的源文变化会拒绝旧结果入库，应重新入队。

同实体多语言入库按实体租约和短时数据库锁协调；其他实体仍可继续处理。失败修复后使用同任务 resume，不要求重新生成/入队；不要重发过期凭证。

## 实现范围与限制

- 本模块不包含外部 agent runner、模型调用、联网搜索服务或常驻 worker 进程；部署方实现两个轮询执行阶段并加载本地化 skill。
- 尚未实现 Content Moderation 工作流适配，检测到审核字段/工作副本时拒绝自动发布，避免绕过审核或触发核心 PATCH 的 working-copy 限制。
- 核心 JSON:API、账户访问权限、文本格式、字段公开配置和真实源字段仍须与目标站点一致。接口异常不授权修改全站配置或删减内容。
- 本模块处理指定字段的存储与发布，不额外触发旧站路由同步、外部站点部署或其他系统操作。
- 数据与队列状态由程序维护；调研质量和语义事实完整性仍按写作 skill 审核。

## 同任务保存重试

保存失败不需要重新入队、换任务 ID 或重新调研写作。有效 running 租约可直接修正后重交 complete；failed/import_failed、租约到期或达到自动上限时，可显式调用：

```text
POST /api/genemedi-i18n/tasks/16/resume
```

请求体 `{"worker_id":"execution-id","lease_seconds":300}`；继续本执行尚有效的租约时另提供原 token，worker_id 也须匹配。其他执行者的活租约不能被抢占。

响应直接是任务对象，next_action 为 complete（继续提交本地草稿）、patch（已有落库结果，继续入库，响应含 patch 与入库 token）或 done（已写入且回执恢复成功）。任务 ID、源快照、已保存结果和尝试历史保留；显式恢复绕过自动5次上限与退避时间，但累计次数不清零。原文/映射变化仍须处理冲突，不能用旧结果覆盖新源。

published/completed、cancelled 以及被新代次取代的 superseded 任务不可 resume。取消调用同任务 cancel 接口，立即作废凭据；已应用的内容不能通过取消撤回。resume 会先恢复已有写入回执，避免丢回复后再 PATCH 产生重复 revision。
