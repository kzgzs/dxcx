<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  checkShortvideo,
  deleteShortvideo,
  getShortvideoCategoryOptions,
  getShortvideoDetail,
  getShortvideoList,
  saveShortvideo,
  setShortvideoStatus,
} from '../../api/shortvideo'
import type { ShortvideoItem } from '../../api/shortvideo'
import { getGoodsList } from '../../api/goods'
import type { GoodsItem } from '../../api/types'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// 商户过滤视图（bid='gt0'）由 tenantMenus merchantViewOf 注入
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })

// ============ 列表 ============
const loading = ref(false)
const list = ref<ShortvideoItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(10)
const keyword = ref('')
const statusFilter = ref<string>('')
const cidFilter = ref<number | ''>('')
const categoryOptions = ref<Array<{ id: number; name: string }>>([])

async function fetchCategories() {
  try {
    categoryOptions.value = await getShortvideoCategoryOptions()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function fetchList() {
  loading.value = true
  try {
    const res = await getShortvideoList({
      page: page.value,
      pagesize: pagesize.value,
      keyword: keyword.value,
      status: statusFilter.value,
      cid: cidFilter.value || undefined,
      bid: props.bid,
    })
    list.value = res.list
    total.value = res.total
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  page.value = 1
  fetchList()
}

function formatTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  cid: 0,
  name: '',
  url: '',
  video_duration: 0,
  coverimg: '',
  description: '',
  productids: [] as number[],
  linkurl: '',
  linkname: '',
  comment: 1,
  comment_check: 0,
  sort: 0,
  status: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入视频名称', trigger: 'blur' }],
  url: [{ required: true, message: '请提供视频地址', trigger: 'blur' }],
}

// 挂商品远程搜索
const goodsOptions = ref<GoodsItem[]>([])
const goodsLoading = ref(false)

async function searchGoods(query: string) {
  goodsLoading.value = true
  try {
    const res = await getGoodsList({ keyword: query, page: 1, pagesize: 20 })
    goodsOptions.value = res.list
  } catch {
    // 忽略
  } finally {
    goodsLoading.value = false
  }
}

function resetForm() {
  Object.assign(form, {
    id: 0,
    cid: 0,
    name: '',
    url: '',
    video_duration: 0,
    coverimg: '',
    description: '',
    productids: [],
    linkurl: '',
    linkname: '',
    comment: 1,
    comment_check: 0,
    sort: 0,
    status: 1,
  })
}

function openAdd() {
  isEdit.value = false
  resetForm()
  dialogVisible.value = true
}

async function openEdit(row: ShortvideoItem) {
  isEdit.value = true
  resetForm()
  try {
    const data = await getShortvideoDetail(row.id)
    Object.assign(form, {
      id: data.id,
      cid: data.cid,
      name: data.name,
      url: data.url,
      video_duration: data.video_duration,
      coverimg: data.coverimg,
      description: data.description,
      productids: data.productids,
      linkurl: data.linkurl,
      linkname: data.linkname,
      comment: data.comment,
      comment_check: data.comment_check,
      sort: data.sort,
      status: data.status,
    })
    // 已挂商品回显
    if (data.productids.length > 0) {
      const res = await getGoodsList({ page: 1, pagesize: 100 })
      goodsOptions.value = res.list
    }
    dialogVisible.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveShortvideo({ ...form, id: form.id || undefined })
      ElMessage.success('保存成功')
      dialogVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form.coverimg = urls[0]
}

// ============ 审核 / 上下架 / 删除 ============
function handleCheck(row: ShortvideoItem, pass: boolean) {
  if (pass) {
    ElMessageBox.confirm(`确定审核通过视频「${row.name}」吗？`, '审核确认', {
      confirmButtonText: '通过',
      cancelButtonText: '取消',
      type: 'warning',
    })
      .then(async () => {
        try {
          await checkShortvideo(row.id, 1)
          ElMessage.success('审核通过')
          fetchList()
        } catch {
          // 错误提示已由 axios 拦截器统一弹出
        }
      })
      .catch(() => {})
    return
  }

  ElMessageBox.prompt('请填写驳回理由', '驳回视频', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputValidator: (v) => (v && v.trim() ? true : '驳回理由不能为空'),
  })
    .then(async ({ value }) => {
      try {
        await checkShortvideo(row.id, 0, value)
        ElMessage.success('已驳回')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

async function handleToggleStatus(row: ShortvideoItem) {
  const target = row.status === 1 ? 0 : 1
  try {
    await setShortvideoStatus([row.id], target)
    ElMessage.success(target === 1 ? '已上架' : '已下架')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleDelete(row: ShortvideoItem) {
  ElMessageBox.confirm(`确定要删除视频「${row.name}」吗？删除后评论与点赞记录一并清理。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteShortvideo([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// 预览
const previewVisible = ref(false)
const previewUrl = ref('')

function handlePreview(row: ShortvideoItem) {
  previewUrl.value = row.url
  previewVisible.value = true
}

onMounted(() => {
  fetchCategories()
  fetchList()
})
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>短视频列表</span>
          <el-button type="success" @click="openAdd">新增视频</el-button>
        </div>
      </template>

      <div class="filter-bar">
        <el-input
          v-model="keyword"
          placeholder="视频名称/文案"
          clearable
          style="width: 200px"
          @keyup.enter="handleSearch"
          @clear="handleSearch"
        />
        <el-select v-model="cidFilter" placeholder="全部分类" clearable style="width: 160px" @change="handleSearch">
          <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
        </el-select>
        <el-select v-model="statusFilter" placeholder="全部状态" clearable style="width: 140px" @change="handleSearch">
          <el-option label="显示" value="1" />
          <el-option label="待审核/驳回" value="0" />
        </el-select>
        <el-button type="primary" @click="handleSearch">搜索</el-button>
      </div>

      <el-table v-loading="loading" :data="list" stripe row-key="id">
        <el-table-column label="封面" width="90">
          <template #default="{ row }">
            <el-image
              v-if="row.coverimg"
              :src="row.coverimg"
              fit="cover"
              style="width: 56px; height: 56px; border-radius: 4px; cursor: pointer"
              @click="handlePreview(row)"
            />
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
        <el-table-column label="视频" min-width="180">
          <template #default="{ row }">
            <div>{{ row.name }}</div>
            <div class="text-muted">{{ row.description || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="cname" label="分类" width="100" />
        <el-table-column label="归属" width="110">
          <template #default="{ row }">
            <el-tag :type="row.bid > 0 ? 'warning' : 'info'" size="small">{{ row.bname }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="发布人" width="100">
          <template #default="{ row }">{{ row.nickname || '平台' }}</template>
        </el-table-column>
        <el-table-column label="播放/赞/分享" width="120">
          <template #default="{ row }">
            {{ row.view_num }} / {{ row.zan_num }} / {{ row.share_num }}
          </template>
        </el-table-column>
        <el-table-column prop="comment_num" label="评论" width="70" />
        <el-table-column label="挂商品" width="80">
          <template #default="{ row }">{{ row.productids.length }} 个</template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tooltip v-if="row.status === 0 && row.reason" :content="`驳回理由：${row.reason}`">
              <el-tag type="danger" size="small">待审/驳回</el-tag>
            </el-tooltip>
            <el-tag v-else-if="row.status === 1" type="success" size="small">显示</el-tag>
            <el-tag v-else type="info" size="small">待审核</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="添加时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="260" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handlePreview(row)">预览</el-button>
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <template v-if="row.status === 0">
              <el-button link type="success" @click="handleCheck(row, true)">通过</el-button>
              <el-button link type="danger" @click="handleCheck(row, false)">驳回</el-button>
            </template>
            <el-button link :type="row.status === 1 ? 'warning' : 'success'" @click="handleToggleStatus(row)">
              {{ row.status === 1 ? '下架' : '上架' }}
            </el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > 0"
        class="pager"
        v-model:current-page="page"
        v-model:page-size="pagesize"
        :total="total"
        layout="total, prev, pager, next"
        @current-change="fetchList"
      />
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑视频' : '新增视频'" width="640px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px" label-position="left">
        <el-form-item label="视频名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入视频名称" />
        </el-form-item>
        <el-form-item label="所属分类">
          <el-select v-model="form.cid" placeholder="请选择分类" clearable style="width: 100%">
            <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="视频地址" prop="url">
          <el-input v-model="form.url" placeholder="视频文件地址（可走附件上传获取）" />
        </el-form-item>
        <el-form-item label="视频时长">
          <el-input-number v-model="form.video_duration" :min="0" :precision="2" :step="1" />
          <span class="form-tip">单位：秒</span>
        </el-form-item>
        <el-form-item label="封面图">
          <div class="img-pick-row">
            <el-image v-if="form.coverimg" :src="form.coverimg" class="img-pick-thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker">选择图片</el-button>
            <el-button v-if="form.coverimg" size="small" link type="danger" @click="form.coverimg = ''">删除</el-button>
          </div>
        </el-form-item>
        <el-form-item label="视频文案">
          <el-input v-model="form.description" type="textarea" :rows="2" placeholder="视频介绍文案" />
        </el-form-item>
        <el-form-item label="挂商品">
          <el-select
            v-model="form.productids"
            multiple
            filterable
            remote
            clearable
            :remote-method="searchGoods"
            :loading="goodsLoading"
            placeholder="输入商品名搜索"
            style="width: 100%"
          >
            <el-option v-for="g in goodsOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="外链地址">
          <el-input v-model="form.linkurl" placeholder="点击视频跳转的链接（可空）" />
        </el-form-item>
        <el-form-item label="外链名称">
          <el-input v-model="form.linkname" placeholder="外链按钮文案（可空）" />
        </el-form-item>
        <el-form-item label="评论开关">
          <el-switch v-model="form.comment" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="评论审核">
          <el-switch v-model="form.comment_check" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后评论需审核通过才展示</span>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
          <span class="form-tip">0 为待审核/下架</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 视频预览 -->
    <el-dialog v-model="previewVisible" title="视频预览" width="520px">
      <video v-if="previewUrl" :src="previewUrl" controls style="width: 100%; max-height: 420px" />
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
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.filter-bar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}

.pager {
  margin-top: 12px;
  justify-content: flex-end;
}

.text-muted {
  color: #909399;
  font-size: 12px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
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
</style>
