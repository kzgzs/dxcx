<?php
// +----------------------------------------------------------------------
// | 投票活动示例数据生成器：活动(进行中/已结束) + 分组 + 选手(三态) + 投票记录
// | 幂等：名称统一前缀 DEMO-TP-；purge 仅按前缀删本生成器数据，不碰手工数据。
// | 表：toupiao / toupiao_group / toupiao_join / toupiao_help
// +----------------------------------------------------------------------
namespace app\common\service;

use think\facade\Db;

class DemoToupiaoGenerator
{
    private string $prefix = 'DEMO-TP-';
    private int $aid = 1;
    private int $now;

    public function __construct()
    {
        $this->now = time();
    }

    public function setAid(int $aid): void
    {
        $this->aid = $aid;
    }

    /** 仅删除本生成器数据（按前缀 + 级联记录），不动手工数据 */
    public function purge(): void
    {
        $like = $this->prefix . '%';
        $actIds  = Db::name('toupiao')->where('aid', $this->aid)->where('name', 'like', $like)->column('id');
        $joinIds = Db::name('toupiao_join')->where('aid', $this->aid)->where('name', 'like', $like)->column('id');

        if ($actIds) {
            Db::name('toupiao_help')->where('aid', $this->aid)->where('hid', 'in', $actIds)->delete();
        }
        if ($joinIds) {
            Db::name('toupiao_help')->where('aid', $this->aid)->where('joinid', 'in', $joinIds)->delete();
        }
        Db::name('toupiao_join')->where('aid', $this->aid)->where('name', 'like', $like)->delete();
        Db::name('toupiao')->where('aid', $this->aid)->where('name', 'like', $like)->delete();
        Db::name('toupiao_group')->where('aid', $this->aid)->where('name', 'like', $like)->delete();
    }

    /** 生成完整演示数据（幂等：先 purge） */
    public function generate(): void
    {
        $this->purge();
        $groupIds  = $this->makeGroups();
        $activityIds = $this->makeActivities($groupIds);
        $joinIds = $this->makeJoins($activityIds);
        $this->makeHelps($activityIds, $joinIds);
    }

    /* ---------------- 分组 ---------------- */
    private function makeGroups(): array
    {
        $groups = [
            [$this->prefix . '分组一', 1],
            [$this->prefix . '分组二', 10],
        ];
        $ids = [];
        foreach ($groups as $i => [$name, $sort]) {
            $ids[] = Db::name('toupiao_group')->insertGetId([
                'aid'        => $this->aid,
                'name'       => $name,
                'sort'       => $sort,
                'status'     => 1,
                'createtime' => $this->now - 86400 * (30 - $i),
            ]);
        }
        return $ids;
    }

    /* ---------------- 活动 ---------------- */
    private function makeActivities(array $groupIds): array
    {
        $day = 86400;
        // [名称, 分组idx, 状态, 开始偏移天, 结束偏移天, banner, 每日可投, 累计可投]
        $acts = [
            [$this->prefix . '最美程序员', 1, 1, -30, 90, '/static/demo/banner-1.jpg', 1, 0],
            [$this->prefix . '闯关购',     0, 1, -20, -5, '/static/demo/banner-2.jpg', 1, 3],
        ];
        $ids = [];
        foreach ($acts as [$name, $gidx, $status, $startOff, $endOff, $banner, $perDay, $perAll]) {
            $ids[] = Db::name('toupiao')->insertGetId([
                'aid'        => $this->aid,
                'name'       => $name,
                'banner'     => $banner,
                'color1'     => '#FD4A46',
                'color2'     => '#1C83FF',
                'listtype'   => 0,
                'helptext'   => '投TA一票',
                'starttime'  => $this->now + $startOff * $day,
                'endtime'    => $this->now + $endOff * $day,
                'canapply'   => 1,
                'apply_check'=> 0,
                'help_check' => 0,
                'sort'       => 0,
                'guize'      => '<p>1. 每人每天可投一票；2. 禁止刷票。</p>',
                'sharetitle' => '',
                'sharepic'   => '',
                'sharedesc'  => '',
                'sharelink'  => '',
                'createtime' => $this->now - 40 * $day,
                'status'     => $status,
                'readcount'  => 144,
                'per_daycount' => $perDay,
                'per_allcount' => $perAll,
                'gettj'      => '-1',
                'jump_url'   => '',
                'group_id'   => $groupIds[$gidx] ?? 0,
            ]);
        }
        return $ids;
    }

    /* ---------------- 选手 ---------------- */
    private function makeJoins(array $activityIds): array
    {
        // [活动idx, 名称, 编号, 联系方式, 主图, 累计票数, 访问次数, 状态, 多少天前]
        $players = [
            [0, $this->prefix . '黑色', '001', '13800001111', '/static/demo/picture-1.jpg', 3823, 144, 1, 118],
            [0, $this->prefix . '丸子', '002', '13800002222', '/static/demo/picture-2.jpg', 1200, 80, 1, 116],
            [0, $this->prefix . '刘颖', '003', '13800003333', '/static/demo/picture-3.jpg', 688, 60, 1, 110],
            [0, $this->prefix . '待审核选手', '004', '13800004444', '/static/demo/picture-1.jpg', 0, 2, 0, 1],
            [0, $this->prefix . '已驳回选手', '005', '13800005555', '/static/demo/picture-2.jpg', 0, 1, 2, 2],
            [1, $this->prefix . '一号', '001', '13800006666', '/static/demo/picture-3.jpg', 7, 31, 1, 192],
        ];
        $joinIds = [];
        foreach ($players as [$aidx, $name, $number, $weixin, $pic, $helpnum, $readcount, $status, $daysAgo]) {
            $joinIds[] = Db::name('toupiao_join')->insertGetId([
                'aid'        => $this->aid,
                'mid'        => 0,
                'hid'        => $activityIds[$aidx],
                'name'       => $name,
                'weixin'     => $weixin,
                'pic'        => $pic,
                'pics'       => '',
                'detail_txt' => '',
                'detail'     => '',
                'helpnum'    => $helpnum,
                'readcount'  => $readcount,
                'createtime' => $this->now - 86400 * $daysAgo,
                'status'     => $status,
                'reason'     => $status === 2 ? '信息不完整，请补充选手介绍' : '',
                'number'     => $number,
                'sort'       => 0,
            ]);
        }
        return $joinIds;
    }

    /* ---------------- 投票记录 ---------------- */
    private function makeHelps(array $activityIds, array $joinIds): void
    {
        // [选手idx, 投票人昵称, 手机, 多少天前, 小时偏移]
        $votes = [
            [0, '188****5159', '18812345159', 0, 2],
            [0, '188****5159', '18812345159', 0, 3],
            [2, '178****1111', '17811111111', 80, 4],
            [2, '178****1111', '17811111111', 80, 5],
            [1, '哈哈哈',       '13900000001', 131, 6],
            [1, '188****5159', '18812345159', 102, 7],
            [5, '178****1111', '17811111111', 192, 8],
        ];
        foreach ($votes as [$jidx, $nickname, $tel, $daysAgo, $hourOff]) {
            Db::name('toupiao_help')->insert([
                'aid'        => $this->aid,
                'mid'        => 0,
                'headimg'    => '',
                'nickname'   => $nickname,
                'tel'        => $tel,
                'hid'        => $activityIds[(int) floor($jidx / 5)] ?? $activityIds[0],
                'joinid'     => $joinIds[$jidx],
                'createtime' => $this->now - 86400 * $daysAgo - 3600 * $hourOff,
            ]);
        }
    }
}
