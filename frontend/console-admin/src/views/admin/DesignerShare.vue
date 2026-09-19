<script setup lang="ts">
/**
 * 设计 - 分享设置（对照参考口径重做，任务284）
 * - 列表：多选 + 工具条（添加/删除/开启/关闭）+ 检索（页面备注/页面路径/类型/状态）
 * - 列口径：ID / 平台类型 / 所属页面 / 页面备注 / 分享内容（图80+标题加粗+描述）/ 创建时间 / 状态（绿开启红关闭）/ 操作（编辑删除）
 * - 编辑弹窗字段口径：平台类型(radio) / 所属页面(选择链接) / 过滤参数(是否+提示) / 页面备注 / 分享标题(必填)
 *   / 分享图标(上传，建议400×400像素) / 分享描述 / 分享链接(提示不填代表当前页面) / 状态
 * - 参考为列表型视图（无预览区），保存/启停/删除后仅刷新列表不跳转
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { delShare, getShareDetail, getShareList, saveShare, setstShare, type ShareRow } from '../../api/designer'
import LinkPicker from '../../components/LinkPicker.vue'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const PLATFORM_OPTIONS = [
  { value: 'all', label: '全部' },
  { value: 'mp', label: '公众号' },
  { value: 'wx', label: '微信小程序' },
  { value: 'alipay', label: '支付宝' },
  { value: 'baidu', label: '百度' },
  { value: 'toutiao', label: '抖音' },
  { value: 'qq', label: 'QQ' },
  { value: 'h5', label: '手机H5' },
  { value: 'app', label: '手机APP' },
]

function platformName(key: string): string {
  return PLATFORM_OPTIONS.find((p) => p.value === key)?.label || key || '全部'
}

// ============ 列表 ============
const loading = ref(false)
const list = ref<ShareRow[]>([])
const total = ref(0)
const page = ref(1)
const limit = ref(20)
const filter = reactive({ indexurlname: '', indexurl: '', platform: '', status: '' as number | '' })
const selected = ref<ShareRow[]>([])

async function fetchList() {
  loading.value = true
  try {
    const data = await getShareList({ page: page.value, limit: limit.value, ...filter })
    list.value = data.list
    total.value = data.count
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

function handleSelection(rows: ShareRow[]) {
  selected.value = rows
}

/** 批量启停（未选择时提示） */
async function handleBatchSetst(st: number) {
  if (!selected.value.length) {
    ElMessage.warning('请选择数据')
    return
  }
  const word = st ? '开启' : '关闭'
  try {
    await ElMessageBox.confirm(`确定要${word}吗?`, '操作确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await setstShare(selected.value.map((r) => r.id), st)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    // 拦截器已提示
  }
}

/** 批量删除（行内删除传单条） */
async function handleDel(ids: number[]) {
  if (!ids.length) {
    ElMessage.warning('请选择数据')
    return
  }
  try {
    await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await delShare(ids)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 拦截器已提示
  }
}

// ============ 编辑表单 ============
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive({
  id: 0,
  platform: 'all',
  indexurl: '',
  is_rootpath: 0,
  indexurlname: '',
  title: '',
  pic: '',
  desc: '',
  url: '',
  status: 1,
})

function resetForm() {
  Object.assign(form, {
    id: 0,
    platform: 'all',
    indexurl: '',
    is_rootpath: 0,
    indexurlname: '',
    title: '',
    pic: '',
    desc: '',
    url: '',
    status: 1,
  })
}

function handleAdd() {
  resetForm()
  dialogVisible.value = true
}

async function handleEdit(row: ShareRow) {
  try {
    const info = await getShareDetail(row.id)
    Object.assign(form, {
      id: info.id,
      platform: info.platform || 'all',
      indexurl: info.indexurl || '',
      is_rootpath: Number(info.is_rootpath ?? 0),
      indexurlname: info.indexurlname || '',
      title: info.title || '',
      pic: info.pic || '',
      desc: info.desc || '',
      url: info.url || '',
      status: Number(info.status ?? 1),
    })
    dialogVisible.value = true
  } catch {
    // 拦截器已提示
  }
}

async function handleSave() {
  if (!form.title.trim()) {
    ElMessage.warning('请填写分享标题')
    return
  }
  saving.value = true
  try {
    await saveShare({ ...form, id: form.id || undefined })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // 拦截器已提示
  } finally {
    saving.value = false
  }
}

// ============ 分享图标选择（任务292 接入 ImgIconPicker） ============
const imgPickerVisible = ref(false)

function onImgPick(urls: string[]) {
  if (urls[0]) form.pic = urls[0]
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="share-header">
          <span>分享设置</span>
          <span class="share-tip">可设置页面分享标题、图片、链接地址等</span>
        </div>
      </template>

      <!-- 工具条 + 检索 -->
      <div class="share-toolbar">
        <div class="share-btns">
          <el-button type="primary" @click="handleAdd">添加</el-button>
          <el-button @click="handleDel(selected.map((r) => r.id))">删除</el-button>
          <el-button @click="handleBatchSetst(1)">开启</el-button>
          <el-button @click="handleBatchSetst(0)">关闭</el-button>
        </div>
        <el-form inline class="share-filter">
          <el-form-item label="页面备注">
            <el-input v-model="filter.indexurlname" placeholder="页面备注" clearable style="width: 150px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item label="页面路径">
            <el-input v-model="filter.indexurl" placeholder="页面路径" clearable style="width: 160px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item label="类型">
            <el-select v-model="filter.platform" clearable placeholder="请选择" style="width: 120px">
              <el-option v-for="p in PLATFORM_OPTIONS" :key="p.value" :label="p.label" :value="p.value" />
            </el-select>
          </el-form-item>
          <el-form-item label="状态">
            <el-select v-model="filter.status" clearable placeholder="全部" style="width: 90px">
              <el-option label="开启" :value="1" />
              <el-option label="关闭" :value="0" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="primary" @click="handleSearch">查询</el-button>
          </el-form-item>
        </el-form>
      </div>

      <el-table v-loading="loading" :data="list" stripe @selection-change="handleSelection">
        <el-table-column type="selection" width="46" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="平台类型" width="110">
          <template #default="{ row }">{{ platformName(row.platform) }}</template>
        </el-table-column>
        <el-table-column prop="indexurl" label="所属页面" min-width="170" show-overflow-tooltip />
        <el-table-column prop="indexurlname" label="页面备注" min-width="120" show-overflow-tooltip />
        <el-table-column label="分享内容" min-width="240">
          <template #default="{ row }">
            <div class="share-content">
              <el-image
                v-if="row.pic"
                :src="row.pic"
                class="share-content-pic"
                fit="cover"
                :preview-src-list="[row.pic]"
                preview-teleported
              />
              <div class="share-content-text">
                <div class="share-content-title">{{ row.title }}</div>
                <div class="share-content-desc">{{ row.desc }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" label="创建时间" width="140" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'st-on' : 'st-off'">{{ row.status === 1 ? '开启' : '关闭' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDel([row.id])">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > limit"
        v-model:current-page="page"
        :page-size="limit"
        :total="total"
        layout="prev, pager, next"
        class="share-pager"
        @current-change="fetchList"
      />
    </el-card>

    <!-- 编辑弹窗（字段顺序与参考一致） -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑分享设置' : '添加分享设置'" width="680px" destroy-on-close>
      <el-form label-width="110px" label-position="left">
        <el-form-item label="平台类型">
          <el-radio-group v-model="form.platform">
            <el-radio v-for="p in PLATFORM_OPTIONS" :key="p.value" :value="p.value">{{ p.label }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="所属页面">
          <LinkPicker v-model="form.indexurl" style="width: 440px" />
        </el-form-item>
        <el-form-item label="过滤参数">
          <el-radio-group v-model="form.is_rootpath">
            <el-radio :value="0">否</el-radio>
            <el-radio :value="1">是</el-radio>
          </el-radio-group>
          <span class="form-tip">过滤参数后，页面将不区分参数，共用分享设置</span>
        </el-form-item>
        <el-form-item label="页面备注">
          <el-input v-model="form.indexurlname" placeholder="便于识别的备注" style="width: 300px" />
        </el-form-item>
        <el-form-item label="分享标题" required>
          <el-input v-model="form.title" placeholder="分享卡片标题" style="width: 360px" />
        </el-form-item>
        <el-form-item label="分享图标">
          <div class="img-field">
            <span class="img-box">
              <img v-if="form.pic" :src="form.pic" />
              <span v-else class="img-empty">—</span>
            </span>
            <el-button size="small" @click="imgPickerVisible = true">选择图片</el-button>
            <el-button v-if="form.pic" link type="danger" size="small" @click="form.pic = ''">清除</el-button>
            <span class="form-tip">建议尺寸：400×400像素</span>
          </div>
          <div v-if="form.pic" class="pic-preview"><img :src="form.pic" /></div>
        </el-form-item>
        <el-form-item label="分享描述">
          <el-input v-model="form.desc" placeholder="分享卡片描述" style="width: 500px" />
        </el-form-item>
        <el-form-item label="分享链接">
          <el-input v-model="form.url" placeholder="选填" style="width: 360px" />
          <div class="form-tip form-tip-block">不填写代表当前页面，设置后发送给朋友或分享到朋友圈，点击后会跳转到此链接地址</div>
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">提交</el-button>
      </template>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="(urls: string[]) => onImgPick(urls)" />
  </div>
</template>

<style scoped>
.share-header {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.share-tip {
  font-size: 12px;
  color: #909399;
}

.share-toolbar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

.share-btns {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}

.share-filter {
  margin-bottom: 0;
}

.share-content {
  display: flex;
  align-items: center;
}

.share-content-pic {
  width: 48px;
  height: 48px;
  border-radius: 4px;
  flex-shrink: 0;
}

.share-content-text {
  margin-left: 10px;
  min-width: 0;
}

.share-content-title {
  font-weight: bold;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.share-content-desc {
  font-size: 12px;
  color: #909399;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.st-on {
  color: #67c23a;
}

.st-off {
  color: #f56c6c;
}

.share-pager {
  margin-top: 14px;
  justify-content: flex-end;
}

.form-tip {
  margin-left: 8px;
  font-size: 12px;
  color: #909399;
}

.form-tip-block {
  margin-left: 0;
  margin-top: 4px;
  line-height: 1.6;
}

.img-field {
  display: flex;
  align-items: center;
  gap: 8px;
}

.img-box {
  width: 40px;
  height: 40px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background: #fafafa;
  flex-shrink: 0;
}

.img-box img {
  max-width: 100%;
  max-height: 100%;
}

.img-empty {
  color: #c0c4cc;
}

.pic-preview {
  width: 100px;
  height: 100px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  margin-top: 8px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fafafa;
}

.pic-preview img {
  max-width: 100%;
  max-height: 100%;
}
</style>
