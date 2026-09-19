<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteBusinessCity,
  getBusinessCityList,
  saveBusinessCity,
  setBusinessCitySt,
  type BusinessCityItem,
} from '../../api/business'
import { formatTime } from '../../utils/time'

// 商户过滤视图预留（merchantViewOf 注入 bid='gt0'）
withDefaults(defineProps<{ bid?: string }>(), { bid: '' })

const loading = ref(false)
const tableData = ref<BusinessCityItem[]>([])
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
    const data = await getBusinessCityList({
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

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

function handleSelectionChange(rows: BusinessCityItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

// ============ 新增/编辑 ============
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive({
  id: 0,
  name: '',
  province: '',
  city: '',
  district: '',
  status: 1,
  sort: 0,
})

function openAdd() {
  form.id = 0
  form.name = ''
  form.province = ''
  form.city = ''
  form.district = ''
  form.status = 1
  form.sort = 0
  dialogVisible.value = true
}

function openEdit(row: BusinessCityItem) {
  form.id = row.id
  form.name = row.name
  form.province = row.province
  form.city = row.city
  form.district = row.district
  form.status = row.status
  form.sort = row.sort
  dialogVisible.value = true
}

async function submitSave() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写城市名称')
    return
  }
  if (!form.province.trim() && !form.city.trim() && !form.district.trim()) {
    ElMessage.warning('请至少填写省/市/区其中一项')
    return
  }
  saving.value = true
  try {
    await saveBusinessCity({
      id: form.id || undefined,
      name: form.name.trim(),
      province: form.province.trim(),
      city: form.city.trim(),
      district: form.district.trim(),
      status: form.status,
      sort: form.sort,
    })
    ElMessage.success('操作成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

// ============ 启停/删除 ============
async function handleSetst(ids: number[], st: number) {
  if (!ids.length) {
    ElMessage.warning('请选择记录')
    return
  }
  try {
    await setBusinessCitySt(ids, st)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleDelete(ids: number[]) {
  if (!ids.length) {
    ElMessage.warning('请选择记录')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${ids.length} 条记录吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteBusinessCity(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="城市名称">
          <el-input
            v-model="query.name"
            placeholder="城市名称关键词"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px">
            <el-option label="启用" :value="1" />
            <el-option label="停用" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
        <el-form-item style="float: right">
          <el-button type="primary" @click="openAdd">添加城市商户</el-button>
          <el-button type="success" @click="handleSetst(selectedIds, 1)">批量启用</el-button>
          <el-button type="warning" @click="handleSetst(selectedIds, 0)">批量停用</el-button>
          <el-button type="danger" @click="handleDelete(selectedIds)">批量删除</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="城市名称" min-width="120" show-overflow-tooltip />
        <el-table-column prop="province" label="省份" min-width="100" show-overflow-tooltip />
        <el-table-column prop="city" label="城市" min-width="100" show-overflow-tooltip />
        <el-table-column prop="district" label="区县" min-width="100" show-overflow-tooltip />
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag v-if="row.status === 1" type="success">启用</el-tag>
            <el-tag v-else type="info">停用</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="添加时间" width="165">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button
              v-if="row.status !== 1"
              link
              type="success"
              @click="handleSetst([row.id], 1)"
            >启用</el-button>
            <el-button
              v-else
              link
              type="warning"
              @click="handleSetst([row.id], 0)"
            >停用</el-button>
            <el-button link type="danger" @click="handleDelete([row.id])">删除</el-button>
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

    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑城市商户' : '添加城市商户'" width="520px">
      <el-form label-width="90px">
        <el-form-item label="城市名称" required>
          <el-input v-model="form.name" placeholder="如：北京站" maxlength="50" />
        </el-form-item>
        <el-form-item label="省份">
          <el-input v-model="form.province" placeholder="如：北京市" maxlength="30" />
        </el-form-item>
        <el-form-item label="城市">
          <el-input v-model="form.city" placeholder="如：北京市" maxlength="30" />
        </el-form-item>
        <el-form-item label="区县">
          <el-input v-model="form.district" placeholder="如：朝阳区" maxlength="30" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="9999" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitSave">保存</el-button>
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

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
