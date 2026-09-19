<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use think\db\BaseQuery;
use think\facade\Db;
use think\Request;

/**
 * 租户数据隔离服务（防越租户查询）
 *
 * 约定：后续所有 admin/api 业务查询一律通过本类起查询，
 * 强制携带 aid 条件，杜绝跨租户数据泄露。
 * 用法：ScopeService::query('shop_order', $aid)->where(...)->select();
 */
class ScopeService
{
    /**
     * 从请求上下文解析当前租户 ID
     * 优先级：JWT 载荷中的 aid（AuthMiddleware 注入，不可伪造） > TenantMiddleware 解析值
     */
    public static function aidOf(Request $request): int
    {
        $payload = $request->authPayload ?? null;

        if (is_array($payload) && isset($payload['aid'])) {
            return (int) $payload['aid'];
        }

        return (int) ($request->aid ?? 0);
    }

    /**
     * 发起带租户隔离条件的查询（aid<=0 直接拒绝，防止漏传）
     *
     * @param string $table 表名（不带前缀）
     * @param int    $aid   租户 ID
     * @return BaseQuery 已附加 where aid = $aid 的查询对象
     */
    public static function query(string $table, int $aid): BaseQuery
    {
        if ($aid <= 0) {
            throw new BusinessException('非法的租户标识', 403, 403);
        }

        return Db::name($table)->where('aid', $aid);
    }

    /**
     * 发起带租户隔离条件的别名查询（用于 join 场景，避免 aid 列歧义）
     *
     * @param string $table 表名（不带前缀）
     * @param int    $aid   租户 ID
     * @param string $alias 表别名
     * @return BaseQuery 已附加 where {alias}.aid = $aid 的查询对象
     */
    public static function queryAs(string $table, int $aid, string $alias): BaseQuery
    {
        if ($aid <= 0) {
            throw new BusinessException('非法的租户标识', 403, 403);
        }

        return Db::name($table)->alias($alias)->where($alias . '.aid', $aid);
    }

    /**
     * 商户维度过滤（任务177，对标参考系统 showtype 语义）
     *
     * 取值口径：'' / 'all' = 不过滤；'0' = 自营（bid=0）；
     * 'gt0'/'merchant' = 全部商户（bid>0）；其它正整数 = 指定商户
     *
     * @param BaseQuery $query 目标查询（已带租户隔离）
     * @param string    $bid   商户维度参数
     * @param string    $field bid 列名（含别名，如 h.bid）
     */
    public static function applyBid(BaseQuery $query, string $bid, string $field = 'bid'): BaseQuery
    {
        $bid = trim($bid);

        if ('' === $bid || 'all' === $bid) {
            return $query;
        }
        if ('gt0' === $bid || 'merchant' === $bid) {
            return $query->where($field, '>', 0);
        }

        return $query->where($field, (int) $bid);
    }
}
