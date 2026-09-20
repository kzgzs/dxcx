<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { getGoodsSales } from '../../api/stat'
import { getFreightList } from '../../api/tenantSet'
import { getStoreList } from '../../api/store'
import { assetUrl } from '../../api/upload'
import type {
  FreightItem,
  GoodsSalesRow,
  GoodsSalesTotal,
  StoreItem,
} from '../../api/types'

// 统计维度：0=按商品统计，2=按规格统计（对齐参考系统）
const activeType = ref<number>(0)

const loading = ref(false)
const exporting = ref(false)
const rows = ref<GoodsSalesRow[]>([])
const total = ref(0)
const totalData = ref<GoodsSalesTotal>({ num: 0, totalprice: 0, chengben: 0, lirun: 0 })

const page = ref(1)
const pagesize = ref(15)
const sortField = ref('totalprice')
const sortOrder = ref<'asc' | 'desc'>('desc')

// 筛选项
const filters = reactive({
  mdid: '' as number | '',
  freight_id: '' as number | '',
  mid: '' as number | '',
  ctime: null as [string, string] | null,
  paytime: null as [string, string] | null,
})

const storeList = ref<StoreItem[]>([])
const freightList = ref<FreightItem[]>([])

// 组装查询参数
function buildParams(exportAll = false): Record<string, number | string> {
  const p: Record<string, number | string> = {
    type: activeType.value,
    page: page.value,
    limit: pagesize.value,
    field: sortField.value,
    order: sortOrder.value,
  }
  if (filters.mdid !== '') p.mdid = Number(filters.mdid)
  if (filters.freight_id !== '') p.freight_id = Number(filters.freight_id)
  if (filters.mid !== '' && filters.mid !== null) p.mid = Number(filters.mid)
  if (filters.ctime && filters.ctime.length === 2) {
    p.ctime_start = Number(filters.ctime[0])
    p.ctime_end = Number(filters.ctime[1])
  }
  if (filters.paytime && filters.paytime.length === 2) {
    p.paytime_start = Number(filters.paytime[0])
    p.paytime_end = Number(filters.paytime[1])
  }
  if (exportAll) p.export = 1
  return p
}

async function fetchList() {
  loading.value = true
  try {
    const res = await getGoodsSales(buildParams())
    rows.value = res.list
    total.value = res.total
    totalData.value = res.totaldata
  } catch {
    // request.ts 已统一弹提示
  } finally {
    loading.value = false
  }
}

function onSearch() {
  page.value = 1
  fetchList()
}

function onReset() {
  filters.mdid = ''
  filters.freight_id = ''
  filters.mid = ''
  filters.ctime = null
  filters.paytime = null
  page.value = 1
  sortField.value = 'totalprice'
  sortOrder.value = 'desc'
  fetchList()
}

function onTypeChange(t: number) {
  activeType.value = t
  page.value = 1
  sortField.value = 'totalprice'
  sortOrder.value = 'desc'
  fetchList()
}

function onSortChange({ prop, order }: { prop: string | null; order: string | null }) {
  if (!prop) return
  sortField.value = prop
  sortOrder.value = order === 'ascending' ? 'asc' : 'desc'
  page.value = 1
  fetchList()
}

function fmtMoney(v: number): string {
  return `¥${Number(v || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

async function loadOptions() {
  try {
    const [stores, freights] = await Promise.all([
      getStoreList({ page: 1, pagesize: 500 }),
      getFreightList(),
    ])
    storeList.value = stores.list || []
    freightList.value = freights.list || []
  } catch {
    // 下拉加载失败不阻塞统计
  }
}

// CSV 单元格转义
function csvCell(s: unknown): string {
  const str = String(s ?? '')
  return /[",\n]/.test(str) ? `"${str.replace(/"/g, '""')}"` : str
}

function dateStr(): string {
  const d = new Date()
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}${mm}${dd}`
}

// 导出当前筛选结果为 CSV（带 BOM，Excel 可直接打开）
async function onExport() {
  exporting.value = true
  try {
    const res = await getGoodsSales(buildParams(true))
    const byGuige = activeType.value === 2
    const head = byGuige
      ? ['排名', '商品名称', '商品规格', '销售数量', '销售金额', '平均单价', '总商品成本', '毛利润']
      : ['排名', '商品名称', '销售数量', '销售金额', '平均单价', '总商品成本', '毛利润']
    const lines = [head.join(',')]

    res.list.forEach((row) => {
      const cells = [
        String(row.ph),
        csvCell(row.name),
        String(row.num),
        row.totalprice.toFixed(2),
        row.avgprice.toFixed(2),
        row.chengben.toFixed(2),
        row.lirun.toFixed(2),
      ]
      if (byGuige) cells.splice(2, 0, csvCell(row.ggname || '-'))
      lines.push(cells.join(','))
    })

    // 合计行（列与表头对齐）
    const sumCells = [
      '',
      '合计',
      String(res.totaldata.num),
      res.totaldata.totalprice.toFixed(2),
      '',
      res.totaldata.chengben.toFixed(2),
      res.totaldata.lirun.toFixed(2),
    ]
    if (byGuige) sumCells.splice(2, 0, '')
    lines.push(sumCells.join(','))

    const blob = new Blob(['\ufeff' + lines.join('\r\n')], {
      type: 'text/csv;charset=utf-8;',
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `销售统计_${byGuige ? '按规格' : '按商品'}_${dateStr()}.csv`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    // 统一提示
  } finally {
    exporting.value = false
  }
}

onMounted(() => {
  loadOptions()
  fetchList()
})
</script>

<template>
  <div class="sales-stat">
    <!-- 顶部：维度切换 + 导出 -->
    <el-card shadow="never" class="head-card">
      <div class="head-bar">
        <el-radio-group :model-value="activeType" @change="(v: string | number) => onTypeChange(Number(v))">
          <el-radio-button :value="0">按商品统计</el-radio-button>
          <el-radio-button :value="2">按规格统计</el-radio-button>
        </el-radio-group>
        <el-button type="success" :loading="exporting" @click="onExport">导出</el-button>
      </div>
    </el-card>

    <!-- 筛选条件 -->
    <el-card shadow="never" class="filter-card">
      <el-form inline label-width="auto">
        <el-form-item label="门店">
          <el-select
            v-model="filters.mdid"
            clearable
            filterable
            placeholder="全部门店"
            style="width: 160px"
          >
            <el-option v-for="s in storeList" :key="s.id" :label="s.name" :value="s.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="配送方式">
          <el-select
            v-model="filters.freight_id"
            clearable
            placeholder="全部配送方式"
            style="width: 150px"
          >
            <el-option v-for="f in freightList" :key="f.id" :label="f.name" :value="f.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input
            v-model.number="filters.mid"
            placeholder="请输入会员ID"
            clearable
            style="width: 130px"
            @keyup.enter="onSearch"
          />
        </el-form-item>
        <el-form-item label="下单时间">
          <el-date-picker
            v-model="filters.ctime"
            type="datetimerange"
            range-separator="~"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            value-format="X"
            style="width: 340px"
          />
        </el-form-item>
        <el-form-item label="付款时间">
          <el-date-picker
            v-model="filters.paytime"
            type="datetimerange"
            range-separator="~"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            value-format="X"
            style="width: 340px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="onSearch">搜索</el-button>
          <el-button @click="onReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 汇总条 -->
    <el-card shadow="never" class="summary-card">
      <div class="summary-row">
        <div class="summary-item">
          <span class="lbl">总销售数量</span>
          <span class="val">{{ totalData.num }}</span>
        </div>
        <div class="summary-item">
          <span class="lbl">总销售金额</span>
          <span class="val primary">{{ fmtMoney(totalData.totalprice) }}</span>
        </div>
        <div class="summary-item">
          <span class="lbl">总商品成本</span>
          <span class="val warn">{{ fmtMoney(totalData.chengben) }}</span>
        </div>
        <div class="summary-item">
          <span class="lbl">毛利润</span>
          <span class="val success">{{ fmtMoney(totalData.lirun) }}</span>
        </div>
      </div>
    </el-card>

    <!-- 明细表格 -->
    <el-card shadow="never" class="table-card">
      <el-table
        :data="rows"
        border
        size="small"
        v-loading="loading"
        :default-sort="{ prop: 'totalprice', order: 'descending' }"
        @sort-change="onSortChange"
      >
        <el-table-column label="排名" prop="ph" width="70" align="center" />
        <el-table-column label="商品名称" min-width="280" show-overflow-tooltip>
          <template #default="{ row }">
            <div class="goods-cell">
              <el-image :src="assetUrl(row.pic)" class="goods-img" fit="cover">
                <template #error>
                  <div class="img-ph">无图</div>
                </template>
              </el-image>
              <span class="goods-name">{{ row.name }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column
          v-if="activeType === 2"
          label="商品规格"
          prop="ggname"
          width="150"
          show-overflow-tooltip
        >
          <template #default="{ row }">{{ row.ggname || '—' }}</template>
        </el-table-column>
        <el-table-column label="销售数量" prop="num" width="110" align="center" sortable="custom" />
        <el-table-column label="销售金额" prop="totalprice" width="130" align="right" sortable="custom">
          <template #default="{ row }">{{ fmtMoney(row.totalprice) }}</template>
        </el-table-column>
        <el-table-column label="平均单价" prop="avgprice" width="120" align="right" sortable="custom">
          <template #default="{ row }">{{ fmtMoney(row.avgprice) }}</template>
        </el-table-column>
        <el-table-column label="总商品成本" prop="chengben" width="130" align="right" sortable="custom">
          <template #default="{ row }">{{ fmtMoney(row.chengben) }}</template>
        </el-table-column>
        <el-table-column label="毛利润" prop="lirun" width="130" align="right" sortable="custom">
          <template #default="{ row }">{{ fmtMoney(row.lirun) }}</template>
        </el-table-column>
      </el-table>

      <div class="pager-wrap">
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          :current-page="page"
          :page-size="pagesize"
          :page-sizes="[15, 30, 50, 100]"
          @current-change="(p: number) => ((page = p), fetchList())"
          @size-change="(s: number) => ((pagesize = s), (page = 1), fetchList())"
        />
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.sales-stat {
  display: flex;
  flex-direction: column;
  gap: 0;
}

.head-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.filter-card :deep(.el-form-item) {
  margin-bottom: 0;
}

.summary-row {
  display: flex;
  align-items: center;
  gap: 64px;
  flex-wrap: wrap;
}

.summary-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.summary-item .lbl {
  font-size: 12px;
  color: #909399;
}

.summary-item .val {
  font-size: 20px;
  font-weight: 700;
  color: #303133;
}

.summary-item .val.primary {
  color: #409eff;
}

.summary-item .val.warn {
  color: #e6a23c;
}

.summary-item .val.success {
  color: #67c23a;
}

.goods-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.goods-img {
  width: 38px;
  height: 38px;
  border-radius: 4px;
  flex-shrink: 0;
  background: #f5f7fa;
}

.img-ph {
  width: 38px;
  height: 38px;
  line-height: 38px;
  text-align: center;
  font-size: 11px;
  color: #c0c4cc;
  background: #f5f7fa;
  border-radius: 4px;
}

.goods-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pager-wrap {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}
</style>
