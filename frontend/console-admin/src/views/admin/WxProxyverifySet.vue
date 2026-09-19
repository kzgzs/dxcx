<script setup lang="ts">
// 微信代认证 - 系统设置（批次7-8 任务216）
// wx_proxyverify_set：功能开关 + 服务市场appid/联系电话 + 三类客户代缴额度（企业/个体工商户/个人）
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getProxyverifySet, saveProxyverifySet } from '../../api/wxproxyverify'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  status: 0,
  service_appid: '',
  third_party_phone: '',
  all: 0,
  used: 0,
  all2: 0,
  used2: 0,
  all3: 0,
  used3: 0,
})
const updatetime = ref(0)

const num = computed(() => Math.max(0, form.all - form.used))
const num2 = computed(() => Math.max(0, form.all2 - form.used2))
const num3 = computed(() => Math.max(0, form.all3 - form.used3))

async function load() {
  loading.value = true
  try {
    const res = await getProxyverifySet()
    form.status = Number(res.status) || 0
    form.service_appid = res.service_appid || ''
    form.third_party_phone = res.third_party_phone || ''
    form.all = Number(res.all) || 0
    form.used = Number(res.used) || 0
    form.all2 = Number(res.all2) || 0
    form.used2 = Number(res.used2) || 0
    form.all3 = Number(res.all3) || 0
    form.used3 = Number(res.used3) || 0
    updatetime.value = Number(res.updatetime) || 0
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  if (form.status === 1 && !form.service_appid.trim()) {
    ElMessage.warning('开启功能后必须填写服务市场appid')
    return
  }
  if (form.status === 1 && !form.third_party_phone.trim()) {
    ElMessage.warning('开启功能后必须填写第三方联系电话')
    return
  }
  saving.value = true
  try {
    await saveProxyverifySet({ ...form })
    ElMessage.success('保存成功')
    load()
  } finally {
    saving.value = false
  }
}

function formatTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

onMounted(load)
</script>

<template>
  <div class="wxpv-set" v-loading="loading">
    <el-form label-width="150px" style="max-width: 760px">
      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="代缴额度说明"
        description="服务商在微信服务市场预购小程序认证包后，此处配置三类客户（企业/个体工商户/个人）的代缴额度；租户发起认证选择「消耗服务商预购包」时将扣减对应额度。"
        style="margin-bottom: 16px"
      />

      <el-form-item label="功能状态">
        <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="开启" inactive-text="关闭" />
      </el-form-item>
      <el-form-item label="服务市场appid">
        <el-input v-model="form.service_appid" maxlength="100" style="width: 320px" placeholder="微信服务市场appid" />
      </el-form-item>
      <el-form-item label="第三方联系电话">
        <el-input v-model="form.third_party_phone" maxlength="30" style="width: 320px" />
      </el-form-item>

      <el-divider content-position="left">企业主体代缴额度</el-divider>
      <el-form-item label="额度总量">
        <el-input-number v-model="form.all" :min="0" :max="999999" />
      </el-form-item>
      <el-form-item label="已使用">
        <el-input-number v-model="form.used" :min="0" :max="form.all" />
      </el-form-item>
      <el-form-item label="剩余可用">
        <el-tag :type="num > 0 ? 'success' : 'danger'">{{ num }} 次</el-tag>
      </el-form-item>

      <el-divider content-position="left">个体工商户主体代缴额度</el-divider>
      <el-form-item label="额度总量">
        <el-input-number v-model="form.all2" :min="0" :max="999999" />
      </el-form-item>
      <el-form-item label="已使用">
        <el-input-number v-model="form.used2" :min="0" :max="form.all2" />
      </el-form-item>
      <el-form-item label="剩余可用">
        <el-tag :type="num2 > 0 ? 'success' : 'danger'">{{ num2 }} 次</el-tag>
      </el-form-item>

      <el-divider content-position="left">个人主体代缴额度</el-divider>
      <el-form-item label="额度总量">
        <el-input-number v-model="form.all3" :min="0" :max="999999" />
      </el-form-item>
      <el-form-item label="已使用">
        <el-input-number v-model="form.used3" :min="0" :max="form.all3" />
      </el-form-item>
      <el-form-item label="剩余可用">
        <el-tag :type="num3 > 0 ? 'success' : 'danger'">{{ num3 }} 次</el-tag>
      </el-form-item>

      <el-form-item>
        <el-button type="primary" :loading="saving" @click="handleSave">保存设置</el-button>
        <span style="color: #999; font-size: 12px; margin-left: 12px">上次更新：{{ formatTime(updatetime) }}</span>
      </el-form-item>
    </el-form>
  </div>
</template>

<style scoped>
.wxpv-set {
  padding: 12px;
}
</style>
