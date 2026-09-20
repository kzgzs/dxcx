<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Setting } from '@element-plus/icons-vue'
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
import { areaProvinces } from '../../utils/areaData'
import { assetUrl } from '../../api/upload'

// ============ 会员 ============
const member = ref<LrMember | null>(null)
const midInput = ref('')
const memberPickVisible = ref(false)
const memberKeyword = ref('')
const memberOptions = ref<LrMember[]>([])
const memberSearching = ref(false)

function openMemberPick() {
  memberPickVisible.value = true
  memberKeyword.value = member.value ? String(member.value.id) : ''
  if (memberKeyword.value) searchMembers()
}

async function searchMembers() {
  const kw = memberKeyword.value.trim()
  if (!kw) {
    ElMessage.warning('请输入会员ID / 手机号 / 昵称')
    return
  }
  memberSearching.value = true
  try {
    const result = await lrGetUser({ keyword: kw })
    memberOptions.value = result.list || []
    if (memberOptions.value.length === 0) ElMessage.info('未找到会员')
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    memberSearching.value = false
  }
}

function selectMember(m: LrMember) {
  member.value = m
  midInput.value = String(m.id)
  memberPickVisible.value = false
  loadAddresses()
}

function clearMember() {
  member.value = null
  midInput.value = ''
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
    if (addresses.value.length) addressPickVisible.value = true
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

// 会员等级价
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

const currentFreight = computed(() =>
  freights.value.find((f) => f.id === form.freight_id) || null,
)

const needAddress = computed(() => {
  const t = currentFreight.value?.pstype
  return t === 0 || t === 2 || t === 5
})
const needStore = computed(() => {
  const t = currentFreight.value?.pstype
  return t === 1 || t === 5
})
const autoSendLimitHit = computed(() => {
  const t = currentFreight.value?.pstype
  return (t === 3 || t === 4) && lines.value.length > 1
})

async function loadFreights() {
  try {
    const result = await getFreightList()
    const list = (result.list || []).filter((f) => Number(f.status) === 1)
    freights.value = list
    if (list.length) form.freight_id = list[0].id
  } catch {
    freights.value = []
  }
}

async function loadStores() {
  try {
    const result = await getStoreList({ page: 1, pagesize: 100 })
    stores.value = (result.list || []).map((s) => ({ id: s.id, name: s.name }))
    if (stores.value.length) form.mdid = stores.value[0].id
  } catch {
    stores.value = []
  }
}

// ============ 省市区三级联动 ============
const provinceOptions = areaProvinces.map((p) => p.name)
const cityOptions = computed(() => {
  const p = areaProvinces.find((x) => x.name === form.province)
  return p ? p.cityList.map((c) => c.name) : []
})
const areaOptions = computed(() => {
  const p = areaProvinces.find((x) => x.name === form.province)
  const c = p?.cityList.find((x) => x.name === form.city)
  return c ? c.areaList : []
})
function onProvinceChange() {
  form.city = ''
  form.district = ''
}
function onCityChange() {
  form.district = ''
}

// ============ 表单 ============
function nowText() {
  const d = new Date()
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

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
  paytypeName: '线下支付',
  paytime: nowText(),
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
    ElMessage.warning('请先选择商品')
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
  if (!form.linkman.trim() || !form.tel.trim()) {
    ElMessage.warning('请填写收货人姓名和电话')
    return
  }
  if (needAddress.value && !form.address.trim()) {
    ElMessage.warning('请填写收货地址')
    return
  }

  const mid = member.value?.id || Number(midInput.value) || 0
  const payName = form.paytypeName.trim() || '线下支付'
  const matched = payTypes.find((p) => p.name === payName)

  saving.value = true
  try {
    const result = await lrSaveOrder({
      mid,
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
      paytype: payName,
      paytypeid: matched?.id ?? 5,
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
  form.freightprice = 0
  form.mdid = stores.value[0]?.id || 0
  form.paytypeName = '线下支付'
  form.paytime = nowText()
  loadFreights()
}

onMounted(() => {
  loadFreights()
  loadStores()
})
</script>

<template>
  <div class="order-entry-page">
    <el-card shadow="never" class="entry-card">
      <div class="entry-title"><el-icon><Setting /></el-icon>录入订单</div>
      <el-form label-width="110px" class="entry-form">
        <!-- 会员ID -->
        <el-form-item label="会员ID：">
          <el-input v-model="midInput" placeholder="会员ID" style="width: 200px" clearable />
          <el-button class="ml-btn" @click="openMemberPick">选择会员</el-button>
          <span v-if="member" class="member-info">
            {{ member.nickname || '会员' + member.id }}
            <em v-if="member.levelname">{{ member.levelname }}</em>
            余额 ¥{{ Number(member.money).toFixed(2) }}
            <el-button link type="danger" @click="clearMember">移除</el-button>
          </span>
        </el-form-item>

        <!-- 选择商品 -->
        <el-form-item label="选择商品：">
          <el-button @click="openGoodsPick">选择</el-button>
        </el-form-item>

        <!-- 已选商品 -->
        <el-form-item label="已选商品：">
          <div class="product-box">
            <div v-if="!lines.length" class="product-empty">暂无已选商品，请点击上方「选择」</div>
            <div v-for="(line, idx) in lines" :key="idx" class="product-row">
              <el-image :src="assetUrl(line.pic)" class="product-img" fit="cover">
                <template #error><div class="img-fallback">无图</div></template>
              </el-image>
              <div class="product-meta">
                <div class="product-name" :title="line.name">{{ line.name }}</div>
                <div class="product-sub">
                  <el-select
                    v-model="line.ggid"
                    size="small"
                    style="width: 220px"
                    @change="onGuigeChange(line)"
                  >
                    <el-option
                      v-for="g in line.guigeList"
                      :key="g.id"
                      :label="`${g.name}（库存${g.stock}）`"
                      :value="g.id"
                    />
                  </el-select>
                </div>
              </div>
              <div class="product-price">¥{{ lineUnitPrice(line).toFixed(2) }}</div>
              <el-input-number
                v-model="line.num"
                :min="1"
                :max="9999"
                :step="1"
                size="small"
                controls-position="right"
                class="product-num"
              />
              <el-button link type="danger" size="small" @click="removeLine(idx)">删除</el-button>
            </div>
          </div>
        </el-form-item>

        <!-- 商品总价 -->
        <el-form-item label="商品总价：">
          <el-input :model-value="goodsSum.toFixed(2)" readonly style="width: 200px" />
        </el-form-item>

        <!-- 收货人 -->
        <el-form-item label="收货人姓名：">
          <el-input v-model="form.linkman" maxlength="50" style="width: 200px" />
          <el-button
            v-if="addresses.length"
            link
            type="primary"
            class="ml-btn"
            @click="addressPickVisible = true"
          >
            选择已有地址
          </el-button>
        </el-form-item>
        <el-form-item label="收货人电话：">
          <el-input v-model="form.tel" maxlength="20" style="width: 200px" />
        </el-form-item>

        <!-- 地区 -->
        <el-form-item label="地区：">
          <el-select v-model="form.province" placeholder="请选择省" style="width: 150px" @change="onProvinceChange">
            <el-option v-for="n in provinceOptions" :key="n" :label="n" :value="n" />
          </el-select>
          <el-select v-model="form.city" placeholder="请选择市" style="width: 150px; margin-left: 8px" @change="onCityChange">
            <el-option v-for="n in cityOptions" :key="n" :label="n" :value="n" />
          </el-select>
          <el-select v-model="form.district" placeholder="请选择地区" style="width: 150px; margin-left: 8px">
            <el-option v-for="n in areaOptions" :key="n" :label="n" :value="n" />
          </el-select>
        </el-form-item>

        <!-- 收货地址 -->
        <el-form-item label="收货地址：">
          <el-input v-model="form.address" maxlength="200" style="width: 500px" />
        </el-form-item>

        <!-- 配送方式 -->
        <el-form-item label="配送方式：">
          <el-radio-group v-model="form.freight_id">
            <el-radio v-for="f in freights" :key="f.id" :value="f.id">{{ f.name }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="needStore" label="选择门店：">
          <el-radio-group v-model="form.mdid">
            <el-radio v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="配送费：">
          <el-input-number
            v-model="form.freightprice"
            :min="0"
            :max="99999"
            :precision="2"
            :step="1"
            controls-position="right"
            style="width: 200px"
          />
        </el-form-item>

        <!-- 支付 -->
        <el-form-item label="支付方式：">
          <el-input v-model="form.paytypeName" style="width: 200px" />
        </el-form-item>
        <el-form-item label="付款时间：">
          <el-date-picker
            v-model="form.paytime"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="付款时间"
            style="width: 220px"
          />
        </el-form-item>
        <el-form-item label="总金额：">
          <el-input :model-value="totalPrice.toFixed(2)" readonly style="width: 200px" />
        </el-form-item>
        <el-form-item v-if="autoSendLimitHit">
          <span class="warn-tip">自动发货/卡密配送仅支持单个商品，请调整商品或配送方式</span>
        </el-form-item>
        <el-form-item>
          <el-button type="success" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 选择会员弹窗 -->
    <el-dialog v-model="memberPickVisible" title="选择会员" width="600px">
      <div class="pick-bar">
        <el-input
          v-model="memberKeyword"
          placeholder="会员ID / 手机号 / 昵称"
          clearable
          style="width: 260px"
          @keyup.enter="searchMembers"
        />
        <el-button type="primary" :loading="memberSearching" @click="searchMembers">搜索</el-button>
      </div>
      <el-table :data="memberOptions" border style="margin-top: 10px">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="nickname" label="昵称" min-width="120" show-overflow-tooltip />
        <el-table-column prop="tel" label="手机号" width="140" />
        <el-table-column label="余额" width="100">
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

    <!-- 选择商品弹窗 -->
    <el-dialog v-model="goodsPickVisible" title="选择商品" width="720px">
      <div class="pick-bar">
        <el-input
          v-model="goodsKeyword"
          placeholder="商品名称 / 编码"
          clearable
          style="width: 260px"
          @keyup.enter="goodsPage = 1; loadGoods()"
        />
        <el-button type="primary" @click="goodsPage = 1; loadGoods()">搜索</el-button>
      </div>
      <el-table :data="goodsList" v-loading="goodsLoading" border style="margin-top: 10px">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="商品名称" min-width="180" show-overflow-tooltip />
        <el-table-column label="售价" width="100">
          <template #default="{ row }">¥{{ Number(row.sell_price).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="stock" label="库存" width="80" />
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="primary" @click="pickGoods(row)">选择</el-button>
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
  padding: 0;
}
.entry-card :deep(.el-card__body) {
  padding: 15px !important;
}
.entry-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding-bottom: 10px;
  margin-bottom: 12px;
  border-bottom: 1px solid #ebeef5;
}
.entry-form {
  max-width: 860px;
}
.ml-btn {
  margin-left: 10px;
}
.member-info {
  margin-left: 12px;
  font-size: 13px;
  color: #606266;
}
.member-info em {
  font-style: normal;
  color: #e6a23c;
  margin: 0 8px;
}
.product-box {
  width: 560px;
  min-height: 38px;
  border: 1px solid #f1f1f1;
  background: #fff;
}
.product-empty {
  padding: 9px 10px;
  color: #c0c4cc;
  font-size: 13px;
}
.product-row {
  display: flex;
  align-items: center;
  padding: 8px 10px;
  border-bottom: 1px dashed #e5e5e5;
  gap: 10px;
}
.product-row:last-child {
  border-bottom: 0;
}
.product-img {
  width: 50px;
  height: 50px;
  border-radius: 4px;
  background: #f5f7fa;
  flex-shrink: 0;
}
.product-meta {
  flex: 1;
  min-width: 0;
}
.product-name {
  font-size: 13px;
  color: #303133;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: 4px;
}
.product-price {
  color: #ff4246;
  font-size: 13px;
  width: 70px;
  text-align: right;
  flex-shrink: 0;
}
.product-num {
  width: 120px;
  flex-shrink: 0;
}
.img-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 12px;
}
.warn-tip {
  color: #f56c6c;
  font-size: 13px;
}
.pick-bar {
  display: flex;
  gap: 8px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 10px;
}
</style>
