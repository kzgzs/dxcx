<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  fenhongExamineSend,
  fenhongLogList,
  fenhongPreview,
  fenhongSendAll,
  fenhongSettle,
} from '../../api/fenhong'
import type {
  FenhongLogItem,
  FenhongLogStat,
  FenhongPreviewResult,
  FenhongRange,
  FenhongType,
} from '../../api/fenhong'
import { formatTime } from '../../utils/time'

/**
 * 三套分红共用页（任务183 批次3-C）：时段预览（上月/本月/昨日/今日/全部）
 * + 结算生成（og.isfenhong=0 → member_fenhonglog 待发放）
 * + 待发放记录列表与发放审核（通过入账/驳回）+ 一键发放
 */
const props = defineProps<{
  type: FenhongType
  title: string
  desc?: string
}>()

const RANGE_OPTIONS: { label: string; value: FenhongRange }[] = [
  { label: '上月', value: 1 },
  { label: '本月', value: 2 },
  { label: '昨日', value: 3 },
  { label: '今日', value: 4 },
  { label: '全部', value: 10 },
]

const STATUS_TAG: Record<number, 'warning' | 'success' | 'danger' | 'info'> = {
  0: 'warning',
  1: 'success',
  2: 'danger',
}

// ---- 时段预览 ----
const range = ref<FenhongRange>(10)
const previewing = ref(false)
const settling = ref(false)
const preview = ref<FenhongPreviewResult | null>(null)

async function handlePreview() {
  previewing.value = true
  try {
    preview.value = await fenhongPreview(props.type, range.value)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    previewing.value = false
  }
}

async function handleSettle() {
  try {
    await ElMessageBox.confirm(
      '结算将扫描该时段未分红的订单并生成分红记录（待发放），已分红订单不会重复计算，确认执行？',
      '结算生成',
      { type: 'warning' }
    )
  } catch {
    return
  }

  settling.value = true
  try {
    const res = await fenhongSettle(range.value)
    ElMessage.success(
      `结算完成：扫描订单行 ${res.ogcount}，生成分红记录 ${res.logs} 条，金额 ￥${Number(res.total).toFixed(2)}`
    )
    handlePreview()
    query.page = 1
    fetchLogs()
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    settling.value = false
  }
}

// ---- 待发放记录 ----
const loading = ref(false)
const sending = ref(false)
const total = ref(0)
const logs = ref<FenhongLogItem[]>([])
const stat = ref<FenhongLogStat>({ pending: 0, paid: 0, pending_money: 0 })

const query = reactive({
  page: 1,
  pagesize: 10,
  status: '0',
})

async function fetchLogs() {
  loading.value = true
  try {
    const data = await fenhongLogList(props.type, query.status === '' ? -1 : Number(query.status), query.page, query.pagesize)
    logs.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || { pending: 0, paid: 0, pending_money: 0 }
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    loading.value = false
  }
}

async function handleExamine(row: FenhongLogItem, st: 1 | 2) {
  const action = st === 1 ? '发放入账' : '驳回'
  try {
    await ElMessageBox.confirm(
      `确认${action}该条分红记录（￥${Number(row.commission).toFixed(2)} → ${row.nickname}）？`,
      '发放审核',
      { type: 'warning' }
    )
  } catch {
    return
  }

  try {
    await fenhongExamineSend(row.id, st)
    ElMessage.success(st === 1 ? '发放成功' : '已驳回')
    fetchLogs()
  } catch {
    // 错误提示已由拦截器弹出
  }
}

async function handleSendAll() {
  try {
    await ElMessageBox.confirm(
      `确认一键发放全部待发放记录（${stat.value.pending} 条，￥${Number(stat.value.pending_money).toFixed(2)}）？`,
      '一键发放',
      { type: 'warning' }
    )
  } catch {
    return
  }

  sending.value = true
  try {
    const res = await fenhongSendAll(props.type)
    ElMessage.success(`发放完成：${res.sent} 条入账，${res.skipped} 条跳过`)
    fetchLogs()
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    sending.value = false
  }
}

function shortDate(ts: number): string {
  if (!ts) return '-'
  const full = formatTime(ts)
  return full.length >= 10 ? full.slice(2, 10) : full
}

function amountOf(row: FenhongLogItem): string {
  const money = Number(row.money || 0)
  const score = Number(row.score || 0)
  if (money > 0) return `余额 ￥${money.toFixed(2)}`
  if (score > 0) return `积分 ${score}`
  return `佣金 ￥${Number(row.commission).toFixed(2)}`
}

onMounted(() => {
  handlePreview()
  fetchLogs()
})
</script>

<template>
  <div>
    <el-row :gutter="12" class="stat-row">
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">待发放</div>
          <div class="stat-value warning">{{ stat.pending }}</div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">待发放金额</div>
          <div class="stat-value primary">￥{{ Number(stat.pending_money).toFixed(2) }}</div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">已发放</div>
          <div class="stat-value success">{{ stat.paid }}</div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 时段预览与结算 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="统计时段">
          <el-select v-model="range" style="width: 100px">
            <el-option v-for="o in RANGE_OPTIONS" :key="o.value" :label="o.label" :value="o.value" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="previewing" @click="handlePreview">预览</el-button>
          <el-button type="warning" :loading="settling" @click="handleSettle">结算生成</el-button>
          <el-button type="success" :loading="sending" :disabled="stat.pending <= 0" @click="handleSendAll">一键发放</el-button>
        </el-form-item>
      </el-form>
      <div v-if="desc" class="desc-text">{{ desc }}</div>
    </el-card>

    <el-card v-if="preview" shadow="never" class="preview-card">
      <template #header>
        <div class="preview-header">
          <span>{{ title }}预览</span>
          <span class="sub-text">
            订单行 {{ preview.ogcount }} / 分红基数 ￥{{ Number(preview.allprice).toFixed(2) }} /
            分红总额 ￥{{ Number(preview.total).toFixed(2) }} / 受益会员 {{ preview.membercount }}
          </span>
        </div>
      </template>
      <el-table :data="preview.list" stripe border size="small" max-height="360">
        <el-table-column prop="mid" label="会员ID" width="80" class-name="compact-col" />
        <el-table-column prop="nickname" label="昵称" min-width="130" show-overflow-tooltip />
        <el-table-column prop="levelname" label="等级" width="110" class-name="compact-col" show-overflow-tooltip />
        <el-table-column label="分红金额" width="110" class-name="compact-col">
          <template #default="{ row }">
            <span class="success-text">+{{ Number(row.commission).toFixed(2) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="ogcount" label="订单行数" width="90" class-name="compact-col" />
      </el-table>
    </el-card>

    <!-- 分红记录与发放审核 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="发放状态">
          <el-select v-model="query.status" style="width: 100px" @change="query.page = 1; fetchLogs()">
            <el-option label="待发放" value="0" />
            <el-option label="已发放" value="1" />
            <el-option label="已驳回" value="2" />
            <el-option label="全部" value="" />
          </el-select>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="logs" stripe border size="small">
        <el-table-column prop="id" label="ID" width="64" class-name="compact-col" />
        <el-table-column label="会员" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.nickname || `会员${row.mid}` }}
            <span class="sub-text">ID:{{ row.mid }}</span>
          </template>
        </el-table-column>
        <el-table-column label="分红金额" width="100" class-name="compact-col">
          <template #default="{ row }">
            <span class="success-text">+{{ Number(row.commission).toFixed(2) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="入账载体" width="120" class-name="compact-col">
          <template #default="{ row }">{{ amountOf(row) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="84" class-name="compact-col">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="时间" width="92" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ shortDate(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right" class-name="compact-col">
          <template #default="{ row }">
            <template v-if="row.status === 0">
              <el-button type="primary" size="small" link @click="handleExamine(row, 1)">发放</el-button>
              <el-button type="danger" size="small" link @click="handleExamine(row, 2)">驳回</el-button>
            </template>
            <span v-else class="sub-text">已处理</span>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="(p: number) => { query.page = p; fetchLogs() }"
        @size-change="(s: number) => { query.pagesize = s; query.page = 1; fetchLogs() }"
      />
    </el-card>
  </div>
</template>

<style scoped>
.stat-row {
  margin-bottom: 12px;
}

.stat-card .stat-label {
  color: #909399;
  font-size: 13px;
}

.stat-card .stat-value {
  font-size: 22px;
  font-weight: 700;
  margin-top: 4px;
}

.stat-value.primary {
  color: #409eff;
}

.stat-value.success {
  color: #67c23a;
}

.stat-value.warning {
  color: #e6a23c;
}

.toolbar-card {
  margin-bottom: 12px;
}

.preview-card {
  margin-bottom: 12px;
}

.preview-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.desc-text {
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
}

.sub-text {
  color: #909399;
  font-size: 12px;
  margin-left: 4px;
}

.success-text {
  color: #67c23a;
  font-weight: 600;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

/* 紧凑列：收紧单元格左右内边距 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
