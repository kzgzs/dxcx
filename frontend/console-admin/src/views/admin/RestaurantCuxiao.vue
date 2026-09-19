<script setup lang="ts">
/**
 * 餐饮 - 营销 - 促销（对照参考系统 restaurant_cuxiao）
 * type：1满减 2满赠 3换购 4打折 5满件打折 6满件立减 7第二件打折
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteRestaurantCuxiao,
  getRestaurantCuxiaoDetail,
  getRestaurantCuxiaoList,
  saveRestaurantCuxiao,
  type RestaurantCuxiaoRow,
} from '../../api/restaurantExt'
import { formatTime } from '../../utils/time'

const TYPE_OPTIONS = [
  { value: 1, label: '满减' },
  { value: 2, label: '满赠' },
  { value: 3, label: '换购' },
  { value: 4, label: '打折' },
  { value: 5, label: '满件打折' },
  { value: 6, label: '满件立减' },
  { value: 7, label: '第二件打折' },
]

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<RestaurantCuxiaoRow[]>([])
const total = ref(0)
const query = reactive({ page: 1, limit: 10, name: '' })

async function fetchList() {
  loading.value = true
  try {
    const data = await getRestaurantCuxiaoList({
      page: query.page,
      limit: query.limit,
      name: query.name || undefined,
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

function statusTag(status: number): 'info' | 'success' | 'danger' {
  if (status === 1) return 'success'
  if (status === 2) return 'danger'
  return 'info'
}

function ruleText(row: RestaurantCuxiaoRow): string {
  switch (row.type) {
    case 1:
      return `满${row.minprice}元立减${row.money}元`
    case 2:
      return `满${row.minprice}元赠送商品`
    case 3:
      return `满${row.minprice}元加价${row.money}元换购`
    case 4:
      return `满${row.minprice}元打${row.zhekou}折`
    case 5:
      return `满${row.minnum}件打${row.zhekou}折`
    case 6:
      return `满${row.minnum}件立减${row.money}元`
    case 7:
      return `第二件打${row.zhekou}折`
    default:
      return '-'
  }
}

function handleDelete(row: RestaurantCuxiaoRow) {
  ElMessageBox.confirm(`确定删除促销活动「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteRestaurantCuxiao([row.id])
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
const levels = ref<{ id: number; name: string }[]>([])
const form = reactive<Record<string, unknown>>({})

function resetForm() {
  Object.assign(form, {
    id: 0,
    type: 1,
    name: '',
    starttime: Math.floor(Date.now() / 1000),
    endtime: Math.floor(Date.now() / 1000) + 7 * 86400,
    gettj: ['-1'],
    sort: 0,
    fwtype: 0,
    categoryids: [],
    productids: [],
    minprice: 0,
    money: 0,
    minnum: 0,
    zhekou: 0,
    proid: 0,
    ggid: 0,
  })
}

async function openEdit(id: number) {
  resetForm()
  try {
    const data = await getRestaurantCuxiaoDetail(id)
    const info = data.info || ({} as RestaurantCuxiaoRow)
    Object.assign(form, info)
    levels.value = info.levels || []
    if (!Array.isArray(form.gettj)) form.gettj = ['-1']
    if (!Array.isArray(form.categoryids)) form.categoryids = []
    if (!Array.isArray(form.productids)) form.productids = []
    editVisible.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

/** 类型切换时重置条件字段 */
function handleTypeChange() {
  form.minprice = 0
  form.money = 0
  form.minnum = 0
  form.zhekou = 0
}

/** 是否为「按金额」触发条件 */
function isMoneyCond(type: unknown): boolean {
  return [1, 2, 3, 4].includes(Number(type))
}

/** 是否为「按件数」触发条件 */
function isNumCond(type: unknown): boolean {
  return [5, 6].includes(Number(type))
}

async function submitEdit() {
  if (!String(form.name || '').trim()) {
    ElMessage.warning('请填写促销活动名称')
    return
  }
  editSaving.value = true
  try {
    await saveRestaurantCuxiao({ ...form })
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
      <div class="cx-header">
        <span class="cx-title">餐饮促销</span>
        <span class="cx-tip">外卖/点餐下单自动命中的满减、满赠、打折等活动</span>
        <el-button type="primary" class="cx-add" @click="openEdit(0)">新增促销</el-button>
      </div>
    </template>

    <div class="cx-toolbar">
      <el-input v-model="query.name" placeholder="活动名称关键字" clearable style="width: 200px" @keyup.enter="handleSearch" />
      <el-button type="primary" @click="handleSearch">搜索</el-button>
    </div>

    <el-table :data="tableData" v-loading="loading" stripe>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="活动名称" min-width="160" show-overflow-tooltip />
      <el-table-column label="类型" width="110">
        <template #default="{ row }">
          <el-tag size="small">{{ row.type_text || TYPE_OPTIONS.find((t) => t.value === row.type)?.label }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="活动规则" min-width="200" show-overflow-tooltip>
        <template #default="{ row }">{{ ruleText(row) }}</template>
      </el-table-column>
      <el-table-column label="活动时间" min-width="240">
        <template #default="{ row }">{{ formatTime(row.starttime) }} ~ {{ formatTime(row.endtime) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="statusTag(row.status || 0)" size="small">{{ row.status_text }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="sort" label="排序" width="70" />
      <el-table-column label="操作" width="140" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row.id)">编辑</el-button>
          <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>
      <template #empty>
        <el-empty description="暂无促销活动" />
      </template>
    </el-table>

    <div class="cx-pager">
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

    <el-dialog v-model="editVisible" :title="form.id ? '编辑促销' : '新增促销'" width="680px" :close-on-click-modal="false">
      <el-form label-width="120px">
        <el-form-item label="促销类型：">
          <el-select v-model="form.type" style="width: 200px" :disabled="!!form.id" @change="handleTypeChange">
            <el-option v-for="opt in TYPE_OPTIONS" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="活动名称：">
          <el-input v-model="form.name" placeholder="如：开业满减狂欢" style="width: 300px" />
        </el-form-item>
        <el-form-item label="活动时间：">
          <el-date-picker
            v-model="form.starttime"
            type="datetime"
            value-format="X"
            placeholder="开始时间"
            style="width: 190px"
          />
          <span class="cx-inline">至</span>
          <el-date-picker
            v-model="form.endtime"
            type="datetime"
            value-format="X"
            placeholder="结束时间"
            style="width: 190px"
          />
        </el-form-item>

        <template v-if="isMoneyCond(form.type)">
          <el-form-item label="满额条件：">
            <el-input-number v-model="form.minprice" :min="0" :precision="2" />
            <span class="cx-inline">元</span>
          </el-form-item>
        </template>
        <template v-if="isNumCond(form.type)">
          <el-form-item label="满件条件：">
            <el-input-number v-model="form.minnum" :min="0" :max="9999" />
            <span class="cx-inline">件</span>
          </el-form-item>
        </template>

        <template v-if="form.type === 1 || form.type === 6">
          <el-form-item label="立减金额：">
            <el-input-number v-model="form.money" :min="0" :precision="2" />
            <span class="cx-inline">元</span>
          </el-form-item>
        </template>
        <template v-if="form.type === 3">
          <el-form-item label="换购加价：">
            <el-input-number v-model="form.money" :min="0" :precision="2" />
            <span class="cx-inline">元</span>
          </el-form-item>
        </template>
        <template v-if="form.type === 2">
          <el-form-item label="赠送菜品ID：">
            <el-input-number v-model="form.proid" :min="0" />
            <span class="cx-inline">满赠的菜品（restaurant_product ID）</span>
          </el-form-item>
        </template>
        <template v-if="form.type === 4 || form.type === 5 || form.type === 7">
          <el-form-item label="折扣：">
            <el-input-number v-model="form.zhekou" :min="0" :max="10" :precision="2" :step="0.1" />
            <span class="cx-inline">折（如 8.5 表示 85 折）</span>
          </el-form-item>
        </template>

        <el-form-item label="参与等级：">
          <el-select v-model="form.gettj" multiple placeholder="不限" style="width: 300px">
            <el-option label="不限等级" value="-1" />
            <el-option v-for="lv in levels" :key="lv.id" :label="lv.name" :value="String(lv.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="排序：">
          <el-input-number v-model="form.sort" :min="0" :max="9999" />
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
.cx-header {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.cx-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.cx-tip {
  font-size: 12px;
  color: #909399;
}

.cx-add {
  margin-left: auto;
}

.cx-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.cx-pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

.cx-inline {
  margin-left: 8px;
  color: #909399;
}
</style>
