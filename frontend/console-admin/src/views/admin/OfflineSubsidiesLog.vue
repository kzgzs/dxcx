<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteOfflineSubsidiesLog, getOfflineSubsidiesLogList } from '../../api/offlineSubsidies'
import type {
  OfflineSubsidiesLogItem,
  OfflineSubsidiesLogStat,
} from '../../api/offlineSubsidies'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<OfflineSubsidiesLogItem[]>([])
const total = ref(0)
const stat = ref<OfflineSubsidiesLogStat>({
  total: 0,
  sum_commission: 0,
  sum_score: 0,
  today_num: 0,
})

const query = reactive({
  page: 1,
  pagesize: 10,
  type: 0,
  scene: '',
  keyword: '',
  range: null as [Date, Date] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const start_time = query.range?.[0] ? Math.floor(query.range[0].getTime() / 1000) : undefined
    const end_time = query.range?.[1] ? Math.floor(query.range[1].getTime() / 1000) : undefined
    const data = await getOfflineSubsidiesLogList({
      page: query.page,
      pagesize: query.pagesize,
      type: query.type > 0 ? query.type : undefined,
      scene: query.scene || undefined,
      keyword: query.keyword || undefined,
      start_time,
      end_time,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || stat.value
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
  query.type = 0
  query.scene = ''
  query.keyword = ''
  query.range = null
  query.page = 1
  fetchList()
}

function typeTagType(type: number): '' | 'success' | 'warning' | 'info' | 'danger' {
  return type === 1 ? 'success' : type === 5 ? 'warning' : type === 4 ? 'danger' : ''
}

// ============ 删除 ============
function handleDelete(row: OfflineSubsidiesLogItem) {
  ElMessageBox.confirm('确定要删除这条补助记录吗？删除后不可恢复。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteOfflineSubsidiesLog(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div>
    <!-- 顶部统计 -->
    <el-card shadow="never" class="stat-card">
      <div class="stat-row">
        <div class="stat-item">
          <div class="stat-label">补助记录总数</div>
          <div class="stat-value">{{ stat.total }}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">累计发放佣金（元）</div>
          <div class="stat-value">{{ stat.sum_commission }}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">累计发放积分</div>
          <div class="stat-value">{{ stat.sum_score }}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">今日新增</div>
          <div class="stat-value">{{ stat.today_num }}</div>
        </div>
      </div>
    </el-card>

    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="类型">
          <el-select v-model="query.type" placeholder="全部" clearable style="width: 130px">
            <el-option label="通证分红" :value="1" />
            <el-option label="推荐奖励" :value="2" />
            <el-option label="讲师奖励" :value="3" />
            <el-option label="爱心基金" :value="4" />
            <el-option label="升级奖励" :value="5" />
          </el-select>
        </el-form-item>
        <el-form-item label="场景">
          <el-select v-model="query.scene" placeholder="全部" clearable style="width: 130px">
            <el-option label="商城订单" value="shop" />
            <el-option label="买单订单" value="maidan" />
          </el-select>
        </el-form-item>
        <el-form-item label="关键词">
          <el-input
            v-model="query.keyword"
            placeholder="订单号/来源会员昵称"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="query.range"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            style="width: 240px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 流水列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="类型" width="100">
          <template #default="{ row }">
            <el-tag :type="typeTagType(row.type)" size="small">{{ row.type_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="获得会员" min-width="130">
          <template #default="{ row }">{{ row.nickname }}（ID:{{ row.mid }}）</template>
        </el-table-column>
        <el-table-column label="来源会员" min-width="130">
          <template #default="{ row }">{{ row.from_nickname }}（ID:{{ row.frommid }}）</template>
        </el-table-column>
        <el-table-column label="订单号" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.ordernum || '-' }}</template>
        </el-table-column>
        <el-table-column label="场景" width="100">
          <template #default="{ row }">{{ row.scene_text }}</template>
        </el-table-column>
        <el-table-column label="订单金额" width="100">
          <template #default="{ row }">{{ row.order_money }}</template>
        </el-table-column>
        <el-table-column label="发放积分" width="90">
          <template #default="{ row }">{{ row.type === 1 ? row.score : '-' }}</template>
        </el-table-column>
        <el-table-column label="发放佣金" width="90">
          <template #default="{ row }">{{ row.type !== 1 ? row.commission : '-' }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="180" show-overflow-tooltip />
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ row.createtime_text }}</template>
        </el-table-column>
        <el-table-column label="操作" width="80" fixed="right">
          <template #default="{ row }">
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
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
        @current-change="(p: number) => ((query.page = p), fetchList())"
        @size-change="(s: number) => ((query.pagesize = s), (query.page = 1), fetchList())"
      />
    </el-card>
  </div>
</template>

<style scoped>
.stat-card {
  margin-bottom: 16px;
}

.stat-row {
  display: flex;
  gap: 48px;
}

.stat-item {
  text-align: center;
}

.stat-label {
  color: #909399;
  font-size: 13px;
}

.stat-value {
  font-size: 22px;
  font-weight: 600;
  margin-top: 4px;
}

.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
