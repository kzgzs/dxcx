<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Close, Search } from '@element-plus/icons-vue'
import {
  batchDeleteComments,
  batchSetCommentStatus,
  getCommentList,
  getCommentSysset,
  replyComment,
  saveComment,
  saveCommentSysset,
} from '../../api/comment'
import { getGoodsList } from '../../api/goods'
import type {
  CommentItem,
  CommentSysset,
  GoodsItem,
} from '../../api/types'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// ============ 选图（ImgIconPicker 统一选图） ============
const imgPickerVisible = ref(false)
const imgPickerMulti = ref(false)
let imgPickerSetter: ((urls: string[]) => void) | null = null
function openImgPicker(setter: (urls: string[]) => void, multi = false) {
  imgPickerMulti.value = multi
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  imgPickerSetter?.(urls)
  imgPickerSetter = null
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<CommentItem[]>([])
const total = ref(0)
const selectedRows = ref<CommentItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '', // 评价内容
  dateRange: null as [Date, Date] | null,
})

// 排序（默认 ID 倒序）
const sortField = ref<'id' | 'score' | 'createtime'>('id')
const sortOrder = ref<'asc' | 'desc'>('desc')

// 商户过滤视图预置：bid prop 由菜单工厂注入，缺省不影响自营菜单
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })

async function fetchList() {
  loading.value = true
  try {
    const data = await getCommentList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      start_time: query.dateRange ? Math.floor(query.dateRange[0].getTime() / 1000) : undefined,
      end_time: query.dateRange ? Math.floor(query.dateRange[1].getTime() / 1000) : undefined,
      bid: props.bid || undefined,
      sort_field: sortField.value,
      sort_order: sortOrder.value,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
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

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

function onSortChange(change: { prop?: string; order?: string | null }) {
  if (!change.prop || !change.order) {
    sortField.value = 'id'
    sortOrder.value = 'desc'
  } else {
    sortField.value = change.prop as 'id' | 'score' | 'createtime'
    sortOrder.value = change.order === 'ascending' ? 'asc' : 'desc'
  }
  query.page = 1
  fetchList()
}

function onSelectionChange(rows: CommentItem[]) {
  selectedRows.value = rows
}

// ============ 批量操作（删除 / 已审 / 未审） ============
function selectedIds(): number[] {
  return selectedRows.value.map((r) => r.id)
}

function batchSet(status: 0 | 1) {
  const ids = selectedIds()
  if (ids.length === 0) {
    ElMessage.warning('请选择数据')
    return
  }
  const label = status === 1 ? '已审核' : '未审核'
  ElMessageBox.confirm(`确定要改为${label}吗?`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await batchSetCommentStatus(ids, status)
        ElMessage.success('操作成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function batchDelete() {
  const ids = selectedIds()
  if (ids.length === 0) {
    ElMessage.warning('请选择数据')
    return
  }
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await batchDeleteComments(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// 行内状态按钮：已审核(蓝) <-> 未审核(红)；已驳回兼容处理为置为已审核
function toggleStatus(row: CommentItem) {
  const target: 0 | 1 = row.status === 1 ? 0 : 1
  const label = target === 1 ? '已审核' : '未审核'
  ElMessageBox.confirm(`确定要改为${label}吗?`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await batchSetCommentStatus([row.id], target)
        ElMessage.success('操作成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// 单条删除
function handleDelete(row: CommentItem) {
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await batchDeleteComments([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 评价详情 ============
const detailVisible = ref(false)
const detailRow = ref<CommentItem | null>(null)

function openDetail(row: CommentItem) {
  detailRow.value = row
  detailVisible.value = true
}

// ============ 回复（支持图片） ============
const replyVisible = ref(false)
const replySubmitting = ref(false)
const replyForm = reactive({ id: 0, content: '', pics: [] as string[] })

function openReply(row: CommentItem) {
  replyForm.id = row.id
  replyForm.content = row.reply_content || ''
  replyForm.pics = [...(row.reply_content_pic || [])]
  replyVisible.value = true
}

function removeReplyPic(index: number) {
  replyForm.pics.splice(index, 1)
}

async function handleReply() {
  if (!replyForm.content.trim() && replyForm.pics.length === 0) {
    ElMessage.warning('请填写回复内容')
    return
  }
  replySubmitting.value = true
  try {
    await replyComment(replyForm.id, replyForm.content.trim(), replyForm.pics.join(','))
    ElMessage.success('回复成功')
    replyVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    replySubmitting.value = false
  }
}

// ============ 虚拟评价新增 ============
const saveVisible = ref(false)
const saveSubmitting = ref(false)
const goodsOptions = ref<GoodsItem[]>([])

const saveForm = reactive({
  id: 0,
  proid: null as number | null,
  proname: '',
  propic: '',
  ggname: '',
  nickname: '',
  headimg: '',
  score: 5,
  content: '',
  pics: [] as string[],
  createtime: null as Date | null,
})

async function loadGoodsOptions() {
  if (goodsOptions.value.length > 0) return
  try {
    const data = await getGoodsList({ page: 1, pagesize: 100 })
    goodsOptions.value = data.list || []
  } catch {
    // 商品选项加载失败不阻塞弹窗
  }
}

function resetSaveForm() {
  Object.assign(saveForm, {
    id: 0,
    proid: null,
    proname: '',
    propic: '',
    ggname: '',
    nickname: '',
    headimg: '',
    score: 5,
    content: '',
    pics: [],
    createtime: null,
  })
}

async function openAdd() {
  resetSaveForm()
  saveVisible.value = true
  await loadGoodsOptions()
}

function onGoodsChange(proid: number | null) {
  const goods = goodsOptions.value.find((g) => g.id === proid)
  if (goods) {
    saveForm.proname = goods.name
    saveForm.propic = goods.image
  }
}

function removeSavePic(index: number) {
  saveForm.pics.splice(index, 1)
}

async function handleSave() {
  if ((saveForm.proid ?? 0) <= 0 && !saveForm.proname.trim()) {
    ElMessage.warning('请选择商品或填写商品名称')
    return
  }
  if (!saveForm.nickname.trim()) {
    ElMessage.warning('请填写会员昵称')
    return
  }
  saveSubmitting.value = true
  try {
    await saveComment({
      id: saveForm.id || undefined,
      proid: saveForm.proid && saveForm.proid > 0 ? saveForm.proid : 0,
      proname: saveForm.proname.trim(),
      propic: saveForm.propic,
      ggname: saveForm.ggname.trim(),
      score: saveForm.score,
      content: saveForm.content.trim(),
      content_pic: saveForm.pics.join(','),
      nickname: saveForm.nickname.trim(),
      headimg: saveForm.headimg,
      createtime: saveForm.createtime ? Math.floor(saveForm.createtime.getTime() / 1000) : undefined,
    })
    ElMessage.success('新增成功')
    saveVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saveSubmitting.value = false
  }
}

// ============ 评价开关 ============
const syssetVisible = ref(false)
const syssetSubmitting = ref(false)
const syssetForm = reactive<CommentSysset>({ comment: 1, comment_check: 1 })

async function openSysset() {
  try {
    const data = await getCommentSysset()
    syssetForm.comment = data.comment
    syssetForm.comment_check = data.comment_check
  } catch {
    // 读取失败用默认值回显
  }
  syssetVisible.value = true
}

async function handleSyssetSave() {
  syssetSubmitting.value = true
  try {
    await saveCommentSysset({
      comment: syssetForm.comment,
      comment_check: syssetForm.comment_check,
    })
    ElMessage.success('保存成功')
    syssetVisible.value = false
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    syssetSubmitting.value = false
  }
}

onMounted(fetchList)
</script>

<template>
  <div>
    <!-- 工具栏：左批量操作，右搜索 -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button @click="batchDelete">删除</el-button>
          <el-button @click="batchSet(1)">已审</el-button>
          <el-button @click="batchSet(0)">未审</el-button>
        </div>
        <div class="toolbar-right">
          <span class="field-label">评价内容</span>
          <el-input
            v-model="query.keyword"
            placeholder="请输入评价内容"
            clearable
            class="kw-input"
            @keyup.enter="handleSearch"
          />
          <span class="field-label">评价时间</span>
          <el-date-picker
            v-model="query.dateRange"
            type="daterange"
            range-separator="~"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            class="time-range"
          />
          <el-button type="primary" :icon="Search" @click="handleSearch" />
          <el-button link type="primary" @click="openSysset">评价设置</el-button>
          <el-button link type="primary" @click="openAdd">新增虚拟评价</el-button>
        </div>
      </div>
    </el-card>

    <!-- 评价列表 -->
    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        border
        size="small"
        :default-sort="{ prop: 'id', order: 'descending' }"
        @selection-change="onSelectionChange"
        @sort-change="onSortChange"
      >
        <el-table-column type="selection" width="42" />
        <el-table-column label="ID" prop="id" width="70" sortable="custom" />
        <el-table-column label="商品信息" width="300">
          <template #default="{ row }">
            <div class="goods-cell">
              <el-image
                v-if="row.propic"
                :src="row.propic"
                :preview-src-list="[row.propic]"
                fit="cover"
                class="goods-img"
                preview-teleported
              />
              <div v-else class="goods-img goods-img-empty" />
              <div class="goods-meta">
                <div class="goods-name" :title="row.proname">{{ row.proname || '-' }}</div>
                <div v-if="row.ggname" class="goods-gg" :title="row.ggname">{{ row.ggname }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评价人信息" width="150">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar :size="32" :src="row.headimg || undefined" />
              <span class="nickname" :title="row.nickname">{{ row.nickname || '-' }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评分" prop="score" width="80" align="center" sortable="custom">
          <template #default="{ row }">{{ row.score }}星</template>
        </el-table-column>
        <el-table-column label="评价内容" min-width="200">
          <template #default="{ row }">
            <div class="content-text">{{ row.content || '-' }}</div>
            <div v-if="row.content_pic && row.content_pic.length" class="pic-row">
              <el-image
                v-for="(pic, i) in row.content_pic"
                :key="i"
                :src="pic"
                :preview-src-list="row.content_pic"
                :initial-index="i"
                fit="cover"
                class="content-img"
                preview-teleported
              />
            </div>
          </template>
        </el-table-column>
        <el-table-column label="评价时间" prop="createtime" width="150" sortable="custom">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-button v-if="row.status === 1" size="small" type="primary" @click="toggleStatus(row)">
              已审核
            </el-button>
            <el-button v-else-if="row.status === 0" size="small" type="danger" @click="toggleStatus(row)">
              未审核
            </el-button>
            <el-button v-else size="small" type="info" @click="toggleStatus(row)">已驳回</el-button>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="180" fixed="right" align="center">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
            <el-button link type="primary" size="small" @click="openReply(row)">回复</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
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

    <!-- 评价详情 -->
    <el-dialog v-model="detailVisible" title="评价详情" width="500px">
      <div v-if="detailRow" class="detail-box">
        <div class="detail-item">
          <div class="detail-label">评价人</div>
          <div class="detail-value">
            <el-avatar :size="32" :src="detailRow.headimg || undefined" />
            <span>{{ detailRow.nickname || '-' }}</span>
          </div>
        </div>
        <div class="detail-item">
          <div class="detail-label">评价时间</div>
          <div class="detail-value">{{ formatTime(detailRow.createtime) }}</div>
        </div>
        <div class="detail-item">
          <div class="detail-label">内容</div>
          <div class="detail-value">{{ detailRow.content || '-' }}</div>
        </div>
        <div v-if="detailRow.content_pic && detailRow.content_pic.length" class="detail-item">
          <div class="detail-label">图片</div>
          <div class="detail-value">
            <el-image
              v-for="(pic, i) in detailRow.content_pic"
              :key="i"
              :src="pic"
              :preview-src-list="detailRow.content_pic"
              :initial-index="i"
              fit="cover"
              class="content-img"
              preview-teleported
            />
          </div>
        </div>
        <div
          v-if="detailRow.reply_content || (detailRow.reply_content_pic && detailRow.reply_content_pic.length)"
          class="detail-item"
        >
          <div class="detail-label">回复内容</div>
          <div class="detail-value">
            <div>{{ detailRow.reply_content }}</div>
            <div v-if="detailRow.reply_content_pic && detailRow.reply_content_pic.length" class="pic-row">
              <el-image
                v-for="(pic, i) in detailRow.reply_content_pic"
                :key="i"
                :src="pic"
                :preview-src-list="detailRow.reply_content_pic"
                :initial-index="i"
                fit="cover"
                class="content-img"
                preview-teleported
              />
            </div>
          </div>
        </div>
      </div>
    </el-dialog>

    <!-- 回复弹窗 -->
    <el-dialog v-model="replyVisible" title="回复评价" width="480px">
      <el-form label-width="80px" label-position="left">
        <el-form-item label="回复内容">
          <el-input
            v-model="replyForm.content"
            type="textarea"
            :rows="4"
            maxlength="255"
            show-word-limit
            placeholder="请输入回复内容"
          />
        </el-form-item>
        <el-form-item label="回复图片">
          <div class="pic-editor">
            <div v-for="(pic, i) in replyForm.pics" :key="i" class="pic-item">
              <el-image :src="pic" fit="cover" class="pic-thumb" />
              <el-icon class="pic-remove" @click="removeReplyPic(i)"><Close /></el-icon>
            </div>
            <div
              v-if="replyForm.pics.length < 3"
              class="pic-add"
              @click="openImgPicker((urls) => { urls.forEach((u) => { if (replyForm.pics.length < 3 && !replyForm.pics.includes(u)) replyForm.pics.push(u) }) }, true)"
            >+</div>
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="replyVisible = false">取消</el-button>
        <el-button type="primary" :loading="replySubmitting" @click="handleReply">确定</el-button>
      </template>
    </el-dialog>

    <!-- 新增虚拟评价弹窗 -->
    <el-dialog v-model="saveVisible" title="新增虚拟评价" width="560px">
      <el-form label-width="90px" label-position="left">
        <el-form-item label="关联商品">
          <el-select
            v-model="saveForm.proid"
            filterable
            clearable
            placeholder="可选，选择后自动带出名称/图片"
            style="width: 100%"
            @change="onGoodsChange"
          >
            <el-option v-for="g in goodsOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="商品名称">
          <el-input v-model="saveForm.proname" placeholder="未选商品时必填" clearable />
        </el-form-item>
        <el-form-item label="商品图片">
          <div class="pic-editor">
            <div v-if="saveForm.propic" class="pic-item">
              <el-image :src="saveForm.propic" fit="cover" class="pic-thumb" />
              <el-icon class="pic-remove" @click="saveForm.propic = ''"><Close /></el-icon>
            </div>
            <div v-else class="pic-add" @click="openImgPicker((urls) => { if (urls[0]) saveForm.propic = urls[0] })">+</div>
          </div>
        </el-form-item>
        <el-form-item label="规格名称">
          <el-input v-model="saveForm.ggname" placeholder="选填" clearable />
        </el-form-item>
        <el-form-item label="会员昵称">
          <el-input v-model="saveForm.nickname" placeholder="必填" clearable />
        </el-form-item>
        <el-form-item label="会员头像">
          <div class="pic-editor">
            <div v-if="saveForm.headimg" class="pic-item">
              <el-image :src="saveForm.headimg" fit="cover" class="pic-thumb" />
              <el-icon class="pic-remove" @click="saveForm.headimg = ''"><Close /></el-icon>
            </div>
            <div v-else class="pic-add" @click="openImgPicker((urls) => { if (urls[0]) saveForm.headimg = urls[0] })">+</div>
          </div>
        </el-form-item>
        <el-form-item label="评分">
          <el-rate v-model="saveForm.score" />
        </el-form-item>
        <el-form-item label="评价内容">
          <el-input
            v-model="saveForm.content"
            type="textarea"
            :rows="3"
            maxlength="255"
            show-word-limit
            placeholder="请输入评价内容"
          />
        </el-form-item>
        <el-form-item label="评价图片">
          <div class="pic-editor">
            <div v-for="(pic, i) in saveForm.pics" :key="i" class="pic-item">
              <el-image :src="pic" fit="cover" class="pic-thumb" />
              <el-icon class="pic-remove" @click="removeSavePic(i)"><Close /></el-icon>
            </div>
            <div
              v-if="saveForm.pics.length < 9"
              class="pic-add"
              @click="openImgPicker((urls) => { urls.forEach((u) => { if (saveForm.pics.length < 9 && !saveForm.pics.includes(u)) saveForm.pics.push(u) }) }, true)"
            >+</div>
          </div>
        </el-form-item>
        <el-form-item label="评价时间">
          <el-date-picker
            v-model="saveForm.createtime"
            type="datetime"
            placeholder="默认当前时间"
            style="width: 100%"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="saveVisible = false">取消</el-button>
        <el-button type="primary" :loading="saveSubmitting" @click="handleSave">确定</el-button>
      </template>
    </el-dialog>

    <!-- 评价设置弹窗 -->
    <el-dialog v-model="syssetVisible" title="评价设置" width="420px">
      <el-form label-width="110px" label-position="left">
        <el-form-item label="评价总开关">
          <el-switch v-model="syssetForm.comment" :active-value="1" :inactive-value="0" />
          <span class="form-tip">关闭后客户端不可评价</span>
        </el-form-item>
        <el-form-item label="评价审核">
          <el-switch v-model="syssetForm.comment_check" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后新评价需审核通过才展示</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="syssetVisible = false">取消</el-button>
        <el-button type="primary" :loading="syssetSubmitting" @click="handleSyssetSave">保存</el-button>
      </template>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" :multi="imgPickerMulti" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding: 14px 16px;
}

.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
}

.toolbar-left {
  display: flex;
  gap: 8px;
}

.toolbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.field-label {
  font-size: 13px;
  color: #606266;
  white-space: nowrap;
}

.kw-input {
  width: 180px;
}

.time-range {
  width: 240px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

/* 商品信息列 */
.goods-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.goods-img {
  width: 50px;
  height: 50px;
  border-radius: 4px;
  flex-shrink: 0;
}

.goods-img-empty {
  background: #f5f7fa;
  border: 1px solid #ebeef5;
}

.goods-meta {
  width: 210px;
  line-height: 20px;
}

.goods-name {
  color: #303133;
  word-break: break-all;
}

.goods-gg {
  margin-top: 4px;
  color: #ff6600;
  font-size: 12px;
  word-break: break-all;
}

/* 评价人列 */
.member-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.member-cell .nickname {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 90px;
}

/* 评价内容列 */
.content-text {
  word-break: break-all;
  color: #303133;
}

.pic-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 6px;
}

.content-img {
  width: 44px;
  height: 44px;
  border-radius: 4px;
  cursor: pointer;
}

/* 详情弹窗 */
.detail-box {
  padding: 0 4px;
}

.detail-item {
  display: flex;
  padding: 12px 0;
  border-bottom: 1px dashed #ebeef5;
}

.detail-item:last-child {
  border-bottom: 0;
}

.detail-label {
  width: 70px;
  flex-shrink: 0;
  color: #303133;
}

.detail-value {
  flex: 1;
  color: #606266;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  word-break: break-all;
}

/* 选图编辑器 */
.pic-editor {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
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
  top: -6px;
  right: -6px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: #f56c6c;
  color: #fff;
  font-size: 12px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
}

.pic-add {
  width: 64px;
  height: 64px;
  border: 1px dashed #d9d9d9;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  color: #8c939d;
  cursor: pointer;
}

.pic-add:hover {
  border-color: #409eff;
  color: #409eff;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
