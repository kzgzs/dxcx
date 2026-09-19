<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  cancelMiandan,
  deleteMiandan,
  getDeliveryList,
  getMiandanList,
  getMiandanOrder,
  getMiandanPath,
  getMiandanSet,
  saveMiandanSet,
} from '../../api/ewaybill'
import type { DeliveryAccount, MiandanOrder, MiandanSet, PathResult } from '../../api/ewaybill'

// ===== 列表 =====
const loading = ref(false)
const isMock = ref(false)
const tableData = ref<MiandanOrder[]>([])
const total = ref(0)
const query = reactive({
  page: 1,
  pagesize: 10,
  order_id: '',
  waybill_id: '',
  name: '',
  status: '',
  timerange: [] as string[],
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getMiandanList({
      page: query.page,
      pagesize: query.pagesize,
      order_id: query.order_id,
      waybill_id: query.waybill_id,
      name: query.name,
      status: query.status,
      starttime: query.timerange?.[0] || '',
      endtime: query.timerange?.[1] || '',
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
  query.order_id = ''
  query.waybill_id = ''
  query.name = ''
  query.status = ''
  query.timerange = []
  handleSearch()
}

function statusTag(status: number) {
  if (status === 1) return { type: 'success', text: '正常' }
  if (status === 4) return { type: 'info', text: '已取消' }
  return { type: 'warning', text: '待处理' }
}

// ===== 轨迹弹窗 =====
const pathVisible = ref(false)
const pathLoading = ref(false)
const pathData = ref<PathResult | null>(null)

async function openPath(row: MiandanOrder) {
  pathVisible.value = true
  pathLoading.value = true
  pathData.value = null
  try {
    pathData.value = await getMiandanPath({
      order_id: row.order_id,
      delivery_id: row.delivery_id,
      waybill_id: row.waybill_id,
    })
  } catch {
    pathVisible.value = false
  } finally {
    pathLoading.value = false
  }
}

// ===== 打印弹窗（iframe 渲染 mock 打印 HTML） =====
const printVisible = ref(false)
const printLoading = ref(false)
const printHtml = ref('')

async function openPrint(row: MiandanOrder) {
  printVisible.value = true
  printLoading.value = true
  printHtml.value = ''
  try {
    const data = await getMiandanOrder({
      order_id: row.order_id,
      delivery_id: row.delivery_id,
      waybill_id: row.waybill_id,
    })
    printHtml.value = data.print_html || ''
  } catch {
    printVisible.value = false
  } finally {
    printLoading.value = false
  }
}

// ===== 取消 / 删除 =====
async function handleCancel(row: MiandanOrder) {
  try {
    await ElMessageBox.confirm(`确定取消运单 ${row.order_id} 吗？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await cancelMiandan({ order_id: row.order_id, delivery_id: row.delivery_id, waybill_id: row.waybill_id })
    ElMessage.success('取消成功')
    fetchList()
  } catch {
    // ignore
  }
}

async function handleDelete(row: MiandanOrder) {
  try {
    await ElMessageBox.confirm('确定删除该运单记录吗？', '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await deleteMiandan([row.id])
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // ignore
  }
}

// ===== 物流设置 tab =====
const activeTab = ref('list')
const setForm = reactive<MiandanSet>({ id: 0, aid: 0, status: 1, query_status: 1, print_type: 0 })
const setSaving = ref(false)
const deliveryAccounts = ref<DeliveryAccount[]>([])

async function fetchSet() {
  try {
    const data = await getMiandanSet()
    setForm.id = data.id
    setForm.aid = data.aid
    setForm.status = data.status
    setForm.query_status = data.query_status
    setForm.print_type = data.print_type
    isMock.value = isMock.value || !!data.is_mock
  } catch {
    // ignore
  }
  try {
    const accounts = await getDeliveryList()
    deliveryAccounts.value = accounts.list || []
  } catch {
    deliveryAccounts.value = []
  }
}

async function handleSaveSet() {
  setSaving.value = true
  try {
    await saveMiandanSet({ status: setForm.status, query_status: setForm.query_status, print_type: setForm.print_type })
    ElMessage.success('保存成功')
  } catch {
    // ignore
  } finally {
    setSaving.value = false
  }
}

function onTabChange(tab: string | number) {
  if (tab === 'set') fetchSet()
}

onMounted(fetchList)
</script>

<template>
  <div class="miandan-page">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>物流助手</span>
          <el-tag v-if="isMock" type="warning" size="small">EWAYBILL_MOCK 演示模式</el-tag>
        </div>
      </template>

      <el-tabs v-model="activeTab" @tab-change="onTabChange">
        <!-- 运单记录 -->
        <el-tab-pane label="运单记录" name="list">
          <div class="filter-bar">
            <el-input v-model="query.order_id" placeholder="订单号" clearable style="width: 180px" @keyup.enter="handleSearch" />
            <el-input v-model="query.waybill_id" placeholder="运单号" clearable style="width: 180px" @keyup.enter="handleSearch" />
            <el-input v-model="query.name" placeholder="收件人姓名/手机号" clearable style="width: 180px" @keyup.enter="handleSearch" />
            <el-select v-model="query.status" placeholder="状态" clearable style="width: 120px">
              <el-option label="正常" value="1" />
              <el-option label="已取消" value="4" />
            </el-select>
            <el-date-picker
              v-model="query.timerange"
              type="daterange"
              range-separator="至"
              start-placeholder="开始日期"
              end-placeholder="结束日期"
              value-format="YYYY-MM-DD"
              style="width: 240px"
            />
            <el-button type="primary" @click="handleSearch">查询</el-button>
            <el-button @click="handleReset">重置</el-button>
          </div>

          <el-table v-loading="loading" :data="tableData" border stripe style="width: 100%">
            <el-table-column prop="order_id" label="订单号" min-width="160" />
            <el-table-column prop="delivery_name" label="快递公司" width="120" />
            <el-table-column prop="waybill_id" label="运单号" min-width="150" />
            <el-table-column label="收件人" min-width="140">
              <template #default="{ row }">
                <div>{{ row.receiver_name }}</div>
                <div class="sub-text">{{ row.receiver_mobile || row.receiver_tel }}</div>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <el-tag :type="statusTag(row.status).type as any" size="small">{{ statusTag(row.status).text }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="createtime_text" label="创建时间" width="165" />
            <el-table-column label="操作" width="220" fixed="right">
              <template #default="{ row }">
                <el-button link type="primary" @click="openPath(row)">轨迹</el-button>
                <el-button link type="primary" @click="openPrint(row)">打印</el-button>
                <el-button v-if="row.status === 1" link type="warning" @click="handleCancel(row)">取消</el-button>
                <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
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
        </el-tab-pane>

        <!-- 物流设置 -->
        <el-tab-pane label="物流设置" name="set">
          <el-form label-width="180px" style="max-width: 640px">
            <el-form-item label="开启物流助手">
              <el-switch v-model="setForm.status" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="允许客户查询物流">
              <el-switch v-model="setForm.query_status" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="打印模板">
              <el-radio-group v-model="setForm.print_type">
                <el-radio :value="0">二联单</el-radio>
                <el-radio :value="1">一联单</el-radio>
              </el-radio-group>
              <div class="sub-text">顺丰不支持一联单，查询时自动切换为二联单</div>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="setSaving" @click="handleSaveSet">保存设置</el-button>
            </el-form-item>
          </el-form>

          <el-divider content-position="left">已绑定物流账号（MOCK 演示数据）</el-divider>
          <el-table :data="deliveryAccounts" border stripe style="max-width: 720px">
            <el-table-column prop="delivery_name" label="快递公司" />
            <el-table-column prop="delivery_id" label="编码" width="120" />
            <el-table-column prop="biz_id" label="商户号" min-width="160" />
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 轨迹弹窗 -->
    <el-dialog v-model="pathVisible" title="物流轨迹" width="520px">
      <div v-loading="pathLoading">
        <template v-if="pathData">
          <div class="path-header">
            <span>运单号：{{ pathData.waybill_id }}</span>
            <el-tag size="small" type="primary">{{ pathData.state_text }}</el-tag>
          </div>
          <el-timeline>
            <el-timeline-item v-for="(step, index) in pathData.steps" :key="index" :timestamp="step.time">
              {{ step.text }}
            </el-timeline-item>
          </el-timeline>
        </template>
      </div>
    </el-dialog>

    <!-- 打印预览弹窗 -->
    <el-dialog v-model="printVisible" title="运单打印预览" width="780px">
      <div v-loading="printLoading">
        <iframe v-if="printHtml" :srcdoc="printHtml" class="print-frame" />
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
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}
.sub-text {
  color: #909399;
  font-size: 12px;
}
.path-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
}
.print-frame {
  width: 100%;
  height: 420px;
  border: 1px solid #ebeef5;
}
</style>
