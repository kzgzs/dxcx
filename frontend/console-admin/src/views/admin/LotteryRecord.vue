<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getLotteryList, getLotteryRecords, verifyLotteryRecord } from '../../api/lottery'
import type { LotteryItem, LotteryRecordItem } from '../../api/lottery'
import { formatTime } from '../../utils/time'

// ============ 活动下拉（记录页筛选用） ============
const actOptions = ref<LotteryItem[]>([])

async function fetchActOptions() {
  try {
    const data = await getLotteryList({ page: 1, pagesize: 100 })
    actOptions.value = data.list || []
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<LotteryRecordItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  hid: '' as number | '',
  nickname: '',
  jxmc: '',
  status: '' as number | '',
  ctime: [] as string[],
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getLotteryRecords({
      page: query.page,
      pagesize: query.pagesize,
      hid: query.hid,
      nickname: query.nickname,
      jxmc: query.jxmc,
      status: query.status,
      start_date: query.ctime?.[0] || '',
      end_date: query.ctime?.[1] || '',
    })
    tableData.value = data.list || []
    total.value = data.total
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.hid = ''
  query.nickname = ''
  query.jxmc = ''
  query.status = ''
  query.ctime = []
  handleSearch()
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

// 领奖状态文案（jx=0 未中奖优先）
function statusText(row: LotteryRecordItem): string {
  if (row.jx === 0) return '未中奖'
  return row.status === 1 ? '已领取' : '未领取'
}

function statusTagType(row: LotteryRecordItem): 'info' | 'warning' | 'success' {
  if (row.jx === 0) return 'info'
  return row.status === 1 ? 'success' : 'warning'
}

// ============ 领奖核销 ============
function handleVerify(row: LotteryRecordItem) {
  ElMessageBox.confirm(
    `确认向会员「${row.nickname || row.mid}」发放奖品「${row.jxmc}」吗？`,
    '领奖核销',
    { confirmButtonText: '确认发放', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        const res = await verifyLotteryRecord(row.id)
        ElMessage.success(`核销成功，核销码：${res.code}`)
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(() => {
  fetchActOptions()
  fetchList()
})
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="活动">
          <el-select v-model="query.hid" placeholder="全部" clearable filterable style="width: 200px">
            <el-option v-for="act in actOptions" :key="act.id" :label="act.name" :value="act.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="会员昵称">
          <el-input
            v-model="query.nickname"
            placeholder="会员昵称"
            clearable
            style="width: 150px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="奖品">
          <el-input
            v-model="query.jxmc"
            placeholder="奖品名称"
            clearable
            style="width: 150px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="领奖状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="未中奖" :value="-1" />
            <el-option label="未领取" :value="0" />
            <el-option label="已领取" :value="1" />
          </el-select>
        </el-form-item>
        <el-form-item label="抽奖时间">
          <el-date-picker
            v-model="query.ctime"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width: 260px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 记录列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="活动" min-width="150" show-overflow-tooltip />
        <el-table-column label="会员" min-width="140">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="jxmc" label="奖品" min-width="120" show-overflow-tooltip />
        <el-table-column label="奖项" width="80">
          <template #default="{ row }">{{ row.jx === 0 ? '—' : `奖项${row.jx}` }}</template>
        </el-table-column>
        <el-table-column label="奖品类型" width="90">
          <template #default="{ row }">
            <span v-if="row.jx === 0" class="text-muted">—</span>
            <span v-else>{{ row.jxtp === 2 ? '红包' : '实物' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="抽奖时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="领奖状态" width="90">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row)" size="small">{{ statusText(row) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="code" label="核销码" width="130" />
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.jx > 0 && row.status === 0"
              link
              type="primary"
              @click="handleVerify(row)"
            >核销</el-button>
            <span v-else class="text-muted">—</span>
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
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 12px;
}

.pagination {
  margin-top: 12px;
  justify-content: flex-end;
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
