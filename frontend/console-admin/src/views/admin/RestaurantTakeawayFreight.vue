<script setup lang="ts">
/**
 * 餐饮 - 外卖 - 配送方式（对照参考系统 restaurant_takeaway_freight）
 * pstype：0快递模板 1自提门店 2同城配送 3发货信息 5配送门店
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteRestaurantFreight,
  getRestaurantFreightDetail,
  getRestaurantFreightList,
  saveRestaurantFreight,
  type RestaurantFreightPrice,
  type RestaurantFreightRow,
  type RestaurantStoreOption,
} from '../../api/restaurantExt'

const PSTYPE_OPTIONS = [
  { value: 0, label: '快递模板' },
  { value: 1, label: '自提门店' },
  { value: 2, label: '同城配送' },
  { value: 3, label: '发货信息' },
  { value: 5, label: '配送门店' },
]

function pstypeText(pstype: number): string {
  return PSTYPE_OPTIONS.find((o) => o.value === pstype)?.label || '未知'
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<RestaurantFreightRow[]>([])
const total = ref(0)
const selection = ref<RestaurantFreightRow[]>([])

const query = reactive({
  page: 1,
  limit: 10,
  name: '',
  status: '' as number | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getRestaurantFreightList({
      page: query.page,
      limit: query.limit,
      name: query.name || undefined,
      status: query.status === '' ? undefined : query.status,
    })
    tableData.value = data.list || []
    total.value = data.count || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.name = ''
  query.status = ''
  query.page = 1
  fetchList()
}

function handleSelectionChange(rows: RestaurantFreightRow[]) {
  selection.value = rows
}

function handleDelete(rows: RestaurantFreightRow[]) {
  if (!rows.length) {
    ElMessage.warning('请先选择配送方式')
    return
  }
  ElMessageBox.confirm(`确定删除所选 ${rows.length} 个配送方式吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteRestaurantFreight(rows.map((r) => r.id))
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 编辑 ============
const editVisible = ref(false)
const editSaving = ref(false)
const stores = ref<RestaurantStoreOption[]>([])
const form = reactive<Record<string, unknown>>({})

function resetForm() {
  Object.assign(form, {
    id: 0,
    pstype: 2,
    name: '同城配送',
    type: 0,
    status: 1,
    sort: 0,
    freeset: 0,
    free_price: 0,
    minpriceset: 0,
    minprice: 0,
    pricedata: [] as RestaurantFreightPrice[],
    storetype: 0,
    storeids: [] as number[],
    pscontenttype: 0,
    pscontent: '',
    needlinkinfo: 0,
    fwprice: 0,
    pstimeset: 0,
    pstimedata: [],
    psprehour: 4,
    peisong_juli1: 5,
    peisong_fee1: 3,
    peisong_juli2: 1,
    peisong_fee2: 1,
    peisong_lng: '',
    peisong_lat: '',
    peisong_range: 0,
    peisong_rangetype: 0,
    peisong_rangepath: '',
  })
}

async function openEdit(id: number) {
  resetForm()
  try {
    const data = await getRestaurantFreightDetail(id)
    stores.value = data.stores || []
    Object.assign(form, data.info || {})
    if (!Array.isArray(form.pricedata)) form.pricedata = []
    if (!Array.isArray(form.storeids)) form.storeids = []
    editVisible.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function addPriceRow() {
  ;(form.pricedata as RestaurantFreightPrice[]).push({
    region: '',
    fristweight: '1',
    fristprice: '0',
    secondweight: '1',
    secondprice: '0',
  })
}

function removePriceRow(index: number) {
  ;(form.pricedata as RestaurantFreightPrice[]).splice(index, 1)
}

async function submitEdit() {
  if (!String(form.name || '').trim()) {
    ElMessage.warning('请填写配送方式名称')
    return
  }
  editSaving.value = true
  try {
    await saveRestaurantFreight({ ...form })
    ElMessage.success('保存成功')
    editVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    editSaving.value = false
  }
}

onMounted(fetchList)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="rf-header">
        <span class="rf-title">配送方式</span>
        <span class="rf-tip">外卖下单可选的配送/自提方式，按排序值倒序展示</span>
        <el-button type="primary" class="rf-add" @click="openEdit(0)">新增配送方式</el-button>
      </div>
    </template>

    <div class="rf-toolbar">
      <el-input v-model="query.name" placeholder="名称关键字" clearable style="width: 200px" @keyup.enter="handleSearch" />
      <el-select v-model="query.status" placeholder="状态" clearable style="width: 120px" @change="handleSearch">
        <el-option label="全部" value="" />
        <el-option label="启用" :value="1" />
        <el-option label="停用" :value="0" />
      </el-select>
      <el-button type="primary" @click="handleSearch">搜索</el-button>
      <el-button @click="handleReset">重置</el-button>
      <el-button type="danger" plain class="rf-batch" @click="handleDelete(selection)">批量删除</el-button>
    </div>

    <el-table :data="tableData" v-loading="loading" stripe @selection-change="handleSelectionChange">
      <el-table-column type="selection" width="45" />
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="名称" min-width="140" />
      <el-table-column label="配送类型" width="110">
        <template #default="{ row }">
          <el-tag size="small">{{ pstypeText(row.pstype) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="summary" label="计费/范围摘要" min-width="280" show-overflow-tooltip />
      <el-table-column label="状态" width="80">
        <template #default="{ row }">
          <el-tag v-if="row.status === 1" type="success" size="small">启用</el-tag>
          <el-tag v-else type="info" size="small">停用</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="sort" label="排序" width="80" />
      <el-table-column label="操作" width="150" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row.id)">编辑</el-button>
          <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
        </template>
      </el-table-column>
      <template #empty>
        <el-empty description="暂无配送方式，点击右上角新增" />
      </template>
    </el-table>

    <div class="rf-pager">
      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.limit"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </div>

    <el-dialog v-model="editVisible" :title="form.id ? '编辑配送方式' : '新增配送方式'" width="720px" :close-on-click-modal="false">
      <el-form label-width="120px">
        <el-form-item label="配送类型：">
          <el-radio-group v-model="form.pstype" :disabled="!!form.id">
            <el-radio v-for="opt in PSTYPE_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="名称：">
          <el-input v-model="form.name" placeholder="如：同城配送 / 到店自提" style="width: 280px" />
        </el-form-item>
        <el-form-item label="状态：">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">停用</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="排序：">
          <el-input-number v-model="form.sort" :min="0" :max="9999" />
        </el-form-item>

        <!-- 快递模板 -->
        <template v-if="form.pstype === 0">
          <el-form-item label="计费单位：">
            <el-radio-group v-model="form.type">
              <el-radio :value="0">按件数</el-radio>
              <el-radio :value="1">按重量</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="运费模板：">
            <div class="rf-price-head">
              <span style="flex: 2">配送区域</span>
              <span>首{{ form.type === 1 ? '重(克)' : '件' }}</span>
              <span>首费(元)</span>
              <span>续{{ form.type === 1 ? '重(克)' : '件' }}</span>
              <span>续费(元)</span>
              <span style="width: 40px" />
            </div>
            <div v-for="(row, i) in form.pricedata as RestaurantFreightPrice[]" :key="i" class="rf-price-row">
              <el-input v-model="row.region" placeholder="区域，多个用;分隔" style="flex: 2" />
              <el-input v-model="row.fristweight" style="width: 80px" />
              <el-input v-model="row.fristprice" style="width: 80px" />
              <el-input v-model="row.secondweight" style="width: 80px" />
              <el-input v-model="row.secondprice" style="width: 80px" />
              <el-button link type="danger" style="width: 40px" @click="removePriceRow(i)">删除</el-button>
            </div>
            <el-button size="small" @click="addPriceRow">添加区域</el-button>
          </el-form-item>
        </template>

        <!-- 自提门店 / 配送门店 -->
        <template v-if="form.pstype === 1 || form.pstype === 5">
          <el-form-item label="适用门店：">
            <el-radio-group v-model="form.storetype">
              <el-radio :value="0">全部门店</el-radio>
              <el-radio :value="1">指定门店</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="form.storetype === 1" label="选择门店：">
            <el-select v-model="form.storeids" multiple placeholder="请选择门店" style="width: 100%">
              <el-option v-for="s in stores" :key="s.id" :label="s.name" :value="s.id" />
            </el-select>
          </el-form-item>
        </template>

        <!-- 同城配送 -->
        <template v-if="form.pstype === 2">
          <el-form-item label="配送费：">
            <span class="rf-inline">{{ form.peisong_juli1 }}公里以内</span>
            <el-input-number v-model="form.peisong_fee1" :min="0" :precision="2" size="small" />
            <span class="rf-inline">元；每超</span>
            <el-input-number v-model="form.peisong_juli2" :min="0" :precision="1" size="small" />
            <span class="rf-inline">公里加收</span>
            <el-input-number v-model="form.peisong_fee2" :min="0" :precision="2" size="small" />
            <span class="rf-inline">元</span>
          </el-form-item>
          <el-form-item label="提前下单时间：">
            <el-input-number v-model="form.psprehour" :min="0" :max="72" />
            <span class="rf-inline">小时（预约单需提前的小时数）</span>
          </el-form-item>
        </template>

        <!-- 发货信息 -->
        <template v-if="form.pstype === 3">
          <el-form-item label="发货内容类型：">
            <el-radio-group v-model="form.pscontenttype">
              <el-radio :value="0">固定内容</el-radio>
              <el-radio :value="1">不固定信息(在线卡密)</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="form.pscontenttype === 0" label="发货内容：">
            <el-input v-model="form.pscontent" type="textarea" :rows="3" placeholder="付款后展示给会员的发货信息" />
          </el-form-item>
        </template>

        <el-divider content-position="left">通用设置</el-divider>
        <el-form-item label="包邮设置：">
          <el-radio-group v-model="form.freeset">
            <el-radio :value="0">不包邮</el-radio>
            <el-radio :value="1">满额包邮</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.freeset === 1" label="包邮门槛：">
          <el-input-number v-model="form.free_price" :min="0" :precision="2" />
          <span class="rf-inline">元</span>
        </el-form-item>
        <el-form-item v-if="form.pstype !== 1 && form.pstype !== 3" label="起送设置：">
          <el-radio-group v-model="form.minpriceset">
            <el-radio :value="0">不设起送</el-radio>
            <el-radio :value="1">满额起送</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.minpriceset === 1 && form.pstype !== 1 && form.pstype !== 3" label="起送金额：">
          <el-input-number v-model="form.minprice" :min="0" :precision="2" />
          <span class="rf-inline">元</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="editSaving" @click="submitEdit">保存</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<style scoped>
.rf-header {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.rf-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.rf-tip {
  font-size: 12px;
  color: #909399;
}

.rf-add {
  margin-left: auto;
}

.rf-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.rf-batch {
  margin-left: auto;
}

.rf-pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

.rf-price-head,
.rf-price-row {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}

.rf-price-head span {
  flex: 1;
  font-size: 12px;
  color: #909399;
  text-align: center;
}

.rf-price-row .el-input {
  flex: 1;
}

.rf-inline {
  margin: 0 6px;
  color: #606266;
}
</style>
