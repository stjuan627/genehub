# AGENTS.md

## 通用约定
- 始终用中文沟通。
- 这是 Drupal 11 项目。执行 PHP/Drush/PHPCS 时优先用 DDEV 容器内命令，例如 `ddev php`、`ddev drush`、`ddev exec vendor/bin/phpcs`；不要依赖宿主机 PHP 版本。
- 新增或修改自定义模块时，默认落在 `web/modules/custom/genehub` 及其子模块目录内。
- 不要把新产品类型直接塞进父模块。父模块只提供 GeneHub 级聚合入口；每个产品类型使用独立子模块。

## 模块分层范式
- 父模块：`web/modules/custom/genehub`。
- 父模块职责：
  - 提供聚合管理页 `genehub.products`：`/admin/content/products`。
  - 提供聚合添加页 `genehub.products_add`：`/admin/content/products/add`。
  - 提供产品 entity type 管理占位页 `genehub.products_settings`：`/admin/structure/products`。
  - 提供旧 admin menu 入口 `genehub.products`，挂到 `system.admin_content`。
  - 提供旧 admin structure menu 入口 `genehub.products_settings`，挂到 `system.admin_structure`。
  - 提供 Drupal 11 Navigation `content` 菜单入口 `genehub.navigation.products`。
- 产品子模块命名：`genehub_<product>`，目录放在 `web/modules/custom/genehub/modules/genehub_<product>`。
- 子模块职责：
  - 定义对应产品 content entity。
  - 定义产品自己的权限、列表、表单、菜单、actions、local tasks。
  - 自己挂接到父模块的 products UI 和 Navigation UI。

## 产品实体范式
- 每个产品类型定义一个 bundleless content entity。
- entity id 使用 `product_<product>`，例如 `product_solidex`。
- entity class 仍放在 `src/Entity/<Product>Product.php`，不要因为 entity id 前缀调整而改类名模式。
- entity class 放在 `src/Entity/<Product>Product.php`。
- `base_table` / `data_table`、entity route name、link template 参数占位符默认与 entity id 保持一致，例如 `product_solidex`、`product_solidex_field_data`、`entity.product_solidex.*`、`{product_solidex}`。
- 不启用 revision：
  - 不设置 revision entity key。
  - 不定义 `revision_table` / `revision_data_table`。
  - 不添加 revision route/form。
- 支持多语言：
  - `translatable: TRUE`。
  - 必须使用 `base_table` + `data_table`。不要尝试只用一个表实现 Drupal 原生 Content Translation。
  - 必须设置 `langcode` entity key。
  - 必须使用 `ContentTranslationHandler` 或确保 Content Translation 可识别。
  - 必须提供或允许生成 `drupal:content-translation-*` link templates。
- 多值 base field 会生成 dedicated field table，这是接受的 Drupal 原生行为；不要为了“单表”牺牲字段语义。

## 字段范式
- 外部表迁移字段优先用 Drupal base fields 定义，除非明确需要 Field UI 配置字段。
- 旧站 URL 字段例如 `products_link` 用纯文本，优先 `string_long`，不要默认使用 `link` 字段。
- HTML/富文本内容字段用 `text_long`。
- 简单文本用 `string`。
- 长纯文本用 `string_long`。
- 结构字段、业务编号、上游 ID、规格类字段默认不翻译。
- 面向用户展示的内容字段默认可翻译，例如名称、描述、应用、FAQ、protocol、background 等。
- 字段是否能在 Content Translation UI 中切换，前提是代码里先 `setTranslatable(TRUE)`；UI 不能把代码里 `FALSE` 的 base field 升级成可翻译存储。
- base field 如需在表单显示配置中可调整，调用 `setDisplayConfigurable('form', TRUE)`。

## UI 路径与菜单范式
- 父模块固定 route：
  - `genehub.products` -> `/admin/content/products`
  - `genehub.products_add` -> `/admin/content/products/add`
  - `genehub.products_settings` -> `/admin/structure/products`
- 路径分层规则：
  - 内容实体管理、添加、查看、编辑、删除、翻译都使用 `/admin/content/products` 命名空间。
  - Entity type 管理、Field UI、Manage fields、Manage form display、Manage display 都使用 `/admin/structure/products` 命名空间。
- 产品子模块 route/link template 使用父路径命名空间：
  - collection：`/admin/content/products/<product>`
  - add-form：`/admin/content/products/add/<product>`
  - canonical：`/admin/content/products/<product>/{<entity_id>}`
  - edit-form：`/admin/content/products/<product>/{<entity_id>}/edit`
  - delete-form：`/admin/content/products/<product>/{<entity_id>}/delete`
  - translations：`/admin/content/products/<product>/{<entity_id>}/translations`
  - entity type settings：`/admin/structure/products/<product>/settings`
  - Field UI 派生路径基于 settings route，例如 `/admin/structure/products/<product>/settings/fields`、`/form-display`、`/display`。
- 子模块必须提供旧 admin menu 入口：
  - collection menu parent 使用 `genehub.products`。
  - entity type settings menu parent 使用 `genehub.products_settings`。
- 子模块必须支持 Drupal 11 Navigation：
  - 父模块在 `menu_name: content` 中提供 `genehub.navigation.products`。
  - 子模块在 `menu_name: content` 中提供 `<module>.navigation.products.<product>`，parent 使用 `genehub.navigation.products`。
  - 子模块在 `menu_name: content` 中提供 `<module>.navigation.create.<entity>`，parent 使用 `navigation.create`，route 指向 add-form。
  - 上面的 `<entity>` 默认就是 `product_<product>`，不要再生成 `<product>_product`。
  - 不要把 Navigation 作为硬依赖；Navigation 禁用时这些 menu link 不应影响旧后台。
- 子模块 local action：
  - Add action 至少出现在 `genehub.products_add` 和产品 collection 页。
- 子模块 local task：
  - 产品 collection local task 的 `base_route` 使用 `genehub.products`。
  - 实体 canonical/edit/delete tabs 的 `base_route` 使用实体 canonical route。
  - Translate tab 由 Content Translation 基于 entity link templates 派生，不要手写重复 tab。

## 后台列表与表单范式
- 产品实体应提供自定义 ListBuilder，而不是长期依赖 core 默认列表。
- ListBuilder 至少展示：
  - 产品名称。
  - 业务编号，例如 `cat_no`。
  - 发布状态。
- 产品实体应提供自定义 ContentEntityForm 子类，用于整理后台表单。
- 表单可参考 SOLIDEX 当前模式：
  - 使用 `advanced` 区域。
  - 增加状态 meta 信息。
  - 把目录/业务字段分组到 `Catalog information`。
  - 把 `uid`、`created` 放到 `Authoring information`。
  - 把语言字段放到 `Language` 分组。
- 项目使用 Gin 主题，子模块可通过 `hook_gin_content_form_routes()` 把 add/edit/translation form route 纳入 Gin content form 样式。

## 验证命令
- PHP 语法：
  - `ddev php -l web/modules/custom/genehub/modules/<module>/src/Entity/<Entity>.php`
  - 对新增 controller/form/list builder 也运行 `ddev php -l`。
- YAML 解析：
  - 用 `ddev php` 加 Symfony YAML 解析新增或修改的 `.yml` 文件。
- PHPCS：
  - `ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml web/modules/custom/genehub`
- Drupal 缓存与路由：
  - `ddev drush cr`
  - `ddev drush route | grep -E 'genehub.products|entity.<entity_id>'`
- 实体定义：
  - 用 `ddev drush php:eval` 检查 `isTranslatable()`、`isRevisionable()`、base table、data table、link templates。
  - 同时确认 entity id、base table、data table、route name 前缀一致，例如 `product_solidex` / `product_solidex_field_data` / `entity.product_solidex.*`。
- 实体 smoke test：
  - 创建一条最小实体，保存后确认 canonical URL，再删除测试实体。
- Navigation 验证：
  - 如果 Navigation 已启用，检查 `content` 菜单树是否包含产品入口和 create 入口。
  - 如果 Navigation 未启用，至少确认 menu link discovery 不因 `menu_name: content` 失败，并说明未做实际 Navigation UI 验证。
