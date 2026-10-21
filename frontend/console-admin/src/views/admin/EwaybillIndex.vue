<script setup lang="ts">
/**
 * 电子面单 - 面单记录（ewaybill_order）
 * 对齐参考「扩展--电子面单--面单记录」：
 * - 顶部绿色提示块（电子面单取消 / 通知取件 说明）
 * - 工具栏 通知取件 / 导出 / 批量打印面单 / 删除 与搜索项同一行（框收窄不换行）
 * - 表格列：ID / 订单信息 / 快递信息 / 收货信息 / 商品信息 / 揽件时间 / 创建时间 / 状态 / 操作
 * - 导出(ewaybill-excel)、删除(ewaybill-del) 为隐藏动作工具栏按钮；行操作 详情/打印/取消/删除
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  cancelEwaybill,
  deleteEwaybill,
  exportEwaybill,
  getEwaybillDetail,
  getEwaybillList,
  getNoticeExpressList,
  printEwaybill,
  yuyueNoticeEwaybill,
} from '../../api/ewaybill'
import type { EwaybillOrder } from '../../api/ewaybill'

const loading = ref(false)
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

function handleSelectionChange(rows: EwaybillOrder[]) {
  selectedIds.value = rows.map((r) => r.id)
}

/* ================= 导出（xlsx Blob） ================= */
async function handleExport() {
  try {
    const blob = await exportEwaybill({
      ordernum: query.ordernum || undefined,
      ordertype: query.ordertype || undefined,
      name: query.name || undefined,
      status: query.status || undefined,
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `电子面单记录_${new Date().getTime()}.xlsx`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    ElMessage.success('导出成功')
  } catch {
    // ignore
  }
}

/* ================= 通知取件 ================= */
const noticeVisible = ref(false)
const noticeLoading = ref(false)
const noticeList = ref<{ name: string; code: string }[]>([])
const noticeCodes = ref<string[]>([])

async function openNotice() {
  noticeVisible.value = true
  noticeLoading.value = true
  noticeCodes.value = []
  try {
    const data = await getNoticeExpressList()
    noticeList.value = data.list || []
  } catch {
    noticeList.value = []
  } finally {
    noticeLoading.value = false
  }
}

async function handleNoticeSubmit() {
  if (!noticeCodes.value.length) {
    ElMessage.warning('请选择快递公司')
    return
  }
  try {
    await yuyueNoticeEwaybill(noticeCodes.value)
    noticeVisible.value = false
  } catch {
    // ignore（失败消息由 axios 拦截器统一弹出）
  }
}

/* ================= 详情（el-descriptions 双列） ================= */
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

/* ================= 打印（iframe srcdoc 渲染打印模板 HTML） ================= */
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

/* ================= 取消 / 删除 ================= */
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
    // ignore
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
        // ignore
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never" class="compact-title">
      <template #header>
        <div class="card-header">
          <span>面单记录</span>
        </div>
      </template>

      <!-- 顶部提示块（绿色左边框） -->
      <div class="notice-block">
        <div>1、电子面单取消：</div>
        <div class="indent">（1）仅支持取消当天下单成功且未被快递员揽收的订单。</div>
        <div class="indent">（2）支持快递公司：优速快递、德邦速递、京东快递、韵达速递、跨越速运。</div>
        <div>2、通知取件：</div>
        <div class="indent">（1）目前支持顺丰速运、中通快递、圆通速递、韵达速递、优速快递、申通快递。</div>
        <div class="indent">（2）预约时间仅限当日。</div>
      </div>

      <!-- 工具栏：按钮与搜索同一行 -->
      <div class="toolbar">
        <div class="toolbar-btns">
          <el-button size="small" @click="openNotice">通知取件</el-button>
          <el-button size="small" @click="handleExport">导出</el-button>
          <el-button size="small" type="primary" plain @click="openPrint(selectedIds)">批量打印面单</el-button>
          <el-button size="small" type="danger" plain @click="handleDelete(selectedIds)">删除</el-button>
        </div>
        <div class="toolbar-search">
          <el-input
            v-model="query.ordernum"
            placeholder="订单号、运单号、面单订单号"
            clearable
            class="w-220"
            @keyup.enter="handleSearch"
          />
          <el-input
            v-model="query.name"
            placeholder="姓名、手机号或座机号"
            clearable
            class="w-180"
            @keyup.enter="handleSearch"
          />
          <el-select v-model="query.ordertype" placeholder="全部" clearable class="w-120">
            <el-option v-for="t in ORDER_TYPES" :key="t.value" :label="t.label" :value="t.value" />
          </el-select>
          <el-select v-model="query.status" placeholder="请选择" clearable class="w-110">
            <el-option label="待处理" value="0" />
            <el-option label="正常" value="1" />
            <el-option label="已取消" value="2" />
          </el-select>
          <el-button type="primary" @click="handleSearch">查询</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="tableData" border stripe @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" sortable />
        <el-table-column label="订单信息" min-width="170" show-overflow-tooltip>
          <template #default="{ row }">
            <div>{{ row.ordernum }}</div>
            <div class="sub-text">面单号：{{ row.order_code }}</div>
          </template>
        </el-table-column>
        <el-table-column label="快递信息" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">
            <div>{{ row.express_com || '-' }}</div>
            <div class="sub-text">{{ row.express_no || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="收货信息" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            <div>{{ row.receiver_name }} {{ row.receiver_mobile || row.receiver_tel }}</div>
            <div class="sub-text">{{ row.receiver_full_address }}</div>
          </template>
        </el-table-column>
        <el-table-column label="商品信息" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">
            <span>{{ row.goods_detail || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="揽件时间" width="150">
          <template #default="{ row }">
            {{ row.expect_start_time_text || '-' }}
          </template>
        </el-table-column>
        <el-table-column prop="createtime_text" label="创建时间" width="150" sortable />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status).type as 'success'" size="small">
              {{ statusTag(row.status).text }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200">
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

    <!-- 通知取件弹窗 -->
    <el-dialog v-model="noticeVisible" title="通知取件" width="420px">
      <div v-loading="noticeLoading">
        <el-checkbox-group v-model="noticeCodes" v-if="noticeList.length">
          <el-checkbox v-for="item in noticeList" :key="item.code" :value="item.code" style="display: block; margin-bottom: 8px">
            {{ item.name }}（{{ item.code }}）
          </el-checkbox>
        </el-checkbox-group>
        <el-empty v-else-if="!noticeLoading" description="暂无可通知的快递公司（需有已出单且支持预约的面单）" />
      </div>
      <template #footer>
        <el-button @click="noticeVisible = false">取消</el-button>
        <el-button type="primary" @click="handleNoticeSubmit">通知取件</el-button>
      </template>
    </el-dialog>

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

.notice-block {
  background: #f5f7fa;
  border-left: 3px solid #19be6b;
  padding: 12px 16px;
  margin-bottom: 3px;
  line-height: 1.9;
  font-size: 13px;
  color: #515a6e;
}
.notice-block .indent {
  padding-left: 2em;
}

.toolbar {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 10px;
  margin-bottom: 3px;
}
.toolbar-btns {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}
.toolbar-search {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  flex-wrap: nowrap;
  justify-content: flex-end;
}
.w-220 { width: 220px; }
.w-180 { width: 180px; }
.w-120 { width: 120px; }
.w-110 { width: 110px; }

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 3px;
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
