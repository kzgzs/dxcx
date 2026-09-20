<script setup lang="ts">
// 短视频列表（对照点大 Shortvideo 控制器 / shortvideo 视图）
// - 平台「营销→短视频→视频列表」预置 bid='0'（仅平台视频，可添加）；
// - 平台「商户→短视频列表」预置 bid='gt0'（仅商户视频，平台只查看/审核/删除，无“添加”，列表多“所属商户”列）；
//   二者是同一张 shortvideo 表按 bid 过滤的两个视图。
// - 点大商户视频由商户登录商户后台（Backstage）自建（bid=商户ID，分类也随商户）；
//   本项目暂无商户端入口，演示数据由 php think demo:shortvideos 生成。
// - 状态三态：0 待审核 / 1 已通过 / 2 已驳回（驳回带 reason）；C 端仅展示 status=1。
// - 添加/编辑平铺在主区域、右上角 X 关闭（非弹窗），字段对齐点大 shortvideo/edit。
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  checkShortvideo,
  deleteShortvideo,
  getShortvideoCategoryOptions,
  getShortvideoCommentList,
  getShortvideoDetail,
  getShortvideoList,
  saveShortvideo,
  setShortvideoStatus,
  type ShortvideoCommentItem,
  type ShortvideoItem,
} from '../../api/shortvideo'
import { getGoodsList } from '../../api/goods'
import type { GoodsItem } from '../../api/types'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { formatTime } from '../../utils/time'

const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
/** 商户视图：平台在「商户」菜单下查看各商户视频（bid>0），不提供“添加” */
const isMerchantView = computed(() => props.bid === 'gt0')
/** 列表筛选分类的 bid 维度：商户视图取所有商户分类，平台视图取平台分类 */
const filterScope = computed(() => (isMerchantView.value ? 'gt0' : '0'))

// ============ 视图切换：列表 / 整页编辑 ============
const viewMode = ref<'list' | 'edit'>('list')

// ============ 列表 ============
const loading = ref(false)
const list = ref<ShortvideoItem[]>([])
const total = ref(0)
const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '' as '' | '0' | '1' | '2',
  cid: 0 as number,
  createtime: '' as string,
})
const selected = ref<ShortvideoItem[]>([])

const filterCategories = ref<Array<{ id: number; name: string }>>([])
const editCategories = ref<Array<{ id: number; name: string }>>([])

async function loadFilterCategories() {
  try {
    filterCategories.value = await getShortvideoCategoryOptions(filterScope.value)
  } catch {
    filterCategories.value = []
  }
}

async function load() {
  loading.value = true
  try {
    const res = await getShortvideoList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword,
      status: query.status,
      cid: query.cid || undefined,
      bid: props.bid,
      createtime: query.createtime || undefined,
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

// ============ 状态展示 ============
function statusText(s: number): string {
  return s === 1 ? '已通过' : s === 2 ? '已驳回' : '待审核'
}
function statusType(s: number): 'success' | 'danger' | 'warning' {
  return s === 1 ? 'success' : s === 2 ? 'danger' : 'warning'
}

// ============ 整页编辑 ============
const saving = ref(false)
const form = reactive({
  id: 0,
  bid: 0,
  cid: 0,
  name: '',
  description: '',
  coverimg: '',
  url: '',
  video_duration: 0,
  linkurl: '',
  linkname: '',
  view_num: 0,
  zan_num: 0,
  sort: 0,
  comment: 1,
  comment_check: 0,
  status: 1,
  reason: '',
})
/** 已挂商品（点大为商品表格：商品ID / 商品名称 / 操作[删除]） */
const productRows = ref<Array<{ id: number; name: string }>>([])

function resetForm() {
  Object.assign(form, {
    id: 0,
    bid: 0,
    cid: 0,
    name: '',
    description: '',
    coverimg: '',
    url: '',
    video_duration: 0,
    linkurl: '',
    linkname: '',
    view_num: 0,
    zan_num: 0,
    sort: 0,
    comment: 1,
    comment_check: 0,
    status: 1,
    reason: '',
  })
  productRows.value = []
}

async function openAdd() {
  resetForm()
  try {
    editCategories.value = await getShortvideoCategoryOptions('0')
  } catch {
    editCategories.value = []
  }
  viewMode.value = 'edit'
}

async function openEdit(row: ShortvideoItem) {
  resetForm()
  try {
    const [detail, cats] = await Promise.all([
      getShortvideoDetail(row.id),
      getShortvideoCategoryOptions(String(row.bid ?? 0)),
    ])
    editCategories.value = cats || []
    Object.assign(form, {
      id: detail.id,
      bid: detail.bid,
      cid: detail.cid,
      name: detail.name,
      description: detail.description,
      coverimg: detail.coverimg,
      url: detail.url,
      video_duration: detail.video_duration || 0,
      linkurl: detail.linkurl,
      linkname: detail.linkname,
      view_num: detail.view_num || 0,
      zan_num: detail.zan_num || 0,
      sort: detail.sort || 0,
      comment: detail.comment,
      comment_check: detail.comment_check,
      status: detail.status,
      reason: detail.reason || '',
    })
    if (detail.productids.length > 0) {
      const res = await getGoodsList({ page: 1, pagesize: 100 })
      const map = new Map<number, GoodsItem>((res.list || []).map((g) => [g.id, g]))
      productRows.value = detail.productids
        .filter((id) => map.has(id))
        .map((id) => ({ id, name: map.get(id)!.name }))
    }
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

/** 视频可播放后自动带出时长（秒），对齐点大隐藏域 video_duration */
function onVideoLoaded(e: Event) {
  const v = e.target as HTMLVideoElement
  if (v && v.duration && isFinite(v.duration)) {
    form.video_duration = Math.round(v.duration * 100) / 100
  }
}

async function handleSave() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写视频名称')
    return
  }
  if (!form.url.trim()) {
    ElMessage.warning('请提供视频地址')
    return
  }
  if (form.status === 2 && !form.reason.trim()) {
    ElMessage.warning('驳回时请填写驳回原因')
    return
  }
  saving.value = true
  try {
    await saveShortvideo({
      id: form.id || undefined,
      bid: form.bid,
      cid: Number(form.cid) || 0,
      name: form.name,
      description: form.description,
      coverimg: form.coverimg,
      url: form.url,
      video_duration: form.video_duration,
      productids: productRows.value.map((p) => p.id),
      linkurl: form.linkurl,
      linkname: form.linkname,
      view_num: Number(form.view_num) || 0,
      zan_num: Number(form.zan_num) || 0,
      sort: Number(form.sort) || 0,
      comment: form.comment,
      comment_check: form.comment_check,
      status: form.status,
      reason: form.status === 2 ? form.reason : '',
    })
    ElMessage.success(form.id ? '保存成功' : '新增成功')
    backToList()
  } finally {
    saving.value = false
  }
}

// ============ 封面选图（ImgIconPicker） ============
const imgPickerVisible = ref(false)
function openImgPicker() {
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls[0]) form.coverimg = urls[0]
}

// ============ 挂商品选择（点大：商品表格 + 添加商品层） ============
const goodsPickerVisible = ref(false)
const goodsLoading = ref(false)
const goodsKeyword = ref('')
const goodsCandidates = ref<GoodsItem[]>([])
const goodsPicked = ref<GoodsItem[]>([])

async function searchGoods(kw: string) {
  goodsLoading.value = true
  try {
    const res = await getGoodsList({ keyword: kw, page: 1, pagesize: 20 })
    goodsCandidates.value = res.list || []
  } finally {
    goodsLoading.value = false
  }
}
function openGoodsPicker() {
  goodsKeyword.value = ''
  goodsPicked.value = []
  goodsPickerVisible.value = true
  searchGoods('')
}
function addPickedGoods() {
  goodsPicked.value.forEach((g) => {
    if (!productRows.value.some((p) => p.id === g.id)) {
      productRows.value.push({ id: g.id, name: g.name })
    }
  })
  goodsPickerVisible.value = false
}
function removeProduct(id: number) {
  productRows.value = productRows.value.filter((p) => p.id !== id)
}

// ============ 行 / 批量操作 ============
async function handleDelete(rows: ShortvideoItem[]) {
  if (!rows.length) {
    ElMessage.warning('请选择数据')
    return
  }
  await ElMessageBox.confirm(`确定删除选中的 ${rows.length} 个视频？评论与点赞记录将一并清理，且无法恢复！`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
  await deleteShortvideo(rows.map((r) => r.id))
  ElMessage.success('删除成功')
  selected.value = []
  load()
}

async function handleBatchStatus(status: number) {
  if (!selected.value.length) {
    ElMessage.warning('请选择数据')
    return
  }
  const label = status === 1 ? '已通过' : '已驳回'
  await ElMessageBox.confirm(`确定将选中的 ${selected.value.length} 个视频设为「${label}」吗？`, '操作确认', { type: 'warning' })
  await setShortvideoStatus(selected.value.map((r) => r.id), status)
  ElMessage.success(`已设为${label}`)
  load()
}

async function handleCheck(row: ShortvideoItem, pass: boolean) {
  if (pass) {
    await ElMessageBox.confirm(`确定审核通过视频「${row.name}」吗？`, '审核确认', {
      confirmButtonText: '通过',
      cancelButtonText: '取消',
      type: 'warning',
    })
    await checkShortvideo(row.id, 1)
    ElMessage.success('审核通过')
    load()
    return
  }
  const { value } = await ElMessageBox.prompt('请填写驳回原因', '驳回视频', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputValidator: (v) => (v && v.trim() ? true : '驳回原因不能为空'),
  })
  await checkShortvideo(row.id, 2, value)
  ElMessage.success('已驳回')
  load()
}

// ============ 查看评论（本页弹层，只读） ============
const commentVisible = ref(false)
const commentLoading = ref(false)
const commentList = ref<ShortvideoCommentItem[]>([])
const commentTitle = ref('')
async function openComments(row: ShortvideoItem) {
  commentTitle.value = `「${row.name}」的评论`
  commentVisible.value = true
  commentLoading.value = true
  try {
    const res = await getShortvideoCommentList({ vid: row.id, page: 1, pagesize: 50 })
    commentList.value = res.list || []
  } finally {
    commentLoading.value = false
  }
}

// ============ 查看链接（视频源地址，可复制） ============
const linkVisible = ref(false)
const linkUrl = ref('')
function openLink(row: ShortvideoItem) {
  linkUrl.value = row.url
  linkVisible.value = true
}
async function copyLink() {
  try {
    await navigator.clipboard.writeText(linkUrl.value)
    ElMessage.success('已复制')
  } catch {
    ElMessage.warning('复制失败，请手动选择复制')
  }
}

watch(
  () => props.bid,
  () => {
    query.cid = 0
    loadFilterCategories()
    handleSearch()
  },
)

onMounted(() => {
  loadFilterCategories()
  load()
})
</script>

<template>
  <div class="sv-page">
    <!-- ============ 列表视图 ============ -->
    <div v-if="viewMode === 'list'">
      <el-card shadow="never" class="toolbar">
        <div class="toolbar-left">
          <el-button v-if="!isMerchantView" type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleDelete(selected)">删除</el-button>
          <el-button type="success" plain @click="handleBatchStatus(1)">已通过</el-button>
          <el-button type="danger" plain @click="handleBatchStatus(2)">已驳回</el-button>
        </div>
        <el-form inline class="toolbar-search" @submit.prevent>
          <el-form-item label="名称">
            <el-input
              v-model="query.keyword"
              placeholder="名称"
              clearable
              style="width: 120px"
              @keyup.enter="handleSearch"
            />
          </el-form-item>
          <el-form-item label="分类">
            <el-select v-model="query.cid" placeholder="请选择" clearable style="width: 120px" @change="handleSearch">
              <el-option v-for="c in filterCategories" :key="c.id" :label="c.name" :value="c.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="状态">
            <el-select v-model="query.status" placeholder="全部" clearable style="width: 100px" @change="handleSearch">
              <el-option label="待审核" value="0" />
              <el-option label="已通过" value="1" />
              <el-option label="已驳回" value="2" />
            </el-select>
          </el-form-item>
          <el-form-item label="创建时间">
            <el-date-picker
              v-model="query.createtime"
              type="date"
              value-format="YYYY-MM-DD"
              placeholder="选择日期"
              style="width: 130px"
              @change="handleSearch"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="success" class="search-icon-btn" @click="handleSearch">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274205 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.186354 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
            </el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card shadow="never">
        <el-table v-loading="loading" :data="list" border @selection-change="(rows: ShortvideoItem[]) => (selected = rows)">
          <el-table-column type="selection" width="42" align="center" />
          <el-table-column prop="id" label="ID" width="64" align="center" header-align="center" />
          <el-table-column v-if="isMerchantView" prop="bname" label="所属商户" width="120" show-overflow-tooltip>
            <template #default="{ row }">{{ row.bname || '-' }}</template>
          </el-table-column>
          <el-table-column v-else label="发布人" width="110">
            <template #default="{ row }">
              <div v-if="row.mid > 0" class="publisher">
                <el-image v-if="row.headimg" :src="row.headimg" class="publisher-img" fit="cover" />
                <span>{{ row.nickname || '会员' }}</span>
              </div>
              <span v-else>平台</span>
            </template>
          </el-table-column>
          <el-table-column prop="cname" label="分类" width="100" show-overflow-tooltip>
            <template #default="{ row }">{{ row.cname || '-' }}</template>
          </el-table-column>
          <el-table-column prop="name" label="名称" min-width="150" show-overflow-tooltip />
          <el-table-column label="封面图" width="72" align="center">
            <template #default="{ row }">
              <el-image
                v-if="row.coverimg"
                :src="row.coverimg"
                :preview-src-list="[row.coverimg]"
                preview-teleported
                fit="cover"
                class="cover-img"
              />
              <span v-else class="text-muted">-</span>
            </template>
          </el-table-column>
          <el-table-column label="创建时间" width="150" align="center" header-align="center">
            <template #default="{ row }">{{ row.createtime ? formatTime(Number(row.createtime)) : '-' }}</template>
          </el-table-column>
          <el-table-column prop="view_num" label="播放数" width="80" align="center" header-align="center" />
          <el-table-column prop="zan_num" label="点赞数" width="80" align="center" header-align="center" />
          <el-table-column prop="comment_num" label="评论数" width="70" align="center" header-align="center" />
          <el-table-column prop="sort" label="序号" width="64" align="center" header-align="center" />
          <el-table-column label="状态" width="92" align="center" header-align="center">
            <template #default="{ row }">
              <el-tooltip v-if="row.status === 2 && row.reason" :content="`驳回原因：${row.reason}`" placement="top">
                <el-tag :type="statusType(row.status)" size="small">{{ statusText(row.status) }}</el-tag>
              </el-tooltip>
              <el-tag v-else :type="statusType(row.status)" size="small">{{ statusText(row.status) }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="320">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
                <el-button link type="primary" @click="openComments(row)">查看评论</el-button>
                <el-button link type="primary" @click="openLink(row)">查看链接</el-button>
                <template v-if="row.status === 0">
                  <el-button link type="success" @click="handleCheck(row, true)">通过</el-button>
                  <el-button link type="danger" @click="handleCheck(row, false)">驳回</el-button>
                </template>
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
          @change="load"
        />
      </el-card>
    </div>

    <!-- ============ 整页编辑（平铺主区域，右上角 X 关闭，非弹窗） ============ -->
    <div v-else class="goods-edit">
      <div class="ge-head">
        <el-button class="ge-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>

      <el-form label-width="92px" class="ge-form">
        <el-form-item label="名称" required>
          <el-input v-model="form.name" maxlength="100" show-word-limit style="width: 500px" placeholder="请输入视频名称" />
        </el-form-item>
        <el-form-item label="简介">
          <el-input v-model="form.description" type="textarea" :rows="2" maxlength="200" show-word-limit style="width: 500px" />
        </el-form-item>
        <el-form-item label="分类">
          <el-select v-model="form.cid" placeholder="--请选择--" clearable style="width: 240px">
            <el-option v-for="c in editCategories" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="封面图">
          <div class="ge-pick-row">
            <el-button @click="openImgPicker">上传图片</el-button>
            <span class="ge-hint">建议尺寸：350×500像素</span>
          </div>
          <div class="cover-preview">
            <el-image v-if="form.coverimg" :src="form.coverimg" class="cover-preview-img" fit="cover" />
            <div v-else class="cover-preview-empty">暂无图片</div>
          </div>
        </el-form-item>
        <el-form-item label="视频">
          <el-input v-model="form.url" placeholder="请输入视频地址" style="width: 500px" />
          <div class="ge-hint-block">视频链接必须为 mp4 格式源链接，视频大小须在 50MB 以内</div>
          <video v-if="form.url" :src="form.url" controls class="video-preview" @loadedmetadata="onVideoLoaded" />
        </el-form-item>

        <el-form-item label="商品列表">
          <el-table :data="productRows" border size="small" class="goods-table">
            <el-table-column prop="id" label="商品ID" width="120" align="center" />
            <el-table-column prop="name" label="商品名称" min-width="220" show-overflow-tooltip />
            <el-table-column label="操作" width="90" align="center">
              <template #default="{ row }">
                <el-button link type="danger" @click="removeProduct(row.id)">删除</el-button>
              </template>
            </el-table-column>
            <template #empty>暂未关联商品</template>
          </el-table>
          <el-button size="small" style="margin-top: 8px" @click="openGoodsPicker">添加</el-button>
        </el-form-item>

        <el-form-item label="关联链接">
          <el-input v-model="form.linkurl" placeholder="点击视频跳转的链接（可空）" style="width: 500px" />
        </el-form-item>
        <el-form-item label="链接文本">
          <el-input v-model="form.linkname" placeholder="前往查看" style="width: 240px" />
        </el-form-item>
        <el-form-item label="播放数">
          <el-input-number v-model="form.view_num" :min="0" :controls="false" style="width: 200px" />
        </el-form-item>
        <el-form-item label="点赞数">
          <el-input-number v-model="form.zan_num" :min="0" :controls="false" style="width: 200px" />
        </el-form-item>
        <el-form-item label="序号">
          <el-input-number v-model="form.sort" :min="0" :controls="false" style="width: 200px" />
          <span class="ge-hint">用于排序，越大越靠前</span>
        </el-form-item>
        <el-form-item label="评论">
          <el-radio-group v-model="form.comment">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="评论审核">
          <el-radio-group v-model="form.comment_check">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="0">待审核</el-radio>
            <el-radio :value="1">已通过</el-radio>
            <el-radio :value="2">已驳回</el-radio>
          </el-radio-group>
          <el-input
            v-if="form.status === 2"
            v-model="form.reason"
            type="textarea"
            :rows="2"
            maxlength="200"
            placeholder="请填写驳回原因"
            style="width: 360px; margin-top: 8px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 挂商品选择 -->
    <el-dialog v-model="goodsPickerVisible" title="选择商品" width="640px" append-to-body>
      <div class="goods-pick-bar">
        <el-input v-model="goodsKeyword" placeholder="输入商品名称搜索" clearable style="width: 260px" @keyup.enter="searchGoods(goodsKeyword)" @clear="searchGoods('')" />
        <el-button type="primary" :loading="goodsLoading" @click="searchGoods(goodsKeyword)">搜索</el-button>
      </div>
      <el-table
        v-loading="goodsLoading"
        :data="goodsCandidates"
        border
        size="small"
        max-height="360"
        @selection-change="(rows: GoodsItem[]) => (goodsPicked = rows)"
      >
        <el-table-column type="selection" width="46" align="center" />
        <el-table-column prop="id" label="商品ID" width="90" align="center" />
        <el-table-column prop="name" label="商品名称" min-width="220" show-overflow-tooltip />
      </el-table>
      <template #footer>
        <el-button @click="goodsPickerVisible = false">取消</el-button>
        <el-button type="primary" @click="addPickedGoods">添加</el-button>
      </template>
    </el-dialog>

    <!-- 查看评论 -->
    <el-dialog v-model="commentVisible" :title="commentTitle" width="560px" append-to-body>
      <el-table v-loading="commentLoading" :data="commentList" border size="small" max-height="420">
        <el-table-column label="评论人" width="110">
          <template #default="{ row }">{{ row.nickname || '会员' }}</template>
        </el-table-column>
        <el-table-column prop="content" label="内容" min-width="240" show-overflow-tooltip />
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">{{ row.status === 1 ? '显示' : '待审' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="时间" width="150">
          <template #default="{ row }">{{ row.createtime ? formatTime(Number(row.createtime)) : '-' }}</template>
        </el-table-column>
        <template #empty>暂无评论</template>
      </el-table>
    </el-dialog>

    <!-- 查看链接 -->
    <el-dialog v-model="linkVisible" title="视频链接" width="520px" append-to-body>
      <el-input v-model="linkUrl" readonly>
        <template #append>
          <el-button @click="copyLink">复制</el-button>
        </template>
      </el-input>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.sv-page {
  /* 主区域四边边距由 AdminLayout .admin-main 统一 3px，页面不再自带 padding */
}
/* 工具栏：左操作 / 右搜索，强制一行；主区域块间距 3px */
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
  align-items: center;
  gap: 8px;
  flex: 0 0 auto;
}
.toolbar-left .el-button {
  margin: 0;
}
.toolbar-search {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  justify-content: flex-end;
  flex-wrap: nowrap;
  min-width: 0;
  margin: 0;
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
  padding: 8px 14px;
}
.search-icon-btn svg {
  display: block;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
.text-muted {
  color: #c0c4cc;
  font-size: 12px;
}
/* 发布人 */
.publisher {
  display: flex;
  align-items: center;
  gap: 6px;
}
.publisher-img {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  flex-shrink: 0;
}
/* 竖版封面 */
.cover-img {
  width: 46px;
  height: 66px;
  border-radius: 4px;
  cursor: pointer;
}
/* 行操作竖排（对标点大） */
.row-ops {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  line-height: 1.5;
}
.row-ops .el-button {
  margin: 0;
  height: auto;
  min-height: 0;
  padding: 0;
  font-size: 12px;
}
/* 整页编辑 */
.goods-edit {
  background: #fff;
  border-radius: 6px;
  padding: 16px 20px;
}
.ge-head {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin-bottom: 12px;
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
.ge-form :deep(.el-form-item) {
  margin-bottom: 16px;
}
.ge-hint {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
}
.ge-hint-block {
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
  margin-top: 4px;
}
.ge-pick-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}
.cover-preview {
  width: 140px;
  height: 200px;
}
.cover-preview-img {
  width: 140px;
  height: 200px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}
.cover-preview-empty {
  width: 140px;
  height: 200px;
  border: 1px dashed #dcdfe6;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 12px;
  background: #fafafa;
}
.video-preview {
  width: 180px;
  max-height: 320px;
  margin-top: 8px;
  border-radius: 4px;
  background: #000;
}
.goods-table {
  width: 520px;
}
.goods-pick-bar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}
</style>
