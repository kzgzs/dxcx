<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  getBindingOverview,
  getBindingConfig,
  saveBindingConfig,
  getWxAuthUrl,
  refreshBindingAuth,
  type PlatformKey,
  type BindingOverview,
  type BindingConfig,
} from '../../api/binding'

// ============ 字段定义（与后端白名单一致） ============

interface FieldDef {
  key: string
  label: string
  secret?: boolean
  textarea?: boolean
  switch?: boolean
  placeholder?: string
  group?: string
}

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
    { key: 'appid', label: 'AppID', placeholder: '支付宝小程序 APPID', group: '基础配置' },
    { key: 'appsecret', label: '应用私钥', secret: true, textarea: true, placeholder: '粘贴应用私钥（可含 PEM 头尾，保存时自动剥离）', group: '基础配置' },
    { key: 'publickey', label: '支付宝公钥', secret: true, textarea: true, group: '基础配置' },
    { key: 'nickname', label: '小程序名称', group: '展示信息' },
    { key: 'headimg', label: '头像 URL', group: '展示信息' },
    { key: 'qrcode', label: '二维码 URL', group: '展示信息' },
    { key: 'alipay', label: '支付宝支付', switch: true, group: '支付配置' },
    { key: 'pay_mode', label: '支付模式', placeholder: '0 普通 / 1 服务商', group: '支付配置' },
    { key: 'msid', label: '二级商户 ID（服务商模式）', group: '支付配置' },
    { key: 'openid_set', label: 'openid 采集', switch: true, group: '其他配置' },
    { key: 'sxpay', label: '随行付分账', switch: true, group: '其他配置' },
    { key: 'sxpay_mno', label: '随行付商户编号', group: '其他配置' },
    { key: 'sxpay_mchkey', label: '随行付密钥', secret: true, group: '其他配置' },
  ],
  baidu: [
    { key: 'appid', label: 'AppID', group: '基础配置' },
    { key: 'appkey', label: 'AppKey', secret: true, group: '基础配置' },
    { key: 'appsecret', label: 'AppSecret', secret: true, group: '基础配置' },
    { key: 'nickname', label: '小程序名称', group: '展示信息' },
    { key: 'headimg', label: '头像 URL', group: '展示信息' },
    { key: 'qrcode', label: '二维码 URL', group: '展示信息' },
    { key: 'baidupay', label: '百度收银台', switch: true, group: '支付配置' },
    { key: 'pay_appid', label: '支付 AppID', group: '支付配置' },
    { key: 'pay_appkey', label: '支付 AppKey', secret: true, group: '支付配置' },
    { key: 'pay_dealId', label: 'dealId', group: '支付配置' },
    { key: 'pay_publickey', label: '支付公钥', secret: true, textarea: true, group: '支付配置' },
    { key: 'pay_privatekey', label: '支付私钥', secret: true, textarea: true, group: '支付配置' },
  ],
  qq: [
    { key: 'appid', label: 'AppID', group: '基础配置' },
    { key: 'appsecret', label: 'AppSecret', secret: true, group: '基础配置' },
    { key: 'apptoken', label: 'AppToken', secret: true, group: '基础配置' },
    { key: 'nickname', label: '小程序名称', group: '展示信息' },
    { key: 'headimg', label: '头像 URL', group: '展示信息' },
    { key: 'qrcode', label: '二维码 URL', group: '展示信息' },
    { key: 'wxpay', label: '微信支付（QQ 内）', switch: true, group: '支付配置' },
    { key: 'wxpay_appid', label: '支付 AppID', group: '支付配置' },
    { key: 'wxpay_type', label: '支付商户类型', placeholder: '0 普通商户 / 1 服务商', group: '支付配置' },
    { key: 'wxpay_sub_mchid', label: '子商户号（服务商模式）', group: '支付配置' },
    { key: 'wxpay_mchid', label: '商户号 MCHID', group: '支付配置' },
    { key: 'wxpay_mchkey', label: '商户密钥 API Key', secret: true, group: '支付配置' },
    { key: 'wxpay_apiclient_cert', label: 'apiclient_cert.pem 内容', secret: true, textarea: true, group: '支付配置' },
    { key: 'wxpay_apiclient_key', label: 'apiclient_key.pem 内容', secret: true, textarea: true, group: '支付配置' },
  ],
  toutiao: [
    { key: 'appid', label: 'AppID', group: '基础配置' },
    { key: 'appsecret', label: 'AppSecret', secret: true, group: '基础配置' },
    { key: 'nickname', label: '小程序名称', group: '展示信息' },
    { key: 'headimg', label: '头像 URL', group: '展示信息' },
    { key: 'qrcode', label: '二维码 URL', group: '展示信息' },
    { key: 'toutiaopay', label: '抖音支付', switch: true, group: '支付配置' },
    { key: 'pay_token', label: '支付 Token（SALT 对应）', secret: true, group: '支付配置' },
    { key: 'pay_mchid', label: '支付商户号', group: '支付配置' },
    { key: 'pay_salt', label: '支付 SALT', secret: true, group: '支付配置' },
  ],
  h5: [
    { key: 'appid', label: '公众号 AppID', placeholder: 'H5 支付所用公众号/服务号 AppID', group: '基础配置' },
    { key: 'wxpay', label: '微信支付（JSAPI）', switch: true, group: '支付配置' },
    { key: 'wxpay_type', label: '支付商户类型', placeholder: '0 普通商户 / 1 服务商', group: '支付配置' },
    { key: 'wxpay_sub_mchid', label: '子商户号（服务商模式）', group: '支付配置' },
    { key: 'wxpay_mchid', label: '商户号 MCHID', group: '支付配置' },
    { key: 'wxpay_mchkey', label: '商户密钥 API Key', secret: true, group: '支付配置' },
    { key: 'wxpay_apiclient_cert', label: 'apiclient_cert.pem 内容', secret: true, textarea: true, group: '支付配置' },
    { key: 'wxpay_apiclient_key', label: 'apiclient_key.pem 内容', secret: true, textarea: true, group: '支付配置' },
    { key: 'alipay', label: '支付宝支付（H5）', switch: true, group: '支付配置' },
    { key: 'ali_appid', label: '支付宝 APPID', group: '支付配置' },
    { key: 'ali_privatekey', label: '支付宝应用私钥', secret: true, textarea: true, group: '支付配置' },
    { key: 'ali_publickey', label: '支付宝公钥', secret: true, textarea: true, group: '支付配置' },
    { key: 'alipay_type', label: '支付宝接口类型', placeholder: '公钥 / 证书', group: '支付配置' },
  ],
  app: [
    { key: 'appid', label: '移动应用 AppID', placeholder: '微信开放平台移动应用 AppID', group: '基础配置' },
    { key: 'appsecret', label: 'AppSecret', secret: true, group: '基础配置' },
    { key: 'androidurl', label: '安卓下载地址', group: '基础配置' },
    { key: 'iosurl', label: 'iOS 下载地址', group: '基础配置' },
    { key: 'wxpay', label: '微信支付（APP）', switch: true, group: '支付配置' },
    { key: 'wxpay_type', label: '支付商户类型', placeholder: '0 普通商户 / 1 服务商', group: '支付配置' },
    { key: 'wxpay_sub_mchid', label: '子商户号（服务商模式）', group: '支付配置' },
    { key: 'wxpay_mchid', label: '商户号 MCHID', group: '支付配置' },
    { key: 'wxpay_mchkey', label: '商户密钥 API Key', secret: true, group: '支付配置' },
    { key: 'wxpay_serial_no', label: 'API 证书序列号', group: '支付配置' },
    { key: 'wxpay_apiclient_cert', label: 'apiclient_cert.pem 内容', secret: true, textarea: true, group: '支付配置' },
    { key: 'wxpay_apiclient_key', label: 'apiclient_key.pem 内容', secret: true, textarea: true, group: '支付配置' },
    { key: 'wxpay_mchkey_v3', label: 'APIv3 密钥', secret: true, group: '支付配置' },
    { key: 'wxpay_wechatpay_pem', label: '平台证书 wechatpay.pem', textarea: true, group: '支付配置' },
    { key: 'sign_type', label: '签名类型', placeholder: 'RSA / SM2', group: '支付配置' },
    { key: 'public_key_id', label: '公钥 ID', group: '支付配置' },
    { key: 'public_key_pem', label: '公钥 PEM', textarea: true, group: '支付配置' },
    { key: 'wxpay_plate_serialno', label: '车牌付序列号', group: '支付配置' },
    { key: 'alipay', label: '支付宝支付（APP）', switch: true, group: '支付配置' },
    { key: 'ali_appid', label: '支付宝 APPID', group: '支付配置' },
    { key: 'ali_privatekey', label: '支付宝应用私钥', secret: true, textarea: true, group: '支付配置' },
    { key: 'ali_publickey', label: '支付宝公钥', secret: true, textarea: true, group: '支付配置' },
    { key: 'transfer_scene_id', label: '转账场景 ID', group: '其他配置' },
    { key: 'transfer_scene_type', label: '转账场景类型', group: '其他配置' },
    { key: 'transfer_scene_content', label: '转账场景内容', group: '其他配置' },
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
  saving.value = true
  try {
    await saveBindingConfig(platform, data)
    ElMessage.success('保存成功')
    loadedTabs.value = loadedTabs.value.filter((k) => k !== platform)
    await Promise.all([loadConfig(platform), loadOverview()])
  } catch {
    /* 拦截器已提示 */
  } finally {
    saving.value = false
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

    <el-tabs v-model="activeTab" class="binding-tabs" @tab-change="onTabChange">
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
              <el-switch
                v-if="field.switch"
                v-model="currentForm[field.key]"
                :active-value="1"
                :inactive-value="0"
              />
              <el-input
                v-else-if="field.textarea"
                v-model="currentForm[field.key]"
                type="textarea"
                :rows="3"
                :placeholder="field.secret ? '已配置则留空沿用原值' : field.placeholder || ''"
              />
              <el-input
                v-else
                v-model="currentForm[field.key]"
                :type="field.secret ? 'password' : 'text'"
                :show-password="field.secret"
                :placeholder="field.secret ? '已配置则留空沿用原值' : field.placeholder || ''"
              />
            </el-form-item>
          </template>
          <el-form-item class="save-row">
            <el-button type="primary" :loading="saving" @click="onSave">保存配置</el-button>
            <span class="save-tip">密钥类字段保存后不再明文显示，留空表示沿用原值。</span>
          </el-form-item>
        </el-form>
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<style scoped>
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
</style>
