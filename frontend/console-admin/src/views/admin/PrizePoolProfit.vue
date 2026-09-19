<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getProfitConfig,
  getProfitLog,
  saveProfitConfig,
  sendProfitNow,
} from '../../api/prizepool'
import type { PrizePoolProfitLogItem } from '../../api/prizepool'
import { getMemberLevelList } from '../../api/member'
import type { MemberLevelItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// ===== 配置表单 =====
const saving = ref(false)
const sending = ref(false)
const form = reactive({
  status: 1,
  pool_num: '0.00',
  pool_time: 1,
  send_bili: 0,
  send_time: 0,
  show_pool: 0,
})

// 等级比例编辑：[{levelid, bili, checked}]
const levels = ref<MemberLevelItem[]>([])
const levelRows = reactive<{ levelid: number; name: string; checked: boolean; bili: number }[]>([])

async function fetchConfig() {
  try {
    const data = await getProfitConfig()
    form.status = Number(data.status)
    form.pool_num = String(data.pool_num)
    form.pool_time = Number(data.pool_time)
    form.send_bili = Number(data.send_bili)
    form.send_time = Number(data.send_time)
    form.show_pool = Number(data.show_pool)

    // 解析等级比例 JSON
    let saved: Record<string, number> = {}
    try {
      saved = data.levelids ? JSON.parse(data.levelids) : {}
    } catch {
      saved = {}
    }
    levelRows.forEach((row) => {
      const key = String(row.levelid)
      if (key in saved) {
        row.checked = true
        row.bili = Number(saved[key])
      } else {
        row.checked = false
        row.bili = 0
      }
    })
  } catch {
    // 拦截器已提示
  }
}

async function fetchLevels() {
  try {
    const data = await getMemberLevelList()
    levels.value = data.list || []
    levelRows.splice(
      0,
      levelRows.length,
      ...levels.value.map((lv) => ({ levelid: lv.id, name: lv.name, checked: false, bili: 0 })),
    )
  } catch {
    // ignore
  }
}

function buildLevelids(): string {
  const map: Record<string, number> = {}
  levelRows.forEach((row) => {
    if (row.checked && row.bili > 0) {
      map[String(row.levelid)] = row.bili
    }
  })
  return JSON.stringify(map)
}

function checkedBiliSum(): number {
  return levelRows.filter((r) => r.checked).reduce((s, r) => s + Number(r.bili || 0), 0)
}

async function handleSave() {
  if (form.send_bili < 0 || form.send_bili > 100) {
    ElMessage.warning('发放比例需在 0-100 之间')
    return
  }
  const sum = checkedBiliSum()
  if (sum > 0 && Math.abs(sum - 100) > 0.01) {
    ElMessage.warning('已勾选等级的比例之和须为 100%')
    return
  }
  saving.value = true
  try {
    await saveProfitConfig({
      status: form.status,
      pool_time: form.pool_time,
      send_bili: form.send_bili,
      levelids: buildLevelids(),
      send_time: form.send_time,
      show_pool: form.show_pool,
    })
    ElMessage.success('保存成功')
    fetchConfig()
  } catch {
    // ignore
  } finally {
    saving.value = false
  }
}

async function handleSend() {
  try {
    await ElMessageBox.confirm('立即按当前配置发放奖金池金额？', '手动发放', { type: 'warning' })
  } catch {
    return
  }
  sending.value = true
  try {
    const data = await sendProfitNow()
    ElMessage.success(`发放成功，入账 ${data.sent} 人`)
    fetchConfig()
    fetchLog()
  } catch {
    // ignore
  } finally {
    sending.value = false
  }
}

// ===== 池流水 =====
const logLoading = ref(false)
const logData = ref<PrizePoolProfitLogItem[]>([])
const logTotal = ref(0)
const logQuery = reactive({ page: 1, pagesize: 10 })

async function fetchLog() {
  logLoading.value = true
  try {
    const data = await getProfitLog(logQuery)
    logData.value = data.list || []
    logTotal.value = data.total || 0
  } catch {
    // ignore
  } finally {
    logLoading.value = false
  }
}

onMounted(async () => {
  await fetchLevels()
  fetchConfig()
  fetchLog()
})
</script>

<template>
  <div>
    <!-- 池余额 -->
    <el-card shadow="never" class="balance-card">
      <div class="balance-label">当前奖金池金额</div>
      <div class="balance-value">￥{{ Number(form.pool_num).toFixed(2) }}</div>
      <el-button type="primary" size="small" :loading="sending" style="margin-top: 8px" @click="handleSend">
        手动发放
      </el-button>
    </el-card>

    <!-- 配置 -->
    <el-card shadow="never" class="form-card">
      <el-form label-width="130px" style="max-width: 640px">
        <el-form-item label="开启状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="开启" inactive-text="关闭" />
        </el-form-item>
        <el-form-item label="入池时机">
          <el-radio-group v-model="form.pool_time">
            <el-radio :value="0">支付后</el-radio>
            <el-radio :value="1">收货后</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="发放总比例(%)">
          <el-input-number v-model="form.send_bili" :min="0" :max="100" :precision="2" :step="1" style="width: 160px" />
          <span class="form-tip">每次发放 = 池金额 × 该比例</span>
        </el-form-item>
        <el-form-item label="每日发放时间">
          <el-select v-model="form.send_time" style="width: 120px">
            <el-option v-for="h in 24" :key="h - 1" :label="`${h - 1} 点`" :value="h - 1" />
          </el-select>
          <span class="form-tip">到点自动发放（当日幂等）</span>
        </el-form-item>
        <el-form-item label="前台展示">
          <el-radio-group v-model="form.show_pool">
            <el-radio :value="0">全部金额</el-radio>
            <el-radio :value="1">比例金额</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="参与等级">
          <el-table :data="levelRows" border size="small" style="width: 420px">
            <el-table-column label="参与" width="60" class-name="compact-col">
              <template #default="{ row }">
                <el-checkbox v-model="row.checked" />
              </template>
            </el-table-column>
            <el-table-column prop="name" label="等级" min-width="120" />
            <el-table-column label="比例(%)" width="160" class-name="compact-col">
              <template #default="{ row }">
                <el-input-number v-model="row.bili" :min="0" :max="100" :precision="2" size="small" :disabled="!row.checked" style="width: 130px" />
              </template>
            </el-table-column>
          </el-table>
          <div class="form-tip">勾选等级的比例之和须为 100%，发放时按等级名单平分</div>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保存配置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 流水 -->
    <el-card shadow="never">
      <div class="section-title">奖金池流水（入池为正 / 发放为负）</div>
      <el-table v-loading="logLoading" :data="logData" stripe border size="small">
        <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
        <el-table-column label="金额" width="100" class-name="compact-col">
          <template #default="{ row }">
            <span :class="Number(row.num) >= 0 ? 'money-plus' : 'money-minus'">
              {{ Number(row.num) >= 0 ? '+' : '' }}{{ Number(row.num).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="会员" min-width="100" show-overflow-tooltip>
          <template #default="{ row }">{{ row.nickname || (row.mid ? `会员${row.mid}` : '-') }}</template>
        </el-table-column>
        <el-table-column label="订单号" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.ordernum || '-' }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="130" show-overflow-tooltip />
        <el-table-column label="时间" width="140" class-name="compact-col">
          <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, prev, pager, next"
        :total="logTotal"
        :current-page="logQuery.page"
        :page-size="logQuery.pagesize"
        @current-change="(p: number) => { logQuery.page = p; fetchLog() }"
      />
    </el-card>
  </div>
</template>

<style scoped>
.balance-card {
  margin-bottom: 12px;
}

.balance-label {
  color: #909399;
  font-size: 13px;
}

.balance-value {
  font-size: 26px;
  font-weight: 700;
  color: #409eff;
  margin-top: 4px;
}

.form-card {
  margin-bottom: 12px;
}

.form-tip {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
}

.section-title {
  font-weight: 600;
  margin-bottom: 10px;
}

.money-plus {
  color: #67c23a;
  font-weight: 600;
}

.money-minus {
  color: #f56c6c;
  font-weight: 600;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
