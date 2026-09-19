<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\service\ScoreService;
use Closure;
use think\Http;
use think\facade\Db;
use think\Request;
use think\Response;

/**
 * 分销推荐关系绑定中间件（任务179 批次3-A）
 *
 * 对标参考系统 canzhao/app/controller/ApiCommon.php 99-121（请求初始化 pid 绑定）：
 * 请求带 fromid 参数且已登录时，按上级等级 agent_rule 三模式绑定推荐关系：
 * - agent_rule=1：绑定一次（已有 pid 不覆盖）
 * - agent_rule=2：随时覆盖（已有 pid 也换绑）
 * - agent_rule=3：首单后绑定（会员无任何已支付订单才绑）
 *
 * 前置条件（对齐参考系统）：fromid≠本人≠当前pid；上级存在且上级.pid≠本人（防循环）；
 * 本人 pid_origin 为空（原推荐关系锁定后不可改）；上级等级 can_agent≠0 且 agent_rule>0。
 *
 * 绑定后维护 member.pid/path/pid_origin/path_origin/change_pid_time，
 * 并按上级等级 score1（+scoremax 上限）送推荐积分（member_tjscore 去重）。
 */
class FenxiaoBindMiddleware
{
    public function __construct(protected Http $http, protected ScoreService $scoreService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // 控制器执行前绑定（对标参考系统 ApiCommon 请求初始化时序）；异常不打断主链路
        try {
            if ('api' === strtolower($this->http->getName())) {
                $mid = (int) ($request->authUid ?? 0);
                $aid = (int) ($request->aid ?? 0);
                $fromid = (int) $request->param('fromid', 0);

                if ($mid > 0 && $aid > 0 && $fromid > 0) {
                    $this->bindPid($aid, $mid, $fromid);
                }
            }
        } catch (\Throwable) {
            // 绑定失败不影响业务请求
        }

        return $next($request);
    }

    /**
     * pid 绑定主逻辑
     */
    protected function bindPid(int $aid, int $mid, int $fromid): void
    {
        if ($fromid === $mid) {
            return;
        }

        $member = Db::name('member')->where('aid', $aid)->where('id', $mid)->find();

        if (empty($member)) {
            return;
        }

        $member = (array) $member;

        if ($fromid === (int) ($member['pid'] ?? 0)) {
            return; // 已是当前上级
        }

        // 原推荐关系已锁定（pid_origin 非空）不再变更
        if (!empty($member['pid_origin'])) {
            return;
        }

        $upuser = Db::name('member')->where('aid', $aid)->where('id', $fromid)->find();

        if (empty($upuser)) {
            return;
        }

        $upuser = (array) $upuser;

        // 防循环：上级的上级不能是本人
        if ((int) ($upuser['pid'] ?? 0) === $mid) {
            return;
        }

        $uplv = Db::name('member_level')->where('aid', $aid)->where('id', (int) ($upuser['levelid'] ?? 0))->find();

        if (empty($uplv)) {
            return;
        }

        $uplv = (array) $uplv;

        if ((int) ($uplv['can_agent'] ?? 0) === 0 || (int) ($uplv['agent_rule'] ?? 0) <= 0) {
            return;
        }

        $rule = (int) $uplv['agent_rule'];

        if (3 === $rule) {
            // 首次消费后绑定：会员无任何已支付订单才绑
            $hasPayOrder = (int) Db::name('shop_order')
                ->where('aid', $aid)->where('mid', $mid)
                ->whereIn('status', [1, 2, 3])
                ->count();
            if ($hasPayOrder > 0) {
                return;
            }
        } elseif (!empty($member['pid']) && 2 !== $rule) {
            // agent_rule=1：已有 pid 不覆盖
            return;
        }

        $firstBind = empty($member['pid']) && empty($member['pid_origin']);

        // 新 path = 上级 path + 上级 id
        $upPath = trim((string) ($upuser['path'] ?? ''), ',');
        $newPath = '' === $upPath ? (string) $fromid : $upPath . ',' . $fromid;

        $update = [
            'pid'             => $fromid,
            'path'            => $newPath,
            'change_pid_time' => time(),
        ];

        // 首次绑定同步记录原始推荐关系
        if ($firstBind) {
            $update['pid_origin']  = $fromid;
            $update['path_origin'] = $newPath;
        }

        Db::name('member')->where('aid', $aid)->where('id', $mid)->update($update);

        // 换绑后修正所有直推下级的 path（原 path 中含 mid 的下游）
        $oldPathPrefix = trim((string) ($member['path'] ?? ''), ',');
        $oldNeedle = '' === $oldPathPrefix ? (string) $mid : $oldPathPrefix . ',' . $mid;
        $newNeedle = $newPath . ',' . $mid;

        if ($oldNeedle !== $newNeedle) {
            $downs = Db::name('member')
                ->where('aid', $aid)
                ->whereRaw('find_in_set(' . $mid . ', path)')
                ->field('id, path')
                ->select()
                ->toArray();

            foreach ($downs as $down) {
                $segs = explode(',', (string) $down['path']);
                $pos = array_search((string) $mid, $segs, true);
                if (false === $pos) {
                    continue;
                }
                // mid 之前的上级段替换为新链
                $tail = array_slice($segs, $pos); // 含 mid 及其之后
                Db::name('member')->where('id', (int) $down['id'])->update([
                    'path' => implode(',', array_merge(explode(',', $newPath), $tail)),
                ]);
            }
        }

        // 推荐积分（上级等级 score1，member_tjscore 去重 + scoremax 上限）
        $this->grantTjscore($aid, $mid, $fromid, $uplv);
    }

    /**
     * 送推荐积分（对标参考系统 Common::user_tjscore）
     *
     * @param array<string, mixed> $uplv 上级等级行
     */
    protected function grantTjscore(int $aid, int $mid, int $fromid, array $uplv): void
    {
        if ((int) ($uplv['score1'] ?? 0) <= 0 || (int) ($uplv['can_agent'] ?? 0) === 0) {
            return;
        }

        // 去重：同一推荐来源只送一次
        $exists = Db::name('member_tjscore')
            ->where('aid', $aid)->where('mid', $fromid)->where('frommid', $mid)
            ->find();

        if (!empty($exists)) {
            return;
        }

        $give = (int) $uplv['score1'];
        $scoremax = (int) ($uplv['scoremax'] ?? 0);

        if ($scoremax > 0) {
            $sum = (int) Db::name('member_tjscore')->where('aid', $aid)->where('mid', $fromid)->sum('score');
            if ($sum >= $scoremax) {
                return;
            }
            if ($scoremax - $sum < $give) {
                $give = $scoremax - $sum;
            }
        }

        if ($give <= 0) {
            return;
        }

        Db::name('member_tjscore')->insert([
            'aid'        => $aid,
            'mid'        => $fromid,
            'frommid'    => $mid,
            'score'      => $give,
            'createtime' => time(),
        ]);

        $this->scoreService->adjust($aid, $fromid, $give, '推荐奖励');
    }
}
