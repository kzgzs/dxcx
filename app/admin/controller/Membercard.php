<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\ExcelHelper;
use app\common\service\MembercardService;
use app\common\service\PlogService;
use app\common\service\ScopeService;

/**
 * 租户后台 - 微信会员卡（批次7-6 任务214；对标参考系统 Membercard）
 *
 * 会员卡：列表/详情/大表单保存/同步微信(card/create)/同步审核状态(card/get)/删除/备注
 * 领取记录：列表/详情/删除/导出 Excel
 * 送积分：sendscore（membercard_sendscorelog 分页幂等）
 */
class Membercard extends BaseController
{
    public function __construct(\think\App $app, protected MembercardService $service)
    {
        parent::__construct($app);
    }

    /**
     * 会员卡列表
     * GET /admin/membercard/list?page&pagesize&keyword
     */
    public function list()
    {
        return $this->success($this->service->list(
            $this->aid(),
            max(1, (int) $this->request->param('page', 1)),
            max(1, min(100, (int) $this->request->param('pagesize', 20))),
            (string) $this->request->param('keyword', '')
        ));
    }

    /**
     * 会员卡详情（id=0 返回创建默认值）
     * GET /admin/membercard/detail?id
     */
    public function detail()
    {
        return $this->success($this->service->detail($this->aid(), (int) $this->request->param('id', 0)));
    }

    /**
     * 保存会员卡（本地表；info 为大表单全量字段）
     * POST /admin/membercard/save {info:{...}}
     */
    public function save()
    {
        $info = $this->request->post('info/a', []);
        $info = is_array($info) ? $info : [];

        $id   = $this->service->save($this->aid(), $info);
        $verb = (int) ($info['id'] ?? 0) > 0 ? '修改会员卡' : '创建会员卡';
        PlogService::write($this->aid(), $this->uid(), $verb . $id);

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 同步微信（card/create，mock 返回模拟 card_id）
     * POST /admin/membercard/sync {id}
     */
    public function sync()
    {
        $id = (int) $this->request->post('id', 0);
        $rs = $this->service->sync($this->aid(), $id);

        if (!empty($rs['already'])) {
            return $this->success($rs, '该会员卡已同步微信');
        }

        PlogService::write($this->aid(), $this->uid(), '同步会员卡到微信' . $id);

        return $this->success($rs, '同步成功，请等待微信审核');
    }

    /**
     * 同步审核状态（card/get → 0审核中 1通过 2失败 3被删）
     * POST /admin/membercard/syncstatus {id}
     */
    public function syncStatus()
    {
        $id = (int) $this->request->post('id', 0);
        $rs = $this->service->syncStatus($this->aid(), $id);

        return $this->success($rs, '当前状态：' . $rs['status_text']);
    }

    /**
     * 删除会员卡
     * POST /admin/membercard/del {ids:[...]}
     */
    public function del()
    {
        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? $ids : [];

        $this->service->del($this->aid(), $ids);
        PlogService::write($this->aid(), $this->uid(), '删除会员卡' . implode(',', array_map('intval', $ids)));

        return $this->success([], '删除成功');
    }

    /**
     * 设置备注
     * POST /admin/membercard/setremark {id, remark}
     */
    public function setRemark()
    {
        $id = (int) $this->request->post('id', 0);
        $this->service->setRemark($this->aid(), $id, (string) $this->request->post('remark', ''));

        return $this->success([], '操作成功');
    }

    /**
     * 领取记录列表
     * GET /admin/membercard/record?page&pagesize&card_id&card_code&name&mobile&starttime&endtime
     */
    public function record()
    {
        return $this->success($this->service->recordList(
            $this->aid(),
            max(1, (int) $this->request->param('page', 1)),
            max(1, min(100, (int) $this->request->param('pagesize', 20))),
            $this->recordFilters()
        ));
    }

    /**
     * 领取记录详情（开卡表单全量字段）
     * GET /admin/membercard/recorddetail?id
     */
    public function recordDetail()
    {
        return $this->success($this->service->recordDetail($this->aid(), (int) $this->request->param('id', 0)));
    }

    /**
     * 删除领取记录
     * POST /admin/membercard/recorddel {ids:[...]}
     */
    public function recordDel()
    {
        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? $ids : [];

        $this->service->recordDel($this->aid(), $ids);
        PlogService::write($this->aid(), $this->uid(), '删除会员卡领取记录' . implode(',', array_map('intval', $ids)));

        return $this->success([], '删除成功');
    }

    /**
     * 领取记录导出（xlsx 下载；列同参考系统 recordexcel）
     * GET /admin/membercard/recordexport
     */
    public function recordExport()
    {
        $rows = $this->service->recordExportRows($this->aid(), $this->recordFilters());
        PlogService::write($this->aid(), $this->uid(), '导出会员卡领取记录');

        return ExcelHelper::download(
            '会员卡领取记录_' . date('YmdHis') . '.xlsx',
            ['序号', '昵称', '领取时间', '卡号', '姓名', '手机号'],
            $rows
        );
    }

    /**
     * 送积分（分页幂等：首页不带 logid 创建批次，续页携带 logid）
     * POST /admin/membercard/sendscore {id, score, remark, logid, pagenum, pagelimit}
     */
    public function sendScore()
    {
        $rs = $this->service->sendScore(
            $this->aid(),
            (int) $this->request->post('id', 0),
            (int) $this->request->post('score', 0),
            (string) $this->request->post('remark', ''),
            (int) $this->request->post('logid', 0),
            (int) $this->request->post('pagenum', 1),
            (int) $this->request->post('pagelimit', 20)
        );

        if (1 === (int) $rs['status']) {
            PlogService::write($this->aid(), $this->uid(), '会员卡送积分批次' . $rs['logid'] . '完成');
        }

        return $this->success($rs, 1 === (int) $rs['status'] ? '送积分完成' : '本页完成，还有下一页');
    }

    /** 领取记录筛选参数 */
    protected function recordFilters(): array
    {
        return [
            'card_id'   => (string) $this->request->param('card_id', ''),
            'card_code' => (string) $this->request->param('card_code', ''),
            'name'      => (string) $this->request->param('name', ''),
            'mobile'    => (string) $this->request->param('mobile', ''),
            'starttime' => (string) $this->request->param('starttime', ''),
            'endtime'   => (string) $this->request->param('endtime', ''),
        ];
    }

    /** 当前租户 ID（JWT 注入，不可伪造） */
    protected function aid(): int
    {
        return ScopeService::aidOf($this->request);
    }

    /** 当前操作人 ID */
    protected function uid(): int
    {
        return (int) ($this->request->authUid ?? 0);
    }
}
