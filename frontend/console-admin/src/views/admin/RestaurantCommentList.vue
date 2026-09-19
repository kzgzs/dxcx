<script setup lang="ts">
/**
 * 餐饮评价管理（外卖/点餐双域共享实现，scope 区分接口路径）
 * - 列表筛选：内容关键字/状态/时间区间
 * - 操作：批量审核（显示/隐藏）、回复、删除
 * 对照参考系统 restaurant_takeaway_comment / restaurant_shop_comment
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteRestaurantComment,
  getRestaurantCommentList,
  replyRestaurantComment,
  setRestaurantCommentStatus,
  type RestaurantCommentRow,
  type RestaurantCommentScope,
} from '../../api/restaurantExt'
import { formatTime } from '../../utils/time'

const props = withDefaults(defineProps<{ scope?: RestaurantCommentScope; title?: string }>(), {
  scope: 'takeaway',
  title: '外卖评价',
})

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<RestaurantCommentRow[]>([])
const total = ref(0)
const selection = ref<RestaurantCommentRow[]>([])

const query = reactive({
  page: 1,
  limit: 10,
  content: '',
  status: '' as number | '',
  timeRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getRestaurantCommentList(props.scope, {
      page: query.page,
      limit: query.limit,
      content: query.content || undefined,
      status: query.status === '' ? undefined : query.status,
      stime: query.timeRange?.[0] || undefined,
      etime: query.timeRange?.[1] || undefined,
    })
    tableData.value = data.list || []
    total.value = data.count || 0
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
  query.content = ''
  query.status = ''
  query.timeRange = null
  query.page = 1
  fetchList()
}

function handleSelectionChange(rows: RestaurantCommentRow[]) {
  selection.value = rows
}

function idsOf(rows: RestaurantCommentRow[]): number[] {
  return rows.map((r) => r.id)
}

// ============ 审核 ============
async function handleSetst(rows: RestaurantCommentRow[], st: number) {
  if (!rows.length) {
    ElMessage.warning('请先选择评价')
    return
  }
  try {
    await setRestaurantCommentStatus(props.scope, idsOf(rows), st)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 删除 ============
function handleDelete(rows: RestaurantCommentRow[]) {
  if (!rows.length) {
    ElMessage.warning('请先选择评价')
    return
  }
  ElMessageBox.confirm(`确定删除所选 ${rows.length} 条评价吗？删除后不可恢复`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteRestaurantComment(props.scope, idsOf(rows))
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 回复 ============
const replyVisible = ref(false)
const replyRow = ref<RestaurantCommentRow | null>(null)
const replyContent = ref('')
const replySaving = ref(false)

function openReply(row: RestaurantCommentRow) {
  replyRow.value = row
  replyContent.value = row.reply_content || ''
  replyVisible.value = true
}

async function submitReply() {
  if (!replyContent.value.trim()) {
    ElMessage.warning('请填写回复内容')
    return
  }
  replySaving.value = true
  try {
    await replyRestaurantComment(props.scope, replyRow.value!.id, replyContent.value.trim())
    ElMessage.success('回复成功')
    replyVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    replySaving.value = false
  }
}

function scoreTag(score: number): 'success' | 'warning' | 'danger' {
  if (score > 3) return 'success'
  if (score > 2) return 'warning'
  return 'danger'
}

onMounted(fetchList)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="rc-header">
        <span class="rc-title">{{ title }}管理</span>
        <span class="rc-tip">审核为「显示」后将计入菜品评分统计</span>
      </div>
    </template>

    <div class="rc-toolbar">
      <el-input
        v-model="query.content"
        placeholder="评价内容关键字"
        clearable
        style="width: 200px"
        @keyup.enter="handleSearch"
      />
      <el-select v-model="query.status" placeholder="状态" clearable style="width: 120px" @change="handleSearch">
        <el-option label="全部" value="" />
        <el-option label="显示" :value="1" />
        <el-option label="隐藏" :value="0" />
      </el-select>
      <el-date-picker
        v-model="query.timeRange"
        type="daterange"
        value-format="YYYY-MM-DD"
        start-placeholder="开始日期"
        end-placeholder="结束日期"
        style="width: 240px"
      />
      <el-button type="primary" @click="handleSearch">搜索</el-button>
      <el-button @click="handleReset">重置</el-button>
      <div class="rc-toolbar-right">
        <el-button @click="handleSetst(selection, 1)">批量显示</el-button>
        <el-button @click="handleSetst(selection, 0)">批量隐藏</el-button>
        <el-button type="danger" plain @click="handleDelete(selection)">批量删除</el-button>
      </div>
    </div>

    <el-table :data="tableData" v-loading="loading" stripe @selection-change="handleSelectionChange">
      <el-table-column type="selection" width="45" />
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="会员" min-width="130">
        <template #default="{ row }">
          <div class="rc-member">
            <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
            <span>{{ row.nickname || '会员' + row.mid }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column prop="proname" label="菜品" min-width="140" show-overflow-tooltip />
      <el-table-column label="评分" width="90">
        <template #default="{ row }">
          <el-tag :type="scoreTag(row.score)" size="small">{{ row.score }}星</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="评价内容" min-width="220" show-overflow-tooltip>
        <template #default="{ row }">
          <div>{{ row.content }}</div>
          <div v-if="row.content_pic && row.content_pic.length" class="rc-pics">
            <el-image
              v-for="(pic, i) in row.content_pic"
              :key="i"
              :src="pic"
              :preview-src-list="row.content_pic"
              fit="cover"
              class="rc-pic"
            />
          </div>
          <div v-if="row.reply_content" class="rc-reply">商家回复：{{ row.reply_content }}</div>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="80">
        <template #default="{ row }">
          <el-tag v-if="row.status === 1" type="success" size="small">显示</el-tag>
          <el-tag v-else type="info" size="small">隐藏</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="评价时间" width="150">
        <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="240" fixed="right">
        <template #default="{ row }">
          <el-button
            v-if="row.status === 0"
            link
            type="primary"
            @click="handleSetst([row], 1)"
          >显示</el-button>
          <el-button v-else link type="warning" @click="handleSetst([row], 0)">隐藏</el-button>
          <el-button link type="primary" @click="openReply(row)">回复</el-button>
          <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
        </template>
      </el-table-column>
      <template #empty>
        <el-empty description="暂无评价" />
      </template>
    </el-table>

    <div class="rc-pager">
      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.limit"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </div>

    <el-dialog v-model="replyVisible" title="回复评价" width="520px" :close-on-click-modal="false">
      <div v-if="replyRow" class="rc-reply-tip">
        {{ replyRow.nickname || '会员' }}：{{ replyRow.content }}
      </div>
      <el-input
        v-model="replyContent"
        type="textarea"
        :rows="4"
        maxlength="500"
        show-word-limit
        placeholder="请输入回复内容"
      />
      <template #footer>
        <el-button @click="replyVisible = false">取消</el-button>
        <el-button type="primary" :loading="replySaving" @click="submitReply">提交回复</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<style scoped>
.rc-header {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.rc-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.rc-tip {
  font-size: 12px;
  color: #909399;
}

.rc-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

.rc-toolbar-right {
  margin-left: auto;
  display: flex;
  gap: 8px;
}

.rc-member {
  display: flex;
  align-items: center;
  gap: 6px;
}

.rc-pics {
  display: flex;
  gap: 4px;
  margin-top: 4px;
}

.rc-pic {
  width: 40px;
  height: 40px;
  border-radius: 4px;
}

.rc-reply {
  margin-top: 4px;
  font-size: 12px;
  color: #e6a23c;
}

.rc-pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

.rc-reply-tip {
  margin-bottom: 10px;
  padding: 8px 10px;
  background: #f5f7fa;
  border-radius: 4px;
  font-size: 13px;
  color: #606266;
}
</style>
