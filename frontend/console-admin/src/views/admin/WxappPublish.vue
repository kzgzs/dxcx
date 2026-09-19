<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getWxappOverview,
  getWxappTemplateList,
  commitWxappCode,
  submitWxappAudit,
  getWxappAuditStatus,
  releaseWxapp,
  undoWxappAudit,
  revertWxappRelease,
  getWxappTrialQrcode,
  bindWxappTester,
  getWxalogList,
  type WxappOverview,
  type WxappTemplate,
  type WxappAuditStatus,
} from '../../api/wxapp'

const loading = ref(false)
const overview = ref<WxappOverview | null>(null)

// 步骤：0选模板 1上传 2提审 3审核状态 4发布
const step = ref(0)

// 模板
const templates = ref<WxappTemplate[]>([])
const templateId = ref<number>(0)

// 上传参数
const commitForm = ref({
  version: '1.0',
  desc: '',
  homeNavigationCustom: 0,
  usercenterNavigationCustom: 0,
  hideHomeButton: 0,
  navigationBarBackgroundColor: '#ffffff',
  navigationBarTextStyle: 'black',
})
const committing = ref(false)

// 审核
const audit = ref<WxappAuditStatus | null>(null)
const auditing = ref(false)

// 发布 / 回退
const releasing = ref(false)
const revertVersion = ref('')
const qrcode = ref('')
const testerWechatId = ref('')

// 更新记录
const logs = ref<Array<Record<string, unknown>>>([])
const logTotal = ref(0)
const logPage = ref(1)

const auditStatusMap: Record<number, { text: string; type: 'info' | 'warning' | 'success' | 'danger' }> = {
  0: { text: '已上传', type: 'info' },
  1: { text: '审核中', type: 'warning' },
  2: { text: '审核通过', type: 'success' },
  3: { text: '已发布', type: 'success' },
  4: { text: '审核驳回', type: 'danger' },
}

function auditTag(row: Record<string, unknown>) {
  return auditStatusMap[Number(row.audit_status ?? -1)] || { text: '未知', type: 'info' as const }
}

function fmtTime(v: unknown): string {
  const ts = Number(v)
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const selectedTemplate = computed(() => templates.value.find((t) => t.template_id === templateId.value) || null)

async function loadOverview() {
  try {
    overview.value = await getWxappOverview()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

async function loadTemplates() {
  loading.value = true
  try {
    const rs = await getWxappTemplateList()
    templates.value = rs.list || []
    if (templates.value.length && !templateId.value) {
      templateId.value = templates.value[0].template_id
    }
  } catch {
    templates.value = []
  } finally {
    loading.value = false
  }
}

async function loadLogs() {
  try {
    const rs = await getWxalogList(logPage.value, 10)
    logs.value = rs.list || []
    logTotal.value = rs.total || 0
  } catch {
    // 忽略
  }
}

// 第二步：上传代码
async function handleCommit() {
  if (!templateId.value) {
    ElMessage.warning('请选择上传模板')
    return
  }
  committing.value = true
  try {
    await commitWxappCode({
      template_id: templateId.value,
      version: commitForm.value.version,
      desc: commitForm.value.desc,
      homeNavigationCustom: commitForm.value.homeNavigationCustom,
      usercenterNavigationCustom: commitForm.value.usercenterNavigationCustom,
      hideHomeButton: commitForm.value.hideHomeButton,
      navigationBarBackgroundColor: commitForm.value.navigationBarBackgroundColor,
      navigationBarTextStyle: commitForm.value.navigationBarTextStyle,
    })
    ElMessage.success('上传成功')
    step.value = 2
    loadOverview()
    loadLogs()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    committing.value = false
  }
}

// 第三步：提交审核
async function handleSubmitAudit() {
  auditing.value = true
  try {
    const rs = await submitWxappAudit()
    audit.value = {
      audit_id: Number(rs.audit_id ?? 0),
      status: -1,
      reason: '',
      audit_status: 1,
    }
    ElMessage.success('提交成功，请等待审核')
    step.value = 3
    loadLogs()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    auditing.value = false
  }
}

// 第四步：刷新审核状态
async function handleRefreshAudit() {
  auditing.value = true
  try {
    audit.value = await getWxappAuditStatus(audit.value?.audit_id || 0)
    if (audit.value.audit_status === 2) {
      step.value = 4
    }
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    auditing.value = false
  }
}

// 撤回审核
async function handleUndoAudit() {
  try {
    await ElMessageBox.confirm('确认撤回本次提审？撤回后需重新提交审核。', '撤回审核', { type: 'warning' })
  } catch {
    return
  }
  try {
    await undoWxappAudit()
    ElMessage.success('撤销成功')
    audit.value = null
    step.value = 1
    loadLogs()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

// 第五步：发布
async function handleRelease() {
  releasing.value = true
  try {
    await releaseWxapp()
    ElMessage.success('发布成功')
    loadOverview()
    loadLogs()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    releasing.value = false
  }
}

// 版本回退
async function handleRevert() {
  if (!revertVersion.value) {
    ElMessage.warning('请填写要回退到的版本号')
    return
  }
  try {
    await ElMessageBox.confirm(`确认回退到版本 ${revertVersion.value}？`, '版本回退', { type: 'warning' })
  } catch {
    return
  }
  try {
    await revertWxappRelease(revertVersion.value)
    ElMessage.success('回退成功')
    loadLogs()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

// 体验版二维码
async function handleQrcode() {
  try {
    const rs = await getWxappTrialQrcode()
    qrcode.value = rs.qrcode
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

// 绑定体验者
async function handleBindTester() {
  if (!testerWechatId.value) {
    ElMessage.warning('请填写体验者微信号')
    return
  }
  try {
    await bindWxappTester(testerWechatId.value)
    ElMessage.success('添加成功')
    testerWechatId.value = ''
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

onMounted(() => {
  loadOverview()
  loadTemplates()
  loadLogs()
})
</script>

<template>
  <div v-loading="loading">
    <!-- 概览 -->
    <el-card shadow="never" class="mb-card">
      <template #header>
        <span class="card-title">小程序发布</span>
      </template>
      <p class="page-tip">
        发布流程：选择模板 → 上传代码 → 提交审核 → 查询审核状态 → 发布上线；支持撤回审核、版本回退与体验版二维码。
      </p>
      <div class="overview-row">
        <span>AppID：<b>{{ overview?.appid || '-' }}</b></span>
        <span>名称：<b>{{ overview?.nickname || '-' }}</b></span>
        <el-tag v-if="overview?.is_mock" type="warning" size="small">本地演练模式</el-tag>
      </div>

      <el-steps :active="step" finish-status="success" simple class="steps">
        <el-step title="选择模板" @click="step = 0" />
        <el-step title="上传代码" @click="step >= 1 && (step = 1)" />
        <el-step title="提交审核" @click="step >= 2 && (step = 2)" />
        <el-step title="审核状态" @click="step >= 3 && (step = 3)" />
        <el-step title="发布上线" @click="step >= 4 && (step = 4)" />
      </el-steps>
    </el-card>

    <!-- 第一步：选择模板 -->
    <el-card v-if="step === 0" shadow="never" class="mb-card">
      <template #header>
        <span class="card-title">第一步：选择模板</span>
        <el-button link type="primary" class="header-btn" @click="loadTemplates">刷新模板</el-button>
      </template>
      <el-empty v-if="!templates.length" description="请先在控制台开放平台设置中添加小程序模板" />
      <el-table
        v-else
        :data="templates"
        highlight-current-row
        @current-change="(row: WxappTemplate | null) => row && (templateId = row.template_id)"
      >
        <el-table-column width="50">
          <template #default="{ row }">
            <el-radio v-model="templateId" :value="row.template_id" :label="''">&nbsp;</el-radio>
          </template>
        </el-table-column>
        <el-table-column prop="template_id" label="模板ID" width="110" />
        <el-table-column prop="user_version" label="版本" width="100" />
        <el-table-column prop="user_desc" label="描述" min-width="180" show-overflow-tooltip />
        <el-table-column prop="source_miniprogram_name" label="来源小程序" min-width="150" show-overflow-tooltip />
        <el-table-column label="添加时间" width="160">
          <template #default="{ row }">{{ fmtTime(row.create_time) }}</template>
        </el-table-column>
      </el-table>
      <div class="action-row">
        <el-button type="primary" :disabled="!templateId" @click="step = 1">下一步</el-button>
        <span v-if="selectedTemplate" class="step-tip">已选模板：{{ selectedTemplate.user_version }}（{{ selectedTemplate.user_desc }}）</span>
      </div>
    </el-card>

    <!-- 第二步：上传代码 -->
    <el-card v-if="step === 1" shadow="never" class="mb-card">
      <template #header><span class="card-title">第二步：上传代码</span></template>
      <el-form label-width="160px" class="form-narrow">
        <el-form-item label="版本号">
          <el-input v-model="commitForm.version" placeholder="如 1.0" style="width: 220px" />
        </el-form-item>
        <el-form-item label="版本描述">
          <el-input v-model="commitForm.desc" placeholder="本次更新说明" style="width: 420px" />
        </el-form-item>
        <el-form-item label="首页导航自定义">
          <el-switch v-model="commitForm.homeNavigationCustom" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="会员中心导航自定义">
          <el-switch v-model="commitForm.usercenterNavigationCustom" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="隐藏首页按钮">
          <el-switch v-model="commitForm.hideHomeButton" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="导航栏背景色">
          <el-color-picker v-model="commitForm.navigationBarBackgroundColor" />
        </el-form-item>
        <el-form-item label="导航栏文字色">
          <el-radio-group v-model="commitForm.navigationBarTextStyle">
            <el-radio value="black">黑色</el-radio>
            <el-radio value="white">白色</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <div class="action-row">
        <el-button @click="step = 0">上一步</el-button>
        <el-button type="primary" :loading="committing" @click="handleCommit">上传代码</el-button>
        <span class="step-tip">上传时自动注入 ext.json（aid / baseurl / 底部菜单配置）</span>
      </div>
    </el-card>

    <!-- 第三步：提交审核 -->
    <el-card v-if="step === 2" shadow="never" class="mb-card">
      <template #header><span class="card-title">第三步：提交审核</span></template>
      <p class="page-tip">代码已上传为开发版本，可先获取体验二维码预览，确认无误后提交微信审核。</p>
      <div class="action-row">
        <el-button @click="handleQrcode">获取体验版二维码</el-button>
        <el-button type="primary" :loading="auditing" @click="handleSubmitAudit">提交审核</el-button>
      </div>
    </el-card>

    <!-- 第四步：审核状态 -->
    <el-card v-if="step === 3" shadow="never" class="mb-card">
      <template #header><span class="card-title">第四步：审核状态</span></template>
      <div v-if="audit" class="audit-box">
        <span>提审编号：{{ audit.audit_id }}</span>
        <el-tag :type="auditTag({ audit_status: audit.audit_status }).type">
          {{ auditTag({ audit_status: audit.audit_status }).text }}
        </el-tag>
        <span v-if="audit.reason" class="audit-reason">驳回原因：{{ audit.reason }}</span>
      </div>
      <p v-else class="page-tip">暂无提审记录，请先返回提交审核。</p>
      <div class="action-row">
        <el-button :loading="auditing" @click="handleRefreshAudit">刷新审核状态</el-button>
        <el-button type="danger" plain @click="handleUndoAudit">撤回审核</el-button>
        <el-button v-if="audit && audit.audit_status === 2" type="primary" @click="step = 4">去发布</el-button>
      </div>
    </el-card>

    <!-- 第五步：发布 / 回退 / 体验码 -->
    <el-card v-if="step === 4" shadow="never" class="mb-card">
      <template #header><span class="card-title">第五步：发布上线</span></template>
      <div class="action-row">
        <el-button type="primary" :loading="releasing" @click="handleRelease">发布上线</el-button>
        <el-button @click="handleQrcode">体验版二维码</el-button>
      </div>
      <div class="revert-row">
        <el-input v-model="revertVersion" placeholder="要回退到的版本号" style="width: 220px" />
        <el-button plain @click="handleRevert">版本回退</el-button>
        <span class="step-tip">回退后线上版本切换为指定历史版本</span>
      </div>
      <div class="tester-row">
        <el-input v-model="testerWechatId" placeholder="体验者微信号" style="width: 220px" />
        <el-button plain @click="handleBindTester">绑定体验者</el-button>
      </div>
    </el-card>

    <!-- 体验二维码弹窗展示 -->
    <el-card v-if="qrcode" shadow="never" class="mb-card">
      <template #header><span class="card-title">体验版二维码</span></template>
      <img :src="qrcode" alt="体验版二维码" class="qrcode-img" />
      <p class="page-tip">使用微信扫码体验开发版小程序。</p>
    </el-card>

    <!-- 更新记录 -->
    <el-card shadow="never">
      <template #header><span class="card-title">更新记录</span></template>
      <el-table :data="logs">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="action" label="操作" width="150" />
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag size="small" :type="auditTag(row).type">{{ auditTag(row).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="180" show-overflow-tooltip />
        <el-table-column prop="operator" label="操作人" width="110" />
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ fmtTime(row.create_time) }}</template>
        </el-table-column>
      </el-table>
      <div class="pager-row">
        <el-pagination
          v-model:current-page="logPage"
          layout="prev, pager, next"
          :page-size="10"
          :total="logTotal"
          @current-change="loadLogs"
        />
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.card-title {
  font-weight: 600;
}

.header-btn {
  float: right;
  margin-top: -2px;
}

.page-tip {
  margin: 0 0 14px;
  color: #909399;
  font-size: 13px;
}

.mb-card {
  margin-bottom: 12px;
}

.overview-row {
  display: flex;
  align-items: center;
  gap: 24px;
  margin-bottom: 16px;
  font-size: 13px;
  color: #606266;
}

.steps {
  margin-top: 8px;
}

.form-narrow {
  max-width: 640px;
}

.action-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 16px;
}

.step-tip {
  color: #909399;
  font-size: 12px;
}

.audit-box {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 6px;
  font-size: 13px;
  color: #606266;
}

.audit-reason {
  color: #f56c6c;
}

.revert-row,
.tester-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 12px;
}

.qrcode-img {
  width: 220px;
  height: 220px;
  display: block;
  margin-bottom: 10px;
}

.pager-row {
  margin-top: 12px;
  display: flex;
  justify-content: flex-end;
}
</style>
