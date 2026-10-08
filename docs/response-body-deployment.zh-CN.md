# PHP response body 扩展交付与部署

观测云发行版本为 `1.20.7-gtrace`，无需等待 Datadog 上游合并；由观测云构建、交付和维护。标准 Release 通过 GitHub Actions 远端构建，沿用历史版本的完整矩阵：PHP 7.0–8.5、x86_64/ARM64、glibc/musl、NTS/ZTS，以及历史支持的 GNU/Linux debug 变体。发布 ABI 独立包、平台汇总包、RPM/DEB/APK、系统安装 tar 包与 SSI 包；标准安装包包含对应矩阵支持的 AppSec 和 Profiler 组件。

历史的本地 PHP 8.1 专项验证包与标准 Release 的构建环境、组件范围不同。本地 `Dockerfile.build` 和 `package-response-body.sh` 仍用于研发复现，生成的包只有 PHP 8.1 NTS、x86_64 tracer；若在 Debian Trixie 中构建，该包可能要求较新的 glibc。客户交付应使用标准 Release，并选择与现有业务镜像的架构、libc 和 PHP ABI 匹配的包。

## 功能与参数

在 HTTP 请求根 Span 的 `response_body` 字段记录 PHP 实际输出的响应体。默认关闭。开启后采集所有 HTTP 状态，包括 2xx 与 5xx；本次未实现仅错误状态开关。

| 环境变量 | 默认值 | 说明 |
| --- | --- | --- |
| `DD_TRACE_RESPONSE_BODY_ENABLED` | `false` | 是否采集响应体 |
| `DD_TRACE_RESPONSE_BODY_MAX_SIZE` | `8192` | 最大保留字节数；0 或负数禁用，超过 1 MiB 按 1 MiB 处理 |
| `DD_TRACE_RESPONSE_BODY_BLACKLIST_URLS` | 空 | 逗号分隔的路径黑名单，如 `/login,/private/*`；尾部 `*` 匹配前缀，忽略 URL 查询参数 |
| `DD_TRACE_RESPONSE_BODY_WHITELIST_URLS` | 空 | 逗号分隔的路径白名单，如 `/api/order,/api/payment/*`；为空不限制路径，非空时只采集匹配路径 |

黑白名单都按原始 URL 路径匹配，区分大小写，忽略查询参数；支持精确路径和尾部 `*` 前缀匹配，不支持正则表达式或任意位置通配符。黑名单优先：即使路径命中白名单，只要同时命中黑名单，也不采集响应体。白名单不能覆盖总开关、媒体类型和长度限制。名单只控制响应体字段，Trace 仍按原有规则采集。

支持 `application/json` 与 `text/plain`，大小写不敏感，允许 `charset` 参数。超过长度时只保留前缀并设置 `response_body_truncated=true`。空响应、HEAD、其他媒体类型、PHP 已压缩的响应不添加字段。Nginx/Varnish 在 PHP 之后执行的压缩不影响采集。

采集位于 PHP 输出至 SAPI 的通道，遵循业务原有输出缓冲和 flush，不额外创建输出缓冲。字段随原有 Trace 采样和发送流程上报，不会额外创建一条 Trace。需自动生成请求根 Span；手动提前关闭或发送根 Span 时只能获得当时已输出的内容。CLI 与独立 worker 请求生命周期暂不支持；客户 PHP-FPM 是本次验收环境。

配置也可写入 PHP INI：`datadog.trace.response_body_enabled`、`datadog.trace.response_body_max_size`、`datadog.trace.response_body_blacklist_urls`、`datadog.trace.response_body_whitelist_urls`。建议在启动 FPM 前固定配置，修改后重启 FPM 或滚动重建 Pod。

## 客户部署

1. 从 `1.20.7-gtrace` Release 下载 `datadog-setup.php`。在线安装执行 `php datadog-setup.php --php-bin=all`，安装器会从观测云 fork 下载对应平台汇总包。离线安装则同时下载平台汇总包，并传入 `--file`；例如 x86_64 glibc 使用 `dd-library-php-1.20.7-gtrace-x86_64-linux-gnu.tar.gz`，Alpine 使用 `linux-musl`，ARM64 使用 `aarch64`。
2. 将仓库内的示例 Dockerfile 和 FPM 配置、下载的压缩包与安装脚本放到同一目录。核对文件 SHA-256 与 GitHub Assets 提供的 digest 一致后，生成构建时复核用的 `SHA256SUMS`：

```bash
sha256sum dd-library-php-1.20.7-gtrace-x86_64-linux-gnu.tar.gz datadog-setup.php > SHA256SUMS
```

将示例 Dockerfile 的 `PHP_BASE_IMAGE` 指向客户当前业务镜像，并通过 `DDTRACE_ARCHIVE` 指定对应平台汇总包；安装器会根据实际 PHP ABI 和 NTS/ZTS/debug 自动选择扩展。保留客户 Magento 所需的 PHP 模块、应用目录和启动参数。
3. 构建业务镜像：

```bash
docker build --build-arg PHP_BASE_IMAGE=<客户原业务镜像> -t <业务镜像>:response-body .
```

已有 ddtrace 应使用同版本安装脚本替换，确认 INI 中只有一条有效的 `extension=ddtrace.so`。不要同时加载两个 ddtrace 扩展。标准 Release 包含 AppSec/Profiler 组件；如客户原环境使用这些组件，保持相应启用配置，并在业务镜像中验证升级。

4. 沿用原有观测云 DataKit 地址、服务名、环境名等配置。示例运行参数：

```bash
docker run --rm \
  -e DD_AGENT_HOST=<DataKit地址> \
  -e DD_TRACE_AGENT_PORT=9529 \
  -e DD_SERVICE=customer-magento \
  -e DD_TRACE_RESPONSE_BODY_ENABLED=true \
  -e DD_TRACE_RESPONSE_BODY_MAX_SIZE=8192 \
  -e DD_TRACE_RESPONSE_BODY_BLACKLIST_URLS='/login,/private/*' \
  -e DD_TRACE_RESPONSE_BODY_WHITELIST_URLS='/api/order,/api/payment/*' \
  <业务镜像>:response-body
```

DataKit 需已开启 DDTrace 接收器，端口以实际配置为准。Kubernetes 同样设置容器环境变量并更新业务镜像。FPM 会过滤环境变量，附带 pool 配置显式传递这四个参数；其他 DDTrace 参数继续使用客户既有透传配置。

5. `php --ri ddtrace` 确认加载版本；`php-fpm -tt` 检查 pool 配置。通过业务 HTTP 接口请求一个 JSON 响应，在观测云 Trace 详情查看服务端根 Span 的 `response_body`。关闭开关后重启，验证字段不再出现。PHP CLI 检查只能证明扩展加载，实际开关需通过 FPM 请求验收。

回退可滚动恢复原业务镜像；只停用此功能则设置 `DD_TRACE_RESPONSE_BODY_ENABLED=false` 并重启。启用后记录的是响应原文，不自动做字段脱敏，部署时用黑名单排除不应采集的接口。

## 研发复现与发布

标准发布由 `.github/workflows/release-packages.yml` 在 GitHub Actions 远端执行。推送以 `-gtrace` 结尾的版本标签会自动触发，也可通过 `workflow_dispatch` 指定版本标签运行完整流程。正式发布沿用此流程生成完整 Assets。

本地研发复现时，在匹配客户 ABI/架构/libc 的 PHP 构建环境中，安装 PHP 开发工具、C/C++、Rust、CMake、libcurl 开发包等构建依赖。以下打包脚本仅生成 PHP 8.1 NTS x86_64 glibc 的 tracer 专项验证包：

```bash
git submodule update --init --recursive
make
bash tooling/bin/package-response-body.sh /absolute/output/directory
```

安装生成包后，在包含 Python 3 和 python3-msgpack 的 PHP-FPM 容器内运行：

```bash
python3 tests/integration/response_body/test_fpm.py
```

测试同时检查实际 FastCGI 输出字节和发送给模拟 Agent 的 Trace 字段，覆盖默认关闭、开关、分段输出、缓冲处理、大小限制、黑白名单及冲突优先级、HEAD、压缩、错误状态和同 worker 请求隔离。客户部署还需在实际 Magento/Varnish 链路进行灰度验证；后续升级继续选用观测云发行包。
