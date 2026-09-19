<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { mdExport, mdList, mdRefund, type MaidanListQuery, type MaidanOrderRow } from '../../api/maidan'
import { getStoreList } from '../../api/store'

const loading = ref(false)
const exporting = ref(false)

const filters = reactive({
  ordernum: '',
  nickname: '',
  mdid: '' as number | '',
  status: '' as number | '',
  range: [] as string[],
})

const stores = ref<{ id: number; name: string }[]>([])

const list = ref<MaidanOrderRow[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(10)

// 退款弹窗
const refundVisible = ref(false)
const refundTarget = ref<MaidanOrderRow | null>(null)
const refundMoney = ref(0)
const refundReason = ref('')
const refunding = ref(false)

function buildQuery(): MaidanListQuery {
  return {
    page: page.value,
    pagesize: pagesize.value,
    ordernum: filters.ordernum.trim(),
    nickname: filters.nickname.trim(),
    mdid: filters.mdid === '' ? undefined : Number(filters.mdid),
    status: filters.status === '' ? undefined : Number(filters.status),
    starttime: filters.range?.[0] || '',
    endtime: filters.range?.[1] || '',
  }
}

async function loadData() {
  loading.value = true
  try {
    const result = await mdList(buildQuery())
    list.value = result.list || []
    total.value = result.total || 0
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  page.value = 1
  loadData()
}

function handleReset() {
  filters.ordernum = ''
  filters.nickname = ''
  filters.mdid = ''
  filters.status = ''
  filters.range = []
  handleSearch()
}

function formatTime(ts: number) {
  if (!ts) return '—'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

function statusText(row: MaidanOrderRow) {
  if (Number(row.refund_money) >= Number(row.paymoney) && Number(row.paymoney) > 0) return '已全额退款'
  if (Number(row.refund_money) > 0) return '部分退款'
  return '已付款'
}

// ============ 退款 ============
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
    await loadData()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    refunding.value = false
  }
}

// ============ 导出 ============
async function handleExport() {
  exporting.value = true
  try {
    const blob = await mdExport({ ...buildQuery(), page: undefined, pagesize: undefined })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `买单记录_${new Date().toISOString().slice(0, 10)}.xlsx`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    ElMessage.error('导出失败')
  } finally {
    exporting.value = false
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

onMounted(() => {
  loadData()
  loadStores()
})
</script>

<template>
  <div class="maidan-log-page">
    <div class="page-card">
      <div class="card-title">买单记录</div>

      <!-- 筛选区 -->
      <div class="filter-bar">
        <el-input
          v-model="filters.ordernum"
          placeholder="订单号"
          clearable
          style="width: 190px"
          @keyup.enter="handleSearch"
        />
        <el-input
          v-model="filters.nickname"
          placeholder="会员昵称"
          clearable
          style="width: 150px"
          @keyup.enter="handleSearch"
        />
        <el-select v-model="filters.mdid" placeholder="门店" clearable style="width: 150px">
          <el-option v-for="s in stores" :key="s.id" :label="s.name" :value="s.id" />
        </el-select>
        <el-select v-model="filters.status" placeholder="状态" clearable style="width: 130px">
          <el-option label="已付款" :value="1" />
          <el-option label="已退款" :value="2" />
        </el-select>
        <el-date-picker
          v-model="filters.range"
          type="datetimerange"
          value-format="YYYY-MM-DD HH:mm:ss"
          start-placeholder="开始时间"
          end-placeholder="结束时间"
          style="width: 340px"
        />
        <el-button type="primary" @click="handleSearch">查询</el-button>
        <el-button @click="handleReset">重置</el-button>
        <el-button :loading="exporting" @click="handleExport">导出</el-button>
      </div>

      <!-- 列表 -->
      <el-table :data="list" v-loading="loading" border stripe style="width: 100%; margin-top: 12px">
        <el-table-column prop="ordernum" label="单号" min-width="160" show-overflow-tooltip />
        <el-table-column label="会员" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ row.nickname || '—' }}</template>
        </el-table-column>
        <el-table-column prop="money" label="消费金额" width="100" align="right">
          <template #default="{ row }">¥{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="优惠" width="150" align="right">
          <template #default="{ row }">
            <span v-if="Number(row.disprice) > 0">折{{ Number(row.disprice).toFixed(2) }} </span>
            <span v-if="Number(row.couponmoney) > 0">券{{ Number(row.couponmoney).toFixed(2) }} </span>
            <span v-if="Number(row.scoredk) > 0">分{{ Number(row.scoredk).toFixed(2) }}</span>
            <span v-if="Number(row.disprice) <= 0 && Number(row.couponmoney) <= 0 && Number(row.scoredk) <= 0">—</span>
          </template>
        </el-table-column>
        <el-table-column prop="paymoney" label="实付" width="90" align="right">
          <template #default="{ row }">¥{{ Number(row.paymoney).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="已退" width="90" align="right">
          <template #default="{ row }">
            <span :class="{ 'refund-text': Number(row.refund_money) > 0 }">
              ¥{{ Number(row.refund_money).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="paytype" label="支付方式" width="100" />
        <el-table-column prop="mdname" label="门店" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.mdname || '—' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag size="small" :type="statusText(row) === '已付款' ? 'success' : 'warning'">
              {{ statusText(row) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="收款时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column prop="un" label="操作员" width="100" show-overflow-tooltip />
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="Number(row.can_refund_money) > 0"
              link
              type="danger"
              @click="openRefund(row)"
            >
              退款
            </el-button>
            <span v-else class="no-op">—</span>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pagesize"
          :total="total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="handleSearch"
          @current-change="loadData"
        />
      </div>
    </div>

    <!-- 退款弹窗 -->
    <el-dialog v-model="refundVisible" title="买单退款" width="440px" :close-on-click-modal="false">
      <template v-if="refundTarget">
        <el-descriptions :column="1" size="small" border style="margin-bottom: 14px">
          <el-descriptions-item label="单号">{{ refundTarget.ordernum }}</el-descriptions-item>
          <el-descriptions-item label="实付金额">¥{{ Number(refundTarget.paymoney).toFixed(2) }}</el-descriptions-item>
          <el-descriptions-item label="可退金额">¥{{ Number(refundTarget.can_refund_money).toFixed(2) }}</el-descriptions-item>
        </el-descriptions>
        <el-form label-width="90px">
          <el-form-item label="退款金额">
            <el-input-number
              v-model="refundMoney"
              :min="0.01"
              :max="Number(refundTarget.can_refund_money)"
              :precision="2"
              :step="1"
              style="width: 180px"
            />
          </el-form-item>
          <el-form-item label="退款原因">
            <el-input
              v-model="refundReason"
              type="textarea"
              :rows="2"
              maxlength="200"
              show-word-limit
              placeholder="选填"
            />
          </el-form-item>
        </el-form>
        <div class="refund-note">退款金额原路退回会员余额；全额退款时自动返还抵扣积分并释放使用的优惠券。</div>
      </template>
      <template #footer>
        <el-button @click="refundVisible = false">取消</el-button>
        <el-button type="danger" :loading="refunding" @click="handleRefund">确认退款</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.maidan-log-page {
  padding: 12px;
}
.page-card {
  background: #fff;
  border-radius: 4px;
  padding: 16px 20px;
}
.card-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding-bottom: 12px;
  margin-bottom: 16px;
  border-bottom: 1px solid #ebeef5;
}
.filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
.refund-text {
  color: #f56c6c;
}
.no-op {
  color: #c0c4cc;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
.refund-note {
  font-size: 12px;
  color: #909399;
  line-height: 1.7;
}
</style>
