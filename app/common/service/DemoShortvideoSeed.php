<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * 平台「营销 → 短视频」演示数据种子（demo:shortvideo）。
 *
 * 与既有 {@see DemoShortvideoGenerator}（商户视频，【示例】前缀）区分：
 * 本种子服务于租户后台「客户端·营销 → 短视频」整组页面，bid=0 平台数据，
 * 名称统一以 DEMO-SV- 开头（同时作为幂等与清理标识）。
 *
 * 覆盖：分类 / 视频（待审核0 / 已通过1 / 已驳回2）/ 评论（已审核/待审核）/ 回评（已审核/已驳回）。
 * 媒体复用 public/uploads/demo/shortvideo_m_1~3.jpg|mp4。
 */
class DemoShortvideoSeed
{
    public const DEMO_TAG = 'DEMO-SV-';

    private const MEDIA_URL = '/uploads/demo/';

    private int $aid = 1;

    /** @var array<int, string> */
    private array $logs = [];

    /**
     * @return array{categories:int, videos:int, comments:int, replies:int, logs:array<int,string>}
     */
    public function generate(int $aid, bool $reset = false): array
    {
        $this->aid = max(1, $aid);
        $this->logs = [];

        $this->log('==== 平台短视频演示数据生成开始（aid=' . $this->aid . ($reset ? '，--reset 重建' : '') . '）====');

        if (empty(Db::name('admin')->where('id', $this->aid)->find())) {
            $this->log('<error>平台租户 admin.id=' . $this->aid . ' 不存在。</error>');
            return $this->result(0, 0, 0, 0);
        }

        if ($reset) {
            $p = $this->purge($this->aid);
            $this->log('  --reset 先清理：分类 ' . $p['categories'] . '、视频 ' . $p['videos'] . '、评论 ' . $p['comments'] . '、回评 ' . $p['replies']);
        }

        $catIds = $this->ensureCategories();
        $videoIds = $this->ensureVideos($catIds);
        $commentCount = $this->ensureComments($videoIds);
        $replyCount = $this->ensureReplies($videoIds);

        $this->log('==== 生成结束：分类 ' . count($catIds) . '、视频 ' . count($videoIds) . '、评论 ' . $commentCount . '、回评 ' . $replyCount . ' ====');

        return $this->result(count($catIds), count($videoIds), $commentCount, $replyCount);
    }

    /**
     * 仅清理 DEMO-SV- 前缀的演示数据。
     *
     * @return array{categories:int, videos:int, comments:int, replies:int}
     */
    public function purge(int $aid): array
    {
        $aid = max(1, $aid);

        // 先收集演示视频 id，级联删评论/回评/点赞
        $videoIds = Db::name('shortvideo')->where('aid', $aid)->where('bid', 0)
            ->whereLike('name', self::DEMO_TAG . '%')->column('id');
        $commentIds = [];
        if ($videoIds) {
            $commentIds = Db::name('shortvideo_comment')->where('aid', $aid)->whereIn('vid', $videoIds)->column('id');
            Db::name('shortvideo_comment')->where('aid', $aid)->whereIn('vid', $videoIds)->delete();
            Db::name('shortvideo_comment_reply')->where('aid', $aid)->whereIn('vid', $videoIds)->delete();
            Db::name('shortvideo_zanlog')->where('aid', $aid)->whereIn('vid', $videoIds)->delete();
            Db::name('shortvideo')->where('aid', $aid)->whereIn('id', $videoIds)->delete();
        }
        if ($commentIds) {
            Db::name('shortvideo_comment_zanlog')->where('aid', $aid)->whereIn('pid', $commentIds)->delete();
        }

        $catCount = Db::name('shortvideo_category')->where('aid', $aid)->where('bid', 0)
            ->whereLike('name', self::DEMO_TAG . '%')->delete();

        return [
            'categories' => (int) $catCount,
            'videos' => count($videoIds),
            'comments' => count($commentIds),
            'replies' => 0,
        ];
    }

    /**
     * @return array<string, int> 分类名 => id
     */
    private function ensureCategories(): array
    {
        $names = [self::DEMO_TAG . '健康讲座', self::DEMO_TAG . '调理资讯'];
        $now = time();
        $map = [];
        foreach ($names as $i => $name) {
            $exist = Db::name('shortvideo_category')->where('aid', $this->aid)->where('bid', 0)->where('name', $name)->find();
            if ($exist) {
                $map[$name] = (int) $exist['id'];
                continue;
            }
            $id = (int) Db::name('shortvideo_category')->insertGetId([
                'aid' => $this->aid, 'bid' => 0, 'name' => $name,
                'status' => 1, 'sort' => 100 - $i, 'createtime' => $now - ($i + 1) * 86400,
            ]);
            $map[$name] = $id;
            $this->log('  新建分类：' . $name . '（cid=' . $id . '）');
        }
        return $map;
    }

    /**
     * @param array<string, int> $catIds
     * @return array<int, int> 视频名 => id
     */
    private function ensureVideos(array $catIds): array
    {
        $specs = [
            ['name' => self::DEMO_TAG . '好物推荐', 'cat' => self::DEMO_TAG . '健康讲座', 'cover' => 'shortvideo_m_1.jpg', 'video' => 'shortvideo_m_1.mp4', 'desc' => '优质好物实拍推荐，喜欢就下单吧。', 'status' => 1, 'reason' => '', 'view' => 35, 'zan' => 25, 'sort' => 100, 'days' => 2],
            ['name' => self::DEMO_TAG . '测试待审核', 'cat' => self::DEMO_TAG . '健康讲座', 'cover' => 'shortvideo_m_2.jpg', 'video' => 'shortvideo_m_2.mp4', 'desc' => '该视频待审核，演示待审核状态。', 'status' => 0, 'reason' => '', 'view' => 0, 'zan' => 0, 'sort' => 90, 'days' => 1],
            ['name' => self::DEMO_TAG . '已驳回示例', 'cat' => self::DEMO_TAG . '调理资讯', 'cover' => 'shortvideo_m_3.jpg', 'video' => 'shortvideo_m_3.mp4', 'desc' => '该视频因违规被驳回，演示已驳回状态。', 'status' => 2, 'reason' => '内容涉及违规广告，请修改后重新提交', 'view' => 0, 'zan' => 0, 'sort' => 80, 'days' => 3],
            ['name' => self::DEMO_TAG . '不会', 'cat' => self::DEMO_TAG . '调理资讯', 'cover' => 'shortvideo_m_1.jpg', 'video' => 'shortvideo_m_1.mp4', 'desc' => '生活调理小知识分享。', 'status' => 1, 'reason' => '', 'view' => 0, 'zan' => 0, 'sort' => 70, 'days' => 5],
        ];

        $map = [];
        foreach ($specs as $spec) {
            $exist = Db::name('shortvideo')->where('aid', $this->aid)->where('bid', 0)->where('name', $spec['name'])->find();
            if ($exist) {
                $map[$spec['name']] = (int) $exist['id'];
                continue;
            }
            $cid = $catIds[$spec['cat']] ?? 0;
            $id = (int) Db::name('shortvideo')->insertGetId([
                'aid' => $this->aid, 'bid' => 0, 'mid' => 0, 'cid' => $cid,
                'name' => $spec['name'], 'url' => self::MEDIA_URL . $spec['video'],
                'video_duration' => 10.0, 'coverimg' => self::MEDIA_URL . $spec['cover'],
                'description' => $spec['desc'], 'productids' => '', 'linkurl' => '', 'linkname' => '',
                'comment' => 1, 'comment_check' => 0, 'view_num' => $spec['view'], 'zan_num' => $spec['zan'],
                'share_num' => 0, 'sort' => $spec['sort'], 'createtime' => time() - $spec['days'] * 86400,
                'status' => $spec['status'], 'reason' => $spec['reason'],
            ]);
            $map[$spec['name']] = $id;
            $this->log('  新建视频：' . $spec['name'] . '（id=' . $id . '，status=' . $spec['status'] . '）');
        }
        return $map;
    }

    /**
     * @param array<int, int> $videoIds
     */
    private function ensureComments(array $videoIds): int
    {
        $approved = $videoIds[self::DEMO_TAG . '好物推荐'] ?? 0;
        $list = [
            ['vid' => $approved, 'nick' => '小丸子', 'content' => '嘿嘿宝', 'status' => 1, 'days' => 1],
            ['vid' => $approved, 'nick' => '尝鲜客', 'content' => '等你等你', 'status' => 1, 'days' => 2],
            ['vid' => $approved, 'nick' => '新会员', 'content' => '这条评论待审核', 'status' => 0, 'days' => 1],
        ];
        $n = 0;
        foreach ($list as $c) {
            if ($c['vid'] <= 0) continue;
            $exist = Db::name('shortvideo_comment')->where('aid', $this->aid)->where('vid', $c['vid'])->where('content', $c['content'])->find();
            if ($exist) { $n++; continue; }
            Db::name('shortvideo_comment')->insert([
                'aid' => $this->aid, 'bid' => 0, 'vid' => $c['vid'], 'mid' => 0,
                'headimg' => '', 'nickname' => $c['nick'], 'content' => $c['content'],
                'createtime' => time() - $c['days'] * 86400, 'status' => $c['status'], 'zan' => 0,
            ]);
            $n++;
            $this->log('  新建评论：' . $c['content'] . '（status=' . $c['status'] . '）');
        }
        return $n;
    }

    /**
     * @param array<int, int> $videoIds
     */
    private function ensureReplies(array $videoIds): int
    {
        $approved = $videoIds[self::DEMO_TAG . '好物推荐'] ?? 0;
        // 取该视频下第一条评论作为原评论
        $pid = (int) Db::name('shortvideo_comment')->where('aid', $this->aid)->where('vid', $approved)->order('id', 'asc')->value('id');
        if ($pid <= 0) return 0;

        $list = [
            ['nick' => '平台', 'content' => '感谢支持，欢迎选购', 'status' => 1, 'days' => 1],
            ['nick' => '平台', 'content' => '该回复因含广告被驳回', 'status' => 2, 'days' => 2],
        ];
        $n = 0;
        foreach ($list as $r) {
            $exist = Db::name('shortvideo_comment_reply')->where('aid', $this->aid)->where('pid', $pid)->where('content', $r['content'])->find();
            if ($exist) { $n++; continue; }
            Db::name('shortvideo_comment_reply')->insert([
                'aid' => $this->aid, 'bid' => 0, 'vid' => $approved, 'pid' => $pid, 'mid' => 0,
                'headimg' => '', 'nickname' => $r['nick'], 'content' => $r['content'],
                'createtime' => time() - $r['days'] * 86400, 'status' => $r['status'], 'zan' => 0,
            ]);
            $n++;
            $this->log('  新建回评：' . $r['content'] . '（status=' . $r['status'] . '）');
        }
        return $n;
    }

    /**
     * @return array{categories:int, videos:int, comments:int, replies:int, logs:array<int,string>}
     */
    private function result(int $c, int $v, int $cm, int $rp): array
    {
        return ['categories' => $c, 'videos' => $v, 'comments' => $cm, 'replies' => $rp, 'logs' => $this->logs];
    }

    private function log(string $line): void
    {
        $this->logs[] = $line;
    }
}
