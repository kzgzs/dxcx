<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getLipinCodeList,
  getLipinList,
  lipinCodelistDel,
  lipinCodelistExcel,
  lipinSetst,
} from '../../api/lipin'
import type { LipinCodeItem, LipinItem } from '../../api/lipin'

const CODE_STATUS: Record<number, string> = { 0: '未使用', 1: '已使用', 2: '已售' }

// ============ 列表 ============
const loading = ref(false)
const exporting = ref(false)
const list = ref<LipinCodeItem[]>([])
const total = ref(0)
const selected = ref<LipinCodeItem[]>([])
const huodongs = ref<LipinItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  hid: 0,
  cardno: '',
  cardno2: '',
  code: '',
  mid: '',
  nickname: '',
  status: '',
  daterange: '' as string[] | '',
})

function buildParams() {
  return {
    page: query.page,
    pagesize: query.pagesize,
    hid: query.hid || undefined,
    cardno: query.cardno,
    cardno2: query.cardno2,
    code: query.code,
    mid: query.mid ? Number(query.mid) : undefined,
    nickname: query.nickname,
    status: query.status,
    starttime: Array.isArray(query.daterange) ? query.daterange[0] : undefined,
    endtime: Array.isArray(query.daterange) ? query.daterange[1] : undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const res = await getLipinCodeList(buildParams())
    list.value = res.list || []
    total.value = res.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function fetchHuodongs() {
  try {
    const res = await getLipinList({ page: 1, pagesize: 100 })
    huodongs.value = res.list || []
  } catch {
    huodongs.value = []
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleSelectionChange(rows: LipinCodeItem[]) {
  selected.value = rows
}

function formatTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// ============ 批量操作 ============
function requireSelected(): number[] | null {
  if (selected.value.length === 0) {
    ElMessage.warning('请先勾选兑换码')
    return null
  }
  return selected.value.map((it) => it.id)
}

async function handleSetst(st: number) {
  const ids = requireSelected()
  if (!ids) return
  const label = st === 1 ? '已使用' : '已售'
  try {
    await ElMessageBox.confirm(`确定将选中的 ${ids.length} 条兑换码改为「${label}」吗？`, '操作确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await lipinSetst(ids, st)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleDelete() {
  const ids = requireSelected()
  if (!ids) return
  try {
    await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 条兑换码吗？`, '删除确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await lipinCodelistDel(ids)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleExport() {
  exporting.value = true
  try {
    await lipinCodelistExcel(buildParams())
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    exporting.value = false
  }
}

onMounted(() => {
  fetchList()
  fetchHuodongs()
})
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>兑换码管理</span>
          <div>
            <el-button type="warning" plain @click="handleSetst(1)">改为已使用</el-button>
            <el-button type="warning" plain @click="handleSetst(2)">改为已售</el-button>
            <el-button type="danger" plain @click="handleDelete">删除</el-button>
            <el-button type="primary" plain :loading="exporting" @click="handleExport">导出</el-button>
          </div>
        </div>
      </template>

      <div class="filter-bar">
        <el-select v-model="query.hid" placeholder="礼品卡" clearable style="width: 180px" @change="handleSearch">
          <el-option v-for="h in huodongs" :key="h.id" :value="h.id" :label="h.name" />
        </el-select>
        <el-input v-model="query.cardno" placeholder="卡号起" clearable style="width: 120px" @keyup.enter="handleSearch" />
        <span class="filter-sep">~</span>
        <el-input v-model="query.cardno2" placeholder="卡号止" clearable style="width: 120px" @keyup.enter="handleSearch" />
        <el-input v-model="query.code" placeholder="兑换码" clearable style="width: 150px" @keyup.enter="handleSearch" />
        <el-input v-model="query.mid" placeholder="使用人ID" clearable style="width: 110px" @keyup.enter="handleSearch" />
        <el-input v-model="query.nickname" placeholder="昵称" clearable style="width: 120px" @keyup.enter="handleSearch" />
        <el-select v-model="query.status" placeholder="状态" clearable style="width: 110px" @change="handleSearch">
          <el-option value="0" label="未使用" />
          <el-option value="1" label="已使用" />
          <el-option value="2" label="已售" />
        </el-select>
        <el-date-picker
          v-model="query.daterange"
          type="daterange"
          range-separator="至"
          start-placeholder="创建开始"
          end-placeholder="创建结束"
          value-format="YYYY-MM-DD"
          style="width: 260px"
          @change="handleSearch"
        />
        <el-button @click="handleSearch">搜索</el-button>
      </div>

      <el-table v-loading="loading" :data="list" stripe row-key="id" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="45" />
        <el-table-column prop="hid" label="活动ID" width="80" />
        <el-table-column prop="hname" label="活动名称" min-width="140" />
        <el-table-column prop="code" label="兑换码" min-width="150" />
        <el-table-column label="图形码" width="90">
          <template #default="{ row }">
            <el-image v-if="row.qr" :src="row.qr" class="qr-img" fit="cover" :preview-src-list="[row.qr]" />
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
        <el-table-column label="使用人" min-width="160">
          <template #default="{ row }">
            <div v-if="row.mid" class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
              <span>{{ row.nickname || '-' }}（ID: {{ row.mid }}）</span>
            </div>
            <span v-else class="text-muted">尚未使用</span>
          </template>
        </el-table-column>
        <el-table-column label="兑换时间" width="150">
          <template #default="{ row }">{{ formatTime(row.usetime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : row.status === 2 ? 'warning' : 'info'" size="small">
              {{ CODE_STATUS[row.status] || '-' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="120" />
      </el-table>

      <el-pagination
        v-if="total > 0"
        class="pager"
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        layout="total, prev, pager, next"
        @current-change="fetchList"
      />
    </el-card>
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-bottom: 12px;
}

.filter-sep {
  color: #909399;
}

.pager {
  margin-top: 12px;
  justify-content: flex-end;
}

.qr-img {
  width: 70px;
  height: 70px;
  border-radius: 4px;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}

.text-muted {
  color: #909399;
}
</style>
