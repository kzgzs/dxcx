<template>
  <div class="page-live">
    <!-- 筛选栏 -->
    <div class="filter-bar">
      <el-input v-model="query.name" placeholder="房间名" clearable style="width: 200px" @keyup.enter="handleSearch" />
      <el-button type="primary" @click="handleSearch">搜索</el-button>
      <el-button type="success" @click="openAdd">添加直播间</el-button>
    </div>

    <!-- 列表 -->
    <el-table v-loading="loading" :data="list" border stripe style="width: 100%">
      <el-table-column label="ID" prop="id" width="70" align="center" />
      <el-table-column label="封面" width="110" align="center">
        <template #default="{ row }">
          <el-image v-if="row.coverImg" :src="row.coverImg" class="thumb" fit="cover" :preview-src-list="[row.coverImg]" />
          <span v-else class="text-muted">-</span>
        </template>
      </el-table-column>
      <el-table-column label="房间名" min-width="160" show-overflow-tooltip>
        <template #default="{ row }">
          <div>{{ row.name }}</div>
          <div class="text-muted" style="font-size: 12px">roomId: {{ row.roomId || '-' }}</div>
        </template>
      </el-table-column>
      <el-table-column label="主播" prop="anchorName" width="120" />
      <el-table-column label="开播时间" width="170" align="center">
        <template #default="{ row }">{{ fmtTime(row.startTime) }}</template>
      </el-table-column>
      <el-table-column label="结束时间" width="170" align="center">
        <template #default="{ row }">{{ fmtTime(row.endTime) }}</template>
      </el-table-column>
      <el-table-column label="直播状态" width="100" align="center">
        <template #default="{ row }">
          <el-tag :type="liveStatusType(row.live_status)" size="small">{{ liveStatusText(row.live_status) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="显示" width="80" align="center">
        <template #default="{ row }">
          <el-switch :model-value="row.status === 1" @change="(v: boolean | string | number) => handleSetst(row, v)" />
        </template>
      </el-table-column>
      <el-table-column label="排序" prop="sort" width="70" align="center" />
      <el-table-column label="操作" width="230" align="center" fixed="right">
        <template #default="{ row }">
          <el-button size="small" type="primary" link @click="openEdit(row)">编辑</el-button>
          <el-button size="small" type="warning" link @click="handlePusherQr(row)">推流码</el-button>
          <el-button size="small" type="danger" link @click="handleDel(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <div class="pager">
      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        @current-change="loadList"
        @size-change="loadList"
      />
    </div>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="editVisible" :title="form.id ? '编辑直播间' : '添加直播间'" width="720px" destroy-on-close>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
        <el-form-item label="房间名" prop="name">
          <el-input v-model="form.name" placeholder="请输入房间名" maxlength="30" show-word-limit />
        </el-form-item>
        <el-form-item label="封面图" prop="coverImg">
          <div class="upload-line">
            <el-image v-if="form.coverImg" :src="form.coverImg" class="thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker('coverImg')">{{ form.coverImg ? '更换' : '选择图片' }}</el-button>
            <el-button v-if="form.coverImg" size="small" link type="danger" @click="form.coverImg = ''">删除</el-button>
            <span class="text-muted">建议 750x460</span>
          </div>
        </el-form-item>
        <el-form-item label="分享卡片图">
          <div class="upload-line">
            <el-image v-if="form.shareImg" :src="form.shareImg" class="thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker('shareImg')">{{ form.shareImg ? '更换' : '选择图片' }}</el-button>
            <el-button v-if="form.shareImg" size="small" link type="danger" @click="form.shareImg = ''">删除</el-button>
          </div>
        </el-form-item>
        <el-form-item label="直播间分享图">
          <div class="upload-line">
            <el-image v-if="form.feedsImg" :src="form.feedsImg" class="thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker('feedsImg')">{{ form.feedsImg ? '更换' : '选择图片' }}</el-button>
            <el-button v-if="form.feedsImg" size="small" link type="danger" @click="form.feedsImg = ''">删除</el-button>
          </div>
        </el-form-item>
        <el-form-item label="开始时间" prop="startTime">
          <el-date-picker v-model="form.startTime" type="datetime" placeholder="选择开始时间" value-format="YYYY-MM-DD HH:mm:ss" />
        </el-form-item>
        <el-form-item label="结束时间" prop="endTime">
          <el-date-picker v-model="form.endTime" type="datetime" placeholder="选择结束时间" value-format="YYYY-MM-DD HH:mm:ss" />
        </el-form-item>
        <el-form-item label="主播昵称" prop="anchorName">
          <el-input v-model="form.anchorName" placeholder="请输入主播昵称" maxlength="15" />
        </el-form-item>
        <el-form-item label="主播微信号" prop="anchorWechat">
          <el-input v-model="form.anchorWechat" placeholder="主播个人微信号（开播前用于绑定）" maxlength="30" />
        </el-form-item>
        <el-form-item label="副主播微信号">
          <el-input v-model="form.subAnchorWechat" placeholder="选填" maxlength="30" />
        </el-form-item>
        <el-form-item label="创建者微信号">
          <el-input v-model="form.createrWechat" placeholder="选填" maxlength="30" />
        </el-form-item>
        <el-form-item label="直播类型">
          <el-radio-group v-model="form.type">
            <el-radio :value="0">推流</el-radio>
            <el-radio :value="1">拉流</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="画面方向">
          <el-radio-group v-model="form.screenType">
            <el-radio :value="0">竖屏</el-radio>
            <el-radio :value="1">横屏</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="是否公开">
          <el-switch v-model="form.isFeedsPublic" :active-value="1" :inactive-value="0" />
          <span class="text-muted" style="margin-left: 8px">直播间是否在 feed 流中公开</span>
        </el-form-item>
        <el-form-item label="功能开关">
          <div class="switch-grid">
            <span>关闭点赞 <el-switch v-model="form.closeLike" :active-value="1" :inactive-value="0" /></span>
            <span>关闭货架 <el-switch v-model="form.closeGoods" :active-value="1" :inactive-value="0" /></span>
            <span>关闭评论 <el-switch v-model="form.closeComment" :active-value="1" :inactive-value="0" /></span>
            <span>关闭回放 <el-switch v-model="form.closeReplay" :active-value="1" :inactive-value="0" /></span>
            <span>关闭分享 <el-switch v-model="form.closeShare" :active-value="1" :inactive-value="0" /></span>
            <span>关闭客服 <el-switch v-model="form.closeKf" :active-value="1" :inactive-value="0" /></span>
          </div>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="9999" />
        </el-form-item>
        <el-form-item label="显示状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">显示</el-radio>
            <el-radio :value="0">隐藏</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>

    <!-- 推流二维码弹窗 -->
    <el-dialog v-model="qrVisible" title="主播推流二维码" width="400px" destroy-on-close>
      <div v-loading="qrLoading" class="qr-box">
        <el-image v-if="qrUrl" :src="qrUrl" class="qr-img" fit="contain" :preview-src-list="[qrUrl]" />
        <div v-else-if="!qrLoading" class="text-muted">暂无二维码</div>
        <div class="qr-tip">
          主播使用微信「扫一扫」该二维码进入直播间开播。
          <el-button v-if="qrMock" size="small" text type="info">mock 占位图</el-button>
        </div>
        <div class="qr-actions">
          <el-button size="small" @click="loadQr(qrRoomId)">重新获取</el-button>
        </div>
      </div>
    </el-dialog>

    <!-- 选图弹窗（任务294） -->
    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      default-tab="library"
      @pick="(urls: string[]) => onImgPick(urls)"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { liveDel, liveDetail, liveList, livePusherQr, liveSave, liveSetst, type LiveRoomRow } from '../../api/live'

const loading = ref(false)
const saving = ref(false)
const list = ref<LiveRoomRow[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 20, name: '' })

// ============ 列表 ============
async function loadList() {
  loading.value = true
  try {
    const res = await liveList({ page: query.page, pagesize: query.pagesize, name: query.name })
    list.value = res.list || []
    total.value = res.total || 0
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  loadList()
}

function fmtTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function liveStatusText(st: string | number) {
  const map: Record<string, string> = {
    101: '直播中', 102: '未开始', 103: '已结束', 104: '禁播', 105: '暂停', 106: '异常', 107: '已过期',
  }
  return map[String(st)] || '未同步'
}

function liveStatusType(st: string | number) {
  const code = String(st)
  if (code === '101') return 'success'
  if (code === '102') return 'warning'
  if (code === '103') return 'info'
  if (code === '104' || code === '106' || code === '107') return 'danger'
  return 'info'
}

async function handleSetst(row: LiveRoomRow, v: boolean | string | number) {
  const st = v ? 1 : 0
  await liveSetst([row.id], st)
  row.status = st
  ElMessage.success('操作成功')
}

function handleDel(row: LiveRoomRow) {
  ElMessageBox.confirm(`确定删除直播间「${row.name}」？`, '提示', { type: 'warning' }).then(async () => {
    await liveDel([row.id])
    ElMessage.success('删除成功')
    loadList()
  }).catch(() => {})
}

// ============ 编辑 ============
interface LiveForm {
  id: number
  name: string
  coverImg: string
  shareImg: string
  feedsImg: string
  startTime: string
  endTime: string
  anchorName: string
  anchorWechat: string
  subAnchorWechat: string
  createrWechat: string
  isFeedsPublic: number
  type: number
  screenType: number
  closeLike: number
  closeGoods: number
  closeComment: number
  closeReplay: number
  closeShare: number
  closeKf: number
  sort: number
  status: number
}

const editVisible = ref(false)
const formRef = ref<FormInstance>()
const form = reactive<LiveForm>(emptyForm())

function emptyForm(): LiveForm {
  return {
    id: 0, name: '', coverImg: '', shareImg: '', feedsImg: '', startTime: '', endTime: '',
    anchorName: '', anchorWechat: '', subAnchorWechat: '', createrWechat: '',
    isFeedsPublic: 1, type: 0, screenType: 0,
    closeLike: 0, closeGoods: 0, closeComment: 0, closeReplay: 0, closeShare: 0, closeKf: 0,
    sort: 0, status: 1,
  }
}

const rules: FormRules = {
  name: [{ required: true, message: '请填写房间名', trigger: 'blur' }],
  anchorName: [{ required: true, message: '请填写主播昵称', trigger: 'blur' }],
  anchorWechat: [{ required: true, message: '请填写主播微信号', trigger: 'blur' }],
  startTime: [{ required: true, message: '请选择开始时间', trigger: 'change' }],
  endTime: [{ required: true, message: '请选择结束时间', trigger: 'change' }],
}

function openAdd() {
  Object.assign(form, emptyForm())
  editVisible.value = true
}

async function openEdit(row: LiveRoomRow) {
  const detail = await liveDetail(row.id)
  Object.assign(form, emptyForm(), {
    id: detail.id,
    name: detail.name || '',
    coverImg: detail.coverImg || '',
    shareImg: detail.shareImg || '',
    feedsImg: detail.feedsImg || '',
    startTime: detail.startTime ? fmtTime(detail.startTime) : '',
    endTime: detail.endTime ? fmtTime(detail.endTime) : '',
    anchorName: detail.anchorName || '',
    anchorWechat: detail.anchorWechat || '',
    subAnchorWechat: detail.subAnchorWechat || '',
    createrWechat: detail.createrWechat || '',
    isFeedsPublic: Number(detail.isFeedsPublic ?? 1),
    type: Number(detail.type ?? 0),
    screenType: Number(detail.screenType ?? 0),
    closeLike: Number(detail.closeLike ?? 0),
    closeGoods: Number(detail.closeGoods ?? 0),
    closeComment: Number(detail.closeComment ?? 0),
    closeReplay: Number(detail.closeReplay ?? 0),
    closeShare: Number(detail.closeShare ?? 0),
    closeKf: Number(detail.closeKf ?? 0),
    sort: Number(detail.sort ?? 0),
    status: Number(detail.status ?? 1),
  })
  editVisible.value = true
}

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
type ImgKey = 'coverImg' | 'shareImg' | 'feedsImg'
const imgPickerVisible = ref(false)
const imgPickTarget = ref<ImgKey>('coverImg')

function openImgPicker(key: ImgKey) {
  imgPickTarget.value = key
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form[imgPickTarget.value] = urls[0]
}

async function handleSave() {
  if (!formRef.value) return
  await formRef.value.validate()
  saving.value = true
  try {
    await liveSave({ ...form, id: form.id || undefined })
    ElMessage.success('保存成功')
    editVisible.value = false
    loadList()
  } finally {
    saving.value = false
  }
}

// ============ 推流二维码 ============
const qrVisible = ref(false)
const qrLoading = ref(false)
const qrUrl = ref('')
const qrMock = ref(false)
const qrRoomId = ref(0)

function handlePusherQr(row: LiveRoomRow) {
  qrRoomId.value = row.id
  qrUrl.value = ''
  qrMock.value = false
  qrVisible.value = true
  loadQr(row.id)
}

async function loadQr(id: number) {
  qrLoading.value = true
  try {
    const res = await livePusherQr(id)
    qrUrl.value = res.url
    qrMock.value = !!res.mock
  } finally {
    qrLoading.value = false
  }
}

onMounted(loadList)
</script>

<style scoped>
.page-live {
  padding: 12px;
}
.filter-bar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}
.pager {
  margin-top: 12px;
  display: flex;
  justify-content: flex-end;
}
.thumb {
  width: 90px;
  height: 56px;
  border-radius: 4px;
  object-fit: cover;
}
.text-muted {
  color: #999;
  font-size: 12px;
}
.upload-line {
  display: flex;
  align-items: center;
  gap: 10px;
}
.switch-grid {
  display: grid;
  grid-template-columns: repeat(3, 140px);
  gap: 8px 20px;
}
.qr-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  min-height: 260px;
  justify-content: center;
}
.qr-img {
  width: 220px;
  height: 220px;
}
.qr-tip {
  color: #666;
  font-size: 13px;
  text-align: center;
}
</style>
