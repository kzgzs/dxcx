<script setup lang="ts">
import { computed, reactive, ref, watch, watchEffect } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { MagicStick } from '@element-plus/icons-vue'
import type { GoodsSpecGroup, GoodsSkuOption, GoodsSpecValue } from '../api/types'

/**
 * 共享规格编辑器（设置规格 + 刷新规格项目表 / 笛卡尔积）
 *
 * 契约：
 * - v-model:groups  规格分组配置（GoodsSpecGroup[]，组内 items 为规格值）
 * - v-model:skus    SKU 映射，键为组合索引串 ks（如 "0,1"），值为该组合 SKU
 * - :default-sku    新建 SKU 行时的种子值（一般传父表单的 price/stock 等）
 * - @pick-image     (currentUrl, done) => void；父级打开选图弹窗后调用 done(url) 回填
 *
 * 暴露：
 * - specMode / validGroups / skuRows  状态
 * - refreshSpec()                     依据当前规格组补齐 SKU 行（已填按 ks 保留）
 * - buildMultiSpecPayload(params)     多规格时校验并填充 params.specs/options/stock/price/market_price；单规格返回 true
 * - rowsToSkuList()                   按 skuRows 顺序导出 [{names, sku}]，供各页映射自有后端字段
 */
const props = withDefaults(
  defineProps<{
    groups: GoodsSpecGroup[]
    skus: Record<string, GoodsSkuOption>
    defaultSku?: Partial<GoodsSkuOption>
    showCostPrice?: boolean
    showGivescore?: boolean
    showWeight?: boolean
    showBulk?: boolean
  }>(),
  {
    defaultSku: () => ({}),
    showCostPrice: true,
    showGivescore: true,
    showWeight: true,
    showBulk: true,
  },
)

const emit = defineEmits<{
  (e: 'update:groups', v: GoodsSpecGroup[]): void
  (e: 'update:skus', v: Record<string, GoodsSkuOption>): void
  (e: 'pick-image', currentUrl: string, done: (url: string) => void): void
}>()

// 单/多规格由规格组结构自动判定
const specMode = ref<'single' | 'multi'>('single')

const validGroups = computed(() =>
  props.groups
    .filter((g) => g.title.trim() !== '')
    .map((g) => ({
      title: g.title.trim(),
      items: g.items.filter((it: GoodsSpecValue) => it.title.trim() !== ''),
    }))
    .filter((g) => g.items.length > 0),
)

watch(
  () => props.groups,
  () => {
    const valid = validGroups.value
    const total = valid.reduce((s, g) => s + g.items.length, 0)
    specMode.value = valid.length > 1 || total > 1 ? 'multi' : 'single'
  },
  { deep: true, immediate: true },
)

// 笛卡尔积：行 = 规格值全组合
const skuRows = computed(() => {
  const groups = validGroups.value
  if (groups.length === 0) return []
  const rows: { ks: string; names: string[] }[] = []
  const walk = (gi: number, ksArr: number[], names: string[]) => {
    if (gi === groups.length) {
      rows.push({ ks: ksArr.join(','), names: [...names] })
      return
    }
    groups[gi].items.forEach((it: GoodsSpecValue, vi: number) => {
      walk(gi + 1, [...ksArr, vi], [...names, it.title])
    })
  }
  walk(0, [], [])
  return rows
})

function cellSpan(rowIndex: number, groupIndex: number): number {
  const groups = validGroups.value
  let span = 1
  for (let g = groupIndex + 1; g < groups.length; g++) {
    span *= groups[g].items.length
  }
  return rowIndex % span === 0 ? span : 0
}

function skuOf(ks: string, names: string[]): GoodsSkuOption {
  if (!props.skus[ks]) {
    const d = props.defaultSku
    props.skus[ks] = {
      name: names.join(' '),
      pic: '',
      market_price: Number(d.market_price) || 0,
      cost_price: Number(d.cost_price) || 0,
      sell_price: Number(d.sell_price) || 0,
      weight: Number(d.weight) || 0,
      stock: Number(d.stock) || 0,
      givescore: Number(d.givescore) || 0,
      procode: '',
      barcode: '',
    }
  }
  return props.skus[ks]
}

watchEffect(() => {
  for (const row of skuRows.value) {
    skuOf(row.ks, row.names)
  }
})

// ===== 设置规格表操作 =====
function addGroup() {
  props.groups.push({ title: '', items: [{ title: '' }] })
  emit('update:groups', props.groups)
}
function removeGroup(index: number) {
  props.groups.splice(index, 1)
  emit('update:groups', props.groups)
}
function addItem(group: GoodsSpecGroup) {
  group.items.push({ title: '' })
}
function removeItem(group: GoodsSpecGroup, index: number) {
  group.items.splice(index, 1)
}

// ===== 批量设置 =====
const bulk = reactive({
  market_price: undefined as number | undefined,
  sell_price: undefined as number | undefined,
  cost_price: undefined as number | undefined,
  stock: undefined as number | undefined,
})
function applyBulk() {
  const fields: (keyof typeof bulk)[] = ['market_price', 'sell_price', 'cost_price', 'stock']
  const hasValue = fields.some((f) => bulk[f] !== undefined && bulk[f] !== null)
  if (!hasValue) {
    ElMessage.warning('请先填写要批量设置的值')
    return
  }
  for (const row of skuRows.value) {
    const sku = skuOf(row.ks, row.names)
    for (const f of fields) {
      if (bulk[f] !== undefined && bulk[f] !== null) {
        ;(sku as unknown as Record<string, number>)[f] = Number(bulk[f])
      }
    }
  }
  ElMessage.success('已应用到全部规格行')
}

// ===== 刷新规格项目表 =====
function refreshSpec() {
  skuRows.value.forEach((r) => skuOf(r.ks, r.names))
  ElMessage.success('规格项目表已更新')
}

// ===== 规格编号自动生成（口径同点大 canzhao：randomString(6)，去易混字符） =====
const PROCODE_CHARS = 'ABCDEFGHJKMNPQRSTWXYZabcdefhijkmnprstwxyz2345678'
function randomCode(len = 6): string {
  let s = ''
  for (let i = 0; i < len; i++) s += PROCODE_CHARS.charAt(Math.floor(Math.random() * PROCODE_CHARS.length))
  return s
}
// 收集当前表内已用编号，保证同表唯一
function usedProcodes(): Set<string> {
  const set = new Set<string>()
  for (const row of skuRows.value) {
    const p = skuOf(row.ks, row.names).procode
    if (p) set.add(p)
  }
  return set
}
function uniqueProcode(exclude?: string): string {
  const used = usedProcodes()
  if (exclude) used.delete(exclude)
  let code = randomCode(6)
  let guard = 0
  while (used.has(code) && guard < 20) {
    code = randomCode(6)
    guard++
  }
  return code
}
// 单行生成：空行直接填；已有编号需确认后覆盖
function genRowProcode(sku: GoodsSkuOption) {
  if (sku.procode) {
    ElMessageBox.confirm('该规格已有编号，是否重新生成？', '提示', { type: 'warning', confirmButtonText: '重新生成', cancelButtonText: '取消' })
      .then(() => { sku.procode = uniqueProcode(sku.procode) })
      .catch(() => {})
    return
  }
  sku.procode = uniqueProcode()
}
// 一键生成全部空编号
function genAllEmptyProcodes() {
  let n = 0
  for (const row of skuRows.value) {
    const sku = skuOf(row.ks, row.names)
    if (!sku.procode) {
      sku.procode = uniqueProcode()
      n++
    }
  }
  if (n) ElMessage.success(`已为 ${n} 条空编号生成货号`)
  else ElMessage.info('所有规格行均已有编号')
}

// 多规格载荷：校验销售价、汇总库存/最低价/最高市场价
function buildMultiSpecPayload(params: Record<string, unknown>): boolean {
  if (specMode.value !== 'multi') {
    params.specs = []
    params.options = {}
    return true
  }
  const groups = validGroups.value
  if (groups.length === 0) {
    ElMessage.warning('请至少填写一组规格及规格值')
    return false
  }
  const skuList = skuRows.value
  const options: Record<string, GoodsSkuOption> = {}
  let totalStock = 0
  let minSell = Infinity
  let maxMarket = 0
  for (const row of skuList) {
    const sku = skuOf(row.ks, row.names)
    if (!(Number(sku.sell_price) > 0)) {
      ElMessage.warning(`规格「${row.names.join(' ')}」未填写销售价`)
      return false
    }
    sku.name = row.names.join(' ')
    options[row.ks] = { ...sku }
    totalStock += Number(sku.stock) || 0
    minSell = Math.min(minSell, Number(sku.sell_price))
    maxMarket = Math.max(maxMarket, Number(sku.market_price) || 0)
  }
  params.specs = groups.map((g) => ({
    title: g.title,
    items: g.items.map((it: GoodsSpecValue) => ({ title: it.title, ggpic_wholesale: it.ggpic_wholesale || '' })),
  }))
  params.options = options
  params.stock = totalStock
  params.price = minSell
  params.market_price = maxMarket
  return true
}

// 按笛卡尔积顺序导出行（供各页映射自有后端字段，如 guiges / options 数组）
function rowsToSkuList(): { names: string[]; sku: GoodsSkuOption }[] {
  return skuRows.value.map((r) => ({ names: r.names, sku: skuOf(r.ks, r.names) }))
}

function pickValPic(item: GoodsSpecGroup['items'][number]) {
  emit('pick-image', item.ggpic_wholesale || '', (url: string) => {
    item.ggpic_wholesale = url
  })
}
function pickRowPic(sku: GoodsSkuOption) {
  emit('pick-image', sku.pic || '', (url: string) => {
    sku.pic = url
  })
}

defineExpose({
  specMode,
  validGroups,
  skuRows,
  refreshSpec,
  buildMultiSpecPayload,
  rowsToSkuList,
  skuOf,
})
</script>

<template>
  <div class="goods-spec-editor">
    <!-- 设置规格表 -->
    <table class="gg-table">
      <thead>
        <tr>
          <th style="width: 150px">规格分组</th>
          <th>规格名称</th>
          <th style="width: 90px">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(group, gi) in groups" :key="gi">
          <td>
            <el-input v-model="group.title" style="width: 120px" placeholder="规格分组" />
          </td>
          <td>
            <div v-for="(item, ii) in group.items" :key="ii" class="gg-name-line">
              <el-input v-model="item.title" style="width: 180px" placeholder="请输入规格名称" />
              <el-button size="small" @click="addItem(group)">+</el-button>
              <el-button v-if="ii > 0" size="small" type="danger" plain @click="removeItem(group, ii)">×</el-button>
              <el-image
                v-if="item.ggpic_wholesale"
                :src="item.ggpic_wholesale"
                fit="cover"
                class="spec-value-pic"
                @click="pickValPic(item)"
              />
              <el-button v-else size="small" link type="primary" @click="pickValPic(item)">规格图</el-button>
            </div>
          </td>
          <td>
            <el-button v-if="gi === 0" size="small" @click="addGroup">添加</el-button>
            <el-button v-else size="small" type="danger" plain @click="removeGroup(gi)">删除</el-button>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="ge-block-hint">
      提示：先点击“添加”添加规格分组，如：“颜色”，“尺码”，若只有一种规格则不需要添加规格分组；<br />
      然后分别点击“+”号添加规格名称，如：“红色”，“蓝色”；“大号”，“小号”；<br />
      设置完成后点击“更新规格项目表”按钮进行编辑各个规格的价格、库存等信息
    </div>

    <div class="spec-actions">
      <el-button @click="refreshSpec">更新规格项目表</el-button>
      <el-button :icon="MagicStick" plain @click="genAllEmptyProcodes">生成空编号</el-button>
      <slot name="actions" />
    </div>

    <!-- 批量设置 -->
    <div v-if="showBulk && specMode === 'multi' && skuRows.length > 0" class="sku-bulk">
      <span class="sku-bulk-label">批量设置：</span>
      <el-input-number v-model="bulk.market_price" :min="0" :precision="2" :controls="false" placeholder="市场价" style="width: 100px" />
      <el-input-number v-model="bulk.sell_price" :min="0" :precision="2" :controls="false" placeholder="销售价" style="width: 100px" />
      <el-input-number v-if="showCostPrice" v-model="bulk.cost_price" :min="0" :precision="2" :controls="false" placeholder="成本价" style="width: 100px" />
      <el-input-number v-model="bulk.stock" :min="0" :controls="false" placeholder="库存" style="width: 100px" />
      <el-button type="primary" plain @click="applyBulk">应用到全部行</el-button>
    </div>

    <!-- SKU 项目表（笛卡尔积自动生成，无手工加行） -->
    <div class="sku-table-wrap">
      <table class="sku-table">
        <thead>
          <tr>
            <template v-if="specMode === 'multi'">
              <th v-for="g in validGroups" :key="g.title">{{ g.title }}</th>
            </template>
            <th v-else>规格</th>
            <th>规格编号</th>
            <th>市场价(元)</th>
            <th v-if="showCostPrice">成本价(元)</th>
            <th>销售价(元)</th>
            <th v-if="showWeight">重量(克)</th>
            <th>库存</th>
            <th v-if="showGivescore">赠积分(个)</th>
            <th>图片</th>
          </tr>
        </thead>
        <tbody>
          <!-- 单规格行：默认绑定 skus['0']；父级可用 #single-row 插槽接管（如 Goods 绑定主表单） -->
          <tr v-if="specMode === 'single'">
            <slot name="single-row">
              <td class="sku-spec-cell">默认规格</td>
              <td>
                <div class="procode-cell">
                  <el-input v-model="skuOf('0', ['默认规格']).procode" placeholder="编号" style="width: 96px" />
                  <el-tooltip content="自动生成编号" placement="top">
                    <el-button size="small" :icon="MagicStick" class="procode-gen-btn" @click="genRowProcode(skuOf('0', ['默认规格']))" />
                  </el-tooltip>
                </div>
              </td>
              <td><el-input-number v-model="skuOf('0', ['默认规格']).market_price" :min="0" :precision="2" :controls="false" style="width: 90px" /></td>
              <td v-if="showCostPrice"><el-input-number v-model="skuOf('0', ['默认规格']).cost_price" :min="0" :precision="2" :controls="false" style="width: 90px" /></td>
              <td><el-input-number v-model="skuOf('0', ['默认规格']).sell_price" :min="0" :precision="2" :controls="false" style="width: 90px" /></td>
              <td v-if="showWeight"><el-input-number v-model="skuOf('0', ['默认规格']).weight" :min="0" :controls="false" style="width: 80px" /></td>
              <td><el-input-number v-model="skuOf('0', ['默认规格']).stock" :min="0" :controls="false" style="width: 80px" /></td>
              <td v-if="showGivescore"><el-input-number v-model="skuOf('0', ['默认规格']).givescore" :min="0" :controls="false" style="width: 80px" /></td>
              <td>
                <el-image
                  v-if="skuOf('0', ['默认规格']).pic"
                  :src="skuOf('0', ['默认规格']).pic"
                  fit="cover"
                  class="sku-pic"
                  @click="pickRowPic(skuOf('0', ['默认规格']))"
                />
                <span v-else class="sku-pic-empty" @click="pickRowPic(skuOf('0', ['默认规格']))">上传</span>
              </td>
            </slot>
          </tr>
          <tr v-for="(row, ri) in skuRows" v-else :key="row.ks">
            <template v-for="(name, gi) in row.names" :key="gi">
              <td v-if="cellSpan(ri, gi) > 0" :rowspan="cellSpan(ri, gi)" class="sku-spec-cell">{{ name }}</td>
            </template>
            <td>
              <div class="procode-cell">
                <el-input v-model="skuOf(row.ks, row.names).procode" placeholder="编号" style="width: 96px" />
                <el-tooltip content="自动生成编号" placement="top">
                  <el-button size="small" :icon="MagicStick" class="procode-gen-btn" @click="genRowProcode(skuOf(row.ks, row.names))" />
                </el-tooltip>
              </div>
            </td>
            <td><el-input-number v-model="skuOf(row.ks, row.names).market_price" :min="0" :precision="2" :controls="false" style="width: 90px" /></td>
            <td v-if="showCostPrice"><el-input-number v-model="skuOf(row.ks, row.names).cost_price" :min="0" :precision="2" :controls="false" style="width: 90px" /></td>
            <td><el-input-number v-model="skuOf(row.ks, row.names).sell_price" :min="0" :precision="2" :controls="false" style="width: 90px" /></td>
            <td v-if="showWeight"><el-input-number v-model="skuOf(row.ks, row.names).weight" :min="0" :controls="false" style="width: 80px" /></td>
            <td><el-input-number v-model="skuOf(row.ks, row.names).stock" :min="0" :controls="false" style="width: 80px" /></td>
            <td v-if="showGivescore"><el-input-number v-model="skuOf(row.ks, row.names).givescore" :min="0" :controls="false" style="width: 80px" /></td>
            <td>
              <el-image
                v-if="skuOf(row.ks, row.names).pic"
                :src="skuOf(row.ks, row.names).pic"
                fit="cover"
                class="sku-pic"
                @click="pickRowPic(skuOf(row.ks, row.names))"
              />
              <span v-else class="sku-pic-empty" @click="pickRowPic(skuOf(row.ks, row.names))">上传</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <slot name="footer" />
  </div>
</template>

<style scoped>
.goods-spec-editor {
  width: 100%;
}
.spec-actions {
  margin: 10px 0;
}
.procode-cell {
  display: flex;
  align-items: center;
  gap: 2px;
}
.procode-gen-btn {
  flex: none;
  padding: 4px;
}
.spec-value-pic {
  width: 32px;
  height: 32px;
  border-radius: 4px;
  cursor: pointer;
}
.sku-bulk {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 10px;
  flex-wrap: wrap;
}
.sku-bulk-label {
  color: #606266;
  font-size: 13px;
}
.sku-table-wrap {
  width: 100%;
  overflow-x: auto;
}
.sku-table {
  border-collapse: collapse;
  width: 100%;
  font-size: 13px;
}
.sku-table th,
.sku-table td {
  border: 1px solid #dcdfe6;
  padding: 6px 8px;
  text-align: center;
  white-space: nowrap;
}
.sku-table th {
  background: #f5f7fa;
  color: #606266;
  font-weight: 600;
}
.sku-spec-cell {
  background: #fafafa;
  font-weight: 500;
}
.sku-pic {
  width: 40px;
  height: 40px;
  border-radius: 4px;
  cursor: pointer;
}
.sku-pic-empty {
  color: #409eff;
  cursor: pointer;
  font-size: 12px;
}
.ge-block-hint {
  color: #909399;
  font-size: 12px;
  line-height: 1.9;
  width: 100%;
}
.gg-table {
  border-collapse: collapse;
  width: 100%;
  max-width: 760px;
  font-size: 13px;
}
.gg-table th,
.gg-table td {
  border: 1px solid #dcdfe6;
  padding: 8px;
  text-align: left;
  vertical-align: top;
}
.gg-table th {
  background: #f5f7fa;
  color: #606266;
  font-weight: 600;
  text-align: center;
}
.gg-name-line {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}
.gg-name-line:last-child {
  margin-bottom: 0;
}
</style>
