# app/common/service — 领域服务层

存放跨应用共享的领域服务（Domain Service）类，按业务域组织，例如：

- `Result.php`：统一响应结果封装（已就位）
- `TenantService.php`：租户（aid）管理与初始化（后续任务实现）
- `AuthService.php`：JWT 签发/校验、登录态管理（后续任务实现）
- `RbacService.php`：角色权限（RBAC）校验（后续任务实现）
- 其他业务服务：订单、支付、设备等领域逻辑

约定：

1. 所有文件 `declare(strict_types=1)`，遵循 PSR-12；
2. 服务类只做业务逻辑与数据编排，不直接输出响应（响应由控制器 + `Result` 完成）；
3. 命名空间 `app\common\service`；
4. 中间件位于 `app/common/middleware`，模型位于 `app/common/model`（后续任务建立）。
