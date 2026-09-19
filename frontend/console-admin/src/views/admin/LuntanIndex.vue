<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  auditLuntan,
  deleteLuntan,
  getLuntanCategoryOptions,
  getLuntanList,
  setLuntanTop,
} from '../../api/luntan'
import type { LuntanCategoryItem, LuntanPostItem } from '../../api/luntan'

// ============ 列表 ============
const loading = ref(false)
const list = ref<LuntanPostItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(10)
const statusFilter = ref<string>('')
const cidFilter = ref<number | undefined>(undefined)
const keywordFilter = ref('')
const timeRange = ref<[string, string] | null>(null)
const categories = ref<LuntanCategoryItem[]>([])

async function fetchCategories() {
  try {
    categories.value = await getLuntanCategoryOptions()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function fetchList() {
  loading.value = true
  try {
    const res = await getLuntanList({
      page: page.value,
      pagesize: pagesize.value,
      status: statusFilter.value,
      cid: cidFilter.value,
      keyword: keywordFilter.value,
      starttime: timeRange.value?.[0] || '',
      endtime: timeRange.value?.[1] || '',
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

function statusText(row: LuntanPostItem) {
  return row.status === 0 ? '待审核' : row.status === 1 ? '正常' : row.status === 2 ? '已驳回' : '待支付置顶'
}

function statusType(row: LuntanPostItem) {
  return row.status === 0 ? 'warning' : row.status === 1 ? 'success' : row.status === 2 ? 'danger' : 'info'
}

// ============ 审核 / 置顶 / 删除 ============
async function handleAudit(row: LuntanPostItem, status: number) {
  try {
    await auditLuntan([row.id], status)
    ElMessage.success(status === 1 ? '已通过审核' : '已驳回')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSetTop(row: LuntanPostItem) {
  const target = row.is_top ? 0 : 1
  try {
    await setLuntanTop([row.id], target)
    ElMessage.success(target ? '已置顶' : '已取消置顶')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleDelete(row: LuntanPostItem) {
  ElMessageBox.confirm('确定要删除该帖子吗？其评论、回评与置顶订单一并清理。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLuntan([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(() => {
  fetchCategories()
  fetchList()
})
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>帖子列表</span>
          <div class="filter-bar">
            <el-select v-model="statusFilter" placeholder="全部状态" clearable style="width: 130px" @change="handleSearch">
              <el-option label="待审核" value="0" />
              <el-option label="正常" value="1" />
              <el-option label="已驳回" value="2" />
              <el-option label="待支付置顶" value="3" />
            </el-select>
            <el-select v-model="cidFilter" placeholder="全部版块" clearable style="width: 150px" @change="handleSearch">
              <template v-for="c in categories" :key="c.id">
                <el-option :label="c.name" :value="c.id" />
                <el-option v-for="s in c.children || []" :key="s.id" :label="`　${s.name}`" :value="s.id" />
              </template>
            </el-select>
            <el-input
              v-model="keywordFilter"
              placeholder="内容/昵称"
              clearable
              style="width: 160px"
              @keyup.enter="handleSearch"
              @clear="handleSearch"
            />
            <el-date-picker
              v-model="timeRange"
              type="daterange"
              value-format="YYYY-MM-DD"
              range-separator="至"
              start-placeholder="开始日期"
              end-placeholder="结束日期"
              style="width: 240px"
              @change="handleSearch"
            />
            <el-button type="primary" @click="handleSearch">搜索</el-button>
          </div>
        </div>
      </template>

      <el-table v-loading="loading" :data="list" stripe row-key="id">
        <el-table-column label="发布者" width="140">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
              <el-avatar v-else :size="28">{{ (row.nickname || '会员').slice(0, 1) }}</el-avatar>
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="内容" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">
            <el-tag v-if="row.is_top === 1" type="danger" size="small" style="margin-right: 4px">置顶</el-tag>
            {{ row.content }}
          </template>
        </el-table-column>
        <el-table-column prop="cname" label="版块" width="110" show-overflow-tooltip />
        <el-table-column label="图片" width="110">
          <template #default="{ row }">
            <el-image
              v-if="row.pics_arr && row.pics_arr.length"
              :src="row.pics_arr[0]"
              :preview-src-list="row.pics_arr"
              fit="cover"
              style="width: 40px; height: 40px; border-radius: 4px"
            />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="readcount" label="阅读" width="70" />
        <el-table-column prop="zan" label="点赞" width="70" />
        <el-table-column prop="plcount" label="评论" width="70" />
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusType(row)" size="small">{{ statusText(row) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="createtime_text" label="时间" width="160" />
        <el-table-column label="操作" width="240" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 0">
              <el-button link type="success" @click="handleAudit(row, 1)">通过</el-button>
              <el-button link type="warning" @click="handleAudit(row, 2)">驳回</el-button>
            </template>
            <el-button link :type="row.is_top ? 'info' : 'primary'" @click="handleSetTop(row)">
              {{ row.is_top ? '取消置顶' : '置顶' }}
            </el-button>
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
  flex-wrap: wrap;
  gap: 8px;
}

.filter-bar {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
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
