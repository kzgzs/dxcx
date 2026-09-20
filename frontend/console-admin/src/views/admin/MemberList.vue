<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowDown } from '@element-plus/icons-vue'
import {
  addMemberCommission,
  addMemberScore,
  deleteMembers,
  exportMembers,
  freezeMember,
  getMemberLevelList,
  getMemberList,
  lockMember,
  rechargeMember,
  type MemberRow,
} from '../../api/member'
import type { MemberLevelItem } from '../../api/types'
import { formatTime } from '../../utils/time'
import { assetUrl } from '../../api/upload'
import MemberDetail from './MemberDetail.vue'
import MemberEditPanel from './member/MemberEditPanel.vue'
import MemberShopOrdersPanel from './member/MemberShopOrdersPanel.vue'
import MemberCouponsPanel from './member/MemberCouponsPanel.vue'
import MemberFenxiaoPanel from './member/MemberFenxiaoPanel.vue'

type PanelKind = '' | 'edit' | 'orders' | 'coupons' | 'fenxiao'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<MemberRow[]>([])
const total = ref(0)
const selectedRows = ref<MemberRow[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  id: '' as number | '',
  keyword: '',
  pid: '' as number | '',
  levelid: '' as number | '',
  hascard: '' as '' | '1' | '2',
  field: 'id',
  order: 'desc' as 'asc' | 'desc',
  timeRange: null as [string, string] | null,
})

const levelOptions = ref<MemberLevelItem[]>([])

function buildParams() {
  return {
    page: query.page,
    pagesize: query.pagesize,
    id: query.id === '' ? undefined : Number(query.id),
    keyword: query.keyword || undefined,
    pid: query.pid === '' ? undefined : Number(query.pid),
    levelid: query.levelid === '' ? undefined : Number(query.levelid),
    hascard: query.hascard || undefined,
    field: query.field,
    order: query.order,
    start_time: query.timeRange?.[0] || undefined,
    end_time: query.timeRange?.[1] || undefined,
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getMemberList(buildParams())
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误由拦截器统一提示
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}
function handleReset() {
  query.id = ''
  query.keyword = ''
  query.pid = ''
  query.levelid = ''
  query.hascard = ''
  query.field = 'id'
  query.order = 'desc'
  query.timeRange = null
  query.page = 1
  fetchList()
}
function handlePageChange(p: number) {
  query.page = p
  fetchList()
}
function handleSizeChange(s: number) {
  query.pagesize = s
  query.page = 1
  fetchList()
}
function onSortChange({ prop, order }: { prop: string; order: string | null }) {
  if (!prop || !order) return
  query.field = prop
  query.order = order === 'ascending' ? 'asc' : 'desc'
  fetchList()
}
function onSelectionChange(rows: MemberRow[]) {
  selectedRows.value = rows
}

async function fetchLevels() {
  try {
    const data = await getMemberLevelList()
    levelOptions.value = data.list || []
  } catch {
    // 忽略
  }
}

// ============ 列设置 ============
const colVisible = reactive({
  parent: true,
  info: true,
  money: true,
  score: true,
  commission: true,
  createtime: true,
})

// ============ 平铺面板（编辑 / 商城订单 / 优惠券 / 分销订单） ============
const activePanel = ref<PanelKind>('')
const activeRow = ref<MemberRow | null>(null)

function openPanel(kind: PanelKind, row: MemberRow) {
  activeRow.value = row
  activePanel.value = kind
}
function closePanel() {
  activePanel.value = ''
  activeRow.value = null
  fetchList()
}

// ============ 详情抽屉 / 冻结 / 锁定 ============
const detailRef = ref<InstanceType<typeof MemberDetail>>()
function openDetail(row: MemberRow) {
  detailRef.value?.open(row.id)
}
async function handleFreeze(row: MemberRow) {
  const next = row.isfreeze === 1 ? '解冻' : '冻结'
  try {
    await ElMessageBox.confirm(`确定要${next}会员「${row.nickname || row.id}」吗？`, `${next}确认`, { type: 'warning' })
  } catch {
    return
  }
  const data = await freezeMember(row.id)
  row.isfreeze = data.isfreeze
  ElMessage.success(`已${next}`)
}
async function handleLock(row: MemberRow) {
  const next = row.islock === 1 ? '解锁' : '锁定'
  try {
    await ElMessageBox.confirm(`确定要${next}会员「${row.nickname || row.id}」吗？`, `${next}确认`, { type: 'warning' })
  } catch {
    return
  }
  const data = await lockMember(row.id)
  row.islock = data.islock
  ElMessage.success(`已${next}`)
}

// ============ 删除（单个 / 批量） ============
async function handleDelete(row: MemberRow) {
  try {
    await ElMessageBox.confirm(`确认删除会员「${row.nickname || row.id}」？删除后会员资料将不可恢复（订单与资金流水保留）。`, '删除确认', {
      type: 'warning',
      confirmButtonText: '删除',
      confirmButtonClass: 'el-button--danger',
    })
  } catch {
    return
  }
  const res = await deleteMembers([row.id])
  ElMessage.success(`已删除 ${res.count} 个会员`)
  fetchList()
}
async function handleBatchDelete() {
  const ids = selectedRows.value.map((r) => r.id)
  if (!ids.length) {
    ElMessage.warning('请先勾选会员')
    return
  }
  try {
    await ElMessageBox.confirm(`确认删除选中的 ${ids.length} 个会员？删除后会员资料将不可恢复（订单与资金流水保留）。`, '批量删除', {
      type: 'warning',
      confirmButtonText: '删除',
      confirmButtonClass: 'el-button--danger',
    })
  } catch {
    return
  }
  const res = await deleteMembers(ids)
  ElMessage.success(`已删除 ${res.count} 个会员`)
  fetchList()
}

// ============ 导出 ============
const exporting = ref(false)
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
  if (exporting.value) return
  exporting.value = true
  try {
    const blob = await exportMembers(buildParams() as Record<string, unknown>)
    downloadBlob(blob, `会员列表_${Date.now()}.xlsx`)
  } finally {
    exporting.value = false
  }
}

// ============ 充值小弹窗 ============
const rechargeVisible = ref(false)
const rechargeSubmitting = ref(false)
const rechargeTarget = ref<MemberRow | null>(null)
const rechargeForm = reactive({ money: undefined as number | undefined, paytype: 'wxpay', remark: '' })
const paytypeOptions = [
  { value: 'wxpay', label: '微信支付' },
  { value: 'cash', label: '现金支付' },
  { value: 'alipay', label: '支付宝支付' },
]
function openRecharge(row: MemberRow) {
  rechargeTarget.value = row
  rechargeForm.money = undefined
  rechargeForm.paytype = 'wxpay'
  rechargeForm.remark = ''
  rechargeVisible.value = true
}
async function handleRechargeSubmit() {
  const target = rechargeTarget.value
  const money = Number(rechargeForm.money)
  if (!target) return
  if (!Number.isFinite(money) || money === 0) {
    ElMessage.warning('请输入不为 0 的金额')
    return
  }
  rechargeSubmitting.value = true
  try {
    await rechargeMember({ id: target.id, money, paytype: rechargeForm.paytype, remark: rechargeForm.remark || undefined })
    ElMessage.success(money > 0 ? '充值成功' : '扣费成功')
    rechargeVisible.value = false
    fetchList()
  } catch {
    // 拦截器提示
  } finally {
    rechargeSubmitting.value = false
  }
}

// ============ 加积分小弹窗 ============
const scoreVisible = ref(false)
const scoreSubmitting = ref(false)
const scoreTarget = ref<MemberRow | null>(null)
const scoreForm = reactive({ score: undefined as number | undefined, remark: '' })
function openScore(row: MemberRow) {
  scoreTarget.value = row
  scoreForm.score = undefined
  scoreForm.remark = ''
  scoreVisible.value = true
}
async function handleScoreSubmit() {
  const target = scoreTarget.value
  const score = Number(scoreForm.score)
  if (!target) return
  if (!Number.isInteger(score) || score === 0) {
    ElMessage.warning('请输入不为 0 的整数积分')
    return
  }
  scoreSubmitting.value = true
  try {
    await addMemberScore({ id: target.id, score, remark: scoreForm.remark || undefined })
    ElMessage.success('调整成功')
    scoreVisible.value = false
    fetchList()
  } catch {
    // 拦截器提示
  } finally {
    scoreSubmitting.value = false
  }
}

// ============ 加佣金小弹窗 ============
const commissionVisible = ref(false)
const commissionSubmitting = ref(false)
const commissionTarget = ref<MemberRow | null>(null)
const commissionForm = reactive({ commission: undefined as number | undefined, remark: '' })
function openCommission(row: MemberRow) {
  commissionTarget.value = row
  commissionForm.commission = undefined
  commissionForm.remark = ''
  commissionVisible.value = true
}
async function handleCommissionSubmit() {
  const target = commissionTarget.value
  const commission = Number(commissionForm.commission)
  if (!target) return
  if (!Number.isFinite(commission) || commission === 0) {
    ElMessage.warning('请输入不为 0 的金额')
    return
  }
  commissionSubmitting.value = true
  try {
    await addMemberCommission({ id: target.id, commission, remark: commissionForm.remark || undefined })
    ElMessage.success(commission > 0 ? '加佣金成功' : '扣佣金成功')
    commissionVisible.value = false
    fetchList()
  } catch {
    // 拦截器提示
  } finally {
    commissionSubmitting.value = false
  }
}

// ============ 展示辅助 ============
function platformText(p?: string): string {
  if (!p) return '--'
  const m: Record<string, string> = {
    wx: '微信小程序', wechat: '微信小程序', h5: 'H5', wap: 'H5', api: 'H5',
    app: 'APP', admin: '后台下单', backend: '后台下单',
  }
  return m[p] || p
}

onMounted(() => {
  fetchLevels()
  fetchList()
})
</script>

<template>
  <div class="member-page">
    <!-- ============ 列表视图 ============ -->
    <template v-if="!activePanel">
      <!-- 搜索行 -->
      <el-card shadow="never" class="toolbar-card">
        <div class="search-line">
          <el-input v-model.number="query.id" placeholder="ID" clearable class="w80" @keyup.enter="handleSearch" />
          <el-input v-model="query.keyword" placeholder="昵称/姓名/手机号" clearable class="w170" @keyup.enter="handleSearch" />
          <el-input v-model.number="query.pid" placeholder="推荐人ID" clearable class="w100" @keyup.enter="handleSearch" />
          <el-select v-model="query.levelid" placeholder="等级" clearable class="w120">
            <el-option v-for="lv in levelOptions" :key="lv.id" :label="lv.name" :value="lv.id" />
          </el-select>
          <el-select v-model="query.hascard" placeholder="是否领卡" clearable class="w110">
            <el-option label="已领卡" value="1" />
            <el-option label="未领卡" value="2" />
          </el-select>
          <el-date-picker
            v-model="query.timeRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="-"
            start-placeholder="加入开始"
            end-placeholder="加入结束"
            class="date-range"
          />
          <el-button type="success" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </div>
      </el-card>

      <!-- 工具栏行 -->
      <el-card shadow="never" class="toolbar-card">
        <div class="tools-line">
          <div class="tools-left">
            <el-button type="danger" @click="handleBatchDelete">删除</el-button>
            <el-button :loading="exporting" @click="handleExport">导出</el-button>
          </div>
          <el-popover placement="bottom-end" :width="150" trigger="click">
            <template #reference>
              <el-button circle class="col-set-btn" title="列设置">
                <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M930.133333 490.666667h-72.533333a42.666667 42.666667 0 0 1 0-85.333334h72.533333a42.666667 42.666667 0 0 1 0 85.333334zM810.666667 768a42.666667 42.666667 0 0 0-85.333334 0v42.666667a42.666667 42.666667 0 0 0 85.333334 0v-42.666667z m0-554.666667a42.666667 42.666667 0 0 0-85.333334 0v192a42.666667 42.666667 0 0 0 85.333334 0V213.333333zM490.666667 857.6a42.666667 42.666667 0 0 0-85.333334 0v42.666667a42.666667 42.666667 0 0 0 85.333334 0v-42.666667z m0-234.666667a42.666667 42.666667 0 0 0-85.333334 0v277.333334a42.666667 42.666667 0 0 0 85.333334 0V622.933333z m0-320a42.666667 42.666667 0 0 0-85.333334 0v362.666667a42.666667 42.666667 0 0 0 85.333334 0V302.933333z m0-234.666666a42.666667 42.666667 0 0 0-85.333334 0v277.333333a42.666667 42.666667 0 0 0 85.333334 0V68.266667zM213.333333 490.666667a42.666667 42.666667 0 0 0 0-85.333334H93.866667a42.666667 42.666667 0 0 0 0 85.333334h119.466666z m85.333334 277.333333a42.666667 42.666667 0 0 0-85.333334 0v42.666667a42.666667 42.666667 0 0 0 85.333334 0v-42.666667z m0-554.666667a42.666667 42.666667 0 0 0-85.333334 0v192a42.666667 42.666667 0 0 0 85.333334 0V213.333333z" /></svg>
              </el-button>
            </template>
            <div class="col-set-pop">
              <el-checkbox v-model="colVisible.parent">推荐人</el-checkbox>
              <el-checkbox v-model="colVisible.info">其他信息</el-checkbox>
              <el-checkbox v-model="colVisible.money">余额</el-checkbox>
              <el-checkbox v-model="colVisible.score">积分</el-checkbox>
              <el-checkbox v-model="colVisible.commission">佣金</el-checkbox>
              <el-checkbox v-model="colVisible.createtime">加入时间</el-checkbox>
            </div>
          </el-popover>
        </div>
      </el-card>

      <!-- 表格 -->
      <el-card shadow="never" class="table-card">
        <el-table
          v-loading="loading"
          :data="tableData"
          border
          size="small"
          row-key="id"
          @selection-change="onSelectionChange"
          @sort-change="onSortChange"
        >
          <el-table-column type="selection" width="40" align="center" />
          <el-table-column prop="id" label="ID" width="70" sortable="custom" align="center" />
          <el-table-column v-if="colVisible.parent" label="推荐人" width="100" align="center">
            <template #default="{ row }">
              <span v-if="row.pid">{{ row.parent_nickname || row.pid }}</span>
              <span v-else class="muted">无</span>
            </template>
          </el-table-column>
          <el-table-column label="头像/昵称" min-width="150">
            <template #default="{ row }">
              <div class="member-cell">
                <el-avatar :size="34" :src="row.headimg ? assetUrl(row.headimg) : undefined">{{ (row.nickname || '会').slice(0, 1) }}</el-avatar>
                <div class="member-meta">
                  <el-button link type="primary" class="name-link" @click="openDetail(row)">
                    {{ row.nickname || `会员${row.id}` }}
                  </el-button>
                  <div class="state-tags">
                    <el-tag v-if="row.isfreeze === 1" type="danger" size="small" disable-transitions>冻结</el-tag>
                    <el-tag v-if="row.islock === 1" type="info" size="small" disable-transitions>锁定</el-tag>
                  </div>
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column v-if="colVisible.info" label="其他信息" min-width="220">
            <template #default="{ row }">
              <div class="info-lines">
                <div><span class="il-label">等级：</span>{{ row.level_name || '-' }}</div>
                <div><span class="il-label">来源：</span>{{ platformText(row.platform) }}</div>
                <div>
                  <span class="il-label">优惠券：</span>
                  <el-button link type="primary" class="il-link" @click="openPanel('coupons', row)">{{ row.coupon_count }}</el-button>
                </div>
                <div>
                  <span class="il-label">会员卡：</span>
                  <span :class="row.has_card ? 'text-success' : 'muted'">{{ row.has_card ? '已领取' : '未领取' }}</span>
                </div>
                <div><span class="il-label">姓名：</span>{{ row.realname || '-' }}</div>
                <div><span class="il-label">电话：</span>{{ row.tel || '-' }}</div>
                <div>
                  <span class="il-label">公众号：</span>
                  <span :class="row.subscribe === 1 ? 'text-success' : 'muted'">{{ row.subscribe === 1 ? '已关注' : '未关注' }}</span>
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column v-if="colVisible.money" prop="money" label="余额" width="90" sortable="custom" align="right">
            <template #default="{ row }">{{ Number(row.money).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column v-if="colVisible.score" prop="score" label="积分" width="80" sortable="custom" align="right" />
          <el-table-column v-if="colVisible.commission" prop="commission" label="佣金" width="90" sortable="custom" align="right">
            <template #default="{ row }">{{ Number(row.commission).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column v-if="colVisible.createtime" prop="createtime" label="加入时间" width="155" sortable="custom">
            <template #default="{ row }">{{ row.createtime ? formatTime(row.createtime) : '-' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="282">
            <template #default="{ row }">
              <div class="op-rows">
                <div class="op-row">
                  <el-button link type="primary" size="small" @click="openRecharge(row)">充值</el-button>
                  <el-button link type="primary" size="small" @click="openScore(row)">加积分</el-button>
                  <el-button link type="primary" size="small" @click="openCommission(row)">加佣金</el-button>
                  <el-button link type="primary" size="small" @click="openPanel('orders', row)">商城订单</el-button>
                  <el-button link type="primary" size="small" @click="openPanel('edit', row)">编辑</el-button>
                  <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
                </div>
                <div class="op-row">
                  <el-button link type="primary" size="small" @click="openPanel('coupons', row)">优惠券</el-button>
                  <el-button link type="primary" size="small" @click="openPanel('fenxiao', row)">分销订单</el-button>
                  <el-dropdown trigger="click" placement="bottom-start" @command="(cmd: string) => cmd === 'freeze' ? handleFreeze(row) : cmd === 'lock' ? handleLock(row) : openDetail(row)">
                    <el-button link type="primary" size="small">更多<el-icon class="more-arrow"><ArrowDown /></el-icon></el-button>
                    <template #dropdown>
                      <el-dropdown-menu>
                        <el-dropdown-item command="detail">详情</el-dropdown-item>
                        <el-dropdown-item command="freeze">{{ row.isfreeze === 1 ? '解冻' : '冻结' }}</el-dropdown-item>
                        <el-dropdown-item command="lock">{{ row.islock === 1 ? '解锁' : '锁定' }}</el-dropdown-item>
                      </el-dropdown-menu>
                    </template>
                  </el-dropdown>
                </div>
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
          :page-sizes="[10, 20, 50]"
          @current-change="handlePageChange"
          @size-change="handleSizeChange"
        />
      </el-card>
    </template>

    <!-- ============ 平铺面板 ============ -->
    <MemberEditPanel v-else-if="activePanel === 'edit' && activeRow" :key="'edit-' + activeRow.id" :id="activeRow.id" @close="closePanel" @saved="closePanel" />
    <MemberShopOrdersPanel v-else-if="activePanel === 'orders' && activeRow" :key="'orders-' + activeRow.id" :mid="activeRow.id" @close="closePanel" />
    <MemberCouponsPanel v-else-if="activePanel === 'coupons' && activeRow" :key="'coupons-' + activeRow.id" :mid="activeRow.id" :member="{ headimg: activeRow.headimg, nickname: activeRow.nickname }" @close="closePanel" />
    <MemberFenxiaoPanel v-else-if="activePanel === 'fenxiao' && activeRow" :key="'fenxiao-' + activeRow.id" :mid="activeRow.id" @close="closePanel" />

    <!-- ============ 充值小弹窗 ============ -->
    <el-dialog v-model="rechargeVisible" title="余额充值" width="460px">
      <el-form label-width="80px" label-position="right">
        <el-form-item label="充值方式">
          <el-select v-model="rechargeForm.paytype" style="width: 220px">
            <el-option v-for="p in paytypeOptions" :key="p.value" :label="p.label" :value="p.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="充值金额" required>
          <el-input-number v-model="rechargeForm.money" :precision="2" :step="10" controls-position="right" style="width: 220px" />
          <span class="field-hint">输入负值表示扣除金额</span>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="rechargeForm.remark" type="textarea" :rows="2" maxlength="255" placeholder="选填" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="rechargeVisible = false">取消</el-button>
        <el-button type="primary" :loading="rechargeSubmitting" @click="handleRechargeSubmit">确定充值</el-button>
      </template>
    </el-dialog>

    <!-- ============ 加积分小弹窗（无标题，右上 X） ============ -->
    <el-dialog v-model="scoreVisible" width="420px">
      <el-form label-width="80px" label-position="right">
        <el-form-item label="增加数量" required>
          <el-input-number v-model="scoreForm.score" :precision="0" :step="10" controls-position="right" style="width: 220px" />
          <span class="field-hint">输入负值表示扣除积分</span>
        </el-form-item>
        <el-form-item label="备注信息">
          <el-input v-model="scoreForm.remark" type="textarea" :rows="2" maxlength="255" placeholder="选填" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="scoreVisible = false">取消</el-button>
        <el-button type="primary" :loading="scoreSubmitting" @click="handleScoreSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- ============ 加佣金小弹窗（无标题，右上 X） ============ -->
    <el-dialog v-model="commissionVisible" width="420px">
      <el-form label-width="80px" label-position="right">
        <el-form-item label="增加金额" required>
          <el-input-number v-model="commissionForm.commission" :precision="2" :step="10" controls-position="right" style="width: 220px" />
          <span class="field-hint">输入负值表示扣除佣金</span>
        </el-form-item>
        <el-form-item label="备注信息">
          <el-input v-model="commissionForm.remark" type="textarea" :rows="2" maxlength="255" placeholder="选填" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="commissionVisible = false">取消</el-button>
        <el-button type="primary" :loading="commissionSubmitting" @click="handleCommissionSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- 会员详情抽屉 -->
    <MemberDetail ref="detailRef" />
  </div>
</template>

<style scoped>
.member-page {
  padding: 0;
}
.toolbar-card {
  margin-bottom: 6px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 8px 10px;
}
.search-line {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 6px;
}
.tools-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.tools-left {
  display: flex;
  align-items: center;
  gap: 6px;
}
.w80 { width: 80px; }
.w100 { width: 100px; }
.w110 { width: 110px; }
.w120 { width: 120px; }
.w170 { width: 170px; }
.date-range { width: 230px; }
.col-set-btn { padding: 6px; }
.col-set-pop {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.table-card :deep(.el-card__body) {
  padding: 8px;
}
.member-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.member-meta {
  line-height: 1.4;
}
.name-link {
  padding: 0;
  height: auto;
  font-size: 13px;
}
.state-tags {
  display: flex;
  gap: 4px;
  margin-top: 2px;
}
.info-lines {
  display: flex;
  flex-direction: column;
  line-height: 1.65;
  font-size: 12px;
}
.il-label {
  color: #909399;
}
.il-link {
  padding: 0;
  height: auto;
  min-height: 0;
  font-size: 12px;
}
.text-success {
  color: #19be6b;
}
.muted {
  color: #c0c4cc;
}
.op-rows {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.op-row {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 2px;
  white-space: nowrap;
}
.op-row :deep(.el-button) {
  margin: 0;
  padding: 0 2px;
  height: auto;
  font-size: 12px;
}
.more-arrow {
  margin-left: 2px;
  font-size: 12px;
}
.field-hint {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
.pagination {
  margin-top: 8px;
  justify-content: flex-end;
}
</style>
