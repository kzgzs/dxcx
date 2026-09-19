<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  addRestaurantArea,
  addRestaurantTable,
  deleteRestaurantArea,
  deleteRestaurantTable,
  editRestaurantArea,
  editRestaurantTable,
  getRestaurantAreaList,
  getRestaurantTableList,
} from '../../api/restaurant'
import type { RestaurantAreaItem, RestaurantTableItem } from '../../api/types'

// ============ 区域列表 ============
const areaList = ref<RestaurantAreaItem[]>([])
const activeArea = ref<number | ''>('')

async function fetchAreas() {
  try {
    const data = await getRestaurantAreaList()
    areaList.value = data.list || []
    // 当前选中区域被删除时回退到全部
    if (activeArea.value !== '' && !areaList.value.some((a) => a.id === activeArea.value)) {
      activeArea.value = ''
    }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleAreaClick(id: number | '') {
  activeArea.value = id
  query.page = 1
  fetchTables()
}

// ============ 桌台列表 ============
const loading = ref(false)
const tableData = ref<RestaurantTableItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 20,
})

async function fetchTables() {
  loading.value = true
  try {
    const data = await getRestaurantTableList({
      area_id: activeArea.value === '' ? undefined : activeArea.value,
      page: query.page,
      pagesize: query.pagesize,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handlePageChange(page: number) {
  query.page = page
  fetchTables()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchTables()
}

// ============ 删除桌台 ============
function handleTableDelete(row: RestaurantTableItem) {
  ElMessageBox.confirm(`确定要删除桌台「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteRestaurantTable(row.id)
        ElMessage.success('删除成功')
        fetchTables()
        fetchAreas()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 桌台新增 / 编辑弹窗 ============
const tableDialogVisible = ref(false)
const isTableEdit = ref(false)
const tableFormRef = ref<FormInstance>()
const tableSubmitting = ref(false)

const tableForm = reactive({
  id: 0,
  area_id: '' as number | '',
  name: '',
  persons: 4,
  sort: 0,
})

const tableRules: FormRules = {
  area_id: [{ required: true, message: '请选择所属区域', trigger: 'change' }],
  name: [{ required: true, message: '请输入桌台名称', trigger: 'blur' }],
  persons: [{ required: true, message: '请输入容纳人数', trigger: 'blur' }],
}

function openTableAdd() {
  isTableEdit.value = false
  Object.assign(tableForm, { id: 0, area_id: activeArea.value, name: '', persons: 4, sort: 0 })
  tableDialogVisible.value = true
}

function openTableEdit(row: RestaurantTableItem) {
  isTableEdit.value = true
  Object.assign(tableForm, {
    id: row.id,
    area_id: row.area_id,
    name: row.name,
    persons: row.persons,
    sort: row.sort,
  })
  tableDialogVisible.value = true
}

async function handleTableSubmit() {
  if (!tableFormRef.value) return
  await tableFormRef.value.validate(async (valid) => {
    if (!valid) return
    tableSubmitting.value = true
    try {
      const params = {
        id: tableForm.id,
        area_id: Number(tableForm.area_id),
        name: tableForm.name,
        persons: tableForm.persons,
        sort: tableForm.sort,
      }
      if (isTableEdit.value) {
        await editRestaurantTable(params)
        ElMessage.success('保存成功')
      } else {
        await addRestaurantTable(params)
        ElMessage.success('新增成功')
      }
      tableDialogVisible.value = false
      fetchTables()
      fetchAreas()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      tableSubmitting.value = false
    }
  })
}

// ============ 区域管理弹窗（内联） ============
const areaDialogVisible = ref(false)
const areaFormRef = ref<FormInstance>()
const areaSubmitting = ref(false)
const areaForm = reactive({
  id: 0,
  name: '',
  sort: 0,
})
const areaRules: FormRules = {
  name: [{ required: true, message: '请输入区域名称', trigger: 'blur' }],
}

function openAreaDialog() {
  fetchAreas()
  Object.assign(areaForm, { id: 0, name: '', sort: 0 })
  areaDialogVisible.value = true
}

function openAreaEdit(row: RestaurantAreaItem) {
  Object.assign(areaForm, { id: row.id, name: row.name, sort: row.sort })
  areaFormRef.value?.clearValidate()
}

function cancelAreaEdit() {
  Object.assign(areaForm, { id: 0, name: '', sort: 0 })
  areaFormRef.value?.clearValidate()
}

async function handleAreaSave() {
  if (!areaFormRef.value) return
  await areaFormRef.value.validate(async (valid) => {
    if (!valid) return
    areaSubmitting.value = true
    try {
      const params = {
        id: areaForm.id,
        name: areaForm.name,
        sort: areaForm.sort,
      }
      if (areaForm.id) {
        await editRestaurantArea(params)
        ElMessage.success('保存成功')
      } else {
        await addRestaurantArea(params)
        ElMessage.success('新增成功')
      }
      Object.assign(areaForm, { id: 0, name: '', sort: 0 })
      fetchAreas()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      areaSubmitting.value = false
    }
  })
}

function handleAreaDelete(row: RestaurantAreaItem) {
  ElMessageBox.confirm(`确定要删除区域「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteRestaurantArea(row.id)
        ElMessage.success('删除成功')
        fetchAreas()
        if (activeArea.value === row.id) {
          activeArea.value = ''
          fetchTables()
        }
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(() => {
  fetchAreas()
  fetchTables()
})
</script>

<template>
  <div>
    <el-row :gutter="16">
      <!-- 左侧：区域列表 -->
      <el-col :span="5">
        <el-card shadow="never" class="area-card">
          <template #header>
            <div class="area-header">
              <span>区域</span>
              <el-button link type="primary" @click="openAreaDialog">管理</el-button>
            </div>
          </template>
          <div
            class="area-item"
            :class="{ active: activeArea === '' }"
            @click="handleAreaClick('')"
          >
            全部区域
          </div>
          <div
            v-for="a in areaList"
            :key="a.id"
            class="area-item"
            :class="{ active: activeArea === a.id }"
            @click="handleAreaClick(a.id)"
          >
            <span>{{ a.name }}</span>
            <span class="area-count">{{ a.table_count }}桌</span>
          </div>
          <el-empty v-if="areaList.length === 0" description="暂无区域" :image-size="60" />
        </el-card>
      </el-col>

      <!-- 右侧：桌台表格 -->
      <el-col :span="19">
        <el-card shadow="never">
          <div class="table-toolbar">
            <span class="table-toolbar-title">桌台列表</span>
            <el-button type="success" @click="openTableAdd">新增桌台</el-button>
          </div>

          <el-table v-loading="loading" :data="tableData" stripe>
            <el-table-column prop="id" label="ID" width="70" />
            <el-table-column prop="name" label="桌台名称" min-width="120" />
            <el-table-column prop="area_name" label="区域" width="110" />
            <el-table-column label="容纳人数" width="100">
              <template #default="{ row }">{{ row.persons }}人</template>
            </el-table-column>
            <el-table-column prop="sort" label="排序" width="80" />
            <el-table-column label="占用状态" width="100">
              <template #default="{ row }">
                <el-tag :type="row.busy === 1 ? 'danger' : 'success'" size="small">
                  {{ row.busy === 1 ? '占用中' : '空闲' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="130" fixed="right">
              <template #default="{ row }">
                <el-button link type="primary" @click="openTableEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleTableDelete(row)">删除</el-button>
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
            :page-sizes="[10, 20, 50, 100]"
            @current-change="handlePageChange"
            @size-change="handleSizeChange"
          />
        </el-card>
      </el-col>
    </el-row>

    <!-- 桌台新增 / 编辑弹窗 -->
    <el-dialog
      v-model="tableDialogVisible"
      :title="isTableEdit ? '编辑桌台' : '新增桌台'"
      width="440px"
    >
      <el-form
        ref="tableFormRef"
        :model="tableForm"
        :rules="tableRules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="所属区域" prop="area_id">
          <el-select v-model="tableForm.area_id" placeholder="请选择区域" style="width: 100%">
            <el-option
              v-for="a in areaList"
              :key="a.id"
              :label="a.name"
              :value="a.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="桌台名称" prop="name">
          <el-input v-model="tableForm.name" placeholder="如：A01" />
        </el-form-item>
        <el-form-item label="容纳人数" prop="persons">
          <el-input-number v-model="tableForm.persons" :min="1" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="tableForm.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="tableDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="tableSubmitting" @click="handleTableSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>

    <!-- 区域管理弹窗 -->
    <el-dialog v-model="areaDialogVisible" title="区域管理" width="520px">
      <el-table :data="areaList" stripe max-height="320">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="区域名称" min-width="120" />
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column prop="table_count" label="桌台数" width="80" />
        <el-table-column label="操作" width="130">
          <template #default="{ row }">
            <el-button link type="primary" @click="openAreaEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleAreaDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-form
        ref="areaFormRef"
        :model="areaForm"
        :rules="areaRules"
        inline
        class="area-form"
      >
        <el-form-item prop="name">
          <el-input v-model="areaForm.name" placeholder="区域名称" style="width: 160px" />
        </el-form-item>
        <el-form-item>
          <el-input-number v-model="areaForm.sort" :min="0" :precision="0" :step="1" placeholder="排序" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="areaSubmitting" @click="handleAreaSave">
            {{ areaForm.id ? '保存' : '新增' }}
          </el-button>
          <el-button v-if="areaForm.id" @click="cancelAreaEdit">取消编辑</el-button>
        </el-form-item>
      </el-form>
    </el-dialog>
  </div>
</template>

<style scoped>
.area-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.area-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px;
  border-radius: 6px;
  cursor: pointer;
  color: #606266;
}

.area-item:hover {
  background: #f5f7fa;
}

.area-item.active {
  background: #ecf5ff;
  color: #409eff;
  font-weight: 600;
}

.area-count {
  font-size: 12px;
  color: #909399;
}

.table-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.table-toolbar-title {
  font-weight: 600;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.area-form {
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px solid #ebeef5;
}
</style>
