<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\ScopeService;
use app\common\service\YuyueService;
use think\facade\Db;

/**
 * 租户后台 - 预约管理（admin 应用，JWT role=admin，aid 走 ScopeService）
 *
 * 项目分类 / 预约项目 / 技师管理 / 预约订单（含核销）
 */
class Yuyue extends BaseController
{
    public function __construct(\think\App $app, protected YuyueService $yuyueService)
    {
        parent::__construct($app);
    }

    /* ================= 项目分类 ================= */

    /**
     * 分类列表
     * GET /admin/yuyue/category/list
     */
    public function categoryList()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->yuyueService->categoryList($aid));
    }

    /**
     * 新建分类
     * POST /admin/yuyue/category/add {name,pic?,sort?,status?}
     */
    public function categoryAdd()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->yuyueService->categoryAdd($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑分类
     * POST /admin/yuyue/category/edit {id,...}（空值视为不修改）
     */
    public function categoryEdit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->yuyueService->categoryEdit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 删除分类（有项目拒绝）
     * POST /admin/yuyue/category/delete {id}
     */
    public function categoryDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->yuyueService->categoryDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /* ================= 服务商品 YuyueProduct ================= */

    /**
     * 服务商品列表（name/status/bid/checked 筛选；orderField/orderType 白名单排序）
     * GET /admin/yuyue/product/list?page&pagesize&name&status&bid&checked&orderField&orderType
     */
    public function productList()
    {
        $aid = ScopeService::aidOf($this->request);

        $name       = trim((string) $this->request->get('name', ''));
        $status     = trim((string) $this->request->get('status', ''));
        $bid        = trim((string) $this->request->get('bid', ''));
        $checked    = trim((string) $this->request->get('checked', ''));
        $orderField = trim((string) $this->request->get('orderField', ''));
        $orderType  = trim((string) $this->request->get('orderType', ''));

        [$page, $pageSize] = $this->pageParams();

        return $this->success($this->yuyueService->productList($aid, $name, $status, $bid, $checked, $orderField, $orderType, $page, $pageSize));
    }

    /**
     * 服务商品详情（编辑表单回显）
     * GET /admin/yuyue/product/detail?id=
     */
    public function productDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->yuyueService->productDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 新建服务商品
     * POST /admin/yuyue/product/add {name,cid,pic,pics,sellpoint,sell_price,sales,sort,status,detail}
     */
    public function productAdd()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->yuyueService->productAdd($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑服务商品
     * POST /admin/yuyue/product/edit {id,...}（空值视为不修改）
     */
    public function productEdit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->yuyueService->productEdit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 删除服务商品（软删；有未完结订单拒绝；兼容单 id 与 ids[]）
     * POST /admin/yuyue/product/delete {id} | {ids[]}
     */
    public function productDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->yuyueService->productDelete($aid, $this->postIdsFlexible());

        return $this->success([], '删除成功');
    }

    /**
     * 上架/下架（兼容单 id 与 ids[]）
     * POST /admin/yuyue/product/status {id,status} | {ids[],status}
     */
    public function productStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $status = (int) $this->request->post('status', -1);

        $this->yuyueService->productStatus($aid, $this->postIdsFlexible(), $status);

        return $this->success([], 1 === $status ? '已上架' : '已下架');
    }

    /**
     * 服务商品审核（1通过 / 2驳回）
     * POST /admin/yuyue/product/check {id,ischecked,reason?}
     */
    public function productCheck()
    {
        $aid = ScopeService::aidOf($this->request);

        $id        = (int) $this->request->post('id', 0);
        $ischecked = (int) $this->request->post('ischecked', 0);
        $reason    = trim((string) $this->request->post('reason', ''));

        if ($id <= 0) {
            throw new BusinessException('缺少服务商品ID');
        }

        $this->yuyueService->productCheck($aid, $id, $ischecked, $reason);

        return $this->success([], 1 === $ischecked ? '已通过' : '已驳回');
    }

    /**
     * 复制服务商品
     * POST /admin/yuyue/product/copy {id}
     */
    public function procopy()
    {
        $aid = ScopeService::aidOf($this->request);

        $newId = $this->yuyueService->procopy($aid, (int) $this->request->post('id', 0));

        return $this->success(['id' => $newId], '复制成功');
    }


    /* ================= 技师管理 ================= */

    /**
     * 技师列表（keyword/status 筛选）
     * GET /admin/yuyue/worker/list?page&pagesize&keyword&status
     */
    public function workerList()
    {
        $aid = ScopeService::aidOf($this->request);

        $realname = trim((string) $this->request->get('realname', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->yuyueService->workerList($aid, $realname, $status, $page, $pageSize));
    }

    /**
     * 新建技师
     * POST /admin/yuyue/worker/add {name,pic,phone,category_id?,desc,status}
     */
    public function workerAdd()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->yuyueService->workerAdd($aid, $this->request->post()), '创建成功');
    }

    /**
     * 编辑技师
     * POST /admin/yuyue/worker/edit {id,...}（空值视为不修改）
     */
    public function workerEdit()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->yuyueService->workerEdit($aid, $this->request->post());

        return $this->success([], '保存成功');
    }

    /**
     * 删除技师（有未完结订单拒绝）
     * POST /admin/yuyue/worker/delete {id}
     */
    public function workerDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $this->yuyueService->workerDelete($aid, (int) $this->request->post('id', 0));

        return $this->success([], '删除成功');
    }

    /**
     * 启用/停用
     * POST /admin/yuyue/worker/status {id,status}
     */
    public function workerStatus()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', -1);

        $this->yuyueService->workerStatus($aid, $id, $status);

        return $this->success([], 1 === $status ? '已启用' : '已停用');
    }

    /**
     * 加余额（money 为负表示扣除）
     * POST /admin/yuyue/worker/addMoney {id,money,remark}
     */
    public function workerAddMoney()
    {
        $aid = ScopeService::aidOf($this->request);

        $id     = (int) $this->request->post('id', 0);
        $money  = (float) $this->request->post('money', 0);
        $remark = trim((string) $this->request->post('remark', ''));

        return $this->success($this->yuyueService->workerAddMoney($aid, $id, $money, $remark), '操作成功');
    }

    /**
     * 查看服务记录（按人员过滤）
     * GET /admin/yuyue/worker/orders?worker_id=&proname=&linkman=&tel=&ctime=&status=&page=&pagesize=
     */
    public function workerOrders()
    {
        $aid = ScopeService::aidOf($this->request);

        $workerId = (int) $this->request->get('worker_id', 0);
        if ($workerId <= 0) {
            return $this->success(['list' => [], 'total' => 0, 'page' => 1, 'pagesize' => 10]);
        }

        $proname  = trim((string) $this->request->get('proname', ''));
        $linkman  = trim((string) $this->request->get('linkman', ''));
        $tel      = trim((string) $this->request->get('tel', ''));
        $ctime    = trim((string) $this->request->get('ctime', ''));
        $status   = trim((string) $this->request->get('status', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->yuyueService->workerOrderList($aid, $workerId, $proname, $linkman, $tel, $ctime, $status, $page, $pageSize));
    }

    /**
     * 查进度（服务单时间线）
     * GET /admin/yuyue/worker/orderProgress?id=
     */
    public function workerOrderProgress()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->get('id', 0);

        return $this->success($this->yuyueService->workerOrderProgress($aid, $id));
    }
    /* ================= 预约订单 ================= */

    /**
     * 订单列表（keyword 匹配订单号/项目名/手机号；proname/linkman/tel/ctime 细分筛选）
     * GET /admin/yuyue/order/list?page&pagesize&status&keyword&proname&linkman&tel&ctime
     */
    public function orderList()
    {
        $aid = ScopeService::aidOf($this->request);

        $status  = trim((string) $this->request->get('status', ''));
        $keyword = trim((string) $this->request->get('keyword', ''));
        $proname = trim((string) $this->request->get('proname', ''));
        $linkman = trim((string) $this->request->get('linkman', ''));
        $tel     = trim((string) $this->request->get('tel', ''));
        $ctime   = trim((string) $this->request->get('ctime', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = (int) $this->request->get('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return $this->success($this->yuyueService->adminOrderList($aid, $status, $keyword, $proname, $linkman, $tel, $ctime, $page, $pageSize));
    }

    /**
     * 查看进度（时间线）
     * GET /admin/yuyue/order/progress?id=
     */
    public function orderProgress()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->yuyueService->adminOrderProgress($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 订单详情（查看详情弹窗）
     * GET /admin/yuyue/order/detail?id=
     */
    public function orderDetail()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->yuyueService->adminOrderDetail($aid, (int) $this->request->get('id', 0)));
    }

    /**
     * 服务完成
     * POST /admin/yuyue/order/finish {id}
     */
    public function orderFinish()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->yuyueService->adminOrderFinish($aid, $id);

        return $this->success([], '已确认完成');
    }

    /**
     * 删除订单（软删）
     * POST /admin/yuyue/order/delete {id}
     */
    public function orderDelete()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);
        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->yuyueService->adminOrderDelete($aid, $id);

        return $this->success([], '删除成功');
    }

    /**
     * 核销（已支付且核销码匹配 → 已完成）
     * POST /admin/yuyue/order/verify {id, hexiao_code}
     */
    public function orderVerify()
    {
        $aid = ScopeService::aidOf($this->request);

        $id = (int) $this->request->post('id', 0);

        if ($id <= 0) {
            throw new BusinessException('缺少订单ID');
        }

        $this->yuyueService->adminOrderVerify($aid, $id, (string) $this->request->post('hexiao_code', ''));

        return $this->success([], '核销成功');
    }

    /* ================= 商品服务 yuyue_fuwu（任务222，对照 canzhao YuyueFuwu） ================= */

    /**
     * 商品服务列表（name/status/bid 筛选）
     * GET /admin/yuyue/fuwu/list?page&pagesize&name&status&bid
     */
    public function fuwuList()
    {
        $aid = ScopeService::aidOf($this->request);

        $query = ScopeService::query('yuyue_fuwu', $aid);
        ScopeService::applyBid($query, trim((string) $this->request->get('bid', '')));

        $name = trim((string) $this->request->get('name', ''));
        if ('' !== $name) {
            $query->where('name', 'like', '%' . $name . '%');
        }
        $status = trim((string) $this->request->get('status', ''));
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        [$page, $pageSize] = $this->pageParams();
        $total = (clone $query)->count();
        $rows  = $query->order('sort', 'desc')->order('id', 'asc')
            ->page($page, $pageSize)->select()->toArray();

        return $this->success(['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize]);
    }

    /**
     * 商品服务详情
     * GET /admin/yuyue/fuwu/detail?id=
     */
    public function fuwuDetail()
    {
        $aid = ScopeService::aidOf($this->request);
        $row = ScopeService::query('yuyue_fuwu', $aid)->where('id', (int) $this->request->get('id', 0))->find();
        if (empty($row)) {
            throw new BusinessException('商品服务不存在', 404, 404);
        }

        return $this->success($row);
    }

    /**
     * 商品服务保存（id>0 编辑，否则新增）
     * POST /admin/yuyue/fuwu/save {id?,name,desc?,status?,sort?}
     */
    public function fuwuSave()
    {
        $aid  = ScopeService::aidOf($this->request);
        $id   = (int) $this->request->post('id', 0);
        $name = trim((string) $this->request->post('name', ''));
        if ('' === $name) {
            throw new BusinessException('请填写服务名称');
        }

        $data = [
            'name'   => $name,
            'desc'   => trim((string) $this->request->post('desc', '')),
            'status' => in_array((int) $this->request->post('status', 1), [0, 1], true) ? (int) $this->request->post('status', 1) : 1,
            'sort'   => (int) $this->request->post('sort', 1),
        ];

        if ($id > 0) {
            $exists = ScopeService::query('yuyue_fuwu', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('商品服务不存在', 404, 404);
            }
            Db::name('yuyue_fuwu')->where('id', $id)->update($data);
        } else {
            $id = (int) Db::name('yuyue_fuwu')->insertGetId($data + ['aid' => $aid, 'bid' => 0, 'createtime' => time()]);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 商品服务删除（批量）
     * POST /admin/yuyue/fuwu/delete {ids[]}
     */
    public function fuwuDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->postIds();

        ScopeService::query('yuyue_fuwu', $aid)->whereIn('id', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /* ================= 商品评价 yuyue_comment（任务222，对照 canzhao YuyueComment） ================= */

    /**
     * 商品评价列表（content/status/时间区间 筛选）
     * GET /admin/yuyue/comment/list?page&pagesize&content&status&start_date&end_date
     */
    public function commentList()
    {
        $aid = ScopeService::aidOf($this->request);

        $query = ScopeService::query('yuyue_comment', $aid);
        ScopeService::applyBid($query, trim((string) $this->request->get('bid', '')));

        $content = trim((string) $this->request->get('content', ''));
        if ('' !== $content) {
            $query->where('content', 'like', '%' . $content . '%');
        }
        $status = trim((string) $this->request->get('status', ''));
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }
        $this->applyDateRange($query, 'createtime');

        [$page, $pageSize] = $this->pageParams();
        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as $k => $v) {
            $rows[$k]['pics'] = '' !== (string) ($v['content_pic'] ?? '') ? explode(',', (string) $v['content_pic']) : [];
        }

        return $this->success(['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize]);
    }

    /**
     * 商品评价详情
     * GET /admin/yuyue/comment/detail?id=
     */
    public function commentDetail()
    {
        $aid = ScopeService::aidOf($this->request);
        $row = ScopeService::query('yuyue_comment', $aid)->where('id', (int) $this->request->get('id', 0))->find();
        if (empty($row)) {
            throw new BusinessException('评价不存在', 404, 404);
        }
        $row = (array) $row;

        $row['pics']             = '' !== (string) ($row['content_pic'] ?? '') ? explode(',', (string) $row['content_pic']) : [];
        $row['reply_pics']       = '' !== (string) ($row['reply_content_pic'] ?? '') ? explode(',', (string) $row['reply_content_pic']) : [];
        $row['append_pics']      = '' !== (string) ($row['append_content_pic'] ?? '') ? explode(',', (string) $row['append_content_pic']) : [];
        $row['append_reply_pics'] = '' !== (string) ($row['append_reply_content_pic'] ?? '') ? explode(',', (string) $row['append_reply_content_pic']) : [];

        return $this->success($row);
    }

    /**
     * 商品评价审核（批量 显示/隐藏，同步回写项目评分统计）
     * POST /admin/yuyue/comment/setst {ids[], st}
     */
    public function commentSetStatus()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->postIds();
        $st  = (int) $this->request->post('st', 0);
        if (!in_array($st, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        $rows = ScopeService::query('yuyue_comment', $aid)->whereIn('id', $ids)->field('id, proid')->select()->toArray();
        if (empty($rows)) {
            throw new BusinessException('评价不存在');
        }

        Db::name('yuyue_comment')->where('aid', $aid)->whereIn('id', array_column($rows, 'id'))->update(['status' => $st]);

        // 回写项目评分（对齐 canzhao YuyueComment::setst 口径）
        foreach (array_unique(array_map(static fn (array $v): int => (int) $v['proid'], $rows)) as $proid) {
            if ($proid <= 0) {
                continue;
            }
            $cq     = static fn (): \think\db\BaseQuery => ScopeService::query('yuyue_comment', $aid)->where('proid', $proid)->where('status', 1);
            $num    = $cq()->count();
            $avg    = $cq()->avg('score');
            $haonum = $cq()->where('score', '>', 3)->count();
            if (0 === $num) {
                $avg = 5;
            }
            Db::name('yuyue_product')->where('aid', $aid)->where('id', $proid)->update([
                'comment_score'      => round((float) $avg, 1),
                'comment_num'        => $num,
                'comment_haopercent' => $num > 0 ? (int) round($haonum / $num * 100) : 100,
            ]);
        }

        return $this->success([], '操作成功');
    }

    /**
     * 商品评价回复
     * POST /admin/yuyue/comment/reply {id, content}
     */
    public function commentReply()
    {
        $aid     = ScopeService::aidOf($this->request);
        $id      = (int) $this->request->post('id', 0);
        $content = trim((string) $this->request->post('content', ''));
        if ($id <= 0 || '' === $content) {
            throw new BusinessException('回复内容不能为空');
        }

        $exists = ScopeService::query('yuyue_comment', $aid)->where('id', $id)->find();
        if (empty($exists)) {
            throw new BusinessException('评价不存在', 404, 404);
        }

        // 回复即落库，并同步转为已审核（status=1，对齐任务要求「回复真实落库并转已审」）
        Db::name('yuyue_comment')->where('aid', $aid)->where('id', $id)->update([
            'reply_content' => $content,
            'reply_time'    => time(),
            'status'        => 1,
        ]);

        return $this->success([], '回复成功');
    }

    /**
     * 商品评价删除（批量）
     * POST /admin/yuyue/comment/delete {ids[]}
     */
    public function commentDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->postIds();

        ScopeService::query('yuyue_comment', $aid)->whereIn('id', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /* ================= 人员类型 yuyue_worker_category（任务222，对照 canzhao YuyueWorkerCategory） ================= */

    /**
     * 人员类型列表（全量含 pid，前端按层级渲染）
     * GET /admin/yuyue/workerCategory/list?name&status&bid
     */
    public function workerCategoryList()
    {
        $aid = ScopeService::aidOf($this->request);

        $query = ScopeService::query('yuyue_worker_category', $aid);
        ScopeService::applyBid($query, trim((string) $this->request->get('bid', '')));

        $name = trim((string) $this->request->get('name', ''));
        if ('' !== $name) {
            $query->where('name', 'like', '%' . $name . '%');
        }
        $status = trim((string) $this->request->get('status', ''));
        if ('' !== $status) {
            $query->where('status', (int) $status);
        }

        $rows = $query->order('sort', 'desc')->order('id', 'asc')->select()->toArray();

        return $this->success(['list' => $rows]);
    }

    /**
     * 人员类型保存（id>0 编辑，否则新增）
     * POST /admin/yuyue/workerCategory/save {id?,pid?,name,pic?,status?,sort?}
     */
    public function workerCategorySave()
    {
        $aid  = ScopeService::aidOf($this->request);
        $id   = (int) $this->request->post('id', 0);
        $name = trim((string) $this->request->post('name', ''));
        if ('' === $name) {
            throw new BusinessException('请填写类型名称');
        }
        $pid = (int) $this->request->post('pid', 0);
        if ($id > 0 && $pid === $id) {
            throw new BusinessException('上级类型不能选择自身');
        }

        $data = [
            'pid'    => $pid,
            'name'   => $name,
            'pic'    => trim((string) $this->request->post('pic', '')),
            'status' => in_array((int) $this->request->post('status', 1), [0, 1], true) ? (int) $this->request->post('status', 1) : 1,
            'sort'   => (int) $this->request->post('sort', 1),
        ];

        if ($id > 0) {
            $exists = ScopeService::query('yuyue_worker_category', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                throw new BusinessException('人员类型不存在', 404, 404);
            }
            Db::name('yuyue_worker_category')->where('id', $id)->update($data);
        } else {
            $id = (int) Db::name('yuyue_worker_category')->insertGetId($data + ['aid' => $aid, 'bid' => 0, 'createtime' => time()]);
        }

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 人员类型改状态（批量）
     * POST /admin/yuyue/workerCategory/setst {ids[], st}
     */
    public function workerCategorySetStatus()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->postIds();
        $st  = (int) $this->request->post('st', 0);
        if (!in_array($st, [0, 1], true)) {
            throw new BusinessException('状态值无效');
        }

        ScopeService::query('yuyue_worker_category', $aid)->whereIn('id', $ids)->update(['status' => $st]);

        return $this->success([], '操作成功');
    }

    /**
     * 人员类型删除（批量；类型下有人员拒绝）
     * POST /admin/yuyue/workerCategory/delete {ids[]}
     */
    public function workerCategoryDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->postIds();

        $workerCount = ScopeService::query('yuyue_worker', $aid)->whereIn('cid', $ids)->count();
        if ($workerCount > 0) {
            throw new BusinessException('该类型下存在服务人员，不能删除');
        }

        ScopeService::query('yuyue_worker_category', $aid)->whereIn('id', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /* ================= 人员评价 yuyue_worker_comment（任务222，对照 canzhao YuyueWorkerComment） ================= */

    /**
     * 人员评价列表（worker_id/content/时间区间 筛选，联表人员姓名手机）
     * GET /admin/yuyue/workerComment/list?page&pagesize&worker_id&content&start_date&end_date
     */
    public function workerCommentList()
    {
        $aid = ScopeService::aidOf($this->request);

        $query = ScopeService::queryAs('yuyue_worker_comment', $aid, 'c')
            ->leftJoin('yuyue_worker w', 'w.id = c.worker_id')
            ->field('c.*, w.realname AS worker_name, w.tel AS worker_tel');
        ScopeService::applyBid($query, trim((string) $this->request->get('bid', '')), 'c.bid');

        $workerId = (int) $this->request->get('worker_id', 0);
        if ($workerId > 0) {
            $query->where('c.worker_id', $workerId);
        }
        $content = trim((string) $this->request->get('content', ''));
        if ('' !== $content) {
            $query->where('c.content', 'like', '%' . $content . '%');
        }
        $this->applyDateRange($query, 'c.createtime');

        [$page, $pageSize] = $this->pageParams();
        $total = (clone $query)->count();
        $rows  = $query->order('c.id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as $k => $v) {
            $rows[$k]['pics'] = '' !== (string) ($v['content_pic'] ?? '') ? explode(',', (string) $v['content_pic']) : [];
        }

        return $this->success(['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize]);
    }

    /**
     * 人员评价详情
     * GET /admin/yuyue/workerComment/detail?id=
     */
    public function workerCommentDetail()
    {
        $aid = ScopeService::aidOf($this->request);
        $row = ScopeService::query('yuyue_worker_comment', $aid)->where('id', (int) $this->request->get('id', 0))->find();
        if (empty($row)) {
            throw new BusinessException('评价不存在', 404, 404);
        }
        $row = (array) $row;

        $row['pics']       = '' !== (string) ($row['content_pic'] ?? '') ? explode(',', (string) $row['content_pic']) : [];
        $row['reply_pics'] = '' !== (string) ($row['reply_content_pic'] ?? '') ? explode(',', (string) $row['reply_content_pic']) : [];
        $worker = Db::name('yuyue_worker')->where('aid', $aid)->where('id', (int) ($row['worker_id'] ?? 0))->field('realname, tel, headimg')->find();
        $row['worker'] = $worker ? (array) $worker : ['realname' => '', 'tel' => '', 'headimg' => ''];

        return $this->success($row);
    }

    /**
     * 人员评价删除（批量）
     * POST /admin/yuyue/workerComment/delete {ids[]}
     */
    public function workerCommentDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->postIds();

        ScopeService::query('yuyue_worker_comment', $aid)->whereIn('id', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /* ================= 提成明细 yuyue_worker_moneylog（任务222，对照 canzhao YuyueMoney::moneylog） ================= */

    /**
     * 提成明细列表（realname/uid 筛选，联表人员姓名手机）
     * GET /admin/yuyue/moneylog/list?page&pagesize&realname&uid
     */
    public function moneylogList()
    {
        $aid = ScopeService::aidOf($this->request);

        $query = ScopeService::queryAs('yuyue_worker_moneylog', $aid, 'm')
            ->leftJoin('yuyue_worker w', 'w.id = m.uid')
            ->field('m.*, w.realname, w.tel');
        ScopeService::applyBid($query, trim((string) $this->request->get('bid', '')), 'm.bid');

        $realname = trim((string) $this->request->get('realname', ''));
        if ('' !== $realname) {
            $query->where('w.realname', 'like', '%' . $realname . '%');
        }
        $uid = (int) $this->request->get('uid', 0);
        if ($uid > 0) {
            $query->where('m.uid', $uid);
        }

        [$page, $pageSize] = $this->pageParams();
        $total = (clone $query)->count();
        $rows  = $query->order('m.id', 'desc')->page($page, $pageSize)->select()->toArray();

        return $this->success(['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize]);
    }

    /**
     * 提成明细导出（对齐 canzhao moneylogexcel：返回 title+data 二维表）
     * GET /admin/yuyue/moneylog/export?realname&uid&page&pagesize
     */
    public function moneylogExport()
    {
        $aid = ScopeService::aidOf($this->request);

        $query = ScopeService::queryAs('yuyue_worker_moneylog', $aid, 'm')
            ->leftJoin('yuyue_worker w', 'w.id = m.uid')
            ->field('m.*, w.realname, w.tel');

        $realname = trim((string) $this->request->get('realname', ''));
        if ('' !== $realname) {
            $query->where('w.realname', 'like', '%' . $realname . '%');
        }
        $uid = (int) $this->request->get('uid', 0);
        if ($uid > 0) {
            $query->where('m.uid', $uid);
        }

        [$page, $pageSize] = $this->pageParams();
        $rows = $query->order('m.id', 'desc')->page($page, $pageSize)->select()->toArray();

        $data = array_map(static fn (array $v): array => [
            trim((string) ($v['realname'] ?? '') . ' ' . (string) ($v['tel'] ?? '')),
            (string) $v['money'],
            (string) $v['after'],
            date('Y-m-d H:i:s', (int) $v['createtime']),
            (string) ($v['remark'] ?? ''),
        ], $rows);

        return $this->success(['title' => ['服务人员', '变更金额', '变更后剩余', '变更时间', '备注'], 'data' => $data, 'count' => count($data)]);
    }

    /**
     * 提成明细删除（批量）
     * POST /admin/yuyue/moneylog/delete {ids[]}
     */
    public function moneylogDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->postIds();

        ScopeService::query('yuyue_worker_moneylog', $aid)->whereIn('id', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /* ================= 提现记录 yuyue_worker_withdrawlog（任务222，对照 canzhao YuyueMoney::withdrawlog） ================= */

    /**
     * 提现记录列表（realname/uid/status 筛选）
     * 状态机：0审核中 → 1已审核（待打款） → 3已打款；0 → 2已驳回（退回余额）
     * GET /admin/yuyue/withdrawlog/list?page&pagesize&realname&uid&status
     */
    public function withdrawlogList()
    {
        $aid = ScopeService::aidOf($this->request);

        $query = ScopeService::queryAs('yuyue_worker_withdrawlog', $aid, 't')
            ->leftJoin('yuyue_worker w', 'w.id = t.uid')
            ->field('t.*, w.realname, w.tel');
        ScopeService::applyBid($query, trim((string) $this->request->get('bid', '')), 't.bid');

        $realname = trim((string) $this->request->get('realname', ''));
        if ('' !== $realname) {
            $query->where('w.realname', 'like', '%' . $realname . '%');
        }
        $uid = (int) $this->request->get('uid', 0);
        if ($uid > 0) {
            $query->where('t.uid', $uid);
        }
        $status = trim((string) $this->request->get('status', ''));
        if ('' !== $status) {
            $query->where('t.status', (int) $status);
        }

        [$page, $pageSize] = $this->pageParams();
        $total = (clone $query)->count();
        $rows  = $query->order('t.id', 'desc')->page($page, $pageSize)->select()->toArray();

        $statusText = [0 => '审核中', 1 => '已审核', 2 => '已驳回', 3 => '已打款', 4 => '处理中'];
        foreach ($rows as $k => $v) {
            $rows[$k]['status_text'] = $statusText[(int) $v['status']] ?? '未知';
        }

        return $this->success(['list' => $rows, 'total' => $total, 'page' => $page, 'pagesize' => $pageSize]);
    }

    /**
     * 提现审核（1通过待打款 / 2驳回退回人员余额并记提成流水）
     * POST /admin/yuyue/withdrawlog/audit {id, pass(1|2), reason?}
     */
    public function withdrawlogAudit()
    {
        $aid    = ScopeService::aidOf($this->request);
        $id     = (int) $this->request->post('id', 0);
        $pass   = (int) $this->request->post('pass', 0);
        $reason = trim((string) $this->request->post('reason', ''));
        if (!in_array($pass, [1, 2], true)) {
            throw new BusinessException('审核结果无效');
        }

        $info = ScopeService::query('yuyue_worker_withdrawlog', $aid)->where('id', $id)->find();
        if (empty($info)) {
            throw new BusinessException('提现记录不存在', 404, 404);
        }
        if (0 !== (int) $info['status']) {
            throw new BusinessException('仅审核中的提现可审核');
        }
        if (2 === $pass && '' === $reason) {
            throw new BusinessException('驳回时请填写原因');
        }

        Db::transaction(function () use ($aid, $info, $pass, $reason): void {
            Db::name('yuyue_worker_withdrawlog')->where('aid', $aid)->where('id', (int) $info['id'])
                ->update(['status' => $pass, 'reason' => $reason]);

            if (2 === $pass) {
                // 驳回返还余额（对齐 canzhao YuyueWorker::addmoney 口径）
                $worker = Db::name('yuyue_worker')->where('aid', $aid)->where('id', (int) $info['uid'])->find();
                if (empty($worker)) {
                    throw new BusinessException('服务人员不存在');
                }
                $back  = (float) $info['txmoney'];
                $after = round((float) $worker['money'] + $back, 2);
                Db::name('yuyue_worker')->where('aid', $aid)->where('id', (int) $info['uid'])->update(['money' => $after]);
                Db::name('yuyue_worker_moneylog')->insert([
                    'aid'        => $aid,
                    'bid'        => (int) ($info['bid'] ?? 0),
                    'uid'        => (int) $info['uid'],
                    'money'      => $back,
                    'after'      => $after,
                    'createtime' => time(),
                    'remark'     => '提现驳回返还',
                ]);
            }
        });

        return $this->success([], 1 === $pass ? '已通过，待打款' : '已驳回，金额已退回人员余额');
    }

    /**
     * 提现打款（已审核 → 已打款；打款流水号可传，缺省自动生成）
     * POST /admin/yuyue/withdrawlog/pay {id, paynum?}
     */
    public function withdrawlogPay()
    {
        $aid    = ScopeService::aidOf($this->request);
        $id     = (int) $this->request->post('id', 0);
        $paynum = trim((string) $this->request->post('paynum', ''));

        $info = ScopeService::query('yuyue_worker_withdrawlog', $aid)->where('id', $id)->find();
        if (empty($info)) {
            throw new BusinessException('提现记录不存在', 404, 404);
        }
        if (1 !== (int) $info['status']) {
            throw new BusinessException('已审核状态才能打款');
        }

        Db::name('yuyue_worker_withdrawlog')->where('aid', $aid)->where('id', $id)->update([
            'status'  => 3,
            'paytime' => time(),
            'paynum'  => '' !== $paynum ? $paynum : 'YWTX' . $id . date('YmdHis'),
        ]);

        return $this->success([], '打款成功');
    }

    /**
     * 提现记录删除（批量）
     * POST /admin/yuyue/withdrawlog/delete {ids[]}
     */
    public function withdrawlogDelete()
    {
        $aid = ScopeService::aidOf($this->request);
        $ids = $this->postIds();

        ScopeService::query('yuyue_worker_withdrawlog', $aid)->whereIn('id', $ids)->delete();

        return $this->success([], '删除成功');
    }

    /* ================= 系统设置 yuyue_set（任务222，对照 canzhao YuyueSet） ================= */

    /** 系统设置可写字段白名单 */
    private const SET_FIELDS = [
        'status', 'paidantype', 'withdraw_weixin', 'withdraw_aliaccount', 'withdraw_bankcard',
        'withdrawmin', 'withdrawfee', 'businessst', 'businessfee',
        'make_status', 'make_domain', 'make_appid', 'make_token', 'make_shopkoufei', 'isapi',
        'diyname', 'autoshdays', 'autoclose', 'minminute', 'pic', 'desc',
        'datetype', 'zaohour', 'wanhour', 'timejg', 'timepoint', 'rqtype',
        'yybegintime', 'yyendtime', 'yyzhouqi', 'pdprehour', 'detailpic',
        'discount', 'iscoupon', 'formurl', 'apply_paymoney',
        'xieyi_show', 'xieyi', 'sign_juli', 'apply_url',
        'ad_status', 'ad_pic', 'ad_link', 'video_status', 'video_tag', 'video_title',
        'yuyue_numtext', 'yuyue_success', 'comment_check', 'isautopd', 'show_free',
        'finish_confirm', 'confirm_type', 'autoconfirm',
    ];

    /**
     * 系统设置读取（无记录自动初始化；formdata 缺省补默认表单，yyzhouqi 拆数组）
     * GET /admin/yuyue/set/config
     */
    public function setConfig()
    {
        $aid  = ScopeService::aidOf($this->request);
        $info = ScopeService::query('yuyue_set', $aid)->where('bid', 0)->find();
        if (empty($info)) {
            Db::name('yuyue_set')->insert(['aid' => $aid, 'bid' => 0]);
            $info = ScopeService::query('yuyue_set', $aid)->where('bid', 0)->find();
        }
        $info = (array) $info;

        if (empty($info['formdata'])) {
            $info['formdata'] = json_encode([
                ['key' => 'input', 'val1' => '备注', 'val2' => '选填，请输入备注信息', 'val3' => '0'],
            ], JSON_UNESCAPED_UNICODE);
        }
        $info['yyzhouqi'] = '' !== (string) ($info['yyzhouqi'] ?? '') ? explode(',', (string) $info['yyzhouqi']) : [];

        return $this->success($info);
    }

    /**
     * 系统设置保存（白名单字段；autoconfirm 不能小于 1 天；yyzhouqi 数组拼逗号串）
     * POST /admin/yuyue/set/save {字段...}
     */
    public function setSave()
    {
        $aid = ScopeService::aidOf($this->request);

        $post = $this->request->post();
        $update = [];
        foreach (self::SET_FIELDS as $field) {
            if (array_key_exists($field, $post)) {
                $update[$field] = $post[$field];
            }
        }
        if (array_key_exists('formdata', $post)) {
            $update['formdata'] = is_array($post['formdata'])
                ? json_encode($post['formdata'], JSON_UNESCAPED_UNICODE)
                : (string) $post['formdata'];
        }

        if (isset($update['autoconfirm']) && (int) $update['autoconfirm'] < 1) {
            throw new BusinessException('自动确认预约时间不能小于1天');
        }
        if (isset($update['yyzhouqi'])) {
            $update['yyzhouqi'] = is_array($update['yyzhouqi']) ? implode(',', $update['yyzhouqi']) : (string) $update['yyzhouqi'];
        }

        if (empty($update)) {
            throw new BusinessException('没有可保存的配置项');
        }

        $exists = ScopeService::query('yuyue_set', $aid)->where('bid', 0)->find();
        if (empty($exists)) {
            Db::name('yuyue_set')->insert($update + ['aid' => $aid, 'bid' => 0]);
        } else {
            Db::name('yuyue_set')->where('aid', $aid)->where('bid', 0)->update($update);
        }

        return $this->success([], '保存成功');
    }

    /* ================= 内部工具（任务222） ================= */

    /**
     * 分页参数（pagesize 上限 100）
     *
     * @return array{0: int, 1: int}
     */
    protected function pageParams(): array
    {
        $page     = max(1, (int) $this->request->param('page', 1));
        $pageSize = (int) $this->request->param('pagesize', 10);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;

        return [$page, $pageSize];
    }

    /**
     * 批量 ID 解析（post ids 数组，过滤非法值）
     *
     * @return int[]
     */
    protected function postIds(): array
    {
        $ids = $this->request->post('ids/a', []);
        $ids = array_map('intval', is_array($ids) ? $ids : []);
        $ids = array_values(array_unique(array_filter($ids, static fn (int $v): bool => $v > 0)));
        if (empty($ids)) {
            throw new BusinessException('缺少操作目标');
        }

        return $ids;
    }

    /**
     * 批量 ID 解析（兼容单 id 与 ids[]，过滤非法值；为空抛错）
     *
     * @return int[]
     */
    protected function postIdsFlexible(): array
    {
        $ids = $this->request->post('ids/a', []);
        if (empty($ids)) {
            $single = (int) $this->request->post('id', 0);
            if ($single > 0) {
                $ids = [$single];
            }
        }
        $ids = array_map('intval', is_array($ids) ? $ids : []);
        $ids = array_values(array_unique(array_filter($ids, static fn (int $v): bool => $v > 0)));
        if (empty($ids)) {
            throw new BusinessException('缺少操作目标');
        }

        return $ids;
    }

    /**
     * 时间区间过滤（start_date / end_date，Y-m-d 口径，含当天）
     */
    protected function applyDateRange(\think\db\BaseQuery $query, string $field): void
    {
        $start = trim((string) $this->request->get('start_date', ''));
        $end   = trim((string) $this->request->get('end_date', ''));
        if ('' !== $start && false !== strtotime($start)) {
            $query->where($field, '>=', strtotime($start));
        }
        if ('' !== $end && false !== strtotime($end)) {
            $query->where($field, '<', strtotime($end) + 86400);
        }
    }
}
