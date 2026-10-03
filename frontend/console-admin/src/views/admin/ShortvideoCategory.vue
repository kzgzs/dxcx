<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteShortvideoCategory,
  getShortvideoCategoryList,
  saveShortvideoCategory,
  setShortvideoCategoryStatus,
} from '../../api/shortvideo'
import type { ShortvideoCategoryItem } from '../../api/shortvideo'

// 商户过滤视图（bid='gt0'）由 tenantMenus merchantViewOf 注入
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
const isMerchantView = computed(() => props.bid === 'gt0' || props.bid === 'merchant')

// ============ 列表 / 平铺编辑 视图切换 ============
const viewMode = ref<'list' | 'edit'>('list')

// ============ 列表 ============
const loading = ref(false)
const list = ref<ShortvideoCategoryItem[]>([])
const total = ref(0)
const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '' as '' | '1' | '0',
})
const orderby = ref('')
const selected = ref<ShortvideoCategoryItem[]>([])

async function fetchList() {
  loading.value = true
  try {
    const res = await getShortvideoCategoryList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword,
      status: query.status,
      bid: props.bid,
    })
    list.value = res.list
    total.value = res.total
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

function onSortChange({ prop, order }: { prop?: string; order?: 'ascending' | 'descending' | null }) {
  orderby.value = prop && order ? `${prop}|${order === 'ascending' ? 'asc' : 'desc'}` : ''
  handleSearch()
}

function formatTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// ============ 平铺新增 / 编辑（主区域平铺 + 右上 X，非弹窗） ============
const isEdit = ref(false)
const submitting = ref(false)
const form = reactive({
  id: 0,
  name: '',
  status: 1,
  sort: 0,
  bid: 0,
})

function resetForm() {
  Object.assign(form, { id: 0, name: '', status: 1, sort: 0, bid: 0 })
}

function openAdd() {
  isEdit.value = false
  resetForm()
  viewMode.value = 'edit'
}

function openEdit(row: ShortvideoCategoryItem) {
  isEdit.value = true
  resetForm()
  Object.assign(form, { id: row.id, name: row.name, status: row.status, sort: row.sort, bid: row.bid })
  viewMode.value = 'edit'
}

function backToList() {
  viewMode.value = 'list'
  fetchList()
}

async function handleSubmit() {
  if (!form.name.trim()) {
    ElMessage.warning('请输入分类名称')
    return
  }
  submitting.value = true
  try {
    await saveShortvideoCategory({
      id: form.id || undefined,
      name: form.name.trim(),
      status: form.status,
      sort: form.sort,
      bid: form.bid,
    })
    ElMessage.success('保存成功')
    backToList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

// ============ 删除 / 批量启关 ============
async function handleDelete(rows: ShortvideoCategoryItem[]) {
  if (!rows.length) {
    ElMessage.warning('请选择数据')
    return
  }
  await ElMessageBox.confirm(`确定删除选中的 ${rows.length} 个分类吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
  await deleteShortvideoCategory(rows[0].id)
  ElMessage.success('删除成功')
  selected.value = []
  fetchList()
}

async function handleBatchStatus(status: number) {
  if (!selected.value.length) {
    ElMessage.warning('请选择数据')
    return
  }
  const label = status === 1 ? '开启' : '关闭'
  await ElMessageBox.confirm(`确定${label}选中的 ${selected.value.length} 个分类吗？`, '操作确认', { type: 'warning' })
  await setShortvideoCategoryStatus(
    selected.value.map((r) => r.id),
    status,
  )
  ElMessage.success(`已${label}`)
  fetchList()
}

onMounted(fetchList)
</script>

<template>
  <div class="sv-page">
    <!-- ============ 列表视图 ============ -->
    <template v-if="viewMode === 'list'">
      <el-card shadow="never" class="toolbar">
        <div class="toolbar-left">
          <el-button v-if="!isMerchantView" type="success" @click="openAdd">添加</el-button>
          <el-button type="success" plain @click="handleBatchStatus(1)">开启</el-button>
          <el-button type="danger" plain @click="handleBatchStatus(0)">关闭</el-button>
        </div>
        <el-form inline class="toolbar-search" @submit.prevent>
          <el-form-item label="分类名称">
            <el-input
              v-model="query.keyword"
              placeholder="分类名称"
              clearable
              style="width: 150px"
              @keyup.enter="handleSearch"
              @clear="handleSearch"
            />
          </el-form-item>
          <el-form-item label="状态">
            <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px" @change="handleSearch">
              <el-option label="已开启" value="1" />
              <el-option label="已关闭" value="0" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="success" class="search-icon-btn" @click="handleSearch">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274205 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.186354 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
            </el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card shadow="never">
        <el-table v-loading="loading" :data="list" border @selection-change="(rows: ShortvideoCategoryItem[]) => (selected = rows)" @sort-change="onSortChange">
          <el-table-column type="selection" width="42" align="center" />
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column prop="name" label="分类名称" min-width="180" show-overflow-tooltip />
          <el-table-column prop="createtime" label="创建时间" width="160" sortable="custom">
            <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
          </el-table-column>
          <el-table-column prop="sort" label="排序" width="90" sortable="custom" />
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
                {{ row.status === 1 ? '已开启' : '已关闭' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="150">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
              </div>
            </template>
          </el-table-column>
          <template #empty>暂无数据</template>
        </el-table>

        <el-pagination
          v-if="total > 0"
          v-model:current-page="query.page"
          v-model:page-size="query.pagesize"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next, jumper"
          class="pagination"
          @change="fetchList"
        />
      </el-card>
    </template>

    <!-- ============ 平铺新增 / 编辑（主区域 + 右上 X） ============ -->
    <div v-else class="cat-edit">
      <div class="ce-head">
        <span class="ce-title">{{ isEdit ? '✎ 编辑短视频分类' : '✚ 添加短视频分类' }}</span>
        <el-button class="ce-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>
      <el-form label-width="90px" class="ce-form">
        <el-form-item label="名称">
          <el-input v-model="form.name" maxlength="30" show-word-limit style="width: 260px" placeholder="请输入分类名称" />
          <span class="ce-hint">字数30字以内</span>
        </el-form-item>
        <el-form-item label="序号">
          <el-input-number v-model="form.sort" :min="0" :controls="false" style="width: 160px" />
          <span class="ce-hint">用于排序，越大越靠前</span>
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item>
          <el-button type="success" :loading="submitting" @click="handleSubmit">提 交</el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<style scoped>
.toolbar {
  margin-bottom: 3px;
}
.toolbar :deep(.el-card__body) {
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: nowrap;
}
.toolbar-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 0 0 auto;
}
.toolbar-left .el-button {
  margin: 0;
}
.toolbar-search {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  justify-content: flex-end;
  flex-wrap: nowrap;
  min-width: 0;
  margin: 0;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
}
.toolbar-search :deep(.el-form-item:last-child) {
  margin-right: 0;
}
.toolbar-search :deep(.el-form-item__label) {
  padding-right: 6px;
}
.search-icon-btn {
  padding: 8px 14px;
}
.search-icon-btn svg {
  display: block;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
.row-ops {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 8px;
}
.row-ops .el-button {
  margin: 0;
  height: auto;
  min-height: 0;
  padding: 0;
}
/* 平铺编辑 */
.cat-edit {
  background: #fff;
  border-radius: 6px;
  padding: 16px 20px;
}
.ce-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.ce-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}
.ce-close {
  border: none;
  background: transparent;
  color: #909399;
}
.ce-close:hover {
  color: #409eff;
  background: transparent;
}
.ce-form :deep(.el-form-item) {
  margin-bottom: 18px;
}
.ce-hint {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
/* 表头 #e6e6e6，内容居左 */
:deep(.el-table__header th) {
  background: #e6e6e6 !important;
}
:deep(.el-table .cell) {
  text-align: left;
}
</style>
