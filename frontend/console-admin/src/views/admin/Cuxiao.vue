<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Search } from '@element-plus/icons-vue'
import {
  addCuxiao,
  deleteCuxiao,
  editCuxiao,
  getCategoryOptions,
  getCuxiaoDetail,
  getCuxiaoList,
  getGoodsOptions,
  setCuxiaoStatus,
  type CategoryOption,
  type CuxiaoItem,
  type GoodsOption,
} from '../../api/cuxiao'

const TYPE_OPTIONS = [
  { value: 0, label: '仅显示' },
  { value: 1, label: '满减' },
  { value: 2, label: '满赠' },
  { value: 3, label: '换购' },
]
const TYPE_TEXT: Record<number, string> = { 0: '仅显示', 1: '满减', 2: '满赠', 3: '换购' }

// ============ 列表 ============
const loading = ref(false)
const list = ref<CuxiaoItem[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 10, keyword: '', type: '' })

async function fetchList() {
  loading.value = true
  try {
    const d = await getCuxiaoList(query)
    list.value = d.list || []
    total.value = d.total || 0
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

async function handleStatus(row: CuxiaoItem, status: number) {
  try {
    await setCuxiaoStatus(row.id, status)
    ElMessage.success(status === 1 ? '已启用' : '已停用')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleDelete(row: CuxiaoItem) {
  try {
    await ElMessageBox.confirm(`确定删除活动「${row.name}」？`, '提示', { type: 'warning' })
    await deleteCuxiao(row.id)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 取消或失败
  }
}

// ============ 表单弹窗 ============
const dialogVisible = ref(false)
const saving = ref(false)
const isEdit = ref(false)

const form = reactive({
  id: 0,
  name: '',
  type: 1,
  tip: '',
  gettj: '-1',
  fwtype: 0,
  categoryArr: [] as number[],
  productArr: [] as number[],
  minprice: 0,
  minnum: 1,
  money: 0,
  proid: 0,
  ggid: 0,
  status: 1,
  sort: 0,
})
const timeRange = ref<[string, string] | null>(null)

const goodsOptions = ref<GoodsOption[]>([])
const categoryOptions = ref<CategoryOption[]>([])

const moneyLabel = computed(() => (form.type === 3 ? '换购加价金额' : '减免金额'))

async function loadCandidates() {
  try {
    const [goods, cats] = await Promise.all([getGoodsOptions(), getCategoryOptions()])
    goodsOptions.value = goods.list || []
    categoryOptions.value = cats.list || []
  } catch {
    // 候选加载失败不阻断
  }
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, {
    id: 0,
    name: '',
    type: 1,
    tip: '',
    gettj: '-1',
    fwtype: 0,
    categoryArr: [],
    productArr: [],
    minprice: 0,
    minnum: 1,
    money: 0,
    proid: 0,
    ggid: 0,
    status: 1,
    sort: 0,
  })
  timeRange.value = null
  dialogVisible.value = true
}

async function openEdit(row: CuxiaoItem) {
  isEdit.value = true
  try {
    const d = await getCuxiaoDetail(row.id)
    Object.assign(form, {
      id: d.id,
      name: d.name,
      type: d.type,
      tip: d.tip,
      gettj: d.gettj || '-1',
      fwtype: d.fwtype,
      categoryArr: d.categoryids ? d.categoryids.split(',').filter(Boolean).map(Number) : [],
      productArr: d.productids ? d.productids.split(',').filter(Boolean).map(Number) : [],
      minprice: d.minprice,
      minnum: d.minnum,
      money: d.money,
      proid: d.proid,
      ggid: d.ggid,
      status: d.status,
      sort: d.sort,
    })
    timeRange.value = d.starttime && d.endtime ? [d.starttime, d.endtime] : null
    dialogVisible.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSave() {
  if (!form.name) {
    ElMessage.warning('请填写活动名称')
    return
  }
  if (!timeRange.value) {
    ElMessage.warning('请选择活动起止时间')
    return
  }
  const payload: Partial<CuxiaoItem> = {
    name: form.name,
    type: form.type,
    tip: form.tip,
    gettj: form.gettj || '-1',
    fwtype: form.fwtype,
    categoryids: form.categoryArr.join(','),
    productids: form.productArr.join(','),
    minprice: form.minprice,
    minnum: form.minnum,
    money: form.money,
    proid: form.type === 2 || form.type === 3 ? form.proid : 0,
    ggid: form.ggid,
    sort: form.sort,
    status: form.status,
    starttime: timeRange.value[0],
    endtime: timeRange.value[1],
  }
  saving.value = true
  try {
    if (isEdit.value) {
      await editCuxiao({ ...payload, id: form.id })
    } else {
      await addCuxiao(payload)
    }
    ElMessage.success('保存成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchList()
  loadCandidates()
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="card-header">
        <div class="toolbar">
          <el-input
            v-model="query.keyword"
            placeholder="活动名称"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
          <el-select v-model="query.type" placeholder="活动类型" clearable style="width: 130px">
            <el-option v-for="o in TYPE_OPTIONS" :key="o.value" :label="o.label" :value="String(o.value)" />
          </el-select>
          <el-button type="primary" :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <el-button type="primary" :icon="Plus" @click="openAdd">新建活动</el-button>
      </div>
    </template>

    <el-table v-loading="loading" :data="list" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="活动名称" min-width="150" />
      <el-table-column label="类型" width="90">
        <template #default="{ row }">{{ TYPE_TEXT[row.type] || '-' }}</template>
      </el-table-column>
      <el-table-column label="优惠内容" min-width="160">
        <template #default="{ row }">
          <template v-if="row.type === 1">满{{ row.minprice }}元减{{ row.money }}元</template>
          <template v-else-if="row.type === 2">满{{ row.minprice }}元赠「{{ row.proname }}」</template>
          <template v-else-if="row.type === 3">满{{ row.minprice }}元可{{ row.money }}元换购「{{ row.proname }}」</template>
          <template v-else>{{ row.tip || '仅展示' }}</template>
        </template>
      </el-table-column>
      <el-table-column label="活动时间" min-width="200">
        <template #default="{ row }">{{ row.starttime }} ~ {{ row.endtime }}</template>
      </el-table-column>
      <el-table-column prop="sort" label="排序" width="70" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-switch
            :model-value="row.status"
            :active-value="1"
            :inactive-value="0"
            @change="(v: number) => handleStatus(row, v)"
          />
        </template>
      </el-table-column>
      <el-table-column label="操作" width="140" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="query.page"
      v-model:page-size="query.pagesize"
      :total="total"
      layout="total, prev, pager, next, sizes"
      style="margin-top: 12px"
      @current-change="fetchList"
      @size-change="handleSearch"
    />

    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑活动' : '新建活动'" width="640px">
      <el-form label-width="110px">
        <el-form-item label="活动名称">
          <el-input v-model="form.name" maxlength="50" />
        </el-form-item>
        <el-form-item label="活动类型">
          <el-radio-group v-model="form.type">
            <el-radio v-for="o in TYPE_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="提示文案">
          <el-input v-model="form.tip" maxlength="100" placeholder="C 端展示的促销提示文案" />
        </el-form-item>
        <el-form-item label="参与人群">
          <el-input v-model="form.gettj" placeholder="-1 表示全部；多个会员等级ID用逗号分隔" style="width: 320px" />
        </el-form-item>
        <el-form-item label="适用范围">
          <el-radio-group v-model="form.fwtype">
            <el-radio :value="0">全部商品</el-radio>
            <el-radio :value="1">指定分类</el-radio>
            <el-radio :value="2">指定商品</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.fwtype === 1" label="选择分类">
          <el-select v-model="form.categoryArr" multiple filterable placeholder="请选择分类">
            <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="form.fwtype === 2" label="选择商品">
          <el-select v-model="form.productArr" multiple filterable placeholder="请选择商品">
            <el-option v-for="g in goodsOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="form.type !== 0" label="满额门槛">
          <el-input-number v-model="form.minprice" :min="0" :precision="2" />
          <span class="unit">元</span>
        </el-form-item>
        <el-form-item v-if="form.type !== 0" label="最低件数">
          <el-input-number v-model="form.minnum" :min="1" />
        </el-form-item>
        <el-form-item v-if="form.type === 1 || form.type === 3" :label="moneyLabel">
          <el-input-number v-model="form.money" :min="0" :precision="2" />
          <span class="unit">元</span>
        </el-form-item>
        <el-form-item v-if="form.type === 2" label="赠送商品">
          <el-select v-model="form.proid" filterable placeholder="请选择赠送商品">
            <el-option v-for="g in goodsOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="form.type === 3" label="换购商品">
          <el-select v-model="form.proid" filterable placeholder="请选择换购商品">
            <el-option v-for="g in goodsOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="活动时间">
          <el-date-picker
            v-model="timeRange"
            type="datetimerange"
            value-format="YYYY-MM-DD HH:mm:ss"
            range-separator="至"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
          />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.toolbar {
  display: flex;
  gap: 8px;
}
.unit {
  margin-left: 8px;
  color: #606266;
}
</style>
