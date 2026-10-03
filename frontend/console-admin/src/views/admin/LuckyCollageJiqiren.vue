<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteLuckyCollageJiqi,
  getLuckyCollageJiqiList,
  saveLuckyCollageJiqi,
} from '../../api/luckyCollage'
import type { LuckyCollageJiqiItem } from '../../api/luckyCollage'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const tableData = ref<LuckyCollageJiqiItem[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getLuckyCollageJiqiList({
      page: query.page,
      pagesize: query.pagesize,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    /* 拦截器统一提示 */
  } finally {
    loading.value = false
  }
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

function handleSelectionChange(rows: LuckyCollageJiqiItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

function handleDelete(row: LuckyCollageJiqiItem) {
  ElMessageBox.confirm(`确定要删除机器人「${row.nickname}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLuckyCollageJiqi(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

function batchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先选择机器人')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${selectedIds.value.length} 个机器人吗？`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        for (const id of selectedIds.value) {
          await deleteLuckyCollageJiqi(id)
        }
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

// ============ 平铺新增 / 编辑 ============
const isEdit = ref(false)
const submitting = ref(false)
const form = reactive({
  id: 0,
  nickname: '',
  headimg: '',
})

function openAdd() {
  Object.assign(form, { id: 0, nickname: '', headimg: '' })
  isEdit.value = true
}

function openEdit(row: LuckyCollageJiqiItem) {
  Object.assign(form, {
    id: row.id,
    nickname: row.nickname,
    headimg: row.headimg,
  })
  isEdit.value = true
}

function backToList() {
  isEdit.value = false
  Object.assign(form, { id: 0, nickname: '', headimg: '' })
}

async function handleSubmit() {
  if (!form.nickname.trim()) {
    ElMessage.warning('请输入机器人昵称')
    return
  }
  submitting.value = true
  try {
    await saveLuckyCollageJiqi({ ...form, id: form.id || undefined })
    ElMessage.success('保存成功')
    isEdit.value = false
    fetchList()
  } catch {
    /* 拦截器统一提示 */
  } finally {
    submitting.value = false
  }
}

// ============ 图片选择 ============
const imgPickerVisible = ref(false)
function openImgPicker() {
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls.length) form.headimg = urls[0]
}

onMounted(fetchList)
</script>

<template>
  <!-- ============ 列表视图 ============ -->
  <div v-if="!isEdit" class="lucky-jiqi">
    <div class="page-head"><span class="head-title">机器人</span></div>
    <div class="toolbar">
      <div class="toolbar-left">
        <el-button type="success" @click="openAdd">添加</el-button>
        <el-button :disabled="!selectedIds.length" @click="batchDelete">删除</el-button>
      </div>
    </div>

    <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
      <el-table-column type="selection" width="40" />
      <el-table-column prop="id" label="ID" width="80" />
      <el-table-column prop="nickname" label="昵称" min-width="160" />
      <el-table-column label="头像" width="120">
        <template #default="{ row }">
          <el-image v-if="row.headimg" :src="row.headimg" fit="cover" class="head-thumb" />
        </template>
      </el-table-column>
      <el-table-column label="创建时间" width="160">
        <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="120">
        <template #default="{ row }">
          <div class="row-ops">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </div>
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

    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      default-tab="library"
      @pick="(urls: string[]) => onImgPick(urls)"
    />
  </div>

  <!-- ============ 平铺编辑视图 ============ -->
  <div v-else class="jiqi-edit">
    <div class="edit-head">
      <span class="edit-title">{{ form.id ? '编辑机器人' : '添加机器人' }}</span>
      <button class="edit-close" @click="backToList">✕</button>
    </div>
    <el-form label-width="90px" label-position="right" style="max-width: 560px">
      <el-form-item label="昵称" required>
        <el-input v-model="form.nickname" placeholder="请输入机器人昵称" style="width: 240px" />
      </el-form-item>
      <el-form-item label="头像">
        <div class="img-pick-row">
          <el-image v-if="form.headimg" :src="form.headimg" class="img-pick-thumb" fit="cover" />
          <el-button size="small" @click="openImgPicker">上传图片</el-button>
          <span class="form-tip">建议尺寸：400×400像素</span>
        </div>
      </el-form-item>
      <el-form-item>
        <el-button type="success" :loading="submitting" @click="handleSubmit">提 交</el-button>
      </el-form-item>
    </el-form>

    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      default-tab="library"
      @pick="(urls: string[]) => onImgPick(urls)"
    />
  </div>
</template>

<style scoped>
.lucky-jiqi { margin: 3px; background: #fff; padding: 12px 16px; }
.page-head { margin-bottom: 8px; }
.head-title { font-size: 15px; font-weight: 600; }
.toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.toolbar-left { display: flex; gap: 8px; }
.head-thumb { width: 40px; height: 40px; border-radius: 4px; }
.pagination { margin-top: 8px; justify-content: flex-end; }
.row-ops { display: flex; flex-direction: row; gap: 6px; }
:deep(.el-table__header th) { background: #e6e6e6; }
:deep(.el-table .cell) { text-align: left; }

.jiqi-edit { background: #fff; padding: 16px 20px; margin: 3px; }
.edit-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.edit-title { font-size: 15px; font-weight: 600; }
.edit-close { border: none; background: transparent; color: #909399; font-size: 18px; cursor: pointer; }
.img-pick-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.img-pick-thumb { width: 100px; height: 100px; border-radius: 6px; border: 1px solid #ebeef5; }
.form-tip { color: #909399; font-size: 12px; }
</style>
