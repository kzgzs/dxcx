<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteVoteActivity,
  deleteVoteGroup,
  getVoteGroupList,
  getVoteHelpList,
  getVoteJoinList,
  getVoteList,
  saveVoteActivity,
  saveVoteGroup,
  setVoteActivityStatus,
} from '../../api/vote'
import type {
  VoteActivityItem,
  VoteActivitySaveParams,
  VoteGroupItem,
  VoteHelpItem,
  VoteJoinItem,
} from '../../api/vote'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const activeTab = ref('activity')

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) activityForm.banner = urls[0]
}

// ============ 活动列表 ============
const listLoading = ref(false)
const listData = ref<VoteActivityItem[]>([])
const listTotal = ref(0)
const listQuery = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '' as number | '',
  group_id: 0,
})

async function loadList() {
  listLoading.value = true
  try {
    const res = await getVoteList(listQuery)
    listData.value = res.list || []
    listTotal.value = res.total || 0
  } finally {
    listLoading.value = false
  }
}

function handleSearch() {
  listQuery.page = 1
  loadList()
}

function handleReset() {
  listQuery.keyword = ''
  listQuery.status = ''
  listQuery.group_id = 0
  handleSearch()
}

// 活动编辑弹窗
const activityDialogVisible = ref(false)
const activitySaving = ref(false)
const activityForm = reactive<VoteActivitySaveParams & { timeRange: [Date, Date] | [] }>({
  id: 0,
  name: '',
  banner: '',
  color1: '#FD4A46',
  color2: '#1C83FF',
  listtype: 0,
  helptext: '投TA一票',
  starttime: 0,
  endtime: 0,
  canapply: 1,
  apply_check: 1,
  help_check: 0,
  sort: 0,
  guize: '',
  sharetitle: '',
  sharepic: '',
  sharedesc: '',
  sharelink: '',
  per_daycount: 1,
  per_allcount: 0,
  jump_url: '',
  group_id: 0,
  status: 1,
  timeRange: [],
})

function openActivityAdd() {
  Object.assign(activityForm, {
    id: 0,
    name: '',
    banner: '',
    color1: '#FD4A46',
    color2: '#1C83FF',
    listtype: 0,
    helptext: '投TA一票',
    starttime: 0,
    endtime: 0,
    canapply: 1,
    apply_check: 1,
    help_check: 0,
    sort: 0,
    guize: '',
    sharetitle: '',
    sharepic: '',
    sharedesc: '',
    sharelink: '',
    per_daycount: 1,
    per_allcount: 0,
    jump_url: '',
    group_id: 0,
    status: 1,
    timeRange: [new Date(), new Date(Date.now() + 7 * 86400 * 1000)],
  })
  activityDialogVisible.value = true
}

function openActivityEdit(row: VoteActivityItem) {
  Object.assign(activityForm, {
    ...row,
    timeRange: [new Date(row.starttime * 1000), new Date(row.endtime * 1000)],
  })
  activityDialogVisible.value = true
}

async function handleActivitySave() {
  if (!activityForm.name.trim()) {
    ElMessage.warning('请填写活动名称')
    return
  }
  if (!activityForm.timeRange || activityForm.timeRange.length !== 2) {
    ElMessage.warning('请选择活动起止时间')
    return
  }
  activitySaving.value = true
  try {
    const range = activityForm.timeRange as [Date, Date]
    const payload: VoteActivitySaveParams = {
      ...activityForm,
      starttime: Math.floor(new Date(range[0]).getTime() / 1000),
      endtime: Math.floor(new Date(range[1]).getTime() / 1000),
    } as VoteActivitySaveParams
    await saveVoteActivity(payload)
    ElMessage.success('保存成功')
    activityDialogVisible.value = false
    loadList()
  } finally {
    activitySaving.value = false
  }
}

async function handleActivityDelete(row: VoteActivityItem) {
  await ElMessageBox.confirm(`确定删除活动「${row.name}」？其选手与投票记录将一并删除`, '提示', { type: 'warning' })
  await deleteVoteActivity(row.id)
  ElMessage.success('删除成功')
  loadList()
}

async function handleStatusChange(row: VoteActivityItem, val: number | string | boolean) {
  await setVoteActivityStatus(row.id, Number(val))
  ElMessage.success(Number(val) === 1 ? '已开启' : '已关闭')
}

function goJoinPage() {
  // 选手管理在独立菜单页，提示用户从左侧菜单进入
  ElMessage.info('请从左侧菜单「选手管理」查看选手')
}

// ============ 活动分组 ============
const groupLoading = ref(false)
const groupData = ref<VoteGroupItem[]>([])
const groupKeyword = ref('')

async function loadGroups() {
  groupLoading.value = true
  try {
    const res = await getVoteGroupList(groupKeyword.value)
    groupData.value = res.list || []
  } finally {
    groupLoading.value = false
  }
}

const groupDialogVisible = ref(false)
const groupSaving = ref(false)
const groupForm = reactive({ id: 0, name: '', sort: 0, status: 1 })

function openGroupAdd() {
  Object.assign(groupForm, { id: 0, name: '', sort: 0, status: 1 })
  groupDialogVisible.value = true
}

function openGroupEdit(row: VoteGroupItem) {
  Object.assign(groupForm, { id: row.id, name: row.name, sort: row.sort, status: row.status })
  groupDialogVisible.value = true
}

async function handleGroupSave() {
  if (!groupForm.name.trim()) {
    ElMessage.warning('请填写分组名称')
    return
  }
  groupSaving.value = true
  try {
    await saveVoteGroup({ ...groupForm })
    ElMessage.success('保存成功')
    groupDialogVisible.value = false
    loadGroups()
  } finally {
    groupSaving.value = false
  }
}

async function handleGroupDelete(row: VoteGroupItem) {
  await ElMessageBox.confirm(`确定删除分组「${row.name}」？`, '提示', { type: 'warning' })
  await deleteVoteGroup(row.id)
  ElMessage.success('删除成功')
  loadGroups()
}

// ============ 投票记录 ============
const helpLoading = ref(false)
const helpData = ref<VoteHelpItem[]>([])
const helpTotal = ref(0)
const helpQuery = reactive({ page: 1, pagesize: 10, hid: 0, joinid: 0 })
const joinOptions = ref<VoteJoinItem[]>([])

async function loadHelp() {
  helpLoading.value = true
  try {
    const res = await getVoteHelpList(helpQuery)
    helpData.value = res.list || []
    helpTotal.value = res.total || 0
  } finally {
    helpLoading.value = false
  }
}

async function loadJoinOptions() {
  if (helpQuery.hid <= 0) {
    joinOptions.value = []
    helpQuery.joinid = 0
    return
  }
  const res = await getVoteJoinList({ hid: helpQuery.hid, page: 1, pagesize: 100 })
  joinOptions.value = res.list || []
}

function handleHelpSearch() {
  helpQuery.page = 1
  loadHelp()
}

onMounted(() => {
  loadList()
  loadGroups()
  loadHelp()
})
</script>

<template>
  <div class="vote-manage">
    <el-tabs v-model="activeTab">
      <!-- ============ 活动列表 ============ -->
      <el-tab-pane label="活动列表" name="activity">
        <div class="toolbar">
          <el-input v-model="listQuery.keyword" placeholder="活动名称" clearable style="width: 180px" @keyup.enter="handleSearch" />
          <el-select v-model="listQuery.status" placeholder="状态" clearable style="width: 120px">
            <el-option label="开启" :value="1" />
            <el-option label="关闭" :value="0" />
          </el-select>
          <el-select v-model="listQuery.group_id" placeholder="分组" clearable style="width: 140px">
            <el-option v-for="g in groupData" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
          <el-button type="primary" @click="handleSearch">查询</el-button>
          <el-button @click="handleReset">重置</el-button>
          <el-button type="success" @click="openActivityAdd">新增活动</el-button>
        </div>

        <el-table :data="listData" v-loading="listLoading" border stripe>
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column label="活动名称" min-width="160">
            <template #default="{ row }">
              <div class="cell-flex">
                <el-image v-if="row.banner" :src="row.banner" class="thumb" fit="cover" />
                <span>{{ row.name }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="group_name" label="分组" width="110" />
          <el-table-column label="展示方式" width="90">
            <template #default="{ row }">{{ row.listtype === 1 ? '横排' : '双排' }}</template>
          </el-table-column>
          <el-table-column label="起止时间" width="240">
            <template #default="{ row }">
              <div>{{ formatTime(row.starttime) }}</div>
              <div>{{ formatTime(row.endtime) }}</div>
            </template>
          </el-table-column>
          <el-table-column label="投票限制" width="120">
            <template #default="{ row }">
              <div>每日 {{ row.per_daycount }} 票</div>
              <div>累计 {{ row.per_allcount > 0 ? row.per_allcount + ' 票' : '不限' }}</div>
            </template>
          </el-table-column>
          <el-table-column prop="joinnum" label="选手数" width="80" align="center" />
          <el-table-column prop="helpnum" label="累计票数" width="90" align="center" />
          <el-table-column label="状态" width="80" align="center">
            <template #default="{ row }">
              <el-switch :model-value="row.status" :active-value="1" :inactive-value="0" @change="(v: number | string | boolean) => handleStatusChange(row, v)" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="200" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="openActivityEdit(row)">编辑</el-button>
              <el-button link type="primary" @click="goJoinPage">选手</el-button>
              <el-button link type="danger" @click="handleActivityDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <div class="pager">
          <el-pagination
            v-model:current-page="listQuery.page"
            v-model:page-size="listQuery.pagesize"
            :total="listTotal"
            :page-sizes="[10, 20, 50]"
            layout="total, sizes, prev, pager, next"
            @current-change="loadList"
            @size-change="handleSearch"
          />
        </div>
      </el-tab-pane>

      <!-- ============ 活动分组 ============ -->
      <el-tab-pane label="活动分组" name="group">
        <div class="toolbar">
          <el-input v-model="groupKeyword" placeholder="分组名称" clearable style="width: 180px" @keyup.enter="loadGroups" />
          <el-button type="primary" @click="loadGroups">查询</el-button>
          <el-button type="success" @click="openGroupAdd">新增分组</el-button>
        </div>

        <el-table :data="groupData" v-loading="groupLoading" border stripe>
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column prop="name" label="分组名称" min-width="160" />
          <el-table-column prop="activity_count" label="活动数" width="90" align="center" />
          <el-table-column prop="sort" label="排序" width="90" align="center" />
          <el-table-column label="状态" width="80" align="center">
            <template #default="{ row }">
              <el-tag :type="row.status === 1 ? 'success' : 'info'">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="创建时间" width="170">
            <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="140" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="openGroupEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleGroupDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <!-- ============ 投票记录 ============ -->
      <el-tab-pane label="投票记录" name="help">
        <div class="toolbar">
          <el-select v-model="helpQuery.hid" placeholder="选择活动" clearable style="width: 200px" @change="loadJoinOptions">
            <el-option v-for="a in listData" :key="a.id" :label="a.name" :value="a.id" />
          </el-select>
          <el-select v-model="helpQuery.joinid" placeholder="选择选手" clearable style="width: 180px">
            <el-option v-for="j in joinOptions" :key="j.id" :label="`${j.number || j.id} ${j.name}`" :value="j.id" />
          </el-select>
          <el-button type="primary" @click="handleHelpSearch">查询</el-button>
        </div>

        <el-table :data="helpData" v-loading="helpLoading" border stripe>
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column prop="hdname" label="所属活动" min-width="140" />
          <el-table-column label="选手" min-width="140">
            <template #default="{ row }">{{ row.number ? `${row.number} ${row.joinname}` : row.joinname }}</template>
          </el-table-column>
          <el-table-column label="投票人" min-width="140">
            <template #default="{ row }">
              <div class="cell-flex">
                <el-image v-if="row.headimg" :src="row.headimg" class="thumb-sm" fit="cover" />
                <span>{{ row.nickname }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="mid" label="会员ID" width="90" align="center" />
          <el-table-column label="投票时间" width="170">
            <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
          </el-table-column>
        </el-table>

        <div class="pager">
          <el-pagination
            v-model:current-page="helpQuery.page"
            v-model:page-size="helpQuery.pagesize"
            :total="helpTotal"
            :page-sizes="[10, 20, 50]"
            layout="total, sizes, prev, pager, next"
            @current-change="loadHelp"
            @size-change="handleHelpSearch"
          />
        </div>
      </el-tab-pane>
    </el-tabs>

    <!-- ============ 活动编辑弹窗 ============ -->
    <el-dialog v-model="activityDialogVisible" :title="activityForm.id ? '编辑活动' : '新增活动'" width="720px" destroy-on-close>
      <el-form :model="activityForm" label-width="110px">
        <el-form-item label="活动名称" required>
          <el-input v-model="activityForm.name" placeholder="请输入活动名称" />
        </el-form-item>
        <el-form-item label="活动起止" required>
          <el-date-picker
            v-model="activityForm.timeRange"
            type="datetimerange"
            range-separator="至"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
          />
        </el-form-item>
        <el-form-item label="活动分组">
          <el-select v-model="activityForm.group_id" placeholder="不分组" clearable style="width: 200px">
            <el-option v-for="g in groupData" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="活动横幅">
          <div class="img-pick-row">
            <el-image v-if="activityForm.banner" :src="activityForm.banner" class="img-pick-thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker">{{ activityForm.banner ? '更换' : '选择图片' }}</el-button>
            <el-button v-if="activityForm.banner" size="small" link type="danger" @click="activityForm.banner = ''">删除</el-button>
          </div>
        </el-form-item>
        <el-form-item label="选手列表样式">
          <el-radio-group v-model="activityForm.listtype">
            <el-radio :value="0">双排</el-radio>
            <el-radio :value="1">横排</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="投票按钮文案">
          <el-input v-model="activityForm.helptext" placeholder="如：投TA一票" style="width: 220px" />
        </el-form-item>
        <el-form-item label="每日可投票数">
          <el-input-number v-model="activityForm.per_daycount" :min="1" :precision="0" :step="1" />
          <span class="form-tip">同一会员每天最多可投票数</span>
        </el-form-item>
        <el-form-item label="累计可投票数">
          <el-input-number v-model="activityForm.per_allcount" :min="0" :precision="0" :step="1" />
          <span class="form-tip">同一会员活动期间累计上限，0 表示不限</span>
        </el-form-item>
        <el-form-item label="开启报名">
          <el-switch v-model="activityForm.canapply" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="报名需审核">
          <el-switch v-model="activityForm.apply_check" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="主题色">
          <el-color-picker v-model="activityForm.color1" />
          <el-color-picker v-model="activityForm.color2" />
        </el-form-item>
        <el-form-item label="规则说明">
          <el-input v-model="activityForm.guize" type="textarea" :rows="4" placeholder="活动规则说明" />
        </el-form-item>
        <el-form-item label="投票成功跳转">
          <el-input v-model="activityForm.jump_url" placeholder="选填，投票成功后跳转链接" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="activityForm.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="activityForm.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="activityDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="activitySaving" @click="handleActivitySave">保存</el-button>
      </template>
    </el-dialog>

    <!-- ============ 分组编辑弹窗 ============ -->
    <el-dialog v-model="groupDialogVisible" :title="groupForm.id ? '编辑分组' : '新增分组'" width="420px" destroy-on-close>
      <el-form :model="groupForm" label-width="90px">
        <el-form-item label="分组名称" required>
          <el-input v-model="groupForm.name" placeholder="请输入分组名称" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="groupForm.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="groupForm.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="groupDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="groupSaving" @click="handleGroupSave">保存</el-button>
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
.vote-manage {
  padding: 16px;
}
.toolbar {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 14px;
  flex-wrap: wrap;
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
.thumb-sm {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  flex-shrink: 0;
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
.form-tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
</style>
