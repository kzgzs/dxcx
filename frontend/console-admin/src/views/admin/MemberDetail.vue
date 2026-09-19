<script setup lang="ts">
import { reactive, ref } from 'vue'
import {
  getMemberAddress,
  getMemberDetail,
  getMemberMoneyLog,
  getMemberOrders,
  getMemberScoreLog,
} from '../../api/member'
import type {
  MemberAddressItem,
  MemberDetail,
  MemberMoneyLogItem,
  MemberOrderItem,
  MemberScoreLogItem,
} from '../../api/types'
import { formatTime } from '../../utils/time'

// ============ 抽屉开关 ============
const visible = ref(false)
const activeTab = ref('base')
const detail = ref<MemberDetail | null>(null)
const detailLoading = ref(false)
const currentMid = ref(0)

// 订单状态文案
function orderStatusText(status: number): string {
  const map: Record<number, string> = {
    0: '待付款',
    1: '待发货',
    2: '待收货',
    3: '已完成',
    4: '已取消',
  }
  return map[status] ?? `状态${status}`
}

// ============ 各 tab 数据 ============
const moneyLog = reactive({ list: [] as MemberMoneyLogItem[], total: 0, page: 1, loading: false })
const scoreLog = reactive({ list: [] as MemberScoreLogItem[], total: 0, page: 1, loading: false })
const orders = reactive({ list: [] as MemberOrderItem[], total: 0, page: 1, loading: false })
const address = reactive({ list: [] as MemberAddressItem[], total: 0, page: 1, loading: false })

const PAGE_SIZE = 10

async function fetchDetail() {
  detailLoading.value = true
  try {
    detail.value = await getMemberDetail(currentMid.value)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    detailLoading.value = false
  }
}

async function fetchMoneyLog() {
  moneyLog.loading = true
  try {
    const data = await getMemberMoneyLog({ mid: currentMid.value, page: moneyLog.page, pagesize: PAGE_SIZE })
    moneyLog.list = data.list || []
    moneyLog.total = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    moneyLog.loading = false
  }
}

async function fetchScoreLog() {
  scoreLog.loading = true
  try {
    const data = await getMemberScoreLog({ mid: currentMid.value, page: scoreLog.page, pagesize: PAGE_SIZE })
    scoreLog.list = data.list || []
    scoreLog.total = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    scoreLog.loading = false
  }
}

async function fetchOrders() {
  orders.loading = true
  try {
    const data = await getMemberOrders({ mid: currentMid.value, page: orders.page, pagesize: PAGE_SIZE })
    orders.list = data.list || []
    orders.total = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    orders.loading = false
  }
}

async function fetchAddress() {
  address.loading = true
  try {
    const data = await getMemberAddress({ mid: currentMid.value, page: address.page, pagesize: PAGE_SIZE })
    address.list = data.list || []
    address.total = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    address.loading = false
  }
}

// tab 切换时按需加载（懒加载，避免一次全拉）
function handleTabClick(tab: { paneName: string }) {
  loadTab(tab.paneName)
}

function loadTab(name: string) {
  if (name === 'moneylog' && !moneyLog.list.length && !moneyLog.total) fetchMoneyLog()
  if (name === 'scorelog' && !scoreLog.list.length && !scoreLog.total) fetchScoreLog()
  if (name === 'orders' && !orders.list.length && !orders.total) fetchOrders()
  if (name === 'address' && !address.list.length && !address.total) fetchAddress()
}

/** 打开抽屉（父组件调用） */
function open(mid: number) {
  currentMid.value = mid
  activeTab.value = 'base'
  detail.value = null
  Object.assign(moneyLog, { list: [], total: 0, page: 1, loading: false })
  Object.assign(scoreLog, { list: [], total: 0, page: 1, loading: false })
  Object.assign(orders, { list: [], total: 0, page: 1, loading: false })
  Object.assign(address, { list: [], total: 0, page: 1, loading: false })
  visible.value = true
  fetchDetail()
}

defineExpose({ open })
</script>

<template>
  <el-drawer v-model="visible" title="会员详情" size="720px" destroy-on-close>
    <div v-loading="detailLoading" class="member-detail">
      <!-- 头部：头像 + 基本信息 -->
      <div v-if="detail" class="detail-head">
        <el-avatar :size="52" :src="detail.headimg || undefined">
          {{ (detail.nickname || '会员').slice(0, 1) }}
        </el-avatar>
        <div class="head-info">
          <div class="head-name">
            {{ detail.nickname || '-' }}
            <el-tag v-if="detail.level_name" size="small" type="warning">{{ detail.level_name }}</el-tag>
            <el-tag v-if="detail.isfreeze === 1" size="small" type="danger">已冻结</el-tag>
            <el-tag v-if="detail.islock === 1" size="small" type="info">已锁定</el-tag>
          </div>
          <div class="head-sub">
            ID：{{ detail.id }} ｜ 手机：{{ detail.tel || '-' }} ｜ 注册：{{ formatTime(detail.createtime) }}
          </div>
        </div>
        <div class="head-stat">
          <div class="stat-item">
            <div class="stat-num">¥ {{ detail.money.toFixed(2) }}</div>
            <div class="stat-label">余额</div>
          </div>
          <div class="stat-item">
            <div class="stat-num">{{ detail.score }}</div>
            <div class="stat-label">积分</div>
          </div>
          <div class="stat-item">
            <div class="stat-num">{{ detail.buynum }} / ¥ {{ detail.buymoney.toFixed(2) }}</div>
            <div class="stat-label">消费次数 / 金额</div>
          </div>
        </div>
      </div>

      <el-tabs v-model="activeTab" @tab-click="handleTabClick">
        <!-- 基本信息 -->
        <el-tab-pane label="基本信息" name="base">
          <el-descriptions v-if="detail" :column="2" border size="small">
            <el-descriptions-item label="昵称">{{ detail.nickname || '-' }}</el-descriptions-item>
            <el-descriptions-item label="姓名">{{ detail.realname || '-' }}</el-descriptions-item>
            <el-descriptions-item label="手机号">{{ detail.tel || '-' }}</el-descriptions-item>
            <el-descriptions-item label="性别">{{ detail.sex || '-' }}</el-descriptions-item>
            <el-descriptions-item label="会员等级">{{ detail.level_name || '-' }}</el-descriptions-item>
            <el-descriptions-item label="等级到期">{{ detail.levelendtime ? formatTime(detail.levelendtime) : '永久' }}</el-descriptions-item>
            <el-descriptions-item label="余额">¥ {{ detail.money.toFixed(2) }}</el-descriptions-item>
            <el-descriptions-item label="积分">{{ detail.score }}</el-descriptions-item>
            <el-descriptions-item label="消费次数">{{ detail.buynum }}</el-descriptions-item>
            <el-descriptions-item label="消费金额">¥ {{ detail.buymoney.toFixed(2) }}</el-descriptions-item>
            <el-descriptions-item label="订单数">{{ detail.order_count }}</el-descriptions-item>
            <el-descriptions-item label="订单金额">¥ {{ detail.order_amount.toFixed(2) }}</el-descriptions-item>
            <el-descriptions-item label="注册时间">{{ formatTime(detail.createtime) }}</el-descriptions-item>
            <el-descriptions-item label="最后访问">{{ formatTime(detail.last_visittime) }}</el-descriptions-item>
            <el-descriptions-item label="来源平台">{{ detail.platform || '-' }}</el-descriptions-item>
            <el-descriptions-item label="备注">{{ detail.remark || '-' }}</el-descriptions-item>
          </el-descriptions>
        </el-tab-pane>

        <!-- 余额明细 -->
        <el-tab-pane label="余额明细" name="moneylog">
          <el-table v-loading="moneyLog.loading" :data="moneyLog.list" border size="small">
            <el-table-column prop="id" label="ID" width="64" />
            <el-table-column label="变动金额" width="100">
              <template #default="{ row }">
                <span :style="{ color: row.money >= 0 ? '#67c23a' : '#f56c6c' }">
                  {{ row.money >= 0 ? '+' : '' }}{{ Number(row.money).toFixed(2) }}
                </span>
              </template>
            </el-table-column>
            <el-table-column label="变动后余额" width="100">
              <template #default="{ row }">{{ Number(row.after).toFixed(2) }}</template>
            </el-table-column>
            <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip />
            <el-table-column label="时间" width="140">
              <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
            </el-table-column>
          </el-table>
          <el-pagination
            v-if="moneyLog.total > PAGE_SIZE"
            class="tab-pagination"
            small
            background
            layout="total, prev, pager, next"
            :total="moneyLog.total"
            :current-page="moneyLog.page"
            :page-size="PAGE_SIZE"
            @current-change="(p: number) => { moneyLog.page = p; fetchMoneyLog() }"
          />
        </el-tab-pane>

        <!-- 积分明细 -->
        <el-tab-pane label="积分明细" name="scorelog">
          <el-table v-loading="scoreLog.loading" :data="scoreLog.list" border size="small">
            <el-table-column prop="id" label="ID" width="64" />
            <el-table-column label="变动积分" width="90">
              <template #default="{ row }">
                <span :style="{ color: row.score >= 0 ? '#67c23a' : '#f56c6c' }">
                  {{ row.score >= 0 ? '+' : '' }}{{ row.score }}
                </span>
              </template>
            </el-table-column>
            <el-table-column prop="after" label="变动后积分" width="100" />
            <el-table-column prop="remark" label="备注" min-width="140" show-overflow-tooltip />
            <el-table-column label="时间" width="140">
              <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
            </el-table-column>
          </el-table>
          <el-pagination
            v-if="scoreLog.total > PAGE_SIZE"
            class="tab-pagination"
            small
            background
            layout="total, prev, pager, next"
            :total="scoreLog.total"
            :current-page="scoreLog.page"
            :page-size="PAGE_SIZE"
            @current-change="(p: number) => { scoreLog.page = p; fetchScoreLog() }"
          />
        </el-tab-pane>

        <!-- 订单 -->
        <el-tab-pane label="订单" name="orders">
          <el-table v-loading="orders.loading" :data="orders.list" border size="small">
            <el-table-column prop="ordernum" label="订单号" min-width="160" show-overflow-tooltip />
            <el-table-column label="订单金额" width="92">
              <template #default="{ row }">{{ Number(row.totalprice).toFixed(2) }}</template>
            </el-table-column>
            <el-table-column label="状态" width="80">
              <template #default="{ row }">{{ orderStatusText(row.status) }}</template>
            </el-table-column>
            <el-table-column label="下单时间" width="140">
              <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
            </el-table-column>
          </el-table>
          <el-pagination
            v-if="orders.total > PAGE_SIZE"
            class="tab-pagination"
            small
            background
            layout="total, prev, pager, next"
            :total="orders.total"
            :current-page="orders.page"
            :page-size="PAGE_SIZE"
            @current-change="(p: number) => { orders.page = p; fetchOrders() }"
          />
        </el-tab-pane>

        <!-- 地址 -->
        <el-tab-pane label="地址" name="address">
          <el-table v-loading="address.loading" :data="address.list" border size="small">
            <el-table-column label="收货人" width="110">
              <template #default="{ row }">
                {{ row.name }}
                <el-tag v-if="row.isdefault === 1" size="small" type="primary">默认</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="tel" label="电话" width="120" />
            <el-table-column label="地址" min-width="220" show-overflow-tooltip>
              <template #default="{ row }">{{ row.region }} {{ row.address }}</template>
            </el-table-column>
          </el-table>
          <el-pagination
            v-if="address.total > PAGE_SIZE"
            class="tab-pagination"
            small
            background
            layout="total, prev, pager, next"
            :total="address.total"
            :current-page="address.page"
            :page-size="PAGE_SIZE"
            @current-change="(p: number) => { address.page = p; fetchAddress() }"
          />
        </el-tab-pane>
      </el-tabs>
    </div>
  </el-drawer>
</template>

<style scoped>
.member-detail {
  min-height: 300px;
}

.detail-head {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 14px;
  margin-bottom: 12px;
  border: 1px solid #ebeef5;
  border-radius: 6px;
  background-color: #fafafa;
}

.head-info {
  flex: 1;
  min-width: 0;
}

.head-name {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.head-sub {
  margin-top: 4px;
  font-size: 12px;
  color: #909399;
}

.head-stat {
  display: flex;
  gap: 18px;
}

.stat-item {
  text-align: center;
}

.stat-num {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  white-space: nowrap;
}

.stat-label {
  margin-top: 2px;
  font-size: 12px;
  color: #909399;
  white-space: nowrap;
}

.tab-pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
</style>
