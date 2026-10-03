<?php
// +----------------------------------------------------------------------
// | 抽奖活动示例数据生成器：大转盘+刮刮卡各1个活动，抽奖记录覆盖未领取/已领取/未中奖
// +----------------------------------------------------------------------
namespace app\common\service;

use think\facade\Db;

class DemoLotteryGenerator
{
    private string $prefix = '【DEMO-LOTTERY】';
    private int $aid = 1;
    private int $now;

    public function __construct()
    {
        $this->now = time();
    }

    /** 仅删除本生成器数据（按活动名前缀），不动任何手工数据 */
    public function purge(): void
    {
        $ids = Db::name('choujiang')->where('name', 'like', $this->prefix . '%')->column('id');
        if (!empty($ids)) {
            Db::name('choujiang_record')->whereIn('hid', $ids)->delete();
        }
        Db::name('choujiang')->where('name', 'like', $this->prefix . '%')->delete();
    }

    /** 生成完整演示数据（幂等：先 purge） */
    public function generate(): void
    {
        $this->purge();

        // 大转盘：进行中（近7天开始，6天后结束）
        $dzpId = $this->makeActivity('dzp', $this->prefix . '幸运大转盘活动开始啦', $this->now - 7 * 86400, $this->now + 6 * 86400, '#F58D40');
        // 刮刮卡：已结束（7天前至1天前）
        $ggkId = $this->makeActivity('ggk', $this->prefix . '刮刮卡活动开始啦', $this->now - 7 * 86400, $this->now - 1 * 86400, '#C40004');

        $this->makeRecords($dzpId, '幸运大转盘活动开始啦');
        $this->makeRecords($ggkId, '刮刮卡活动开始啦');
    }

    /**
     * 建一个活动（奖项1红包5/5%、奖项2实物10/10%、奖项3积分30/30%、不中奖55/55%）
     */
    private function makeActivity(string $type, string $name, int $start, int $end, string $bgcolor): int
    {
        $row = [
            'aid'           => $this->aid,
            'type'          => $type,
            'name'          => $name,
            'toppic'        => '',
            'bgpic'         => '',
            'poppic'        => '',
            'popnote'       => '',
            'bgcolor'       => $bgcolor,
            'starttime'     => $start,
            'endtime'       => $end,
            'guize'         => "1.活动时间：" . date('Y年m月d日', $start) . "——" . date('Y年m月d日', $end) . "。\n2.活动期间，每人每天可参与抽奖3次。\n3.本次活动奖品为奖品一、奖品二、奖品三。",
            'pertotal'      => 3,
            'perday'        => 3,
            'shareaddnum'   => 0,
            'sharedaytimes' => 0,
            'sharetimes'    => 0,
            'joincond'      => 'all',
            'joinscope'     => 0,
            'use_type'      => 1,
            'usescore'      => 0,
            'scene_id'      => '',
            'formcontent'   => json_encode([
                ['type' => '单行输入', 'name' => '姓名', 'content' => '请输入提示信息', 'required' => true],
                ['type' => '单行输入', 'name' => '手机号', 'content' => '请输入提示信息', 'required' => true],
            ], JSON_UNESCAPED_UNICODE),
            'sharetitle'    => '',
            'sharelink'      => '',
            'sharepic'       => '',
            'sharedesc'      => '',
            'status'        => 1,
            'cjnum'         => 0,
            'zjnum'         => 0,
            'createtime'    => (string) ($start - 3600),
            'updatetime'    => (string) $this->now,
            // j0 不中奖
            'j0mc' => '谢谢参与', 'j0pic' => '', 'j0sl' => 55, 'j0yj' => 0,
            // j1 红包 3元红包
            'j1mc' => '3元红包', 'j1pic' => '', 'j1tp' => 2, 'j1sl' => 5, 'j1gl' => 5.00, 'j1yj' => 0,
            // j2 实物
            'j2mc' => '精美礼品', 'j2pic' => '', 'j2tp' => 1, 'j2sl' => 10, 'j2gl' => 10.00, 'j2yj' => 0,
            // j3 积分
            'j3mc' => '100积分', 'j3pic' => '', 'j3tp' => 3, 'j3sl' => 30, 'j3gl' => 30.00, 'j3yj' => 0,
        ];

        return (int) Db::name('choujiang')->insertGetId($row);
    }

    /**
     * 给活动造抽奖记录：未领取/已领取/未中奖各若干
     */
    private function makeRecords(int $hid, string $actName): void
    {
        // 会员池
        $members = [
            ['mid' => 7786, 'nickname' => 'W.', 'headimg' => ''],
            ['mid' => 7836, 'nickname' => 'Soso.', 'headimg' => ''],
        ];
        $ipRemark = 'IP地址非你在商户平台设置的可用IP地址';

        // (jx, jxtp, jxmc, status, remark)
        $plans = [
            [1, 2, '3元红包', 0, $ipRemark],   // 未领取 红包
            [1, 2, '1元红包', 0, $ipRemark],   // 未领取 红包
            [1, 2, '2元红包', 0, $ipRemark],   // 未领取 红包
            [1, 2, '0.1元红包', 1, ''],         // 已领取
            [0, 1, '谢谢参与', 0, ''],          // 未中奖
            [0, 1, '谢谢参与', 0, ''],          // 未中奖
            [0, 1, '谢谢参与', 0, ''],          // 未中奖
            [1, 2, '0.1元红包', 1, ''],         // 已领取
            [0, 1, '谢谢参与', 0, ''],          // 未中奖
        ];

        $zjnum = 0;
        $idx = 0;
        foreach ($plans as $plan) {
            [$jx, $jxtp, $jxmc, $status, $remark] = $plan;
            $m = $members[$idx % count($members)];
            $idx++;

            $record = [
                'aid'        => $this->aid,
                'hid'        => $hid,
                'name'       => $actName,
                'mid'        => $m['mid'],
                'headimg'    => $m['headimg'],
                'nickname'   => $m['nickname'],
                'jx'         => $jx,
                'jxtp'       => $jxtp,
                'jxmc'       => $jxmc,
                'createtime' => $this->now - $idx * 120,
                'createdate' => date('Y-m-d', $this->now - $idx * 120),
                'status'     => $status,
                'remark'     => $remark,
                'code'       => $status === 1 ? strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)) : '',
                'updatetime' => $status === 1 ? $this->now : 0,
            ];
            Db::name('choujiang_record')->insert($record);

            if ($jx > 0) {
                $zjnum++;
            }
            // 已出奖数累加（红包 j1）
            if ($jx === 1) {
                Db::name('choujiang')->where('id', $hid)->inc('j1yj')->update();
            }
        }

        // 活动计数：已抽奖=记录数，已中奖=中奖记录数
        Db::name('choujiang')->where('id', $hid)->update([
            'cjnum' => count($plans),
            'zjnum' => $zjnum,
        ]);
    }
}
