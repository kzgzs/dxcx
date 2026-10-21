<script setup lang="ts">
/**
 * 扩展·投票活动·活动列表（平铺整页 + 右上 X 关闭编辑态）
 * - 列表列与行操作对齐点大截图：编辑/删除/链接 + 选手列表/投票记录
 * - 创建/编辑活动为平铺主区域（四部分字段），真实落库、编辑回填
 * - 「链接」→ 请选择链接小弹窗（活动首页/排行榜/报名/活动说明）→ 全站统一 LinkAddressModal，
 *   四个 path 与二维码场景参数各不相同
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteVoteActivity,
  getVoteGroupList,
  getVoteList,
  saveVoteActivity,
  setVoteActivityStatus,
} from '../../api/vote'
import type { VoteActivityItem, VoteActivitySaveParams, VoteGroupItem } from '../../api/vote'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import LinkAddressModal from '../../components/LinkAddressModal.vue'
import LinkPicker from '../../components/LinkPicker.vue'
import SimpleRichEditor from '../../components/SimpleRichEditor.vue'
import VoteJoin from './VoteJoin.vue'
import VoteRecord from './VoteRecord.vue'

// ============ 主区域内平铺层（行操作：选手列表/投票记录，不跳菜单页） ============
const subView = ref<'' | 'join' | 'record'>('')
const subHid = ref(0)
function openSubJoin(row: VoteActivityItem) {
  subHid.value = row.id
  subView.value = 'join'
}
function openSubRecord(row: VoteActivityItem) {
  subHid.value = row.id
  subView.value = 'record'
}
function closeSub() {
  subView.value = ''
  loadList()
}

// ============ 列表 / 编辑视图切换 ============
const view = ref<'list' | 'form'>('list')

// ============ 活动列表 ============
const listLoading = ref(false)
const listData = ref<VoteActivityItem[]>([])
const listTotal = ref(0)
const selectedIds = ref<number[]>([])
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

function handleSelectionChange(rows: VoteActivityItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

function statusTag(row: VoteActivityItem): { text: string; type: 'success' | 'info' | 'warning' } {
  if (row.status !== 1) return { text: '已关闭', type: 'info' }
  const now = Math.floor(Date.now() / 1000)
  if (row.starttime > now) return { text: '未开始', type: 'warning' }
  if (row.endtime < now) return { text: '已结束', type: 'info' }
  return { text: '进行中', type: 'success' }
}

// ============ 分组下拉 ============
const groupData = ref<VoteGroupItem[]>([])
async function loadGroups() {
  const res = await getVoteGroupList()
  groupData.value = res.list || []
}

// ============ 批量删除 / 开启 / 关闭 ============
async function batchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选活动')
    return
  }
  await ElMessageBox.confirm(`确定删除所选 ${selectedIds.value.length} 个活动？其选手与投票记录将一并删除`, '提示', { type: 'warning' })
  for (const id of selectedIds.value) {
    await deleteVoteActivity(id)
  }
  ElMessage.success('删除成功')
  selectedIds.value = []
  loadList()
}

async function batchSetStatus(st: number) {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选活动')
    return
  }
  for (const id of selectedIds.value) {
    await setVoteActivityStatus(id, st)
  }
  ElMessage.success(st === 1 ? '已开启' : '已关闭')
  selectedIds.value = []
  loadList()
}

async function handleRowDelete(row: VoteActivityItem) {
  await ElMessageBox.confirm(`确定删除活动「${row.name}」？其选手与投票记录将一并删除`, '提示', { type: 'warning' })
  await deleteVoteActivity(row.id)
  ElMessage.success('删除成功')
  loadList()
}

// ============ 行操作：选手列表 / 投票记录（主区域内平铺，不跳菜单页） ============

// ============ 链接弹窗：请选择链接 → 查看链接 ============
const linkPickerVisible = ref(false)
const currentRow = ref<VoteActivityItem | null>(null)
const linkModalVisible = ref(false)
const linkPath = ref('')
const linkTitle = ref('')

function openLinkPicker(row: VoteActivityItem) {
  currentRow.value = row
  linkPickerVisible.value = true
}

function openLinkView(kind: 'index' | 'phb' | 'baoming' | 'shuoming', row: VoteActivityItem) {
  const id = row.id
  const map: Record<string, { path: string; title: string }> = {
    index: { path: `/activity/toupiao/index?id=${id}`, title: '活动首页' },
    phb: { path: `/activity/toupiao/phb?id=${id}`, title: '排行榜' },
    baoming: { path: `/activity/toupiao/baoming?id=${id}`, title: '报名' },
    shuoming: { path: `/activity/toupiao/shuoming?id=${id}`, title: '活动说明' },
  }
  linkPath.value = map[kind].path
  linkTitle.value = map[kind].title
  // 不关闭「请选择链接」弹窗：查看链接(二维码)弹窗叠加在其上层；关闭二维码后仍可继续点其它按钮
  linkModalVisible.value = true
}

// ============ 创建 / 编辑活动（平铺表单） ============
const activitySaving = ref(false)
const activityForm = reactive({
  id: 0,
  name: '',
  group_id: 0,
  banner: '',
  color1: '#FD4A46',
  color2: '#1C83FF',
  listtype: 0,
  helptext: '投TA一票',
  join_num_text: '参与人数',
  join_name_text: '选手名称',
  canapply: 1,
  apply_check: 0,
  help_check: 0,
  starttime: 0 as number,
  endtime: 0 as number,
  timeRange: [] as [Date, Date] | [],
  // 报名参与条件
  gj_all: true,
  gj_follow: false,
  per_daycount: 1,
  per_allcount: 0,
  jump_url: '',
  sort: 0,
  guize: '',
  sharetitle: '',
  sharepic: '',
  sharedesc: '',
  sharelink: '',
  status: 1,
})

function resetForm() {
  const now = new Date()
  Object.assign(activityForm, {
    id: 0,
    name: '',
    group_id: 0,
    banner: '',
    color1: '#FD4A46',
    color2: '#1C83FF',
    listtype: 0,
    helptext: '投TA一票',
    join_num_text: '参与人数',
    join_name_text: '选手名称',
    canapply: 1,
    apply_check: 0,
    help_check: 0,
    starttime: 0,
    endtime: 0,
    timeRange: [now, new Date(now.getTime() + 7 * 86400 * 1000)] as [Date, Date],
    gj_all: true,
    gj_follow: false,
    per_daycount: 1,
    per_allcount: 0,
    jump_url: '',
    sort: 0,
    guize: '',
    sharetitle: '',
    sharepic: '',
    sharedesc: '',
    sharelink: '',
    status: 1,
  })
}

function openAdd() {
  resetForm()
  view.value = 'form'
}

function openEdit(row: VoteActivityItem) {
  resetForm()
  activityForm.id = row.id
  activityForm.name = row.name
  activityForm.group_id = row.group_id || 0
  activityForm.banner = row.banner || ''
  activityForm.color1 = row.color1 || '#FD4A46'
  activityForm.color2 = row.color2 || '#1C83FF'
  activityForm.listtype = row.listtype || 0
  activityForm.helptext = row.helptext || '投TA一票'
  activityForm.join_num_text = row.join_num_text || '参与人数'
  activityForm.join_name_text = row.join_name_text || '选手名称'
  activityForm.canapply = row.canapply
  activityForm.apply_check = row.apply_check
  activityForm.help_check = row.help_check
  activityForm.starttime = row.starttime
  activityForm.endtime = row.endtime
  activityForm.timeRange = [new Date(row.starttime * 1000), new Date(row.endtime * 1000)]
  const gj = (row.gettj || '-1').split(',').filter(Boolean)
  activityForm.gj_all = gj.includes('-1')
  activityForm.gj_follow = gj.includes('0')
  activityForm.per_daycount = row.per_daycount || 1
  activityForm.per_allcount = row.per_allcount || 0
  activityForm.jump_url = row.jump_url || ''
  activityForm.sort = row.sort || 0
  activityForm.guize = row.guize || ''
  activityForm.sharetitle = row.sharetitle || ''
  activityForm.sharepic = row.sharepic || ''
  activityForm.sharedesc = row.sharedesc || ''
  activityForm.sharelink = row.sharelink || ''
  activityForm.status = row.status
  view.value = 'form'
}

function closeForm() {
  view.value = 'list'
  loadList()
}

async function handleSave() {
  if (!activityForm.name.trim()) {
    ElMessage.warning('请填写活动名称')
    return
  }
  if (!activityForm.timeRange || activityForm.timeRange.length !== 2) {
    ElMessage.warning('请选择活动起止时间')
    return
  }
  const range = activityForm.timeRange as [Date, Date]
  const gettj = activityForm.gj_all
    ? '-1'
    : activityForm.gj_follow
      ? '0'
      : '-1'
  const payload: VoteActivitySaveParams = {
    id: activityForm.id || undefined,
    name: activityForm.name.trim(),
    group_id: activityForm.group_id || 0,
    banner: activityForm.banner,
    color1: activityForm.color1,
    color2: activityForm.color2,
    listtype: activityForm.listtype,
    helptext: activityForm.helptext,
    join_num_text: activityForm.join_num_text,
    join_name_text: activityForm.join_name_text,
    starttime: Math.floor(new Date(range[0]).getTime() / 1000),
    endtime: Math.floor(new Date(range[1]).getTime() / 1000),
    canapply: activityForm.canapply,
    apply_check: activityForm.apply_check,
    help_check: activityForm.help_check,
    sort: activityForm.sort,
    guize: activityForm.guize,
    sharetitle: activityForm.sharetitle,
    sharepic: activityForm.sharepic,
    sharedesc: activityForm.sharedesc,
    sharelink: activityForm.sharelink,
    per_daycount: activityForm.per_daycount,
    per_allcount: activityForm.per_allcount,
    gettj,
    jump_url: activityForm.jump_url,
    status: activityForm.status,
  }
  activitySaving.value = true
  try {
    await saveVoteActivity(payload)
    ElMessage.success('保存成功')
    closeForm()
  } finally {
    activitySaving.value = false
  }
}

// ============ 图片选择 ============
const bannerPickerVisible = ref(false)
const sharePicPickerVisible = ref(false)
function onBannerPick(urls: string[]) {
  if (urls.length) activityForm.banner = urls[0]
}
function onSharePicPick(urls: string[]) {
  if (urls.length) activityForm.sharepic = urls[0]
}

const formTitle = computed(() => (activityForm.id ? '编辑活动' : '创建活动'))

onMounted(() => {
  loadList()
  loadGroups()
})
</script>

<template>
  <!-- ==================== 内嵌：活动行「选手列表」平铺层 ==================== -->
  <div v-if="subView === 'join'" class="vote-manage">
    <VoteJoin :locked-hid="subHid" embedded @close="closeSub" />
  </div>

  <!-- ==================== 内嵌：活动行「投票记录」平铺层 ==================== -->
  <div v-else-if="subView === 'record'" class="vote-manage">
    <VoteRecord :locked-hid="subHid" embedded @close="closeSub" />
  </div>

  <!-- ==================== 列表视图 ==================== -->
  <div v-else-if="view === 'list'" class="vote-manage">
    <div class="toolbar">
      <el-button type="success" @click="openAdd">创建活动</el-button>
      <el-button @click="batchDelete">删除</el-button>
      <el-button @click="batchSetStatus(1)">开启</el-button>
      <el-button @click="batchSetStatus(0)">关闭</el-button>
      <el-divider direction="vertical" />
      <el-input v-model="listQuery.keyword" placeholder="活动名称" clearable class="w-name" @keyup.enter="handleSearch" />
      <el-select v-model="listQuery.group_id" placeholder="分组" class="w-group" @change="handleSearch">
        <el-option label="全部" :value="0" />
        <el-option v-for="g in groupData" :key="g.id" :label="g.name" :value="g.id" />
      </el-select>
      <el-select v-model="listQuery.status" placeholder="状态" class="w-status" @change="handleSearch">
        <el-option label="全部" :value="''" />
        <el-option label="开启" :value="1" />
        <el-option label="关闭" :value="0" />
      </el-select>
      <el-button type="primary" @click="handleSearch">搜索</el-button>
    </div>

    <el-table :data="listData" v-loading="listLoading" border @selection-change="handleSelectionChange">
      <el-table-column type="selection" width="40" />
      <el-table-column prop="id" label="ID" width="70" sortable />
      <el-table-column prop="name" label="活动名称" min-width="140" />
      <el-table-column label="顶部图片" width="90">
        <template #default="{ row }">
          <el-image v-if="row.banner" :src="row.banner" class="thumb" fit="cover" />
        </template>
      </el-table-column>
      <el-table-column prop="group_name" label="分组" width="100" />
      <el-table-column prop="joinnum" label="选手数量" width="90" align="center" />
      <el-table-column prop="helpnum" label="累计票数" width="90" align="center" sortable />
      <el-table-column prop="readcount" label="访问次数" width="90" align="center" sortable />
      <el-table-column label="活动时间" width="230">
        <template #default="{ row }">{{ formatTime(row.starttime) }} ~ {{ formatTime(row.endtime) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="statusTag(row).type" size="small">{{ statusTag(row).text }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="220">
        <template #default="{ row }">
          <div class="op-col">
            <span class="op-row">
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" @click="handleRowDelete(row)">删除</el-button>
              <el-button link type="primary" size="small" @click="openLinkPicker(row)">链接</el-button>
            </span>
            <span class="op-row">
              <el-button link type="primary" size="small" @click="openSubJoin(row)">选手列表</el-button>
              <el-button link type="primary" size="small" @click="openSubRecord(row)">投票记录</el-button>
            </span>
          </div>
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

    <!-- 请选择链接小弹窗 -->
    <el-dialog v-model="linkPickerVisible" title="请选择链接" width="560px" align-center>
      <div class="link-choices">
        <el-button size="large" @click="currentRow && openLinkView('index', currentRow)">活动首页</el-button>
        <el-button size="large" @click="currentRow && openLinkView('phb', currentRow)">排行榜</el-button>
        <el-button size="large" @click="currentRow && openLinkView('baoming', currentRow)">报名</el-button>
        <el-button size="large" @click="currentRow && openLinkView('shuoming', currentRow)">活动说明</el-button>
      </div>
    </el-dialog>

    <!-- 查看链接（全站统一） -->
    <LinkAddressModal v-model:visible="linkModalVisible" :path="linkPath" :name="linkTitle" />
  </div>

  <!-- ==================== 创建/编辑活动 平铺表单 ==================== -->
  <div v-else class="vote-form">
    <div class="form-head">
      <span class="form-head-title">+ {{ formTitle }}</span>
      <span class="form-close" title="关闭" @click="closeForm">&times;</span>
    </div>

    <el-form :model="activityForm" label-width="180px" class="form-body">
      <el-form-item label="活动名称" required>
        <el-input v-model="activityForm.name" placeholder="请输入活动名称" class="w-input" />
      </el-form-item>
      <el-form-item label="活动分组">
        <el-select v-model="activityForm.group_id" placeholder="请选择" class="w-input">
          <el-option v-for="g in groupData" :key="g.id" :label="g.name" :value="g.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="顶部图片">
        <div class="img-row">
          <el-image v-if="activityForm.banner" :src="activityForm.banner" class="img-thumb" fit="cover" />
          <el-button size="small" @click="bannerPickerVisible = true">上传图片</el-button>
          <span class="tip">建议尺寸：640x320像素，png格式</span>
        </div>
      </el-form-item>
      <el-form-item label="背景颜色">
        <el-input v-model="activityForm.color1" class="w-color" />
        <el-color-picker v-model="activityForm.color1" />
        <span class="tip">页面背景色，如：#FD4A46</span>
      </el-form-item>
      <el-form-item label="字体颜色">
        <el-input v-model="activityForm.color2" class="w-color" />
        <el-color-picker v-model="activityForm.color2" />
        <span class="tip">页面文本颜色，如：#1C83FF</span>
      </el-form-item>
      <el-form-item label="选手列表">
        <el-radio-group v-model="activityForm.listtype">
          <el-radio :value="0">双排</el-radio>
          <el-radio :value="1">横排</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="投票文字">
        <el-input v-model="activityForm.helptext" class="w-input" placeholder="如：投TA一票、给TA点赞" />
      </el-form-item>
      <el-form-item label="参与人数文字">
        <el-input v-model="activityForm.join_num_text" class="w-input" placeholder="参与人数自定义文字" />
      </el-form-item>
      <el-form-item label="选手名称文字">
        <el-input v-model="activityForm.join_name_text" class="w-input" placeholder="选手名称自定义文字" />
      </el-form-item>
      <el-form-item label="开始时间" required>
        <el-date-picker v-model="activityForm.timeRange" type="datetimerange" range-separator="~" start-placeholder="开始时间" end-placeholder="结束时间" class="field-range" />
      </el-form-item>
      <el-form-item label="用户报名">
        <el-radio-group v-model="activityForm.canapply">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="报名审核">
        <el-radio-group v-model="activityForm.apply_check">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-divider content-position="left">报名设置</el-divider>
      <el-form-item label="报名参与条件">
        <el-checkbox v-model="activityForm.gj_all" label="所有人" @change="(v: any) => { if (v) activityForm.gj_follow = false }" />
        <el-checkbox v-model="activityForm.gj_follow" label="关注用户" @change="(v: any) => { if (v) activityForm.gj_all = false }" />
      </el-form-item>
      <el-form-item label="每天可投">
        <el-input-number v-model="activityForm.per_daycount" :min="1" :precision="0" class="field-xs" />
        <span class="tip">票　每个会员每天最多可投票的选手数量，每位选手限投1票</span>
      </el-form-item>
      <el-form-item label="总计可投">
        <el-input-number v-model="activityForm.per_allcount" :min="0" :precision="0" class="field-xs" />
        <span class="tip">票　活动期间每个会员最多可投票的选手数量，0表示不限制，每位选手限投1票</span>
      </el-form-item>
      <el-form-item label="投票验证">
        <el-radio-group v-model="activityForm.help_check">
          <el-radio :value="0">不需要验证</el-radio>
          <el-radio :value="1">图片验证码</el-radio>
          <el-radio :value="2">短信验证码</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="投票成功后跳转链接">
        <LinkPicker v-model="activityForm.jump_url" pick-text="选择链接" placeholder="请输入或选择链接" class="w-input" />
      </el-form-item>
      <el-form-item label="序号">
        <el-input-number v-model="activityForm.sort" :min="0" :precision="0" class="field-xs" />
        <span class="tip">用于排序，越大越靠前</span>
      </el-form-item>
      <el-form-item label="状态">
        <el-radio-group v-model="activityForm.status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-divider content-position="left">活动规则</el-divider>
      <el-form-item label="活动规则">
        <SimpleRichEditor v-model="activityForm.guize" class="w-editor" />
      </el-form-item>

      <el-divider content-position="left">分享设置</el-divider>
      <el-form-item label="分享标题">
        <el-input v-model="activityForm.sharetitle" class="w-input" />
        <span class="tip">默认为活动名称</span>
      </el-form-item>
      <el-form-item label="分享图标">
        <div class="img-row">
          <el-image v-if="activityForm.sharepic" :src="activityForm.sharepic" class="img-thumb" fit="cover" />
          <el-button size="small" @click="sharePicPickerVisible = true">上传图片</el-button>
          <span class="tip">建议尺寸：400*400像素</span>
        </div>
      </el-form-item>
      <el-form-item label="分享描述">
        <el-input v-model="activityForm.sharedesc" class="w-input" />
      </el-form-item>
      <el-form-item label="分享链接">
        <el-input v-model="activityForm.sharelink" class="w-input" />
        <span class="tip">默认为活动链接，设置后发送给朋友或分享到朋友圈，点击后会跳转到该链接地址</span>
      </el-form-item>

      <el-form-item>
        <el-button type="primary" :loading="activitySaving" @click="handleSave">提交</el-button>
      </el-form-item>
    </el-form>

    <ImgIconPicker v-model:visible="bannerPickerVisible" default-tab="library" @pick="onBannerPick" />
    <ImgIconPicker v-model:visible="sharePicPickerVisible" default-tab="library" @pick="onSharePicPick" />
  </div>
</template>

<style scoped>
.vote-manage,
.vote-form {
  padding: 3px;
}
.toolbar {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 3px;
  flex-wrap: nowrap;
}
.w-name { width: 180px; }
.w-group { width: 130px; }
.w-status { width: 110px; }
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 3px;
}
.thumb {
  width: 46px;
  height: 32px;
  border-radius: 4px;
}
.op-row {
  display: flex;
  gap: 4px;
}
.link-choices {
  display: flex;
  justify-content: center;
  gap: 18px;
  padding: 12px 0;
}
.form-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 4px 10px;
}
.form-head-title {
  font-size: 16px;
  font-weight: 600;
}
.form-close {
  font-size: 24px;
  color: #909399;
  cursor: pointer;
}
.form-close:hover { color: #f56c6c; }
.form-body {
  max-width: 860px;
}
.w-input { width: 320px; }
.w-color { width: 160px; margin-right: 8px; }
.w-editor { width: 100%; max-width: 640px; }
.img-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.img-thumb {
  width: 96px;
  height: 48px;
  border-radius: 4px;
  border: 1px solid #ebeef5;
}
.tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
</style>
