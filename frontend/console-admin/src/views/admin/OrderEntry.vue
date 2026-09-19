<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  lrAddressList,
  lrGetUser,
  lrGoodsOptions,
  lrGuigeOptions,
  lrSaveOrder,
  type LrAddress,
  type LrGoodsOption,
  type LrGuigeOption,
  type LrMember,
} from '../../api/orderlr'
import { getFreightList } from '../../api/tenantSet'
import { getStoreList } from '../../api/store'
import type { FreightItem } from '../../api/types'

// ============ 会员 ============
const memberKeyword = ref('')
const memberSearching = ref(false)
const member = ref<LrMember | null>(null)

async function handleMemberSearch() {
  const kw = memberKeyword.value.trim()
  if (!kw) {
    ElMessage.warning('请输入会员ID / 手机号 / 昵称')
    return
  }
  memberSearching.value = true
  try {
    const result = await lrGetUser({ keyword: kw })
    const rows = result.list || []
    if (rows.length === 0) {
      ElMessage.info('未找到会员，将按游客录入')
      return
    }
    if (rows.length === 1) {
      selectMember(rows[0])
      return
    }
    memberOptions.value = rows
    memberPickVisible.value = true
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    memberSearching.value = false
  }
}

const memberOptions = ref<LrMember[]>([])
const memberPickVisible = ref(false)

function selectMember(m: LrMember) {
  member.value = m
  memberPickVisible.value = false
  loadAddresses()
}

function clearMember() {
  member.value = null
  memberKeyword.value = ''
  addresses.value = []
  selectedAddressId.value = 0
}

// ============ 收货地址 ============
const addresses = ref<LrAddress[]>([])
const selectedAddressId = ref(0)
const addressPickVisible = ref(false)

async function loadAddresses() {
  if (!member.value) {
    addresses.value = []
    return
  }
  try {
    const result = await lrAddressList(member.value.id)
    addresses.value = result.list || []
  } catch {
    addresses.value = []
  }
}

function applyAddress(addr: LrAddress) {
  form.linkman = addr.name || ''
  form.tel = addr.tel || ''
  form.province = addr.province || ''
  form.city = addr.city || ''
  form.district = addr.district || ''
  form.address = addr.address || ''
  selectedAddressId.value = addr.id
  addressPickVisible.value = false
}

// ============ 商品行 ============
interface OrderLine {
  proid: number
  name: string
  pic: string
  ggid: number
  guigeList: LrGuigeOption[]
  num: number
  price: number
  lvprice: number
}

const lines = ref<OrderLine[]>([])

const goodsPickVisible = ref(false)
const goodsKeyword = ref('')
const goodsLoading = ref(false)
const goodsList = ref<LrGoodsOption[]>([])
const goodsTotal = ref(0)
const goodsPage = ref(1)

async function loadGoods() {
  goodsLoading.value = true
  try {
    const result = await lrGoodsOptions({
      keyword: goodsKeyword.value.trim(),
      page: goodsPage.value,
      pagesize: 10,
    })
    goodsList.value = result.list || []
    goodsTotal.value = result.total || 0
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    goodsLoading.value = false
  }
}

function openGoodsPick() {
  goodsPickVisible.value = true
  goodsPage.value = 1
  loadGoods()
}

async function pickGoods(g: LrGoodsOption) {
  try {
    const result = await lrGuigeOptions(g.id)
    const guiges = result.list || []
    if (guiges.length === 0) {
      ElMessage.warning('该商品暂无规格，无法录入')
      return
    }
    const first = guiges[0]
    lines.value.push({
      proid: g.id,
      name: g.name,
      pic: g.pic,
      ggid: first.id,
      guigeList: guiges,
      num: 1,
      price: Number(first.sell_price) || 0,
      lvprice: g.lvprice,
    })
    goodsPickVisible.value = false
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

function onGuigeChange(line: OrderLine) {
  const g = line.guigeList.find((x) => x.id === line.ggid)
  if (g) line.price = Number(g.sell_price) || 0
}

function removeLine(index: number) {
  lines.value.splice(index, 1)
}

// 会员等级价：商品开启等级价且选中会员时取 lvprice_data[等级ID]
function lineUnitPrice(line: OrderLine): number {
  if (line.lvprice === 1 && member.value) {
    const g = line.guigeList.find((x) => x.id === line.ggid)
    const lv = g?.lvprice_data?.[String(member.value.levelid)]
    const v = Number(lv)
    if (v > 0) return v
  }
  return line.price
}

const goodsSum = computed(() =>
  lines.value.reduce((sum, l) => sum + lineUnitPrice(l) * l.num, 0),
)

// ============ 配送方式 ============
const freights = ref<FreightItem[]>([])
const stores = ref<{ id: number; name: string }[]>([])

const pstypeText: Record<number, string> = {
  0: '快递',
  1: '到店自提',
  2: '同城配送',
  3: '自动发货',
  4: '卡密',
  5: '门店配送',
}

const currentFreight = computed(() =>
  freights.value.find((f) => f.id === form.freight_id) || null,
)

// 需要收货地址的配送类型：快递0 / 同城2 / 门店配送5
const needAddress = computed(() => {
  const t = currentFreight.value?.pstype
  return t === 0 || t === 2 || t === 5
})

// 需要选门店：自提1 / 门店配送5
const needStore = computed(() => {
  const t = currentFreight.value?.pstype
  return t === 1 || t === 5
})

// 自动发货/卡密限单商品
const autoSendLimitHit = computed(() => {
  const t = currentFreight.value?.pstype
  return (t === 3 || t === 4) && lines.value.length > 1
})

async function loadFreights() {
  try {
    const result = await getFreightList()
    freights.value = (result.list || []).filter((f) => Number(f.status) === 1)
  } catch {
    freights.value = []
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

// ============ 表单 ============
const form = reactive({
  linkman: '',
  tel: '',
  province: '',
  city: '',
  district: '',
  address: '',
  freight_id: 0 as number,
  freightprice: 0,
  mdid: 0 as number,
  paytypeid: 1,
  paytime: '',
})

const payTypes = [
  { id: 1, name: '微信支付' },
  { id: 2, name: '余额支付' },
  { id: 3, name: '支付宝' },
  { id: 4, name: '线下支付' },
  { id: 5, name: '其他' },
]

const totalPrice = computed(() =>
  Math.round((goodsSum.value + (Number(form.freightprice) || 0)) * 100) / 100,
)

const saving = ref(false)

async function handleSave() {
  if (lines.value.length === 0) {
    ElMessage.warning('请至少添加一件商品')
    return
  }
  for (const l of lines.value) {
    if (!(l.num > 0)) {
      ElMessage.warning(`商品「${l.name}」数量需大于 0`)
      return
    }
  }
  if (autoSendLimitHit.value) {
    ElMessage.warning('自动发货/卡密配送仅支持单个商品')
    return
  }
  if (!form.freight_id) {
    ElMessage.warning('请选择配送方式')
    return
  }
  if (needStore.value && !form.mdid) {
    ElMessage.warning('请选择门店')
    return
  }
  if (needAddress.value) {
    if (!form.linkman.trim() || !form.tel.trim()) {
      ElMessage.warning('请填写收货联系人和电话')
      return
    }
    if (!form.address.trim()) {
      ElMessage.warning('请填写收货地址')
      return
    }
  } else if (!form.linkman.trim() || !form.tel.trim()) {
    ElMessage.warning('请填写联系人和电话')
    return
  }

  saving.value = true
  try {
    const result = await lrSaveOrder({
      mid: member.value?.id || 0,
      items: lines.value.map((l) => ({ proid: l.proid, ggid: l.ggid, num: l.num })),
      linkman: form.linkman.trim(),
      tel: form.tel.trim(),
      province: form.province.trim(),
      city: form.city.trim(),
      district: form.district.trim(),
      address: form.address.trim(),
      freight_id: form.freight_id,
      freightprice: Number(form.freightprice) || 0,
      mdid: needStore.value ? Number(form.mdid) || 0 : 0,
      paytype: payTypes.find((p) => p.id === form.paytypeid)?.name || '其他',
      paytypeid: form.paytypeid,
      paytime: form.paytime || '',
    })
    ElMessage.success(`录单成功，订单号 ${result.ordernum}，总额 ¥${Number(result.totalprice).toFixed(2)}`)
    handleReset()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    saving.value = false
  }
}

function handleReset() {
  clearMember()
  lines.value = []
  form.linkman = ''
  form.tel = ''
  form.province = ''
  form.city = ''
  form.district = ''
  form.address = ''
  form.freight_id = 0
  form.freightprice = 0
  form.mdid = 0
  form.paytypeid = 1
  form.paytime = ''
}

onMounted(() => {
  loadFreights()
  loadStores()
})
</script>

<template>
  <div class="order-entry-page">
    <div class="page-card">
      <div class="card-title">录入订单（代客下单）</div>

      <!-- 1. 选会员 -->
      <div class="section-title">1. 选择会员（留空按游客录入）</div>
      <div class="section-body">
        <el-input
          v-model="memberKeyword"
          placeholder="会员ID / 手机号 / 昵称"
          style="width: 220px"
          clearable
          @keyup.enter="handleMemberSearch"
        />
        <el-button type="primary" :loading="memberSearching" @click="handleMemberSearch" style="margin-left: 10px">
          搜索会员
        </el-button>
        <div class="member-card" v-if="member">
          <span class="member-name">{{ member.nickname || '会员' + member.id }}</span>
          <el-tag size="small" v-if="member.levelname">{{ member.levelname }}</el-tag>
          <span class="member-sub">ID：{{ member.id }}</span>
          <span class="member-sub">{{ member.tel }}</span>
          <span class="member-sub">余额：¥{{ Number(member.money).toFixed(2) }}</span>
          <span class="member-sub">积分：{{ member.score }}</span>
          <el-button link type="danger" @click="clearMember">移除</el-button>
          <el-button v-if="addresses.length" link type="primary" @click="addressPickVisible = true">
            选择已有地址
          </el-button>
        </div>
      </div>

      <!-- 2. 添加商品 -->
      <div class="section-title">2. 添加商品</div>
      <div class="section-body">
        <el-button type="primary" plain @click="openGoodsPick">+ 添加商品</el-button>
        <el-table :data="lines" border style="width: 100%; margin-top: 12px" v-if="lines.length">
          <el-table-column prop="name" label="商品" min-width="160" show-overflow-tooltip />
          <el-table-column label="规格" min-width="160">
            <template #default="{ row }">
              <el-select v-model="row.ggid" style="width: 100%" @change="onGuigeChange(row)">
                <el-option
                  v-for="g in row.guigeList"
                  :key="g.id"
                  :label="`${g.name}（库存${g.stock}）`"
                  :value="g.id"
                />
              </el-select>
            </template>
          </el-table-column>
          <el-table-column label="单价" width="110" align="right">
            <template #default="{ row }">
              ¥{{ lineUnitPrice(row).toFixed(2) }}
              <div class="lv-tip" v-if="lineUnitPrice(row) !== row.price">等级价</div>
            </template>
          </el-table-column>
          <el-table-column label="数量" width="130">
            <template #default="{ row }">
              <el-input-number v-model="row.num" :min="1" :max="9999" :step="1" size="small" style="width: 100px" />
            </template>
          </el-table-column>
          <el-table-column label="小计" width="100" align="right">
            <template #default="{ row }">¥{{ (lineUnitPrice(row) * row.num).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="70" align="center">
            <template #default="{ $index }">
              <el-button link type="danger" @click="removeLine($index)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div class="empty-tip" v-else>未添加商品</div>
      </div>

      <!-- 3. 收货信息 -->
      <div class="section-title">3. 收货信息</div>
      <div class="section-body">
        <template v-if="needAddress">
          <el-form label-width="90px" class="sub-form">
            <el-form-item label="联系人">
              <el-input v-model="form.linkman" style="width: 200px" maxlength="50" />
            </el-form-item>
            <el-form-item label="电话">
              <el-input v-model="form.tel" style="width: 200px" maxlength="20" />
            </el-form-item>
            <el-form-item label="地区">
              <el-input v-model="form.province" placeholder="省" style="width: 120px" />
              <el-input v-model="form.city" placeholder="市" style="width: 120px; margin-left: 8px" />
              <el-input v-model="form.district" placeholder="区/县" style="width: 120px; margin-left: 8px" />
            </el-form-item>
            <el-form-item label="详细地址">
              <el-input v-model="form.address" style="width: 420px" maxlength="200" />
            </el-form-item>
          </el-form>
        </template>
        <template v-else>
          <el-form label-width="90px" class="sub-form">
            <el-form-item label="联系人">
              <el-input v-model="form.linkman" style="width: 200px" maxlength="50" />
            </el-form-item>
            <el-form-item label="电话">
              <el-input v-model="form.tel" style="width: 200px" maxlength="20" />
            </el-form-item>
          </el-form>
          <div class="empty-tip">当前配送方式无需收货地址</div>
        </template>
      </div>

      <!-- 4. 配送方式 -->
      <div class="section-title">4. 配送方式与运费</div>
      <div class="section-body">
        <el-form label-width="90px" class="sub-form">
          <el-form-item label="配送方式">
            <el-select v-model="form.freight_id" placeholder="请选择" style="width: 300px">
              <el-option
                v-for="f in freights"
                :key="f.id"
                :label="`${f.name}（${pstypeText[f.pstype] || '配送'}）`"
                :value="f.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="选择门店" v-if="needStore">
            <el-select v-model="form.mdid" placeholder="请选择门店" style="width: 300px">
              <el-option v-for="s in stores" :key="s.id" :label="s.name" :value="s.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="运费">
            <el-input-number
              v-model="form.freightprice"
              :min="0"
              :max="99999"
              :precision="2"
              :step="1"
              style="width: 160px"
            />
            <span class="form-tip">免运费填 0，可手工录入实际收取的运费</span>
          </el-form-item>
        </el-form>
        <div class="warn-tip" v-if="autoSendLimitHit">自动发货/卡密配送仅支持单个商品，请调整商品或配送方式</div>
      </div>

      <!-- 5. 支付信息 -->
      <div class="section-title">5. 支付信息</div>
      <div class="section-body">
        <el-form label-width="90px" class="sub-form">
          <el-form-item label="支付方式">
            <el-select v-model="form.paytypeid" style="width: 200px">
              <el-option v-for="p in payTypes" :key="p.id" :label="p.name" :value="p.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="支付时间">
            <el-date-picker
              v-model="form.paytime"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="留空按当前时间（可补录历史订单）"
              style="width: 280px"
            />
          </el-form-item>
        </el-form>
      </div>

      <!-- 6. 汇总 -->
      <div class="summary-bar">
        <span>商品合计：¥{{ goodsSum.toFixed(2) }}</span>
        <span>运费：¥{{ (Number(form.freightprice) || 0).toFixed(2) }}</span>
        <span class="total">订单总额：¥{{ totalPrice.toFixed(2) }}</span>
        <el-button type="primary" size="large" :loading="saving" @click="handleSave">保存订单</el-button>
        <el-button size="large" @click="handleReset">重置</el-button>
      </div>

      <div class="page-note">
        <p>说明：</p>
        <p>1. 未选择会员时按游客录入，不享受等级价；选择会员后开启等级价的商品自动按会员等级取价。</p>
        <p>2. 录入的订单直接为「已付款」状态，支付方式与支付时间可补录历史数据，订单来源标记为后台录入。</p>
        <p>3. 到店自提/门店配送需选择门店；自动发货与卡密配送仅支持单商品订单。</p>
      </div>
    </div>

    <!-- 会员候选弹窗 -->
    <el-dialog v-model="memberPickVisible" title="选择会员" width="560px">
      <el-table :data="memberOptions" border>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="nickname" label="昵称" min-width="120" show-overflow-tooltip />
        <el-table-column prop="tel" label="手机号" width="130" />
        <el-table-column label="余额" width="100" align="right">
          <template #default="{ row }">¥{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="primary" @click="selectMember(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <!-- 地址候选弹窗 -->
    <el-dialog v-model="addressPickVisible" title="选择收货地址" width="620px">
      <el-table :data="addresses" border max-height="360">
        <el-table-column prop="name" label="收货人" width="100" show-overflow-tooltip />
        <el-table-column prop="tel" label="电话" width="130" />
        <el-table-column label="地址" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.province }}{{ row.city }}{{ row.district }} {{ row.address }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="primary" @click="applyAddress(row)">使用</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <!-- 商品候选弹窗 -->
    <el-dialog v-model="goodsPickVisible" title="添加商品" width="720px">
      <div class="goods-pick-bar">
        <el-input
          v-model="goodsKeyword"
          placeholder="商品名称 / 编码"
          clearable
          style="width: 240px"
          @keyup.enter="goodsPage = 1; loadGoods()"
        />
        <el-button type="primary" @click="goodsPage = 1; loadGoods()">搜索</el-button>
      </div>
      <el-table :data="goodsList" v-loading="goodsLoading" border style="margin-top: 10px">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="name" label="商品名称" min-width="180" show-overflow-tooltip />
        <el-table-column label="售价" width="90" align="right">
          <template #default="{ row }">¥{{ Number(row.sell_price).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="stock" label="库存" width="80" align="right" />
        <el-table-column label="等级价" width="80" align="center">
          <template #default="{ row }">
            <el-tag v-if="Number(row.lvprice) === 1" size="small" type="warning">开启</el-tag>
            <span v-else class="no-op">—</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="primary" @click="pickGoods(row)">添加</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination
          v-model:current-page="goodsPage"
          :total="goodsTotal"
          :page-size="10"
          layout="total, prev, pager, next"
          @current-change="loadGoods"
        />
      </div>
    </el-dialog>
  </div>
</template>

<style scoped>
.order-entry-page {
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
.section-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  margin: 18px 0 10px;
  padding-left: 8px;
  border-left: 3px solid #409eff;
}
.section-body {
  padding: 0 8px;
}
.sub-form {
  max-width: 860px;
}
.form-tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
.warn-tip {
  color: #f56c6c;
  font-size: 13px;
  margin-top: 6px;
}
.empty-tip {
  color: #909399;
  font-size: 13px;
  margin-top: 10px;
}
.member-card {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  margin-left: 16px;
  padding: 6px 14px;
  background: #f0f9eb;
  border: 1px solid #e1f3d8;
  border-radius: 4px;
}
.member-name {
  font-weight: 600;
  color: #303133;
}
.member-sub {
  color: #909399;
  font-size: 13px;
}
.lv-tip {
  font-size: 11px;
  color: #e6a23c;
}
.no-op {
  color: #c0c4cc;
}
.summary-bar {
  display: flex;
  align-items: center;
  gap: 24px;
  margin-top: 20px;
  padding: 14px 16px;
  background: #f7f8fa;
  border-radius: 4px;
  font-size: 14px;
  color: #606266;
}
.summary-bar .total {
  font-size: 16px;
  font-weight: 600;
  color: #f56c6c;
}
.page-note {
  margin-top: 12px;
  padding: 12px 14px;
  background: #f7f8fa;
  border-radius: 4px;
  color: #909399;
  font-size: 12px;
  line-height: 1.9;
  max-width: 1040px;
}
.page-note p {
  margin: 0;
}
.goods-pick-bar {
  display: flex;
  gap: 8px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 10px;
}
</style>
