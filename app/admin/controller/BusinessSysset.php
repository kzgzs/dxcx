<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\service\PlogService;
use app\common\service\ScopeService;
use think\facade\Db;

/**
 * 租户后台 - 多商户系统设置（批次8 任务221）
 *
 * 对标参考系统 Business.php sysset()（1035-1075 行）：
 * - business_sysset 单行（aid），无记录时自动初始化空行
 * - 保存按白名单字段过滤；wxfw 证书 PEM 后缀校验
 * - 关闭多商户分销设置（commission_canset 1→0）时重置商户商品分销配置
 */
class BusinessSysset extends BaseController
{
    /** 允许保存的字段白名单（business_sysset 现有列，排除 id/aid） */
    private const ALLOW_FIELDS = [
        'default_rate', 'withdraw_weixin', 'withdraw_aliaccount', 'withdraw_bankcard',
        'withdrawmin', 'withdrawfee', 'product_isshow', 'commission_canset',
        'coupon_canset', 'commission_kouchu', 'scoredk_kouchu', 'leveldk_kouchu',
        'product_showset', 'xieyi_show', 'xieyi', 'seckill_canset', 'collage_canset',
        'diy_canset', 'product_check', 'article_check', 'shortvideo_check', 'status',
        'commission_autotransfer', 'wxfw_status', 'wxfw_mchname', 'wxfw_appid',
        'wxfw_mchid', 'wxfw_mchkey', 'wxfw_apiclient_cert', 'wxfw_apiclient_key',
        'wxfw2_mchname', 'wxfw2_mchid', 'moneypay', 'scorein_money', 'scorein_score',
        'parentcommission', 'show_product', 'show_comment', 'show_detail', 'show_link',
        'show_linktext', 'show_producttext', 'show_commenttext', 'show_detailtext',
        'business_useplatmendian', 'business_useplatshopparam', 'business_selfscore',
        'business_selfscore_minus', 'business_selfscore2', 'business_cashdesk_wxpay_type',
        'business_cashdesk_yue', 'business_cashdesk_cashpay', 'business_cashdesk_alipay_type',
        'business_cashdesk_sxpay_type', 'show_shopdetail_menu', 'weixin_withdraw_max',
        'alipay_withdraw_max', 'platform_fee_type',
    ];

    /**
     * 读取系统设置（无记录自动初始化空行，参考系统 sysset 同口径）
     * GET /admin/businessSysset/get
     */
    public function get()
    {
        $aid = ScopeService::aidOf($this->request);

        $info = ScopeService::query('business_sysset', $aid)->find();
        if (empty($info)) {
            Db::name('business_sysset')->insert(['aid' => $aid]);
            $info = ScopeService::query('business_sysset', $aid)->find();
        }

        return $this->success($info);
    }

    /**
     * 保存系统设置（白名单过滤 + PEM 校验 + 关闭分销重置）
     * POST /admin/businessSysset/save  {info:{...}}
     */
    public function save()
    {
        $aid = ScopeService::aidOf($this->request);
        $uid = (int) ($this->request->authUid ?? 0);

        $input = (array) $this->request->post('info/a', []);
        $info  = array_intersect_key($input, array_flip(self::ALLOW_FIELDS));
        if (empty($info)) {
            return $this->error('没有可保存的配置项');
        }

        // 证书 PEM 后缀校验（参考系统 sysset 同口径）
        $cert = trim((string) ($info['wxfw_apiclient_cert'] ?? ''));
        if ($cert !== '' && substr($cert, -4) !== '.pem') {
            return $this->error('PEM证书格式错误');
        }
        $key = trim((string) ($info['wxfw_apiclient_key'] ?? ''));
        if ($key !== '' && substr($key, -4) !== '.pem') {
            return $this->error('证书密钥格式错误');
        }

        $rs = ScopeService::query('business_sysset', $aid)->find();
        if ($rs) {
            // 关闭多商户分销设置时，重置商户商品的分销配置（参考系统同口径）
            if ((int) $rs['commission_canset'] === 1 && (int) ($info['commission_canset'] ?? 1) === 0) {
                Db::name('shop_product')->where('aid', $aid)->where('bid', '>', 0)->update(['commissionset' => -1]);
            }
            ScopeService::query('business_sysset', $aid)->update($info);
            PlogService::write($aid, $uid, '多商户系统设置');
        } else {
            $info['aid'] = $aid;
            Db::name('business_sysset')->insert($info);
            PlogService::write($aid, $uid, '多商户系统设置');
        }

        return $this->success([], '设置成功');
    }
}
