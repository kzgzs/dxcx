<script setup lang="ts">
/**
 * 电子面单 - 面单记录（ewaybill_order）
 * - 筛选：订单号模糊 / 订单类型（shop/tuangou/seckill/collage）/ 收件人 / 状态
 * - 详情 el-descriptions 双列；打印 iframe :srcdoc 渲染 ewaybill/print 返回 HTML
 * - 取消仅 status=1（快递鸟限制仅当日可取消）；删除单条/批量
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  cancelEwaybill,
  deleteEwaybill,
  getEwaybillDetail,
  getEwaybillList,
  printEwaybill,
} from '../../api/ewaybill'
import type { EwaybillOrder } from '../../api/ewaybill'

const loading = ref(false)
const isMock = ref(false)
const tableData = ref<EwaybillOrder[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  ordernum: '',
  ordertype: '',
  name: '',
  status: '',
})

/** 订单类型下拉（参考口径） */
const ORDER_TYPES = [
  { value: 'shop', label: '商城订单' },
  { value: 'tuangou', label: '团购订单' },
  { value: 'seckill', label: '秒杀订单' },
  { value: 'collage', label: '拼团订单' },
]

/** 状态 tag（0待处理 1正常 2已取消） */
function statusTag(status: number) {
  if (status === 1) return { type: 'success', text: '正常' }
  if (status === 2) return { type: 'info', text: '已取消' }
  return { type: 'warning', text: '待处理' }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getEwaybillList({
      page: query.page,
      pagesize: query.pagesize,
      ordernum: query.ordernum || undefined,
      ordertype: query.ordertype || undefined,
      name: query.name || undefined,
      status: query.status || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    isMock.value = !!data.is_mock
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
  query.ordernum = ''
  query.ordertype = ''
  query.name = ''
  query.status = ''
  handleSearch()
}

function handleSelectionChange(rows: EwaybillOrder[]) {
  selectedIds.value = rows.map((r) => r.id)
}

// ============ 详情（el-descriptions 双列） ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<EwaybillOrder | null>(null)

async function openDetail(row: EwaybillOrder) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await getEwaybillDetail(row.id)
  } catch {
    detailVisible.value = false
  } finally {
    detailLoading.value = false
  }
}

// ============ 打印（iframe srcdoc 渲染打印模板 HTML） ============
const printVisible = ref(false)
const printLoading = ref(false)
const printHtml = ref('')

async function openPrint(ids: number[]) {
  if (!ids.length) {
    ElMessage.warning('请选择面单')
    return
  }
  printVisible.value = true
  printLoading.value = true
  printHtml.value = ''
  try {
    const data = await printEwaybill(ids)
    printHtml.value = (data.list || []).join('<div style="page-break-after:always"></div>')
  } catch {
    printVisible.value = false
  } finally {
    printLoading.value = false
  }
}

// ============ 取消 / 删除 ============
async function handleCancel(row: EwaybillOrder) {
  try {
    await ElMessageBox.confirm(
      `确定取消面单 ${row.order_code} 吗？快递公司仅支持取消当日创建的面单。`,
      '提示',
      { type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await cancelEwaybill(row.id)
    ElMessage.success('取消成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleDelete(ids: number[]) {
  if (!ids.length) {
    ElMessage.warning('请选择面单')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${ids.length} 条面单记录吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteEwaybill(ids)
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
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>面单记录</span>
          <el-tag v-if="isMock" type="warning" size="small">EWAYBILL_MOCK 演示模式</el-tag>
        </div>
      </template>

      <div class="filter-bar">
        <el-input
          v-model="query.ordernum"
          placeholder="订单号"
          clearable
          style="width: 180px"
          @keyup.enter="handleSearch"
        />
        <el-select v-model="query.ordertype" placeholder="订单类型" clearable style="width: 140px">
          <el-option v-for="t in ORDER_TYPES" :key="t.value" :label="t.label" :value="t.value" />
        </el-select>
        <el-input
          v-model="query.name"
          placeholder="收件人姓名/手机号"
          clearable
          style="width: 180px"
          @keyup.enter="handleSearch"
        />
        <el-select v-model="query.status" placeholder="状态" clearable style="width: 120px">
          <el-option label="待处理" value="0" />
          <el-option label="正常" value="1" />
          <el-option label="已取消" value="2" />
        </el-select>
        <el-button type="primary" @click="handleSearch">查询</el-button>
        <el-button @click="handleReset">重置</el-button>
        <div class="filter-right">
          <el-button type="primary" plain @click="openPrint(selectedIds)">批量打印</el-button>
          <el-button type="danger" @click="handleDelete(selectedIds)">批量删除</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="tableData" border stripe @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="45" />
        <el-table-column prop="ordertype_name" label="订单类型" width="100" />
        <el-table-column prop="ordernum" label="订单号" min-width="160" show-overflow-tooltip />
        <el-table-column prop="order_code" label="面单订单号" min-width="150" show-overflow-tooltip />
        <el-table-column prop="express_com" label="快递公司" width="100" />
        <el-table-column prop="express_no" label="快递单号" min-width="150" show-overflow-tooltip />
        <el-table-column label="收件人" min-width="130">
          <template #default="{ row }">
            <div>{{ row.receiver_name }}</div>
            <div class="sub-text">{{ row.receiver_mobile || row.receiver_tel }}</div>
          </template>
        </el-table-column>
        <el-table-column label="包裹" width="110">
          <template #default="{ row }">
            <div>{{ row.quantity }} 件</div>
            <div class="sub-text">{{ row.weight }} kg</div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status).type as 'success'" size="small">
              {{ statusTag(row.status).text }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="createtime_text" label="创建时间" width="165" />
        <el-table-column label="操作" width="210" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
            <el-button link type="primary" @click="openPrint([row.id])">打印</el-button>
            <el-button v-if="row.status === 1" link type="warning" @click="handleCancel(row)">取消</el-button>
            <el-button link type="danger" @click="handleDelete([row.id])">删除</el-button>
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
          @size-change="fetchList"
          @current-change="fetchList"
        />
      </div>
    </el-card>

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="面单详情" width="760px">
      <div v-loading="detailLoading">
        <el-descriptions v-if="detail" :column="2" border>
          <el-descriptions-item label="订单类型">{{ detail.ordertype_name }}</el-descriptions-item>
          <el-descriptions-item label="订单号">{{ detail.ordernum }}</el-descriptions-item>
          <el-descriptions-item label="面单订单号">{{ detail.order_code }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag :type="statusTag(detail.status).type as 'success'" size="small">
              {{ detail.status_name }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="快递公司">{{ detail.express_com || '-' }}</el-descriptions-item>
          <el-descriptions-item label="快递单号">{{ detail.express_no || '-' }}</el-descriptions-item>
          <el-descriptions-item label="子母单号">{{ detail.sub_express_no || '-' }}</el-descriptions-item>
          <el-descriptions-item label="付款方式">{{ detail.paytype_text || '-' }}</el-descriptions-item>
          <el-descriptions-item label="包裹数量">{{ detail.quantity }} 件</el-descriptions-item>
          <el-descriptions-item label="重量">{{ detail.weight }} kg</el-descriptions-item>
          <el-descriptions-item label="体积">{{ detail.volume }} m³</el-descriptions-item>
          <el-descriptions-item label="保价">
            {{ detail.insure === 1 ? `是（${detail.insure_value} 元）` : '否' }}
          </el-descriptions-item>
          <el-descriptions-item label="温层">{{ detail.warm_layer_text || detail.warm_layer || '-' }}</el-descriptions-item>
          <el-descriptions-item label="预约时间">
            {{ detail.expect_start_time_text && detail.expect_end_time_text ? `${detail.expect_start_time_text} ~ ${detail.expect_end_time_text}` : '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="收件人">
            {{ detail.receiver_name }}　{{ detail.receiver_mobile || detail.receiver_tel }}
          </el-descriptions-item>
          <el-descriptions-item label="收件地址">{{ detail.receiver_full_address }}</el-descriptions-item>
          <el-descriptions-item label="发件人">
            {{ detail.sender_name }}　{{ detail.sender_mobile || detail.sender_tel }}
          </el-descriptions-item>
          <el-descriptions-item label="发件地址">{{ detail.sender_full_address }}</el-descriptions-item>
          <el-descriptions-item label="备注" :span="2">{{ detail.remark || '-' }}</el-descriptions-item>
          <el-descriptions-item label="创建时间">{{ detail.createtime_text }}</el-descriptions-item>
        </el-descriptions>
      </div>
    </el-dialog>

    <!-- 打印预览弹窗 -->
    <el-dialog v-model="printVisible" title="面单打印预览" width="820px">
      <div v-loading="printLoading">
        <iframe v-if="printHtml" :srcdoc="printHtml" class="print-frame" />
        <el-empty v-else-if="!printLoading" description="暂无打印内容" />
      </div>
    </el-dialog>
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  gap: 10px;
}

.filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 14px;
}

.filter-right {
  margin-left: auto;
  display: flex;
  gap: 10px;
}

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}

.sub-text {
  color: #909399;
  font-size: 12px;
}

.print-frame {
  width: 100%;
  height: 480px;
  border: 1px solid #ebeef5;
}
</style>
