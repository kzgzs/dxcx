<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { getBindingConfig, saveBindingConfig, type BindingConfig } from '../../api/binding'

// ============ 公众号/小程序 微信支付设置共用表单 ============
// 落库通道：BindingService admin_setapp_mp / admin_setapp_wx（白名单字段）
// 密钥类字段 GET 脱敏（***）回显为「已配置」徽标 + 空输入框，留空保存沿用原值

const props = defineProps<{
  platform: 'mp' | 'wx'
  pageTitle: string
  pageDesc: string
}>()

// 支付相关白名单字段（与后端 BindingService::PLATFORMS 对齐）
const PAY_FIELDS_MP = [
  'wxpay', 'wxpay_type', 'wxpay_sub_mchid', 'wxpay_mchid', 'wxpay_mchkey',
  'wxpay_apiclient_cert', 'wxpay_apiclient_key', 'wxpay_serial_no',
  'sxpay_mno', 'sxpay_mchkey', 'wxpay_wechatpay_pem',
  'transfer_scene_id', 'transfer_scene_type', 'transfer_scene_content',
  'wxpay_mchkey_v3', 'sign_type', 'public_key_id', 'public_key_pem', 'wxpay_plate_serialno',
] as const
const PAY_FIELDS_WX = [...PAY_FIELDS_MP, 'wxpay_sub_mchid2', 'sxpay_embedded'] as const

// 密钥类字段（后端脱敏回显 ***；前端回显为「已配置」，输入框置空）
const SECRET_FIELDS = ['wxpay_mchkey', 'wxpay_apiclient_cert', 'wxpay_apiclient_key', 'sxpay_mchkey', 'wxpay_mchkey_v3']

const formRef = ref<FormInstance>()
const loading = ref(false)
const saving = ref(false)
const bound = ref(false)
const form = reactive<Record<string, string | number>>({
  wxpay: 0,
  wxpay_type: 0,
  wxpay_sub_mchid: '',
  wxpay_sub_mchid2: '',
  wxpay_mchid: '',
  wxpay_mchkey: '',
  wxpay_apiclient_cert: '',
  wxpay_apiclient_key: '',
  wxpay_serial_no: '',
  sxpay_mno: '',
  sxpay_mchkey: '',
  sxpay_embedded: 0,
  wxpay_wechatpay_pem: '',
  transfer_scene_id: '1005',
  transfer_scene_type: '',
  transfer_scene_content: '',
  wxpay_mchkey_v3: '',
  sign_type: 0,
  public_key_id: '',
  public_key_pem: '',
  wxpay_plate_serialno: '',
})
// 已配置但未改动的密钥字段（回显徽标用）
const secretSet = ref<Set<string>>(new Set())

const payFields = computed(() => (props.platform === 'wx' ? PAY_FIELDS_WX : PAY_FIELDS_MP))
const isWxapp = computed(() => props.platform === 'wx')
const sceneIs1001 = computed(() => String(form.transfer_scene_id) === '1001')

// 纯数字商户号校验（非空时）
const mchidValidator = (_rule: unknown, value: string | number, callback: (e?: Error) => void) => {
  const v = String(value ?? '').trim()
  if (v !== '' && !/^\d+$/.test(v)) {
    callback(new Error('商户号应为纯数字'))
  } else {
    callback()
  }
}

const rules = computed<FormRules>(() => ({
  wxpay_mchid: [{ validator: mchidValidator, trigger: 'blur' }],
  wxpay_sub_mchid: [{ validator: mchidValidator, trigger: 'blur' }],
  wxpay_sub_mchid2: [{ validator: mchidValidator, trigger: 'blur' }],
}))

async function loadConfig() {
  loading.value = true
  try {
    const data: BindingConfig = (await getBindingConfig(props.platform)) || {}
    bound.value = String(data.appid ?? '') !== ''
    secretSet.value = new Set()
    for (const key of payFields.value) {
      const raw = data[key]
      if (SECRET_FIELDS.includes(key)) {
        // 脱敏回显：已配置显示徽标，输入框置空（留空保存沿用原值）
        secretSet.value.add(String(raw) === '***' ? key : '')
        form[key] = ''
        continue
      }
      if (key === 'wxpay' || key === 'wxpay_type' || key === 'sign_type' || key === 'sxpay_embedded') {
        form[key] = Number(raw ?? 0) || 0
        continue
      }
      form[key] = raw === undefined || raw === null ? '' : String(raw)
    }
    secretSet.value.delete('')
    if (String(form.transfer_scene_id) === '') form.transfer_scene_id = '1005'
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

async function onSave() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }
  saving.value = true
  try {
    const payload: BindingConfig = {}
    for (const key of payFields.value) {
      payload[key] = form[key] ?? ''
    }
    await saveBindingConfig(props.platform, payload)
    ElMessage.success('保存成功')
    await loadConfig()
  } catch {
    /* 拦截器已提示 */
  } finally {
    saving.value = false
  }
}

function secretTip(key: string): string {
  return secretSet.value.has(key) ? '已配置，留空保存则沿用原值' : '未配置'
}

onMounted(loadConfig)
</script>

<template>
  <div class="payset-page">
    <div class="page-head">
      <h2>{{ pageTitle }}</h2>
      <p>{{ pageDesc }}</p>
    </div>

    <el-alert
      v-if="!loading && !bound"
      class="unbound-alert"
      type="warning"
      :closable="false"
      show-icon
      title="当前尚未绑定 AppID"
      description="请先在「绑定」页面完成 AppID 绑定后再保存支付配置，否则保存会被拒绝。"
    />

    <el-form
      ref="formRef"
      v-loading="loading"
      :model="form"
      :rules="rules"
      label-width="180px"
      label-position="right"
      class="payset-form"
    >
      <!-- 支付开关 -->
      <div class="group-title">支付开关</div>
      <el-form-item label="微信支付状态">
        <el-radio-group v-model="form.wxpay">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span v-if="!isWxapp" class="field-tip">
          JSAPI 支付授权目录请填写当前站点域名（以 / 结尾）
        </span>
      </el-form-item>

      <!-- 支付模式 -->
      <el-form-item label="微信支付模式">
        <el-radio-group v-model="form.wxpay_type">
          <el-radio :value="0">普通模式</el-radio>
          <el-radio :value="1">服务商模式</el-radio>
          <el-radio v-if="isWxapp" :value="2">二级商户模式</el-radio>
          <el-radio :value="3">随行付</el-radio>
        </el-radio-group>
      </el-form-item>

      <!-- 服务商模式 -->
      <template v-if="form.wxpay_type === 1">
        <div class="group-title">服务商模式配置</div>
        <el-form-item label="支付商户号" prop="wxpay_sub_mchid">
          <el-input v-model="form.wxpay_sub_mchid" placeholder="必须填子商户的商户号" style="max-width: 360px" />
          <span class="field-tip">必须填子商户的商户号</span>
        </el-form-item>
      </template>

      <!-- 二级商户模式（仅小程序） -->
      <template v-if="isWxapp && form.wxpay_type === 2">
        <div class="group-title">二级商户模式配置</div>
        <el-form-item label="支付商户号" prop="wxpay_sub_mchid2">
          <el-input v-model="form.wxpay_sub_mchid2" placeholder="二级商户的商户号" style="max-width: 360px" />
        </el-form-item>
      </template>

      <!-- 随行付 -->
      <template v-if="form.wxpay_type === 3">
        <div class="group-title">随行付配置</div>
        <el-form-item label="商户编号">
          <el-input v-model="form.sxpay_mno" placeholder="随行付商户编号" style="max-width: 360px" />
        </el-form-item>
        <el-form-item label="支付密钥">
          <el-input
            v-model="form.sxpay_mchkey"
            type="password"
            show-password
            :placeholder="secretTip('sxpay_mchkey')"
            style="max-width: 360px"
          />
          <el-tag v-if="secretSet.has('sxpay_mchkey')" size="small" type="success" class="secret-tag">已配置</el-tag>
        </el-form-item>
        <el-form-item v-if="isWxapp" label="随行付小程序收银台">
          <el-radio-group v-model="form.sxpay_embedded">
            <el-radio :value="0">关闭</el-radio>
            <el-radio :value="1">开启</el-radio>
          </el-radio-group>
          <span class="field-tip">调用随行付（半屏）小程序支付，未审核通过前以全屏方式调用</span>
        </el-form-item>
      </template>

      <!-- 普通模式（直连商户） -->
      <template v-if="form.wxpay_type === 0">
        <div class="group-title">普通模式配置（直连商户）</div>
        <el-form-item label="支付商户号" prop="wxpay_mchid">
          <el-input v-model="form.wxpay_mchid" placeholder="微信支付商户号（纯数字）" style="max-width: 360px" />
        </el-form-item>
        <el-form-item label="支付密钥">
          <el-input
            v-model="form.wxpay_mchkey"
            type="password"
            show-password
            :placeholder="secretTip('wxpay_mchkey')"
            style="max-width: 360px"
          />
          <el-tag v-if="secretSet.has('wxpay_mchkey')" size="small" type="success" class="secret-tag">已配置</el-tag>
          <span class="field-tip">请在 微信支付商户平台 [账户中心]-[API安全] 中设置 [APIv2密钥]</span>
        </el-form-item>
        <el-form-item label="PEM证书">
          <el-input
            v-model="form.wxpay_apiclient_cert"
            type="textarea"
            :rows="3"
            :placeholder="secretTip('wxpay_apiclient_cert')"
          />
          <el-tag v-if="secretSet.has('wxpay_apiclient_cert')" size="small" type="success" class="secret-tag">已配置</el-tag>
          <span class="field-tip">apiclient_cert.pem 证书内容（可含 PEM 头尾）</span>
        </el-form-item>
        <el-form-item label="证书密钥">
          <el-input
            v-model="form.wxpay_apiclient_key"
            type="textarea"
            :rows="3"
            :placeholder="secretTip('wxpay_apiclient_key')"
          />
          <el-tag v-if="secretSet.has('wxpay_apiclient_key')" size="small" type="success" class="secret-tag">已配置</el-tag>
          <span class="field-tip">apiclient_key.pem 商户API私钥内容（可含 PEM 头尾）</span>
        </el-form-item>
        <el-form-item label="证书序列号">
          <el-input v-model="form.wxpay_serial_no" placeholder="使用商家转账到零钱功能时填写，否则可不填" style="max-width: 420px" />
          <span class="field-tip">请在 微信支付商户平台 [账户中心]-[API安全]-[管理证书] 中查看</span>
        </el-form-item>

        <!-- APIv3 配置 -->
        <div class="group-title">商家转账配置（APIv3配置）</div>
        <el-form-item label="V3支付密钥">
          <el-input
            v-model="form.wxpay_mchkey_v3"
            type="password"
            show-password
            :placeholder="secretTip('wxpay_mchkey_v3')"
            style="max-width: 360px"
          />
          <el-tag v-if="secretSet.has('wxpay_mchkey_v3')" size="small" type="success" class="secret-tag">已配置</el-tag>
          <span class="field-tip">请在 微信支付商户平台 [账户中心]-[API安全] 中设置 [APIv3密钥]</span>
        </el-form-item>
        <el-form-item label="签名验签方式">
          <el-radio-group v-model="form.sign_type">
            <el-radio :value="0">平台证书</el-radio>
            <el-radio :value="1">微信支付公钥</el-radio>
          </el-radio-group>
          <span class="field-tip">根据 [账户中心]-[API安全]-[验证微信支付身份] 的设置选择，不要随意切换</span>
        </el-form-item>

        <template v-if="form.sign_type === 0">
          <el-form-item label="平台证书序列号">
            <el-input v-model="form.wxpay_plate_serialno" placeholder="平台证书序列号" style="max-width: 420px" />
            <span class="field-tip">获取方式：[账户中心]-[API安全]-[平台证书]-[管理证书]</span>
          </el-form-item>
          <el-form-item label="平台证书">
            <el-input
              v-model="form.wxpay_wechatpay_pem"
              type="textarea"
              :rows="3"
              placeholder="wechatpay.pem 平台证书内容"
            />
            <span class="field-tip">请在 微信支付商户平台 [账户中心]-[API安全]-[验证微信支付身份] 中下载证书后粘贴</span>
          </el-form-item>
        </template>

        <template v-else>
          <el-form-item label="公钥ID">
            <el-input v-model="form.public_key_id" placeholder="微信支付公钥 ID" style="max-width: 420px" />
          </el-form-item>
          <el-form-item label="公钥文件">
            <el-input
              v-model="form.public_key_pem"
              type="textarea"
              :rows="3"
              placeholder="pub_key.pem 微信支付公钥内容"
            />
            <span class="field-tip">请在 微信支付商户平台 [账户中心]-[API安全] 中设置 [微信支付公钥] 后粘贴公钥内容</span>
          </el-form-item>
        </template>

        <el-form-item label="商家转账场景">
          <el-select v-model="form.transfer_scene_id" style="max-width: 220px">
            <el-option label="现金营销" value="1001" />
            <el-option label="佣金报酬" value="1005" />
          </el-select>
          <span class="field-tip">该笔转账使用的转账场景，目前仅支持【1001-现金营销、1005-佣金报酬】</span>
        </el-form-item>
        <el-form-item :label="sceneIs1001 ? '活动名称' : '岗位类型'">
          <el-input v-model="form.transfer_scene_type" style="max-width: 360px" />
          <span class="field-tip">
            {{
              sceneIs1001
                ? '请描述用户参与活动的名称，如新会员有礼'
                : '请描述收款用户的岗位类型，如外卖员、专家顾问，不填写默认【员工】'
            }}
          </span>
        </el-form-item>
        <el-form-item :label="sceneIs1001 ? '奖励说明' : '报酬说明'">
          <el-input v-model="form.transfer_scene_content" style="max-width: 360px" />
          <span class="field-tip">
            {{
              sceneIs1001
                ? '请描述用户因为什么奖励获取这笔资金，如注册会员抽奖一等奖'
                : '请描述用户接收当前这笔报酬的原因，如7月份配送费、高温补贴，不填写默认审核备注'
            }}
          </span>
        </el-form-item>
      </template>

      <el-form-item class="save-row">
        <el-button type="primary" :loading="saving" @click="onSave">保存配置</el-button>
        <span class="save-tip">密钥类字段保存后不再明文显示，留空表示沿用原值。</span>
      </el-form-item>
    </el-form>
  </div>
</template>

<style scoped>
.payset-page {
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
.unbound-alert {
  margin-bottom: 16px;
  max-width: 860px;
}
.payset-form {
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
.field-tip {
  display: block;
  font-size: 12px;
  color: #8a919f;
  line-height: 1.6;
  margin-top: 2px;
}
:deep(.el-form-item__content) {
  flex-wrap: wrap;
}
.secret-tag {
  margin-left: 8px;
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
