<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import {
  deleteShopFuwu,
  getShopFuwuList,
  saveShopFuwu,
} from '../../api/shopconfig'
import type { ShopFuwuRow } from '../../api/shopconfig'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<ShopFuwuRow[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  name: '',
  status: '' as number | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getShopFuwuList({
      page: query.page,
      pagesize: query.pagesize,
      name: query.name || undefined,
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

function handleSelectionChange(rows: ShopFuwuRow[]) {
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

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  desc: '',
  sort: 0,
  status: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入服务名称', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, { id: 0, name: '', desc: '', sort: 0, status: 1 })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

function openEdit(row: ShopFuwuRow) {
  isEdit.value = true
  Object.assign(form, { id: row.id, name: row.name, desc: row.desc, sort: row.sort, status: row.status })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveShopFuwu({
        id: form.id,
        name: form.name,
        desc: form.desc,
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
        await deleteShopFuwu(ids)
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

function handleDelete(row: ShopFuwuRow) {
  doDelete([row.id])
}

onMounted(fetchList)
</script>

<template>
  <div>
    <!-- 顶部说明条（对齐参考 blockquote） -->
    <el-alert
      class="tip-alert"
      type="info"
      :closable="false"
      show-icon
      title="用于在商品详情页显示服务标签,如七天退换、正品保障等"
    />

    <!-- 工具栏：左侧操作按钮，右侧搜索 -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleBatchDelete">删除</el-button>
        </div>
        <el-form inline class="toolbar-right" @submit.prevent>
          <el-form-item label="分组名称" class="search-item">
            <el-input
              v-model="query.name"
              placeholder="分组名称"
              clearable
              style="width: 180px"
              @keyup.enter="handleSearch"
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
            <el-button type="primary" :icon="Search" @click="handleSearch" />
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
        <el-table-column prop="name" label="名称" min-width="160" show-overflow-tooltip />
        <el-table-column prop="desc" label="内容" min-width="240" show-overflow-tooltip />
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
            <el-button size="small" @click="openEdit(row)">编辑</el-button>
            <el-button size="small" @click="handleDelete(row)">删除</el-button>
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

    <!-- 添加 / 编辑弹窗（对齐点大"添加商品服务"弹窗） -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑商品服务' : '添加商品服务'" width="560px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px" label-position="left">
        <el-form-item label="服务名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入服务名称" />
        </el-form-item>
        <el-form-item label="服务内容">
          <el-input
            v-model="form.desc"
            type="textarea"
            :rows="4"
            placeholder="请输入服务内容"
            style="width: 300px"
          />
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
.tip-alert {
  margin-bottom: 12px;
}

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
</style>
