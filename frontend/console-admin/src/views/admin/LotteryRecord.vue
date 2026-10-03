<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteLotteryRecords,
  exportLotteryRecords,
  getLotteryRecords,
  verifyLotteryRecord,
} from '../../api/lottery'
import type { LotteryRecordItem } from '../../api/lottery'
import { lotteryNav } from '../../api/lotteryNav'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<LotteryRecordItem[]>([])
const total = ref(0)
const selected = ref<LotteryRecordItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  hid: '' as number | '',
  mid: '' as number | '',
  duijiang: '',
  jxmc: '',
  status: '' as number | '',
  ctime: [] as string[],
})

function formatTime(ts: number): string {
  if (!ts) return ''
  const d = new Date(ts * 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getLotteryRecords({
      page: query.page,
      pagesize: query.pagesize,
      hid: query.hid,
      mid: query.mid,
      duijiang: query.duijiang,
      jxmc: query.jxmc,
      status: query.status,
      start_date: query.ctime?.[0] || '',
      end_date: query.ctime?.[1] || '',
    })
    tableData.value = data.list || []
    total.value = data.total
  } catch {
    /* 已统一弹出 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}
function handlePageChange(page: number) {
  query.page = page
  fetchList()
}
function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

// ============ 状态 ============
// jx=0 未中奖；jx>0 status=0 未领取(红)；jx>0 status=1 已领取(绿)
function rowState(row: LotteryRecordItem): { text: string; cls: string } {
  if (row.jx === 0) return { text: '未中奖', cls: 'st-none' }
  return row.status === 1 ? { text: '已领取', cls: 'st-done' } : { text: '未领取', cls: 'st-todo' }
}
// 兑奖信息列：已领取显「发放成功」
function duijiangText(row: LotteryRecordItem): string {
  return row.jx > 0 && row.status === 1 ? '发放成功' : ''
}

// ============ 行操作 ============
function handleClaim(row: LotteryRecordItem) {
  ElMessageBox.confirm(
    `确认将奖品「${row.jxmc}」标记为已领取并发放给会员「${row.nickname || row.mid}」吗？`,
    '改为已领取',
    { confirmButtonText: '确认发放', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        await verifyLotteryRecord(row.id)
        ElMessage.success('已发放成功')
        fetchList()
      } catch {
        /* 已统一弹出 */
      }
    })
    .catch(() => {})
}

function handleDelete(rows: LotteryRecordItem[]) {
  if (!rows.length) {
    ElMessage.warning('请选择数据')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${rows.length} 条记录吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLotteryRecords(rows.map((r) => r.id))
        ElMessage.success('删除成功')
        selected.value = []
        fetchList()
      } catch {
        /* 已统一弹出 */
      }
    })
    .catch(() => {})
}

// ============ 导出 xlsx ============
async function handleExport() {
  try {
    ElMessage.info('正在生成中奖记录Excel…')
    const blob = await exportLotteryRecords({
      hid: query.hid,
      mid: query.mid,
      duijiang: query.duijiang,
      jxmc: query.jxmc,
      status: query.status,
      start_date: query.ctime?.[0] || '',
      end_date: query.ctime?.[1] || '',
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `抽奖记录_${Date.now()}.xlsx`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch (e) {
    ElMessage.error((e as Error)?.message || '导出失败')
  }
}

// ============ 从活动列表带参跳入（按活动 id 过滤） ============
watch(
  () => lotteryNav.token,
  () => {
    if (lotteryNav.hid > 0) {
      query.hid = lotteryNav.hid
      query.page = 1
      fetchList()
    }
  },
)

onMounted(() => {
  if (lotteryNav.hid > 0) {
    query.hid = lotteryNav.hid
  }
  fetchList()
})
</script>

<template>
  <div>
    <!-- 工具栏一行：左 删除/导出；右 会员ID/兑奖信息/奖品/时间/状态+放大镜 -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-left">
        <el-button @click="handleDelete(selected)">删除</el-button>
        <el-button @click="handleExport">导出</el-button>
      </div>
      <el-form inline class="toolbar-search" @submit.prevent>
        <el-form-item label="会员ID">
          <el-input v-model="query.mid" placeholder="" clearable style="width: 90px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="兑奖信息">
          <el-input v-model="query.duijiang" placeholder="" clearable style="width: 110px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="奖品">
          <el-input v-model="query.jxmc" placeholder="" clearable style="width: 110px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="query.ctime"
            type="daterange"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            value-format="YYYY-MM-DD"
            style="width: 220px"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 100px">
            <el-option label="未领取" :value="0" />
            <el-option label="已领取" :value="1" />
            <el-option label="未中奖" :value="-1" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.83 870.13l-179.06-179.13c37.63-50.25 60.23-112.4 60.23-179.64 0-165.02-133.86-298.82-298.95-298.82S229.16 346.34 229.16 511.36s133.8 298.88 298.88 298.88c64.18 0 123.67-20.45 172.93-54.89l180.2 180.27c8.64 8.58 19.88 12.93 31.19 12.93 11.31 0 22.55-4.35 31.13-12.93 8.63-8.63 8.63-39.34 0-55.56zM528.04 710.31c-109.76 0-198.95-89.12-198.95-198.95s89.19-198.95 198.95-198.95 198.95 89.19 198.95 198.95-89.19 198.95-198.95 198.95z" /></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="(rows: LotteryRecordItem[]) => (selected = rows)">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="hid" label="活动ID" width="80" />
        <el-table-column prop="name" label="活动名称" min-width="150" show-overflow-tooltip />
        <el-table-column prop="mid" label="会员ID" width="80" />
        <el-table-column label="会员信息" width="130">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="26" />
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="jxmc" label="奖品" width="110" show-overflow-tooltip />
        <el-table-column label="兑奖信息" width="100">
          <template #default="{ row }">{{ duijiangText(row) }}</template>
        </el-table-column>
        <el-table-column label="时间" width="150" sortable>
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :class="['status-tag', rowState(row).cls]">{{ rowState(row).text }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="150" show-overflow-tooltip />
        <el-table-column label="操作" width="110">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button v-if="row.jx > 0 && row.status === 0" link type="primary" @click="handleClaim(row)">改为已领取</el-button>
              <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
            </div>
          </template>
        </el-table-column>
        <template #empty>暂无数据</template>
      </el-table>

      <el-pagination
        class="pagination"
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 20, 50]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>
  </div>
</template>

<style scoped>
.toolbar-card { margin-bottom: 3px; }
.toolbar-card :deep(.el-card__body) {
  padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: nowrap;
}
.toolbar-left { display: flex; gap: 8px; flex: none; }
.toolbar-left .el-button { margin: 0; }
.toolbar-search { display: flex; align-items: center; justify-content: flex-end; flex: 1; flex-wrap: nowrap; margin: 0; }
.toolbar-search :deep(.el-form-item) { margin-bottom: 0; margin-right: 8px; }
.toolbar-search :deep(.el-form-item:last-child) { margin-right: 0; }
.toolbar-search :deep(.el-form-item__label) { padding-right: 6px; }
.search-icon-btn { padding: 8px 14px; }
.search-icon-btn svg { display: block; }
.pagination { margin-top: 10px; justify-content: flex-end; }

.member-cell { display: flex; align-items: center; gap: 6px; }

.status-tag { font-size: 13px; }
.st-todo { color: #f56c6c; }
.st-done { color: #67c23a; }
.st-none { color: #909399; }

.row-ops { display: flex; flex-direction: column; gap: 2px; }
.row-ops .el-button { margin: 0; padding: 0; }

:deep(.el-table__header th) { background: #e6e6e6 !important; }
:deep(.el-table .cell) { text-align: left; }
</style>
