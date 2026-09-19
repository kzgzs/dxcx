<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteLuckyCollageComment,
  getLuckyCollageCommentList,
  replyLuckyCollageComment,
} from '../../api/luckyCollage'
import type { LuckyCollageCommentItem } from '../../api/luckyCollage'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const tableData = ref<LuckyCollageCommentItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getLuckyCollageCommentList({
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

// ============ 回复 ============
function handleReply(row: LuckyCollageCommentItem) {
  ElMessageBox.prompt('请输入回复内容', '回复评价', {
    confirmButtonText: '回复',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputValue: row.reply_content || '',
    inputPlaceholder: '请填写回复内容',
  })
    .then(async ({ value }) => {
      try {
        await replyLuckyCollageComment(row.id, (value || '').trim())
        ElMessage.success('回复成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 删除 ============
function handleDelete(row: LuckyCollageCommentItem) {
  ElMessageBox.confirm('确定要删除该评价吗？删除后不可恢复。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLuckyCollageComment(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function picList(pics: string) {
  return pics
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="商品" min-width="160">
          <template #default="{ row }">
            <div class="comment-product">
              <el-image
                v-if="row.propic"
                :src="row.propic"
                fit="cover"
                style="width: 36px; height: 36px; border-radius: 4px"
              />
              <div>
                <div class="product-name">{{ row.proname }}</div>
                <div v-if="row.ggname" class="text-muted">{{ row.ggname }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="会员" min-width="120">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar :size="26" :src="row.headimg || undefined">
                {{ (row.nickname || '会员').slice(0, 1) }}
              </el-avatar>
              <span>{{ row.nickname || '会员' }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评分" width="130">
          <template #default="{ row }">
            <el-rate :model-value="row.score" disabled />
          </template>
        </el-table-column>
        <el-table-column label="评价内容" min-width="220">
          <template #default="{ row }">
            <div>{{ row.content || '（未填写内容）' }}</div>
            <div v-if="picList(row.content_pic).length" class="pic-row">
              <el-image
                v-for="(p, i) in picList(row.content_pic)"
                :key="i"
                :src="p"
                fit="cover"
                :preview-src-list="picList(row.content_pic)"
                style="width: 36px; height: 36px; border-radius: 4px"
              />
            </div>
            <div v-if="row.reply_content" class="reply-tip">
              <el-tag size="small" type="success">商家回复</el-tag>
              {{ row.reply_content }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'warning'" size="small">
              {{ row.status === 1 ? '已显示' : '待审核' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="评价时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleReply(row)">
              {{ row.reply_content ? '改回复' : '回复' }}
            </el-button>
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
  </div>
</template>

<style scoped>
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.comment-product {
  display: flex;
  align-items: center;
  gap: 8px;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}

.product-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 180px;
}

.text-muted {
  color: #909399;
  font-size: 12px;
}

.pic-row {
  display: flex;
  gap: 4px;
  margin-top: 4px;
  flex-wrap: wrap;
}

.reply-tip {
  margin-top: 4px;
  color: #67c23a;
  font-size: 12px;
}
</style>
