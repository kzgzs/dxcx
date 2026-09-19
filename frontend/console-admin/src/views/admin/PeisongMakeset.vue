<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance } from 'element-plus'
import { getPeisongMakeset, savePeisongMakeset } from '../../api/peisong'
import { formatTime } from '../../utils/time'

// 租户后台 - 同城配送·码科跑腿对接（批次8 任务223；对标参考系统 Peisong::makeset/save2）
// 第三方 API 走 mock（.env PEISONG.MOCK=1）：只做配置页 + mock 回执，不做真实对接

const loading = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()
const isMock = ref(true)

const form = reactive({
  make_status: 0,
  make_domain: '',
  make_appid: '',
  make_token: '',
  make_shopkoufei: 0,
})

// 回执信息（mock access_token 状态）
const receipt = reactive({
  make_access_token: '',
  make_expire_time: 0,
  express_wx_status: 0,
})

const formRules = {
  make_domain: [{ required: true, message: '请输入对接域名', trigger: 'blur' }],
  make_appid: [{ required: true, message: '请输入 AppID', trigger: 'blur' }],
  make_token: [{ required: true, message: '请输入通信 Token', trigger: 'blur' }],
}

async function fetchSet() {
  loading.value = true
  try {
    const { info } = await getPeisongMakeset()
    if (info) {
      form.make_status = Number(info.make_status ?? 0)
      form.make_domain = info.make_domain || ''
      form.make_appid = info.make_appid || ''
      form.make_token = info.make_token || ''
      form.make_shopkoufei = Number(info.make_shopkoufei ?? 0)
      receipt.make_access_token = info.make_access_token || ''
      receipt.make_expire_time = Number(info.make_expire_time ?? 0)
      receipt.express_wx_status = Number(info.express_wx_status ?? 0)
      isMock.value = !!info.mock
    }
  } catch {
    // axios 拦截器已提示
  } finally {
    loading.value = false
  }
}

/** 保存（当页保存，不刷新不跳转；开启时后端 mock 获取 access_token） */
async function handleSave() {
  if (!formRef.value) return
  await formRef.value.validate()
  saving.value = true
  try {
    const rs = await savePeisongMakeset({ ...form })
    if (rs) {
      receipt.make_access_token = rs.make_access_token || ''
      receipt.make_expire_time = Number(rs.make_expire_time ?? 0)
    }
    ElMessage.success(rs && rs.mock ? '保存成功（mock 回执已生成）' : '保存成功')
  } catch {
    // axios 拦截器已提示
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchSet()
})
</script>

<template>
  <div v-loading="loading">
    <el-alert
      v-if="isMock"
      title="当前为 mock 模式（.env PEISONG.MOCK=1）：保存开启时生成模拟回执，不与码科服务器真实通信"
      type="info"
      :closable="false"
      class="mock-tip"
    />

    <el-form ref="formRef" :model="form" :rules="formRules" label-width="150px" class="set-form">
      <el-card shadow="never" class="set-card">
        <template #header><span class="card-title">码科跑腿对接</span></template>
        <el-form-item label="开启码科配送">
          <el-switch v-model="form.make_status" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后配送单由码科跑腿平台骑手接单（与即时配送互斥）</span>
        </el-form-item>
        <el-form-item label="对接域名" prop="make_domain">
          <el-input v-model="form.make_domain" placeholder="如：example.com（不含 https://）" />
        </el-form-item>
        <el-form-item label="AppID" prop="make_appid">
          <el-input v-model="form.make_appid" placeholder="码科平台分配的 appid" />
        </el-form-item>
        <el-form-item label="通信Token" prop="make_token">
          <el-input v-model="form.make_token" placeholder="码科平台分配的通信 token" />
        </el-form-item>
        <el-form-item label="取消扣费">
          <el-switch v-model="form.make_shopkoufei" :active-value="1" :inactive-value="0" />
          <span class="form-tip">取消码科配送单时是否扣除商家配送费</span>
        </el-form-item>
      </el-card>

      <el-card shadow="never" class="set-card">
        <template #header><span class="card-title">授权回执</span></template>
        <el-form-item label="access_token">
          <span class="receipt-val">{{ receipt.make_access_token || '未获取' }}</span>
        </el-form-item>
        <el-form-item label="有效期至">
          <span class="receipt-val">{{ receipt.make_expire_time ? formatTime(receipt.make_expire_time) : '-' }}</span>
        </el-form-item>
        <el-form-item v-if="receipt.express_wx_status === 1" label="互斥提示">
          <el-tag type="warning" size="small">即时配送已开启，开启码科前请先关闭即时配送</el-tag>
        </el-form-item>
      </el-card>

      <div class="save-row">
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </div>
    </el-form>
  </div>
</template>

<style scoped>
.mock-tip {
  max-width: 760px;
  margin-bottom: 12px;
}

.set-form {
  max-width: 760px;
}

.set-card {
  margin-bottom: 12px;
}

.card-title {
  font-weight: 600;
}

.form-tip {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
}

.receipt-val {
  color: #606266;
  word-break: break-all;
}

.save-row {
  padding: 4px 0 12px;
}
</style>
