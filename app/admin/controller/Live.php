<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\exception\BusinessException;
use app\common\service\BindingTokenService;
use app\common\service\OpenPlatformService;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use app\common\service\WxApiClient;
use think\facade\Db;

/**
 * 租户后台 - 小程序直播（批次7-6 任务214；对标参考系统 Live）
 *
 * 直播间 CRUD（live_room）+ 推流二维码（getwxacodeunlimit，mock 返回占位图路径）
 */
class Live extends BaseController
{
    /** 允许写入 live_room 的字段 */
    protected const SAVE_FIELDS = [
        'name', 'coverImg', 'startTime', 'endTime', 'anchorName', 'anchorWechat',
        'subAnchorWechat', 'createrWechat', 'shareImg', 'feedsImg', 'isFeedsPublic',
        'type', 'screenType', 'closeLike', 'closeGoods', 'closeComment', 'closeReplay',
        'closeShare', 'closeKf', 'sort', 'status',
    ];

    public function __construct(
        \think\App $app,
        protected BindingTokenService $binding,
        protected WxApiClient $api,
        protected OpenPlatformService $open,
    ) {
        parent::__construct($app);
    }

    /**
     * 直播间列表
     * GET /admin/live/list?page&pagesize&name
     */
    public function list()
    {
        $aid      = $this->aid();
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 20)));
        $name     = trim((string) $this->request->param('name', ''));

        $query = Db::name('live_room')->where('aid', $aid);
        if ('' !== $name) {
            $query->where('name', 'like', '%' . $name . '%');
        }

        $total = (clone $query)->count();
        $list  = $query->order('sort', 'desc')->order('startTime', 'desc')
            ->page($page, $pagesize)->select()->toArray();

        foreach ($list as &$row) {
            $goods = json_decode((string) ($row['goods'] ?? ''), true);
            $row['goods'] = is_array($goods) ? $goods : [];
        }
        unset($row);

        return $this->success(['list' => $list, 'total' => $total, 'nowtime' => time()]);
    }

    /**
     * 直播间详情
     * GET /admin/live/detail?id
     */
    public function detail()
    {
        $id   = (int) $this->request->param('id', 0);
        $info = Db::name('live_room')->where('aid', $this->aid())->where('id', $id)->find();
        if (empty($info)) {
            return $this->error('直播间不存在');
        }

        return $this->success($info);
    }

    /**
     * 新增/编辑直播间
     * POST /admin/live/save {info:{...}}
     */
    public function save()
    {
        $aid  = $this->aid();
        $info = $this->request->post('info/a', []);
        $info = is_array($info) ? $info : [];

        if ('' === trim((string) ($info['name'] ?? ''))) {
            return $this->error('请填写房间名');
        }
        if ('' === trim((string) ($info['anchorName'] ?? ''))) {
            return $this->error('请填写主播昵称');
        }

        $data = [];
        foreach (self::SAVE_FIELDS as $field) {
            if (array_key_exists($field, $info)) {
                $data[$field] = $info[$field];
            }
        }
        foreach (['startTime', 'endTime'] as $timeField) {
            $value = (string) ($data[$timeField] ?? '');
            if ('' === $value) {
                return $this->error('请选择' . ('startTime' === $timeField ? '开始时间' : '结束时间'));
            }
            $ts = is_numeric($value) ? (int) $value : (int) strtotime($value);
            if ($ts <= 0) {
                return $this->error('时间格式错误');
            }
            $data[$timeField] = $ts;
        }
        foreach (['isFeedsPublic', 'type', 'screenType', 'closeLike', 'closeGoods', 'closeComment', 'closeReplay', 'closeShare', 'closeKf', 'sort', 'status'] as $intField) {
            if (isset($data[$intField])) {
                $data[$intField] = (int) $data[$intField];
            }
        }

        $id = (int) ($info['id'] ?? 0);
        if ($id > 0) {
            $exists = Db::name('live_room')->where('aid', $aid)->where('id', $id)->find();
            if (empty($exists)) {
                return $this->error('直播间不存在');
            }
            Db::name('live_room')->where('aid', $aid)->where('id', $id)->update($data);
            PlogService::write($aid, $this->uid(), '编辑直播间' . $id);

            return $this->success(['id' => $id], '操作成功');
        }

        // 新增：mock 直接落库模拟 roomId；真实模式走 broadcast/room/create（WxApiClient 暂未封装，桩）
        if ($this->open->isMock()) {
            $data['roomId'] = random_int(100000, 999999);
        } else {
            throw new BusinessException('真实模式直播间创建未接入（TODO）', 501, 501);
        }

        $data['aid']        = $aid;
        $data['createtime'] = time();
        $newId = (int) Db::name('live_room')->insertGetId($data);
        PlogService::write($aid, $this->uid(), '添加直播间' . $newId);

        return $this->success(['id' => $newId], '操作成功');
    }

    /**
     * 改显示状态
     * POST /admin/live/setst {ids:[...], st}
     */
    public function setst()
    {
        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
        $st  = (int) $this->request->post('st', 1);
        if (empty($ids)) {
            return $this->error('请选择直播间');
        }

        Db::name('live_room')->where('aid', $this->aid())->whereIn('id', $ids)->update(['status' => $st]);
        PlogService::write($this->aid(), $this->uid(), '直播间改状态' . implode(',', $ids));

        return $this->success([], '操作成功');
    }

    /**
     * 删除直播间
     * POST /admin/live/del {ids:[...]}
     */
    public function del()
    {
        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
        if (empty($ids)) {
            return $this->error('请选择直播间');
        }

        Db::name('live_room')->where('aid', $this->aid())->whereIn('id', $ids)->delete();
        PlogService::write($this->aid(), $this->uid(), '删除直播间' . implode(',', $ids));

        return $this->success([], '删除成功');
    }

    /**
     * 主播推流二维码（getwxacodeunlimit；mock 返回占位图路径并回写 qrcode_url）
     * POST /admin/live/getpusherqr {id}
     */
    public function getpusherqr()
    {
        $aid  = $this->aid();
        $id   = (int) $this->request->post('id', 0);
        $room = Db::name('live_room')->where('aid', $aid)->where('id', $id)->find();
        if (empty($room)) {
            return $this->error('直播间不存在');
        }

        $scene = (string) ((int) ($room['roomId'] ?? 0) ?: (int) $room['id']);

        if ($this->open->isMock()) {
            // mock：占位图路径（真实返回小程序码二进制，落盘后给 URL）
            $url = '/static/img/posterbg.jpg';
            Db::name('live_room')->where('aid', $aid)->where('id', $id)->update(['qrcode_url' => $url]);

            return $this->success(['url' => $url, 'mock' => true], '获取成功');
        }

        $token = $this->binding->accessToken('wx', $aid);
        $raw   = $this->api->getLivePusherQr($token, $scene);
        if ('' === $raw || str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);
            throw new BusinessException('获取推流二维码失败：' . (string) (is_array($decoded) ? ($decoded['errmsg'] ?? 'unknown') : 'unknown'));
        }

        $dir = app()->getRootPath() . 'public/uploads/live';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = 'pusher_' . $id . '_' . time() . '.jpg';
        file_put_contents($dir . '/' . $path, $raw);
        $url = '/uploads/live/' . $path;
        Db::name('live_room')->where('aid', $aid)->where('id', $id)->update(['qrcode_url' => $url]);

        return $this->success(['url' => $url, 'mock' => false], '获取成功');
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
