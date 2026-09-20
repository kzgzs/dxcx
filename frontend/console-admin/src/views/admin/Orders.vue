<template>
  <div class="orders-page">
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
      </div>
    </div>

    <!-- 复合筛选区 -->
    <el-card shadow="never" class="filter-card">
      <el-form inline :class="{ 'merchant-filter': isMerchantView }" @submit.prevent>
        <el-form-item v-if="isMerchantView">
          <el-select
            :model-value="query.bid === 'gt0' ? '' : Number(query.bid)"
            placeholder="请选择商户"
            clearable
            filterable
            class="biz-select"
            @change="onBusinessChange"
          >
            <el-option v-for="b in businessOptions" :key="b.id" :label="b.name" :value="b.id" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-input
            v-model="query.keyword"
            :placeholder="kwPlaceholder"
            clearable
            class="kw-input"
            @keyup.enter="handleSearch"
          >
            <template #prepend>
              <el-select v-model="query.keyword_type" class="kw-type">
                <el-option v-for="o in keywordTypes" :key="o.v" :label="o.l" :value="o.v" />
              </el-select>
            </template>
          </el-input>
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.time_type" class="time-type">
            <el-option v-for="o in timeTypes" :key="o.v" :label="o.l" :value="o.v" />
          </el-select>
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
          <el-select v-model="query.freight_id" placeholder="配送方式" clearable class="w120">
            <el-option v-for="f in freightOptions" :key="f.id" :label="f.name" :value="f.id" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="状态" clearable class="w100" @change="onStatusSelect">
            <el-option v-for="o in statusOptions" :key="String(o.v)" :label="o.l" :value="o.v" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <span class="amount-label">订单金额</span>
          <el-input v-model="query.price_min" placeholder="¥" class="amount-input" />
          <span class="amount-sep">-</span>
          <el-input v-model="query.price_max" placeholder="¥" class="amount-input" />
        </el-form-item>
        <el-form-item>
          <el-button type="success" @click="handleSearch">搜索</el-button>
          <el-button @click="resetFilter">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 操作按钮行 -->
    <div class="toolbar">
      <div class="toolbar-left">
        <el-button @click="handleExport">导出</el-button>
        <el-dropdown @command="onBatchCommand">
          <el-button type="primary" plain>
            批量发货
            <svg viewBox="0 0 1024 1024" width="10" height="10" fill="currentColor" style="margin-left:4px">
              <path d="M512 704L256 384h512z" />
            </svg>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="excel">Excel批量发货</el-dropdown-item>
              <el-dropdown-item command="same">同一快递发多单</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
        <el-dropdown @command="onShdCommand">
          <el-button>
            打印送货单
            <svg viewBox="0 0 1024 1024" width="10" height="10" fill="currentColor" style="margin-left:4px">
              <path d="M512 704L256 384h512z" />
            </svg>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="print">打印选中订单送货单</el-dropdown-item>
              <el-dropdown-item command="setting">送货单设置</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
        <el-button type="danger" plain :disabled="!selected.length" @click="handleBatchDelete">
          批量删除
        </el-button>
      </div>
      <div class="toolbar-right">
        <el-popover placement="bottom-end" :width="168" trigger="click">
          <template #reference>
            <el-button class="col-set-btn" title="列设置">
              <svg viewBox="0 0 1024 1024" width="15" height="15" fill="currentColor">
                <path d="M384 128h256v128H384zM384 448h256v128H384zM384 768h256v128H384z" />
                <path
                  d="M128 128h128v128H128zM128 448h128v128H128zM128 768h128v128H128zM768 128h128v128H768zM768 448h128v128H768zM768 768h128v128H768z"
                />
              </svg>
            </el-button>
          </template>
          <div class="col-set">
            <el-checkbox v-for="c in colToggles" :key="c.k" v-model="colVisible[c.k]">
              {{ c.l }}
            </el-checkbox>
          </div>
        </el-popover>
      </div>
    </div>

    <!-- 订单表格（11 列，对齐参考系统） -->
    <el-table
      v-loading="loading"
      :data="list"
      border
      stripe
      size="small"
      class="order-table"
      @selection-change="onSelectionChange"
      @sort-change="onSortChange"
    >
      <el-table-column type="selection" width="40" />
      <el-table-column label="ID" prop="id" width="60" sortable="custom" />
      <el-table-column v-if="isMerchantView" label="所属商户" prop="bname" width="160" show-overflow-tooltip />

      <el-table-column v-if="colVisible.goods" label="商品信息" min-width="240">
        <template #default="{ row }">
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
                <span v-if="(g.refund_num || 0) > 0" class="refund-tag">[退×{{ g.refund_num }}]</span>
              </div>
            </div>
          </div>
        </template>
      </el-table-column>

      <el-table-column v-if="colVisible.order" label="订单号/下单时间/来源" min-width="168">
        <template #default="{ row }">
          <div class="mono order-sn" title="点击复制订单号" @click="copySn(row)">{{ row.order_sn }}</div>
          <div class="green create-time">{{ formatTime(row.create_time) }}</div>
          <div class="platform">{{ platformText(row.platform) }}</div>
        </template>
      </el-table-column>

      <el-table-column v-if="colVisible.amount" label="商品总价/实付款/支付方式" min-width="150">
        <template #default="{ row }">
          <div class="amount-line">商品总价：¥{{ fmtMoney(row.product_amount) }}</div>
          <div class="amount-pay">实付款：¥{{ fmtMoney(row.pay_amount) }}</div>
          <div class="pay-type">{{ payTypeText(row.pay_type) }}</div>
        </template>
      </el-table-column>

      <el-table-column v-if="colVisible.address" label="收货地址" min-width="180">
        <template #default="{ row }">
          <div class="addr-name">{{ row.address?.name || '--' }} {{ row.address?.phone || '' }}</div>
          <div class="addr-detail">
            {{ row.address?.province }}{{ row.address?.city }}{{ row.address?.district
            }}{{ row.address?.detail }}
          </div>
        </template>
      </el-table-column>

      <el-table-column v-if="colVisible.member" label="头像昵称" width="118">
        <template #default="{ row }">
          <div class="member-line">
            <el-image
              v-if="row.member_headimg"
              :src="assetUrl(row.member_headimg)"
              class="member-avatar"
              fit="cover"
            >
              <template #error>
                <div class="avatar-fallback">{{ avatarText(row) }}</div>
              </template>
            </el-image>
            <div v-else class="avatar-fallback">{{ avatarText(row) }}</div>
            <div class="member-meta">
              <div class="member-name">{{ row.member_nickname || '--' }}</div>
              <div class="member-id">会员ID:{{ row.mid }}</div>
            </div>
          </div>
        </template>
      </el-table-column>

      <el-table-column v-if="colVisible.freight" label="配送方式" width="100">
        <template #default="{ row }">
          <div>{{ row.freight_text || '--' }}</div>
          <div class="freight-fee">运费：¥{{ fmtMoney(row.freight) }}</div>
        </template>
      </el-table-column>

      <el-table-column v-if="colVisible.status" label="状态" width="92">
        <template #default="{ row }">
          <div class="status-text" :style="{ color: statusMeta(row.status).c }">
            {{ statusMeta(row.status).t }}
          </div>
          <div
            v-if="(row.refund_count || 0) > 0 || row.refund_status === 1"
            class="refund-flag"
            @click="gotoRefund"
          >
            有售后({{ row.refund_count || 1 }})
          </div>
        </template>
      </el-table-column>

      <el-table-column v-if="colVisible.remark" label="备注" width="132">
        <template #default="{ row }">
          <div v-if="row.buyer_message" class="buyer-msg" :title="row.buyer_message">
            留言：{{ row.buyer_message }}
          </div>
          <div v-if="row.remark" class="remark-text" :title="row.remark">备注：{{ row.remark }}</div>
          <el-button link type="primary" size="small" @click="openRemark(row)">
            {{ row.remark ? '修改备注' : '添加备注' }}
          </el-button>
        </template>
      </el-table-column>

      <el-table-column label="操作" width="128" fixed="right">
        <template #default="{ row }">
          <div class="op-grid">
            <el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
            <el-button
              v-if="[1, 2, 3].includes(row.status)"
              link
              type="primary"
              size="small"
              @click="printOne(row)"
            >
              送货单
            </el-button>
            <el-button v-if="row.status === 0" link type="primary" size="small" @click="openPrice(row)">
              修改
            </el-button>
            <el-button link type="danger" size="small" @click="removeOne(row)">删除</el-button>
            <el-button v-if="row.status === 1" link type="success" size="small" @click="openShipOne(row)">
              发货
            </el-button>
            <el-button v-if="row.status === 0" link type="warning" size="small" @click="closeOne(row)">
              关闭
            </el-button>
            <el-button
              v-if="(row.refund_count || 0) > 0 || row.refund_status === 1"
              link
              type="danger"
              size="small"
              @click="gotoRefund"
            >
              退款
            </el-button>
          </div>
        </template>
      </el-table-column>

      <template #empty>
        <el-empty description="暂无订单" />
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

    <!-- 隐藏的 Excel 选择器 -->
    <input ref="fileInput" type="file" accept=".xlsx,.xls,.csv" hidden @change="onExcelFile" />

    <!-- 发货弹窗（单个 / 同一快递发多单） -->
    <el-dialog
      v-model="shipVisible"
      :title="shipMode === 'one' ? '订单发货' : '同一快递发多单'"
      width="460px"
    >
      <el-form label-width="92px">
        <el-form-item label="快递公司">
          <el-input v-model="shipCompany" placeholder="如：顺丰速运" />
        </el-form-item>
        <el-form-item label="快递单号">
          <el-input v-model="shipSn" placeholder="请输入快递单号" />
        </el-form-item>
        <el-form-item v-if="shipMode === 'same'" label="">
          <span class="tip">将对勾选的 {{ selected.length }} 个待发货订单统一发货。</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="shipVisible = false">取消</el-button>
        <el-button type="primary" :loading="shipSubmitting" @click="submitShip">确认发货</el-button>
      </template>
    </el-dialog>

    <!-- 订单详情抽屉 -->
    <el-drawer v-model="detailVisible" title="订单详情" size="480px">
      <div v-loading="detailLoading" class="detail-wrap">
        <template v-if="detailData">
          <div class="d-status">
            <span :style="{ color: statusMeta(detailData.status).c }">
              {{ statusMeta(detailData.status).t }}
            </span>
            <span class="mono">{{ detailData.order_sn }}</span>
          </div>

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
              <div class="d-goods-total">¥{{ fmtMoney(g.total) }}</div>
            </div>
          </div>

          <div class="d-section">
            <div class="d-title">收货信息</div>
            <div>收货人：{{ detailData.address?.name }} {{ detailData.address?.phone }}</div>
            <div class="gray">
              {{ detailData.address?.province }}{{ detailData.address?.city
              }}{{ detailData.address?.district }}{{ detailData.address?.detail }}
            </div>
          </div>

          <div class="d-section">
            <div class="d-title">金额信息</div>
            <div class="d-row"><span>商品总价</span><span>¥{{ fmtMoney(detailData.product_amount) }}</span></div>
            <div class="d-row"><span>运费</span><span>¥{{ fmtMoney(detailData.freight) }}</span></div>
            <div v-if="Number(detailData.coupon_money) > 0" class="d-row">
              <span>优惠券</span><span>-¥{{ fmtMoney(detailData.coupon_money) }}</span>
            </div>
            <div v-if="Number(detailData.promotion_money) > 0" class="d-row">
              <span>促销优惠</span><span>-¥{{ fmtMoney(detailData.promotion_money) }}</span>
            </div>
            <div v-if="Number(detailData.score_money) > 0" class="d-row">
              <span>积分抵扣</span><span>-¥{{ fmtMoney(detailData.score_money) }}</span>
            </div>
            <div class="d-row d-pay">
              <span>实付款</span><span>¥{{ fmtMoney(detailData.pay_amount) }}</span>
            </div>
          </div>

          <div class="d-section">
            <div class="d-title">支付与物流</div>
            <div class="d-row"><span>支付方式</span><span>{{ payTypeText(detailData.pay_info?.pay_type) }}</span></div>
            <div class="d-row"><span>支付单号</span><span class="mono">{{ detailData.pay_info?.pay_num || '--' }}</span></div>
            <div class="d-row"><span>支付时间</span><span>{{ detailData.pay_info?.pay_time || '--' }}</span></div>
            <div class="d-row"><span>配送方式</span><span>{{ detailData.freight_text || '--' }}</span></div>
            <div class="d-row"><span>物流公司</span><span>{{ detailData.ship_info?.ship_company || '--' }}</span></div>
            <div class="d-row"><span>物流单号</span><span class="mono">{{ detailData.ship_info?.ship_sn || '--' }}</span></div>
            <div class="d-row"><span>发货时间</span><span>{{ detailData.ship_info?.ship_time || '--' }}</span></div>
            <div class="d-row"><span>完成时间</span><span>{{ detailData.collect_time || '--' }}</span></div>
          </div>

          <div class="d-section">
            <div class="d-title">备注</div>
            <div class="d-row"><span>买家留言</span><span>{{ detailData.message || '--' }}</span></div>
            <div class="d-row"><span>商家备注</span><span>{{ detailData.remark || '--' }}</span></div>
          </div>
        </template>
      </div>
    </el-drawer>

    <!-- 送货单设置 -->
    <el-dialog v-model="shdVisible" title="送货单设置" width="480px">
      <el-form label-width="130px">
        <el-form-item label="送货单标题">
          <el-input v-model="shdForm.shipping_pagetitle" placeholder="如：发货送货单" />
        </el-form-item>
        <el-form-item label="每页打印行数">
          <el-input-number v-model="shdForm.shipping_pagenum" :min="0" :max="100" />
          <span class="tip-inline">0=不分页</span>
        </el-form-item>
        <el-form-item label="每页表格固定行数">
          <el-input-number v-model="shdForm.shipping_linenum" :min="0" :max="100" />
          <span class="tip-inline">0=不固定</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="shdVisible = false">取消</el-button>
        <el-button type="primary" @click="saveShd">保存</el-button>
      </template>
    </el-dialog>

    <!-- 商家备注 -->
    <el-dialog v-model="remarkVisible" title="商家备注" width="420px">
      <el-input
        v-model="remarkText"
        type="textarea"
        :rows="4"
        placeholder="仅后台可见的商家备注"
        maxlength="255"
        show-word-limit
      />
      <template #footer>
        <el-button @click="remarkVisible = false">取消</el-button>
        <el-button type="primary" @click="saveRemark">保存</el-button>
      </template>
    </el-dialog>

    <!-- 改价（仅未支付） -->
    <el-dialog v-model="priceVisible" title="修改实付金额" width="420px">
      <el-input v-model="priceValue" type="number" placeholder="请输入新的实付金额（元）">
        <template #prepend>¥</template>
      </el-input>
      <div class="tip">仅未支付订单可改价，修改后买家按新金额支付。</div>
      <template #footer>
        <el-button @click="priceVisible = false">取消</el-button>
        <el-button type="primary" @click="submitPrice">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, inject, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  batchDeleteOrders,
  batchShipByExcel,
  batchShipSelected,
  changeOrderPrice,
  closeOrder,
  exportOrders,
  getFreightOptions,
  getOrderBusinessOptions,
  getOrderDetail,
  getOrderList,
  getOrderStat,
  getShdPrintHtml,
  getShdSet,
  saveShdSet,
  setOrderRemark,
  shipOrder,
} from '../../api/order'
import { assetUrl } from '../../api/upload'
import type { BatchShipResult, OrderItem, OrderStat, ShdSetData } from '../../api/types'

type Row = OrderItem

// 商户菜单「商户订单」以 merchantViewOf 注入 bid='gt0'（对照参考系统 ShopOrder/index&showtype=2：全部商户订单）
const props = defineProps<{ bid?: string }>()
const isMerchantView = computed(() => props.bid === 'gt0')
type StatusValue = number | ''

const loading = ref(false)
const list = ref<Row[]>([])
const total = ref(0)
const stat = ref<OrderStat | null>(null)
const selected = ref<Row[]>([])
const selectedIds = computed(() => selected.value.map((o) => o.id))

const query = reactive({
  page: 1,
  pagesize: 10,
  status: '' as StatusValue,
  keyword: '',
  keyword_type: 1,
  time_type: 1,
  freight_id: '' as number | '',
  price_min: '',
  price_max: '',
  sort_field: 'id',
  sort_order: 'desc',
  bid: '',
})
const dateRange = ref<[string, string] | null>(null)

const tabs: { label: string; value: StatusValue }[] = [
  { label: '全部订单', value: '' },
  { label: '未支付', value: 0 },
  { label: '待发货', value: 1 },
  { label: '已发货', value: 2 },
  { label: '已完成', value: 3 },
  { label: '已关闭', value: 4 },
]
const keywordTypes = [
  { v: 1, l: '订单号' },
  { v: 2, l: '会员ID' },
  { v: 3, l: '会员信息' },
  { v: 4, l: '收货信息' },
  { v: 5, l: '快递单号' },
  { v: 6, l: '商品ID' },
  { v: 7, l: '商品名称' },
  { v: 8, l: '商品编码' },
]
const timeTypes = [
  { v: 1, l: '下单时间' },
  { v: 2, l: '付款时间' },
  { v: 3, l: '发货时间' },
  { v: 4, l: '完成时间' },
]
const statusOptions: { l: string; v: StatusValue }[] = [
  { l: '全部', v: '' },
  { l: '未支付', v: 0 },
  { l: '待发货', v: 1 },
  { l: '已发货', v: 2 },
  { l: '已完成', v: 3 },
  { l: '已关闭', v: 4 },
]
const freightOptions = ref<{ id: number; name: string }[]>([])
const businessOptions = ref<{ id: number; name: string }[]>([])

// 商户下拉：选中具体商户按商户过滤，清空回到全部商户（gt0）
function onBusinessChange(v: number | '') {
  query.bid = v === '' ? 'gt0' : String(v)
  query.page = 1
  loadData()
}

const kwPlaceholder = computed(() => {
  const o = keywordTypes.find((k) => k.v === query.keyword_type)
  return o ? `请输入${o.l}` : '请输入关键词'
})

// 列显隐（选择/ID/操作固定，其余可切换）
const colVisible = reactive({
  goods: true,
  order: true,
  amount: true,
  address: true,
  member: true,
  freight: true,
  status: true,
  remark: true,
})
const colToggles = [
  { k: 'goods', l: '商品信息' },
  { k: 'order', l: '订单信息' },
  { k: 'amount', l: '金额信息' },
  { k: 'address', l: '收货地址' },
  { k: 'member', l: '会员信息' },
  { k: 'freight', l: '配送方式' },
  { k: 'status', l: '状态' },
  { k: 'remark', l: '备注' },
] as const

async function loadData() {
  loading.value = true
  try {
    const params: Record<string, unknown> = { ...query }
    if (dateRange.value?.[0]) params.start = dateRange.value[0]
    if (dateRange.value?.[1]) params.end = dateRange.value[1]
    const res = await getOrderList(params as never)
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
    stat.value = await getOrderStat()
  } catch {
    /* 统计不阻塞列表 */
  }
}
async function loadFreight() {
  try {
    freightOptions.value = await getFreightOptions()
  } catch {
    freightOptions.value = []
  }
}

async function loadBusinessOptions() {
  try {
    businessOptions.value = await getOrderBusinessOptions()
  } catch {
    businessOptions.value = []
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
  query.keyword = ''
  query.keyword_type = 1
  query.time_type = 1
  query.freight_id = ''
  query.price_min = ''
  query.price_max = ''
  query.status = ''
  query.sort_field = 'id'
  query.sort_order = 'desc'
  dateRange.value = null
  if (isMerchantView.value) query.bid = 'gt0'
  query.page = 1
  loadData()
}
function onSizeChange(ps: number) {
  query.pagesize = ps
  query.page = 1
  loadData()
}
function onSortChange({ prop, order }: { prop: string; order: string | null }) {
  if (prop !== 'id') return
  query.sort_field = 'id'
  query.sort_order = order === 'ascending' ? 'asc' : 'desc'
  loadData()
}
function onSelectionChange(rows: Row[]) {
  selected.value = rows
}

// ---------- 展示辅助 ----------
function fmtMoney(n: unknown): string {
  return Number(n || 0).toFixed(2)
}
function formatTime(t: unknown): string {
  if (!t) return '--'
  if (typeof t === 'number') return new Date(t * 1000).toLocaleString()
  return String(t)
}
function statusMeta(s: number): { t: string; c: string } {
  return (
    {
      0: { t: '未支付', c: '#ff9900' },
      1: { t: '待发货', c: '#19be6b' },
      2: { t: '已发货', c: '#2d8cf0' },
      3: { t: '已完成', c: '#52c41a' },
      4: { t: '已关闭', c: '#c0c4cc' },
    } as Record<number, { t: string; c: string }>
  )[s] || { t: '未知', c: '#909399' }
}
function platformText(p?: string): string {
  if (!p) return '--'
  const m: Record<string, string> = {
    wx: '微信小程序',
    wechat: '微信小程序',
    h5: 'H5',
    wap: 'H5',
    api: 'H5',
    app: 'APP',
    admin: '后台下单',
    backend: '后台下单',
  }
  return m[p] || p
}
function payTypeText(p?: string): string {
  if (!p) return '--'
  const m: Record<string, string> = {
    wechat: '微信支付',
    wxpay: '微信支付',
    alipay: '支付宝',
    balance: '余额支付',
    cash: '现金支付',
    mock: '模拟支付',
    score: '积分支付',
  }
  return m[p] || p
}
function avatarText(row: Row): string {
  return (row.member_nickname || '会').slice(0, 1)
}
async function copySn(row: Row) {
  try {
    await navigator.clipboard.writeText(row.order_sn)
    ElMessage.success('订单号已复制')
  } catch {
    /* 剪贴板不可用时忽略 */
  }
}

// ---------- 详情 ----------
const detailVisible = ref(false)
const detailLoading = ref(false)
type OrderDetail = OrderItem & {
  collect_time?: string
  message?: string
  pay_info?: { pay_type: string; pay_num: string; pay_time: string; expire_time?: string }
  ship_info?: { ship_company: string; ship_sn: string; ship_time: string }
}
const detailData = ref<OrderDetail | null>(null)
async function openDetail(row: Row) {
  detailVisible.value = true
  detailLoading.value = true
  detailData.value = null
  try {
    detailData.value = (await getOrderDetail(row.id)) as OrderDetail
  } catch (e) {
    ElMessage.error((e as Error)?.message || '详情加载失败')
  } finally {
    detailLoading.value = false
  }
}

// ---------- 发货（单个 / 同一快递多单） ----------
const shipVisible = ref(false)
const shipMode = ref<'one' | 'same'>('one')
const shipOrderId = ref(0)
const shipCompany = ref('')
const shipSn = ref('')
const shipSubmitting = ref(false)
function openShipOne(row: Row) {
  shipMode.value = 'one'
  shipOrderId.value = row.id
  shipCompany.value = row.express_company || ''
  shipSn.value = row.express_no || ''
  shipVisible.value = true
}
function openShipSame() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选订单')
    return
  }
  shipMode.value = 'same'
  shipCompany.value = ''
  shipSn.value = ''
  shipVisible.value = true
}
async function submitShip() {
  if (!shipCompany.value) {
    ElMessage.warning('请填写快递公司')
    return
  }
  if (!shipSn.value) {
    ElMessage.warning('请填写快递单号')
    return
  }
  shipSubmitting.value = true
  try {
    if (shipMode.value === 'one') {
      await shipOrder({ id: shipOrderId.value, ship_company: shipCompany.value, ship_sn: shipSn.value })
      ElMessage.success('发货成功')
    } else {
      const r = await batchShipSelected({
        ids: selectedIds.value,
        ship_company: shipCompany.value,
        ship_sn: shipSn.value,
      })
      ElMessage.success(`成功 ${r.success} 单，失败 ${r.fail} 单`)
    }
    shipVisible.value = false
    loadData()
    loadStat()
  } catch (e) {
    ElMessage.error((e as Error)?.message || '发货失败')
  } finally {
    shipSubmitting.value = false
  }
}

// ---------- 批量发货入口 / Excel ----------
const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
function onBatchCommand(c: string) {
  if (c === 'excel') fileInput.value?.click()
  else openShipSame()
}
async function onExcelFile(ev: Event) {
  const f = (ev.target as HTMLInputElement).files?.[0]
  if (!f) return
  uploading.value = true
  try {
    const fd = new FormData()
    fd.append('file', f)
    showBatchResult(await batchShipByExcel(fd))
  } catch (e) {
    ElMessage.error((e as Error)?.message || '批量发货失败')
  } finally {
    uploading.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}
function showBatchResult(r: BatchShipResult) {
  const fails = r.items.filter((i) => !i.ok)
  ElMessageBox.alert(
    `成功 ${r.success} 单，失败 ${r.fail} 单${
      fails.length ? '\n' + fails.slice(0, 10).map((x) => `${x.ordernum} ${x.msg}`).join('\n') : ''
    }`,
    '批量发货结果',
    { type: r.fail ? 'warning' : 'success' },
  ).finally(() => {
    loadData()
    loadStat()
  })
}

// ---------- 删除 ----------
function removeOne(row: Row) {
  return doDelete([row.id])
}
function handleBatchDelete() {
  if (!selectedIds.value.length) return
  return doDelete(selectedIds.value)
}
async function doDelete(ids: number[]) {
  try {
    await ElMessageBox.confirm(
      `确认删除选中的 ${ids.length} 个订单？删除会回滚库存/券/积分，且不可恢复`,
      '批量删除',
      { type: 'warning' },
    )
  } catch {
    return
  }
  try {
    const r = await batchDeleteOrders(ids)
    const fails = r.items.filter((i) => !i.ok)
    ElMessage.success(`成功删除 ${r.success} 个，失败 ${r.fail} 个${fails.length ? '：' + fails[0].msg : ''}`)
    loadData()
    loadStat()
  } catch (e) {
    ElMessage.error((e as Error)?.message || '删除失败')
  }
}

// ---------- 导出 ----------
async function handleExport() {
  if (!selected.value.length && !window.confirm('未勾选订单，将按当前筛选条件导出全部，是否继续？')) return
  try {
    ElMessage.info('正在生成订单Excel…')
    const blob = await exportOrders({
      status: query.status,
      keyword: query.keyword,
      ids: selectedIds.value.join(','),
      bid: query.bid || undefined,
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `订单_${Date.now()}.xlsx`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch (e) {
    ElMessage.error((e as Error)?.message || '导出失败')
  }
}

// ---------- 送货单 ----------
const shdVisible = ref(false)
const shdForm = reactive<ShdSetData>({
  shipping_pagetitle: '送货单',
  shipping_pagenum: 0,
  shipping_linenum: 0,
})
async function openShdSet() {
  shdVisible.value = true
  try {
    Object.assign(shdForm, await getShdSet())
  } catch {
    /* 保留默认 */
  }
}
async function saveShd() {
  try {
    await saveShdSet({ ...shdForm })
    ElMessage.success('保存成功')
    shdVisible.value = false
  } catch (e) {
    ElMessage.error((e as Error)?.message || '保存失败')
  }
}
function onShdCommand(c: string) {
  if (c === 'setting') openShdSet()
  else handleBatchPrint()
}
function openPrintBlob(blob: Blob) {
  const url = URL.createObjectURL(blob)
  window.open(url, '_blank')
  setTimeout(() => URL.revokeObjectURL(url), 60000)
}
async function printOne(row: Row) {
  try {
    ElMessage.info('正在生成送货单…')
    openPrintBlob(await getShdPrintHtml([row.id]))
  } catch (e) {
    ElMessage.error((e as Error)?.message || '打印失败')
  }
}
async function handleBatchPrint() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选订单')
    return
  }
  try {
    ElMessage.info('正在生成送货单…')
    openPrintBlob(await getShdPrintHtml(selectedIds.value))
  } catch (e) {
    ElMessage.error((e as Error)?.message || '打印失败')
  }
}

// ---------- 商家备注 ----------
const remarkVisible = ref(false)
const remarkId = ref(0)
const remarkText = ref('')
function openRemark(row: Row) {
  remarkId.value = row.id
  remarkText.value = row.remark || ''
  remarkVisible.value = true
}
async function saveRemark() {
  try {
    await setOrderRemark({ id: remarkId.value, remark: remarkText.value })
    ElMessage.success('备注已保存')
    remarkVisible.value = false
    const t = list.value.find((o) => o.id === remarkId.value)
    if (t) t.remark = remarkText.value
  } catch (e) {
    ElMessage.error((e as Error)?.message || '保存失败')
  }
}

// ---------- 改价 ----------
const priceVisible = ref(false)
const priceId = ref(0)
const priceValue = ref<number | string>('')
function openPrice(row: Row) {
  priceId.value = row.id
  priceValue.value = row.pay_amount
  priceVisible.value = true
}
async function submitPrice() {
  const v = Number(priceValue.value)
  if (!(v > 0)) {
    ElMessage.warning('请输入大于0的金额')
    return
  }
  try {
    await changeOrderPrice({ id: priceId.value, price: v })
    ElMessage.success('改价成功')
    priceVisible.value = false
    loadData()
  } catch (e) {
    ElMessage.error((e as Error)?.message || '改价失败')
  }
}

// ---------- 关闭订单 ----------
async function closeOne(row: Row) {
  try {
    await ElMessageBox.confirm(`确认关闭订单 ${row.order_sn}？关闭后回补库存/券/积分`, '关闭订单', {
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    await closeOrder(row.id)
    ElMessage.success('订单已关闭')
    loadData()
    loadStat()
  } catch (e) {
    ElMessage.error((e as Error)?.message || '关闭失败')
  }
}

// ---------- 跳转退款管理（框架提供的菜单导航） ----------
const navigateByKey = inject<(k: string) => boolean>('adminNavigateByKey')
function gotoRefund() {
  const ok = navigateByKey?.('shop-refund-order-index')
  if (!ok) ElMessage.info('请到 商城-订单-退款申请 处理')
}

onMounted(() => {
  if (isMerchantView.value) {
    query.bid = 'gt0'
    loadBusinessOptions()
  }
  loadData()
  loadStat()
  loadFreight()
})
</script>

<style scoped>
.orders-page {
  /* 主区域四边边距由 AdminLayout .admin-main 统一 3px，页面不再自带 padding */
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
.filter-card {
  margin-bottom: 3px;
}
.filter-card :deep(.el-card__body) {
  padding: 14px 14px 0;
}
.filter-card :deep(.el-form-item) {
  margin-bottom: 14px;
  margin-right: 12px;
}
.kw-input {
  width: 300px;
}
.kw-type {
  width: 104px;
}
.time-type {
  width: 108px;
  margin-right: 8px;
}
.date-range {
  width: 250px;
}
.w120 {
  width: 120px;
}
.w100 {
  width: 100px;
}
.amount-label {
  font-size: 13px;
  color: #606266;
  margin-right: 6px;
}
.amount-input {
  width: 90px;
}
.amount-sep {
  margin: 0 6px;
  color: #909399;
}
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 3px;
}
.toolbar-left {
  display: flex;
  gap: 8px;
  align-items: center;
}
.col-set-btn {
  padding: 8px 10px;
}
.col-set {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.order-table {
  width: 100%;
  font-size: 12px;
}
.order-table :deep(.el-table__cell) {
  padding: 6px 0;
}
.mono {
  font-family: Consolas, Menlo, monospace;
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
.refund-tag {
  color: #f56c6c;
}
.order-sn {
  font-size: 12px;
  color: #303133;
  cursor: pointer;
  word-break: break-all;
}
.order-sn:hover {
  color: #409eff;
}
.green {
  color: #19be6b;
  font-size: 11px;
  margin-top: 2px;
}
.platform {
  font-size: 11px;
  color: #909399;
  margin-top: 2px;
}
.amount-line {
  font-size: 11px;
  color: #909399;
}
.amount-pay {
  font-size: 13px;
  font-weight: 600;
  color: #303133;
  margin: 1px 0;
}
.pay-type {
  font-size: 11px;
  color: #909399;
}
.addr-name {
  font-size: 12px;
  color: #303133;
}
.addr-detail {
  font-size: 11px;
  color: #909399;
  margin-top: 2px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.member-line {
  display: flex;
  align-items: center;
  gap: 6px;
}
.member-avatar,
.avatar-fallback {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  flex: none;
}
.avatar-fallback {
  background: #dfe7f5;
  color: #5a7bb0;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.member-name {
  font-size: 12px;
  color: #303133;
  max-width: 70px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.member-id {
  font-size: 11px;
  color: #909399;
}
.freight-fee {
  font-size: 11px;
  color: #909399;
  margin-top: 2px;
}
.status-text {
  font-size: 12px;
  font-weight: 600;
}
.refund-flag {
  font-size: 11px;
  color: #f56c6c;
  cursor: pointer;
  margin-top: 2px;
}
.buyer-msg,
.remark-text {
  font-size: 11px;
  color: #909399;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  margin-bottom: 2px;
}
.remark-text {
  color: #e6a23c;
}
.op-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 6px;
}
.op-grid :deep(.el-button) {
  margin: 0;
  padding: 0;
  height: 20px;
  justify-self: start;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
.tip {
  font-size: 12px;
  color: #909399;
  margin-top: 8px;
  line-height: 1.6;
}
.tip-inline {
  font-size: 12px;
  color: #909399;
  margin-left: 8px;
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
.d-goods-total {
  font-size: 13px;
  font-weight: 600;
}
.d-row {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  line-height: 2;
}
.d-row span:first-child {
  color: #909399;
}
.d-pay {
  font-weight: 700;
  font-size: 14px;
  color: #f56c6c;
}
.gray {
  color: #909399;
  font-size: 12px;
}
/* 商户订单视图：筛选区压缩为一行（对照参考截图红字要求） */
.merchant-filter :deep(.el-form-item) {
  margin-right: 8px;
  margin-bottom: 12px;
}
.merchant-filter .biz-select {
  width: 130px;
}
.merchant-filter .kw-input {
  width: 210px;
}
.merchant-filter .kw-type {
  width: 92px;
}
.merchant-filter .time-type {
  width: 92px;
}
.merchant-filter .date-range {
  width: 210px;
}
.merchant-filter .w120 {
  width: 104px;
}
.merchant-filter .w100 {
  width: 88px;
}
.merchant-filter .amount-input {
  width: 62px;
}
</style>
