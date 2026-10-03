<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteCollectRewardRecords,
  getCollectRewardRecords,
} from '../../api/marketing'
import type { CollectRewardRecordItem } from '../../api/marketing'

const loading = ref(false)
const tableData = ref<CollectRewardRecordItem[]>([])
const total = ref(0)
const selected = ref<CollectRewardRecordItem[]>([])

const query = reactive({
  ordernum: '',
  ordertime: null as [Date, Date] | null,
  keyword: '',
  reward_type: '' as number | '',
  ordertype: '',
  page: 1,
  pagesize: 10,
})

const ORDERTYPE_OPTIONS = [
  { value: 'shop', label: '商城订单' },
  { value: 'collage', label: '多人拼团订单' },
  { value: 'tuangou', label: '团购订单' },
  { value: 'scoreshop', label: '积分兑换订单' },
  { value: 'businessshop', label: '商家订单' },
]

async function fetchList() {
  loading.value = true
  try {
    const ordertimeStart = query.ordertime?.[0]
      ? Math.floor(query.ordertime[0].getTime() / 1000)
      : undefined
    const ordertimeEnd = query.ordertime?.[1]
      ? Math.floor(query.ordertime[1].getTime() / 1000)
      : undefined
    const data = await getCollectRewardRecords({
      ordernum: query.ordernum || undefined,
      keyword: query.keyword || undefined,
      reward_type: query.reward_type !== '' && Number(query.reward_type) > 0 ? Number(query.reward_type) : undefined,
      ordertype: query.ordertype || undefined,
      ordertime_start: ordertimeStart,
      ordertime_end: ordertimeEnd,
      page: query.page,
      pagesize: query.pagesize,
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

async function doDelete(ids: number[]) {
  if (ids.length === 0) return
  await ElMessageBox.confirm('确定要删除所选奖励记录吗？删除后无法恢复！', '操作确认', {
    type: 'warning',
  })
  await deleteCollectRewardRecords(ids)
  ElMessage.success('删除成功')
  selected.value = []
  fetchList()
}

function handleBatchDelete() {
  const ids = selected.value.map((r) => r.id)
  if (ids.length === 0) {
    ElMessage.warning('请先选择要删除的记录')
    return
  }
  doDelete(ids)
}

function handleRowDelete(row: CollectRewardRecordItem) {
  doDelete([row.id])
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-row">
        <el-button type="danger" size="small" @click="handleBatchDelete">批量删除</el-button>
        <el-form inline @submit.prevent class="search-form" @keyup.enter="handleSearch">
          <el-form-item label="订单号">
            <el-input v-model="query.ordernum" clearable style="width: 130px" />
          </el-form-item>
          <el-form-item label="下单时间">
            <el-date-picker
              v-model="query.ordertime"
              type="daterange"
              range-separator="至"
              start-placeholder="开始"
              end-placeholder="结束"
              style="width: 220px"
            />
          </el-form-item>
          <el-form-item label="会员信息">
            <el-input
              v-model="query.keyword"
              placeholder="昵称、手机号"
              clearable
              style="width: 130px"
            />
          </el-form-item>
          <el-form-item label="奖励类型">
            <el-select v-model="query.reward_type" placeholder="全部" clearable style="width: 110px">
              <el-option label="积分" :value="1" />
              <el-option label="优惠券" :value="2" />
              <el-option label="余额返现" :value="3" />
              <el-option label="佣金返现" :value="4" />
            </el-select>
          </el-form-item>
          <el-form-item label="订单类型">
            <el-select v-model="query.ordertype" placeholder="全部" clearable style="width: 130px">
              <el-option
                v-for="o in ORDERTYPE_OPTIONS"
                :key="o.value"
                :label="o.label"
                :value="o.value"
              />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="success" @click="handleSearch">查询</el-button>
          </el-form-item>
        </el-form>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        class="admin-table"
        @selection-change="(rows: CollectRewardRecordItem[]) => (selected = rows)"
      >
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="奖励ID" width="80" />
        <el-table-column label="会员信息" min-width="150">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="36" />
              <div>
                <div>{{ row.nickname || `会员${row.mid}` }}</div>
                <div class="sub-text">会员ID：{{ row.mid }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="订单号" min-width="180">
          <template #default="{ row }">
            <div>{{ row.ordernum || `#${row.orderid}` }}</div>
            <div class="sub-text">{{ row.ordertime_text }}</div>
            <div v-if="row.business" class="sub-text">商家：{{ row.business }}</div>
          </template>
        </el-table-column>
        <el-table-column label="订单类型" width="120">
          <template #default="{ row }">{{ row.ordertype_text || row.ordertype }}</template>
        </el-table-column>
        <el-table-column label="商品信息" min-width="160">
          <template #default="{ row }">
            <div class="goods-text">{{ row.goods_title || '—' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="奖励类型" width="100">
          <template #default="{ row }">{{ row.reward_type_text }}</template>
        </el-table-column>
        <el-table-column prop="reward_text" label="奖励金额/面额" min-width="140" />
        <el-table-column prop="createtime_text" label="领取时间" width="160" />
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="danger" size="small" @click="handleRowDelete(row)">删除</el-button>
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
.toolbar-card {
  margin-bottom: 3px;
}

.toolbar-row {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 10px;
}

.search-form {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
}

.search-form :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 10px;
}

.admin-table :deep(th) {
  background: #e6e6e6;
}

.admin-table :deep(.cell) {
  text-align: left;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.goods-text {
  color: #606266;
  font-size: 13px;
}

.sub-text {
  color: #909399;
  font-size: 12px;
  line-height: 1.4;
}

.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
</style>
