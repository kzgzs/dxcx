<template>
  <div class="refunds-page">
    <!-- 状态 Tab -->
    <div class="status-tabs">
      <div
        v-for="t in tabs"
        :key="String(t.value)"
        class="status-tab"
        :class="{ active: query.status === t.value }"
        @click="switchTab(t.value)"
      >
        {{ t.label }}
        <span class="tab-badge">({{ tabCount(t.value) }})</span>
      </div>
    </div>

    <!-- 复合筛选区（对齐参考系统：退款单号/退货快递单号/订单号/手机号/申请时间/状态） -->
    <el-card shadow="never" class="filter-card">
      <el-form inline @submit.prevent>
        <el-form-item>
          <el-input
            v-model="query.refund_sn"
            placeholder="退款单号"
            clearable
            class="f-input"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-input
            v-model="query.express_no"
            placeholder="退货快递单号"
            clearable
            class="f-input"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-input
            v-model="query.ordernum"
            placeholder="订单号"
            clearable
            class="f-input"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-input
            v-model="query.tel"
            placeholder="手机号"
            clearable
            class="f-input-sm"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <span class="f-label">申请时间</span>
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            range-separator="-"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            class="date-range"
          />
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="状态" clearable class="f-status" @change="onStatusSelect">
            <el-option v-for="o in statusOptions" :key="String(o.v)" :label="o.l" :value="o.v" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="success" @click="handleSearch">搜索</el-button>
          <el-button @click="resetFilter">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 退款表格（9 列，对齐参考系统） -->
    <el-table
      v-loading="loading"
      :data="list"
      border
      stripe
      size="small"
      class="refund-table"
      @sort-change="onSortChange"
    >
      <el-table-column label="ID" prop="id" width="64" sortable="custom" />

      <el-table-column label="商品信息" min-width="230">
        <template #default="{ row }">
          <template v-if="row.goods && row.goods.length">
            <div v-for="(g, i) in row.goods" :key="i" class="goods-line">
              <el-image :src="assetUrl(g.pic)" class="goods-img" fit="cover">
                <template #error>
                  <div class="img-fallback">无图</div>
                </template>
              </el-image>
              <div class="goods-meta">
                <div class="goods-name">{{ g.name }}</div>
                <div class="goods-sub">
                  <span v-if="g.sku" class="goods-sku">{{ g.sku }}</span>
                  <span class="goods-price">¥{{ fmtMoney(g.price) }} ×{{ g.num }}</span>
                </div>
              </div>
            </div>
          </template>
          <span v-else class="gray">--</span>
        </template>
      </el-table-column>

      <el-table-column label="退款单号/申请时间" prop="refund_ordernum" width="168" sortable="custom">
        <template #default="{ row }">
          <div class="mono refund-sn">{{ row.refund_sn || '--' }}</div>
          <div class="green apply-time">{{ row.apply_time || '--' }}</div>
        </template>
      </el-table-column>

      <el-table-column label="订单号" prop="ordernum" width="150" sortable="custom">
        <template #default="{ row }">
          <span class="mono">{{ row.ordernum || '--' }}</span>
        </template>
      </el-table-column>

      <el-table-column label="类型" width="92">
        <template #default="{ row }">
          {{ row.refund_type_label || '退款' }}
        </template>
      </el-table-column>

      <el-table-column label="退款金额" width="100">
        <template #default="{ row }">
          <span class="refund-money">¥{{ fmtMoney(row.refund_amount) }}</span>
        </template>
      </el-table-column>

      <el-table-column label="退货快递信息" width="180">
        <template #default="{ row }">
          <div v-if="row.express_company || row.express_no">
            <div>{{ row.express_company || '--' }}</div>
            <div class="mono gray express-no">{{ row.express_no }}</div>
            <el-button
              v-if="row.express_no"
              link
              type="primary"
              size="small"
              @click="viewExpress(row)"
            >
              查物流
            </el-button>
          </div>
          <span v-else class="gray">--</span>
        </template>
      </el-table-column>

      <el-table-column label="状态" width="96">
        <template #default="{ row }">
          <span class="status-text" :style="{ color: statusMeta(row.status).c }">
            {{ statusMeta(row.status).t }}
          </span>
        </template>
      </el-table-column>

      <el-table-column label="操作" width="170" fixed="right">
        <template #default="{ row }">
          <div class="op-col">
            <el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
            <el-button
              v-if="row.status === 0"
              link
              type="warning"
              size="small"
              @click="openAudit(row)"
            >
              {{ row.refund_type === 'refund' ? '退款审核' : '退货退款审核' }}
            </el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </div>
        </template>
      </el-table-column>

      <template #empty>
        <el-empty description="暂无退款单" />
      </template>
    </el-table>

    <div class="pager">
      <el-pagination
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="loadData"
        @size-change="onSizeChange"
      />
    </div>

    <!-- 退款详情抽屉 -->
    <el-drawer v-model="detailVisible" title="退款详情" size="500px">
      <div v-loading="detailLoading" class="detail-wrap">
        <template v-if="detailData">
          <div class="d-status">
            <span :style="{ color: statusMeta(detailData.status).c }">
              {{ statusMeta(detailData.status).t }}
            </span>
            <span class="mono">{{ detailData.refund_sn }}</span>
          </div>

          <!-- 商品 -->
          <div class="d-section">
            <div class="d-title">商品信息</div>
            <div v-for="(g, i) in detailData.goods" :key="i" class="d-goods">
              <el-image :src="assetUrl(g.pic)" class="d-goods-img" fit="cover">
                <template #error><div class="img-fallback">无图</div></template>
              </el-image>
              <div class="d-goods-meta">
                <div>{{ g.name }}</div>
                <div class="gray">
                  <span v-if="g.sku">{{ g.sku }}　</span>¥{{ fmtMoney(g.price) }} ×{{ g.num }}
                </div>
              </div>
            </div>
          </div>

          <!-- 退款信息 -->
          <div class="d-section">
            <div class="d-title">退款信息</div>
            <div class="d-row"><span>类型</span><span>{{ detailData.refund_type_label }}</span></div>
            <div class="d-row"><span>退款编号</span><span class="mono">{{ detailData.refund_sn }}</span></div>
            <div class="d-row"><span>退款金额</span><span class="refund-money">¥{{ fmtMoney(detailData.refund_amount) }}</span></div>
            <div class="d-row"><span>申请时间</span><span>{{ detailData.apply_time || '--' }}</span></div>
            <div class="d-row">
              <span>退款状态</span>
              <span :style="{ color: statusMeta(detailData.status).c }">{{ statusMeta(detailData.status).t }}</span>
            </div>
            <div class="d-row"><span>退款原因</span><span>{{ detailData.reason || '--' }}</span></div>
            <div v-if="detailData.refund_pics && detailData.refund_pics.length" class="d-row d-row-pics">
              <span>凭证图片</span>
              <span class="d-pics">
                <el-image
                  v-for="(p, i) in detailData.refund_pics"
                  :key="i"
                  :src="assetUrl(p)"
                  :preview-src-list="(detailData.refund_pics || []).map(assetUrl)"
                  :initial-index="i"
                  class="d-pic"
                  fit="cover"
                />
              </span>
            </div>
            <div v-if="detailData.express_company || detailData.express_no" class="d-row">
              <span>退货快递</span>
              <span>{{ detailData.express_company || '--' }} {{ detailData.express_no }}</span>
            </div>
            <div v-if="detailData.audit_remark" class="d-row">
              <span>审核备注</span><span>{{ detailData.audit_remark }}</span>
            </div>
          </div>

          <!-- 会员 -->
          <div v-if="detailData.member" class="d-section">
            <div class="d-title">下单人</div>
            <div class="d-member">
              <el-image
                v-if="detailData.member.headimg"
                :src="assetUrl(detailData.member.headimg)"
                class="d-avatar"
                fit="cover"
              >
                <template #error><div class="d-avatar-fallback">会员</div></template>
              </el-image>
              <div v-else class="d-avatar-fallback">会员</div>
              <div>
                <div>{{ detailData.member.nickname || '--' }}（ID:{{ detailData.member.id }}）</div>
                <div class="gray">{{ detailData.member.tel || detailData.member_phone || '--' }}</div>
              </div>
            </div>
          </div>

          <!-- 寄回地址（退货退款） -->
          <div v-if="detailData.return && detailData.return.address" class="d-section">
            <div class="d-title">寄回地址</div>
            <div class="d-row">
              <span>联系人</span><span>{{ detailData.return.name }} {{ detailData.return.tel }}</span>
            </div>
            <div class="d-row"><span>地址</span><span>{{ detailData.return.address }}</span></div>
          </div>

          <!-- 原订单 -->
          <div v-if="detailData.order" class="d-section">
            <div class="d-title">原订单</div>
            <div class="d-row"><span>订单编号</span><span class="mono">{{ detailData.order.order_sn }}</span></div>
            <div class="d-row"><span>下单时间</span><span>{{ detailData.order.create_time || '--' }}</span></div>
            <div class="d-row"><span>支付时间</span><span>{{ detailData.order.pay_time || '--' }}</span></div>
            <div class="d-row"><span>支付方式</span><span>{{ detailData.order.pay_type || '--' }}</span></div>
            <div class="d-row"><span>商品金额</span><span>¥{{ fmtMoney(detailData.order.product_amount) }}</span></div>
            <div class="d-row"><span>实付款</span><span>¥{{ fmtMoney(detailData.order.pay_amount) }}</span></div>
            <div class="d-row"><span>订单状态</span><span>{{ detailData.order.status_text }}</span></div>
            <div class="d-row"><span>收货人</span>
              <span>{{ detailData.order.address?.name }} {{ detailData.order.address?.phone }}</span>
            </div>
            <div class="d-row"><span>收货地址</span><span>{{ detailData.order.address?.detail || '--' }}</span></div>
          </div>
        </template>
      </div>
      <template v-if="detailData && detailData.status === 0" #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
        <el-button type="primary" @click="openAudit(detailData)">
          {{ detailData.refund_type === 'refund' ? '退款审核' : '退货退款审核' }}
        </el-button>
      </template>
    </el-drawer>

    <!-- 退款审核弹窗 -->
    <el-dialog v-model="auditVisible" title="退款审核" width="420px" append-to-body>
      <div v-if="auditRow" class="audit-form">
        <div class="a-row"><span class="a-label">类型</span><span>{{ auditRow.refund_type_label }}</span></div>
        <div class="a-row">
          <span class="a-label">申请退款金额</span>
          <span class="refund-money">¥{{ fmtMoney(auditRow.refund_amount) }}</span>
        </div>
        <div class="a-row"><span class="a-label">申请退款原因</span><span>{{ auditRow.reason || '--' }}</span></div>
        <div v-if="auditRow.refund_pics && auditRow.refund_pics.length" class="a-row">
          <span class="a-label">图片</span>
          <span class="a-pics">
            <el-image
              v-for="(p, i) in auditRow.refund_pics"
              :key="i"
              :src="assetUrl(p)"
              :preview-src-list="(auditRow.refund_pics || []).map(assetUrl)"
              :initial-index="i"
              class="a-pic"
              fit="cover"
            />
          </span>
        </div>
        <div class="a-row"><span class="a-label">申请退款时间</span><span>{{ auditRow.apply_time || '--' }}</span></div>
        <div class="a-row a-column">
          <span class="a-label">审核备注</span>
          <el-input v-model="auditRemark" type="textarea" :rows="3" maxlength="200" show-word-limit placeholder="请输入审核备注（选填）" />
        </div>
      </div>
      <template #footer>
        <el-button @click="auditVisible = false">取消</el-button>
        <el-button type="danger" :loading="auditing" @click="submitAudit(2)">驳回退款申请</el-button>
        <el-button type="primary" :loading="auditing" @click="submitAudit(1)">{{ passText }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  auditRefund,
  deleteRefund,
  getRefundDetail,
  getRefundList,
  getRefundStat,
} from '../../api/refund'
import { assetUrl } from '../../api/upload'
import type { RefundDetail, RefundItem, RefundStat } from '../../api/types'

type Row = RefundItem
type StatusValue = number | ''
type SortField = 'id' | 'refund_ordernum' | 'ordernum'

const loading = ref(false)
const list = ref<Row[]>([])
const total = ref(0)
const stat = ref<RefundStat | null>(null)

const query = reactive({
  page: 1,
  pagesize: 10,
  status: '' as StatusValue,
  refund_sn: '',
  express_no: '',
  ordernum: '',
  tel: '',
  sort_field: 'id' as SortField,
  sort_order: 'desc' as 'asc' | 'desc',
})
const dateRange = ref<[string, string] | null>(null)

// 本项目退款契约状态：0 待审核 / 1 已退款 / 2 已驳回
const tabs: { label: string; value: StatusValue }[] = [
  { label: '全部', value: '' },
  { label: '待审核', value: 0 },
  { label: '已退款', value: 1 },
  { label: '驳回', value: 2 },
]
const statusOptions: { l: string; v: StatusValue }[] = [
  { l: '待审核', v: 0 },
  { l: '已退款', v: 1 },
  { l: '驳回', v: 2 },
]

function tabCount(v: StatusValue): number {
  const s = stat.value
  if (!s) return 0
  if (v === 0) return s.pending
  if (v === 1) return s.done
  if (v === 2) return s.rejected
  return s.pending + s.done + s.rejected
}

async function loadData() {
  loading.value = true
  try {
    const params: Record<string, unknown> = { ...query }
    if (dateRange.value?.[0]) params.start = dateRange.value[0]
    if (dateRange.value?.[1]) params.end = dateRange.value[1]
    const res = await getRefundList(params as never)
    list.value = res.list || []
    total.value = res.total || 0
  } catch (e) {
    ElMessage.error((e as Error)?.message || '加载失败')
  } finally {
    loading.value = false
  }
}
async function loadStat() {
  try {
    stat.value = await getRefundStat()
  } catch {
    /* 统计不阻塞列表 */
  }
}

function switchTab(v: StatusValue) {
  query.status = v
  query.page = 1
  loadData()
}
function onStatusSelect() {
  query.page = 1
  loadData()
}
function handleSearch() {
  query.page = 1
  loadData()
}
function resetFilter() {
  query.refund_sn = ''
  query.express_no = ''
  query.ordernum = ''
  query.tel = ''
  query.status = ''
  query.sort_field = 'id'
  query.sort_order = 'desc'
  dateRange.value = null
  query.page = 1
  loadData()
}
function onSizeChange(ps: number) {
  query.pagesize = ps
  query.page = 1
  loadData()
}
function onSortChange({ prop, order }: { prop: string; order: 'ascending' | 'descending' | null }) {
  const allow: SortField[] = ['id', 'refund_ordernum', 'ordernum']
  if (!order || !allow.includes(prop as SortField)) {
    query.sort_field = 'id'
    query.sort_order = 'desc'
  } else {
    query.sort_field = prop as SortField
    query.sort_order = order === 'ascending' ? 'asc' : 'desc'
  }
  loadData()
}

// ---------- 展示辅助 ----------
function fmtMoney(n: unknown): string {
  return Number(n || 0).toFixed(2)
}
function statusMeta(s: number): { t: string; c: string } {
  return (
    {
      0: { t: '退款待审核', c: '#f56c6c' },
      1: { t: '已退款', c: '#19be6b' },
      2: { t: '退款驳回', c: '#909399' },
    } as Record<number, { t: string; c: string }>
  )[s] || { t: '未知', c: '#909399' }
}

// ---------- 详情 ----------
const detailVisible = ref(false)
const detailLoading = ref(false)
const detailData = ref<RefundDetail | null>(null)

async function openDetail(row: Row) {
  detailVisible.value = true
  detailLoading.value = true
  detailData.value = null
  try {
    detailData.value = await getRefundDetail(row.id)
  } catch (e) {
    ElMessage.error((e as Error)?.message || '详情加载失败')
  } finally {
    detailLoading.value = false
  }
}

// ---------- 审核 ----------
const auditVisible = ref(false)
const auditing = ref(false)
const auditRow = ref<RefundItem | null>(null)
const auditRemark = ref('')
const passText = computed(() => (auditRow.value?.refund_type === 'refund' ? '同意并退款' : '同意申请'))

function openAudit(row: Row | RefundDetail) {
  auditRow.value = row
  auditRemark.value = row.audit_remark || ''
  auditVisible.value = true
}
async function submitAudit(pass: number) {
  if (!auditRow.value) return
  auditing.value = true
  try {
    await auditRefund({ id: auditRow.value.id, pass, remark: auditRemark.value.trim() })
    ElMessage.success(pass === 1 ? '退款成功' : '已驳回')
    auditVisible.value = false
    detailVisible.value = false
    loadData()
    loadStat()
  } catch (e) {
    ElMessage.error((e as Error)?.message || '操作失败')
  } finally {
    auditing.value = false
  }
}

// ---------- 删除 ----------
async function handleDelete(row: Row) {
  try {
    await ElMessageBox.confirm(
      `确定删除退款单 ${row.refund_sn} 吗？删除后不可恢复。`,
      '删除确认',
      { type: 'warning', confirmButtonText: '确定删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  try {
    await deleteRefund(row.id)
    ElMessage.success('删除成功')
    loadData()
    loadStat()
  } catch (e) {
    ElMessage.error((e as Error)?.message || '删除失败')
  }
}

// ---------- 查物流（本项目未接第三方，仅展示已记录轨迹） ----------
async function viewExpress(row: Row) {
  try {
    const detail = await getRefundDetail(row.id)
    if (detail.express_content) {
      ElMessageBox.alert(detail.express_content, '物流轨迹', { confirmButtonText: '关闭' })
    } else {
      ElMessage.info('暂无物流轨迹')
    }
  } catch (e) {
    ElMessage.error((e as Error)?.message || '查询失败')
  }
}

onMounted(() => {
  loadData()
  loadStat()
})
</script>

<style scoped>
.refunds-page {
  padding: 0;
}
.status-tabs {
  display: flex;
  gap: 28px;
  background: #fff;
  padding: 0 16px;
  border-radius: 4px;
  border-bottom: 1px solid #ebeef5;
  margin-bottom: 3px;
}
.status-tab {
  position: relative;
  padding: 13px 2px;
  font-size: 14px;
  color: #606266;
  cursor: pointer;
  user-select: none;
}
.status-tab:hover {
  color: #409eff;
}
.status-tab.active {
  color: #303133;
  font-weight: 600;
}
.status-tab.active::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: -1px;
  height: 2px;
  background: #409eff;
  border-radius: 2px;
}
.tab-badge {
  font-size: 12px;
  color: #909399;
  font-weight: 400;
}
.filter-card {
  margin-bottom: 10px;
}
.filter-card :deep(.el-card__body) {
  padding: 8px 10px;
}
.filter-card :deep(.el-form) {
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
  overflow-x: auto;
}
.filter-card :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
  flex: 0 0 auto;
  min-width: max-content;
}
.f-input {
  width: 140px;
}
.f-input-sm {
  width: 116px;
}
.f-status {
  width: 92px;
}
.f-label {
  font-size: 13px;
  color: #606266;
  margin-right: 6px;
}
.filter-card :deep(.el-date-editor.date-range) {
  width: 200px !important;
}
.refund-table {
  width: 100%;
  font-size: 12px;
}
.refund-table :deep(.el-table__cell) {
  padding: 6px 0;
}
.mono {
  font-family: Consolas, Menlo, monospace;
}
.gray {
  color: #909399;
  font-size: 12px;
}
.goods-line {
  display: flex;
  gap: 8px;
  padding: 3px 0;
}
.goods-img {
  width: 40px;
  height: 40px;
  border-radius: 4px;
  border: 1px solid #f0f0f0;
  flex: none;
}
.img-fallback {
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f5f7fa;
  color: #c0c4cc;
  font-size: 11px;
  border-radius: 4px;
}
.goods-meta {
  min-width: 0;
  flex: 1;
}
.goods-name {
  font-size: 12px;
  color: #303133;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.goods-sub {
  margin-top: 2px;
  font-size: 11px;
  color: #909399;
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.refund-sn {
  font-size: 12px;
  color: #303133;
  word-break: break-all;
}
.green {
  color: #19be6b;
  font-size: 11px;
  margin-top: 2px;
}
.express-no {
  margin-top: 2px;
  word-break: break-all;
}
.refund-money {
  color: #f56c6c;
  font-weight: 600;
}
.status-text {
  font-size: 12px;
  font-weight: 600;
}
.op-col {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}
.op-col :deep(.el-button) {
  margin: 0;
  padding: 0;
  height: 20px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
/* 详情抽屉 */
.detail-wrap {
  padding: 0 16px 24px;
}
.d-status {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 15px;
  font-weight: 600;
  padding-bottom: 12px;
  border-bottom: 1px solid #ebeef5;
  margin-bottom: 12px;
}
.d-section {
  margin-bottom: 16px;
}
.d-title {
  font-weight: 600;
  font-size: 13px;
  margin-bottom: 8px;
  padding-left: 8px;
  border-left: 3px solid #409eff;
}
.d-goods {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 0;
}
.d-goods-img {
  width: 44px;
  height: 44px;
  border-radius: 4px;
  border: 1px solid #f0f0f0;
}
.d-goods-meta {
  flex: 1;
  min-width: 0;
  font-size: 12px;
}
.d-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 12px;
  line-height: 2;
}
.d-row > span:first-child {
  color: #909399;
  flex: none;
}
.d-row > span:last-child {
  text-align: right;
  word-break: break-all;
}
.d-row-pics {
  align-items: flex-start;
}
.d-pics,
.a-pics {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  justify-content: flex-end;
}
.d-pic,
.a-pic {
  width: 48px;
  height: 48px;
  border-radius: 4px;
  border: 1px solid #f0f0f0;
  cursor: pointer;
}
.d-member {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 12px;
}
.d-avatar,
.d-avatar-fallback {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  flex: none;
}
.d-avatar-fallback {
  background: #dfe7f5;
  color: #5a7bb0;
  font-size: 11px;
  display: flex;
  align-items: center;
  justify-content: center;
}
/* 审核弹窗 */
.audit-form {
  font-size: 13px;
}
.a-row {
  display: flex;
  align-items: flex-start;
  margin-bottom: 12px;
}
.a-label {
  width: 96px;
  flex: none;
  color: #909399;
  line-height: 32px;
}
.a-column {
  flex-direction: column;
}
.a-column .a-label {
  line-height: 1.6;
  margin-bottom: 6px;
}
</style>
