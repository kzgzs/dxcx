<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteYuyueFuwu, getYuyueFuwuList, saveYuyueFuwu } from '../../api/yuyue'
import type { YuyueFuwuItem, YuyueFuwuListResult } from '../../api/types'

// 商品服务（对齐点大 扩展-预约服务-商品服务：列表 + 平铺增改 .goods-edit + 右上X）
const loading = ref(false)
const tableData = ref<YuyueFuwuItem[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  name: '',
  status: '' as number | '',
})

// 平铺增改面板
const editVisible = ref(false)
const isEdit = ref(false)
const submitting = ref(false)
const form = reactive({
  id: 0,
  name: '',
  desc: '',
  sort: 0,
  status: 1,
})

async function fetchList() {
  loading.value = true
  try {
    const data: YuyueFuwuListResult = await getYuyueFuwuList({
      page: query.page,
      pagesize: query.pagesize,
      name: query.name || undefined,
      status: query.status === '' ? undefined : query.status,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 拦截器统一提示
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handlePageChange(p: number) {
  query.page = p
  fetchList()
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, { id: 0, name: '', desc: '', sort: 0, status: 1 })
  editVisible.value = true
}

function openEdit(row: YuyueFuwuItem) {
  isEdit.value = true
  Object.assign(form, { id: row.id, name: row.name, desc: row.desc || '', sort: row.sort, status: row.status })
  editVisible.value = true
}

function closeEdit() {
  editVisible.value = false
}

async function handleSubmit() {
  if (!form.name.trim()) {
    ElMessage.warning('请输入服务名称')
    return
  }
  submitting.value = true
  try {
    await saveYuyueFuwu({ ...form })
    ElMessage.success('保存成功')
    editVisible.value = false
    fetchList()
  } catch {
    // 拦截器统一提示
  } finally {
    submitting.value = false
  }
}

function handleDeleteRow(row: YuyueFuwuItem) {
  ElMessageBox.confirm(`确认删除「${row.name}」？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueFuwu([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function handleBatchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选要删除的记录')
    return
  }
  ElMessageBox.confirm(`确认删除选中的 ${selectedIds.value.length} 条记录？`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueFuwu(selectedIds.value)
        ElMessage.success('删除成功')
        selectedIds.value = []
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div class="yuyue-fuwu">
    <!-- 增改平铺主区域（进入时隐藏列表，X 返回列表） -->
    <template v-if="editVisible">
      <div class="goods-edit">
        <div class="ge-head">
          <span class="ge-title">{{ isEdit ? '编辑商品服务' : '+ 添加商品服务' }}</span>
          <button class="ge-close" @click="closeEdit">×</button>
        </div>
        <div class="ge-body">
          <div class="form-row">
            <label class="form-label">服务名称</label>
            <el-input v-model="form.name" class="form-control" placeholder="请输入服务名称" />
          </div>
          <div class="form-row">
            <label class="form-label">服务内容</label>
            <el-input v-model="form.desc" type="textarea" :rows="3" class="form-control" placeholder="请输入服务内容" />
          </div>
          <div class="form-row">
            <label class="form-label">序号</label>
            <el-input-number v-model="form.sort" :min="0" class="form-control" />
            <span class="hint">用于排序,越大越靠前</span>
          </div>
          <div class="form-row">
            <label class="form-label">状态</label>
            <el-radio-group v-model="form.status">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
          </div>
          <div class="form-row">
            <label class="form-label"></label>
            <el-button type="primary" :loading="submitting" @click="handleSubmit">提交</el-button>
          </div>
        </div>
      </div>
    </template>

    <!-- 列表态 -->
    <template v-else>
      <!-- 顶部说明 -->
      <div class="tip-bar">用于在商品详情页显示服务标签,如七天退换、正品保障等</div>

      <!-- 工具栏：添加/删除 与 搜索 同行 -->
      <div class="toolbar">
        <div class="btn-row">
          <el-button type="success" @click="openAdd">添加</el-button>
          <el-button type="danger" @click="handleBatchDelete">删除</el-button>
        </div>
        <div class="search-row">
          <span class="lbl">名称</span>
          <el-input v-model="query.name" clearable class="w160" @keyup.enter="handleSearch" />
          <span class="lbl">状态</span>
          <el-select v-model="query.status" placeholder="全部" class="w100">
            <el-option label="全部" :value="''" />
            <el-option label="开启" :value="1" />
            <el-option label="关闭" :value="0" />
          </el-select>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
        </div>
      </div>

      <!-- 列表 -->
      <el-table v-loading="loading" :data="tableData" class="data-table" @selection-change="(rows: YuyueFuwuItem[]) => (selectedIds = rows.map(r => r.id))">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="name" label="名称" min-width="180" />
        <el-table-column prop="desc" label="内容" min-width="220">
          <template #default="{ row }">{{ row.desc || '-' }}</template>
        </el-table-column>
        <el-table-column prop="sort" label="序号" width="80" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <span :class="['status-tag', row.status === 1 ? 'on' : 'off']">{{ row.status === 1 ? '开启' : '关闭' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDeleteRow(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pager"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 20, 50]"
        @current-change="handlePageChange"
        @size-change="(s: number) => { query.pagesize = s; handleSearch() }"
      />
    </template>
  </div>
</template>

<style scoped>
.yuyue-fuwu {
  margin: 3px;
}

.tip-bar {
  background: #f6f8fa;
  border-left: 3px solid #0aa678;
  padding: 10px 14px;
  font-size: 13px;
  color: #555;
  margin-bottom: 3px;
}

.goods-edit {
  background: #fff;
  border: 1px solid #e6e6e6;
  border-radius: 4px;
}

.ge-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  border-bottom: 1px solid #eee;
}

.ge-title {
  font-weight: 600;
}

.ge-close {
  border: none;
  background: transparent;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
  color: #666;
}

.ge-body {
  padding: 14px;
}

.form-row {
  display: flex;
  align-items: flex-start;
  margin-bottom: 14px;
  gap: 10px;
}

.form-label {
  width: 90px;
  text-align: right;
  color: #333;
  flex-shrink: 0;
  line-height: 32px;
}

.form-control {
  flex: 0 1 360px;
  text-align: left;
}

.hint {
  margin-left: 8px;
  color: #999;
  font-size: 12px;
  line-height: 32px;
}

.toolbar {
  background: #fff;
  padding: 8px 10px;
  margin-bottom: 3px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.btn-row,
.search-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.lbl {
  font-size: 13px;
  color: #666;
  white-space: nowrap;
}

.w100 { width: 100px; }
.w160 { width: 160px; }

.data-table :deep(th.el-table__cell) {
  background: #e6e6e6;
  text-align: left;
}

.data-table :deep(.cell) {
  text-align: left;
}

.status-tag.on {
  color: #0aa678;
}

.status-tag.off {
  color: #999;
}

.pager {
  margin-top: 10px;
  justify-content: flex-end;
}
</style>
