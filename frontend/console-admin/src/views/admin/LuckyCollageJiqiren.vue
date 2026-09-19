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
    // 错误提示已由 axios 拦截器统一弹出
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

async function handleStatusChange(row: LuckyCollageJiqiItem) {
  try {
    await saveLuckyCollageJiqi({
      id: row.id,
      nickname: row.nickname,
      headimg: row.headimg,
      status: row.status,
      sort: row.sort,
    })
    ElMessage.success(row.status === 1 ? '已启用' : '已停用')
  } catch {
    row.status = row.status === 1 ? 0 : 1
  }
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const submitting = ref(false)
const form = reactive({
  id: 0,
  nickname: '',
  headimg: '',
  status: 1,
  sort: 1,
})

function openAdd() {
  Object.assign(form, { id: 0, nickname: '', headimg: '', status: 1, sort: 1 })
  dialogVisible.value = true
}

function openEdit(row: LuckyCollageJiqiItem) {
  Object.assign(form, {
    id: row.id,
    nickname: row.nickname,
    headimg: row.headimg,
    status: row.status,
    sort: row.sort,
  })
  dialogVisible.value = true
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
    dialogVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
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
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
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
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>幸运拼团机器人（昵称/头像池，用于凑团人数）</span>
          <el-button type="success" @click="openAdd">新增机器人</el-button>
        </div>
      </template>

      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column label="头像" width="90">
          <template #default="{ row }">
            <el-avatar v-if="row.headimg" :size="40" :src="row.headimg" />
            <el-avatar v-else :size="40">{{ (row.nickname || '机').slice(0, 1) }}</el-avatar>
          </template>
        </el-table-column>
        <el-table-column prop="nickname" label="昵称" min-width="160" />
        <el-table-column prop="sort" label="排序" width="90" />
        <el-table-column label="启用" width="90">
          <template #default="{ row }">
            <el-switch
              v-model="row.status"
              :active-value="1"
              :inactive-value="0"
              @change="handleStatusChange(row)"
            />
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
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
        :page-sizes="[10, 20, 50, 100]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑机器人' : '新增机器人'" width="440px">
      <el-form label-width="90px" label-position="left">
        <el-form-item label="昵称">
          <el-input v-model="form.nickname" placeholder="请输入机器人昵称" />
        </el-form-item>
        <el-form-item label="头像">
          <div class="img-pick-row">
            <el-image v-if="form.headimg" :src="form.headimg" class="img-pick-thumb" fit="cover" style="border-radius: 50%" />
            <el-button size="small" @click="openImgPicker">选择图片</el-button>
            <el-button v-if="form.headimg" size="small" link type="danger" @click="form.headimg = ''">删除</el-button>
          </div>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="启用">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 选图弹窗（任务294） -->
    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      default-tab="library"
      @pick="(urls: string[]) => onImgPick(urls)"
    />
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.img-pick-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.img-pick-thumb {
  width: 72px;
  height: 72px;
  border-radius: 6px;
  border: 1px solid #ebeef5;
}
</style>
