<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { UploadRequestOptions } from 'element-plus'
import {
  getBindingOverview,
  getBindingConfig,
  saveBindingConfig,
  getWxAuthUrl,
  refreshBindingAuth,
  downloadCodePackage,
  type PlatformKey,
  type BindingOverview,
  type BindingConfig,
} from '../../api/binding'
import { uploadFile } from '../../api/goods'
import { uploadBusinessCert } from '../../api/business'

// ============ 字段定义（与后端白名单一致） ============

interface FieldDef {
  key: string
  label: string
  secret?: boolean
  textarea?: boolean
  switch?: boolean
  image?: boolean
  pem?: boolean
  pemName?: string
  scene?: boolean
  staticHelp?: string
  radio?: { value: number; label: string }[]
  placeholder?: string
  help?: string
  helpLink?: { text: string; url: string }
  group?: string
  readonly?: boolean
  static?: string
}

// 通用单选项（对齐点大演示站）
const RADIO_ONOFF = [
  { value: 1, label: '开启' },
  { value: 0, label: '关闭' },
]
const RADIO_WXPAY_MODE = [
  { value: 0, label: '普通模式' },
  { value: 1, label: '服务商模式' },
]
const RADIO_ALIPAY_MODE = [
  { value: 0, label: '普通支付' },
  { value: 1, label: '直付通支付' },
]
const RADIO_OPENID = [
  { value: 0, label: 'uid标准' },
  { value: 1, label: 'openid标准' },
]
const RADIO_SIGN = [
  { value: 0, label: '平台证书' },
  { value: 1, label: '微信支付公钥' },
]
const WXPAY_MCHKEY_HELP = '请在微信支付商户平台[账户中心]-[API安全]中设置[APIv2密钥]'
const WXPAY_CERT_HELP = '请在微信支付商户平台[账户中心]-[API安全]中设置[API证书]，设置完成后上传'
const WXPAY_MCH_LINK = { text: '微信支付商户平台', url: 'https://pay.weixin.qq.com/' }

const FIELD_DEFS: Record<PlatformKey, FieldDef[]> = {
  mp: [
    { key: 'appid', label: 'AppID', placeholder: '公众号 AppID（gh 开头的原始 ID 无效）', group: '基础配置' },
    { key: 'appsecret', label: 'AppSecret', secret: true, group: '基础配置' },
    { key: 'token', label: 'Token', group: '基础配置' },
    { key: 'key', label: '消息加解密密钥（EncodingAESKey）', group: '基础配置' },
    { key: 'nickname', label: '公众号名称', group: '展示信息' },
    { key: 'headimg', label: '头像 URL', group: '展示信息' },
    { key: 'qrcode', label: '二维码 URL', group: '展示信息' },
    { key: 'open_appid', label: '开放平台 AppID', group: '展示信息' },
    { key: 'wxpay', label: '微信支付', switch: true, group: '支付配置' },
    { key: 'wxpay_type', label: '支付商户类型', placeholder: '0 普通商户 / 1 服务商', group: '支付配置' },
    { key: 'wxpay_mchid', label: '商户号 MCHID', group: '支付配置' },
    { key: 'wxpay_mchkey', label: '商户密钥 API Key', secret: true, group: '支付配置' },
    { key: 'wxpay_sub_mchid', label: '子商户号（服务商模式）', group: '支付配置' },
    { key: 'wxpay_serial_no', label: 'API 证书序列号', group: '支付配置' },
    { key: 'wxpay_apiclient_cert', label: 'apiclient_cert.pem 内容', secret: true, textarea: true, group: '支付配置' },
    { key: 'wxpay_apiclient_key', label: 'apiclient_key.pem 内容', secret: true, textarea: true, group: '支付配置' },
    { key: 'wxpay_mchkey_v3', label: 'APIv3 密钥', secret: true, group: '支付配置' },
    { key: 'wxpay_wechatpay_pem', label: '平台证书 wechatpay.pem', textarea: true, group: '支付配置' },
    { key: 'sign_type', label: '签名类型', placeholder: 'RSA / SM2', group: '支付配置' },
    { key: 'public_key_id', label: '公钥 ID', group: '支付配置' },
    { key: 'public_key_pem', label: '公钥 PEM', textarea: true, group: '支付配置' },
    { key: 'wxpay_plate_serialno', label: '车牌付序列号', group: '支付配置' },
    { key: 'transfer_scene_id', label: '转账场景 ID', group: '其他配置' },
    { key: 'transfer_scene_type', label: '转账场景类型', group: '其他配置' },
    { key: 'transfer_scene_content', label: '转账场景内容', group: '其他配置' },
    { key: 'sxpay_mno', label: '随行付商户编号', group: '其他配置' },
    { key: 'sxpay_mchkey', label: '随行付密钥', secret: true, group: '其他配置' },
  ],
  wx: [
    { key: 'appid', label: 'AppID', placeholder: '小程序 AppID（wx 开头）', group: '基础配置' },
    { key: 'appsecret', label: 'AppSecret', secret: true, group: '基础配置' },
    { key: 'token', label: '消息推送 Token', group: '基础配置' },
    { key: 'key', label: '消息加解密密钥（EncodingAESKey）', group: '基础配置' },
    { key: 'nickname', label: '小程序名称', group: '展示信息' },
    { key: 'headimg', label: '头像 URL', group: '展示信息' },
    { key: 'qrcode', label: '小程序码 URL', group: '展示信息' },
    { key: 'open_appid', label: '开放平台 AppID', group: '展示信息' },
    { key: 'signature', label: '功能介绍', group: '展示信息' },
    { key: 'wxpay', label: '微信支付', switch: true, group: '支付配置' },
    { key: 'wxpay_type', label: '支付商户类型', placeholder: '0 普通商户 / 1 服务商', group: '支付配置' },
    { key: 'wxpay_mchid', label: '商户号 MCHID', group: '支付配置' },
    { key: 'wxpay_mchkey', label: '商户密钥 API Key', secret: true, group: '支付配置' },
    { key: 'wxpay_sub_mchid', label: '子商户号（服务商模式）', group: '支付配置' },
    { key: 'wxpay_sub_mchid2', label: '子商户号 2', group: '支付配置' },
    { key: 'wxpay_serial_no', label: 'API 证书序列号', group: '支付配置' },
    { key: 'wxpay_apiclient_cert', label: 'apiclient_cert.pem 内容', secret: true, textarea: true, group: '支付配置' },
    { key: 'wxpay_apiclient_key', label: 'apiclient_key.pem 内容', secret: true, textarea: true, group: '支付配置' },
    { key: 'wxpay_mchkey_v3', label: 'APIv3 密钥', secret: true, group: '支付配置' },
    { key: 'wxpay_wechatpay_pem', label: '平台证书 wechatpay.pem', textarea: true, group: '支付配置' },
    { key: 'sign_type', label: '签名类型', placeholder: 'RSA / SM2', group: '支付配置' },
    { key: 'public_key_id', label: '公钥 ID', group: '支付配置' },
    { key: 'public_key_pem', label: '公钥 PEM', textarea: true, group: '支付配置' },
    { key: 'wxpay_plate_serialno', label: '车牌付序列号', group: '支付配置' },
    { key: 'transfer_scene_id', label: '转账场景 ID', group: '其他配置' },
    { key: 'transfer_scene_type', label: '转账场景类型', group: '其他配置' },
    { key: 'transfer_scene_content', label: '转账场景内容', group: '其他配置' },
    { key: 'sxpay_mno', label: '随行付商户编号', group: '其他配置' },
    { key: 'sxpay_mchkey', label: '随行付密钥', secret: true, group: '其他配置' },
    { key: 'sxpay_embedded', label: '随行付嵌入式分账', group: '其他配置' },
  ],
  alipay: [
    { key: 'appid', label: '小程序ID', group: '填写小程序信息', help: '登录支付宝开放平台控制台[小程序]中查找' },
    { key: 'appsecret', label: '应用私钥', secret: true, textarea: true, group: '填写小程序信息', help: '请填写应用私钥去头去尾去回车，一行字符串，', helpLink: { text: '接入指引', url: 'https://opendocs.alipay.com/common/02kipl' } },
    { key: 'publickey', label: '支付宝公钥', secret: true, textarea: true, group: '填写小程序信息', help: '请填写支付宝公钥，一行字符串' },
    { key: 'nickname', label: '小程序名称', group: '填写小程序信息' },
    { key: 'headimg', label: '小程序头像', image: true, group: '填写小程序信息' },
    { key: 'qrcode', label: '小程序码', image: true, group: '填写小程序信息' },
    { key: 'alipay', label: '小程序支付状态', radio: RADIO_ONOFF, group: '填写小程序信息' },
    { key: 'openid_set', label: 'openid配置', radio: RADIO_OPENID, group: '填写小程序信息', help: '登录开放平台控制台>进入对应应用详情页>开发设置>openid配置管理 查看并选择对应的选项', helpLink: { text: '开放平台控制台', url: 'https://open.alipay.com/develop/mini' } },
    { key: 'pay_mode', label: '支付模式', radio: RADIO_ALIPAY_MODE, group: '填写小程序信息' },
    { key: 'msid', label: '二级商户ID（直付通）', group: '填写小程序信息', placeholder: '直付通支付模式下填写' },
    { key: '_ip_white', label: 'IP白名单', static: 'ip', group: '设置IP白名单及域名', help: '[开发]-[开发设置]-[开发信息]-[服务器IP白名单]中设置' },
    { key: '_domain_white', label: '域名白名单', static: 'domain', group: '设置IP白名单及域名', help: '[开发]-[开发设置]-[服务器域名白名单]中设置' },
  ],
  baidu: [
    { key: 'appid', label: 'App ID', group: '基础信息', help: '登录百度小程序平台[管理]-[基础设置]-[开发设置]中查找', helpLink: { text: '百度小程序平台', url: 'https://smartprogram.baidu.com/developer/index.html' } },
    { key: 'appkey', label: 'App Key', secret: true, group: '基础信息', help: '登录百度小程序平台[管理]-[基础设置]-[开发设置]中查找', helpLink: { text: '百度小程序平台', url: 'https://smartprogram.baidu.com/developer/index.html' } },
    { key: 'appsecret', label: 'App Secret', secret: true, group: '基础信息', help: '登录百度小程序平台[管理]-[基础设置]-[开发设置]中查找', helpLink: { text: '百度小程序平台', url: 'https://smartprogram.baidu.com/developer/index.html' } },
    { key: 'nickname', label: '小程序名称', group: '基础信息' },
    { key: 'headimg', label: '小程序头像', image: true, group: '基础信息' },
    { key: 'qrcode', label: '小程序码', image: true, group: '基础信息' },
    { key: 'baidupay', label: '支付状态', radio: RADIO_ONOFF, group: '支付设置' },
    { key: 'pay_appid', label: 'APP ID', group: '支付设置', help: '登录百度小程序平台[管理]-[功能管理]-[支付设置]中查找', helpLink: { text: '百度小程序平台', url: 'https://smartprogram.baidu.com/developer/index.html' } },
    { key: 'pay_appkey', label: 'APP KEY', group: '支付设置', help: '登录百度小程序平台[管理]-[功能管理]-[支付设置]中查找', helpLink: { text: '百度小程序平台', url: 'https://smartprogram.baidu.com/developer/index.html' } },
    { key: 'pay_dealId', label: 'dealId', group: '支付设置', help: '登录百度小程序平台[管理]-[功能管理]-[支付设置]中查找', helpLink: { text: '百度小程序平台', url: 'https://smartprogram.baidu.com/developer/index.html' } },
    { key: 'pay_publickey', label: '平台公钥', secret: true, textarea: true, group: '支付设置', help: '登录百度小程序平台[管理]-[功能管理]-[支付设置]中查找', helpLink: { text: '百度小程序平台', url: 'https://smartprogram.baidu.com/developer/index.html' } },
    { key: 'pay_privatekey', label: 'RSA私钥', secret: true, textarea: true, group: '支付设置', help: '请填写RSA私钥，一行字符串，', helpLink: { text: '点击查看生成方法', url: 'https://smartprogram.baidu.com/docs/develop/function/PayAPI/' } },
    { key: '_pay_notify', label: '支付回调地址', static: 'notify', group: '支付设置', help: '登录百度小程序平台[管理]-[功能管理]-[支付设置]-[支付开发设置]中编辑', helpLink: { text: '百度小程序平台', url: 'https://smartprogram.baidu.com/developer/index.html' } },
  ],
  qq: [
    { key: 'appid', label: 'AppID', group: '基础信息' },
    { key: 'appsecret', label: 'AppSecret', secret: true, group: '基础信息' },
    { key: 'apptoken', label: 'AppToken', secret: true, group: '基础信息' },
    { key: 'nickname', label: '小程序名称', group: '基础信息' },
    { key: 'headimg', label: '小程序头像', image: true, group: '基础信息' },
    { key: 'qrcode', label: '小程序码', image: true, group: '基础信息' },
    { key: 'wxpay', label: '支付状态', radio: RADIO_ONOFF, group: '微信支付设置', staticHelp: 'jsapi' },
    { key: 'wxpay_appid', label: '支付APPID', group: '微信支付设置', help: '公众号的appid' },
    { key: 'wxpay_type', label: '微信支付模式', radio: RADIO_WXPAY_MODE, group: '微信支付设置' },
    { key: 'wxpay_mchid', label: '支付商户号', group: '微信支付设置' },
    { key: 'wxpay_mchkey', label: '支付密钥', secret: true, group: '微信支付设置', help: WXPAY_MCHKEY_HELP, helpLink: WXPAY_MCH_LINK },
    { key: 'wxpay_apiclient_cert', label: 'PEM证书', pem: true, pemName: 'apiclient_cert.pem', group: '微信支付设置', help: 'apiclient_cert.pem ' + WXPAY_CERT_HELP, helpLink: WXPAY_MCH_LINK },
    { key: 'wxpay_apiclient_key', label: '证书密钥', pem: true, pemName: 'apiclient_key.pem', group: '微信支付设置', help: 'apiclient_key.pem ' + WXPAY_CERT_HELP, helpLink: WXPAY_MCH_LINK },
    { key: '_pay_notify', label: '支付回调地址', static: 'notify', group: '微信支付设置' },
  ],
  toutiao: [
    { key: 'appid', label: 'AppID', group: '基础信息' },
    { key: 'appsecret', label: 'AppSecret', secret: true, group: '基础信息' },
    { key: 'nickname', label: '小程序名称', group: '基础信息' },
    { key: 'headimg', label: '小程序头像', image: true, group: '基础信息' },
    { key: 'qrcode', label: '小程序码', image: true, group: '基础信息' },
    { key: 'toutiaopay', label: '支付状态', radio: RADIO_ONOFF, group: '支付设置' },
    { key: 'pay_token', label: 'Token(令牌)', group: '支付设置' },
    { key: 'pay_mchid', label: '商户号', group: '支付设置' },
    { key: 'pay_salt', label: 'SALT', secret: true, group: '支付设置' },
    { key: '_pay_server', label: '服务器地址', static: 'domain', group: '支付设置' },
  ],
  h5: [
    { key: 'wxpay', label: '支付状态', radio: RADIO_ONOFF, group: '微信支付设置', staticHelp: 'jsapi' },
    { key: 'appid', label: '支付APPID', group: '微信支付设置', help: '公众号的appid' },
    { key: 'wxpay_type', label: '微信支付模式', radio: RADIO_WXPAY_MODE, group: '微信支付设置' },
    { key: 'wxpay_mchid', label: '支付商户号', group: '微信支付设置' },
    { key: 'wxpay_mchkey', label: '支付密钥', secret: true, group: '微信支付设置', help: WXPAY_MCHKEY_HELP, helpLink: WXPAY_MCH_LINK },
    { key: 'wxpay_apiclient_cert', label: 'PEM证书', pem: true, pemName: 'apiclient_cert.pem', group: '微信支付设置', help: 'apiclient_cert.pem ' + WXPAY_CERT_HELP, helpLink: WXPAY_MCH_LINK },
    { key: 'wxpay_apiclient_key', label: '证书密钥', pem: true, pemName: 'apiclient_key.pem', group: '微信支付设置', help: 'apiclient_key.pem ' + WXPAY_CERT_HELP, helpLink: WXPAY_MCH_LINK },
    { key: 'alipay', label: '支付状态', radio: RADIO_ONOFF, group: '支付宝支付设置', helpLink: { text: '接入指引', url: 'https://opendocs.alipay.com/common/02kipl' } },
    { key: 'ali_appid', label: 'APPID', group: '支付宝支付设置', help: '支付宝分配给开发者的应用ID' },
    { key: 'alipay_type', label: '支付模式', radio: [{ value: 0, label: '普通模式' }], group: '支付宝支付设置' },
    { key: 'ali_privatekey', label: '应用私钥', secret: true, textarea: true, group: '支付宝支付设置', help: '请填写应用私钥去头去尾去回车，一行字符串' },
    { key: 'ali_publickey', label: '支付宝公钥', secret: true, textarea: true, group: '支付宝支付设置', help: '请填写支付宝公钥，一行字符串' },
  ],
  app: [
    { key: '_app_tip', label: '', static: 'apptip', group: '绑定微信开放平台' },
    { key: 'appid', label: 'AppID', group: '绑定微信开放平台' },
    { key: 'appsecret', label: 'AppSecret', group: '绑定微信开放平台' },
    { key: 'wxpay', label: '微信支付状态', radio: RADIO_ONOFF, group: '微信支付设置' },
    { key: 'wxpay_type', label: '微信支付模式', radio: RADIO_WXPAY_MODE, group: '微信支付设置' },
    { key: 'wxpay_mchid', label: '支付商户号', group: '微信支付设置' },
    { key: 'wxpay_mchkey', label: '支付密钥', secret: true, group: '微信支付设置', help: WXPAY_MCHKEY_HELP, helpLink: WXPAY_MCH_LINK },
    { key: 'wxpay_apiclient_cert', label: 'PEM证书', pem: true, pemName: 'apiclient_cert.pem', group: '微信支付设置', help: 'apiclient_cert.pem ' + WXPAY_CERT_HELP, helpLink: WXPAY_MCH_LINK },
    { key: 'wxpay_apiclient_key', label: '证书密钥', pem: true, pemName: 'apiclient_key.pem', group: '微信支付设置', help: 'apiclient_key.pem ' + WXPAY_CERT_HELP, helpLink: WXPAY_MCH_LINK },
    { key: 'wxpay_serial_no', label: '证书序列号', group: '商家转账配置(APIv3配置)', help: '请在微信支付商户平台[账户中心]-[API安全]-[管理证书]中查看', helpLink: WXPAY_MCH_LINK },
    { key: 'wxpay_mchkey_v3', label: 'V3支付密钥', secret: true, group: '商家转账配置(APIv3配置)', help: '请在微信支付商户平台[账户中心]-[API安全]中设置[APIv3密钥]', helpLink: WXPAY_MCH_LINK },
    { key: 'sign_type', label: '签名验签方式', radio: RADIO_SIGN, group: '商家转账配置(APIv3配置)', help: '根据自己[账户中心]-[API安全]-[验证微信支付身份]的设置选择，不要随意切换：', helpLink: { text: '微信官方说明', url: 'https://kf.qq.com/faq/1808246nUzae180824mYzQbU.html' } },
    { key: 'wxpay_plate_serialno', label: '平台证书序列号', group: '商家转账配置(APIv3配置)', help: '获取方式：[账户中心]-[API安全]-[平台证书]-[管理证书]' },
    { key: 'wxpay_wechatpay_pem', label: '平台证书', pem: true, pemName: 'wechatpay.pem', group: '商家转账配置(APIv3配置)', help: 'wechatpay.pem 请在微信支付商户平台[账户中心]-[API安全]-[验证微信支付身份]中下载证书，设置完成后上传；注：使用自动下载需开启php的shell_exec函数', helpLink: WXPAY_MCH_LINK },
    { key: 'transfer_scene_id', label: '商家转账场景', scene: true, group: '商家转账配置(APIv3配置)', help: '该笔转账使用的转账场景，可前往"商户平台-产品中心-商家转账"中申请，目前仅支持【1000-现金营销、1005-佣金报酬】' },
    { key: 'transfer_scene_type', label: '岗位类型', group: '商家转账配置(APIv3配置)', help: '请在信息内容描述收款用户的岗位类型，如外卖员、专家顾问，不填写默认【员工】', helpLink: { text: '详情介绍', url: 'https://pay.weixin.qq.com/doc/v3/merchant/4012716458' } },
    { key: 'transfer_scene_content', label: '报酬说明', group: '商家转账配置(APIv3配置)', help: '请在信息内容描述用户接收当前这笔报酬的原因，如7月份配送费，高温补贴，不填写默认审核备注', helpLink: { text: '详情介绍', url: 'https://pay.weixin.qq.com/doc/v3/merchant/4012716458' } },
    { key: 'alipay', label: '支付状态', radio: RADIO_ONOFF, group: '支付宝支付设置', helpLink: { text: '接入指引', url: 'https://opendocs.alipay.com/common/02kipl' } },
    { key: 'ali_appid', label: 'APPID', group: '支付宝支付设置', help: '支付宝分配给开发者的应用ID' },
    { key: 'ali_privatekey', label: '应用私钥', secret: true, textarea: true, group: '支付宝支付设置', help: '请填写应用私钥去头去尾去回车，一行字符串' },
    { key: 'ali_publickey', label: '支付宝公钥', secret: true, textarea: true, group: '支付宝支付设置', help: '请填写支付宝公钥，一行字符串' },
    { key: 'androidurl', label: 'Android安装地址', group: '安装包地址', help: '可上传到站点根目录然后设置安装包地址（https://你的域名/安装包文件名.apk）' },
    { key: 'iosurl', label: 'IOS安装地址', group: '安装包地址', help: '上架后在应用商店中复制链接地址' },
    { key: '_user_download', label: '用户下载地址', static: 'download', group: '安装包地址' },
  ],
}

const TABS: { key: PlatformKey; name: string }[] = [
  { key: 'mp', name: '微信公众号' },
  { key: 'wx', name: '微信小程序' },
  { key: 'alipay', name: '支付宝小程序' },
  { key: 'baidu', name: '百度小程序' },
  { key: 'toutiao', name: '抖音小程序' },
  { key: 'qq', name: 'QQ小程序' },
  { key: 'h5', name: '手机H5' },
  { key: 'app', name: '手机APP' },
]

const LEVEL_TEXT: Record<number, string> = {
  4: '认证服务号',
  3: '认证订阅号',
  2: '未认证服务号',
  1: '未认证订阅号',
  0: '未知',
}

// ============ 状态 ============

const props = defineProps<{ initialTab?: string }>()

const activeTab = ref<PlatformKey>(
  props.initialTab && TABS.some((t) => t.key === props.initialTab) ? (props.initialTab as PlatformKey) : 'mp',
)
const overview = ref<Partial<BindingOverview>>({})
const loading = ref(false)
const saving = ref(false)
const authLoading = ref(false)
const authUrlLoading = ref(false)

// 微信端绑定方式入口：manual 手动配置 / auth 授权绑定
const wxMode = ref<Record<'wx' | 'mp', 'manual' | 'auth'>>({ wx: 'manual', mp: 'manual' })

// 各端表单数据（切换 tab 懒加载）
const forms = reactive<Partial<Record<PlatformKey, BindingConfig>>>({})
const loadedTabs = ref<PlatformKey[]>([])

const currentOverview = computed(() => overview.value[activeTab.value])
const currentFields = computed(() => FIELD_DEFS[activeTab.value])
const groups = computed(() => {
  const list: string[] = []
  for (const f of currentFields.value) {
    const g = f.group || '基础配置'
    if (!list.includes(g)) list.push(g)
  }
  return list
})
const isWxTab = computed(() => activeTab.value === 'wx' || activeTab.value === 'mp')
const currentForm = computed<BindingConfig>(() => forms[activeTab.value] || {})
// 支持下载代码包的小程序端
const CODE_PLATFORMS: PlatformKey[] = ['alipay', 'baidu', 'qq', 'toutiao']
const hasDownloadCode = computed(() => CODE_PLATFORMS.includes(activeTab.value))

// ============ 加载 ============

async function loadOverview() {
  try {
    overview.value = (await getBindingOverview()) || {}
  } catch {
    /* 拦截器已提示 */
  }
}

async function loadConfig(platform: PlatformKey) {
  if (loadedTabs.value.includes(platform)) return
  loading.value = true
  try {
    const data = await getBindingConfig(platform)
    forms[platform] = data || {}
    loadedTabs.value.push(platform)
    // 微信端：已授权绑定默认展示授权入口
    if ((platform === 'wx' || platform === 'mp') && Number(data?.authtype) === 1) {
      wxMode.value[platform] = 'auth'
    }
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

function onTabChange(key: string | number) {
  const platform = key as PlatformKey
  loadConfig(platform)
}

// ============ 保存 ============

async function onSave() {
  const platform = activeTab.value
  const data = forms[platform]
  if (!data) return
  // 剔除下划线开头的静态展示字段（回调地址/域名等，不入库）
  const payload: BindingConfig = {}
  for (const [k, v] of Object.entries(data)) {
    if (!k.startsWith('_')) payload[k] = v
  }
  saving.value = true
  try {
    await saveBindingConfig(platform, payload)
    ElMessage.success('保存成功')
    loadedTabs.value = loadedTabs.value.filter((k) => k !== platform)
    await Promise.all([loadConfig(platform), loadOverview()])
  } catch {
    /* 拦截器已提示 */
  } finally {
    saving.value = false
  }
}

// ============ 下载代码包 ============

const codeLoading = ref(false)
async function onDownloadCode() {
  if (!hasDownloadCode.value) return
  codeLoading.value = true
  try {
    const rs = await downloadCodePackage(activeTab.value)
    if (rs?.url) {
      ElMessage.success(rs.msg || '代码包已生成')
      window.open(rs.url, '_blank')
    } else {
      ElMessage.warning(rs?.msg || '代码包生成失败')
    }
  } catch {
    /* 拦截器已提示 */
  } finally {
    codeLoading.value = false
  }
}

// ============ 授权绑定（仅 wx/mp） ============

async function onAuthBind() {
  const platform = activeTab.value
  if (platform !== 'wx' && platform !== 'mp') return
  authUrlLoading.value = true
  try {
    const rs = await getWxAuthUrl(platform === 'mp' ? 1 : 2)
    if (rs?.url) {
      window.open(rs.url, '_blank')
      ElMessage.info('已打开授权页，请在新窗口完成授权后回来刷新状态')
    }
  } catch {
    /* 拦截器已提示 */
  } finally {
    authUrlLoading.value = false
  }
}

async function onRefreshAuth() {
  const platform = activeTab.value
  if (platform !== 'wx' && platform !== 'mp') return
  authLoading.value = true
  try {
    await refreshBindingAuth(platform)
    ElMessage.success('授权信息已刷新')
    loadedTabs.value = loadedTabs.value.filter((k) => k !== platform)
    await Promise.all([loadConfig(platform), loadOverview()])
  } catch {
    /* 拦截器已提示 */
  } finally {
    authLoading.value = false
  }
}

function fieldsOf(group: string): FieldDef[] {
  return currentFields.value.filter((f) => (f.group || '基础配置') === group)
}

// 站点根地址（演示站同类静态字段由服务器域名自动生成）
const siteOrigin = window.location.origin
const staticText = (kind?: string): string => {
  switch (kind) {
    case 'domain':
      return siteOrigin.replace(/^https?:\/\//, '')
    case 'notify':
      return siteOrigin + '/notify.php'
    case 'jsapi':
      return siteOrigin + '/'
    case 'download':
      return siteOrigin + '/?s=/Index/downloadapp/aid/1'
    case 'ip':
      return '服务器IP（部署后在支付宝后台填入本机公网IP）'
    case 'apptip':
      return '请先在微信开放平台(open.weixin.qq.com)创建移动应用，用于微信登录、微信分享及微信支付'
    default:
      return ''
  }
}

// 商家转账场景（1000-现金营销 / 1005-佣金报酬）
const SCENE_OPTIONS = [
  { value: '1000', label: '1000-现金营销' },
  { value: '1005', label: '1005-佣金报酬' },
]

const uploadingKey = ref('')
async function doUpload(options: UploadRequestOptions, field: FieldDef) {
  const form = forms[activeTab.value]
  if (!form) return
  uploadingKey.value = field.key
  try {
    if (field.pem) {
      const data = await uploadBusinessCert(options.file)
      ;(form as Record<string, unknown>)[field.key] = data.url
    } else {
      const data = await uploadFile(options.file)
      ;(form as Record<string, unknown>)[field.key] = data.url
    }
    ElMessage.success('上传成功')
  } catch {
    /* 拦截器已提示 */
  } finally {
    uploadingKey.value = ''
  }
}

onMounted(() => {
  loadOverview()
  loadConfig(activeTab.value)
})
</script>

<template>
  <div class="platform-binding">
    <div class="page-head">
      <h2>平台绑定</h2>
      <p>管理各端小程序/公众号的接入配置，微信端支持手动配置与开放平台代授权两种方式。</p>
    </div>

    <!-- 顶部横向 tab 条已隐藏：左侧树菜单已按端独立挂位，按 initialTab 只渲染对应端 -->
    <el-tabs v-model="activeTab" class="binding-tabs hide-tab-nav" @tab-change="onTabChange">
      <el-tab-pane v-for="tab in TABS" :key="tab.key" :name="tab.key" :label="tab.name">
        <!-- 绑定状态卡 -->
        <div v-loading="loading" class="status-card">
          <template v-if="currentOverview && currentOverview.bound">
            <el-avatar
              v-if="currentOverview.headimg"
              :src="currentOverview.headimg"
              :size="48"
              shape="square"
            />
            <div class="status-main">
              <div class="status-title">
                <span class="nickname">{{ currentOverview.nickname || currentOverview.appid }}</span>
                <el-tag v-if="isWxTab" size="small" :type="currentOverview.authtype === 1 ? 'success' : 'info'">
                  {{ currentOverview.mode }}
                </el-tag>
                <el-tag
                  v-if="isWxTab && currentOverview.authtype === 1 && currentOverview.auth_status === 0"
                  size="small"
                  type="danger"
                >
                  授权已取消
                </el-tag>
              </div>
              <div class="status-sub">
                AppID：{{ currentOverview.appid }}
                <template v-if="isWxTab && currentOverview.level"> ｜ {{ LEVEL_TEXT[currentOverview.level] || '未知级别' }}</template>
              </div>
            </div>
            <div v-if="isWxTab" class="status-actions">
              <el-button v-if="currentOverview.authtype === 1" size="small" :loading="authLoading" @click="onRefreshAuth">
                刷新授权信息
              </el-button>
              <el-button size="small" @click="onTabChange(activeTab)">刷新状态</el-button>
            </div>
          </template>
          <template v-else>
            <div class="status-main">
              <div class="status-title"><span class="nickname unbound">未绑定</span></div>
              <div class="status-sub">填写下方配置保存后即完成绑定</div>
            </div>
          </template>
        </div>

        <!-- 微信端：手动配置 / 授权绑定 双入口 -->
        <div v-if="isWxTab" class="mode-switch">
          <el-radio-group v-model="wxMode[activeTab as 'wx' | 'mp']">
            <el-radio-button value="manual">手动配置</el-radio-button>
            <el-radio-button value="auth">授权绑定</el-radio-button>
          </el-radio-group>
        </div>

        <!-- 授权绑定区（仅 wx/mp） -->
        <div v-if="isWxTab && wxMode[activeTab as 'wx' | 'mp'] === 'auth'" class="auth-panel">
          <h4>通过开放平台代授权绑定</h4>
          <p class="tip">
            点击下方按钮打开微信开放平台授权页，使用{{ activeTab === 'mp' ? '公众号' : '小程序' }}管理员微信扫码确认授权，
            授权成功后系统自动保存绑定信息（AppID、名称、头像、二维码），无需填写任何密钥。
          </p>
          <div class="auth-btns">
            <el-button type="primary" :loading="authUrlLoading" @click="onAuthBind">前往授权绑定</el-button>
            <el-button :loading="authLoading" @click="onRefreshAuth">重新拉取授权信息</el-button>
          </div>
        </div>

        <!-- 配置表单 -->
        <el-form
          v-if="!isWxTab || wxMode[activeTab as 'wx' | 'mp'] === 'manual'"
          label-width="220px"
          label-position="right"
          class="binding-form"
        >
          <template v-for="group in groups" :key="group">
            <div class="group-title">{{ group }}</div>
            <el-form-item v-for="field in fieldsOf(group)" :key="field.key" :label="field.label">
              <!-- 纯说明字段（无标签时仅展示提示） -->
              <div v-if="field.static === 'apptip'" class="form-tip">{{ staticText(field.static) }}</div>

              <!-- 静态只读字段（回调地址/域名/下载地址等） -->
              <div v-else-if="field.static" class="field-line">
                <el-input :model-value="staticText(field.static)" readonly />
                <span v-if="field.help || field.staticHelp" class="form-help">
                  <template v-if="field.staticHelp === 'jsapi'">JSAPI支付授权目录：{{ staticText('jsapi') }} </template>
                  <a v-if="field.helpLink" :href="field.helpLink.url" target="_blank">{{ field.helpLink.text }}</a>
                  {{ field.help }}
                </span>
              </div>

              <!-- 单选组 -->
              <div v-else-if="field.radio" class="field-line">
                <el-radio-group v-model="(currentForm as Record<string, unknown>)[field.key]">
                  <el-radio v-for="opt in field.radio" :key="opt.value" :value="opt.value">{{ opt.label }}</el-radio>
                </el-radio-group>
                <span v-if="field.help || field.staticHelp" class="form-help">
                  <template v-if="field.staticHelp === 'jsapi'">JSAPI支付授权目录：{{ staticText('jsapi') }} </template>
                  {{ field.help }}
                  <a v-if="field.helpLink" :href="field.helpLink.url" target="_blank">{{ field.helpLink.text }}</a>
                </span>
              </div>

              <!-- 商家转账场景下拉 -->
              <div v-else-if="field.scene" class="field-line">
                <el-select v-model="(currentForm as Record<string, unknown>)[field.key]" placeholder="请选择转账场景" style="width:220px">
                  <el-option v-for="opt in SCENE_OPTIONS" :key="opt.value" :label="opt.label" :value="opt.value" />
                </el-select>
                <span v-if="field.help" class="form-help">{{ field.help }}</span>
              </div>

              <!-- 图片上传 -->
              <div v-else-if="field.image" class="field-line">
                <el-upload :show-file-list="false" :http-request="(o: UploadRequestOptions) => doUpload(o, field)" accept="image/*">
                  <el-button :loading="uploadingKey === field.key">上传图片</el-button>
                </el-upload>
                <el-image
                  v-if="(currentForm as Record<string, string>)[field.key]"
                  :src="(currentForm as Record<string, string>)[field.key]"
                  class="thumb"
                  :preview-src-list="[(currentForm as Record<string, string>)[field.key]]"
                />
              </div>

              <!-- PEM 证书上传 -->
              <div v-else-if="field.pem" class="field-line">
                <el-input :model-value="(currentForm as Record<string, string>)[field.key]" readonly class="pem-input" />
                <el-upload :show-file-list="false" :http-request="(o: UploadRequestOptions) => doUpload(o, field)" accept=".pem">
                  <el-button :loading="uploadingKey === field.key">上传</el-button>
                </el-upload>
                <span v-if="(currentForm as Record<string, string>)[field.key]" class="uploaded">已上传</span>
                <span v-if="field.help || field.helpLink" class="form-help">
                  {{ field.help }}
                  <a v-if="field.helpLink" :href="field.helpLink.url" target="_blank">{{ field.helpLink.text }}</a>
                </span>
              </div>

              <!-- 开关 -->
              <div v-else-if="field.switch" class="field-line">
                <el-switch v-model="(currentForm as Record<string, unknown>)[field.key]" :active-value="1" :inactive-value="0" />
                <span v-if="field.help" class="form-help">{{ field.help }}</span>
              </div>

              <!-- 多行文本 -->
              <div v-else-if="field.textarea" class="field-line">
                <el-input
                  v-model="(currentForm as Record<string, unknown>)[field.key]"
                  type="textarea"
                  :rows="3"
                  :placeholder="field.secret ? '已配置则留空沿用原值' : field.placeholder || ''"
                />
                <span v-if="field.help || field.helpLink" class="form-help">
                  {{ field.help }}
                  <a v-if="field.helpLink" :href="field.helpLink.url" target="_blank">{{ field.helpLink.text }}</a>
                </span>
              </div>

              <!-- 普通输入 -->
              <div v-else class="field-line">
                <el-input
                  v-model="(currentForm as Record<string, unknown>)[field.key]"
                  :type="field.secret ? 'password' : 'text'"
                  :show-password="field.secret"
                  :placeholder="field.secret ? '已配置则留空沿用原值' : field.placeholder || ''"
                />
                <span v-if="field.help || field.helpLink" class="form-help">
                  {{ field.help }}
                  <a v-if="field.helpLink" :href="field.helpLink.url" target="_blank">{{ field.helpLink.text }}</a>
                </span>
              </div>
            </el-form-item>
          </template>
          <el-form-item class="save-row">
            <el-button type="primary" :loading="saving" @click="onSave">保存</el-button>
            <el-button v-if="hasDownloadCode" :loading="codeLoading" @click="onDownloadCode">下载代码包</el-button>
            <span class="save-tip">密钥类字段保存后不再明文显示，留空表示沿用原值。</span>
          </el-form-item>
        </el-form>
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<style scoped>
/* 隐藏顶部横向 tab 导航条（左侧树菜单已负责导航） */
.hide-tab-nav :deep(.el-tabs__header) { display: none; }
.platform-binding {
  padding: 16px;
}
.page-head h2 {
  margin: 0 0 4px;
  font-size: 18px;
  color: #1f2329;
}
.page-head p {
  margin: 0 0 16px;
  font-size: 13px;
  color: #8a919f;
}
.status-card {
  display: flex;
  align-items: center;
  gap: 14px;
  background: #fff;
  border: 1px solid #ebedf0;
  border-radius: 8px;
  padding: 16px 20px;
  margin-bottom: 16px;
}
.status-main {
  flex: 1;
  min-width: 0;
}
.status-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
}
.nickname {
  font-size: 15px;
  font-weight: 600;
  color: #1f2329;
}
.nickname.unbound {
  color: #8a919f;
}
.status-sub {
  font-size: 13px;
  color: #8a919f;
}
.mode-switch {
  margin-bottom: 16px;
}
.auth-panel {
  background: #fff;
  border: 1px solid #ebedf0;
  border-radius: 8px;
  padding: 20px 24px;
  margin-bottom: 16px;
}
.auth-panel h4 {
  margin: 0 0 8px;
  font-size: 15px;
  color: #1f2329;
}
.auth-panel .tip {
  margin: 0 0 16px;
  font-size: 13px;
  color: #8a919f;
  line-height: 1.7;
}
.binding-form {
  background: #fff;
  border: 1px solid #ebedf0;
  border-radius: 8px;
  padding: 20px 24px 4px;
  max-width: 860px;
}
.group-title {
  font-size: 14px;
  font-weight: 600;
  color: #1f2329;
  margin: 8px 0 14px;
  padding-left: 8px;
  border-left: 3px solid #07c160;
}
.group-title + .group-title {
  margin-top: 20px;
}
.save-row :deep(.el-form-item__content) {
  display: flex;
  align-items: center;
  gap: 12px;
}
.save-tip {
  font-size: 12px;
  color: #8a919f;
}
.field-line {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 3px;
  width: 100%;
}
.field-line .el-input,
.field-line .el-select,
.field-line .pem-input {
  width: 320px;
}
.field-line .form-help {
  flex-basis: 100%;
  font-size: 12px;
  color: #909399;
  line-height: 1.6;
  margin: 0;
}
.form-help a {
  color: #409eff;
  margin: 0 2px;
}
.field-line .thumb {
  width: 90px;
  height: 90px;
  border-radius: 4px;
  border: 1px solid #ebeef5;
  margin-left: 3px;
}
.field-line .uploaded {
  font-size: 12px;
  color: #07c160;
  margin-left: 3px;
}
.form-tip {
  font-size: 13px;
  color: #909399;
  line-height: 1.7;
}
</style>
