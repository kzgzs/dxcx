<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { Plus, Delete, Search } from '@element-plus/icons-vue'
import {
  deleteShopParam,
  getShopParamCategoryTree,
  getShopParamDetail,
  getShopParamList,
  saveShopParam,
} from '../../api/shopconfig'
import type { ParamCategoryNode, ShopParamRow } from '../../api/shopconfig'

const loading = ref(false)
const tableData = ref<ShopParamRow[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  name: '',
  cid: '' as number | '',
  status: '' as number | '',
})

// 列表"参数"列展示：type=0 显示 [输入文本]，否则把选项用中文逗号拼接
function formatParamCell(row: ShopParamRow): string {
  if (Number(row.type) === 0) return '[输入文本]'
  let arr: unknown = row.params
  if (typeof arr === 'string') {
    try {
      arr = JSON.parse(arr)
    } catch {
      arr = []
    }
  }
  if (!Array.isArray(arr) || arr.length === 0) return '-'
  return arr.map(String).filter((s) => s !== '').join('，')
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getShopParamList({
      page: query.page,
      pagesize: query.pagesize,
      name: query.name || undefined,
      cid: query.cid === '' ? undefined : query.cid,
      status: query.status === '' ? undefined : query.status,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSelectionChange(rows: ShopParamRow[]) {
  selectedIds.value = rows.map((r) => r.id)
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

const categoryTree = ref<ParamCategoryNode[]>([])
const treeProps = { value: 'id', label: 'name', children: 'child' }

async function fetchCategoryTree() {
  try {
    const data = await getShopParamCategoryTree()
    categoryTree.value = data.tree || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  cid: [] as number[],
  type: 0,
  params: [] as string[],
  tips: '',
  is_required: 0,
  sort: 0,
  status: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入参数名称', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, { id: 0, name: '', cid: [], type: 0, params: [], tips: '', is_required: 0, sort: 0, status: 1 })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

async function openEdit(row: ShopParamRow) {
  isEdit.value = true
  Object.assign(form, { id: row.id, name: row.name, cid: [], type: row.type, params: [], tips: row.tips, is_required: row.is_required, sort: row.sort, status: row.status })
  formRef.value?.clearValidate()
  dialogVisible.value = true
  try {
    const data = await getShopParamDetail(row.id)
    const info = data.info || {}
    form.cid = Array.isArray(info.cid) ? (info.cid as unknown[]).map(Number) : []
    const p = info.params
    form.params = Array.isArray(p) && p.length > 0 ? (p as unknown[]).map(String) : []
    form.type = Number(info.type ?? 0)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function addOptionRow() {
  form.params.push('')
}

function removeOptionRow(index: number) {
  form.params.splice(index, 1)
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    const opts = form.params.map((s) => s.trim()).filter((s) => s !== '')
    if (form.type > 0 && opts.length === 0) {
      ElMessage.warning('单项/多项选择类型请至少填写一个选项')
      return
    }
    submitting.value = true
    try {
      await saveShopParam({
        id: form.id,
        name: form.name,
        cid: form.cid,
        type: form.type,
        params: form.type === 0 ? [] : opts,
        tips: form.tips,
        is_required: form.is_required,
        sort: form.sort,
        status: form.status,
      })
      ElMessage.success('操作成功')
      dialogVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

// ============ 删除（单个 / 批量） ============
function doDelete(ids: number[]) {
  if (ids.length === 0) {
    ElMessage.warning('请选择数据')
    return
  }
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteShopParam(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleBatchDelete() {
  doDelete(selectedIds.value)
}

function handleDelete(row: ShopParamRow) {
  doDelete([row.id])
}

onMounted(() => {
  fetchList()
  fetchCategoryTree()
})
</script>

<template>
  <div>
    <!-- 工具栏：左侧操作按钮，右侧搜索（对齐点大商品参数） -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleBatchDelete">删除</el-button>
        </div>
        <el-form inline class="toolbar-right" @submit.prevent>
          <el-form-item label="名称" class="search-item">
            <el-input
              v-model="query.name"
              placeholder="名称"
              clearable
              style="width: 160px"
              @keyup.enter="handleSearch"
            />
          </el-form-item>
          <el-form-item label="分类" class="search-item">
            <el-tree-select
              v-model="query.cid"
              :data="categoryTree"
              :props="treeProps"
              node-key="id"
              placeholder="全部"
              clearable
              check-strictly
              filterable
              default-expand-all
              :render-after-expand="false"
              style="width: 160px"
            />
          </el-form-item>
          <el-form-item label="状态" class="search-item">
            <el-select v-model="query.status" placeholder="全部" style="width: 110px">
              <el-option label="全部" :value="''" />
              <el-option label="开启" :value="1" />
              <el-option label="关闭" :value="0" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="success" :icon="Search" @click="handleSearch" />
          </el-form-item>
        </el-form>
      </div>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        border
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="name" label="名称" min-width="140" show-overflow-tooltip />
        <el-table-column prop="catname" label="适用分类" min-width="160" show-overflow-tooltip />
        <el-table-column label="参数" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">{{ formatParamCell(row) }}</template>
        </el-table-column>
        <el-table-column prop="sort" label="序号" width="80" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <span :style="{ color: row.status === 1 ? '#009a61' : '#f56c6c' }">
              {{ row.status === 1 ? '开启' : '关闭' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 15, 30, 50]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 添加 / 编辑弹窗（对齐点大"添加商品参数"弹窗） -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑商品参数' : '添加商品参数'" width="640px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px" label-position="left">
        <el-form-item label="参数名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入参数名称" />
        </el-form-item>

        <el-form-item label="适用分类">
          <el-tree-select
            v-model="form.cid"
            :data="categoryTree"
            :props="treeProps"
            node-key="id"
            multiple
            filterable
            default-expand-all
            placeholder="--请选择--"
            clearable
            check-strictly
            collapse-tags
            collapse-tags-tooltip
            :render-after-expand="false"
            style="width: 360px"
          />
          <span class="form-tip" style="margin-left: 10px">商品编辑中，会使用商品对应的分类参数+未关联分类参数</span>
        </el-form-item>

        <el-form-item label="参数类型">
          <el-radio-group v-model="form.type">
            <el-radio :value="0">输入文本</el-radio>
            <el-radio :value="1">单项选择</el-radio>
            <el-radio :value="2">多项选择</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item v-if="form.type > 0" label="设置参数">
          <div style="width: 100%">
            <div v-for="(_, idx) in form.params" :key="idx" class="option-row">
              <el-input v-model="form.params[idx]" placeholder="选项名称" style="flex: 1" />
              <el-button link type="danger" :icon="Delete" @click="removeOptionRow(idx)">删除</el-button>
            </div>
            <el-button type="primary" plain :icon="Plus" @click="addOptionRow">新增</el-button>
          </div>
        </el-form-item>

        <el-form-item label="提示信息">
          <el-input v-model="form.tips" placeholder="请输入提示信息" />
        </el-form-item>

        <el-form-item label="是否必填">
          <el-radio-group v-model="form.is_required">
            <el-radio :value="0">否</el-radio>
            <el-radio :value="1">是</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="序号">
          <el-input-number v-model="form.sort" :min="0" :step="1" />
          <span class="form-tip" style="margin-left: 10px">用于排序,越大越靠前</span>
        </el-form-item>

        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="success" :loading="submitting" @click="handleSubmit">提交</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
}

.toolbar-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.toolbar-right {
  display: flex;
  align-items: center;
  margin-left: auto;
}

.toolbar-right :deep(.el-form-item) {
  margin-bottom: 0;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-start;
}

.form-tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.4;
}

.option-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
</style>
