<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { closeSeckillOrder, getSeckillOrderList } from '../../api/seckill'
import type { SeckillOrderItem } from '../../api/seckill'
import { formatTime } from '../../utils/time'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<SeckillOrderItem[]>([])
const total = ref(0)

// tabs：''全部 0未付 1已付 2已发货 3已收货 4已关闭
const activeTab = ref('')

const query = reactive({
  page: 1,
  pagesize: 10,
  ordernum: '',
  proname: '',
  linkman: '',
  tel: '',
  dateRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getSeckillOrderList({
      page: query.page,
      pagesize: query.pagesize,
      status: activeTab.value === '' ? undefined : Number(activeTab.value),
      ordernum: query.ordernum || undefined,
      proname: query.proname || undefined,
      linkman: query.linkman || undefined,
      tel: query.tel || undefined,
      start_date: query.dateRange ? query.dateRange[0] : undefined,
      end_date: query.dateRange ? query.dateRange[1] : undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleTabChange() {
  query.page = 1
  fetchList()
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.ordernum = ''
  query.proname = ''
  query.linkman = ''
  query.tel = ''
  query.dateRange = null
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

const statusMap: Record<number, { text: string; type: 'info' | 'primary' | 'success' | 'warning' | 'danger' }> = {
  0: { text: '未支付', type: 'info' },
  1: { text: '已支付', type: 'primary' },
  2: { text: '已发货', type: 'warning' },
  3: { text: '已收货', type: 'success' },
  4: { text: '已关闭', type: 'danger' },
}

function statusText(status: number) {
  return statusMap[status]?.text || '未知'
}

function statusType(status: number) {
  return statusMap[status]?.type || 'info'
}

// ============ 关单（仅未支付） ============
function handleClose(row: SeckillOrderItem) {
  ElMessageBox.confirm(
    `确定要关闭订单「${row.ordernum}」吗？关闭后将回补库存并退回优惠券，不可恢复。`,
    '关单确认',
    { confirmButtonText: '关闭订单', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        await closeSeckillOrder(row.id)
        ElMessage.success('订单已关闭')
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
    <el-card shadow="never">
      <!-- 状态 tabs -->
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <el-tab-pane label="全部" name="" />
        <el-tab-pane label="未支付" name="0" />
        <el-tab-pane label="已支付" name="1" />
        <el-tab-pane label="已发货" name="2" />
        <el-tab-pane label="已收货" name="3" />
        <el-tab-pane label="已关闭" name="4" />
      </el-tabs>

      <!-- 搜索工具栏 -->
      <el-form inline @submit.prevent>
        <el-form-item label="订单号">
          <el-input
            v-model="query.ordernum"
            placeholder="订单号"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="商品">
          <el-input
            v-model="query.proname"
            placeholder="商品名称"
            clearable
            style="width: 160px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="收货人">
          <el-input
            v-model="query.linkman"
            placeholder="收货人"
            clearable
            style="width: 130px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="手机号">
          <el-input
            v-model="query.tel"
            placeholder="手机号"
            clearable
            style="width: 140px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="下单时间">
          <el-date-picker
            v-model="query.dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
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

      <!-- 订单列表 -->
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="ordernum" label="订单号" width="190" />
        <el-table-column label="商品" min-width="200">
          <template #default="{ row }">
            <div class="order-product">
              <el-image
                v-if="row.propic"
                :src="row.propic"
                fit="cover"
                style="width: 36px; height: 36px; border-radius: 4px"
              />
              <div>
                <div class="product-name">{{ row.proname }}</div>
                <div v-if="row.ggname" class="text-muted">{{ row.ggname }} × {{ row.num }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="买家" min-width="110">
          <template #default="{ row }">{{ row.nickname || `会员${row.mid}` }}</template>
        </el-table-column>
        <el-table-column label="金额" width="100">
          <template #default="{ row }">￥{{ Number(row.totalprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="收货人" width="130">
          <template #default="{ row }">
            <div>{{ row.linkman }}</div>
            <div class="text-muted">{{ row.tel }}</div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small">{{ statusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="下单时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="120" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.status === 0" link type="danger" @click="handleClose(row)">
              关单
            </el-button>
            <span v-else class="text-muted">-</span>
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
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.order-product {
  display: flex;
  align-items: center;
  gap: 8px;
}

.product-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 220px;
}

.text-muted {
  color: #909399;
  font-size: 12px;
}
</style>
