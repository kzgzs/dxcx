<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  delPeisongComment,
  getPeisongCommentDetail,
  getPeisongCommentList,
  getPeisongUserOptions,
} from '../../api/peisong'
import type { PeisongCommentItem, PsUserOption } from '../../api/peisong'
import { formatTime } from '../../utils/time'

// 租户后台 - 同城配送·配送评价（批次8 任务223；对标参考系统 PeisongComment）

const loading = ref(false)
const tableData = ref<PeisongCommentItem[]>([])
const total = ref(0)
const selection = ref<PeisongCommentItem[]>([])
const psOptions = ref<PsUserOption[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  psid: '',
  content: '',
  timeRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getPeisongCommentList({
      page: query.page,
      pagesize: query.pagesize,
      psid: query.psid === '' ? undefined : query.psid,
      content: query.content || undefined,
      start_date: query.timeRange?.[0] || undefined,
      end_date: query.timeRange?.[1] || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // axios 拦截器已提示
  } finally {
    loading.value = false
  }
}

async function fetchPsOptions() {
  try {
    const data = await getPeisongUserOptions()
    psOptions.value = data.list || []
  } catch {
    // 忽略
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.psid = ''
  query.content = ''
  query.timeRange = null
  query.page = 1
  fetchList()
}

// 详情弹窗
const detailVisible = ref(false)
const detailData = ref<{ detail: PeisongCommentItem; member: { nickname: string; headimg: string } } | null>(null)

async function handleDetail(row: PeisongCommentItem) {
  try {
    const data = await getPeisongCommentDetail(row.id)
    detailData.value = data
    detailVisible.value = true
  } catch {
    // axios 拦截器已提示
  }
}

async function handleDel(row?: PeisongCommentItem) {
  const ids = row ? [row.id] : selection.value.map((v) => v.id)
  if (!ids.length) return ElMessage.warning('请先选择评价')
  await ElMessageBox.confirm(`确认删除选中的 ${ids.length} 条评价？`, '提示', { type: 'warning' })
  await delPeisongComment(ids)
  ElMessage.success('删除成功')
  fetchList()
}

onMounted(() => {
  fetchList()
  fetchPsOptions()
})
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="配送员">
          <el-select v-model="query.psid" placeholder="全部" clearable filterable style="width: 150px">
            <el-option v-for="u in psOptions" :key="u.id" :label="`${u.realname}（${u.tel}）`" :value="String(u.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="评价内容">
          <el-input v-model="query.content" placeholder="关键字" clearable style="width: 150px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="query.timeRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            value-format="YYYY-MM-DD"
            style="width: 220px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <div class="op-row">
        <el-button type="danger" plain @click="handleDel()">批量删除</el-button>
      </div>

      <el-table v-loading="loading" :data="tableData" stripe border size="small" @selection-change="(rows: PeisongCommentItem[]) => (selection = rows)">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
        <el-table-column label="会员" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.nickname || `会员${row.mid}` }}</template>
        </el-table-column>
        <el-table-column label="评分" width="130" class-name="compact-col">
          <template #default="{ row }">
            <el-rate :model-value="row.score" disabled size="small" />
          </template>
        </el-table-column>
        <el-table-column prop="content" label="评价内容" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">{{ row.content || '-' }}</template>
        </el-table-column>
        <el-table-column label="配送员" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.psuser.realname">{{ row.psuser.realname }}<span class="sub-text">{{ row.psuser.tel }}</span></span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="商家" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.business.name || '自营' }}</template>
        </el-table-column>
        <el-table-column label="订单号" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.ordernum || '-' }}</template>
        </el-table-column>
        <el-table-column label="时间" width="88" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ formatTime(row.createtime).slice(2, 10) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="110" fixed="right" class-name="compact-col">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="handleDetail(row)">详情</el-button>
            <el-button link type="danger" size="small" @click="handleDel(row)">删除</el-button>
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
        @current-change="(p: number) => { query.page = p; fetchList() }"
        @size-change="(s: number) => { query.pagesize = s; query.page = 1; fetchList() }"
      />
    </el-card>

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailVisible" title="评价详情" width="520px" destroy-on-close>
      <template v-if="detailData">
        <el-descriptions :column="1" border size="small">
          <el-descriptions-item label="会员">{{ detailData.member.nickname || '-' }}</el-descriptions-item>
          <el-descriptions-item label="评分">
            <el-rate :model-value="Number(detailData.detail.score)" disabled size="small" />
          </el-descriptions-item>
          <el-descriptions-item label="评价内容">{{ detailData.detail.content || '-' }}</el-descriptions-item>
          <el-descriptions-item v-if="detailData.detail.content_pic && detailData.detail.content_pic.length" label="评价图片">
            <el-image
              v-for="(pic, i) in detailData.detail.content_pic"
              :key="i"
              :src="pic"
              :preview-src-list="detailData.detail.content_pic"
              fit="cover"
              class="comment-pic"
            />
          </el-descriptions-item>
          <el-descriptions-item label="回复">{{ detailData.detail.reply_content || '未回复' }}</el-descriptions-item>
          <el-descriptions-item label="时间">{{ detailData.detail.createtime ? formatTime(detailData.detail.createtime) : '-' }}</el-descriptions-item>
        </el-descriptions>
      </template>
      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 12px;
}

.op-row {
  margin-bottom: 12px;
}

.sub-text {
  color: #909399;
  font-size: 12px;
  margin-left: 4px;
}

.comment-pic {
  width: 64px;
  height: 64px;
  margin-right: 6px;
  border-radius: 4px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
