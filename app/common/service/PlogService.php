<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 操作日志统一服务（对标参考系统 System::plog 手动埋点）
 *
 * 约定：
 * - plog 表：aid/bid/uid/remark/ip/createtime
 * - 各业务写操作处调用 PlogService::write() 埋点，记录"谁在什么时间做了什么"
 * - 埋点失败不影响主业务（吞异常静默）
 */
class PlogService
{
    /**
     * 写入一条操作日志
     *
     * @param int    $aid    租户 ID
     * @param int    $uid    操作管理员 ID（admin_user.id）
     * @param string $remark 操作描述（如：编辑管理员账号3）
     * @param int    $bid    商户 ID（平台级操作为 0）
     * @param string $ip     操作 IP（缺省自动取当前请求 IP）
     */
    public static function write(int $aid, int $uid, string $remark, int $bid = 0, string $ip = ''): void
    {
        try {
            Db::name('plog')->insert([
                'aid'        => $aid,
                'bid'        => $bid,
                'uid'        => $uid,
                'remark'     => $remark,
                'ip'         => $ip !== '' ? $ip : request()->ip(),
                'createtime' => time(),
            ]);
        } catch (\Throwable $e) {
            // 埋点失败不阻断业务
        }
    }
}
