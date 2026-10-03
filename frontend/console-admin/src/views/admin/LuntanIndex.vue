<script setup lang="ts">
// 用户论坛 - 帖子列表（对齐点大 Luntan/index + Luntan/edit）
// - 列表：批量 添加/删除/审核通过/审核驳回；搜索 内容/分类/状态
// - 添加/编辑平铺在主区域、右上角 X 关闭（非弹窗）
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { uploadFile } from '../../api/goods'
import {
  auditLuntan,
  deleteLuntan,
  getLuntanCategoryOptions,
  getLuntanDetail,
  getLuntanList,
  saveLuntanPost,
  setLuntanTop,
} from '../../api/luntan'
import type { LuntanCategoryItem, LuntanPostItem } from '../../api/luntan'
import { getMemberList } from '../../api/member'
import type { MemberRow } from '../../api/member'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { formatTime } from '../../utils/time'

// ============ 视图切换：列表 / 整页编辑 ============
const viewMode = ref<'list' | 'edit'>('list')

// ============ 列表 ============
const loading = ref(false)
const list = ref<LuntanPostItem[]>([])
const total = ref(0)
const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '' as string,
  cid: undefined as number | undefined,
})
const selected = ref<LuntanPostItem[]>([])
const categories = ref<LuntanCategoryItem[]>([])

async function loadCategories() {
  try {
    categories.value = await getLuntanCategoryOptions()
  } catch {
    categories.value = []
  }
}

async function load() {
  loading.value = true
  try {
    const res = await getLuntanList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      status: query.status || undefined,
      cid: query.cid || undefined,
    })
    list.value = res.list || []
    total.value = res.total || 0
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  load()
}

function statusText(row: LuntanPostItem) {
  return row.status === 1 ? '已通过' : row.status === 2 ? '已驳回' : row.status === 3 ? '待支付置顶' : '待审核'
}
function statusType(row: LuntanPostItem): 'success' | 'danger' | 'warning' | 'info' {
  return row.status === 1 ? 'success' : row.status === 2 ? 'danger' : row.status === 3 ? 'info' : 'warning'
}

// ============ 批量操作 ============
function selectedIds(): number[] {
  return selected.value.map((r) => r.id)
}

function openAdd() {
  resetForm()
  viewMode.value = 'edit'
}

function handleBatchAudit(status: number) {
  const ids = selectedIds()
  if (ids.length === 0) {
    ElMessage.warning('请选择帖子')
    return
  }
  auditLuntan(ids, status)
    .then(() => {
      ElMessage.success(status === 1 ? '已通过审核' : '已驳回')
      load()
    })
    .catch(() => {})
}

function handleBatchDelete() {
  const ids = selectedIds()
  if (ids.length === 0) {
    ElMessage.warning('请选择帖子')
    return
  }
  ElMessageBox.confirm('确定要删除选中的帖子吗？', '删除确认', { type: 'warning' })
    .then(async () => {
      await deleteLuntan(ids)
      ElMessage.success('删除成功')
      load()
    })
    .catch(() => {})
}

function handleRowDelete(row: LuntanPostItem) {
  ElMessageBox.confirm('确定要删除该帖子吗？其评论、回评一并清理。', '删除确认', { type: 'warning' })
    .then(async () => {
      await deleteLuntan([row.id])
      ElMessage.success('删除成功')
      load()
    })
    .catch(() => {})
}

function handleSetTop(row: LuntanPostItem) {
  const target = row.is_top ? 0 : 1
  setLuntanTop([row.id], target)
    .then(() => {
      ElMessage.success(target ? '已置顶' : '已取消置顶')
      load()
    })
    .catch(() => {})
}

// ============ 整页编辑 ============
const saving = ref(false)
const form = reactive({
  id: 0,
  mid: 0,
  cid: undefined as number | undefined,
  content: '',
  pics: [] as string[],
  video: '',
  readcount: 0,
  zan: 0,
  status: 1 as number,
})

function resetForm() {
  Object.assign(form, {
    id: 0,
    mid: 0,
    cid: undefined as number | undefined,
    content: '',
    pics: [] as string[],
    video: '',
    readcount: 0,
    zan: 0,
    status: 1,
  })
}

async function openEdit(row: LuntanPostItem) {
  resetForm()
  try {
    const d = await getLuntanDetail(row.id)
    Object.assign(form, {
      id: d.id,
      mid: d.mid || 0,
      cid: d.cid || 0,
      content: d.content || '',
      pics: d.pics_arr || [],
      video: d.video || '',
      readcount: d.readcount || 0,
      zan: d.zan || 0,
      status: d.status === 0 || d.status === 2 || d.status === 3 ? d.status : 1,
    })
    viewMode.value = 'edit'
  } catch {
    // 错误提示由拦截器统一弹出
  }
}

function backToList() {
  viewMode.value = 'list'
  resetForm()
  load()
}

// 图片多选
const imgPickerVisible = ref(false)
function openImgPicker() {
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  urls.forEach((u) => {
    if (!form.pics.includes(u)) form.pics.push(u)
  })
}
function removePic(i: number) {
  form.pics.splice(i, 1)
}

// 视频上传
const uploadingVideo = ref(false)
async function onVideoUpload(options: { file: File }) {
  uploadingVideo.value = true
  try {
    const res = await uploadFile(options.file)
    if (res?.url) {
      form.video = res.url
      ElMessage.success('视频上传成功')
    }
  } finally {
    uploadingVideo.value = false
  }
}

// 查找会员
const memberPickerVisible = ref(false)
const memberKeyword = ref('')
const memberList = ref<MemberRow[]>([])
const memberLoading = ref(false)
async function searchMembers() {
  memberLoading.value = true
  try {
    const res = await getMemberList({ keyword: memberKeyword.value || undefined, page: 1, pagesize: 20 })
    memberList.value = res.list || []
  } finally {
    memberLoading.value = false
  }
}
function pickMember(m: MemberRow) {
  form.mid = m.id
  memberPickerVisible.value = false
}

async function handleSave() {
  if (!form.cid) {
    ElMessage.warning('请选择分类')
    return
  }
  saving.value = true
  try {
    await saveLuntanPost({
      id: form.id || undefined,
      mid: form.mid || 0,
      cid: Number(form.cid),
      content: form.content.trim(),
      pics: form.pics.join(','),
      video: form.video,
      readcount: form.readcount,
      zan: form.zan,
      status: form.status,
    })
    ElMessage.success('保存成功')
    backToList()
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  loadCategories()
  load()
})
</script>

<template>
  <div class="lt-page">
    <!-- ============ 列表视图 ============ -->
    <div v-if="viewMode === 'list'">
      <div class="page-title">
        <span class="title-icon">≡</span> 帖子列表
      </div>

      <el-card shadow="never" class="toolbar">
        <div class="toolbar-left">
          <el-button type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleBatchDelete">删除</el-button>
          <el-button @click="handleBatchAudit(1)">审核通过</el-button>
          <el-button @click="handleBatchAudit(2)">审核驳回</el-button>
        </div>
        <div class="toolbar-right">
          <el-input v-model="query.keyword" placeholder="内容" clearable style="width: 140px" @keyup.enter="handleSearch" @clear="handleSearch" />
          <el-select v-model="query.cid" placeholder="分类" clearable style="width: 120px" @change="handleSearch">
            <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
          <el-select v-model="query.status" placeholder="状态" clearable style="width: 110px" @change="handleSearch">
            <el-option label="待审核" value="0" />
            <el-option label="已通过" value="1" />
            <el-option label="已驳回" value="2" />
          </el-select>
          <el-button type="success" class="search-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274205 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.186354 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
          </el-button>
        </div>
      </el-card>

      <el-card shadow="never" class="table-card">
        <el-table v-loading="loading" :data="list" border size="small" @selection-change="(rows: LuntanPostItem[]) => (selected = rows)">
          <el-table-column type="selection" width="40" />
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column label="分类" width="110" show-overflow-tooltip>
            <template #default="{ row }">{{ row.cname || '-' }}</template>
          </el-table-column>
          <el-table-column label="发帖人" width="140">
            <template #default="{ row }">
              <div class="member-cell">
                <el-avatar :size="26" :src="row.headimg || undefined">{{ (row.nickname || '管理员').slice(0, 1) }}</el-avatar>
                <span>{{ row.nickname || '管理员' }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="帖子内容" min-width="260">
            <template #default="{ row }">
              <div class="post-text">{{ row.content || '-' }}</div>
              <div v-if="row.pics_arr && row.pics_arr.length" class="pic-row">
                <el-image
                  v-for="(p, i) in row.pics_arr"
                  :key="i"
                  :src="p"
                  :preview-src-list="row.pics_arr"
                  :initial-index="i"
                  fit="cover"
                  class="post-pic"
                  preview-teleported
                />
              </div>
              <video v-if="row.video" :src="row.video" controls class="post-video" />
            </template>
          </el-table-column>
          <el-table-column prop="readcount" label="阅读数" width="80" />
          <el-table-column prop="zan" label="点赞数" width="80" />
          <el-table-column prop="plcount" label="评论数" width="80">
            <template #default="{ row }"><span class="num-blue">{{ row.plcount || 0 }}</span></template>
          </el-table-column>
          <el-table-column label="发布时间" width="150">
            <template #default="{ row }">{{ row.createtime_text || (row.createtime ? formatTime(Number(row.createtime)) : '-') }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="statusType(row)" size="small">{{ statusText(row) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="180">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="primary" @click="handleSetTop(row)">{{ row.is_top ? '取消置顶' : '置顶' }}</el-button>
                <el-button link type="danger" @click="handleRowDelete(row)">删除</el-button>
              </div>
            </template>
          </el-table-column>
          <template #empty>暂无数据</template>
        </el-table>

        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.pagesize"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next, jumper"
          class="pagination"
          @current-change="load"
          @size-change="load"
        />
      </el-card>
    </div>

    <!-- ============ 整页编辑（平铺主区域，右上角 X 关闭） ============ -->
    <div v-else class="edit-page">
      <div class="edit-head">
        <div class="edit-title">＋ {{ form.id ? '编辑帖子' : '添加帖子' }}</div>
        <el-button class="edit-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>

      <el-form label-width="90px" class="edit-form">
        <el-form-item label="发帖人ID">
          <el-input v-model.number="form.mid" placeholder="发帖人ID" style="width: 200px" />
          <el-button link type="primary" class="find-member" @click="memberPickerVisible = true">查找会员ID</el-button>
        </el-form-item>
        <el-form-item label="分类">
          <el-select v-model="form.cid" placeholder="--请选择--" style="width: 220px">
            <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="图片内容">
          <el-button @click="openImgPicker">批量上传</el-button>
          <div v-if="form.pics.length" class="pic-editor">
            <div v-for="(p, i) in form.pics" :key="i" class="pic-item">
              <el-image :src="p" fit="cover" class="pic-thumb" />
              <span class="pic-remove" @click="removePic(i)">×</span>
            </div>
          </div>
        </el-form-item>
        <el-form-item label="视频内容">
          <div class="video-row">
            <el-input v-model="form.video" placeholder="填写视频链接或上传视频" style="width: 320px" />
            <el-upload :show-file-list="false" :http-request="onVideoUpload" accept="video/mp4">
              <el-button :loading="uploadingVideo">上传视频</el-button>
            </el-upload>
          </div>
          <div class="field-tip">填写视频链接或上传视频，视频链接必须为mp4格式的源链接，视频大小须在50MB以内</div>
          <video v-if="form.video" :src="form.video" controls class="video-preview" />
        </el-form-item>
        <el-form-item label="阅读">
          <el-input-number v-model="form.readcount" :min="0" :controls="false" style="width: 200px" />
        </el-form-item>
        <el-form-item label="点赞">
          <el-input-number v-model="form.zan" :min="0" :controls="false" style="width: 200px" />
        </el-form-item>
        <el-form-item label="文本内容">
          <el-input v-model="form.content" type="textarea" :rows="5" style="width: 480px" />
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="0">待审核</el-radio>
            <el-radio :value="1">通过</el-radio>
            <el-radio :value="2">驳回</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 会员选择小弹窗 -->
    <el-dialog v-model="memberPickerVisible" title="查找会员" width="560px" append-to-body>
      <div class="member-search-bar">
        <el-input v-model="memberKeyword" placeholder="输入会员昵称/ID搜索" clearable style="width: 260px" @keyup.enter="searchMembers" />
        <el-button type="primary" :loading="memberLoading" @click="searchMembers">搜索</el-button>
      </div>
      <el-table v-loading="memberLoading" :data="memberList" border size="small" max-height="360">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="头像" width="60">
          <template #default="{ row }"><el-avatar :size="28" :src="row.headimg || undefined" /></template>
        </el-table-column>
        <el-table-column prop="nickname" label="昵称" min-width="140" show-overflow-tooltip />
        <el-table-column prop="tel" label="手机号" width="120" />
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="primary" @click="pickMember(row)">选择</el-button>
          </template>
        </el-table-column>
        <template #empty>暂无数据</template>
      </el-table>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" :multi="true" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.page-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding: 2px 4px 8px;
}
.title-icon {
  margin-right: 4px;
  color: #909399;
}
.toolbar {
  margin-bottom: 3px;
}
.toolbar :deep(.el-card__body) {
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: nowrap;
}
.toolbar-left {
  display: flex;
  gap: 8px;
  flex: 0 0 auto;
}
.toolbar-left .el-button {
  margin: 0;
}
.toolbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1 1 auto;
  justify-content: flex-end;
  flex-wrap: nowrap;
  min-width: 0;
}
.search-btn {
  padding: 8px 14px;
}
.search-btn svg {
  display: block;
}
.table-card {
  margin-top: 3px;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}
.post-text {
  color: #303133;
  word-break: break-all;
  line-height: 1.5;
}
.pic-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 6px;
}
.post-pic {
  width: 60px;
  height: 60px;
  border-radius: 4px;
  cursor: pointer;
}
.post-video {
  margin-top: 6px;
  width: 200px;
  display: block;
}
.num-blue {
  color: #409eff;
}
.row-ops {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  align-items: center;
  column-gap: 8px;
  row-gap: 4px;
}
.row-ops .el-button {
  margin: 0;
  padding: 0;
  height: auto;
  font-size: 12px;
}
/* 整页编辑 */
.edit-page {
  background: #fff;
  border-radius: 6px;
  padding: 16px 20px;
}
.edit-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.edit-title {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}
.edit-close {
  border: none;
  background: transparent;
  color: #909399;
}
.find-member {
  margin-left: 8px;
}
.video-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.field-tip {
  color: #909399;
  font-size: 12px;
  margin-top: 6px;
}
.video-preview {
  margin-top: 8px;
  width: 240px;
}
.pic-editor {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
}
.pic-item {
  position: relative;
  width: 64px;
  height: 64px;
}
.pic-thumb {
  width: 64px;
  height: 64px;
  border-radius: 4px;
  border: 1px solid #e4e7ed;
}
.pic-remove {
  position: absolute;
  top: -8px;
  right: -8px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #f56c6c;
  color: #fff;
  font-size: 13px;
  line-height: 18px;
  text-align: center;
  cursor: pointer;
}
.member-search-bar {
  display: flex;
  gap: 8px;
  margin-bottom: 10px;
}
</style>
