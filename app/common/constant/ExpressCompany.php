<?php

declare(strict_types=1);

namespace app\common\constant;

/**
 * 快递公司字典（公司名 => 标准编码）
 *
 * 内容移植自参考系统 app/common.php express_data()，一次性落地本项目，
 * 运行时不依赖参考目录。供租户「快递设置」候选列表与编码回填使用。
 */
class ExpressCompany
{
    /**
     * 内置快递公司字典（顺序即候选列表展示顺序）
     *
     * @return array<string, string>
     */
    public static function dict(): array
    {
        return [
            '申通快递'   => 'STO',
            'EMS'        => 'EMS',
            '顺丰速运'   => 'SFEXPRESS',
            '圆通速递'   => 'YTO',
            '中通快递'   => 'ZTO',
            '韵达快递'   => 'YUNDA',
            '天天快递'   => 'TTKDEX',
            '百世快递'   => 'HTKY',
            '全峰快递'   => 'QFKD',
            '德邦快递'   => 'DEPPON',
            '宅急送'     => 'ZJS',
            '如风达'     => 'RFD',
            '安信达'     => 'ANXINDA',
            '邦送物流'   => '',
            'DHL快递'    => 'DHL',
            '大田物流'   => 'DTW',
            'EMS国际'    => '',
            '国通快递'   => 'GTO',
            '共速达'     => 'GSD',
            '华宇物流'   => 'HOAU',
            '佳吉快运'   => 'JIAJI',
            '佳怡物流'   => 'JIAYI',
            '快捷快递'   => 'FASTEXPRESS',
            '龙邦速递'   => 'LBEX',
            '联邦快递'   => 'FEDEX',
            '联昊通'     => 'LTS',
            '能达速递'   => 'ND56',
            '全一快递'   => 'APEX',
            '全日通'     => 'QRT',
            '速尔快递'   => 'SURE',
            'TNT快递'    => 'TNT',
            '天地华宇'   => 'HOAU',
            '新邦物流'   => 'XBWL',
            '新蛋物流'   => '',
            '优速快递'   => 'UC56',
            '中邮物流'   => 'ZYWL',
            '安能物流'   => 'ANE',
            '安能快递'   => 'ANEEX',
            '品骏快递'   => 'PJKD',
            '极兔快递'   => 'JITU',
            '京东'       => 'JD',
            '丹鸟快递'   => 'DANNIAO',
            '信丰'       => 'XFEXPRESS',
            '壹米滴答'   => 'YIMIDIDA',
            '百世快运'   => 'BSKY',
            '中通快运'   => 'ZTO56',
            '日日顺物流' => 'RRS',
            '商家配送'   => '',
            '其他'       => '',
        ];
    }

    /**
     * 按公司名取标准编码（不存在返回空串）
     */
    public static function codeOf(string $name): string
    {
        return self::dict()[$name] ?? '';
    }
}
