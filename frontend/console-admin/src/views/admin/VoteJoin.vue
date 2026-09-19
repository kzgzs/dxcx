<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { UploadProps } from 'element-plus'
import {
  addVoteJoinPiao,
  auditVoteJoin,
  deleteVoteJoin,
  getVoteJoinList,
  getVoteList,
  getVoteStat,
  saveVoteJoin,
} from '../../api/vote'
import type { VoteActivityItem, VoteJoinItem, VoteStatResult } from '../../api/vote'
import { formatTime } from '../../utils/time'
import { useAuthStore } from '../../stores/auth'

const authStore = useAuthStore()

// tabs：待审核 / 已通过 / 已驳回 / 排行
const activeTab = ref<'pending' | 'passed' | 'rejected' | 'rank'>('pending')

const statusOfTab: Record<'pending' | 'passed' | 'rejected', number> = {
  pending: 0,
  passed: 1,
  rejected: 2,
}

// ============ 上传通用 ============
const uploadHeaders = computed(() => ({
  Authorization: `Bearer ${authStore.token}`,
}))

function parseUploadResponse(response: { code?: number; msg?: string; data?: { url?: string } }): string {
  if (response && response.code === 0 && response.data?.url) {
    return response.data.url
  }
  ElMessage.error(response?.msg || '上传失败')
  return ''
}

const onUploadError: UploadProps['onError'] = () => {
  ElMessage.error('上传失败，请重试')
}

// ============ 活动下拉 ============
const activityOptions = ref<VoteActivityItem[]>([])

async function loadActivities() {
  const res = await getVoteList({ page: 1, pagesize: 100 })
  activityOptions.value = res.list || []
}

function activityName(hid: number): string {
  return activityOptions.value.find((a) => a.id === hid)?.name || `活动${hid}`
}

// ============ 选手列表 ============
const joinLoading = ref(false)
const joinData = ref<VoteJoinItem[]>([])
const joinTotal = ref(0)
const joinQuery = reactive({
  page: 1,
  pagesize: 10,
  hid: 0,
  keyword: '',
})

async function loadJoins() {
  joinLoading.value = true
  try {
    const res = await getVoteJoinList({
      ...joinQuery,
      status: statusOfTab[activeTab.value as 'pending' | 'passed' | 'rejected'],
    })
    joinData.value = res.list || []
    joinTotal.value = res.total || 0
  } finally {
    joinLoading.value = false
  }
}

function handleSearch() {
  joinQuery.page = 1
  loadJoins()
}

function handleTabChange() {
  selectedIds.value = []
  if ('rank' === activeTab.value) {
    loadRank()
  } else {
    handleSearch()
  }
}

// ============ 审核（状态机：待审核 → 通过/驳回；驳回可重新通过） ============
const selectedIds = ref<number[]>([])

function handleSelectionChange(rows: VoteJoinItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

async function handleAudit(ids: number[], pass: boolean) {
  if (pass) {
    await ElMessageBox.confirm(`确定通过所选 ${ids.length} 名选手？通过后将自动生成选手编号`, '提示', { type: 'warning' })
    await auditVoteJoin(ids, 1)
    ElMessage.success('已通过')
  } else {
    const { value } = await ElMessageBox.prompt('请填写驳回原因', '驳回选手', {
      confirmButtonText: '驳回',
      cancelButtonText: '取消',
      inputPlaceholder: '驳回原因（必填）',
      inputValidator: (v: string) => (!!v && !!v.trim()) || '驳回原因不能为空',
    })
    await auditVoteJoin(ids, 2, String(value).trim())
    ElMessage.success('已驳回')
  }
  selectedIds.value = []
  loadJoins()
}

// ============ 选手编辑弹窗（新增/编辑） ============
const joinDialogVisible = ref(false)
const joinSaving = ref(false)
const joinForm = reactive({
  id: 0,
  hid: 0,
  name: '',
  pic: '',
  detail_txt: '',
  weixin: '',
  status: 1,
  sort: 0,
})

const joinPicFileList = computed(() => (joinForm.pic ? [{ name: 'pic', url: joinForm.pic }] : []))

function openJoinAdd() {
  Object.assign(joinForm, {
    id: 0,
    hid: joinQuery.hid || activityOptions.value[0]?.id || 0,
    name: '',
    pic: '',
    detail_txt: '',
    weixin: '',
    status: 1,
    sort: 0,
  })
  joinDialogVisible.value = true
}

function openJoinEdit(row: VoteJoinItem) {
  Object.assign(joinForm, {
    id: row.id,
    hid: row.hid,
    name: row.name,
    pic: row.pic,
    detail_txt: row.detail_txt,
    weixin: row.weixin,
    status: row.status,
    sort: row.sort,
  })
  joinDialogVisible.value = true
}

async function handleJoinSave() {
  if (!joinForm.hid) {
    ElMessage.warning('请选择所属活动')
    return
  }
  if (!joinForm.name.trim()) {
    ElMessage.warning('请填写选手名称')
    return
  }
  joinSaving.value = true
  try {
    await saveVoteJoin({ ...joinForm })
    ElMessage.success('保存成功')
    joinDialogVisible.value = false
    if ('rank' !== activeTab.value) loadJoins()
  } finally {
    joinSaving.value = false
  }
}

async function handleJoinDelete(row: VoteJoinItem) {
  await ElMessageBox.confirm(`确定删除选手「${row.name}」？其投票记录将一并删除`, '提示', { type: 'warning' })
  await deleteVoteJoin(row.id)
  ElMessage.success('删除成功')
  loadJoins()
}

// ============ 加票 ============
async function handleAddpiao(row: VoteJoinItem) {
  const { value } = await ElMessageBox.prompt(`给「${row.name}」加票`, '后台加票', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    inputPlaceholder: '加票数（正整数）',
    inputValidator: (v: string) => (/^[1-9]\d*$/.test(String(v || '')) ? true : '请输入正整数'),
  })
  await addVoteJoinPiao(row.id, Number(value))
  ElMessage.success('加票成功')
  loadJoins()
}

// ============ 排行统计 ============
const rankLoading = ref(false)
const rankResult = ref<VoteStatResult | null>(null)
const rankHid = ref(0)
const rankTop = ref(10)

async function loadRank() {
  if (!rankHid.value) {
    rankResult.value = null
    return
  }
  rankLoading.value = true
  try {
    rankResult.value = await getVoteStat(rankHid.value, rankTop.value)
  } finally {
    rankLoading.value = false
  }
}

onMounted(() => {
  loadActivities()
  loadJoins()
})
</script>

<template>
  <div class="vote-join">
    <el-tabs v-model="activeTab" @tab-change="handleTabChange">
      <!-- ============ 待审核 ============ -->
      <el-tab-pane label="待审核" name="pending">
        <div class="toolbar">
          <el-select v-model="joinQuery.hid" placeholder="全部活动" clearable style="width: 200px">
            <el-option v-for="a in activityOptions" :key="a.id" :label="a.name" :value="a.id" />
          </el-select>
          <el-input v-model="joinQuery.keyword" placeholder="选手名称/编号" clearable style="width: 180px" @keyup.enter="handleSearch" />
          <el-button type="primary" @click="handleSearch">查询</el-button>
          <el-button type="success" @click="openJoinAdd">后台添加选手</el-button>
          <div class="toolbar-right">
            <el-button type="primary" :disabled="!selectedIds.length" @click="handleAudit(selectedIds, true)">批量通过</el-button>
            <el-button type="danger" :disabled="!selectedIds.length" @click="handleAudit(selectedIds, false)">批量驳回</el-button>
          </div>
        </div>

        <el-table :data="joinData" v-loading="joinLoading" border stripe @selection-change="handleSelectionChange">
          <el-table-column type="selection" width="45" />
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column label="选手" min-width="160">
            <template #default="{ row }">
              <div class="cell-flex">
                <el-image v-if="row.pic" :src="row.pic" class="thumb" fit="cover" />
                <span>{{ row.name }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="所属活动" min-width="130">
            <template #default="{ row }">{{ row.hdname || activityName(row.hid) }}</template>
          </el-table-column>
          <el-table-column prop="weixin" label="联系方式" width="130" />
          <el-table-column label="报名时间" width="170">
            <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="230" fixed="right">
            <template #default="{ row }">
              <el-button link type="success" @click="handleAudit([row.id], true)">通过</el-button>
              <el-button link type="danger" @click="handleAudit([row.id], false)">驳回</el-button>
              <el-button link type="primary" @click="openJoinEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleJoinDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <div class="pager">
          <el-pagination
            v-model:current-page="joinQuery.page"
            v-model:page-size="joinQuery.pagesize"
            :total="joinTotal"
            :page-sizes="[10, 20, 50]"
            layout="total, sizes, prev, pager, next"
            @current-change="loadJoins"
            @size-change="handleSearch"
          />
        </div>
      </el-tab-pane>

      <!-- ============ 已通过 ============ -->
      <el-tab-pane label="已通过" name="passed">
        <div class="toolbar">
          <el-select v-model="joinQuery.hid" placeholder="全部活动" clearable style="width: 200px">
            <el-option v-for="a in activityOptions" :key="a.id" :label="a.name" :value="a.id" />
          </el-select>
          <el-input v-model="joinQuery.keyword" placeholder="选手名称/编号" clearable style="width: 180px" @keyup.enter="handleSearch" />
          <el-button type="primary" @click="handleSearch">查询</el-button>
          <el-button type="success" @click="openJoinAdd">后台添加选手</el-button>
        </div>

        <el-table :data="joinData" v-loading="joinLoading" border stripe>
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column prop="number" label="编号" width="80" align="center" />
          <el-table-column label="选手" min-width="160">
            <template #default="{ row }">
              <div class="cell-flex">
                <el-image v-if="row.pic" :src="row.pic" class="thumb" fit="cover" />
                <span>{{ row.name }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="所属活动" min-width="130">
            <template #default="{ row }">{{ row.hdname || activityName(row.hid) }}</template>
          </el-table-column>
          <el-table-column prop="helpnum" label="票数" width="90" align="center" />
          <el-table-column prop="mingci" label="排名" width="80" align="center" />
          <el-table-column prop="readcount" label="访问" width="80" align="center" />
          <el-table-column label="报名时间" width="170">
            <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="240" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="handleAddpiao(row)">加票</el-button>
              <el-button link type="primary" @click="openJoinEdit(row)">编辑</el-button>
              <el-button link type="warning" @click="handleAudit([row.id], false)">驳回</el-button>
              <el-button link type="danger" @click="handleJoinDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <div class="pager">
          <el-pagination
            v-model:current-page="joinQuery.page"
            v-model:page-size="joinQuery.pagesize"
            :total="joinTotal"
            :page-sizes="[10, 20, 50]"
            layout="total, sizes, prev, pager, next"
            @current-change="loadJoins"
            @size-change="handleSearch"
          />
        </div>
      </el-tab-pane>

      <!-- ============ 已驳回 ============ -->
      <el-tab-pane label="已驳回" name="rejected">
        <div class="toolbar">
          <el-select v-model="joinQuery.hid" placeholder="全部活动" clearable style="width: 200px">
            <el-option v-for="a in activityOptions" :key="a.id" :label="a.name" :value="a.id" />
          </el-select>
          <el-input v-model="joinQuery.keyword" placeholder="选手名称/编号" clearable style="width: 180px" @keyup.enter="handleSearch" />
          <el-button type="primary" @click="handleSearch">查询</el-button>
        </div>

        <el-table :data="joinData" v-loading="joinLoading" border stripe>
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column label="选手" min-width="160">
            <template #default="{ row }">
              <div class="cell-flex">
                <el-image v-if="row.pic" :src="row.pic" class="thumb" fit="cover" />
                <span>{{ row.name }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="所属活动" min-width="130">
            <template #default="{ row }">{{ row.hdname || activityName(row.hid) }}</template>
          </el-table-column>
          <el-table-column prop="reason" label="驳回原因" min-width="160" show-overflow-tooltip />
          <el-table-column label="报名时间" width="170">
            <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="200" fixed="right">
            <template #default="{ row }">
              <el-button link type="success" @click="handleAudit([row.id], true)">重新通过</el-button>
              <el-button link type="primary" @click="openJoinEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleJoinDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <div class="pager">
          <el-pagination
            v-model:current-page="joinQuery.page"
            v-model:page-size="joinQuery.pagesize"
            :total="joinTotal"
            :page-sizes="[10, 20, 50]"
            layout="total, sizes, prev, pager, next"
            @current-change="loadJoins"
            @size-change="handleSearch"
          />
        </div>
      </el-tab-pane>

      <!-- ============ 排行 ============ -->
      <el-tab-pane label="排行" name="rank">
        <div class="toolbar">
          <el-select v-model="rankHid" placeholder="选择活动" style="width: 200px" @change="loadRank">
            <el-option v-for="a in activityOptions" :key="a.id" :label="a.name" :value="a.id" />
          </el-select>
          <el-select v-model="rankTop" style="width: 120px" @change="loadRank">
            <el-option label="Top 10" :value="10" />
            <el-option label="Top 20" :value="20" />
            <el-option label="Top 50" :value="50" />
          </el-select>
        </div>

        <div v-if="rankResult" class="rank-summary" v-loading="rankLoading">
          <el-tag type="info">通过选手 {{ rankResult.joinnum }} 人</el-tag>
          <el-tag type="success">累计票数 {{ rankResult.helpnum }}</el-tag>
        </div>

        <el-table :data="rankResult?.toplist || []" v-loading="rankLoading" border stripe>
          <el-table-column prop="mingci" label="名次" width="80" align="center" />
          <el-table-column prop="number" label="编号" width="80" align="center" />
          <el-table-column label="选手" min-width="160">
            <template #default="{ row }">
              <div class="cell-flex">
                <el-image v-if="row.pic" :src="row.pic" class="thumb" fit="cover" />
                <span>{{ row.name }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="helpnum" label="票数" width="100" align="center" />
          <el-table-column prop="readcount" label="访问" width="100" align="center" />
        </el-table>
      </el-tab-pane>
    </el-tabs>

    <!-- ============ 选手编辑弹窗 ============ -->
    <el-dialog v-model="joinDialogVisible" :title="joinForm.id ? '编辑选手' : '后台添加选手'" width="560px" destroy-on-close>
      <el-form :model="joinForm" label-width="100px">
        <el-form-item label="所属活动" required>
          <el-select v-model="joinForm.hid" placeholder="选择活动" style="width: 260px" :disabled="!!joinForm.id">
            <el-option v-for="a in activityOptions" :key="a.id" :label="a.name" :value="a.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="选手名称" required>
          <el-input v-model="joinForm.name" placeholder="请输入选手名称" />
        </el-form-item>
        <el-form-item label="选手图片">
          <el-upload
            action="/admin/upload"
            name="file"
            :headers="uploadHeaders"
            list-type="picture-card"
            :file-list="joinPicFileList"
            :limit="1"
            :on-success="(res: unknown) => { const url = parseUploadResponse(res as { code?: number; msg?: string; data?: { url?: string } }); if (url) joinForm.pic = url }"
            :on-remove="() => { joinForm.pic = '' }"
            :on-error="onUploadError"
            accept="image/*"
          >
            <span class="upload-plus">+</span>
          </el-upload>
        </el-form-item>
        <el-form-item label="选手介绍">
          <el-input v-model="joinForm.detail_txt" type="textarea" :rows="3" placeholder="选手介绍" />
        </el-form-item>
        <el-form-item label="联系方式">
          <el-input v-model="joinForm.weixin" placeholder="微信号/手机号（选填）" />
        </el-form-item>
        <el-form-item label="审核状态">
          <el-radio-group v-model="joinForm.status">
            <el-radio :value="0">待审核</el-radio>
            <el-radio :value="1">已通过</el-radio>
            <el-radio :value="2">已驳回</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="joinForm.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="joinDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="joinSaving" @click="handleJoinSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.vote-join {
  padding: 16px;
}
.toolbar {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 14px;
  flex-wrap: wrap;
}
.toolbar-right {
  margin-left: auto;
  display: flex;
  gap: 10px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}
.cell-flex {
  display: flex;
  align-items: center;
  gap: 8px;
}
.thumb {
  width: 46px;
  height: 32px;
  border-radius: 4px;
  flex-shrink: 0;
}
.upload-plus {
  font-size: 22px;
  color: #999;
}
.rank-summary {
  display: flex;
  gap: 10px;
  margin-bottom: 12px;
}
</style>
