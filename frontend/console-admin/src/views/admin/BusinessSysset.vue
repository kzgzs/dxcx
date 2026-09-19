<script setup lang="ts">
/**
 * 商户 - 多商户系统设置（business_sysset 单行）
 * - GET businessSysset/get 回显（无记录后端自动初始化空行）
 * - POST businessSysset/save {info:{...}}（后端 58 列白名单过滤，PEM 后缀校验）
 * - 严格对齐参考系统「商户-系统设置」三部分：
 *   一、基础/返款规则/审核/微信服务商凭证（含 PEM 上传）
 *   二、提现设置 / 显示设置
 *   三、多商户收银台设置 / 入驻协议（富文本）
 * - 参考系统中由付费插件(getcustom)控制、本项目未启用的字段不在 UI 渲染，
 *   但仍在 form 中原样回显并随保存提交，避免把库内既有配置清 0。
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { UploadRequestOptions } from 'element-plus'
import SimpleRichEditor from '../../components/SimpleRichEditor.vue'
import {
  getBusinessSysset,
  saveBusinessSysset,
  uploadBusinessCert,
  type BusinessSyssetInfo,
} from '../../api/business'

const loading = ref(false)
const saving = ref(false)
const certUploading = ref(false)
const keyUploading = ref(false)

interface SyssetForm {
  // ===== 第一部分：基础 / 规则 / 审核 / 服务商 =====
  status: number
  default_rate: number | string
  platform_fee_type: number
  commission_canset: number
  commission_kouchu: number
  scoredk_kouchu: number
  leveldk_kouchu: number
  product_showset: number
  product_check: number
  article_check: number
  shortvideo_check: number
  wxfw_status: number
  wxfw_mchname: string
  wxfw_appid: string
  wxfw_mchid: string
  wxfw_mchkey: string
  wxfw_apiclient_cert: string
  wxfw_apiclient_key: string
  // ===== 第二部分：提现设置 =====
  withdraw_weixin: number
  withdraw_aliaccount: number
  withdraw_bankcard: number
  commission_autotransfer: number
  weixin_withdraw_max: number | string
  alipay_withdraw_max: number | string
  withdrawmin: number | string
  withdrawfee: number | string
  // ===== 第二部分：显示设置 =====
  product_isshow: number
  show_link: number
  show_linktext: string
  show_product: number
  show_producttext: string
  show_comment: number
  show_commenttext: string
  show_detail: number
  show_detailtext: string
  show_shopdetail_menu: number
  // ===== 第三部分：收银台 / 入驻协议 =====
  business_cashdesk_wxpay_type: number
  business_cashdesk_yue: number
  business_cashdesk_cashpay: number
  xieyi_show: number
  xieyi: string
  // ===== 参考系统插件项 / 本页不渲染但保留回写，避免清 0 =====
  coupon_canset: number
  seckill_canset: number
  collage_canset: number
  diy_canset: number
  parentcommission: number | string
  wxfw2_mchname: string
  wxfw2_mchid: string
  moneypay: number
  scorein_money: number | string
  scorein_score: number
  business_useplatmendian: number
  business_useplatshopparam: number
  business_selfscore: number
  business_selfscore_minus: number
  business_selfscore2: number
  business_cashdesk_alipay_type: number
  business_cashdesk_sxpay_type: number
}

const form = reactive<SyssetForm>({
  status: 0,
  default_rate: 0,
  platform_fee_type: 0,
  commission_canset: 0,
  commission_kouchu: 0,
  scoredk_kouchu: 0,
  leveldk_kouchu: 0,
  product_showset: 0,
  product_check: 0,
  article_check: 0,
  shortvideo_check: 0,
  wxfw_status: 0,
  wxfw_mchname: '',
  wxfw_appid: '',
  wxfw_mchid: '',
  wxfw_mchkey: '',
  wxfw_apiclient_cert: '',
  wxfw_apiclient_key: '',
  withdraw_weixin: 1,
  withdraw_aliaccount: 1,
  withdraw_bankcard: 1,
  commission_autotransfer: 0,
  weixin_withdraw_max: 0,
  alipay_withdraw_max: 0,
  withdrawmin: 0,
  withdrawfee: 0,
  product_isshow: 0,
  show_link: 1,
  show_linktext: '',
  show_product: 1,
  show_producttext: '',
  show_comment: 1,
  show_commenttext: '',
  show_detail: 1,
  show_detailtext: '',
  show_shopdetail_menu: 0,
  business_cashdesk_wxpay_type: 2,
  business_cashdesk_yue: 1,
  business_cashdesk_cashpay: 1,
  xieyi_show: 0,
  xieyi: '',
  coupon_canset: 0,
  seckill_canset: 0,
  collage_canset: 0,
  diy_canset: 0,
  parentcommission: 0,
  wxfw2_mchname: '',
  wxfw2_mchid: '',
  moneypay: 0,
  scorein_money: 0,
  scorein_score: 0,
  business_useplatmendian: 0,
  business_useplatshopparam: 0,
  business_selfscore: 0,
  business_selfscore_minus: 1,
  business_selfscore2: 0,
  business_cashdesk_alipay_type: 2,
  business_cashdesk_sxpay_type: 2,
})

function numOf(v: string | number | undefined | null): number {
  const n = Number(v ?? 0)
  return Number.isFinite(n) ? n : 0
}

async function fetchInfo() {
  loading.value = true
  try {
    const info: BusinessSyssetInfo = await getBusinessSysset()
    // 数值型字段统一转 number 供 radio/checkbox 绑定
    const numericKeys = [
      'status', 'platform_fee_type',
      'commission_canset', 'commission_kouchu', 'scoredk_kouchu', 'leveldk_kouchu',
      'product_showset', 'product_check', 'article_check', 'shortvideo_check', 'wxfw_status',
      'withdraw_weixin', 'withdraw_aliaccount', 'withdraw_bankcard', 'commission_autotransfer',
      'product_isshow', 'show_link', 'show_product', 'show_comment', 'show_detail',
      'show_shopdetail_menu', 'business_cashdesk_wxpay_type', 'business_cashdesk_yue',
      'business_cashdesk_cashpay', 'xieyi_show', 'scorein_score', 'moneypay',
      'coupon_canset', 'seckill_canset', 'collage_canset', 'diy_canset',
      'business_useplatmendian', 'business_useplatshopparam', 'business_selfscore',
      'business_selfscore_minus', 'business_selfscore2', 'business_cashdesk_alipay_type',
      'business_cashdesk_sxpay_type',
    ] as const
    for (const key of numericKeys) {
      ;(form as unknown as Record<string, number>)[key] = numOf(info[key])
    }
    // 数值/小数输入框字段（保留字符串形态供输入，decimal 列可直接接收）
    const decimalKeys = [
      'default_rate', 'weixin_withdraw_max', 'alipay_withdraw_max', 'withdrawmin',
      'withdrawfee', 'parentcommission', 'scorein_money',
    ] as const
    for (const key of decimalKeys) {
      ;(form as unknown as Record<string, string | number>)[key] =
        (info[key] ?? '') as string | number
    }
    // 字符串字段
    form.wxfw_mchname = String(info.wxfw_mchname ?? '')
    form.wxfw_appid = String(info.wxfw_appid ?? '')
    form.wxfw_mchid = String(info.wxfw_mchid ?? '')
    form.wxfw_mchkey = String(info.wxfw_mchkey ?? '')
    form.wxfw_apiclient_cert = String(info.wxfw_apiclient_cert ?? '')
    form.wxfw_apiclient_key = String(info.wxfw_apiclient_key ?? '')
    form.wxfw2_mchname = String(info.wxfw2_mchname ?? '')
    form.wxfw2_mchid = String(info.wxfw2_mchid ?? '')
    form.xieyi = String(info.xieyi ?? '')
    // 首页栏目文案：空时回显默认文案（与参考系统一致）
    form.show_linktext = String(info.show_linktext ?? '') || '联系商家'
    form.show_producttext = String(info.show_producttext ?? '') || '本店商品'
    form.show_commenttext = String(info.show_commenttext ?? '') || '店铺评价'
    form.show_detailtext = String(info.show_detailtext ?? '') || '商家详情'
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

/** 提现方式 checkbox：独立 0/1 列与勾选态互转 */
function toggleWithdraw(key: 'withdraw_weixin' | 'withdraw_aliaccount' | 'withdraw_bankcard', checked: unknown) {
  form[key] = checked ? 1 : 0
}

/** 证书/密钥上传（.pem/.key），成功后把站点相对路径写入对应字段 */
async function handleCertUpload(options: UploadRequestOptions, key: 'wxfw_apiclient_cert' | 'wxfw_apiclient_key') {
  const uploading = key === 'wxfw_apiclient_cert' ? certUploading : keyUploading
  uploading.value = true
  try {
    const res = await uploadBusinessCert(options.file as File)
    form[key] = res.url
    ElMessage.success('上传成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    uploading.value = false
  }
}

function basename(url: string): string {
  if (!url) return ''
  return url.split('/').pop() || url
}

async function handleSave() {
  saving.value = true
  try {
    await saveBusinessSysset({ ...form })
    ElMessage.success('设置成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchInfo()
})
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <div class="sysset-header"><span>多商户系统设置</span></div>
      </template>

      <el-form label-width="170px" label-position="left" class="bs-form">
        <!-- ==================== 第一部分 ==================== -->
        <el-form-item label="多商户开关：">
          <div class="field">
            <el-radio-group v-model="form.status">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
          </div>
        </el-form-item>

        <el-form-item label="商家默认佣金：">
          <div class="field">
            <div class="ctrl-line">
              <el-input v-model="form.default_rate" class="w120" />
              <span class="unit">%</span>
            </div>
          </div>
        </el-form-item>

        <el-form-item label="商家退款佣金计算规则：">
          <div class="field">
            <el-radio-group v-model="form.platform_fee_type">
              <el-radio :value="1">先计算</el-radio>
              <el-radio :value="0">后计算</el-radio>
            </el-radio-group>
            <div class="tip">先计算：平台费率=订单金额*费率；后计算：平台费率=（订单金额-退款金额-其他扣除部分）*费率</div>
          </div>
        </el-form-item>

        <el-form-item label="商家可设置商品分销：">
          <div class="field">
            <el-radio-group v-model="form.commission_canset">
              <el-radio :value="0">关闭</el-radio>
              <el-radio :value="1">开启</el-radio>
            </el-radio-group>
            <div class="tip">商家编辑商品时是否可以设置分销，关闭后商家的商品不参与分销，并清除所有商家商品分销设置</div>
          </div>
        </el-form-item>

        <el-form-item label="商家退款扣除分销佣金：">
          <div class="field">
            <el-radio-group v-model="form.commission_kouchu">
              <el-radio :value="0">不扣</el-radio>
              <el-radio :value="1">扣除</el-radio>
            </el-radio-group>
            <div class="tip">
              扣除后，平台向商家结算货款时，将扣除订单内产生的分销佣金，剩余金额结算给商家；选择“不扣”，则按订单原货款金额返款。<br />
              例：订单金额100元，分销佣金10元；选择“扣除”商家得90元；选择“不扣”则商家得100元。
            </div>
          </div>
        </el-form-item>

        <el-form-item label="商家退款扣除积分抵扣：">
          <div class="field">
            <el-radio-group v-model="form.scoredk_kouchu">
              <el-radio :value="0">不扣</el-radio>
              <el-radio :value="1">扣除</el-radio>
            </el-radio-group>
            <div class="tip">
              扣除后，平台向商家结算货款时，将扣除会员下单时使用积分抵扣的金额，剩余金额结算给商家；选择“不扣”，则平台仍按订单原货款金额返款给商家。<br />
              例：订单金额100元，积分抵扣10元，会员实付90元；选择“扣除”商家得90元；选择“不扣”则商家得100元
            </div>
          </div>
        </el-form-item>

        <el-form-item label="商家退款扣除会员折扣：">
          <div class="field">
            <el-radio-group v-model="form.leveldk_kouchu">
              <el-radio :value="0">不扣</el-radio>
              <el-radio :value="1">扣除</el-radio>
            </el-radio-group>
            <div class="tip">
              扣除后，平台向商家结算货款时，会扣除会员折扣所减免的金额后剩余金额结算给商家；选择“不扣”，平台仍按订单原货款金额返款给商家。<br />
              例：订单金额100元，会员折扣9折，实付90元；选择“扣除”商家得90元；选择“不扣”则商家得100元
            </div>
          </div>
        </el-form-item>

        <el-form-item label="会员价显示条件：">
          <div class="field">
            <el-radio-group v-model="form.product_showset">
              <el-radio :value="0">关闭</el-radio>
              <el-radio :value="1">开启</el-radio>
            </el-radio-group>
            <div class="tip">开启后，商家在编辑商品时可设置会员价、显示条件及购买条件。</div>
          </div>
        </el-form-item>

        <el-form-item label="商家商品审核：">
          <div class="field">
            <el-radio-group v-model="form.product_check">
              <el-radio :value="0">关闭</el-radio>
              <el-radio :value="1">开启</el-radio>
            </el-radio-group>
            <div class="tip">开启后，商家上传的商品需经平台审核方可上架。</div>
          </div>
        </el-form-item>

        <el-form-item label="商家文章审核：">
          <div class="field">
            <el-radio-group v-model="form.article_check">
              <el-radio :value="0">关闭</el-radio>
              <el-radio :value="1">开启</el-radio>
            </el-radio-group>
            <div class="tip">开启后，商家发布的文章需经平台审核后方可显示。</div>
          </div>
        </el-form-item>

        <el-form-item label="商家短视频审核：">
          <div class="field">
            <el-radio-group v-model="form.shortvideo_check">
              <el-radio :value="0">关闭</el-radio>
              <el-radio :value="1">开启</el-radio>
            </el-radio-group>
            <div class="tip">开启后，商家上传的短视频需经平台审核后方可显示。</div>
          </div>
        </el-form-item>

        <el-form-item label="微信服务商：">
          <div class="field">
            <el-radio-group v-model="form.wxfw_status">
              <el-radio :value="0">关闭</el-radio>
              <el-radio :value="1">开启</el-radio>
            </el-radio-group>
            <div class="tip">开启后，可为入驻商户配置微信支付子商户号，实现独立收款与结算。</div>
          </div>
        </el-form-item>

        <!-- 微信服务商凭证：仅开启时显示（对齐参考系统 wxfw_div 联动） -->
        <template v-if="form.wxfw_status === 1">
          <el-form-item label="服务商名称：">
            <div class="field">
              <el-input v-model="form.wxfw_mchname" class="w320" placeholder="请输入服务商名称" />
              <div class="tip">服务商的商户名称，在服务商后台[账户中心]-[商户信息]中查找</div>
            </div>
          </el-form-item>

          <el-form-item label="服务商AppID：">
            <div class="field">
              <el-input v-model="form.wxfw_appid" class="w320" placeholder="请输入服务商 AppID" />
              <div class="tip">服务商公众号开发者ID，请在微信公众平台[设置与开发-账号设置-账号详情-注册信息]中查找AppID</div>
            </div>
          </el-form-item>

          <el-form-item label="支付商户号：">
            <div class="field">
              <el-input v-model="form.wxfw_mchid" class="w320" placeholder="请输入支付商户号" />
              <div class="tip">服务商微信支付商户号</div>
            </div>
          </el-form-item>

          <el-form-item label="支付密钥：">
            <div class="field">
              <el-input v-model="form.wxfw_mchkey" class="w320" placeholder="请输入 APIv2 密钥" />
              <div class="tip">请在微信支付商户平台[账户中心]-[API安全]中设置[APIv2密钥]</div>
            </div>
          </el-form-item>

          <el-form-item label="PEM证书：">
            <div class="field">
              <div class="ctrl-line">
                <el-input v-model="form.wxfw_apiclient_cert" class="w420" readonly placeholder="请上传 apiclient_cert.pem" />
                <el-upload
                  :show-file-list="false"
                  :http-request="(opt: UploadRequestOptions) => handleCertUpload(opt, 'wxfw_apiclient_cert')"
                  accept=".pem,.key"
                  class="cert-upload"
                >
                  <el-button :loading="certUploading">上传</el-button>
                </el-upload>
                <span v-if="form.wxfw_apiclient_cert" class="uploaded">已上传 {{ basename(form.wxfw_apiclient_cert) }}</span>
              </div>
              <div class="tip">apiclient_cert.pem，请在微信支付商户平台[账户中心]-[API安全]中设置[API证书]</div>
            </div>
          </el-form-item>

          <el-form-item label="证书密钥：">
            <div class="field">
              <div class="ctrl-line">
                <el-input v-model="form.wxfw_apiclient_key" class="w420" readonly placeholder="请上传 apiclient_key.pem" />
                <el-upload
                  :show-file-list="false"
                  :http-request="(opt: UploadRequestOptions) => handleCertUpload(opt, 'wxfw_apiclient_key')"
                  accept=".pem,.key"
                  class="cert-upload"
                >
                  <el-button :loading="keyUploading">上传</el-button>
                </el-upload>
                <span v-if="form.wxfw_apiclient_key" class="uploaded">已上传 {{ basename(form.wxfw_apiclient_key) }}</span>
              </div>
              <div class="tip">apiclient_key.pem，请在微信支付商户平台[账户中心]-[API安全]中设置[API证书]</div>
            </div>
          </el-form-item>
        </template>

        <!-- ==================== 第二部分：提现设置 ==================== -->
        <el-divider content-position="left">提现设置</el-divider>

        <el-form-item label="商家提现方式：">
          <div class="field">
            <el-checkbox
              :model-value="form.withdraw_weixin === 1"
              @change="toggleWithdraw('withdraw_weixin', $event)"
            >微信钱包</el-checkbox>
            <el-checkbox
              :model-value="form.withdraw_aliaccount === 1"
              @change="toggleWithdraw('withdraw_aliaccount', $event)"
            >支付宝</el-checkbox>
            <el-checkbox
              :model-value="form.withdraw_bankcard === 1"
              @change="toggleWithdraw('withdraw_bankcard', $event)"
            >银行卡</el-checkbox>
          </div>
        </el-form-item>

        <el-form-item label="自动打款：">
          <div class="field">
            <el-radio-group v-model="form.commission_autotransfer">
              <el-radio :value="0">关闭</el-radio>
              <el-radio :value="1">开启</el-radio>
            </el-radio-group>
            <div class="tip tip-danger">注意：谨慎开启自动打款，开启自动打款提现资金风险不可控制，建议审核并明确用户资金来源后手动打款。</div>
            <div class="tip">开启后提现到微信钱包时不需要审核，自动用企业付款打款，需开通企业付款到零钱功能</div>
          </div>
        </el-form-item>

        <el-form-item label="微信限额：">
          <div class="field">
            <div class="ctrl-line">
              <el-input v-model="form.weixin_withdraw_max" class="w120" />
              <span class="unit">元</span>
            </div>
            <div class="tip">使用微信提现自动打款，提现金额超出限额后无法提交，大于0生效</div>
          </div>
        </el-form-item>

        <el-form-item label="支付宝限额：">
          <div class="field">
            <div class="ctrl-line">
              <el-input v-model="form.alipay_withdraw_max" class="w120" />
              <span class="unit">元</span>
            </div>
            <div class="tip">使用微信提现自动打款，提现金额超出限额后无法提交，大于0生效</div>
          </div>
        </el-form-item>

        <el-form-item label="提现最小金额：">
          <div class="field">
            <div class="ctrl-line">
              <el-input v-model="form.withdrawmin" class="w120" />
              <span class="unit">元</span>
            </div>
          </div>
        </el-form-item>

        <el-form-item label="提现手续费：">
          <div class="field">
            <div class="ctrl-line">
              <el-input v-model="form.withdrawfee" class="w120" />
              <span class="unit">%</span>
            </div>
          </div>
        </el-form-item>

        <!-- ==================== 第二部分：显示设置 ==================== -->
        <el-divider content-position="left">显示设置</el-divider>

        <el-form-item label="主商城显示商家的商品：">
          <div class="field">
            <el-radio-group v-model="form.product_isshow">
              <el-radio :value="0">隐藏</el-radio>
              <el-radio :value="1">显示</el-radio>
            </el-radio-group>
            <div class="tip">主商城中的商品列表及分类商品页面是否显示商家的商品</div>
          </div>
        </el-form-item>

        <el-form-item label="首页显示联系商家：">
          <div class="field">
            <div class="ctrl-line">
              <el-radio-group v-model="form.show_link">
                <el-radio :value="0">隐藏</el-radio>
                <el-radio :value="1">显示</el-radio>
              </el-radio-group>
              <el-input v-model="form.show_linktext" class="w110" maxlength="30" />
            </div>
          </div>
        </el-form-item>

        <el-form-item label="首页显示本店商品：">
          <div class="field">
            <div class="ctrl-line">
              <el-radio-group v-model="form.show_product">
                <el-radio :value="0">隐藏</el-radio>
                <el-radio :value="1">显示</el-radio>
              </el-radio-group>
              <el-input v-model="form.show_producttext" class="w110" maxlength="30" />
            </div>
          </div>
        </el-form-item>

        <el-form-item label="首页显示店铺评价：">
          <div class="field">
            <div class="ctrl-line">
              <el-radio-group v-model="form.show_comment">
                <el-radio :value="0">隐藏</el-radio>
                <el-radio :value="1">显示</el-radio>
              </el-radio-group>
              <el-input v-model="form.show_commenttext" class="w110" maxlength="30" />
            </div>
          </div>
        </el-form-item>

        <el-form-item label="首页显示商家详情：">
          <div class="field">
            <div class="ctrl-line">
              <el-radio-group v-model="form.show_detail">
                <el-radio :value="0">隐藏</el-radio>
                <el-radio :value="1">显示</el-radio>
              </el-radio-group>
              <el-input v-model="form.show_detailtext" class="w110" maxlength="30" />
            </div>
          </div>
        </el-form-item>

        <el-form-item label="自定义商品详情导航：">
          <div class="field">
            <el-radio-group v-model="form.show_shopdetail_menu">
              <el-radio :value="0">关闭</el-radio>
              <el-radio :value="1">开启</el-radio>
            </el-radio-group>
            <div class="tip">开启后商家可自定义商品详情导航</div>
          </div>
        </el-form-item>

        <!-- ==================== 第三部分：多商户收银台设置 ==================== -->
        <el-divider content-position="left">多商户收银台设置</el-divider>

        <el-form-item label="多商户微信收款：">
          <div class="field">
            <el-radio-group v-model="form.business_cashdesk_wxpay_type">
              <el-radio :value="1">服务商</el-radio>
              <el-radio :value="2">平台收款</el-radio>
              <el-radio :value="3">独立收款</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
            <div class="tip">
              1、平台收款：请先在[扩展]-[收银台]-[收银台设置]中配置微信支付。2、独立收款：平台不抽成，佣金不发放。3、切换类型影响订单退款。4、开启[商户]-[系统设置]-[微信服务商]才能切换
            </div>
          </div>
        </el-form-item>

        <el-form-item label="多商户余额收款：">
          <div class="field">
            <el-radio-group v-model="form.business_cashdesk_yue">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
            <div class="tip">开启后商户可使用该方式收款</div>
          </div>
        </el-form-item>

        <el-form-item label="多商户现金收款：">
          <div class="field">
            <el-radio-group v-model="form.business_cashdesk_cashpay">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
            <div class="tip">开启后商户可使用该方式收款</div>
          </div>
        </el-form-item>

        <!-- ==================== 第三部分：入驻协议 ==================== -->
        <el-divider content-position="left">入驻协议</el-divider>

        <el-form-item label="商户入驻协议：">
          <div class="field">
            <el-radio-group v-model="form.xieyi_show">
              <el-radio :value="0">关闭</el-radio>
              <el-radio :value="1">开启</el-radio>
            </el-radio-group>
          </div>
        </el-form-item>

        <el-form-item label="入驻协议内容：">
          <div class="field rich-field">
            <SimpleRichEditor v-model="form.xieyi" />
          </div>
        </el-form-item>

        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.sysset-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 600;
}

.bs-form {
  max-width: 1000px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex: 1;
}

.ctrl-line {
  display: flex;
  align-items: center;
  gap: 8px;
}

.ctrl-line :deep(.el-radio-group) {
  margin-right: 4px;
}

.tip {
  font-size: 12px;
  line-height: 1.7;
  color: #909399;
}

.tip-danger {
  color: #f56c6c;
}

.unit {
  font-size: 13px;
  color: #606266;
}

.uploaded {
  font-size: 12px;
  color: #67c23a;
}

.w110 {
  width: 110px;
}

.w120 {
  width: 120px;
}

.w320 {
  width: 320px;
}

.w420 {
  width: 420px;
}

.cert-upload {
  margin: 0;
}

.rich-field {
  width: 760px;
  max-width: 100%;
}

.bs-form :deep(.el-divider__text) {
  font-weight: 600;
  color: #303133;
}
</style>
