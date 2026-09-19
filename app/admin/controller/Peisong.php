<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 同城配送整组（批次8 任务223；对标 canzhao Peisong/PeisongUser/PeisongOrder/PeisongComment/PeisongMoney）
 *
 * 覆盖：配送员(peisong_user) / 配送单(peisong_order) / 评价(peisong_order_comment) /
 * 提成明细(peisong_moneylog) / 提现记录(peisong_withdrawlog) / 系统设置(peisong_set) / 码科跑腿对接(makeset)
 *
 * 第三方 API（码科跑腿）一律 mock：.env [PEISONG] MOCK=1（缺省按 mock），
 * 只做配置页 + mock 回执（access_token / getprice），不做真实对接。
 * 本批不引入地图 SDK：配送员区域/位置为文本字段，距离由前端文本传参。
 * 商户过滤一律走 ScopeService::applyBid（前端 merchantViewOf 注入 bid='gt0'）。
 */
class Peisong extends BaseController
{
    /** 配送单状态：0待接单 1已接单 2已到店 3已取货 4已完成 10已取消 */
    private const ORDER_STATUS = [0 => '待接单', 1 => '已接单', 2 => '已到店', 3 => '已取货', 4 => '已完成', 10 => '已取消'];

    /** 提现状态：0审核中 1已审核 2已驳回 3已打款 */
    private const WITHDRAW_STATUS = [0 => '审核中', 1 => '已审核', 2 => '已驳回', 3 => '已打款'];

    // ==================== 配送员（peisong_user） ====================

    /**
     * 配送员列表（对标 PeisongUser::index ajax 分支）
     * GET /admin/peisong/user/list
     */
    public function userList()
    {
        $aid      = $this->aid();
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 20)));

        $query = ScopeService::query('peisong_user', $aid);
        $realname = trim((string) $this->request->param('realname', ''));
        if ('' !== $realname) {
            $query->where('realname', 'like', '%' . $realname . '%');
        }
        $status = $this->request->param('status', '');
        if ('' !== $status && null !== $status) {
            $query->where('status', (int) $status);
        }

        $total = (clone $query)->count();
        $list  = $query->order('sort', 'desc')->order('id', 'desc')
            ->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $member = Db::name('member')->where('id', $row['mid'])->field('nickname,headimg')->find();
            $row['nickname']  = $member['nickname'] ?? '';
            $row['headimg']   = $member['headimg'] ?? '';
            $row['pszCount']  = (int) ScopeService::query('peisong_order', $aid)
                ->where('psid', $row['id'])->whereIn('status', [0, 1, 2, 3])->count();
            $row['ywcCount']  = (int) ScopeService::query('peisong_order', $aid)
                ->where('psid', $row['id'])->where('status', 4)->count();
            $row['money']     = number_format((float) $row['money'], 2, '.', '');
            $row['totalmoney'] = number_format((float) $row['totalmoney'], 2, '.', '');
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 配送员新增/编辑（对标 PeisongUser::save；realname 同租户唯一）
     * POST /admin/peisong/user/save
     */
    public function userSave()
    {
        $aid = $this->aid();
        $id  = (int) $this->request->post('id', 0);

        $realname = trim((string) $this->request->post('realname', ''));
        if ('' === $realname) {
            throw new BusinessException('请填写姓名');
        }

        $dup = ScopeService::query('peisong_user', $aid)
            ->where('id', '<>', $id)->where('realname', $realname)->find();
        if ($dup) {
            throw new BusinessException('该姓名已存在，请填写其它姓名');
        }

        // 本批不引入地图 SDK：经纬度/区域为文本字段直接落库
        $data = [
            'realname'      => $realname,
            'tel'           => trim((string) $this->request->post('tel', '')),
            'status'        => (int) $this->request->post('status', 1) === 1 ? 1 : 0,
            'sort'          => (int) $this->request->post('sort', 0),
            'weixin'        => trim((string) $this->request->post('weixin', '')),
            'aliaccount'    => trim((string) $this->request->post('aliaccount', '')),
            'bankname'      => trim((string) $this->request->post('bankname', '')),
            'bankcarduser'  => trim((string) $this->request->post('bankcarduser', '')),
            'bankcardnum'   => trim((string) $this->request->post('bankcardnum', '')),
            'longitude'     => trim((string) $this->request->post('longitude', '')),
            'latitude'      => trim((string) $this->request->post('latitude', '')),
        ];

        if ($id > 0) {
            $exists = ScopeService::query('peisong_user', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('配送员不存在');
            }
            ScopeService::query('peisong_user', $aid)->where('id', $id)->update($data);
            PlogService::write($aid, $this->uid(), '编辑配送员' . $id);
        } else {
            $data['aid']        = $aid;
            $data['mid']        = (int) $this->request->post('mid', 0);
            $data['createtime'] = time();
            $id = (int) ScopeService::query('peisong_user', $aid)->insertGetId($data);
            PlogService::write($aid, $this->uid(), '添加配送员' . $id);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 配送员改状态（对标 PeisongUser::setst）
     * POST /admin/peisong/user/setst {ids[], st}
     */
    public function userSetst()
    {
        $aid  = $this->aid();
        $ids  = $this->intIds($this->request->post('ids', []));
        $st   = (int) $this->request->post('st', 0) === 1 ? 1 : 0;
        if (empty($ids)) {
            throw new BusinessException('缺少ID');
        }

        ScopeService::query('peisong_user', $aid)->whereIn('id', $ids)->update(['status' => $st]);
        PlogService::write($aid, $this->uid(), '配送员改状态' . implode(',', $ids));

        return $this->success([], '操作成功');
    }

    /**
     * 配送员删除（对标 PeisongUser::del）
     * POST /admin/peisong/user/del {ids[]}
     */
    public function userDel()
    {
        $aid = $this->aid();
        $ids = $this->intIds($this->request->post('ids', []));
        if (empty($ids)) {
            throw new BusinessException('缺少ID');
        }

        ScopeService::query('peisong_user', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $this->uid(), '配送员删除' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    /**
     * 启用中配送员下拉（对标 PeisongUser::getpeisonguser）
     * GET /admin/peisong/user/options
     */
    public function userOptions()
    {
        $aid  = $this->aid();
        $rows = ScopeService::query('peisong_user', $aid)
            ->where('status', 1)->order('sort', 'desc')->order('id', 'desc')
            ->field('id,realname,tel')->select()->toArray();

        foreach ($rows as &$row) {
            $row['dan'] = (int) ScopeService::query('peisong_order', $aid)
                ->where('psid', $row['id'])->whereIn('status', [0, 1])->count();
        }
        unset($row);

        return $this->success(['list' => $rows]);
    }

    // ==================== 配送单（peisong_order） ====================

    /**
     * 配送单列表（对标 PeisongOrder::index ajax 分支；不输出 HTML，状态/类型给文本）
     * GET /admin/peisong/order/list
     */
    public function orderList()
    {
        $aid      = $this->aid();
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 20)));

        $query = ScopeService::queryAs('peisong_order', $aid, 'p')
            ->leftJoin('member m', 'm.id=p.mid');

        ScopeService::applyBid($query, (string) $this->request->param('bid', ''), 'p.bid');

        $ordernum = trim((string) $this->request->param('ordernum', ''));
        if ('' !== $ordernum) {
            $query->where('p.ordernum', $ordernum);
        }
        $psid = $this->request->param('psid', '');
        if ('' !== $psid && null !== $psid) {
            $query->where('p.psid', (int) $psid);
        }
        $status = $this->request->param('status', '');
        if ('' !== $status && null !== $status) {
            $query->where('p.status', (int) $status);
        }
        // pstype：1系统配送(psid>=0) 2码科配送(psid=-1) 3已拒绝(psid=-2)
        $pstype = $this->request->param('pstype', '');
        if ('' !== $pstype && null !== $pstype) {
            if (1 === (int) $pstype) {
                $query->where('p.psid', '>=', 0);
            } elseif (2 === (int) $pstype) {
                $query->where('p.psid', -1);
            } elseif (3 === (int) $pstype) {
                $query->where('p.psid', -2);
            }
        }
        $nickname = trim((string) $this->request->param('nickname', ''));
        if ('' !== $nickname) {
            $query->where('m.nickname|m.tel', 'like', '%' . $nickname . '%');
        }

        $total = (int) (clone $query)->count();
        $list  = $query->field('p.*,m.nickname,m.headimg')
            ->order('p.id', 'desc')->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $row['member'] = ['id' => (int) $row['mid'], 'nickname' => (string) ($row['nickname'] ?? ''), 'headimg' => (string) ($row['headimg'] ?? '')];
            if ((int) $row['psid'] === -2) {
                $row['psuser'] = ['realname' => '', 'tel' => ''];
            } elseif ((int) $row['psid'] === -1) {
                $row['psuser'] = ['realname' => (string) ($row['make_rider_name'] ?? ''), 'tel' => (string) ($row['make_rider_mobile'] ?? '')];
            } else {
                $psuser = Db::name('peisong_user')->where('id', $row['psid'])->field('realname,tel')->find();
                $row['psuser'] = $psuser ?: ['realname' => '', 'tel' => ''];
            }
            $row['orderinfo'] = $this->decodeJson($row['orderinfo'] ?? '');
            $row['binfo']     = $this->decodeJson($row['binfo'] ?? '');
            $row['prolist']   = $this->decodeJson($row['prolist'] ?? '');
            if ('paotui_order' === $row['type'] && !empty($row['binfo']['name'])) {
                $row['binfo']['name'] = '（跑腿）' . $row['binfo']['name'];
            }
            $row['status_name'] = self::ORDER_STATUS[(int) $row['status']] ?? (string) $row['status'];
            $row['pstype']      = (int) $row['psid'] >= 0 ? '系统配送' : (-1 === (int) $row['psid'] ? '码科配送' : '');
            $row['ticheng']     = number_format((float) $row['ticheng'], 2, '.', '');
            $row['psfee']       = number_format((float) $row['psfee'], 2, '.', '');
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 配送单改状态（对标 PeisongOrder::setst；st=10 为取消）
     * POST /admin/peisong/order/setst {ids[], st}
     */
    public function orderSetst()
    {
        $aid = $this->aid();
        $ids = $this->intIds($this->request->post('ids', []));
        $st  = (int) $this->request->post('st', 0);
        if (empty($ids)) {
            throw new BusinessException('缺少ID');
        }
        if (!isset(self::ORDER_STATUS[$st])) {
            throw new BusinessException('状态不符合');
        }

        $query = ScopeService::query('peisong_order', $aid)->whereIn('id', $ids);
        ScopeService::applyBid($query, (string) $this->request->post('bid', ''));
        $query->update(['status' => $st]);

        PlogService::write($aid, $this->uid(), '配送单修改状态' . implode(',', $ids));

        return $this->success([], '操作成功');
    }

    /**
     * 配送单删除（对标 PeisongOrder::del）
     * POST /admin/peisong/order/del {ids[]}
     */
    public function orderDel()
    {
        $aid = $this->aid();
        $ids = $this->intIds($this->request->post('ids', []));
        if (empty($ids)) {
            throw new BusinessException('缺少ID');
        }

        $query = ScopeService::query('peisong_order', $aid)->whereIn('id', $ids);
        ScopeService::applyBid($query, (string) $this->request->post('bid', ''));
        $query->delete();

        PlogService::write($aid, $this->uid(), '删除配送记录' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    // ==================== 配送评价（peisong_order_comment） ====================

    /**
     * 评价列表（对标 PeisongComment::index ajax 分支）
     * GET /admin/peisong/comment/list
     */
    public function commentList()
    {
        $aid      = $this->aid();
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 20)));

        $query = ScopeService::query('peisong_order_comment', $aid);

        ScopeService::applyBid($query, (string) $this->request->param('bid', ''));

        $psid = $this->request->param('psid', '');
        if ('' !== $psid && null !== $psid && (int) $psid > 0) {
            $query->where('psid', (int) $psid);
        }
        $content = trim((string) $this->request->param('content', ''));
        if ('' !== $content) {
            $query->where('content', 'like', '%' . $content . '%');
        }
        $start = (string) $this->request->param('start_date', '');
        $end   = (string) $this->request->param('end_date', '');
        if ('' !== $start) {
            $query->where('createtime', '>=', strtotime($start));
        }
        if ('' !== $end) {
            $query->where('createtime', '<', strtotime($end) + 86400);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $psuser = Db::name('peisong_user')->where('id', $row['psid'])->field('realname,tel')->find();
            $row['psuser'] = $psuser ?: ['realname' => '', 'tel' => ''];
            if ((int) $row['bid'] > 0) {
                $business = Db::name('business')->where('id', $row['bid'])->field('name,tel')->find();
            } else {
                $business = Db::name('admin_set')->where('aid', $aid)->field('name,tel')->find();
            }
            $row['business'] = $business ?: ['name' => '', 'tel' => ''];
            $row['content_pic'] = '' !== trim((string) $row['content_pic']) ? explode(',', (string) $row['content_pic']) : [];
            $row['score'] = (int) ($row['score'] ?? 0);
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 评价详情（对标 PeisongComment::getdetail）
     * POST /admin/peisong/comment/detail {id}
     */
    public function commentDetail()
    {
        $aid = $this->aid();
        $id  = (int) $this->request->post('id', 0);

        $detail = ScopeService::query('peisong_order_comment', $aid)->where('id', $id)->find();
        if (empty($detail)) {
            throw new BusinessException('评价不存在');
        }

        $detail['content_pic'] = '' !== trim((string) $detail['content_pic']) ? explode(',', (string) $detail['content_pic']) : [];
        $member = Db::name('member')->where('aid', $aid)->where('id', $detail['mid'])->find();
        if (!$member) {
            $member = ['nickname' => $detail['nickname'], 'headimg' => $detail['headimg']];
        }

        return $this->success(['detail' => $detail, 'member' => $member]);
    }

    /**
     * 评价删除（对标 PeisongComment::del）
     * POST /admin/peisong/comment/del {ids[]}
     */
    public function commentDel()
    {
        $aid = $this->aid();
        $ids = $this->intIds($this->request->post('ids', []));
        if (empty($ids)) {
            throw new BusinessException('缺少ID');
        }

        ScopeService::query('peisong_order_comment', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $this->uid(), '删除配送员评价' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    // ==================== 提成明细（peisong_moneylog） ====================

    /**
     * 提成/余额明细列表（对标 PeisongMoney::moneylog ajax 分支）
     * GET /admin/peisong/moneylog/list
     */
    public function moneylogList()
    {
        $aid      = $this->aid();
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 20)));

        $query = ScopeService::queryAs('peisong_moneylog', $aid, 'l')
            ->leftJoin('peisong_user u', 'u.id=l.uid');

        $realname = trim((string) $this->request->param('realname', ''));
        if ('' !== $realname) {
            $query->where('u.realname', 'like', '%' . $realname . '%');
        }
        $uid = $this->request->param('uid', '');
        if ('' !== $uid && null !== $uid) {
            $query->where('l.uid', (int) $uid);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->field('u.realname,u.tel,l.*')
            ->order('l.id', 'desc')->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $row['money'] = number_format((float) $row['money'], 2, '.', '');
            $row['after'] = number_format((float) $row['after'], 2, '.', '');
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 提成明细删除（对标 PeisongMoney::moneylogdel）
     * POST /admin/peisong/moneylog/del {ids[]}
     */
    public function moneylogDel()
    {
        $aid = $this->aid();
        $ids = $this->intIds($this->request->post('ids', []));
        if (empty($ids)) {
            throw new BusinessException('缺少ID');
        }

        ScopeService::query('peisong_moneylog', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $this->uid(), '删除余额明细' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    // ==================== 提现记录（peisong_withdrawlog） ====================

    /**
     * 提现记录列表（对标 PeisongMoney::withdrawlog ajax 分支）
     * GET /admin/peisong/withdrawlog/list
     */
    public function withdrawlogList()
    {
        $aid      = $this->aid();
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 20)));

        $query = ScopeService::queryAs('peisong_withdrawlog', $aid, 'w')
            ->leftJoin('peisong_user u', 'u.id=w.uid');

        $realname = trim((string) $this->request->param('realname', ''));
        if ('' !== $realname) {
            $query->where('u.realname', 'like', '%' . $realname . '%');
        }
        $uid = $this->request->param('uid', '');
        if ('' !== $uid && null !== $uid) {
            $query->where('w.uid', (int) $uid);
        }
        $status = $this->request->param('status', '');
        if ('' !== $status && null !== $status) {
            $query->where('w.status', (int) $status);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->field('u.realname,u.tel,w.*')
            ->order('w.id', 'desc')->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $row['money']       = number_format((float) $row['money'], 2, '.', '');
            $row['txmoney']     = number_format((float) $row['txmoney'], 2, '.', '');
            $row['status_name'] = self::WITHDRAW_STATUS[(int) $row['status']] ?? (string) $row['status'];
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 提现改状态（对标 PeisongMoney::withdrawlogsetst，微信打款走 mock）
     * POST /admin/peisong/withdrawlog/setst {id, st, reason}
     * st=1 审核通过 / st=2 驳回（返还余额）/ st=3 已打款 / st=10 打款（mock 即时成功）
     */
    public function withdrawlogSetst()
    {
        $aid    = $this->aid();
        $id     = (int) $this->request->post('id', 0);
        $st     = (int) $this->request->post('st', 0);
        $reason = trim((string) $this->request->post('reason', ''));

        $info = ScopeService::query('peisong_withdrawlog', $aid)->where('id', $id)->find();
        if (empty($info)) {
            throw new BusinessException('提现记录不存在');
        }

        if (10 === $st) {
            // 打款：已审核状态才能打款；mock 模式直接打款成功
            if (1 !== (int) $info['status']) {
                throw new BusinessException('已审核状态才能打款');
            }
            if (!$this->isMock()) {
                throw new BusinessException('真实转账通道未开启，请用 mock 模式（.env PEISONG.MOCK=1）');
            }
            ScopeService::query('peisong_withdrawlog', $aid)->where('id', $id)->update([
                'status'    => 3,
                'paytime'   => time(),
                'paynum'    => 'MOCK' . date('YmdHis') . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
                'wx_state'  => 'SUCCESS',
                'reason'    => $reason,
            ]);
            PlogService::write($aid, $this->uid(), '配送员余额提现mock打款' . $id);

            return $this->success(['mock' => true, 'status' => 3], '打款成功（mock）');
        }

        if (!in_array($st, [1, 2, 3], true)) {
            throw new BusinessException('状态不符合');
        }

        ScopeService::query('peisong_withdrawlog', $aid)->where('id', $id)->update([
            'status' => $st,
            'reason' => $reason,
        ]);

        if (2 === $st) {
            // 驳回返还余额（对标 \app\common\PeisongUser::addmoney）
            $this->addMoney($aid, (int) $info['uid'], (float) $info['txmoney'], '余额提现返还', false);
            PlogService::write($aid, $this->uid(), '配送员余额提现驳回' . $id);
        } elseif (3 === $st) {
            ScopeService::query('peisong_withdrawlog', $aid)->where('id', $id)->update(['paytime' => time()]);
            PlogService::write($aid, $this->uid(), '配送员余额提现改为已打款' . $id);
        } else {
            PlogService::write($aid, $this->uid(), '配送员余额提现审核通过' . $id);
        }

        return $this->success([], '操作成功');
    }

    /**
     * 提现记录删除（对标 PeisongMoney::withdrawlogdel）
     * POST /admin/peisong/withdrawlog/del {ids[]}
     */
    public function withdrawlogDel()
    {
        $aid = $this->aid();
        $ids = $this->intIds($this->request->post('ids', []));
        if (empty($ids)) {
            throw new BusinessException('缺少ID');
        }

        ScopeService::query('peisong_withdrawlog', $aid)->whereIn('id', $ids)->delete();
        PlogService::write($aid, $this->uid(), '配送员余额提现记录删除' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    // ==================== 系统设置（peisong_set） ====================

    /**
     * 系统设置读取（对标 Peisong::set；无记录自动初始化）
     * GET /admin/peisong/set
     */
    public function set()
    {
        $aid  = $this->aid();
        $info = $this->ensureSet($aid);
        unset($info['make_access_token'], $info['make_expire_time']);

        return $this->success(['info' => $info]);
    }

    /**
     * 系统设置保存（对标 Peisong::save；字段白名单）
     * POST /admin/peisong/set
     */
    public function setSave()
    {
        $aid = $this->aid();
        $this->ensureSet($aid);

        $data = [
            'status'             => $this->flag('status'),
            'paidantype'         => in_array((int) $this->request->post('paidantype', 0), [0, 1, 2], true)
                ? (int) $this->request->post('paidantype', 0) : 0,
            'express_paidan'     => $this->flag('express_paidan'),
            'yuji_sendminute'    => max(0, (int) $this->request->post('yuji_sendminute', 10)),
            'yuji_psjuli1'       => max(0, (int) $this->request->post('yuji_psjuli1', 5)),
            'yuji_psminute1'     => max(0, (int) $this->request->post('yuji_psminute1', 20)),
            'yuji_psjuli2'       => max(0, (int) $this->request->post('yuji_psjuli2', 1)),
            'yuji_psminute2'     => max(0, (int) $this->request->post('yuji_psminute2', 2)),
            'jiesuantype'        => (int) $this->request->post('jiesuantype', 0) === 1 ? 1 : 0,
            'tcmoney'            => $this->money($this->request->post('tcmoney', 0)),
            'peisong_juli1'      => (string) max(0, (float) $this->request->post('peisong_juli1', 5)),
            'peisong_tcmoney1'   => $this->money($this->request->post('peisong_tcmoney1', 0)),
            'peisong_juli2'      => (string) max(0, (float) $this->request->post('peisong_juli2', 1)),
            'peisong_tcmoney2'   => $this->money($this->request->post('peisong_tcmoney2', 0)),
            'peisong_tcmoneymax' => $this->money($this->request->post('peisong_tcmoneymax', 0)),
            'withdraw_weixin'    => $this->flag('withdraw_weixin'),
            'withdraw_aliaccount' => $this->flag('withdraw_aliaccount'),
            'withdraw_bankcard'  => $this->flag('withdraw_bankcard'),
            'withdrawmin'        => (string) max(0, (float) $this->request->post('withdrawmin', 10)),
            'withdrawfee'        => (string) max(0, (float) $this->request->post('withdrawfee', 0)),
            'businessst'         => $this->flag('businessst'),
            'businessfee'        => $this->money($this->request->post('businessfee', 0)),
        ];

        ScopeService::query('peisong_set', $aid)->where('aid', $aid)->update($data);
        PlogService::write($aid, $this->uid(), '同城配送系统设置');

        return $this->success([], '保存成功');
    }

    // ==================== 码科跑腿对接（peisong_set.make_*，mock） ====================

    /**
     * 码科设置读取（对标 Peisong::makeset）
     * GET /admin/peisong/makeset
     */
    public function makeset()
    {
        $aid  = $this->aid();
        $info = $this->ensureSet($aid);

        return $this->success(['info' => [
            'make_status'       => (int) ($info['make_status'] ?? 0),
            'make_domain'       => (string) ($info['make_domain'] ?? ''),
            'make_appid'        => (string) ($info['make_appid'] ?? ''),
            'make_token'        => (string) ($info['make_token'] ?? ''),
            'make_shopkoufei'   => (int) ($info['make_shopkoufei'] ?? 0),
            'make_access_token' => (string) ($info['make_access_token'] ?? ''),
            'make_expire_time'  => (int) ($info['make_expire_time'] ?? 0),
            'express_wx_status' => (int) ($info['express_wx_status'] ?? 0),
            'mock'              => $this->isMock(),
        ]]);
    }

    /**
     * 码科设置保存（对标 Peisong::save2 字段口径；开启时 mock 获取 access_token）
     * POST /admin/peisong/makeset
     */
    public function makesetSave()
    {
        $aid = $this->aid();
        $this->ensureSet($aid);

        $makeStatus = (int) $this->request->post('make_status', 0) === 1 ? 1 : 0;

        if (1 === $makeStatus) {
            $set = ScopeService::query('peisong_set', $aid)->find();
            if (1 === (int) ($set['express_wx_status'] ?? 0)) {
                throw new BusinessException('请先关闭即时配送');
            }
        }

        $data = [
            'make_status'       => $makeStatus,
            'make_domain'       => trim((string) $this->request->post('make_domain', '')),
            'make_appid'        => trim((string) $this->request->post('make_appid', '')),
            'make_token'        => trim((string) $this->request->post('make_token', '')),
            'make_shopkoufei'   => (int) $this->request->post('make_shopkoufei', 0) === 1 ? 1 : 0,
            // 口径照抄 canzhao：保存即清空旧 token，开启时重新获取
            'make_access_token' => '',
            'make_expire_time'  => 0,
        ];

        if (1 === $makeStatus) {
            if ('' === $data['make_domain'] || '' === $data['make_appid'] || '' === $data['make_token']) {
                throw new BusinessException('开启前请填写域名/AppID/Token');
            }
            $token = $this->makeAccessToken($aid, $data);
            $data['make_access_token'] = $token['token'];
            $data['make_expire_time']  = $token['expire_time'];
        }

        ScopeService::query('peisong_set', $aid)->where('aid', $aid)->update($data);
        PlogService::write($aid, $this->uid(), '码科跑腿设置');

        return $this->success([
            'mock'              => $this->isMock(),
            'make_access_token' => $data['make_access_token'],
            'make_expire_time'  => $data['make_expire_time'],
        ], '保存成功');
    }

    /**
     * 获取配送员+预估费用（对标 Peisong::getpeisonguser；无地图 SDK，距离文本传参）
     * GET /admin/peisong/getpeisonguser?showtype=&juli=（公里）
     */
    public function getpeisonguser()
    {
        $aid      = $this->aid();
        $showtype = (int) $this->request->param('showtype', 0);
        $juli     = max(0, (float) $this->request->param('juli', 0)); // 公里，文本传参

        $set = $this->ensureSet($aid);

        // 提成计算（口径照抄 canzhao \app\model\PeisongOrder::ticheng）
        if (0 === (int) $set['jiesuantype']) {
            $ticheng = (float) $set['tcmoney'];
        } else {
            $ticheng = (float) $set['peisong_tcmoney1'];
            $juli1   = (float) $set['peisong_juli1'];
            $juli2   = (float) $set['peisong_juli2'];
            if ($juli - $juli1 > 0 && $juli2 > 0) {
                $ticheng += ceil(($juli - $juli1) / $juli2) * (float) $set['peisong_tcmoney2'];
            }
            if ((float) $set['peisong_tcmoneymax'] > 0 && $ticheng > (float) $set['peisong_tcmoneymax']) {
                $ticheng = (float) $set['peisong_tcmoneymax'];
            }
        }

        $selectArr = [];
        if (1 === (int) $set['make_status']) {
            // 码科配送：mock getprice（真实为 /apis/v2/get_delivery_price），paidantype 强制 2
            $price     = $this->makeGetprice($aid, $ticheng);
            $ticheng   = $price;
            $paidantype = 2;
        } elseif (0 === (int) $set['paidantype'] && 1 !== $showtype) {
            // 抢单模式
            $selectArr[] = ['id' => 0, 'title' => '--配送员抢单--'];
            $paidantype  = 0;
        } else {
            $paidantype  = (int) $set['paidantype'];
            $peisonguser = ScopeService::query('peisong_user', $aid)
                ->where('status', 1)->order('sort', 'desc')->order('id', 'desc')
                ->select()->toArray();
            foreach ($peisonguser as $v) {
                $dan = (int) ScopeService::query('peisong_order', $aid)
                    ->where('psid', $v['id'])->whereIn('status', [0, 1])->count();
                $selectArr[] = ['id' => (int) $v['id'], 'title' => $v['realname'] . '-' . $v['tel'] . '(配送中' . $dan . '单)'];
            }
        }

        // 商家配送费 = 提成 *（1 + 服务费比例）
        $psfee = $ticheng * (1 + (float) $set['businessfee'] * 0.01);

        return $this->success([
            'peisonguser' => $selectArr,
            'paidantype'  => $paidantype,
            'psfee'       => number_format($psfee, 2, '.', ''),
            'ticheng'     => number_format($ticheng, 2, '.', ''),
            'mock'        => $this->isMock(),
        ]);
    }

    // ==================== 内部工具 ====================

    protected function aid(): int
    {
        return ScopeService::aidOf($this->request);
    }

    protected function uid(): int
    {
        return (int) ($this->request->authUid ?? 0);
    }

    /**
     * 码科跑腿 mock 开关（.env [PEISONG] MOCK，缺省按 mock）
     */
    protected function isMock(): bool
    {
        $raw   = (string) env('PEISONG.MOCK', '1');
        $value = trim(strtok($raw, '#'));

        return '' === $value || '1' === $value;
    }

    /**
     * 码科获取 access_token（真实为 /apis/v2/get_token；mock 返回固定格式回执）
     * @return array{token:string, expire_time:int}
     */
    private function makeAccessToken(int $aid, array $set): array
    {
        if ($this->isMock()) {
            return [
                'token'       => 'mockmk_' . substr(md5($aid . '|' . $set['make_appid'] . '|' . time()), 0, 24),
                'expire_time' => time() + 86000 * 3,
            ];
        }

        // 真实对接不在本批范围
        throw new BusinessException('真实码科对接未开启，请用 mock 模式（.env PEISONG.MOCK=1）');
    }

    /**
     * 码科获取配送价格（真实为 /apis/v2/get_delivery_price；mock 按本地提成口径返回）
     */
    private function makeGetprice(int $aid, float $fallback): float
    {
        if ($this->isMock()) {
            return $fallback;
        }

        throw new BusinessException('真实码科对接未开启，请用 mock 模式（.env PEISONG.MOCK=1）');
    }

    /**
     * peisong_set 行不存在则初始化（对标各控制器 defaultSet）
     */
    private function ensureSet(int $aid): array
    {
        $info = ScopeService::query('peisong_set', $aid)->find();
        if (empty($info)) {
            Db::name('peisong_set')->insert(['aid' => $aid]);
            $info = ScopeService::query('peisong_set', $aid)->find();
        }

        return $info;
    }

    /**
     * 配送员加余额+流水（口径照抄 canzhao \app\common\PeisongUser::addmoney）
     */
    private function addMoney(int $aid, int $uid, float $money, string $remark, bool $addTotal = true): void
    {
        if (0.0 === $money) {
            return;
        }
        $user = ScopeService::query('peisong_user', $aid)->where('id', $uid)->find();
        if (empty($user)) {
            return;
        }

        $totalmoney = ($money > 0 && $addTotal)
            ? (float) $user['totalmoney'] + $money
            : (float) $user['totalmoney'];
        $after = (float) $user['money'] + $money;

        ScopeService::query('peisong_user', $aid)->where('id', $uid)->update([
            'totalmoney' => number_format($totalmoney, 2, '.', ''),
            'money'      => number_format($after, 2, '.', ''),
        ]);

        Db::name('peisong_moneylog')->insert([
            'aid'        => $aid,
            'uid'        => $uid,
            'money'      => number_format($money, 2, '.', ''),
            'after'      => number_format($after, 2, '.', ''),
            'createtime' => time(),
            'remark'     => $remark,
        ]);
    }

    /**
     * ids 参数归一为正整数数组（兼容数组/逗号串）
     * @return int[]
     */
    private function intIds(mixed $ids): array
    {
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        if (!is_array($ids)) {
            return [];
        }

        $result = [];
        foreach ($ids as $v) {
            $v = (int) $v;
            if ($v > 0) {
                $result[] = $v;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * JSON 字段反序列化容错
     */
    private function decodeJson(mixed $raw): array
    {
        $decoded = is_string($raw) && '' !== trim($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 0/1 开关字段归一
     */
    private function flag(string $field): int
    {
        return (int) $this->request->post($field, 0) === 1 ? 1 : 0;
    }

    /**
     * 金额归一：两位小数字符串
     */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
