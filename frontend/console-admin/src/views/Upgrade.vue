<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  checkUpgrade,
  doUpgrade,
  getUpgradeInfo,
  getUpgradeLog,
  type UpgradeLogItem,
} from '../api/upgrade'
import { formatDate } from '../utils/time'

/**
 * 系统升级（对照参考系统 web_upgrade/index.html：版本更新页）
 * 结构：当前版本与最新版本对比提示文案 + 检测更新 + 升级操作确认 + 升级日志时间轴
 * 交互规范：所有操作当页完成，不跳转
 */
const loading = ref(false)
const myversion = ref('-')
const newversion = ref('-')
const needupgrade = ref(0)
const remark = ref('')

// 检测更新（当页提示结果）
const checking = ref(false)
async function handleCheck() {
  checking.value = true
  try {
    const res = await checkUpgrade()
    myversion.value = res.myversion
    newversion.value = res.newversion
    needupgrade.value = res.needupgrade
    ElMessage({ type: res.needupgrade ? 'warning' : 'success', message: res.message })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    checking.value = false
  }
}

// 执行升级（对照参考系统 getfilllist 对比 → 升级操作确认弹窗 → 确认升级）
const upgrading = ref(false)
async function handleUpgrade() {
  upgrading.value = true
  const loadingMsg = ElMessage({ message: '正在比对升级所需文件...', type: 'info', duration: 0 })
  try {
    const res = await checkUpgrade()
    loadingMsg.close()
    if (!res.needupgrade) {
      // 对照参考系统：无更新时提示「暂无更新」
      ElMessage.success('暂无更新')
      syncInfo(res)
      return
    }
    syncInfo(res)
    await ElMessageBox.confirm(
      `最新版本为 V${res.newversion}，当前系统版本为 V${res.myversion}；确认升级后，系统将自动完成程序文件更新与数据库结构升级。`,
      '升级操作确认',
      { confirmButtonText: '确认升级', cancelButtonText: '取消', type: 'warning' },
    )
    await doUpgrade()
    ElMessage.success('升级成功')
    await fetchInfo()
    logLoaded.value = false
  } catch (e) {
    loadingMsg.close()
    // 取消升级无需提示；其他错误已由 axios 拦截器统一弹出
    void e
  } finally {
    upgrading.value = false
  }
}

function syncInfo(res: { myversion: string; newversion: string; needupgrade: number }) {
  myversion.value = res.myversion
  newversion.value = res.newversion
  needupgrade.value = res.needupgrade
}

// 升级日志（对照参考系统 showupgradelog 时间轴弹窗）
const logVisible = ref(false)
const logLoading = ref(false)
const logLoaded = ref(false)
const logList = ref<UpgradeLogItem[]>([])
async function showUpgradeLog() {
  logVisible.value = true
  if (logLoaded.value) return
  logLoading.value = true
  try {
    logList.value = await getUpgradeLog()
    logLoaded.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    logLoading.value = false
  }
}

async function fetchInfo() {
  loading.value = true
  try {
    const data = await getUpgradeInfo()
    myversion.value = data.myversion
    newversion.value = data.newversion
    needupgrade.value = data.needupgrade
    remark.value = data.remark || ''
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

onMounted(fetchInfo)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <!-- 版本对比提示 -->
    <div class="upgrade-body">
      <template v-if="needupgrade === 1">
        <div class="ver-line ver-line--dark">
          检测到新版本 V{{ newversion }}，当前系统版本为 V{{ myversion }}，点击“升级”按钮即可开始更新
        </div>
        <div v-if="remark" class="remark-block">
          <span class="remark-title">V{{ newversion }} 版本说明：</span><br />
          <span v-html="remark.replace(/\r\n/g, '<br>').replace(/\n/g, '<br>')"></span>
        </div>
      </template>
      <div v-else class="ver-line ver-line--gray">
        线上最新版本为 {{ newversion }}，系统已处于最新版本，无需执行升级。
      </div>

      <!-- 操作按钮：检测更新 / 升级 / 升级日志（当页操作不跳转） -->
      <div class="btn-row">
        <el-button type="primary" plain :loading="checking" @click="handleCheck">
          检测更新
        </el-button>
        <el-button
          v-if="needupgrade === 1"
          type="primary"
          :loading="upgrading"
          @click="handleUpgrade"
        >
          升级
        </el-button>
        <el-button plain @click="showUpgradeLog">升级日志</el-button>
      </div>
    </div>
  </el-card>

  <!-- 升级日志弹窗（表格化：版本号 / 日期 / 版本说明，长文本收缩可展开） -->
  <el-dialog v-model="logVisible" title="升级日志" width="640px" destroy-on-close>
    <div v-loading="logLoading" class="log-body">
      <el-table :data="logList" stripe border size="small" empty-text="暂无升级日志">
        <el-table-column label="版本号" width="110">
          <template #default="{ row }">
            <span class="log-version">V{{ row.version }}</span>
          </template>
        </el-table-column>
        <el-table-column label="日期" width="150">
          <template #default="{ row }">
            <span class="log-date">{{ formatDate(row.createtime) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="版本说明" min-width="260" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.remark">{{ String(row.remark).replace(/\r\n|\n/g, ' ') }}</span>
            <span v-else class="log-muted">—</span>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="暂无升级日志" :image-size="80" />
        </template>
      </el-table>
      <div class="log-init">初始版本</div>
    </div>
  </el-dialog>
</template>

<style scoped>
.upgrade-body {
  padding: 20px 10px;
  font-size: 16px;
}

.ver-line {
  padding: 10px 0;
}

.ver-line--dark {
  color: #333;
}

.ver-line--gray {
  color: #666;
}

.remark-block {
  padding: 20px 0;
}

.remark-title {
  color: #333;
  height: 40px;
  line-height: 40px;
}

.btn-row {
  margin-top: 20px;
}

.log-body {
  min-height: 120px;
  max-height: 420px;
  overflow-y: auto;
}

.log-version {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  white-space: nowrap;
}

.log-date {
  color: #666;
  font-size: 13px;
  white-space: nowrap;
}

.log-muted {
  color: #c0c4cc;
}

.log-init {
  margin-top: 10px;
  padding-left: 2px;
  color: #909399;
  font-size: 13px;
}
</style>
