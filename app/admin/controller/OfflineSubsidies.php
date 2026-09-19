<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 线下补助（P24-7 批次8 任务227，canzhao 无后台控制器，自研）
 *
 * 口径参照 canzhao/app/common/Member.php offlineSubsidiesLog 生成逻辑：
 * - type：1通证分红(score) 2推荐奖励 3讲师奖励 4爱心基金 5升级奖励（commission）
 * - scene：shop 商城订单 / maidan 买单订单
 * 配置表 offline_subsidies_set（aid 单行），流水表 member_offline_subsidies_log。
 */
class OfflineSubsidies extends BaseController
{
    /** 类型文案映射 */
    private const TYPE_TEXT = [
        1 => '通证分红',
        2 => '推荐奖励',
        3 => '讲师奖励',
        4 => '爱心基金',
        5 => '升级奖励',
    ];

    /** 场景文案映射 */
    private const SCENE_TEXT = [
        'shop'    => '商城订单',
        'maidan'  => '买单订单',
        'levelup' => '升级订单',
    ];

    /** 配置字段（数字型） */
    private const NUM_FIELDS = [
        'status', 'cycle', 'fwtype', 'base',
        'upgrade_num', 'upgrade_auto_uplevelid',
    ];

    /** 配置字段（比例型 decimal） */
    private const RATIO_FIELDS = [
        'effective_ratio',
        'upgrade_ratio', 'pass_ratio', 'recommend_ratio', 'lecturer_ratio', 'lovefund_ratio',
    ];

    /** 配置字段（文本型） */
    private const TEXT_FIELDS = [
        'productids', 'scene',
        'upgrade_levelid', 'upgrade_text',
        'pass_levelid', 'pass_text',
        'recommend_levelid', 'recommend_text',
        'lecturer_levelid', 'lecturer_text',
        'lovefund_levelid', 'lovefund_text',
    ];

    /* ================= 配置 ================= */

    /**
     * 获取配置（无记录返回默认值）
     * GET /admin/offlineSubsidies/set
     */
    public function setGet()
    {
        $aid = ScopeService::aidOf($this->request);

        $row = ScopeService::query('offline_subsidies_set', $aid)->find();

        return $this->success($this->setDefaults((array) ($row ?: [])));
    }

    /**
     * 保存配置（aid 单行 upsert）
     * POST /admin/offlineSubsidies/set
     */
    public function setSave()
    {
        $aid  = ScopeService::aidOf($this->request);
        $post = $this->request->post();

        $data = [];

        foreach (self::NUM_FIELDS as $field) {
            $data[$field] = (int) ($post[$field] ?? 0);
        }

        foreach (self::RATIO_FIELDS as $field) {
            $ratio = (float) ($post[$field] ?? 0);

            if ($ratio < 0 || $ratio > 100) {
                throw new BusinessException('比例必须在 0-100 之间');
            }

            $data[$field] = $ratio;
        }

        foreach (self::TEXT_FIELDS as $field) {
            $data[$field] = trim((string) ($post[$field] ?? ''));
        }

        $data['status'] = $data['status'] === 1 ? 1 : 0;

        // scene 仅允许合法场景枚举
        $scenes = array_filter(array_map('trim', explode(',', $data['scene'])));

        foreach ($scenes as $scene) {
            if (!isset(self::SCENE_TEXT[$scene])) {
                throw new BusinessException('奖励场景不合法：' . $scene);
            }
        }

        $data['scene'] = implode(',', array_unique($scenes));

        $data['updatetime'] = time();

        $exists = ScopeService::query('offline_subsidies_set', $aid)->find();

        if (empty($exists)) {
            $data['aid'] = $aid;
            Db::name('offline_subsidies_set')->insert($data);
        } else {
            Db::name('offline_subsidies_set')->where('id', (int) $exists['id'])->update($data);
        }

        return $this->success([], '保存成功');
    }

    /* ================= 数据统计列表 ================= */

    /**
     * 补助流水列表（含顶部统计）
     * GET /admin/offlineSubsidies/log/list?page=&pagesize=&type=&scene=&keyword=&start_time=&end_time=
     */
    public function logList()
    {
        $aid = ScopeService::aidOf($this->request);

        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        $query = ScopeService::queryAs('member_offline_subsidies_log', $aid, 'l');

        $type = (int) $this->request->get('type', 0);

        if ($type > 0) {
            $query->where('l.type', $type);
        }

        $scene = trim((string) $this->request->get('scene', ''));

        if ('' !== $scene) {
            $query->where('l.scene', $scene);
        }

        $keyword = trim((string) $this->request->get('keyword', ''));

        if ('' !== $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('l.ordernum', '%' . $keyword . '%')
                    ->whereOr('m.nickname', 'like', '%' . $keyword . '%');
            });
        }

        $startTime = (int) $this->request->get('start_time', 0);
        $endTime   = (int) $this->request->get('end_time', 0);

        if ($startTime > 0) {
            $query->where('l.createtime', '>=', $startTime);
        }

        if ($endTime > 0) {
            $query->where('l.createtime', '<', $endTime + 86400);
        }

        $query->leftJoin('member m', 'm.id = l.frommid AND m.aid = l.aid');

        $total = (clone $query)->count();

        $rows = $query->field('l.*, m.nickname as from_nickname')
            ->order('l.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $mids = array_values(array_filter(array_unique(array_map(fn (array $r) => (int) $r['mid'], $rows))));

        $nicknames = $mids
            ? ScopeService::query('member', $aid)->whereIn('id', $mids)->column('nickname', 'id')
            : [];

        foreach ($rows as &$row) {
            $row['type_text']      = self::TYPE_TEXT[(int) $row['type']] ?? '未知';
            $row['scene_text']     = self::SCENE_TEXT[(string) $row['scene']] ?? (string) $row['scene'];
            $row['nickname']       = $nicknames[(int) $row['mid']] ?? ('会员' . $row['mid']);
            $row['from_nickname']  = $row['from_nickname'] ?: ('会员' . $row['frommid']);
            $row['createtime_text'] = (int) $row['createtime'] > 0 ? date('Y-m-d H:i:s', (int) $row['createtime']) : '';
        }

        unset($row);

        return $this->success([
            'list'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pagesize' => $pageSize,
            'stat'     => $this->logStat($aid),
        ]);
    }

    /**
     * 删除流水记录
     * POST /admin/offlineSubsidies/log/delete {id}
     */
    public function logDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('请选择记录');
        }

        ScopeService::query('member_offline_subsidies_log', $aid)->where('id', $id)->delete();

        return $this->success([], '删除成功');
    }

    // ================= 内部工具 =================

    /**
     * 顶部统计：总记录/总佣金/总积分/今日新增
     *
     * @return array<string, mixed>
     */
    protected function logStat(int $aid): array
    {
        $todayStart = strtotime(date('Y-m-d'));

        $sumCommission = (float) ScopeService::query('member_offline_subsidies_log', $aid)->sum('commission');
        $sumScore      = (float) ScopeService::query('member_offline_subsidies_log', $aid)->sum('score');
        $todayNum      = ScopeService::query('member_offline_subsidies_log', $aid)
            ->where('createtime', '>=', $todayStart)
            ->count();

        return [
            'total'          => ScopeService::query('member_offline_subsidies_log', $aid)->count(),
            'sum_commission' => round($sumCommission, 2),
            'sum_score'      => round($sumScore, 2),
            'today_num'      => $todayNum,
        ];
    }

    /**
     * 配置默认值补全（口径对照 canzhao offlineSubsidiesLog 字段）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function setDefaults(array $row): array
    {
        $defaults = [
            'id'                       => 0,
            'status'                   => 0,
            'effective_ratio'          => '0.00',
            'cycle'                    => 0,
            'fwtype'                   => 0,
            'base'                     => 0,
            'productids'               => '',
            'scene'                    => '',
            'upgrade_levelid'          => '',
            'upgrade_ratio'            => '0.00',
            'upgrade_text'             => '升级奖励',
            'upgrade_num'              => 0,
            'upgrade_auto_uplevelid'   => 0,
            'pass_levelid'             => '',
            'pass_ratio'               => '0.00',
            'pass_text'                => '通证分红',
            'recommend_levelid'        => '',
            'recommend_ratio'          => '0.00',
            'recommend_text'           => '推荐奖励',
            'lecturer_levelid'         => '',
            'lecturer_ratio'           => '0.00',
            'lecturer_text'            => '讲师奖励',
            'lovefund_levelid'         => '',
            'lovefund_ratio'           => '0.00',
            'lovefund_text'            => '爱心基金',
            'updatetime'               => 0,
        ];

        return array_merge($defaults, array_intersect_key($row, $defaults));
    }
}
