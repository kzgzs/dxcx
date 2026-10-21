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
        if ($this->localMode($aid)) {
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

        if ($this->localMode($aid)) {
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

    /**
     * 从小程序中同步直播间（微信 wxa/business/getliveinfo；mock 直接返回成功）
     * POST /admin/live/tongbu
     */
    public function tongbu()
    {
        $aid = $this->aid();

        if ($this->localMode($aid)) {
            PlogService::write($aid, $this->uid(), '小程序直播同步(mock)');
            return $this->success([], '同步完成');
        }

        $token = $this->binding->accessToken('wx', $aid);
        $rs    = $this->wxPostJson('https://api.weixin.qq.com/wxa/business/getliveinfo?access_token=' . $token, ['start' => 0, 'limit' => 100]);
        if (!is_array($rs) || !in_array((int) ($rs['errcode'] ?? -1), [0, 1], true)) {
            if (is_array($rs) && (int) ($rs['errcode'] ?? 0) === 9410000) {
                Db::name('live_room')->where('aid', $aid)->delete();
                return $this->success([], '同步完成');
            }
            return $this->error($this->wxError($rs));
        }

        $roomIds = [];
        foreach (array_reverse($rs['room_info'] ?? []) as $v) {
            $roomIds[] = (int) $v['roomid'];
            $data = [
                'roomId'      => (int) $v['roomid'],
                'name'        => (string) ($v['name'] ?? ''),
                'coverImg'    => (string) ($v['cover_img'] ?? ''),
                'shareImg'    => (string) ($v['share_img'] ?? ''),
                'live_status' => (string) ($v['live_status'] ?? ''),
                'startTime'   => (int) ($v['start_time'] ?? 0),
                'endTime'     => (int) ($v['end_time'] ?? 0),
                'anchorName'  => (string) ($v['anchor_name'] ?? ''),
                'goods'       => json_encode($v['goods'] ?? [], JSON_UNESCAPED_UNICODE),
            ];
            $exists = Db::name('live_room')->where('aid', $aid)->where('roomId', (int) $v['roomid'])->find();
            if ($exists) {
                Db::name('live_room')->where('id', $exists['id'])->update($data);
            } else {
                Db::name('live_room')->insert($data + ['aid' => $aid, 'createtime' => time()]);
            }
        }
        if ($roomIds) {
            Db::name('live_room')->where('aid', $aid)->whereNotIn('roomId', $roomIds)->delete();
        }
        PlogService::write($aid, $this->uid(), '小程序直播同步');

        return $this->success([], '同步完成');
    }

    /**
     * 商品库列表（微信 wxaapi/broadcast/goods/getapproved；mock 读本地 live_goods）
     * 审核状态：1审核中 2审核通过 3审核驳回
     * GET /admin/live/goodslist?st&page&pagesize
     */
    public function goodslist()
    {
        $aid      = $this->aid();
        $st       = (int) $this->request->param('st', 1);
        $page     = max(1, (int) $this->request->param('page', 1));
        $pagesize = max(1, min(100, (int) $this->request->param('pagesize', 10)));

        if ($this->localMode($aid)) {
            $q     = Db::name('live_goods')->where('aid', $aid)->where('audit_status', $st);
            $total = (clone $q)->count();
            $rows  = $q->order('id', 'desc')->page($page, $pagesize)->select()->toArray();
            foreach ($rows as &$r) {
                $r['price_yuan']  = $this->fenToYuan((int) $r['price']);
                $r['price2_yuan'] = $this->fenToYuan((int) $r['price2']);
            }
            unset($r);
            return $this->success(['list' => $rows, 'total' => $total]);
        }

        $rs = $this->wxGetJson('https://api.weixin.qq.com/wxaapi/broadcast/goods/getapproved', [
            'offset' => ($page - 1) * $pagesize,
            'limit'  => $pagesize,
            'status' => $st,
        ], $this->binding->accessToken('wx', $aid));
        if (!is_array($rs) || 0 !== (int) ($rs['errcode'] ?? -1)) {
            return $this->error($this->wxError($rs));
        }
        $rows = $rs['goods'] ?? [];
        foreach ($rows as &$g) {
            $g['price_yuan']  = $this->fenToYuan((int) ($g['price'] ?? 0));
            $g['price2_yuan'] = $this->fenToYuan((int) ($g['price2'] ?? 0));
        }
        unset($g);

        return $this->success(['list' => $rows, 'total' => (int) ($rs['total'] ?? 0)]);
    }

    /**
     * 添加商品（微信 goods/add；mock 落本地 live_goods，默认审核中）
     * POST /admin/live/goodsadd {info:{name,coverImgUrl,url,priceType,price,price2}}（价格单位：元）
     */
    public function goodsadd()
    {
        $aid  = $this->aid();
        $info = $this->request->post('info/a', []);
        $info = is_array($info) ? $info : [];
        $check = $this->checkGoods($info);
        if ('' !== $check) {
            return $this->error($check);
        }

        if ($this->localMode($aid)) {
            $id = (int) Db::name('live_goods')->insertGetId([
                'aid'          => $aid,
                'goodsId'      => 0,
                'name'         => $info['name'],
                'coverImgUrl'  => $info['coverImgUrl'],
                'url'          => $info['url'],
                'priceType'    => (int) $info['priceType'],
                'price'        => $this->yuanToFen($info['price']),
                'price2'       => $this->yuanToFen($info['price2'] ?? 0),
                'audit_status' => 1,
                'createtime'   => time(),
            ]);
            Db::name('live_goods')->where('id', $id)->update(['goodsId' => $id]);
            PlogService::write($aid, $this->uid(), '直播商品库添加商品' . $id);
            return $this->success([], '操作成功');
        }

        $goodsInfo = [
            'coverImgUrl' => $this->imageToMediaId($aid, (string) $info['coverImgUrl']),
            'name'        => $info['name'],
            'priceType'   => (int) $info['priceType'],
            'price'       => $this->yuanToFen($info['price']),
            'price2'      => $this->yuanToFen($info['price2'] ?? 0),
            'url'         => (string) $info['url'],
        ];
        $rs = $this->wxPostJson('https://api.weixin.qq.com/wxaapi/broadcast/goods/add?access_token=' . $this->binding->accessToken('wx', $aid), ['goodsInfo' => $goodsInfo]);
        if (!is_array($rs) || 0 !== (int) ($rs['errcode'] ?? -1)) {
            return $this->error($this->wxError($rs));
        }
        return $this->success([], '操作成功');
    }

    /**
     * 修改价格（微信 goods/update；mock 更新本地）
     * POST /admin/live/goodsupd {goodsId,info:{priceType,price,price2}}
     */
    public function goodsupd()
    {
        $aid       = $this->aid();
        $goodsId   = (int) $this->request->param('goodsId', 0);
        $info      = $this->request->post('info/a', []);
        $info      = is_array($info) ? $info : [];
        $priceType = (int) ($info['priceType'] ?? 1);
        if (!in_array($priceType, [1, 2, 3], true)) {
            return $this->error('价格形式错误');
        }
        if (!is_numeric($info['price'] ?? null) || (float) $info['price'] < 0) {
            return $this->error('请填写正确的价格');
        }
        if ($priceType !== 1 && (!is_numeric($info['price2'] ?? null) || (float) $info['price2'] < 0)) {
            return $this->error('请填写正确的价格');
        }
        if (2 === $priceType && (float) $info['price2'] < (float) $info['price']) {
            return $this->error('价格区间上限不能小于下限');
        }
        if (3 === $priceType && (float) $info['price2'] > (float) $info['price']) {
            return $this->error('折扣现价不能大于原价');
        }

        if ($this->localMode($aid)) {
            $exists = Db::name('live_goods')->where('aid', $aid)->where('id', $goodsId)->find();
            if (empty($exists)) {
                return $this->error('商品不存在');
            }
            Db::name('live_goods')->where('id', $goodsId)->update([
                'priceType' => $priceType,
                'price'     => $this->yuanToFen($info['price']),
                'price2'    => $this->yuanToFen($info['price2'] ?? 0),
            ]);
            PlogService::write($aid, $this->uid(), '直播商品修改价格' . $goodsId);
            return $this->success([], '操作成功');
        }

        $rs = $this->wxPostJson('https://api.weixin.qq.com/wxaapi/broadcast/goods/update?access_token=' . $this->binding->accessToken('wx', $aid), [
            'goodsInfo' => [
                'goodsId'   => $goodsId,
                'priceType' => $priceType,
                'price'     => $this->yuanToFen($info['price']),
                'price2'    => $this->yuanToFen($info['price2'] ?? 0),
            ],
        ]);
        if (!is_array($rs) || 0 !== (int) ($rs['errcode'] ?? -1)) {
            return $this->error($this->wxError($rs));
        }
        return $this->success([], '操作成功');
    }

    /**
     * 删除商品（微信 goods/delete；mock 删本地）
     * POST /admin/live/goodsdel {goodsId}
     */
    public function goodsdel()
    {
        $aid     = $this->aid();
        $goodsId = (int) $this->request->post('goodsId', 0);
        if ($goodsId <= 0) {
            return $this->error('参数错误');
        }

        if ($this->localMode($aid)) {
            Db::name('live_goods')->where('aid', $aid)->where('id', $goodsId)->delete();
            PlogService::write($aid, $this->uid(), '直播商品库删除商品' . $goodsId);
            return $this->success([], '操作成功');
        }

        $rs = $this->wxPostJson('https://api.weixin.qq.com/wxaapi/broadcast/goods/delete?access_token=' . $this->binding->accessToken('wx', $aid), ['goodsId' => $goodsId]);
        if (!is_array($rs) || 0 !== (int) ($rs['errcode'] ?? -1)) {
            return $this->error($this->wxError($rs));
        }
        return $this->success([], '操作成功');
    }

    /**
     * 直播间选择商品（微信 room/addgoods；mock 把商品写入 live_room.goods）
     * POST /admin/live/roomaddgoods {id, ids:[live_goods.id 或微信 goodsId]}
     */
    public function roomaddgoods()
    {
        $aid = $this->aid();
        $id  = (int) $this->request->post('id', 0);
        $ids = $this->request->post('ids', []);
        $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
        $room = Db::name('live_room')->where('aid', $aid)->where('id', $id)->find();
        if (empty($room)) {
            return $this->error('直播间不存在');
        }
        if (empty($ids)) {
            return $this->error('请选择商品');
        }

        if ($this->localMode($aid)) {
            $goodsRows = Db::name('live_goods')->where('aid', $aid)->whereIn('id', $ids)->select()->toArray();
            $goods = [];
            foreach ($goodsRows as $g) {
                $goods[] = [
                    'goods_id'   => (int) $g['goodsId'],
                    'name'       => $g['name'],
                    'cover_img'  => $g['coverImgUrl'],
                    'url'        => $g['url'],
                    'price_type' => (int) $g['priceType'],
                    'price'      => (int) $g['price'],
                    'price2'     => (int) $g['price2'],
                ];
            }
            $old = json_decode((string) ($room['goods'] ?? ''), true);
            $old = is_array($old) ? $old : [];
            $map = [];
            foreach (array_merge($old, $goods) as $og) {
                $map[(int) ($og['goods_id'] ?? 0)] = $og;
            }
            Db::name('live_room')->where('id', $id)->update(['goods' => json_encode(array_values($map), JSON_UNESCAPED_UNICODE)]);
            PlogService::write($aid, $this->uid(), '直播间添加商品' . $id);
            return $this->success([], '操作成功');
        }

        $rs = $this->wxPostJson('https://api.weixin.qq.com/wxaapi/broadcast/room/addgoods?access_token=' . $this->binding->accessToken('wx', $aid), [
            'ids'    => $ids,
            'roomId' => (int) ($room['roomId'] ?? 0),
        ]);
        if (!is_array($rs) || 0 !== (int) ($rs['errcode'] ?? -1)) {
            return $this->error($this->wxError($rs));
        }
        return $this->tongbu();
    }

    /** 商品表单校验，返回错误串（空串通过）；价格入参单位元 */
    private function checkGoods(array $info): string
    {
        $name = trim((string) ($info['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 17) {
            return '商品名称最短3个汉字，最长17个汉字';
        }
        if ('' === trim((string) ($info['coverImgUrl'] ?? ''))) {
            return '请上传商品图片';
        }
        $priceType = (int) ($info['priceType'] ?? 1);
        if (!in_array($priceType, [1, 2, 3], true)) {
            return '价格形式错误';
        }
        if (!is_numeric($info['price'] ?? null) || (float) $info['price'] < 0) {
            return '请填写正确的价格';
        }
        if ($priceType !== 1) {
            if (!is_numeric($info['price2'] ?? null) || (float) $info['price2'] < 0) {
                return '请填写正确的价格';
            }
            if (2 === $priceType && (float) $info['price2'] < (float) $info['price']) {
                return '价格区间上限不能小于下限';
            }
            if (3 === $priceType && (float) $info['price2'] > (float) $info['price']) {
                return '折扣现价不能大于原价';
            }
        }
        return '';
    }

    /** 元（字符串/数字）→ 分（整数） */
    private function yuanToFen($yuan): int
    {
        return (int) round(((float) $yuan) * 100);
    }

    /** 分 → 元（两位小数，去多余0） */
    private function fenToYuan(int $fen): string
    {
        return rtrim(rtrim(number_format($fen / 100, 2, '.', ''), '0'), '.');
    }

    /** 真实模式：本地图片 URL 转微信永久素材 mediaId */
    private function imageToMediaId(int $aid, string $imageUrl): string
    {
        $img = $this->fetchUrl($imageUrl);
        if ('' === $img) {
            throw new BusinessException('商品图片读取失败：' . $imageUrl);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'wxlive_') . '.jpg';
        file_put_contents($tmp, $img);
        try {
            $rs = $this->api->addMaterial($this->binding->accessToken('wx', $aid), 'image', $tmp);
        } finally {
            @unlink($tmp);
        }
        $mediaId = (string) ($rs['media_id'] ?? '');
        if ('' === $mediaId) {
            throw new BusinessException('商品图片上传微信失败：' . (string) ($rs['errmsg'] ?? 'unknown'));
        }
        return $mediaId;
    }

    /** 微信 JSON POST，返回解码数组或 null */
    private function wxPostJson(string $url, array $data): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return is_string($resp) ? (json_decode($resp, true) ?: null) : null;
    }

    /** 微信 GET，返回解码数组或 null */
    private function wxGetJson(string $base, array $query, string $token): ?array
    {
        $query['access_token'] = $token;
        $ch = curl_init($base . '?' . http_build_query($query));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return is_string($resp) ? (json_decode($resp, true) ?: null) : null;
    }

    /** 下载 URL 内容（兼容站内相对路径） */
    private function fetchUrl(string $url): string
    {
        if (str_starts_with($url, '/')) {
            $path = app()->getRootPath() . 'public' . $url;
            return is_file($path) ? (string) file_get_contents($path) : '';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return is_string($resp) ? $resp : '';
    }

    /** 微信错误文案 */
    private function wxError(?array $rs): string
    {
        if (!is_array($rs)) {
            return '微信接口请求失败';
        }
        $map = [
            9410000 => '小程序直播功能未开通或已被收回',
            300002  => '商品名称违规',
            300003  => '商品图片违规',
        ];
        $code = (int) ($rs['errcode'] ?? -1);
        return ($map[$code] ?? '微信接口错误') . '(' . $code . ')：' . (string) ($rs['errmsg'] ?? '');
    }

    /** 本地模式：开放平台 mock，或租户未绑定小程序（无 appid）时商品库/同步/选品走本地表 */
    private function localMode(int $aid): bool
    {
        if ($this->open->isMock()) {
            return true;
        }
        $appid = (string) Db::name('admin_setapp_wx')->where('aid', $aid)->value('appid');
        return '' === $appid;
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
