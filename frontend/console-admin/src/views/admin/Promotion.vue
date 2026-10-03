<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getManjianConfig, saveManjianConfig } from '../../api/manjian'
import { getCategoryList, getGoodsList } from '../../api/goods'
import type { CategoryItem, GoodsItem } from '../../api/types'

interface SelectedItem {
  id: number
  name: string
}

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  fwtype: 0,
  total_status: 0,
  status: 0,
})

const tierRows = ref<{ money: number; jian: number }[]>([])
const selectedCategories = ref<SelectedItem[]>([])
const selectedProducts = ref<SelectedItem[]>([])

function addRow() {
  tierRows.value.push({ money: 0, jian: 0 })
}

function removeRow(index: number) {
  tierRows.value.splice(index, 1)
}

// ---- 商品/类目选择器（弹窗） ----
const pickerVisible = ref(false)
const pickerKeyword = ref('')
const pickerLoading = ref(false)
const pickerPool = ref<{ id: number; name: string }[]>([])

function openPicker() {
  pickerKeyword.value = ''
  pickerVisible.value = true
  loadPickerPool()
}

async function loadPickerPool() {
  pickerLoading.value = true
  try {
    if (form.fwtype === 1) {
      const res = await getCategoryList({ page: 1, pagesize: 200, keyword: pickerKeyword.value })
      pickerPool.value = (res.list || []).map((c: CategoryItem) => ({ id: c.id, name: c.name }))
    } else if (form.fwtype === 2) {
      const res = await getGoodsList({ page: 1, pagesize: 200, keyword: pickerKeyword.value })
      pickerPool.value = (res.list || []).map((g: GoodsItem) => ({ id: g.id, name: g.name }))
    }
  } catch {
    pickerPool.value = []
  } finally {
    pickerLoading.value = false
  }
}

function onSearchPicker() {
  loadPickerPool()
}

function pickItem(item: SelectedItem) {
  const list = form.fwtype === 1 ? selectedCategories.value : selectedProducts.value
  if (list.some((i) => i.id === item.id)) {
    ElMessage.warning('该项已在列表中')
    return
  }
  list.push({ id: item.id, name: item.name })
}

function removeSelected(index: number) {
  if (form.fwtype === 1) {
    selectedCategories.value.splice(index, 1)
  } else {
    selectedProducts.value.splice(index, 1)
  }
}

// ---- 回显：由 id 串解析名称 ----
async function resolveNames(categoryIds: number[], productIds: number[]) {
  try {
    if (categoryIds.length) {
      const res = await getCategoryList({ page: 1, pagesize: 200 })
      const pool = res.list || []
      selectedCategories.value = categoryIds
        .map((id) => pool.find((c) => c.id === id))
        .filter((c): c is CategoryItem => !!c)
        .map((c) => ({ id: c.id, name: c.name }))
    }
    if (productIds.length) {
      const res = await getGoodsList({ page: 1, pagesize: 200 })
      const pool = res.list || []
      selectedProducts.value = productIds
        .map((id) => pool.find((g) => g.id === id))
        .filter((g): g is GoodsItem => !!g)
        .map((g) => ({ id: g.id, name: g.name }))
    }
  } catch {
    // 回显名称失败不阻断
  }
}

async function fetchConfig() {
  loading.value = true
  try {
    const d = await getManjianConfig()
    form.fwtype = d.fwtype || 0
    form.total_status = d.total_status || 0
    form.status = d.status || 0
    tierRows.value = (d.mjdata || []).map((r) => ({ money: r.money || 0, jian: r.jian || 0 }))
    if (tierRows.value.length === 0) {
      addRow()
    }
    const catIds = (d.categoryids || '').split(',').filter(Boolean).map(Number)
    const proIds = (d.productids || '').split(',').filter(Boolean).map(Number)
    await resolveNames(catIds, proIds)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  if (form.fwtype === 1 && selectedCategories.value.length === 0) {
    ElMessage.warning('请选择指定类目')
    return
  }
  if (form.fwtype === 2 && selectedProducts.value.length === 0) {
    ElMessage.warning('请选择指定商品')
    return
  }
  const rows = tierRows.value.filter((r) => Number(r.money) > 0 && Number(r.jian) > 0)
  saving.value = true
  try {
    await saveManjianConfig({
      fwtype: form.fwtype,
      categoryids: form.fwtype === 1 ? selectedCategories.value.map((c) => c.id).join(',') : '',
      productids: form.fwtype === 2 ? selectedProducts.value.map((p) => p.id).join(',') : '',
      total_status: form.total_status,
      status: form.status,
      money: rows.map((r) => Number(r.money)),
      jian: rows.map((r) => Number(r.jian)),
    })
    ElMessage.success('保存成功')
    fetchConfig()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchConfig()
})
</script>

<template>
  <div class="page-wrap">
    <div v-loading="loading" class="panel">
      <el-form label-width="110px" class="pl-form">
        <el-form-item label="满减设置">
          <div class="tier-list">
            <div v-for="(row, index) in tierRows" :key="index" class="tier-row">
              <span>购物满</span>
              <el-input v-model.number="row.money" type="number" class="w-110" />
              <span>元，减</span>
              <el-input v-model.number="row.jian" type="number" class="w-110" />
              <span>元</span>
              <el-button v-if="index === 0" size="small" @click="addRow">新增</el-button>
              <el-button size="small" @click="removeRow(index)">删除</el-button>
            </div>
          </div>
        </el-form-item>

        <el-form-item label="选择商品">
          <el-radio-group v-model="form.fwtype">
            <el-radio :value="0">所有商品</el-radio>
            <el-radio :value="1">指定类目</el-radio>
            <el-radio :value="2">指定商品</el-radio>
          </el-radio-group>
          <div class="tip">所有商品类型仅限商城、餐饮外卖使用，指定类目和指定商品类型仅限商城使用</div>
        </el-form-item>

        <el-form-item v-if="form.fwtype === 1 || form.fwtype === 2" label-width="110px">
          <el-table
            :data="form.fwtype === 1 ? selectedCategories : selectedProducts"
            border
            size="small"
            class="item-table"
          >
            <el-table-column prop="id" label="商品ID" width="120" align="center" />
            <el-table-column prop="name" label="商品名称" min-width="220" show-overflow-tooltip />
            <el-table-column label="操作" width="90" align="center">
              <template #default="{ $index }">
                <el-button size="small" @click="removeSelected($index)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
          <el-button size="small" class="mt-8" @click="openPicker">添加</el-button>
        </el-form-item>

        <el-form-item label="累计消费额满减">
          <el-radio-group v-model="form.total_status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <div class="tip">只在商城使用，开启后所有已完成的商城订单金额都计入累计消费额</div>
        </el-form-item>

        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item>
          <el-button type="success" :loading="saving" @click="handleSave">提交</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 类目/商品选择弹窗 -->
    <el-dialog
      v-model="pickerVisible"
      :title="form.fwtype === 1 ? '选择类目' : '选择商品'"
      width="720px"
      :close-on-click-modal="false"
      destroy-teleport-node
    >
      <div class="picker-search">
        <el-input
          v-model="pickerKeyword"
          placeholder="名称"
          clearable
          style="width: 240px"
          @keyup.enter="onSearchPicker"
        >
          <template #append>
            <el-button @click="onSearchPicker">搜索</el-button>
          </template>
        </el-input>
      </div>
      <el-table v-loading="pickerLoading" :data="pickerPool" border size="small" max-height="380">
        <el-table-column prop="id" label="ID" width="90" align="center" />
        <el-table-column prop="name" label="名称" min-width="260" show-overflow-tooltip />
        <el-table-column label="操作" width="90" align="center">
          <template #default="{ row }">
            <el-button size="small" @click="pickItem(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>
  </div>
</template>

<style scoped>
.page-wrap {
  margin: 3px;
}
.panel {
  padding: 12px;
}
.pl-form :deep(.el-form-item) {
  margin-bottom: 14px;
}
.tier-list {
  width: 100%;
}
.tier-row {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.w-110 {
  width: 110px;
}
.tip {
  margin-top: 4px;
  color: #909399;
  font-size: 12px;
  line-height: 1.4;
}
.mt-8 {
  margin-top: 8px;
}
.item-table {
  width: 600px;
}
.picker-search {
  margin-bottom: 12px;
}
</style>
