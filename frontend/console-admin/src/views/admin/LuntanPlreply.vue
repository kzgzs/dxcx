<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { auditLuntanPlreply, deleteLuntanPlreply, getLuntanPlreplyList } from '../../api/luntan'
import type { LuntanPlreplyItem } from '../../api/luntan'

// ============ 列表 ============
const loading = ref(false)
const list = ref<LuntanPlreplyItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(10)
const statusFilter = ref<string>('')
const keywordFilter = ref('')

async function fetchList() {
  loading.value = true
  try {
    const res = await getLuntanPlreplyList({
      page: page.value,
      pagesize: pagesize.value,
      status: statusFilter.value,
      keyword: keywordFilter.value,
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
  page.value = 1
  fetchList()
}

// ============ 审核 / 删除 ============
async function handleAudit(row: LuntanPlreplyItem, status: number) {
  try {
    await auditLuntanPlreply([row.id], status)
    ElMessage.success(status === 1 ? '已显示' : '已隐藏')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleDelete(row: LuntanPlreplyItem) {
  ElMessageBox.confirm('确定要删除该回评吗？', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLuntanPlreply([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>论坛回评</span>
          <div class="filter-bar">
            <el-select v-model="statusFilter" placeholder="全部状态" clearable style="width: 130px" @change="handleSearch">
              <el-option label="显示" value="1" />
              <el-option label="待审核" value="0" />
            </el-select>
            <el-input
              v-model="keywordFilter"
              placeholder="内容/昵称"
              clearable
              style="width: 200px"
              @keyup.enter="handleSearch"
              @clear="handleSearch"
            />
            <el-button type="primary" @click="handleSearch">搜索</el-button>
          </div>
        </div>
      </template>

      <el-table v-loading="loading" :data="list" stripe row-key="id">
        <el-table-column label="会员" width="140">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
              <el-avatar v-else :size="28">{{ (row.nickname || '会员').slice(0, 1) }}</el-avatar>
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="content" label="回评内容" min-width="220" show-overflow-tooltip />
        <el-table-column prop="pinglun_content" label="所属评论" min-width="160" show-overflow-tooltip />
        <el-table-column prop="zan" label="点赞" width="70" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'warning'" size="small">{{ row.status === 1 ? '显示' : '待审核' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="createtime_text" label="时间" width="160" />
        <el-table-column label="操作" width="170" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 0">
              <el-button link type="success" @click="handleAudit(row, 1)">通过</el-button>
            </template>
            <el-button v-else link type="warning" @click="handleAudit(row, 0)">隐藏</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > 0"
        class="pager"
        v-model:current-page="page"
        v-model:page-size="pagesize"
        :total="total"
        layout="total, prev, pager, next"
        @current-change="fetchList"
      />
    </el-card>
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.filter-bar {
  display: flex;
  gap: 8px;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}

.pager {
  margin-top: 12px;
  justify-content: flex-end;
}
</style>
