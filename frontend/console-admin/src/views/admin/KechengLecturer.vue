<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  checkKechengLecturer,
  getKechengLecturerList,
  setKechengLecturerStatus,
} from '../../api/kecheng'
import type { KechengLecturerItem } from '../../api/kecheng'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const tableData = ref<KechengLecturerItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(20)
const keyword = ref('')
const filterCheck = ref<string>('')

async function fetchList() {
  loading.value = true
  try {
    const data = await getKechengLecturerList({
      page: page.value,
      pagesize: pagesize.value,
      keyword: keyword.value,
      checkstatus: filterCheck.value,
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
  page.value = 1
  fetchList()
}

const CHECK_TAG: Record<number, { text: string; type: 'warning' | 'success' | 'danger' }> = {
  0: { text: '待审核', type: 'warning' },
  1: { text: '已通过', type: 'success' },
  [-1]: { text: '已驳回', type: 'danger' },
}

function checkTag(row: KechengLecturerItem) {
  return CHECK_TAG[row.checkstatus] || CHECK_TAG[0]
}

async function handlePass(row: KechengLecturerItem) {
  try {
    await ElMessageBox.confirm(`确认通过「${row.realname || row.nickname}」的讲师申请？`, '提示', {
      type: 'success',
    })
    await checkKechengLecturer({ id: row.id, checkstatus: 1 })
    ElMessage.success('已通过审核')
    fetchList()
  } catch {
    // 取消或错误提示已由拦截器弹出
  }
}

async function handleReject(row: KechengLecturerItem) {
  try {
    const { value } = await ElMessageBox.prompt('请填写驳回原因', '驳回申请', {
      confirmButtonText: '驳回',
      cancelButtonText: '取消',
      inputType: 'textarea',
      inputValidator: (v: string) => (v && v.trim() ? true : '驳回原因不能为空'),
    })
    await checkKechengLecturer({ id: row.id, checkstatus: -1, reason: value })
    ElMessage.success('已驳回申请')
    fetchList()
  } catch {
    // 取消或错误提示已由拦截器弹出
  }
}

async function handleToggleStatus(row: KechengLecturerItem) {
  try {
    await setKechengLecturerStatus(row.id, row.status === 1 ? 0 : 1)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

onMounted(fetchList)
</script>

<template>
  <div>
    <!-- 工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="keyword"
            placeholder="昵称 / 姓名 / 手机号"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="审核状态">
          <el-select v-model="filterCheck" placeholder="全部" clearable style="width: 120px">
            <el-option label="待审核" value="0" />
            <el-option label="已通过" value="1" />
            <el-option label="已驳回" value="-1" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">查询</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="头像" width="80">
          <template #default="{ row }">
            <el-avatar v-if="row.headimg" :src="row.headimg" :size="40" />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="nickname" label="昵称" min-width="120" show-overflow-tooltip />
        <el-table-column prop="realname" label="姓名" width="110" />
        <el-table-column prop="tel" label="手机号" width="130" />
        <el-table-column prop="shortdesc" label="简介" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.shortdesc || '-' }}</template>
        </el-table-column>
        <el-table-column prop="course_count" label="课程数" width="80" />
        <el-table-column label="审核状态" width="100">
          <template #default="{ row }">
            <el-tooltip
              v-if="row.checkstatus === -1 && row.check_reason"
              :content="row.check_reason"
              placement="top"
            >
              <el-tag :type="checkTag(row).type" size="small">{{ checkTag(row).text }}</el-tag>
            </el-tooltip>
            <el-tag v-else :type="checkTag(row).type" size="small">{{ checkTag(row).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="启用" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="申请时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <template v-if="row.checkstatus === 0">
              <el-button link type="success" @click="handlePass(row)">通过</el-button>
              <el-button link type="danger" @click="handleReject(row)">驳回</el-button>
            </template>
            <template v-else-if="row.checkstatus === 1">
              <el-button link type="danger" @click="handleReject(row)">驳回</el-button>
            </template>
            <template v-else>
              <el-button link type="success" @click="handlePass(row)">通过</el-button>
            </template>
            <el-button link type="warning" @click="handleToggleStatus(row)">
              {{ row.status === 1 ? '停用' : '启用' }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pager"
        v-model:current-page="page"
        v-model:page-size="pagesize"
        :total="total"
        :page-sizes="[20, 50, 100]"
        layout="total, sizes, prev, pager, next"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </el-card>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
