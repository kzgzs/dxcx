<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { getKechengCourseList, getKechengStudylogList } from '../../api/kecheng'
import type { KechengCourseItem, KechengStudylogItem } from '../../api/kecheng'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const tableData = ref<KechengStudylogItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(20)
const keyword = ref('')
const filterKcid = ref<number | undefined>(undefined)

const courses = ref<KechengCourseItem[]>([])

async function fetchCourses() {
  try {
    const data = await getKechengCourseList({ page: 1, pagesize: 500 })
    courses.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getKechengStudylogList({
      page: page.value,
      pagesize: pagesize.value,
      keyword: keyword.value,
      kcid: filterKcid.value,
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

onMounted(() => {
  fetchCourses()
  fetchList()
})
</script>

<template>
  <div>
    <!-- 工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="keyword"
            placeholder="章节名 / 手机号"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="课程">
          <el-select
            v-model="filterKcid"
            placeholder="全部课程"
            clearable
            filterable
            style="width: 220px"
          >
            <el-option v-for="c in courses" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">查询</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 学习记录 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="会员" width="160">
          <template #default="{ row }">
            {{ row.member_nickname || '-' }}
            <span v-if="row.member_tel">（{{ row.member_tel }}）</span>
          </template>
        </el-table-column>
        <el-table-column prop="title" label="章节" min-width="180" show-overflow-tooltip />
        <el-table-column label="进度" width="160">
          <template #default="{ row }">
            <el-progress :percentage="Math.min(100, Math.max(0, Number(row.jindu || 0)))" />
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '已学完' : '学习中' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="currentTime" label="播放位置(秒)" width="110" />
        <el-table-column label="学习时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
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
