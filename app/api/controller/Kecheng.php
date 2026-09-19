<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\KechengExamService;
use app\common\service\KechengService;
use app\common\service\PayService;
use app\common\service\ScopeService;

/**
 * C 端 - 知识付费·课程与交易（批次5-D1 任务197）+ 题库与考试（批次5-D2 任务198）
 *
 * 匿名（白名单见 AuthMiddleware）：list/detail/category3/downclist3/classify
 * 登录（role=member）：mulu/mldetail/createOrder/orderlist/pay/addstudy/nextsection/givescore/getposter
 * 考试（登录）：getTiku/prevquestion/nextquestion/tofinish/complete/recordlog/error/geterrotiku
 * 讲师（登录，kecheng_sysset.lecturer_open 开关）：lecturerapply/lecturercenter/lecturerEditkecheng/lecturerlist
 */
class Kecheng extends Base
{
    public function __construct(\think\App $app, protected KechengService $kechengService, protected PayService $payService, protected KechengExamService $examService)
    {
        parent::__construct($app);
    }

    /**
     * 课程列表（匿名）
     * GET /api/kecheng/list?cid&keyword&pagenum
     */
    public function getlist()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->kechengService->apiList(
            $aid,
            null,
            (int) $this->request->param('cid', 0),
            trim((string) $this->request->param('keyword', '')),
            max(1, (int) $this->request->param('pagenum', 1))
        ));
    }

    /**
     * 目录列表（登录）
     * GET /api/kecheng/mulu?id(kcid)&keyword&pagenum
     */
    public function getmululist()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->kechengService->apiMuluList(
            $aid,
            $mid,
            (int) $this->request->param('id', 0),
            trim((string) $this->request->param('keyword', '')),
            max(1, (int) $this->request->param('pagenum', 1))
        ));
    }

    /**
     * 一级分类（匿名）
     * GET /api/kecheng/category3
     */
    public function category3()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->kechengService->apiCategory3($aid));
    }

    /**
     * 二三级分类（匿名）
     * GET /api/kecheng/downclist3?id(pid)
     */
    public function getdownclist3()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->kechengService->apiDownclist3($aid, (int) $this->request->param('id', 0)));
    }

    /**
     * 分类下课程（匿名）
     * GET /api/kecheng/classify?id&last_id&last_classify_id
     */
    public function getClassifyKecheng()
    {
        $aid = ScopeService::aidOf($this->request);

        return $this->success($this->kechengService->apiClassifyKecheng(
            $aid,
            null,
            (int) $this->request->param('id', 0),
            (int) $this->request->param('last_id', 0),
            (int) $this->request->param('last_classify_id', 0)
        ));
    }

    /**
     * 课程详情（匿名可看；登录附会员价/已购/预计佣金）
     * GET /api/kecheng/detail?id
     */
    public function detail()
    {
        $aid = ScopeService::aidOf($this->request);

        // 匿名白名单进入；若携带有效 token 则解析会员（尽力而为，不强制）
        $member = null;
        $payload = (array) ($this->request->authPayload ?? []);
        $mid     = (int) ($payload['mid'] ?? ($payload['uid'] ?? 0));
        if ($mid > 0) {
            [$aid, $mid] = $this->memberContext();
            $member = \think\facade\Db::name('member')->where('aid', $aid)->where('id', $mid)->find() ?: null;
        }

        return $this->success($this->kechengService->apiDetail($aid, $member, (int) $this->request->param('id', 0)));
    }

    /**
     * 章节学习详情（登录；免费课自动成单/顺序学习/考试解锁）
     * GET/POST /api/kecheng/mldetail?kcid&id(章节id 可选)
     */
    public function mldetail()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->kechengService->apiMlDetail(
            $aid,
            $mid,
            (int) $this->request->param('kcid', 0),
            (int) $this->request->param('id', 0)
        ));
    }

    /**
     * 课程海报（登录；消费 PosterService type=kecheng 模板）
     * GET /api/kecheng/getposter?proid&platform
     */
    public function getposter()
    {
        [$aid, $mid] = $this->memberContext();

        $platform = (string) $this->request->param('platform', 'wx');
        $platform = in_array($platform, ['wx', 'mp'], true) ? $platform : 'wx';

        return $this->success($this->kechengService->apiGetPoster(
            $aid,
            $mid,
            (int) $this->request->param('proid', 0),
            $platform
        ));
    }

    /**
     * 创建课程订单（登录；gettj 权限 + 会员价 + 三级分销冻结 + 统一支付单）
     * POST /api/kecheng/order/create {kcid}
     */
    public function createOrder()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->kechengService->apiCreateOrder(
            $aid,
            $mid,
            (int) $this->request->post('kcid', 0)
        ), '提交成功');
    }

    /**
     * 订单支付（登录；mock 模式内部直接入账 → onPaid 置 status=1 + join_num++）
     * POST /api/kecheng/order/pay {orderid,paytype}
     */
    public function orderPay()
    {
        [$aid, $mid] = $this->memberContext();

        $orderid = (int) $this->request->post('orderid', 0);
        $paytype = (string) $this->request->post('paytype', 'wechat');

        return $this->success($this->payService->payBiz($aid, $mid, 'kecheng', $orderid, $paytype));
    }

    /**
     * 我的课程订单（登录）
     * GET /api/kecheng/order/list?keyword&pagenum
     */
    public function orderlist()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->kechengService->apiOrderList(
            $aid,
            $mid,
            trim((string) $this->request->param('keyword', '')),
            max(1, (int) $this->request->param('pagenum', 1))
        ));
    }

    /**
     * 学习进度上报（登录；音视频 currentTime/duration 计算，播满置完成）
     * POST /api/kecheng/study/add {logid,currentTime,playJd?}
     */
    public function addstudy()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->kechengService->apiAddStudy(
            $aid,
            $mid,
            (int) $this->request->post('logid', 0),
            (float) $this->request->post('currentTime', 0),
            trim((string) $this->request->post('playJd', ''))
        ));
    }

    /**
     * 下一章（登录）
     * GET /api/kecheng/study/nextsection?id&kcid
     */
    public function nextsection()
    {
        [$aid] = $this->memberContext();

        return $this->success($this->kechengService->apiNextSection(
            $aid,
            (int) $this->request->param('id', 0),
            (int) $this->request->param('kcid', 0)
        ));
    }

    /**
     * 学习送积分（登录；kecheng_give_scorelog 去重）
     * POST /api/kecheng/givescore {kccid}
     */
    public function givescore()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->kechengService->apiGiveScore(
            $aid,
            $mid,
            (int) $this->request->post('kccid', 0)
        ));
    }

    /* ==================== 考试段（批次5-D2 任务198） ==================== */

    /**
     * 抽题开考（登录；已合格阻断/未答完续答/随机抽 dtnum 题固化；限时惰性超时）
     * POST /api/kecheng/exam/getTiku {kcid, mlid?}
     */
    public function getTiku()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->examService->apiGetTiku(
            $aid,
            $mid,
            (int) $this->request->param('kcid', 0),
            (int) $this->request->param('mlid', 0)
        ));
    }

    /**
     * 上一题（登录；回看已作答）
     * POST /api/kecheng/exam/prevquestion {dtid}
     */
    public function prevquestion()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->examService->apiPrevQuestion(
            $aid,
            $mid,
            (int) $this->request->post('dtid', 0)
        ));
    }

    /**
     * 答题判分+下一题（登录；多选数组/单选填空字符串）
     * POST /api/kecheng/exam/nextquestion {dtid, right_option}
     */
    public function nextquestion()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->examService->apiNextQuestion(
            $aid,
            $mid,
            (int) $this->request->post('dtid', 0),
            $this->request->post('right_option', '')
        ));
    }

    /**
     * 最后一题判分并交卷（登录；ishg 按 hgscore 判定）
     * POST /api/kecheng/exam/tofinish {dtid, right_option}
     */
    public function tofinish()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->examService->apiToFinish(
            $aid,
            $mid,
            (int) $this->request->post('dtid', 0),
            $this->request->post('right_option', '')
        ));
    }

    /**
     * 交卷结果页（登录）
     * GET /api/kecheng/exam/complete?rid
     */
    public function complete()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->examService->apiComplete(
            $aid,
            $mid,
            (int) $this->request->param('rid', 0)
        ));
    }

    /**
     * 答题记录（登录）
     * GET /api/kecheng/exam/recordlog?kcid&mlid&pagenum
     */
    public function recordlog()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->examService->apiRecordLog(
            $aid,
            $mid,
            (int) $this->request->param('kcid', 0),
            (int) $this->request->param('mlid', 0),
            max(1, (int) $this->request->param('pagenum', 1))
        ));
    }

    /**
     * 错题解析回看（登录；op=up/down 翻题）
     * GET /api/kecheng/exam/error?rid&op&logid
     */
    public function examError()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->examService->apiError(
            $aid,
            $mid,
            (int) $this->request->param('rid', 0),
            (string) $this->request->param('op', ''),
            (int) $this->request->param('logid', 0)
        ));
    }

    /**
     * 错题下一题（登录；同 error op=down 口径）
     * GET /api/kecheng/exam/geterrotiku?rid&op&logid
     */
    public function geterrotiku()
    {
        [$aid, $mid] = $this->memberContext();

        $op = (string) $this->request->param('op', 'down');
        $op = in_array($op, ['up', 'down'], true) ? $op : 'down';

        return $this->success($this->examService->apiError(
            $aid,
            $mid,
            (int) $this->request->param('rid', 0),
            $op,
            (int) $this->request->param('logid', 0)
        ));
    }

    /* ==================== 讲师段（批次5-D2 任务198，lecturer_open 开关） ==================== */

    /**
     * 讲师申请状态回显（登录）
     * GET /api/kecheng/lecturer/applyInfo
     */
    public function lecturerapplyinfo()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->examService->apiLecturerApplyInfo($aid, $mid));
    }

    /**
     * 讲师申请/修改信息（登录；opttype 0申请 1修改）
     * POST /api/kecheng/lecturer/apply {info:{pic,nickname,realname,tel,shortdesc}, opttype}
     */
    public function lecturerapply()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->examService->apiLecturerApply(
            $aid,
            $mid,
            (array) $this->request->post('info', []),
            (int) $this->request->post('opttype', 0)
        ));
    }

    /**
     * 讲师中心（登录；本人讲师信息 + 名下课程）
     * GET /api/kecheng/lecturer/center?st&pagenum
     */
    public function lecturercenter()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->examService->apiLecturerCenter(
            $aid,
            $mid,
            (int) $this->request->param('st', 0),
            max(1, (int) $this->request->param('pagenum', 1))
        ));
    }

    /**
     * 讲师课程保存（登录；讲师自建/编辑课程）
     * POST /api/kecheng/lecturer/editkecheng {info:{...}}
     */
    public function lecturerEditkecheng()
    {
        [$aid, $mid] = $this->memberContext();

        return $this->success($this->examService->apiLecturerCourseSave(
            $aid,
            $mid,
            (array) $this->request->post('info', $this->request->post())
        ), '提交成功');
    }

    /**
     * 讲师列表（登录；审核通过且启用）
     * GET /api/kecheng/lecturer/list?pagenum
     */
    public function lecturerlist()
    {
        [$aid] = $this->memberContext();

        return $this->success($this->examService->apiLecturerList(
            $aid,
            max(1, (int) $this->request->param('pagenum', 1))
        ));
    }
}
