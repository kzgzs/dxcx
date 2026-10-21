<script setup lang="ts">
/**
 * 扩展·视频管理（对齐点大 canzhao app/controller/VideoList.php + view/video_list/*）
 *
 * 列表页：工具栏一行（左 添加/删除，右 类型下拉/名称/绿色圆形搜索）
 *        表格列：勾选 / ID / 视频封面 / 视频名称 / 视频类型 / 创建时间 / 操作
 * 平铺增改（列表/平铺互斥，右上 X 返回列表）：
 *   - 视频名称 / 视频封面(素材库选图) / 视频类型 radio
 *   - 本地视频(type=0)：视频源链接 input + 上传视频(素材库选择器，支持视频) + 提示
 *   - 微信视频号(type=1)：播放类型 radio(视频号主页/内嵌播放) + 视频号ID/视频ID/视频Token
 * 字段口径完全照 canzhao：name/pic/type/video_url + ext_param{feedtype,finderuser,feedid,feedtoken}
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteVideo, getVideoList, saveVideo } from '../../api/videolist'
import type { VideoListItem } from '../../api/videolist'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const TYPE_MAP: Record<number, string> = { 0: '本地视频', 1: '微信视频号' }

// ============ 列表 ============
const viewMode = ref<'list' | 'edit'>('list')
const loading = ref(false)
const list = ref<VideoListItem[]>([])
const total = ref(0)
const selected = ref<VideoListItem[]>([])
const query = reactive({ page: 1, pagesize: 10, name: '', type: '' })

async function fetchList() {
  loading.value = true
  try {
    const res = await getVideoList({
      page: query.page,
      pagesize: query.pagesize,
      name: query.name,
      type: query.type,
    })
    list.value = res.list || []
    total.value = res.total || 0
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function formatTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(Number(ts) * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function onSelectionChange(rows: VideoListItem[]) {
  selected.value = rows
}

async function handleBatchDelete() {
  const ids = selected.value.map((r) => r.id)
  if (!ids.length) {
    ElMessage.warning('请先勾选要删除的视频')
    return
  }
  await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个视频吗？删除后无法恢复！`, '操作确认', { type: 'warning' })
  try {
    await deleteVideo(ids)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    /* 拦截器已提示 */
  }
}

async function handleDelete(row: VideoListItem) {
  await ElMessageBox.confirm(`确定要删除视频「${row.name}」吗？删除后无法恢复！`, '操作确认', { type: 'warning' })
  try {
    await deleteVideo([row.id])
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    /* 拦截器已提示 */
  }
}

// ============ 平铺增改 ============
const submitting = ref(false)
const form = reactive({
  id: 0,
  name: '',
  pic: '',
  type: 0 as 0 | 1,
  video_url: '',
  feedtype: 0 as 0 | 1,
  finderuser: '',
  feedid: '',
  feedtoken: '',
  // 不展示但保留，编辑时透传避免被后端默认值覆盖（canzhao 无此二字段，后端默认 1/0）
  status: 1,
  sort: 0,
})

function resetForm() {
  Object.assign(form, {
    id: 0,
    name: '',
    pic: '',
    type: 0 as 0 | 1,
    video_url: '',
    feedtype: 0 as 0 | 1,
    finderuser: '',
    feedid: '',
    feedtoken: '',
    status: 1,
    sort: 0,
  })
}

function openAdd() {
  resetForm()
  viewMode.value = 'edit'
}

function openEdit(row: VideoListItem) {
  resetForm()
  const ext = row.ext || {}
  Object.assign(form, {
    id: row.id,
    name: row.name || '',
    pic: row.pic || '',
    type: Number(row.type) === 1 ? 1 : 0,
    video_url: row.video_url || '',
    feedtype: Number(ext.feedtype ?? 0) === 1 ? 1 : 0,
    finderuser: ext.finderuser || '',
    feedid: ext.feedid || '',
    feedtoken: ext.feedtoken || '',
    status: Number(row.status ?? 1),
    sort: Number(row.sort ?? 0),
  })
  viewMode.value = 'edit'
}

function backToList() {
  viewMode.value = 'list'
}

// ============ 素材库选择（封面 / 上传视频 复用同一 ImgIconPicker） ============
const pickerVisible = ref(false)
const pickerMode = ref<'pic' | 'video'>('pic')

function openPicPicker() {
  pickerMode.value = 'pic'
  pickerVisible.value = true
}

function openVideoPicker() {
  pickerMode.value = 'video'
  pickerVisible.value = true
}

function onPick(urls: string[]) {
  const url = urls[0] || ''
  if (!url) return
  if (pickerMode.value === 'pic') form.pic = url
  else form.video_url = url
  pickerVisible.value = false
}

// ============ 提交 ============
async function handleSubmit() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写视频名称')
    return
  }
  submitting.value = true
  try {
    await saveVideo({
      id: form.id || undefined,
      name: form.name.trim(),
      pic: form.pic,
      type: form.type,
      video_url: form.type === 0 ? form.video_url : '',
      video_duration: '',
      status: form.status,
      sort: form.sort,
      ext:
        form.type === 1
          ? {
              feedtype: form.feedtype,
              finderuser: form.finderuser.trim(),
              feedid: form.feedid.trim(),
              feedtoken: form.feedtoken.trim(),
            }
          : {},
    })
    ElMessage.success('保存成功')
    viewMode.value = 'list'
    fetchList()
  } catch {
    /* 拦截器已提示 */
  } finally {
    submitting.value = false
  }
}

onMounted(fetchList)
</script>

<template>
  <div class="vl-page">
    <!-- ==================== 列表视图 ==================== -->
    <div v-if="viewMode === 'list'">
      <el-card shadow="never" class="toolbar">
        <div class="toolbar-left">
          <el-button type="success" @click="openAdd">添加</el-button>
          <el-button :disabled="!selected.length" @click="handleBatchDelete">删除</el-button>
        </div>
        <el-form inline class="toolbar-search" @submit.prevent>
          <el-form-item label="类型">
            <el-select
              v-model="query.type"
              placeholder="全部"
              style="width: 120px"
              @change="handleSearch"
            >
              <el-option label="全部" value="" />
              <el-option label="本地视频" value="0" />
              <el-option label="微信视频号" value="1" />
            </el-select>
          </el-form-item>
          <el-form-item label="名称">
            <el-input
              v-model="query.name"
              placeholder="名称"
              clearable
              style="width: 140px"
              @keyup.enter="handleSearch"
              @clear="handleSearch"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="success" circle class="search-icon-btn" @click="handleSearch">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274205 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.186354 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
            </el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card shadow="never">
        <el-table
          v-loading="loading"
          :data="list"
          border
          class="data-table"
          @selection-change="onSelectionChange"
        >
          <el-table-column type="selection" width="42" />
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column label="视频封面" width="110">
            <template #default="{ row }">
              <el-image
                v-if="row.pic"
                :src="row.pic"
                :preview-src-list="[row.pic]"
                preview-teleported
                fit="cover"
                class="cover-img"
              />
              <span v-else class="text-muted">-</span>
            </template>
          </el-table-column>
          <el-table-column prop="name" label="视频名称" min-width="160" show-overflow-tooltip />
          <el-table-column label="视频类型" width="120">
            <template #default="{ row }">{{ TYPE_MAP[Number(row.type)] || '-' }}</template>
          </el-table-column>
          <el-table-column label="创建时间" width="150">
            <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="140">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
              </div>
            </template>
          </el-table-column>
          <template #empty>暂无数据</template>
        </el-table>

        <el-pagination
          v-if="total > 0"
          v-model:current-page="query.page"
          v-model:page-size="query.pagesize"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next, jumper"
          class="pagination"
          @current-change="fetchList"
          @size-change="handleSearch"
        />
      </el-card>
    </div>

    <!-- ==================== 平铺增改主区域（列表/平铺互斥） ==================== -->
    <div v-else class="goods-edit">
      <div class="ge-head">
        <div class="ge-title">{{ form.id ? '编辑视频' : '＋ 添加视频' }}</div>
        <el-button class="ge-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>

      <div class="ge-body">
        <!-- 视频名称 -->
        <div class="form-row">
          <label class="form-label">视频名称</label>
          <el-input v-model="form.name" class="form-control" placeholder="请输入视频名称" maxlength="50" />
        </div>

        <!-- 视频封面 -->
        <div class="form-row">
          <label class="form-label">视频封面</label>
          <div class="form-side">
            <el-button size="small" @click="openPicPicker">上传图片</el-button>
            <div v-if="form.pic" class="cover-preview">
              <img :src="form.pic" alt="封面" />
            </div>
          </div>
        </div>

        <!-- 视频类型 -->
        <div class="form-row">
          <label class="form-label">视频类型</label>
          <el-radio-group v-model="form.type">
            <el-radio :value="0">本地视频</el-radio>
            <el-radio :value="1">微信视频号</el-radio>
          </el-radio-group>
        </div>

        <!-- 本地视频：视频源链接 + 上传视频(素材库) -->
        <div v-if="form.type === 0" class="form-row">
          <label class="form-label">视频</label>
          <div class="video-block">
            <div class="video-input-row">
              <el-input v-model="form.video_url" class="video-input" placeholder="请填写视频链接" />
              <el-button size="small" @click="openVideoPicker">上传视频</el-button>
            </div>
            <div class="ge-hint-block">填写视频链接或上传视频，视频链接必须为mp4格式的源链接，视频大小须在50MB以内</div>
          </div>
        </div>

        <!-- 微信视频号：条件字段 -->
        <template v-else>
          <div class="form-row">
            <label class="form-label">播放类型</label>
            <el-radio-group v-model="form.feedtype">
              <el-radio :value="0">视频号主页</el-radio>
              <el-radio :value="1">内嵌播放</el-radio>
            </el-radio-group>
          </div>

          <div class="form-row">
            <label class="form-label">视频号ID</label>
            <el-input v-model="form.finderuser" class="form-control" placeholder="sp开头的一串字符" />
            <span class="ge-hint">sp开头的一串字符，必填。登录视频号助手，在首页可以查看自己的视频号ID，仅微信小程序可用</span>
          </div>

          <div class="form-row">
            <label class="form-label">视频ID</label>
            <el-input v-model="form.feedid" class="form-control" placeholder="视频ID" />
            <span class="ge-hint">必填，登录视频号助手，可以查看视频ID，仅微信小程序可用</span>
          </div>

          <div class="form-row">
            <label class="form-label">视频Token</label>
            <el-input v-model="form.feedtoken" class="form-control" placeholder="feed-token" />
            <span class="ge-hint">
              feed-token，内嵌播放时必填
              <a
                href="https://developers.weixin.qq.com/miniprogram/dev/framework/open-ability/channels-activity.html#feed-token"
                target="_blank"
                style="color: #0da0ff"
                >如何获取</a
              >，内嵌播放时视频号和小程序必须为同一主体
            </span>
          </div>
        </template>

        <!-- 提交 -->
        <div class="form-row">
          <label class="form-label"></label>
          <el-button type="success" :loading="submitting" @click="handleSubmit">提交</el-button>
        </div>
      </div>
    </div>

    <!-- 素材库选择弹窗（封面 / 上传视频 复用，支持图片与视频） -->
    <ImgIconPicker v-model:visible="pickerVisible" default-tab="upload" @pick="onPick" />
  </div>
</template>

<style scoped>
.vl-page {
  padding: 3px;
}

/* 工具栏一行：左按钮 / 右搜索，不换行 */
.toolbar :deep(.el-card__body) {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 12px;
  padding: 10px;
}
.toolbar-left {
  flex: none;
  display: flex;
  gap: 8px;
}
.toolbar-search {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  justify-content: flex-end;
  flex-wrap: nowrap;
  min-width: 0;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
}
.toolbar-search :deep(.el-form-item:last-child) {
  margin-right: 0;
}
.toolbar-search :deep(.el-form-item__label) {
  padding-right: 6px;
}
.search-icon-btn {
  padding: 10px;
}

/* 表格：表头 #e6e6e6，内容居左，无 fixed */
.data-table :deep(th) {
  background: #e6e6e6;
}
.data-table {
  text-align: left;
}
.cover-img {
  width: 70px;
  height: 50px;
  border-radius: 4px;
}
.text-muted {
  color: #c0c4cc;
  font-size: 12px;
}

/* 行操作按钮一行排列 */
.row-ops {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  align-items: center;
  column-gap: 8px;
  row-gap: 4px;
  line-height: 1.5;
}
.row-ops .el-button {
  margin: 0;
  height: auto;
  min-height: 0;
  padding: 0;
  font-size: 12px;
}

.pagination {
  margin-top: 3px;
  justify-content: flex-end;
}

/* 平铺增改面板 */
.goods-edit {
  background: #fff;
  border-radius: 6px;
  padding: 16px 20px;
}
.ge-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.ge-title {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}
.ge-close {
  border: none;
  background: transparent;
  color: #909399;
}
.ge-close:hover {
  color: #409eff;
  background: transparent;
}
.ge-body {
  max-width: 860px;
}
.form-row {
  display: flex;
  align-items: flex-start;
  margin-bottom: 14px;
  gap: 10px;
}
.form-label {
  width: 90px;
  text-align: right;
  color: #333;
  flex-shrink: 0;
  line-height: 32px;
}
.form-control {
  flex: 0 1 360px;
  text-align: left;
}
.form-side {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.cover-preview img {
  width: 120px;
  height: 80px;
  object-fit: cover;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}
.video-block {
  flex: 1 1 auto;
  max-width: 560px;
}
.video-input-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.video-input {
  flex: 1 1 auto;
}
.ge-hint {
  color: #909399;
  font-size: 12px;
  line-height: 32px;
}
.ge-hint-block {
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
  margin-top: 4px;
}
</style>
