<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, shallowRef } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance } from 'element-plus'
import '@wangeditor/editor/dist/css/style.css'
import { Editor, Toolbar } from '@wangeditor/editor-for-vue'
import { getTenantSysset, saveTenantSysset, getFreightList } from '../../api/tenantSet'
import type { TenantSyssetData, FreightMapConfig } from '../../api/types'
import { getYuebaoConfig, saveYuebaoConfig } from '../../api/yuebao'
import { uploadFile } from '../../api/goods'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import MapPicker from '../../components/MapPicker.vue'
// 附件设置 Tab（任务258：从独立菜单移入本页，位置对照参考系统 sysset lay-id=10；独立保存，不随主表单提交）
import AttachSet from './AttachSet.vue'

const formRef = ref<FormInstance>()
const loading = ref(false)
const saving = ref(false)
const activeTab = ref('base')

const formData = reactive<TenantSyssetData>({
  name: '',
  logo: '',
  desc: '',
  tel: '',
  province: '',
  city: '',
  district: '',
  address: '',
  longitude: '',
  latitude: '',
  main_business: '',
  official_account_status: 0,
  guanzhu_tip: '',
  order_broadcast: '1,2',
  navbgmode: 0,
  navbgcolor: '',
  navtextcolor: 0,
  visitperm: 'all',
  kfurl: '',
  wxkf: 0,
  wxkfurl: '',
  moneypay: 1,
  recharge: 1,
  recharge_minimum: '0.00',
  withdraw: 1,
  comwithdraw: 1,
  withdrawmin: '1.00',
  withdrawfee: '0.00',
  withdrawmax: 0,
  comwithdrawmin: '1.00',
  comwithdrawmax: 0,
  comwithdrawfee: '0.00',
  day_withdraw_num: 0,
  withdraw_desc: '',
  withdraw_weixin: 1,
  withdraw_aliaccount: 0,
  withdraw_bankcard: 0,
  score2money: '0.01',
  scoredkmaxpercent: '100.00',
  scorein_money: '1.00',
  scorein_score: 1,
  scorecz_money: '1.00',
  scorecz_score: 1,
  scorebdkyf: 0,
  score_from_moneypay: 1,
  score_transfer: 0,
  score_expire_status: 0,
  score_expire_days: 0,
  logintype_wx: '1',
  logintype_h5: '1',
  login_setnickname: 0,
  login_bind: 0,
  reg_check: 0,
  reg_invite_code: 0,
  loading_icon: '',
  textset: { 余额: '余额', 积分: '积分', 佣金: '佣金', 优惠券: '优惠券', 会员: '会员' },
  xyinfo: {
    status: 0,
    agree_type: 0,
    name: '《用户注册协议》',
    content: '',
    name2: '',
    content2: '',
  },
  color1: '#FD4A46',
  color2: '#7E71F6',
  fxjiesuantype: 0,
  fxjiesuantype_pj: 0,
  fxjiesuantime: 0,
  fxjiesuantime_delaydays: '0.00',
  fxjiesuantime_perweek: 0,
  fx_differential: 0,
  commission_send_wallet: 0,
  commission_send_wallet_levelids: '',
  invoice: 0,
  invoice_type: '1',
  invoice_rate: '0.00',
  status: 1,
  close_tip: '',
})

const formRules = {
  name: [{ required: true, message: '请输入商城名称', trigger: 'blur' }],
}

// 余额宝设置（独立走 /admin/yuebao/config，tenant_config sys_ 键；任务181）
const ybForm = reactive({
  open_yuebao: 0,
  yuebao_rate: '0',
  yuebao_withdrawmin: '0',
  yuebao_withdrawfee: '0',
  yuebao_turn_yue: 1,
})

// ============ CSV 登录方式 <-> 勾选数组 ============
function csvToArr(csv: string): number[] {
  return String(csv ?? '')
    .split(',')
    .filter((s) => s !== '')
    .map((s) => Number(s))
}
const logintypeWxList = computed({
  get: () => csvToArr(formData.logintype_wx),
  set: (v: number[]) => {
    formData.logintype_wx = [...v].sort().join(',')
  },
})
const logintypeH5List = computed({
  get: () => csvToArr(formData.logintype_h5),
  set: (v: number[]) => {
    formData.logintype_h5 = [...v].sort().join(',')
  },
})

// 提现方式三勾选 <-> 三个 0/1 字段
const withdrawMethods = computed({
  get: () => {
    const arr: string[] = []
    if (formData.withdraw_weixin === 1) arr.push('weixin')
    if (formData.withdraw_aliaccount === 1) arr.push('aliaccount')
    if (formData.withdraw_bankcard === 1) arr.push('bankcard')
    return arr
  },
  set: (v: string[]) => {
    formData.withdraw_weixin = v.includes('weixin') ? 1 : 0
    formData.withdraw_aliaccount = v.includes('aliaccount') ? 1 : 0
    formData.withdraw_bankcard = v.includes('bankcard') ? 1 : 0
  },
})

// 文本自定义内置高频文案项（canzhao textset 默认键：余额/积分/佣金/优惠券/会员）
const textsetKeys = ['余额', '积分', '佣金', '优惠券', '会员']

// ============ 经纬度选择（任务308：有高德 key 走地图选点，无 key 降级手动输入） ============
const coordDialogVisible = ref(false)
const coordForm = reactive({ longitude: '', latitude: '' })
const mapConfig = ref<FreightMapConfig>({ map_type: '', map_key: '', map_jscode: '', map_qq_key: '' })
const hasMapKey = computed(() => mapConfig.value.map_type === 'amap' || mapConfig.value.map_type === 'qq')
const mapPickerVisible = ref(false)
function openCoordDialog() {
  if (hasMapKey.value) {
    mapPickerVisible.value = true
    return
  }
  coordForm.longitude = formData.longitude
  coordForm.latitude = formData.latitude
  coordDialogVisible.value = true
}
function confirmCoord() {
  formData.longitude = coordForm.longitude.trim()
  formData.latitude = coordForm.latitude.trim()
  coordDialogVisible.value = false
}
function onMapPick(lng: string, lat: string) {
  formData.longitude = lng
  formData.latitude = lat
}

// ============ 基础设置 CSV 勾选（任务308：关注提示/订单播报/访问条件） ============
const guanzhuTipList = computed({
  get: () => csvToArr(formData.guanzhu_tip),
  set: (v: number[]) => {
    formData.guanzhu_tip = [...v].sort().join(',')
  },
})
const broadcastList = computed({
  get: () => csvToArr(formData.order_broadcast),
  set: (v: number[]) => {
    formData.order_broadcast = [...v].sort().join(',')
  },
})
const visitpermList = computed({
  get: () =>
    String(formData.visitperm ?? '')
      .split(',')
      .filter((s) => s !== ''),
  set: (v: string[]) => {
    // 空选回落所有人，避免锁死前端访问
    formData.visitperm = (v.length > 0 ? [...v] : ['all']).join(',')
  },
})

// ============ 注册协议双富文本（wangEditor） ============
const editorRef1 = shallowRef()
const editorRef2 = shallowRef()
const toolbarConfig = {}
const editorConfig = { placeholder: '请输入协议内容...', MENU_CONF: {} as Record<string, unknown> }
function handleCreated1(editor: unknown) {
  editorRef1.value = editor
}
function handleCreated2(editor: unknown) {
  editorRef2.value = editor
}
const editorUploadConfig = {
  async customUpload(file: File, insertFn: (url: string) => void) {
    try {
      const res = await uploadFile(file)
      insertFn(res.url)
    } catch {
      ElMessage.error('图片上传失败')
    }
  },
}
editorConfig.MENU_CONF = { uploadImage: editorUploadConfig }
onBeforeUnmount(() => {
  editorRef1.value?.destroy?.()
  editorRef2.value?.destroy?.()
})

/** 加载系统设置 */
async function loadData() {
  loading.value = true
  try {
    const result = await getTenantSysset()
    if (result) {
      Object.assign(formData, result)
      // 后端 radio/数值字段可能为字符串，统一转 Number 以匹配 el-radio :label 与 el-input-number
      formData.wxkf = Number(result.wxkf ?? 0)
      formData.official_account_status = Number(result.official_account_status ?? 0)
      formData.navbgmode = Number(result.navbgmode ?? 0)
      formData.navtextcolor = Number(result.navtextcolor ?? 0)
      formData.moneypay = Number(result.moneypay ?? 1)
      formData.recharge = Number(result.recharge ?? 1)
      formData.withdraw = Number(result.withdraw ?? 1)
      formData.comwithdraw = Number(result.comwithdraw ?? 1)
      formData.withdrawmax = Number(result.withdrawmax ?? 0)
      formData.comwithdrawmax = Number(result.comwithdrawmax ?? 0)
      formData.day_withdraw_num = Number(result.day_withdraw_num ?? 0)
      formData.withdraw_weixin = Number(result.withdraw_weixin ?? 1)
      formData.withdraw_aliaccount = Number(result.withdraw_aliaccount ?? 0)
      formData.withdraw_bankcard = Number(result.withdraw_bankcard ?? 0)
      formData.scorein_score = Number(result.scorein_score ?? 1)
      formData.scorecz_score = Number(result.scorecz_score ?? 1)
      formData.scorebdkyf = Number(result.scorebdkyf ?? 0)
      formData.score_from_moneypay = Number(result.score_from_moneypay ?? 1)
      formData.score_transfer = Number(result.score_transfer ?? 0)
      formData.score_expire_status = Number(result.score_expire_status ?? 0)
      formData.score_expire_days = Number(result.score_expire_days ?? 0)
      formData.login_setnickname = Number(result.login_setnickname ?? 0)
      formData.login_bind = Number(result.login_bind ?? 0)
      formData.reg_check = Number(result.reg_check ?? 0)
      formData.reg_invite_code = Number(result.reg_invite_code ?? 0)
      formData.invoice = Number(result.invoice ?? 0)
      formData.status = Number(result.status ?? 1)
      // 分销字段统一转 Number（radio :label 为数字）
      formData.fxjiesuantype = Number(result.fxjiesuantype ?? 0)
      formData.fxjiesuantype_pj = Number(result.fxjiesuantype_pj ?? 0)
      formData.fxjiesuantime = Number(result.fxjiesuantime ?? 0)
      formData.fxjiesuantime_delaydays = String(result.fxjiesuantime_delaydays ?? '0.00')
      formData.fxjiesuantime_perweek = Number(result.fxjiesuantime_perweek ?? 0)
      formData.fx_differential = Number(result.fx_differential ?? 0)
      formData.commission_send_wallet = Number(result.commission_send_wallet ?? 0)
      formData.commission_send_wallet_levelids = String(result.commission_send_wallet_levelids ?? '')
      // textset 兜底内置键
      const ts: Record<string, string> = {}
      for (const k of textsetKeys) {
        ts[k] = String(result.textset?.[k] ?? k)
      }
      formData.textset = ts
      // xyinfo 兜底默认
      formData.xyinfo = {
        status: Number(result.xyinfo?.status ?? 0),
        agree_type: Number(result.xyinfo?.agree_type ?? 0),
        name: String(result.xyinfo?.name ?? '《用户注册协议》'),
        content: String(result.xyinfo?.content ?? ''),
        name2: String(result.xyinfo?.name2 ?? ''),
        content2: String(result.xyinfo?.content2 ?? ''),
      }
    }
    // 余额宝设置（并行拉取，失败不影响主表单）
    getYuebaoConfig()
      .then((cfg) => {
        if (cfg) {
          ybForm.open_yuebao = Number(cfg.open_yuebao ?? 0)
          ybForm.yuebao_rate = String(cfg.yuebao_rate ?? '0')
          ybForm.yuebao_withdrawmin = String(cfg.yuebao_withdrawmin ?? '0')
          ybForm.yuebao_withdrawfee = String(cfg.yuebao_withdrawfee ?? '0')
          ybForm.yuebao_turn_yue = Number(cfg.yuebao_turn_yue ?? 1)
        }
      })
      .catch(() => {
        // 忽略：余额宝设置段保持默认值
      })
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

/** 保存 */
function handleSave() {
  if (!formRef.value) return
  // 登录方式两端至少勾选一项
  if (logintypeWxList.value.length === 0 || logintypeH5List.value.length === 0) {
    activeTab.value = 'login'
    ElMessage.warning('小程序端与 H5 端登录方式至少各勾选一项')
    return
  }
  formRef.value.validate(async (valid) => {
    if (!valid) return
    saving.value = true
    try {
      await saveTenantSysset({ ...formData })
      // 余额宝设置随主表单一起保存（独立接口）
      await saveYuebaoConfig({ ...ybForm })
      ElMessage.success('保存成功')
    } catch {
      // 错误提示由 axios 拦截器统一处理
    } finally {
      saving.value = false
    }
  })
}

// ============ 选图（ImgIconPicker 统一选图） ============
const imgPickerVisible = ref(false)
let imgPickerSetter: ((url: string) => void) | null = null
function openImgPicker(setter: (url: string) => void) {
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls[0]) imgPickerSetter?.(urls[0])
  imgPickerSetter = null
}

onMounted(() => {
  loadData()
  // 地图选点配置（复用配送方式接口下发的 map_config）
  getFreightList()
    .then((d) => {
      if (d.map_config) {
        mapConfig.value = d.map_config
      }
    })
    .catch(() => {
      // 拉取失败保持手动输入降级
    })
})
</script>

<template>
  <div class="sysset-container" v-loading="loading">
    <el-form
      ref="formRef"
      :model="formData"
      :rules="formRules"
      label-width="130px"
      label-position="right"
      size="default"
    >
      <el-tabs v-model="activeTab">
        <!-- ==================== 基础设置 ==================== -->
        <el-tab-pane label="基础设置" name="base">
          <el-form-item label="商家名称" prop="name">
            <el-input v-model="formData.name" placeholder="请输入商家名称" clearable class="narrow-input" />
          </el-form-item>

          <el-form-item label="商家LOGO">
            <div class="field-block">
              <div class="field-line">
                <el-button @click="openImgPicker((u) => (formData.logo = u))">上传图片</el-button>
                <span class="form-help">建议尺寸: 200×200像素</span>
              </div>
              <div class="img-preview" v-if="formData.logo">
                <img :src="formData.logo" alt="商家LOGO预览" />
              </div>
            </div>
          </el-form-item>

          <el-form-item label="商家简介" prop="desc">
            <el-input v-model="formData.desc" placeholder="请输入商家简介" clearable class="wide-input" />
          </el-form-item>

          <el-form-item label="商家服务电话" prop="tel">
            <el-input v-model="formData.tel" placeholder="请输入商家服务电话" clearable class="narrow-input" />
          </el-form-item>

          <el-form-item label="商家地址" prop="address">
            <el-input v-model="formData.address" placeholder="请输入商家地址" clearable class="narrow-input" />
          </el-form-item>

          <el-form-item label="经纬度">
            <div class="field-line">
              <el-input v-model="formData.longitude" placeholder="经度" clearable class="mini-input-wide" />
              <span class="inline-label">-</span>
              <el-input v-model="formData.latitude" placeholder="纬度" clearable class="mini-input-wide" />
              <el-button @click="openCoordDialog">选择坐标</el-button>
            </div>
          </el-form-item>

          <el-form-item label="客服系统链接" prop="kfurl">
            <div class="field-block">
              <el-input v-model="formData.kfurl" placeholder="请输入客服系统链接" clearable class="mid-input" />
              <div class="form-help block-help">
                商品详情页客服链接，不填写则使用商城内部客服系统
                <el-popover placement="top" :width="380" trigger="hover">
                  <template #reference>
                    <el-link type="primary" :underline="false">示例</el-link>
                  </template>
                  <div class="kf-example">
                    示例：https://xxx.example.com/chat?shop=1
                    <br />电话客服链接格式：tel:0539-123456
                  </div>
                </el-popover>
              </div>
            </div>
          </el-form-item>

          <el-form-item label="微信小程序客服" prop="wxkf">
            <div class="field-block">
              <div class="field-line">
                <el-radio-group v-model="formData.wxkf">
                  <el-radio :label="0">客服链接</el-radio>
                  <el-radio :label="1">小程序客服</el-radio>
                  <el-radio :label="2">微信客服</el-radio>
                </el-radio-group>
                <template v-if="formData.wxkf === 0">
                  <span class="inline-label">客服链接：</span>
                  <el-input v-model="formData.wxkfurl" placeholder="请输入客服链接" clearable class="narrow-input" />
                </template>
              </div>
              <div class="form-help block-help">
                客服链接：使用填写的客服链接，不填写则使用商城内部客服系统；电话客服链接格式：tel:0539-123456
                <br />小程序客服：前往 小程序后台-功能-客服-小程序客服，配置客服人员
                <br />微信客服：在
                <el-link type="primary" :underline="false" href="https://kf.weixin.qq.com/" target="_blank">微信客服</el-link>
                系统注册账号并绑定，
                <el-link type="primary" :underline="false" href="https://kf.weixin.qq.com/" target="_blank">查看绑定流程</el-link>
                ，填写企业ID后复制客服链接填入上方客服链接处
              </div>
            </div>
          </el-form-item>

          <el-form-item label="公众号关注组件" prop="official_account_status">
            <div class="field-block">
              <el-radio-group v-model="formData.official_account_status">
                <el-radio :label="1">开启</el-radio>
                <el-radio :label="0">关闭</el-radio>
              </el-radio-group>
              <div class="form-help block-help">
                仅微信小程序端生效（支持场景：扫描二维码、扫描小程序码），设置方式：小程序后台，在“设置”-&gt;“关注公众号”中设置要展示的公众号
              </div>
            </div>
          </el-form-item>

          <el-form-item label="关注提示">
            <el-checkbox-group v-model="guanzhuTipList">
              <el-checkbox :value="1">首页</el-checkbox>
              <el-checkbox :value="2">商品详情页</el-checkbox>
            </el-checkbox-group>
            <span class="form-help">仅公众号端生效</span>
          </el-form-item>

          <el-form-item label="订单播报">
            <div class="field-block">
              <el-checkbox-group v-model="broadcastList">
                <el-checkbox :value="1">首页</el-checkbox>
                <el-checkbox :value="2">商品详情页</el-checkbox>
              </el-checkbox-group>
              <div class="form-help block-help">在首页/商品详情页滚动展示最近的购买记录</div>
            </div>
          </el-form-item>

          <el-form-item label="主色调" prop="color1">
            <el-input v-model="formData.color1" clearable class="mini-input-wide" />
            <el-color-picker v-model="formData.color1" />
            <span class="form-help">商城主色调，如：#FD4A46，请勿使用白色，否则部分元素将因颜色重叠而不可见</span>
          </el-form-item>

          <el-form-item label="辅色调" prop="color2">
            <el-input v-model="formData.color2" clearable class="mini-input-wide" />
            <el-color-picker v-model="formData.color2" />
            <span class="form-help">商城辅色调，如：#7E71F6</span>
          </el-form-item>

          <el-form-item label="导航背景颜色" prop="navbgmode">
            <div class="field-block">
              <div class="field-line">
                <el-radio-group v-model="formData.navbgmode">
                  <el-radio :label="0">跟随系统主色调</el-radio>
                  <el-radio :label="1">自定义颜色</el-radio>
                </el-radio-group>
                <span class="form-help">小程序/APP导航栏背景颜色，如#FD4A46</span>
              </div>
              <div class="field-line nav-custom-line" v-if="formData.navbgmode === 1">
                <el-input v-model="formData.navbgcolor" placeholder="如：#FD4A46" clearable class="mini-input-wide" />
                <el-color-picker v-model="formData.navbgcolor" />
              </div>
            </div>
          </el-form-item>

          <el-form-item label="导航文字颜色" prop="navtextcolor">
            <el-radio-group v-model="formData.navtextcolor">
              <el-radio :label="1">白色</el-radio>
              <el-radio :label="0">黑色</el-radio>
            </el-radio-group>
            <span class="form-help">小程序/APP导航栏文字颜色，仅支持白色/黑色</span>
          </el-form-item>

          <el-form-item label="系统前端访问条件">
            <el-checkbox-group v-model="visitpermList">
              <el-checkbox value="all">所有人</el-checkbox>
              <el-checkbox value="member">普通会员</el-checkbox>
              <el-checkbox value="dealer">经销商</el-checkbox>
            </el-checkbox-group>
          </el-form-item>

          <el-form-item label="状态" prop="status">
            <el-radio-group v-model="formData.status">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
            <span class="form-help">管理端关闭后仅总管理员可访问</span>
          </el-form-item>

          <el-form-item label="关停提示" prop="close_tip" v-if="formData.status === 0">
            <el-input v-model="formData.close_tip" placeholder="关停期间展示给会员的提示文字" clearable class="narrow-input" />
          </el-form-item>
        </el-tab-pane>

        <!-- ==================== 财务设置 ==================== -->
        <el-tab-pane label="财务设置" name="finance">
          <div class="group-title">支付与充值</div>

          <el-form-item label="余额支付" prop="moneypay">
            <el-radio-group v-model="formData.moneypay">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="充值功能" prop="recharge">
            <el-radio-group v-model="formData.recharge">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="充值门槛" prop="recharge_minimum">
            <el-input v-model="formData.recharge_minimum" placeholder="0.00" clearable class="narrow-input" />
            <span class="form-help">最低充值多少余额，单位：元，0为不限制</span>
          </el-form-item>

          <div class="group-title">余额提现</div>

          <el-form-item label="余额提现" prop="withdraw">
            <el-radio-group v-model="formData.withdraw">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="佣金提现" prop="comwithdraw">
            <el-radio-group v-model="formData.comwithdraw">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="提现方式">
            <el-checkbox-group v-model="withdrawMethods">
              <el-checkbox value="weixin">微信</el-checkbox>
              <el-checkbox value="aliaccount">支付宝</el-checkbox>
              <el-checkbox value="bankcard">银行卡</el-checkbox>
            </el-checkbox-group>
          </el-form-item>

          <el-form-item label="最低提现金额" prop="withdrawmin">
            <el-input v-model="formData.withdrawmin" placeholder="0.00" clearable class="narrow-input" />
            <span class="form-help">单位：元</span>
          </el-form-item>

          <el-form-item label="单笔最高提现" prop="withdrawmax">
            <el-input-number v-model="formData.withdrawmax" :min="0" :step="1" controls-position="right" class="mini-number" />
            <span class="form-help">单笔最高提现金额，单位：元，大于0生效，0为无限制</span>
          </el-form-item>

          <el-form-item label="提现手续费" prop="withdrawfee">
            <el-input v-model="formData.withdrawfee" placeholder="0.00" clearable class="narrow-input" />
            <span class="form-help">单位：元/笔</span>
          </el-form-item>

          <el-form-item label="每日提现次数" prop="day_withdraw_num">
            <el-input-number v-model="formData.day_withdraw_num" :min="0" :step="1" controls-position="right" class="mini-number" />
            <span class="form-help">会员每日余额和佣金可提现总次数，设为0时为无限制提现</span>
          </el-form-item>

          <el-form-item label="佣金最低提现" prop="comwithdrawmin">
            <el-input v-model="formData.comwithdrawmin" placeholder="0.00" clearable class="narrow-input" />
            <span class="form-help">单位：元</span>
          </el-form-item>

          <el-form-item label="佣金最高提现" prop="comwithdrawmax">
            <el-input-number v-model="formData.comwithdrawmax" :min="0" :step="1" controls-position="right" class="mini-number" />
            <span class="form-help">单位：元，0为无限制</span>
          </el-form-item>

          <el-form-item label="佣金提现手续费" prop="comwithdrawfee">
            <el-input v-model="formData.comwithdrawfee" placeholder="0.00" clearable class="narrow-input" />
            <span class="form-help">单位：%</span>
          </el-form-item>

          <el-form-item label="提现说明" prop="withdraw_desc">
            <el-input
              v-model="formData.withdraw_desc"
              type="textarea"
              :rows="3"
              placeholder="提现页面展示给会员的说明文字"
              class="wide-input"
            />
          </el-form-item>

          <!-- 余额宝（任务181） -->
          <div class="group-title">余额宝设置</div>

          <el-form-item label="余额宝功能">
            <el-radio-group v-model="ybForm.open_yuebao">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="日收益率">
            <el-input v-model="ybForm.yuebao_rate" placeholder="0" clearable class="narrow-input" />
            <span class="form-help">单位：%/日，每日收益 = 余额宝余额 × 收益率；会员可单独覆盖费率</span>
          </el-form-item>

          <el-form-item label="最低提现金额">
            <el-input v-model="ybForm.yuebao_withdrawmin" placeholder="0.00" clearable class="narrow-input" />
            <span class="form-help">单位：元</span>
          </el-form-item>

          <el-form-item label="提现手续费">
            <el-input v-model="ybForm.yuebao_withdrawfee" placeholder="0.00" clearable class="narrow-input" />
            <span class="form-help">单位：元/笔</span>
          </el-form-item>

          <el-form-item label="收益转余额">
            <el-radio-group v-model="ybForm.yuebao_turn_yue">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
            <span class="form-help">允许会员将余额宝收益转入余额</span>
          </el-form-item>

          <div class="group-title">发票设置</div>

          <el-form-item label="发票功能" prop="invoice">
            <el-radio-group v-model="formData.invoice">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="发票类型" prop="invoice_type" v-if="formData.invoice === 1">
            <el-radio-group v-model="formData.invoice_type">
              <el-radio label="1">普通发票</el-radio>
              <el-radio label="2">增值税专用发票</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="发票税率" prop="invoice_rate" v-if="formData.invoice === 1">
            <el-input v-model="formData.invoice_rate" placeholder="0.00" clearable class="narrow-input" />
            <span class="form-help">单位：%</span>
          </el-form-item>
        </el-tab-pane>

        <!-- ==================== 积分设置 ==================== -->
        <el-tab-pane label="积分设置" name="score">
          <el-form-item label="积分抵扣比例" prop="score2money">
            <el-input v-model="formData.score2money" placeholder="0.01" clearable class="narrow-input" />
            <span class="form-help">1 积分可抵扣的金额（元）</span>
          </el-form-item>

          <el-form-item label="最高抵扣比例" prop="scoredkmaxpercent">
            <el-input v-model="formData.scoredkmaxpercent" placeholder="100.00" clearable class="narrow-input" />
            <span class="form-help">下单时积分最高可抵扣订单金额的百分比</span>
          </el-form-item>

          <el-form-item label="积分不抵运费" prop="scorebdkyf">
            <el-radio-group v-model="formData.scorebdkyf">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
            <span class="form-help">开启后积分不能抵扣运费</span>
          </el-form-item>

          <el-form-item label="余额支付送积分" prop="score_from_moneypay">
            <el-radio-group v-model="formData.score_from_moneypay">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
            <span class="form-help">开启后余额支付订单同样按消费送积分规则赠送积分</span>
          </el-form-item>

          <el-form-item label="消费送积分" prop="scorein_money">
            <div class="field-line">
              <span class="inline-label">每消费</span>
              <el-input v-model="formData.scorein_money" placeholder="1.00" clearable class="mini-input" />
              <span class="inline-label">元送</span>
              <el-input-number v-model="formData.scorein_score" :min="0" :step="1" controls-position="right" class="mini-number" />
              <span class="inline-label">积分</span>
            </div>
          </el-form-item>

          <el-form-item label="充值送积分" prop="scorecz_money">
            <div class="field-line">
              <span class="inline-label">每充值</span>
              <el-input v-model="formData.scorecz_money" placeholder="1.00" clearable class="mini-input" />
              <span class="inline-label">元送</span>
              <el-input-number v-model="formData.scorecz_score" :min="0" :step="1" controls-position="right" class="mini-number" />
              <span class="inline-label">积分</span>
            </div>
          </el-form-item>

          <el-form-item label="积分转赠" prop="score_transfer">
            <el-radio-group v-model="formData.score_transfer">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
            <span class="form-help">开启后会员之间可互相转赠积分</span>
          </el-form-item>

          <el-form-item label="积分过期" prop="score_expire_status">
            <el-radio-group v-model="formData.score_expire_status">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
            <span class="form-help">开启后，达到过期天数的所有积分会过期，请谨慎操作</span>
          </el-form-item>

          <el-form-item label="过期天数" prop="score_expire_days" v-if="formData.score_expire_status === 1">
            <el-input-number v-model="formData.score_expire_days" :min="0" :step="1" controls-position="right" class="mini-number" />
            <span class="form-help">积分获得后多少天过期，0为不过期</span>
          </el-form-item>
        </el-tab-pane>

        <!-- ==================== 分销设置 ==================== -->
        <el-tab-pane label="分销设置" name="fenxiao">
          <div class="group-title">结算方式</div>

          <el-form-item label="分销结算方式" prop="fxjiesuantype">
            <el-radio-group v-model="formData.fxjiesuantype">
              <el-radio :label="0">按商品价格</el-radio>
              <el-radio :label="1">按成交价格</el-radio>
              <el-radio :label="2">按销售利润</el-radio>
            </el-radio-group>
            <span class="form-help">
              按商品价格：商品价格×提成百分比；按成交价格：成交价格×提成百分比（扣除会员折扣、满减、优惠券及积分抵扣）；按销售利润：（成交价格-商品成本）×提成百分比
            </span>
          </el-form-item>

          <el-form-item label="平级奖结算方式" prop="fxjiesuantype_pj">
            <el-radio-group v-model="formData.fxjiesuantype_pj">
              <el-radio :label="0">按上级奖励</el-radio>
              <el-radio :label="1">按商品价格</el-radio>
              <el-radio :label="2">按成交价格</el-radio>
              <el-radio :label="3">按销售利润</el-radio>
            </el-radio-group>
            <span class="form-help">仅适用于分销提成方式按百分比计算时；按上级奖励 = 上级分销奖励金额×平级奖百分比</span>
          </el-form-item>

          <el-form-item label="发放钱包" prop="commission_send_wallet">
            <el-radio-group v-model="formData.commission_send_wallet">
              <el-radio :label="0">到佣金</el-radio>
              <el-radio :label="1">到余额</el-radio>
            </el-radio-group>
            <span class="form-help">分销佣金结算后发放到的钱包</span>
          </el-form-item>

          <el-form-item label="发放等级限制" prop="commission_send_wallet_levelids" v-if="formData.commission_send_wallet === 1">
            <el-input
              v-model="formData.commission_send_wallet_levelids"
              placeholder="如：1,2,3；留空或 -1 表示不限制"
              clearable
              class="narrow-input"
            />
            <span class="form-help">仅限发放到余额时生效，逗号分隔会员等级ID；留空或 -1 表示所有等级</span>
          </el-form-item>

          <div class="group-title">结算周期</div>

          <el-form-item label="结算周期" prop="fxjiesuantime_perweek">
            <el-radio-group v-model="formData.fxjiesuantime_perweek">
              <el-radio :label="0">按日结算</el-radio>
              <el-radio :label="1">按周结算</el-radio>
            </el-radio-group>
            <span class="form-help">按周结算时下方延迟数值为星期几（1~7，7为周日）</span>
          </el-form-item>

          <el-form-item label="结算时间" prop="fxjiesuantime">
            <el-radio-group v-model="formData.fxjiesuantime">
              <el-radio :label="0">确认收货后</el-radio>
              <el-radio :label="1">付款后</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item :label="formData.fxjiesuantime_perweek === 1 ? '每周结算日' : '延迟结算天数'" prop="fxjiesuantime_delaydays">
            <el-input v-model="formData.fxjiesuantime_delaydays" placeholder="0" clearable class="mini-input" />
            <span class="inline-label">{{ formData.fxjiesuantime_perweek === 1 ? '结算（1~7，7为周日）' : '天结算' }}</span>
            <span class="form-help">0 代表付款或确认收货后立即结算；如果产生退款，已发放的佣金不会扣除</span>
          </el-form-item>

          <div class="group-title">级差设置</div>

          <el-form-item label="分销级差" prop="fx_differential">
            <el-radio-group v-model="formData.fx_differential">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
            <span class="form-help">开启后分销将按照级差进行计算，仅限按金额和比例方式</span>
          </el-form-item>
        </el-tab-pane>

        <!-- ==================== 登录注册 ==================== -->
        <el-tab-pane label="登录注册" name="login">
          <el-form-item label="小程序端登录方式">
            <el-checkbox-group v-model="logintypeWxList">
              <el-checkbox :value="1">注册登录</el-checkbox>
              <el-checkbox :value="2">手机号登录</el-checkbox>
              <el-checkbox :value="3">授权登录</el-checkbox>
            </el-checkbox-group>
            <span class="form-help">至少勾选一项</span>
          </el-form-item>

          <el-form-item label="H5端登录方式">
            <el-checkbox-group v-model="logintypeH5List">
              <el-checkbox :value="1">注册登录</el-checkbox>
              <el-checkbox :value="2">手机号登录</el-checkbox>
            </el-checkbox-group>
            <span class="form-help">至少勾选一项</span>
          </el-form-item>

          <el-form-item label="设置昵称" prop="login_setnickname">
            <el-radio-group v-model="formData.login_setnickname">
              <el-radio :label="0">不需要设置</el-radio>
              <el-radio :label="1">可选设置</el-radio>
              <el-radio :label="2">强制设置</el-radio>
            </el-radio-group>
            <span class="form-help">小程序端首次授权登录时是否提示设置头像昵称</span>
          </el-form-item>

          <el-form-item label="绑定手机号" prop="login_bind">
            <el-radio-group v-model="formData.login_bind">
              <el-radio :label="0">不绑定</el-radio>
              <el-radio :label="1">可选绑定</el-radio>
              <el-radio :label="2">强制绑定</el-radio>
            </el-radio-group>
            <span class="form-help">授权登录时是否提示绑定手机号</span>
          </el-form-item>

          <el-form-item label="邀请码" prop="reg_invite_code">
            <el-radio-group v-model="formData.reg_invite_code">
              <el-radio :label="0">关闭</el-radio>
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="2">强制邀请</el-radio>
            </el-radio-group>
            <span class="form-help">开启后注册时可填写邀请人邀请码，强制邀请则必须填写</span>
          </el-form-item>

          <el-form-item label="注册审核" prop="reg_check">
            <el-radio-group v-model="formData.reg_check">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
            <span class="form-help">开启后新注册会员需后台审核通过才可登录</span>
          </el-form-item>
        </el-tab-pane>

        <!-- ==================== 注册协议 ==================== -->
        <el-tab-pane label="注册协议" name="xieyi">
          <el-form-item label="注册协议" prop="xyinfo.status">
            <el-radio-group v-model="formData.xyinfo.status">
              <el-radio :label="1">开启</el-radio>
              <el-radio :label="0">关闭</el-radio>
            </el-radio-group>
            <span class="form-help">开启后注册/登录页展示协议勾选</span>
          </el-form-item>

          <el-form-item label="同意方式" prop="xyinfo.agree_type">
            <el-radio-group v-model="formData.xyinfo.agree_type">
              <el-radio :label="0">打勾同意</el-radio>
              <el-radio :label="1">阅读到最后同意</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="协议名称" prop="xyinfo.name">
            <el-input v-model="formData.xyinfo.name" placeholder="如：《用户注册协议》" clearable class="narrow-input" />
          </el-form-item>

          <el-form-item label="协议内容" prop="xyinfo.content">
            <div class="editor-wrap">
              <Toolbar :editor="editorRef1" :defaultConfig="toolbarConfig" style="border-bottom: 1px solid #e4e7ed" />
              <Editor
                v-model="formData.xyinfo.content"
                :defaultConfig="editorConfig"
                style="height: 300px; overflow-y: hidden"
                @onCreated="handleCreated1"
              />
            </div>
          </el-form-item>

          <el-form-item label="隐私政策名称" prop="xyinfo.name2">
            <el-input v-model="formData.xyinfo.name2" placeholder="如：《隐私政策》" clearable class="narrow-input" />
          </el-form-item>

          <el-form-item label="隐私政策内容" prop="xyinfo.content2">
            <div class="editor-wrap">
              <Toolbar :editor="editorRef2" :defaultConfig="toolbarConfig" style="border-bottom: 1px solid #e4e7ed" />
              <Editor
                v-model="formData.xyinfo.content2"
                :defaultConfig="editorConfig"
                style="height: 300px; overflow-y: hidden"
                @onCreated="handleCreated2"
              />
            </div>
          </el-form-item>
        </el-tab-pane>

        <!-- ==================== 文本自定义 ==================== -->
        <el-tab-pane label="文本自定义" name="textset">
          <el-form-item v-for="key in textsetKeys" :key="key" :label="key">
            <el-input v-model="formData.textset[key]" :placeholder="`替换「${key}」的展示文案`" clearable class="narrow-input" />
            <span class="form-help">左侧为原词，输入替换后展示给会员的文案</span>
          </el-form-item>
        </el-tab-pane>

        <!-- ==================== 附件设置（任务258：对照参考系统 sysset lay-id=10，位于加载图标之前） ==================== -->
        <el-tab-pane label="附件设置" name="attach" lazy>
          <AttachSet />
        </el-tab-pane>

        <!-- ==================== 加载图标 ==================== -->
        <el-tab-pane label="加载图标" name="loading">
          <el-form-item label="加载图标">
            <div class="field-block">
              <div class="field-line">
                <el-input v-model="formData.loading_icon" placeholder="加载图标地址" clearable class="narrow-input" />
                <el-button type="primary" @click="openImgPicker((u) => (formData.loading_icon = u))">选择图标</el-button>
              </div>
              <div class="form-help block-help">
                可自定义上传，最大尺寸：100×100像素；支持格式：png；修改图标后，需清理前端缓存！
              </div>
              <div class="img-preview" v-if="formData.loading_icon">
                <img :src="formData.loading_icon" alt="加载图标预览" />
              </div>
            </div>
          </el-form-item>
        </el-tab-pane>
      </el-tabs>

      <!-- 保存按钮（附件 Tab 用自身保存逻辑，隐藏主表单保存；当页保存不跳转） -->
      <div class="save-row" v-if="activeTab !== 'attach'">
        <el-button type="primary" :loading="saving" @click="handleSave">提交</el-button>
      </div>
    </el-form>

    <!-- 选择坐标弹层（无高德 key 降级：手动输入回填） -->
    <el-dialog v-model="coordDialogVisible" title="选择坐标" width="420px" append-to-body>
      <el-form label-width="80px">
        <el-form-item label="经度">
          <el-input v-model="coordForm.longitude" placeholder="如：113.625368" clearable />
        </el-form-item>
        <el-form-item label="纬度">
          <el-input v-model="coordForm.latitude" placeholder="如：34.746611" clearable />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="coordDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmCoord">确定</el-button>
      </template>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />

    <!-- 高德地图选点（任务308：复用系统既有高德选点逻辑） -->
    <MapPicker
      v-model:visible="mapPickerVisible"
      :map-key="mapConfig.map_key"
      :map-jscode="mapConfig.map_jscode"
      :map-qq-key="mapConfig.map_qq_key"
      :init-lng="formData.longitude"
      :init-lat="formData.latitude"
      @pick="onMapPick"
    />
  </div>
</template>

<style scoped>
.sysset-container {
  padding: 20px;
  max-width: 1100px;
}

/* ============ 参考系统风格：扁平行式表单（无卡片边框） ============ */

/* 每行：[右对齐标签] [窄输入框] [右侧说明]，行间距 22px */
:deep(.el-form-item) {
  margin-bottom: 22px;
}

:deep(.el-form-item__label) {
  color: #606266;
  font-weight: normal;
}

/* 内容区水平排列、垂直居中 */
:deep(.el-form-item__content) {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}

/* 分组标题（左灰竖条 + 加粗） */
.group-title {
  margin: 26px 0 18px;
  padding-left: 10px;
  border-left: 3px solid #409eff;
  font-size: 14px;
  font-weight: 600;
  color: #333;
}

.el-tab-pane .group-title:first-child {
  margin-top: 6px;
}

/* 窄输入框：固定 240px */
.narrow-input {
  width: 240px;
  flex: none;
}

/* 宽输入框（提现说明 textarea） */
.wide-input {
  width: 420px;
  flex: none;
}

/* 行内组合输入（消费送积分等） */
.mini-input {
  width: 110px;
  flex: none;
}

.mini-input-wide {
  width: 170px;
  flex: none;
}

/* 中宽输入框（客服系统链接等） */
.mid-input {
  width: 320px;
  flex: none;
}

/* 导航自定义颜色行（与上方单选保持间距） */
.nav-custom-line {
  margin-top: 8px;
}

.mini-number {
  width: 120px;
  flex: none;
}

.inline-label {
  color: #606266;
  margin: 0 8px;
}

/* 图片字段：首行控件 + 下方预览 */
.field-block {
  display: flex;
  flex-direction: column;
}

.field-line {
  display: flex;
  align-items: center;
  gap: 10px;
}

/* 说明文字：输入框右侧同行的灰色小字 */
.form-help {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
  line-height: 1.6;
}

.block-help {
  margin-left: 0;
  margin-top: 6px;
}

/* 富文本编辑器容器 */
.editor-wrap {
  width: 720px;
  border: 1px solid #e4e7ed;
  border-radius: 4px;
}

/* 保存按钮：缩进到输入框起始位置 */
.save-row {
  margin-top: 30px;
  margin-left: 130px;
}

/* 图片预览：输入框下方小图 */
.img-preview {
  margin-top: 10px;
  padding: 6px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  display: inline-block;
}

.img-preview img {
  height: 90px;
  max-width: 260px;
  object-fit: contain;
  display: block;
}

.kf-example {
  font-size: 12px;
  color: #606266;
  line-height: 1.8;
}
</style>
