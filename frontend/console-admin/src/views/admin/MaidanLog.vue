<script setup lang="ts">
function onAvatarError(e: Event) { (e.target as HTMLImageElement).style.visibility = 'hidden' }

import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { mdDelete, mdExport, mdList, mdRefund, type MaidanListQuery, type MaidanOrderRow } from '../../api/maidan'
import { getStoreList } from '../../api/store'
import { assetUrl } from '../../api/upload'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const exporting = ref(false)
const total = ref(0)
const tableData = ref<MaidanOrderRow[]>([])
const selectedRows = ref<MaidanOrderRow[]>([])

const stores = ref<{ id: number; name: string }[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  mid: '',
  nickname: '',
  ordernum: '',
  mdid: '' as number | '',
  timeRange: [] as string[],
})

const pageStat = computed(() => {
  let money = 0
  let paymoney = 0
  let refund = 0
  for (const r of tableData.value) {
    money += Number(r.money) || 0
    paymoney += Number(r.paymoney) || 0
    refund += Number(r.refund_money) || 0
  }
  return {
    money: money.toFixed(2),
    paymoney: paymoney.toFixed(2),
    refund: refund.toFixed(2),
  }
})

function buildParams(): MaidanListQuery {
  return {
    page: query.page,
    pagesize: query.pagesize,
    mid: query.mid ? Number(query.mid) : undefined,
    nickname: query.nickname || undefined,
    ordernum: query.ordernum || undefined,
    mdid: query.mdid === '' ? undefined : Number(query.mdid),
    starttime: query.timeRange?.[0] || undefined,
    endtime: query.timeRange?.[1] || undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await mdList(buildParams())
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    loading.value = false
  }
}

async function loadStores() {
  try {
    const result = await getStoreList({ page: 1, pagesize: 100 })
    stores.value = (result.list || []).map((s) => ({ id: s.id, name: s.name }))
  } catch {
    stores.value = []
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

function handleSelectionChange(rows: MaidanOrderRow[]) {
  selectedRows.value = rows
}

function downloadBlob(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = fileName
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

async function handleExport() {
  exporting.value = true
  try {
    const blob = await mdExport({ ...buildParams(), page: undefined, pagesize: undefined })
    downloadBlob(blob, `买单记录_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.xlsx`)
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    exporting.value = false
  }
}

async function handleBatchDelete() {
  if (selectedRows.value.length === 0) {
    ElMessage.warning('请先勾选要删除的记录')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确认删除选中的 ${selectedRows.value.length} 条买单记录？删除后无法恢复！`,
      '删除确认',
      { type: 'warning', confirmButtonText: '确定删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  try {
    await mdDelete(selectedRows.value.map((r) => r.id))
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 错误提示已由拦截器弹出
  }
}

async function handleRowDelete(row: MaidanOrderRow) {
  try {
    await ElMessageBox.confirm('确定要删除该条买单记录吗？删除后无法恢复！', '删除确认', {
      type: 'warning',
      confirmButtonText: '确定删除',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }
  try {
    await mdDelete([row.id])
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 错误提示已由拦截器弹出
  }
}

// ============ 退款 ============
const refundVisible = ref(false)
const refundTarget = ref<MaidanOrderRow | null>(null)
const refundMoney = ref(0)
const refundReason = ref('')
const refunding = ref(false)

function openRefund(row: MaidanOrderRow) {
  refundTarget.value = row
  refundMoney.value = Number(row.can_refund_money) || 0
  refundReason.value = ''
  refundVisible.value = true
}

async function handleRefund() {
  const target = refundTarget.value
  if (!target) return
  const max = Number(target.can_refund_money) || 0
  const amount = Number(refundMoney.value) || 0
  if (!(amount > 0)) {
    ElMessage.warning('请输入退款金额')
    return
  }
  if (amount > max) {
    ElMessage.warning(`退款金额不能超过可退金额 ¥${max.toFixed(2)}`)
    return
  }
  refunding.value = true
  try {
    await mdRefund({ id: target.id, money: amount, reason: refundReason.value.trim() })
    ElMessage.success('退款成功，余额已原路退回')
    refundVisible.value = false
    await fetchList()
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    refunding.value = false
  }
}

onMounted(() => {
  fetchList()
  loadStores()
})
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item>
          <el-button :disabled="selectedRows.length === 0" @click="handleBatchDelete">删除</el-button>
          <el-button :loading="exporting" @click="handleExport">导出EXCEL</el-button>
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input v-model="query.mid" placeholder="ID" clearable style="width: 80px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="昵称" clearable style="width: 105px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="订单号">
          <el-input v-model="query.ordernum" placeholder="订单号" clearable style="width: 145px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="所属门店">
          <el-select v-model="query.mdid" placeholder="全部" clearable style="width: 130px">
            <el-option label="全部" :value="''" />
            <el-option v-for="s in stores" :key="s.id" :label="s.name" :value="s.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="付款时间">
          <el-date-picker
            v-model="query.timeRange"
            type="datetimerange"
            range-separator="~"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            value-format="YYYY-MM-DD HH:mm:ss"
            style="width: 260px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z"/></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never" class="table-card">
      <div class="stat-inline">
        <span class="stat-inline-item">本页消费：<b>￥{{ pageStat.money }}</b></span>
        <span class="stat-inline-item">本页实付：<b>￥{{ pageStat.paymoney }}</b></span>
        <span class="stat-inline-item">本页退款：<b>￥{{ pageStat.refund }}</b></span>
      </div>
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="ordernum" label="订单号" min-width="160" show-overflow-tooltip />
        <el-table-column label="所属门店" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.mdname || '-' }}</template>
        </el-table-column>
        <el-table-column prop="mid" label="会员ID" width="80" />
        <el-table-column label="会员信息" min-width="150">
          <template #default="{ row }">
            <div class="member-cell">
              <img v-if="row.headimg" :src="assetUrl(row.headimg)" class="member-avatar" @error="onAvatarError" />
              <span>{{ row.nickname || ('会员' + row.mid) }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="付款金额" width="100">
          <template #default="{ row }">￥{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="实付金额" min-width="130">
          <template #default="{ row }">
            <div class="paymoney-cell">
              <span class="paymoney-text">￥{{ Number(row.paymoney).toFixed(2) }}</span>
              <template v-if="Number(row.refund_money) > 0">
                <span class="refund-text">退款:￥{{ Number(row.refund_money).toFixed(2) }}</span>
                <span v-if="row.refund_reason" class="refund-text">原因:{{ row.refund_reason }}</span>
              </template>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="会员折扣" width="90">
          <template #default="{ row }">￥{{ Number(row.disprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="积分抵扣" width="90">
          <template #default="{ row }">￥{{ Number(row.scoredk).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="优惠券抵扣" width="90">
          <template #default="{ row }">￥{{ Number(row.couponmoney).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="付款时间" width="150">
          <template #default="{ row }">
            <el-tooltip v-if="row.paytime" :content="formatTime(row.paytime)" placement="top">
              <span>{{ formatTime(row.paytime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="paytype" label="付款方式" width="100" show-overflow-tooltip>
          <template #default="{ row }">{{ row.paytype || '-' }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="100" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作员" width="120" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.un">{{ row.un }}(操作员ID:{{ row.uid }})</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="100">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button v-if="Number(row.can_refund_money) > 0" link type="warning" @click="openRefund(row)">退款</el-button>
              <el-button link type="danger" @click="handleRowDelete(row)">删除</el-button>
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

    <!-- 退款弹窗 -->
    <el-dialog v-model="refundVisible" title="退款" width="480px" :close-on-click-modal="false">
      <template v-if="refundTarget">
        <el-descriptions :column="1" size="small" border style="margin-bottom: 14px">
          <el-descriptions-item label="订单号">{{ refundTarget.ordernum }}</el-descriptions-item>
          <el-descriptions-item label="实付金额">￥{{ Number(refundTarget.paymoney).toFixed(2) }}</el-descriptions-item>
          <el-descriptions-item label="可退金额">￥{{ Number(refundTarget.can_refund_money).toFixed(2) }}</el-descriptions-item>
        </el-descriptions>
        <el-form label-width="90px">
          <el-form-item label="退款金额">
            <el-input-number
              v-model="refundMoney"
              :min="0.01"
              :max="Number(refundTarget.can_refund_money)"
              :precision="2"
              :step="1"
              style="width: 200px"
            />
            <span style="margin-left: 8px; color: #909399; font-size: 12px">元</span>
          </el-form-item>
          <el-form-item label="退款原因">
            <el-input
              v-model="refundReason"
              type="textarea"
              :rows="3"
              maxlength="200"
              placeholder="请输入退款原因"
            />
          </el-form-item>
        </el-form>
      </template>
      <template #footer>
        <el-button @click="refundVisible = false">取消</el-button>
        <el-button type="primary" :loading="refunding" @click="handleRefund">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 8px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 8px 12px;
}
.toolbar-card :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
}
.toolbar-card :deep(.el-form-item__label) {
  padding-right: 6px;
}
.search-icon-btn {
  padding: 8px 14px;
}
.search-icon-btn svg {
  display: block;
}
.table-card :deep(.el-card__body) {
  padding: 5px;
}
.table-card :deep(.el-table__header-wrapper th.el-table__cell) {
  background: #e6e6e6;
}
.table-card :deep(.el-table .cell) {
  text-align: left;
  padding-left: 6px;
  padding-right: 6px;
}
.table-card :deep(.el-table td.el-table__cell) {
  padding: 3px 0;
}
.stat-inline {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 6px 18px;
  padding: 2px 6px 6px;
  font-size: 13px;
  color: #606266;
}
.stat-inline b {
  color: #303133;
  font-weight: 700;
}
.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}
.member-avatar {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  object-fit: cover;
  border: 1px solid #ebeef5;
  flex-shrink: 0;
}
.paymoney-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.paymoney-text {
  color: #f55555;
  font-weight: 600;
}
.refund-text {
  color: #f56c6c;
  font-size: 12px;
}
.row-ops {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  gap: 2px 6px;
  white-space: nowrap;
  align-items: center;
}
.row-ops :deep(.el-button) {
  height: auto;
  padding: 0;
  margin: 0;
  font-size: 12px;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
</style>
