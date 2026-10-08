# PHP response body 扩展交付与部署

这是 GuanceCloud/dd-trace-php gtrace 分支上的开发验证版本：`1.20.7-gtrace-dev-response-body`，基于 `a09b326dd`。无需等待 Datadog 上游合并；由观测云构建、交付和维护。当前交付范围是 PHP 8.1、NTS、Linux x86_64、glibc，包含 tracer，不包含 profiler 和 AppSec。正式发布前需按客户业务镜像验证并固定版本。

当前二进制在 PHP 8.1.34-FPM、Debian Trixie、glibc 2.41 中构建，ELF 至少需要 `GLIBC_2.39`，不能用于 Debian Bookworm 等更旧系统。随附 `Dockerfile.build` 固定了本次构建镜像 digest。客户镜像若系统库更旧，需要在匹配客户系统的构建环境中重新编译；不要为安装扩展直接替换客户原有基础系统。

## 功能与参数

在 HTTP 请求根 Span 的 `response_body` 字段记录 PHP 实际输出的响应体。默认关闭。开启后采集所有 HTTP 状态，包括 2xx 与 5xx；本次未实现仅错误状态开关。

| 环境变量 | 默认值 | 说明 |
| --- | --- | --- |
| `DD_TRACE_RESPONSE_BODY_ENABLED` | `false` | 是否采集响应体 |
| `DD_TRACE_RESPONSE_BODY_MAX_SIZE` | `8192` | 最大保留字节数；0 或负数禁用，超过 1 MiB 按 1 MiB 处理 |
| `DD_TRACE_RESPONSE_BODY_BLACKLIST_URLS` | 空 | 逗号分隔的路径黑名单，如 `/login,/private/*`；尾部 `*` 匹配前缀，忽略 URL 查询参数 |

支持 `application/json` 与 `text/plain`，大小写不敏感，允许 `charset` 参数。超过长度时只保留前缀并设置 `response_body_truncated=true`。空响应、HEAD、其他媒体类型、PHP 已压缩的响应不添加字段。Nginx/Varnish 在 PHP 之后执行的压缩不影响采集。

采集位于 PHP 输出至 SAPI 的通道，遵循业务原有输出缓冲和 flush，不额外创建输出缓冲。字段随原有 Trace 采样和发送流程上报，不会额外创建一条 Trace。需自动生成请求根 Span；手动提前关闭或发送根 Span 时只能获得当时已输出的内容。CLI 与独立 worker 请求生命周期暂不支持；客户 PHP-FPM 是本次验收环境。

配置也可写入 PHP INI：`datadog.trace.response_body_enabled`、`datadog.trace.response_body_max_size`、`datadog.trace.response_body_blacklist_urls`。建议在启动 FPM 前固定配置，修改后重启 FPM 或滚动重建 Pod。

## 客户部署

1. 将压缩包、`datadog-setup.php`、`SHA256SUMS`、`Dockerfile` 和 FPM 配置放到同一目录。安装脚本已指向观测云 fork；使用 `--file` 离线安装，无需从 Datadog 下载。
2. 将示例 Dockerfile 的 `PHP_BASE_IMAGE` 指向客户当前业务镜像。扩展必须匹配 PHP API `20210902`、NTS、x86_64 和 glibc；Alpine/musl、ARM64、ZTS 或其他 PHP 版本需重新构建。保留客户 Magento 所需的 PHP 模块、应用目录和启动参数。
3. 构建业务镜像：

```bash
docker build --build-arg PHP_BASE_IMAGE=<客户原业务镜像> -t <业务镜像>:response-body .
```

已有 ddtrace 应使用附带安装脚本替换，确认 INI 中只有一条有效的 `extension=ddtrace.so`。不要同时加载两个 ddtrace 扩展。当前包只有 tracer，不要加 `--enable-profiling` 或 `--enable-appsec`；如原环境启用了这些组件，先使用相同版本的完整组件构建验证。

4. 沿用原有观测云 DataKit 地址、服务名、环境名等配置。示例运行参数：

```bash
docker run --rm \
  -e DD_AGENT_HOST=<DataKit地址> \
  -e DD_TRACE_AGENT_PORT=9529 \
  -e DD_SERVICE=customer-magento \
  -e DD_TRACE_RESPONSE_BODY_ENABLED=true \
  -e DD_TRACE_RESPONSE_BODY_MAX_SIZE=8192 \
  -e DD_TRACE_RESPONSE_BODY_BLACKLIST_URLS='/login,/private/*' \
  <业务镜像>:response-body
```

DataKit 需已开启 DDTrace 接收器，端口以实际配置为准。Kubernetes 同样设置容器环境变量并更新业务镜像。FPM 会过滤环境变量，附带 pool 配置显式传递这三个参数；其他 DDTrace 参数继续使用客户既有透传配置。

5. `php --ri ddtrace` 确认加载版本；`php-fpm -tt` 检查 pool 配置。通过业务 HTTP 接口请求一个 JSON 响应，在观测云 Trace 详情查看服务端根 Span 的 `response_body`。关闭开关后重启，验证字段不再出现。PHP CLI 检查只能证明扩展加载，实际开关需通过 FPM 请求验收。

回退可滚动恢复原业务镜像；只停用此功能则设置 `DD_TRACE_RESPONSE_BODY_ENABLED=false` 并重启。启用后记录的是响应原文，不自动做字段脱敏，部署时用黑名单排除不应采集的接口。

## 研发复现与发布

在匹配客户 ABI/架构/libc 的 PHP 构建环境中，安装 PHP 开发工具、C/C++、Rust、CMake、libcurl 开发包等构建依赖。

```bash
git submodule update --init --recursive
make
bash tooling/bin/package-response-body.sh /absolute/output/directory
```

安装生成包后，在包含 Python 3 和 python3-msgpack 的 PHP-FPM 容器内运行：

```bash
python3 tests/integration/response_body/test_fpm.py
```

测试同时检查实际 FastCGI 输出字节和发送给模拟 Agent 的 Trace 字段，覆盖默认关闭、开关、分段输出、缓冲处理、大小限制、黑名单、HEAD、压缩、错误状态和同 worker 请求隔离。正式交付需再在客户 Magento/Varnish 链路进行灰度验证。当前开发包没有公开发布；正式版本由观测云仓库打 tag 并构建对应平台产物，后续升级继续选用观测云发行包。
