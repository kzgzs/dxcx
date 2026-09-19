<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getCouponManageList,
  getCouponManageRecords,
  setRecordStatus,
} from '../../api/couponManage'
import type { CouponManageItem, CouponManageRecordItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<CouponManageRecordItem[]>([])
const total = ref(0)
const selected = ref<CouponManageRecordItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  coupon_id: '' as number | '',
  mid: '' as number | '',
  status: '' as number | '',
  keyword: '',
  timeRange: null as [Date, Date] | null,
})

// 券候选（筛选下拉）
const couponOptions = ref<CouponManageItem[]>([])

async function loadCouponOptions() {
  try {
    const data = await getCouponManageList({ page: 1, pagesize: 100 })
    couponOptions.value = data.list || []
  } catch {
    // 候选加载失败不阻断列表
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getCouponManageRecords({
      page: query.page,
      pagesize: query.pagesize,
      coupon_id: query.coupon_id === '' ? undefined : Number(query.coupon_id),
      mid: query.mid === '' ? undefined : Number(query.mid),
      status: query.status === '' ? undefined : query.status,
      keyword: query.keyword || undefined,
      start_time:
        query.timeRange && query.timeRange[0]
          ? Math.floor(query.timeRange[0].getTime() / 1000)
          : undefined,
      end_time:
        query.timeRange && query.timeRange[1]
          ? Math.floor(query.timeRange[1].getTime() / 1000)
          : undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
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
  query.coupon_id = ''
  query.mid = ''
  query.status = ''
  query.keyword = ''
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

function handleSelectionChange(rows: CouponManageRecordItem[]) {
  selected.value = rows
}

// 状态文案（过期为查询时实时计算）
const STATUS_TEXT: Record<number, { label: string; type: '' | 'success' | 'info' | 'danger' }> = {
  0: { label: '未使用', type: 'success' },
  1: { label: '已使用', type: 'info' },
  2: { label: '已过期', type: 'danger' },
}

// 有效期文案
function periodText(row: CouponManageRecordItem) {
  if (!row.starttime && !row.endtime) return '长期有效'
  return `${formatTime(row.starttime)} ~ ${formatTime(row.endtime)}`
}

// ============ 手工核销 / 反核销 ============
async function changeStatus(ids: number[], st: number, tip: string) {
  try {
    await ElMessageBox.confirm(tip, '操作确认', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    await setRecordStatus(ids, st)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出（计次券禁止手工核销等）
  }
}

function handleVerify(row: CouponManageRecordItem) {
  changeStatus([row.id], 1, `确定手工核销「${row.couponname}」这条记录吗？`)
}

function handleUnverify(row: CouponManageRecordItem) {
  changeStatus([row.id], 0, `确定反核销「${row.couponname}」这条记录吗？`)
}

function handleBatchVerify() {
  if (selected.value.length === 0) {
    ElMessage.warning('请先勾选记录')
    return
  }
  changeStatus(
    selected.value.map((r) => r.id),
    1,
    `确定批量核销选中的 ${selected.value.length} 条记录吗？（计次券将被拒绝）`,
  )
}

onMounted(() => {
  loadCouponOptions()
  fetchList()
})
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="优惠券">
          <el-select
            v-model="query.coupon_id"
            placeholder="全部"
            clearable
            filterable
            style="width: 180px"
          >
            <el-option
              v-for="c in couponOptions"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input
            v-model="query.mid"
            placeholder="会员ID"
            clearable
            style="width: 110px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="未使用" :value="0" />
            <el-option label="已使用" :value="1" />
            <el-option label="已过期" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item label="关键词">
          <el-input
            v-model="query.keyword"
            placeholder="昵称/手机号"
            clearable
            style="width: 150px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="领取时间">
          <el-date-picker
            v-model="query.timeRange"
            type="datetimerange"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            style="width: 300px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
          <el-button type="success" plain @click="handleBatchVerify">批量核销</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 记录列表 -->
    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="64" />
        <el-table-column label="会员" min-width="130">
          <template #default="{ row }">
            {{ row.nickname || '-' }}
            <span v-if="row.member_phone" class="member-phone">（{{ row.member_phone }}）</span>
          </template>
        </el-table-column>
        <el-table-column prop="couponname" label="优惠券" min-width="130" show-overflow-tooltip />
        <el-table-column label="类型" width="100">
          <template #default="{ row }">
            <el-tag size="small" effect="plain">{{ row.type_txt }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="有效期" min-width="200">
          <template #default="{ row }">{{ periodText(row) }}</template>
        </el-table-column>
        <el-table-column label="领取时间" width="150">
          <template #default="{ row }">{{ formatTime(row.receive_time) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="86">
          <template #default="{ row }">
            <el-tag size="small" :type="(STATUS_TEXT[row.status] || STATUS_TEXT[0]).type">
              {{ (STATUS_TEXT[row.status] || STATUS_TEXT[0]).label }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="使用时间" width="150">
          <template #default="{ row }">{{ formatTime(row.use_time) }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="来源" width="90" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '主动领取' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 0">
              <el-button
                link
                type="primary"
                :disabled="row.type === 3"
                @click="handleVerify(row)"
              >
                核销
              </el-button>
            </template>
            <template v-else-if="row.status === 1">
              <el-button
                link
                type="warning"
                :disabled="row.type === 3"
                @click="handleUnverify(row)"
              >
                反核销
              </el-button>
            </template>
            <span v-else class="expired-tip">-</span>
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
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.member-phone {
  color: #909399;
  font-size: 12px;
}

.expired-tip {
  color: #c0c4cc;
}
</style>
