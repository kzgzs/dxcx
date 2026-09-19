<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  callQueue,
  cancelQueue,
  deleteQueue,
  getQueueCategoryList,
  getQueueList,
  passQueue,
  speakCallText,
} from '../../api/queue'
import type { QueueCategoryRow, QueueRow } from '../../api/queue'

// ============ 状态字典 ============
const STATUS_TEXT: Record<number, string> = { 0: '排队中', 1: '已入座', 2: '过号', 3: '已取消' }
const STATUS_TYPE: Record<number, 'info' | 'success' | 'warning' | 'danger'> = {
  0: 'info',
  1: 'success',
  2: 'warning',
  3: 'danger',
}

function formatTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// ============ 分类（叫号入口卡片） ============
const categories = ref<QueueCategoryRow[]>([])

async function fetchCategories() {
  try {
    const data = await getQueueCategoryList()
    categories.value = (data.list || []).filter((c) => c.status === 1)
  } catch {
    // 错误提示已由拦截器弹出
  }
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<QueueRow[]>([])
const total = ref(0)
const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '',
  cid: 0 as number,
  date: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getQueueList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      status: query.status,
      cid: query.cid || undefined,
      date: query.date || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.keyword = ''
  query.status = ''
  query.cid = 0
  query.date = ''
  handleSearch()
}

// ============ 叫号（分类最早一桌） ============
const calling = ref(0)

async function handleCallCategory(cat: QueueCategoryRow) {
  calling.value = cat.id
  try {
    const data = await callQueue({ cid: cat.id })
    const q = data.queue
    ElMessage.success(`已叫号：${q.queue_no}`)
    speakCallText(q.call_text || `请${q.queue_no}号顾客前往就餐`)
    fetchList()
    fetchCategories()
  } catch {
    // 「没有可叫号的排队」等业务错误由拦截器弹出
  } finally {
    calling.value = 0
  }
}

// ============ 重复叫（指定号） ============
async function handleRecall(row: QueueRow) {
  try {
    await callQueue({ id: row.id })
    ElMessage.success(`已重复叫号：${row.queue_no}`)
    speakCallText(row.call_text || `请${row.queue_no}号顾客前往就餐`)
    fetchList()
  } catch {
    // 错误由拦截器弹出
  }
}

// ============ 过号 ============
function handlePass(row: QueueRow) {
  ElMessageBox.confirm(`确定将「${row.queue_no}」过号吗？`, '过号确认', {
    confirmButtonText: '过号',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await passQueue(row.id)
        ElMessage.success('已过号')
        fetchList()
        fetchCategories()
      } catch {
        // 错误由拦截器弹出
      }
    })
    .catch(() => {})
}

// ============ 取消 / 删除 ============
function handleCancel(row: QueueRow) {
  ElMessageBox.confirm(`确定取消「${row.queue_no}」的排队吗？`, '取消确认', {
    confirmButtonText: '取消排队',
    cancelButtonText: '返回',
    type: 'warning',
  })
    .then(async () => {
      try {
        await cancelQueue(row.id)
        ElMessage.success('已取消')
        fetchList()
        fetchCategories()
      } catch {
        // 错误由拦截器弹出
      }
    })
    .catch(() => {})
}

function handleDelete(row: QueueRow) {
  ElMessageBox.confirm(`确定删除排队记录「${row.queue_no}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteQueue(row.id)
        ElMessage.success('删除成功')
        fetchList()
        fetchCategories()
      } catch {
        // 错误由拦截器弹出
      }
    })
    .catch(() => {})
}

const waitingCount = computed(() => categories.value.reduce((s, c) => s + (c.waitnum || 0), 0))

onMounted(() => {
  fetchCategories()
  fetchList()
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">排队队列（今日排队中 {{ waitingCount }} 桌）</span>
      </div>
    </template>

    <!-- 分类叫号入口 -->
    <div v-if="categories.length" class="call-cards">
      <div v-for="cat in categories" :key="cat.id" class="call-card">
        <div class="call-card-name">{{ cat.name }}（{{ cat.code }}）</div>
        <div class="call-card-num">{{ cat.waitnum || 0 }} <span>桌等待</span></div>
        <el-button
          type="primary"
          size="small"
          :loading="calling === cat.id"
          :disabled="(cat.waitnum || 0) === 0"
          @click="handleCallCategory(cat)"
        >
          叫号
        </el-button>
      </div>
    </div>
    <el-empty v-else description="暂无启用的队列分类，请先到「队列设置」添加" :image-size="60" />

    <!-- 搜索条 -->
    <div class="search-bar">
      <el-select v-model="query.status" placeholder="状态" clearable style="width: 110px" @change="handleSearch">
        <el-option label="排队中" value="0" />
        <el-option label="已入座" value="1" />
        <el-option label="过号" value="2" />
        <el-option label="已取消" value="3" />
      </el-select>
      <el-select v-model="query.cid" placeholder="分类" clearable style="width: 130px" @change="handleSearch">
        <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
      </el-select>
      <el-date-picker
        v-model="query.date"
        type="date"
        placeholder="日期"
        value-format="YYYY-MM-DD"
        style="width: 150px"
        @change="handleSearch"
      />
      <el-input v-model="query.keyword" placeholder="排队号/联系人/手机号" clearable style="width: 200px" @keyup.enter="handleSearch" />
      <el-button type="primary" @click="handleSearch">查询</el-button>
      <el-button @click="handleReset">重置</el-button>
    </div>

    <el-table v-loading="loading" :data="tableData" stripe border size="small">
      <el-table-column prop="queue_no" label="排队号" width="90" class-name="compact-col" />
      <el-table-column prop="cname" label="分类" width="90" class-name="compact-col" />
      <el-table-column prop="linkman" label="联系人" width="100" show-overflow-tooltip />
      <el-table-column prop="tel" label="手机号" width="118" class-name="compact-col" />
      <el-table-column prop="renshu" label="人数" width="60" class-name="compact-col" />
      <el-table-column label="状态" width="80" class-name="compact-col">
        <template #default="{ row }">
          <el-tag size="small" :type="STATUS_TYPE[row.status]">{{ STATUS_TEXT[row.status] }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="取号时间" width="110" class-name="compact-col">
        <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
      </el-table-column>
      <el-table-column label="叫号时间" width="110" class-name="compact-col">
        <template #default="{ row }">{{ formatTime(row.call_time) }}</template>
      </el-table-column>
      <el-table-column prop="nickname" label="会员" min-width="100" show-overflow-tooltip />
      <el-table-column label="操作" width="150" fixed="right" class-name="compact-col">
        <template #default="{ row }">
          <div class="op-wrap">
            <el-button v-if="row.status === 0" link type="primary" @click="handleRecall(row)">叫号</el-button>
            <el-button v-if="row.status !== 0" link type="primary" @click="handleRecall(row)">重复叫</el-button>
            <el-button v-if="row.status === 0" link type="warning" @click="handlePass(row)">过号</el-button>
            <el-button v-if="row.status === 0" link type="danger" @click="handleCancel(row)">取消</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <div class="pager">
      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </div>
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.card-title {
  font-weight: 600;
}

/* 分类叫号卡片 */
.call-cards {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}

.call-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  width: 140px;
  padding: 10px;
  border: 1px solid #ebeef5;
  border-radius: 6px;
  background: #fafafa;
}

.call-card-name {
  font-size: 13px;
  color: #606266;
}

.call-card-num {
  font-size: 22px;
  font-weight: 600;
  color: #409eff;
}

.call-card-num span {
  font-size: 12px;
  font-weight: 400;
  color: #909399;
}

.search-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

/* 操作列：link 文字按钮单行排布（紧凑间距） */
.op-wrap {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 2px;
  white-space: nowrap;
}

/* 紧凑列：收紧单元格左右内边距 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
