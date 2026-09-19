<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { getYuebaoMoneylogList } from '../../api/yuebao'
import type { YuebaoMoneyLogItem, YuebaoMoneyLogStat } from '../../api/yuebao'
import { formatTime } from '../../utils/time'

// 流水类型：1收益增加 / 2提现减少 / 3转余额减少 / 4退款返回
const TYPE_TAG: Record<number, 'success' | 'danger' | 'warning' | 'primary'> = {
  1: 'success',
  2: 'danger',
  3: 'warning',
  4: 'primary',
}

const loading = ref(false)
const tableData = ref<YuebaoMoneyLogItem[]>([])
const total = ref(0)
const stat = ref<YuebaoMoneyLogStat>({ income: 0, expense: 0, net: 0 })

const query = reactive({
  page: 1,
  pagesize: 10,
  type: '',
  nickname: '',
  mid: '',
  timeRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuebaoMoneylogList({
      page: query.page,
      pagesize: query.pagesize,
      type: query.type || undefined,
      nickname: query.nickname || undefined,
      mid: query.mid ? Number(query.mid) : undefined,
      start_date: query.timeRange?.[0] || undefined,
      end_date: query.timeRange?.[1] || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || { income: 0, expense: 0, net: 0 }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.type = ''
  query.nickname = ''
  query.mid = ''
  query.timeRange = null
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

function shortDate(ts: number): string {
  if (!ts) return '-'
  const full = formatTime(ts)
  return full.length >= 10 ? full.slice(2, 10) : full
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- 顶部统计卡片 -->
    <el-row :gutter="12" class="stat-row">
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">收入合计（收益/退回）</div>
          <div class="stat-value success">￥{{ Number(stat.income).toFixed(2) }}</div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">支出合计（提现/转余额）</div>
          <div class="stat-value danger">￥{{ Number(stat.expense).toFixed(2) }}</div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">净额</div>
          <div class="stat-value primary">￥{{ Number(stat.net).toFixed(2) }}</div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="类型">
          <el-select v-model="query.type" placeholder="全部" clearable style="width: 120px">
            <el-option label="收益增加" value="1" />
            <el-option label="提现减少" value="2" />
            <el-option label="转余额减少" value="3" />
            <el-option label="退款返回" value="4" />
          </el-select>
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="会员昵称" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input v-model="query.mid" placeholder="ID" clearable style="width: 90px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="query.timeRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            value-format="YYYY-MM-DD"
            style="width: 220px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
        <el-table-column label="会员" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.nickname || `会员${row.mid}` }}
            <span v-if="row.tel" class="sub-text">{{ row.tel }}</span>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="92" class-name="compact-col">
          <template #default="{ row }">
            <el-tag :type="TYPE_TAG[row.type] || 'info'" size="small">{{ row.type_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="变动金额" width="96" class-name="compact-col">
          <template #default="{ row }">
            <span :class="Number(row.money) >= 0 ? 'money-plus' : 'money-minus'">
              {{ Number(row.money) >= 0 ? '+' : '' }}{{ Number(row.money).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="变动后" width="96" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.after).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="时间" width="88" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ shortDate(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
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

.stat-value.danger {
  color: #f56c6c;
}

.stat-value.success {
  color: #67c23a;
}

.toolbar-card {
  margin-bottom: 12px;
}

.money-plus {
  color: #67c23a;
  font-weight: 600;
}

.money-minus {
  color: #f56c6c;
  font-weight: 600;
}

.sub-text {
  color: #909399;
  font-size: 12px;
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
