<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\exception\BusinessException;
use app\common\service\PayService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * C端 - 自定义表单（终端用户填写提交）
 *
 * 链路：租户后台设计表单 → 设计器「表单」组件调用（页面路径 pagesD/form/detail?id=）
 *      → 本控制器 formdetail（匿名）/ formsubmit（登录）→ 后台数据审核
 *
 * - formdetail：表单定义 + content 组件列表（匿名，aid 走 query）
 * - formsubmit：登录 + 时间/总量/每人次数/必填校验 + 写入 form_order；
 *   开启在线支付且金额>0 时创建统一支付单，返回 status=2 需支付
 * - formdata：我的提交记录
 * - formpay：发起支付（复用 PayService::payBiz，支付配置由租户平台配置自理）
 */
class Form extends Base
{
    public function __construct(\think\App $app, protected PayService $payService)
    {
        parent::__construct($app);
    }

    /**
     * 表单详情（匿名，白名单：form/detail；aid 走 query）
     * GET /api/form/detail?id=&aid=
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);
        $id  = (int) $this->request->get('id', 0);

        $form = Db::name('form')->where('aid', $aid)->where('id', $id)->find();
        if (empty($form)) {
            throw new BusinessException('表单不存在', 404, 404);
        }

        $content = json_decode((string) $form['content'], true);

        // 已登录时附带本人提交次数（供前端判断每人数量限制）
        $mycount = 0;
        try {
            $payload = (array) ($this->request->authPayload ?? []);
            $mid = (int) ($payload['mid'] ?? ($payload['uid'] ?? 0));
            if ($mid > 0) {
                $mycount = (int) Db::name('form_order')->where('aid', $aid)
                    ->where('formid', $id)->where('mid', $mid)->count();
            }
        } catch (\Throwable) {
            $mycount = 0;
        }

        return $this->success([
            'id'        => (int) $form['id'],
            'name'      => (string) $form['name'],
            'starttime' => (string) $form['starttime'],
            'endtime'   => (string) $form['endtime'],
            'payset'    => (int) $form['payset'],
            'price'     => (string) $form['price'],
            'priceedit' => (int) $form['priceedit'],
            'maxlimit'  => (int) $form['maxlimit'],
            'perlimit'  => (int) $form['perlimit'],
            'content'   => is_array($content) ? $content : [],
            'mycount'   => $mycount,
        ]);
    }

    /**
     * 提交表单（登录）
     * POST /api/form/submit {formid, formdata{form0..formN}, price?, fromurl?}
     */
    public function submit()
    {
        [$aid, $mid] = $this->memberContext();

        $formid = (int) $this->request->post('formid', 0);
        $form = Db::name('form')->where('aid', $aid)->where('id', $formid)->find();
        if (empty($form)) {
            throw new BusinessException('表单不存在', 404, 404);
        }

        // 有效时间校验
        $now = time();
        $start = strtotime((string) $form['starttime']);
        $end   = strtotime((string) $form['endtime']);
        if (false !== $start && $start > $now) {
            throw new BusinessException('活动未开始');
        }
        if (false !== $end && $end < $now) {
            throw new BusinessException('活动已结束');
        }

        // 总收集数量限制
        if ((int) $form['maxlimit'] > 0) {
            $count = (int) Db::name('form_order')->where('aid', $aid)->where('formid', $formid)->count();
            if ($count >= (int) $form['maxlimit']) {
                throw new BusinessException('提交人数已满');
            }
        }

        // 每人提交次数限制
        $mycs = (int) Db::name('form_order')->where('aid', $aid)->where('formid', $formid)->where('mid', $mid)->count();
        if ((int) $form['perlimit'] > 0 && $mycs >= (int) $form['perlimit']) {
            throw new BusinessException(1 === (int) $form['perlimit'] ? '您已经提交过了' : '每人最多可提交' . (int) $form['perlimit'] . '次');
        }

        // 逐字段写入 + 必填校验
        $formcontent = json_decode((string) $form['content'], true) ?: [];
        $formdata = (array) $this->request->post('formdata/a', []);

        $data = [
            'aid'        => $aid,
            'bid'        => (int) $form['bid'],
            'formid'     => (int) $form['id'],
            'title'      => (string) $form['name'],
            'mid'        => $mid,
            'createtime' => $now,
        ];

        foreach ($formcontent as $k => $v) {
            $value = $formdata['form' . $k] ?? '';
            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            }
            $data['form' . $k] = strval($value);
            if (1 === (int) ($v['val3'] ?? 0) && '' === $data['form' . $k]) {
                throw new BusinessException(($v['val1'] ?? '该项') . ' 必填');
            }
        }

        // 支付金额（priceedit=1 用户自填，否则默认金额）
        $price = 0.0;
        if (1 === (int) $form['payset']) {
            $price = 1 === (int) $form['priceedit']
                ? (float) $this->request->post('price', 0)
                : (float) $form['price'];
            if ($price < 0) {
                $price = 0.0;
            }
        }

        $ordernum = date('ymdHis') . $aid . random_int(1000, 9999);
        $data['money']    = $price;
        $data['ordernum'] = $ordernum;
        $data['fromurl']  = (string) $this->request->post('fromurl', '');
        $data['platform'] = (string) $this->request->post('platform', '');
        // 分销设置快照（确认发放佣金时按订单自身 commissionset 判定）
        $data['commissionset'] = (int) $form['commissionset'];

        $orderid = (int) Db::name('form_order')->insertGetId($data);
        if ($orderid <= 0) {
            throw new BusinessException('提交失败');
        }

        // 开启在线支付且金额>0：创建统一支付单，前端发起支付
        if ($price > 0) {
            return $this->success(['status' => 2, 'msg' => '需要支付', 'orderid' => $orderid, 'money' => $price]);
        }

        return $this->success(['status' => 1, 'msg' => '提交成功', 'orderid' => $orderid]);
    }

    /**
     * 发起表单支付（登录，复用统一支付链路；支付配置由租户平台配置自理）
     * POST /api/form/pay {order_id, pay_type}
     */
    public function pay()
    {
        [$aid, $mid] = $this->memberContext();

        $orderId = (int) $this->request->post('order_id', 0);
        $payType = trim((string) $this->request->post('pay_type', ''));

        if ($orderId <= 0) {
            throw new BusinessException('缺少订单ID');
        }
        if ('' === $payType) {
            throw new BusinessException('请选择支付方式');
        }

        return $this->success($this->payService->payBiz($aid, $mid, 'form', $orderId, $payType));
    }

    /**
     * 我的表单提交记录（登录）
     * GET /api/form/mydata?page=&pagesize=
     */
    public function mydata()
    {
        [$aid, $mid] = $this->memberContext();

        $page     = max(1, (int) $this->request->get('page', 1));
        $pagesize = min(50, max(1, (int) $this->request->get('pagesize', 10)));

        $query = Db::name('form_order')->where('aid', $aid)->where('mid', $mid);
        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $pagesize)
            ->field('id,formid,title,status,createtime,paystatus,money,ordernum,paytime,reason,isrefund')
            ->select()->toArray();

        return $this->success(['list' => $rows, 'total' => $total]);
    }
}
