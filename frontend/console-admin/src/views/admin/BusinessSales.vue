<script setup lang="ts">
/**
 * 商户 - 商户销量（对照参考系统 business/sales.html）
 * - 工具栏：左侧删除；右侧商户ID/商家名称 + 搜索
 * - 列：ID(可排序) / 商家信息 / 总销量 / 总营业额 / 虚拟销量 / 各业务「销量+营业额」成对列（按 sales_auth 显隐）
 * - GET businessSales/list：bid=0 显示为「平台」
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import {
  deleteBusinessSales,
  getBusinessSalesList,
  type BusinessSalesAuth,
  type BusinessSalesItem,
} from '../../api/business'

const loading = ref(false)
const tableData = ref<BusinessSalesItem[]>([])
const total = ref(0)
const salesAuth = ref<BusinessSalesAuth>({ all: true })
const selectedBids = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  bid: '',
  name: '',
  field: '',
  order: '',
})

/**
 * 13 类业务列（顺序、标签、字段严格对照参考系统 sales.html）
 * 每类输出「销量 + 营业额」两列
 */
const BUSINESS_COLUMNS: { type: string; authKey: string; label: string }[] = [
  { type: 'shop', authKey: 'shop', label: '商品' },
  { type: 'collage', authKey: 'collage', label: '多人拼团' },
  { type: 'kanjia', authKey: 'kanjia', label: '砍价' },
  { type: 'seckill', authKey: 'seckill', label: '秒杀' },
  { type: 'tuangou', authKey: 'tuangou', label: '团购' },
  { type: 'scoreshop', authKey: 'scoreshop', label: '积分商品' },
  { type: 'lucky_collage', authKey: 'luckyCollage', label: '幸运拼团' },
  { type: 'cycle', authKey: 'cycle', label: '周期购' },
  { type: 'yuyue', authKey: 'yuyue', label: '预约服务' },
  { type: 'kecheng', authKey: 'kecheng', label: '课程' },
  { type: 'restaurant_takeaway', authKey: 'restaurantTakeaway', label: '餐饮外卖' },
  { type: 'restaurant_shop', authKey: 'restaurantShop', label: '餐饮点餐' },
  { type: 'maidan', authKey: 'maidan', label: '买单' },
]

/** 列显隐：all 权限全显，否则按模块权限逐项判定 */
function columnVisible(authKey: string) {
  return salesAuth.value.all || !!salesAuth.value[authKey]
}

function num(v: unknown): string {
  return Number(v || 0).toLocaleString()
}
function money(v: unknown): string {
  return Number(v || 0).toFixed(2)
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getBusinessSalesList({
      page: query.page,
      pagesize: query.pagesize,
      bid: query.bid || undefined,
      name: query.name || undefined,
      field: query.field || undefined,
      order: query.order || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    salesAuth.value = data.sales_auth || { all: true }
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

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

/** 表头排序（对照参考系统 autoSort:false，field/order 交后端白名单排序） */
function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  query.field = order ? prop : ''
  query.order = order === 'ascending' ? 'asc' : order === 'descending' ? 'desc' : ''
  query.page = 1
  fetchList()
}

function handleSelectionChange(rows: BusinessSalesItem[]) {
  selectedBids.value = rows.map((r) => r.bid)
}

/** 删除（对照参考系统 datadel：取选中行 bid，确认文案一致） */
function handleDelete() {
  if (!selectedBids.value.length) {
    ElMessage.warning('请选择数据')
    return
  }
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复，且前端商户销量会展示异常！', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteBusinessSales(selectedBids.value)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div class="sales-page">
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button @click="handleDelete">删除</el-button>
        </div>
        <div class="toolbar-right">
          <el-input
            v-model="query.bid"
            placeholder="商户ID"
            clearable
            class="bid-input"
            @keyup.enter="handleSearch"
          />
          <el-input
            v-model="query.name"
            placeholder="商家名称"
            clearable
            class="name-input"
            @keyup.enter="handleSearch"
          />
          <el-button type="primary" :icon="Search" @click="handleSearch" />
        </div>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        border
        stripe
        size="small"
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" sortable="custom" align="center" />
        <el-table-column label="商家信息" width="180">
          <template #default="{ row }">
            <div class="biz-info">
              <div>商家名称：{{ row.name || (row.bid === 0 ? '平台' : '-') }}</div>
              <div>商家ID：{{ row.bid }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="total_sales" label="总销量" width="90" align="right">
          <template #default="{ row }">{{ num(row.total_sales) }}</template>
        </el-table-column>
        <el-table-column prop="total_money" label="总营业额" width="100" align="right">
          <template #default="{ row }">{{ money(row.total_money) }}</template>
        </el-table-column>
        <el-table-column prop="sales" label="虚拟销量" width="90" align="right">
          <template #default="{ row }">{{ num(row.sales) }}</template>
        </el-table-column>
        <template v-for="col in BUSINESS_COLUMNS" :key="col.type">
          <el-table-column
            v-if="columnVisible(col.authKey)"
            :prop="`${col.type}_sales`"
            :label="`${col.label}销量`"
            width="100"
            align="right"
            header-align="center"
          >
            <template #default="{ row }">{{ num(row[`${col.type}_sales`]) }}</template>
          </el-table-column>
          <el-table-column
            v-if="columnVisible(col.authKey)"
            :prop="`${col.type}_money`"
            :label="`${col.label}营业额`"
            width="100"
            align="right"
            header-align="center"
          >
            <template #default="{ row }">{{ money(row[`${col.type}_money`]) }}</template>
          </el-table-column>
        </template>
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
.sales-page {
  /* 主区域四边边距由 AdminLayout .admin-main 统一 3px，页面不再自带 padding */
}

.toolbar-card {
  margin-bottom: 3px;
}

.toolbar-card :deep(.el-card__body) {
  padding: 12px 14px;
}

.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.toolbar-right {
  display: flex;
  gap: 8px;
  align-items: center;
}

.bid-input {
  width: 130px;
}

.name-input {
  width: 160px;
}

.biz-info {
  line-height: 20px;
  color: #303133;
  white-space: normal;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
