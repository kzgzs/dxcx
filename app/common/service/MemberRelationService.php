<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;

/**
 * 会员关系图服务（任务176 批次2-D）
 *
 * 对照参考系统 Member::charts/chartsMore/getdown：
 * - 按 member.pid 递归直推树，深度上限 10（防环 + 性能保护）
 * - 节点：昵称/等级名/直推下级数 downcount/全部下级数 downcountall（path 列 find_in_set 统计）
 * - chartsMore 懒加载：按 mid 返回该节点的下级子树（供前端 ECharts tree 逐层展开）
 */
class MemberRelationService
{
    /** 递归深度上限（对齐参考系统 getdown：>10 停止） */
    public const MAX_DEPTH = 10;

    /**
     * 关系图主接口：以指定会员为根构建直推树
     *
     * @return array{root: array<string, mixed>|null, deeplevel: int}
     */
    public function chart(int $aid, int $mid, string $keyword, int $depth): array
    {
        $depth = $depth > 0 ? min($depth, self::MAX_DEPTH) : self::MAX_DEPTH;

        if ($mid <= 0 && '' !== trim($keyword)) {
            $mid = $this->searchFirst($aid, trim($keyword));
        }

        if ($mid <= 0) {
            return ['root' => null, 'deeplevel' => 0];
        }

        $member = ScopeService::query('member', $aid)
            ->field('id, nickname, headimg, pid, realname, tel, levelid')
            ->where('id', $mid)
            ->find();

        if (empty($member)) {
            throw new BusinessException('会员不存在', 404, 404);
        }

        $levelMap = $this->levelMap($aid);
        $member   = (array) $member;

        $root = $this->buildNode($aid, $member, $levelMap, 0, $depth);

        return ['root' => $root, 'deeplevel' => $this->deeplevel];
    }

    /**
     * 懒加载：返回指定会员的下级子树（含自身节点信息，children 为直推树）
     *
     * @return array<string, mixed>
     */
    public function chartsMore(int $aid, int $mid): array
    {
        if ($mid <= 0) {
            throw new BusinessException('缺少会员ID');
        }

        $member = ScopeService::query('member', $aid)
            ->field('id, nickname, headimg, pid, realname, tel, levelid')
            ->where('id', $mid)
            ->find();

        if (empty($member)) {
            throw new BusinessException('会员不存在', 404, 404);
        }

        $levelMap = $this->levelMap($aid);

        $node = $this->buildNode($aid, (array) $member, $levelMap, 0, self::MAX_DEPTH);

        return ['node' => $node, 'deeplevel' => $this->deeplevel];
    }

    /** 本次递归到达的最大深度 */
    protected int $deeplevel = 0;

    /**
     * 递归构建节点（含 children；达到深度上限时 children 置空并 hasMore=true 供懒加载）
     *
     * @param array<string, mixed> $member
     * @param array<int, string>   $levelMap
     * @return array<string, mixed>
     */
    protected function buildNode(int $aid, array $member, array $levelMap, int $depth, int $maxDepth): array
    {
        $mid       = (int) $member['id'];
        $levelId   = (int) ($member['levelid'] ?? 0);
        $directNum = (int) ScopeService::query('member', $aid)->where('pid', $mid)->count();
        $allNum    = (int) ScopeService::query('member', $aid)
            ->whereRaw('find_in_set(' . $mid . ', path)')
            ->count();

        $node = [
            'id'            => $mid,
            'name'          => (string) ($member['nickname'] ?: ('会员' . $mid)),
            'headimg'       => (string) ($member['headimg'] ?? ''),
            'realname'      => (string) ($member['realname'] ?? ''),
            'tel'           => (string) ($member['tel'] ?? ''),
            'pid'           => (int) ($member['pid'] ?? 0),
            'levelid'       => $levelId,
            'level_name'    => $levelMap[$levelId] ?? '',
            'downcount'     => $directNum,
            'downcountall'  => $allNum,
            'deeplevel'     => $depth,
            'hasMore'       => false,
            'children'      => [],
        ];

        if ($directNum <= 0) {
            return $node;
        }

        if ($depth >= $maxDepth) {
            // 深度上限：保留懒加载入口（对齐参考系统 >10 截断）
            $node['hasMore'] = true;

            return $node;
        }

        if ($depth + 1 > $this->deeplevel) {
            $this->deeplevel = $depth + 1;
        }

        $children = ScopeService::query('member', $aid)
            ->field('id, nickname, headimg, pid, realname, tel, levelid')
            ->where('pid', $mid)
            ->select()
            ->toArray();

        $node['children'] = array_map(
            fn (array $child) => $this->buildNode($aid, $child, $levelMap, $depth + 1, $maxDepth),
            $children
        );

        return $node;
    }

    /**
     * 按关键字定位首位会员（id/nickname/tel）
     */
    protected function searchFirst(int $aid, string $keyword): int
    {
        $query = ScopeService::query('member', $aid);

        if (ctype_digit($keyword)) {
            $query->where('id', (int) $keyword);
            $member = $query->find();
            if (!empty($member)) {
                return (int) $member['id'];
            }
        }

        $member = ScopeService::query('member', $aid)
            ->where('nickname', 'like', '%' . $keyword . '%')
            ->order('id', 'asc')
            ->find();

        return $member ? (int) $member['id'] : 0;
    }

    /**
     * 等级 ID → 名称映射
     *
     * @return array<int, string>
     */
    protected function levelMap(int $aid): array
    {
        $rows = ScopeService::query('member_level', $aid)->field('id, name')->select()->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $map;
    }
}
