<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import {
  deleteCouponManageRecords,
  getCouponManageList,
  getCouponManageRecords,
  setRecordStatus,
} from '../../api/couponManage'
import type { CouponManageItem, CouponManageRecordItem } from '../../api/types'
import { formatTime } from '../../utils/time'

const props = defineProps<{ couponId?: number }>()

const loading = ref(false)
const tableData = ref<CouponManageRecordItem[]>([])
const total = ref(0)
const selected = ref<CouponManageRecordItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  coupon_id: '' as number | '',
  mid: '' as number | '',
  nickname: '',
  remark: '',
  timeRange: null as [Date, Date] | null,
})

const couponOptions = ref<CouponManageItem[]>([])

async function loadCouponOptions() {
  try {
    const data = await getCouponManageList({ page: 1, pagesize: 100 })
    couponOptions.value = data.list || []
  } catch {
    /* 候选失败不阻断 */
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
      keyword: query.nickname || undefined,
      remark: query.remark || undefined,
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
    /* 拦截器 */
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

function handleSelectionChange(rows: CouponManageRecordItem[]) {
  selected.value = rows
}

function statusText(row: CouponManageRecordItem): { label: string; color: string } {
  if (row.status === 1) return { label: '已使用', color: '#67c23a' }
  if (row.status === 2) return { label: '已过期', color: '#909399' }
  return { label: '未使用', color: '#909399' }
}

async function changeStatus(ids: number[], st: number, tip: string) {
  if (ids.length === 0) {
    ElMessage.warning('请先勾选记录')
    return
  }
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
    /* 拦截器 */
  }
}

function batchToUnused() {
  changeStatus(selected.value.map((r) => r.id), 0, `确定将选中的 ${selected.value.length} 条改为未使用吗？`)
}

function batchToUsed() {
  changeStatus(selected.value.map((r) => r.id), 1, `确定将选中的 ${selected.value.length} 条改为已使用吗？`)
}

function rowToUsed(row: CouponManageRecordItem) {
  changeStatus([row.id], 1, `确定将这条记录改为已使用吗？`)
}

function rowToUnused(row: CouponManageRecordItem) {
  changeStatus([row.id], 0, `确定将这条记录改为未使用吗？`)
}

function batchDelete() {
  if (selected.value.length === 0) {
    ElMessage.warning('请先勾选记录')
    return
  }
  doDelete(selected.value.map((r) => r.id), `确定删除选中的 ${selected.value.length} 条记录吗？删除后无法恢复！`)
}

function rowDelete(row: CouponManageRecordItem) {
  doDelete([row.id], '确定要删除这条记录吗？删除后无法恢复！')
}

function doDelete(ids: number[], tip: string) {
  ElMessageBox.confirm(tip, '操作确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCouponManageRecords(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器 */
      }
    })
    .catch(() => {})
}

onMounted(() => {
  if (props.couponId && props.couponId > 0) {
    query.coupon_id = props.couponId
  }
  loadCouponOptions()
  fetchList()
})
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-row">
        <div class="toolbar-left">
          <el-button @click="batchToUnused">改为未使用</el-button>
          <el-button @click="batchToUsed">改为已使用</el-button>
          <el-button @click="batchDelete">删除</el-button>
        </div>
        <div class="toolbar-right">
          <el-select v-model="query.coupon_id" placeholder="全部券" clearable style="width: 150px">
            <el-option v-for="c in couponOptions" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
          <el-input v-model="query.mid" placeholder="用户ID" clearable style="width: 100px" @keyup.enter="handleSearch" />
          <el-input v-model="query.nickname" placeholder="昵称" clearable style="width: 120px" @keyup.enter="handleSearch" />
          <el-date-picker
            v-model="query.timeRange"
            type="datetimerange"
            range-separator="至"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            style="width: 340px"
          />
          <el-input v-model="query.remark" placeholder="备注" clearable style="width: 120px" @keyup.enter="handleSearch" />
          <el-button circle type="success" :icon="Search" @click="handleSearch" />
        </div>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        :header-cell-style="{ background: '#e6e6e6' }"
        :cell-style="{ textAlign: 'left' }"
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="couponname" label="优惠券名称" min-width="130" show-overflow-tooltip />
        <el-table-column label="领取人" width="200">
          <template #default="{ row }">
            <div class="member-cell">
              <img v-if="row.headimg" :src="row.headimg" class="headimg" />
              <span>{{ row.nickname }}(ID:{{ row.mid }})</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="领取时间" width="150">
          <template #default="{ row }">{{ formatTime(row.receive_time) }}</template>
        </el-table-column>
        <el-table-column label="到期时间" width="150">
          <template #default="{ row }">{{ row.endtime ? formatTime(row.endtime) : '-' }}</template>
        </el-table-column>
        <el-table-column label="总次数/已使用" width="120">
          <template #default="{ row }">
            <span v-if="row.type === 3">{{ row.limit_count }} / {{ row.used_count }}</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="使用时间" width="150">
          <template #default="{ row }">{{ row.use_time ? formatTime(row.use_time) : '' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :style="{ color: statusText(row).color }">{{ statusText(row).label }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" width="120" show-overflow-tooltip />
        <el-table-column label="操作" width="200">
          <template #default="{ row }">
            <div class="op-flex">
              <el-button v-if="row.status === 0 && row.type !== 3" link type="primary" @click="rowToUsed(row)">改为已使用</el-button>
              <el-button v-if="row.status === 1 && row.type !== 3" link type="primary" @click="rowToUnused(row)">改为未使用</el-button>
              <el-button link type="danger" @click="rowDelete(row)">删除</el-button>
            </div>
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
  margin-bottom: 3px;
}

.toolbar-card :deep(.el-card__body) {
  padding: 5px;
}

.toolbar-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
}

.toolbar-left,
.toolbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.pagination {
  margin-top: 3px;
  justify-content: flex-end;
}

.op-flex {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  gap: 4px;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}

.headimg {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  object-fit: cover;
}
</style>
