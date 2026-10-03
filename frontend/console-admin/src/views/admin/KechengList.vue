<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteKechengCourse,
  getKechengCategoryList,
  getKechengCourseDetail,
  getKechengCourseList,
  getMemberLevelOptions,
  saveKechengCourse,
  setKechengCourseStatus,
} from '../../api/kecheng'
import type {
  KechengCategoryItem,
  KechengCourseItem,
  MemberLevelOption,
} from '../../api/kecheng'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import LinkAddressModal from '../../components/LinkAddressModal.vue'
import GoodsDetailEditor from '../../components/GoodsDetailEditor.vue'
import type { DesignerModule } from '../../api/designer'
import { defaultModule } from './designerModules'
import KechengChapter from './KechengChapter.vue'
import KechengTiku from './KechengTiku.vue'
import KechengRecord from './KechengRecord.vue'

const kctypeText: Record<number, string> = { 1: '图文', 2: '音频', 3: '视频', 4: '综合' }
/** 点券派生口径：1元 = 100点券（截图内价格×100，如11→1100、6.6→660） */
const pointOf = (v: number | string | undefined) => Math.round(Number(v ?? 0) * 100)

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<KechengCourseItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(20)
const keyword = ref('')
const filterStatus = ref<string | number>('')
const filterCid = ref<number | undefined>(undefined)
const checkedIds = ref<Set<number>>(new Set())

const categories = ref<KechengCategoryItem[]>([])
const memberLevels = ref<MemberLevelOption[]>([])

/** 价格会员价表用全部等级；分销展开用具有分销权限(can_agent>0)的等级 */
const priceLevels = computed(() => memberLevels.value)
const agentLevels = computed(() => memberLevels.value.filter((l) => (l.can_agent || 0) > 0))

async function fetchOptions() {
  try {
    const [catData, lvData] = await Promise.all([getKechengCategoryList(), getMemberLevelOptions()])
    categories.value = catData.list || []
    memberLevels.value = Array.isArray(lvData) ? lvData : lvData.list || []
  } catch {
    /* 拦截器统一提示 */
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getKechengCourseList({
      page: page.value,
      pagesize: pagesize.value,
      keyword: keyword.value,
      status: filterStatus.value,
      cid: filterCid.value,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    /* 拦截器统一提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  page.value = 1
  fetchList()
}

function categoryNames(cid: string) {
  const ids = String(cid || '')
    .split(',')
    .filter((v) => v && v !== '0')
    .map(Number)
  return (
    ids
      .map((id) => categories.value.find((v) => v.id === id)?.name || '')
      .filter(Boolean)
      .join(' / ') || '-'
  )
}

function onCheckChange(row: KechengCourseItem, v: boolean) {
  if (v) checkedIds.value.add(row.id)
  else checkedIds.value.delete(row.id)
}

// ============ 上下架 / 删除 ============
async function rowStatus(row: KechengCourseItem, st: number) {
  await setKechengCourseStatus(row.id, st)
  row.status = st
}

function handleToggleStatus(row: KechengCourseItem) {
  const next = row.status === 1 ? 0 : 1
  rowStatus(row, next)
    .then(() => ElMessage.success(next === 1 ? '已上架' : '已下架'))
    .catch(() => {})
}

async function batchStatus(st: number) {
  if (!checkedIds.value.size) return
  try {
    for (const id of Array.from(checkedIds.value)) {
      await setKechengCourseStatus(id, st)
    }
    checkedIds.value.clear()
    ElMessage.success(st === 1 ? '已上架' : '已下架')
    fetchList()
  } catch {
    /* 拦截器统一提示 */
  }
}

function handleDelete(row: KechengCourseItem) {
  ElMessageBox.confirm(`确定要删除课程「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteKechengCourse(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

function handleBatchDelete() {
  if (!checkedIds.value.size) return
  ElMessageBox.confirm(`确定要删除选中的 ${checkedIds.value.size} 门课程吗？`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        for (const id of Array.from(checkedIds.value)) {
          await deleteKechengCourse(id)
        }
        checkedIds.value.clear()
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

// ============ 查看链接 ============
const linkVisible = ref(false)
const linkPath = ref('')
function openLink(row: KechengCourseItem) {
  linkPath.value = `/activity/kecheng/product?id=${row.id}`
  linkVisible.value = true
}

// ============ 嵌入：章节 / 题库 / 答题记录 ============
const embedded = ref<'' | 'chapter' | 'tiku' | 'record'>('')
const embeddedProid = ref<number>(0)
const embeddedRowName = ref('')
const embeddedTitle = computed(() => {
  const map: Record<string, string> = { chapter: '章节', tiku: '题库', record: '答题记录' }
  return `${map[embedded.value]} - ${embeddedRowName.value}`
})

function openEmbedded(kind: 'chapter' | 'tiku' | 'record', row: KechengCourseItem) {
  embedded.value = kind
  embeddedProid.value = row.id
  embeddedRowName.value = row.name
}
function closeEmbedded() {
  embedded.value = ''
}

// ============ 增改平铺 ============
const editing = ref(false)
const editId = ref(0)
const activeTab = ref<'info' | 'detail'>('info')
const submitting = ref(false)

interface LevelCommission {
  commission1: number
  commission2: number
  commission3: number
}

const form = reactive({
  name: '',
  kctype: 1,
  pic: '',
  pics: '',
  price: 0,
  market_price: 0,
  lvprice: 0,
  cid: undefined as number | undefined,
  join_num: 0,
  isdt: 1,
  dtnum: 10,
  sxdate: 60,
  hgscore: 60,
  commissionset: 0,
  commissiondata1: {} as Record<string, LevelCommission>,
  commissiondata2: {} as Record<string, LevelCommission>,
  commissiondata3: {} as Record<string, LevelCommission>,
  lvprice_data: {} as Record<string, { money_price: number }>,
  sort: 0,
  status: 1,
  detailModules: [defaultModule('richtext')] as DesignerModule[],
})

function resetForm() {
  form.name = ''
  form.kctype = 1
  form.pic = ''
  form.pics = ''
  form.price = 0
  form.market_price = 0
  form.lvprice = 0
  form.cid = undefined
  form.join_num = 0
  form.isdt = 1
  form.dtnum = 10
  form.sxdate = 60
  form.hgscore = 60
  form.commissionset = 0
  form.commissiondata1 = {}
  form.commissiondata2 = {}
  form.commissiondata3 = {}
  form.lvprice_data = {}
  form.sort = 0
  form.status = 1
  form.detailModules = [defaultModule('richtext')]
}

function openAdd() {
  resetForm()
  editId.value = 0
  activeTab.value = 'info'
  editing.value = true
}

/** detail 列（模块数组 JSON）还原为 DIY 模块数组；兼容旧 HTML 包裹成富文本模块 */
function parseDetailModules(raw: unknown): DesignerModule[] {
  const str = typeof raw === 'string' ? raw.trim() : ''
  if (str) {
    try {
      const parsed = JSON.parse(str)
      if (Array.isArray(parsed)) return parsed as DesignerModule[]
    } catch {
      /* 落到包裹 */
    }
    const m = defaultModule('richtext')
    ;(m as Record<string, unknown>).content = str
    return [m]
  }
  return [defaultModule('richtext')]
}

function normCommission(
  raw: Record<string, { commission1?: number | string; commission2?: number | string; commission3?: number | string }> | undefined,
): Record<string, LevelCommission> {
  const out: Record<string, LevelCommission> = {}
  for (const [k, v] of Object.entries(raw || {})) {
    out[k] = {
      commission1: Number(v?.commission1 ?? 0),
      commission2: Number(v?.commission2 ?? 0),
      commission3: Number(v?.commission3 ?? 0),
    }
  }
  return out
}

async function openEdit(row: KechengCourseItem) {
  resetForm()
  editId.value = row.id
  activeTab.value = 'info'
  editing.value = true
  try {
    const d = await getKechengCourseDetail(row.id)
    form.name = d.name
    form.kctype = Number(d.kctype) || 1
    form.pic = d.pic || ''
    form.pics = d.pics || ''
    form.price = Number(d.price) || 0
    form.market_price = Number(d.market_price) || 0
    form.lvprice = Number(d.lvprice) || 0
    const cidNum = String(d.cid || '')
      .split(',')
      .filter((v) => v && v !== '0')
      .map(Number)[0]
    form.cid = cidNum || undefined
    form.join_num = Number(d.join_num) || 0
    form.isdt = Number(d.isdt) === 0 ? 0 : 1
    form.dtnum = Number(d.dtnum) || 10
    form.sxdate = Number(d.sxdate) || 60
    form.hgscore = Number(d.hgscore) || 60
    form.commissionset = Number(d.commissionset) || 0
    form.commissiondata1 = normCommission(d.commissiondata1)
    form.commissiondata2 = normCommission(d.commissiondata2)
    form.commissiondata3 = normCommission(d.commissiondata3)
    const lv: Record<string, { money_price: number }> = {}
    for (const [k, v] of Object.entries(d.lvprice_data || {})) {
      lv[k] = { money_price: Number(v?.money_price ?? 0) }
    }
    form.lvprice_data = lv
    form.sort = Number(d.sort) || 0
    form.status = Number(d.status) === 0 ? 0 : 1
    form.detailModules = parseDetailModules(d.detail)
  } catch {
    /* 拦截器统一提示 */
  }
}

function commissionRow(lvId: number, key: 'commissiondata1' | 'commissiondata2' | 'commissiondata3') {
  const k = String(lvId)
  if (!form[key][k]) form[key][k] = { commission1: 0, commission2: 0, commission3: 0 }
  return form[key][k]
}

function lvpriceRow(lvId: number) {
  const k = String(lvId)
  if (!form.lvprice_data[k]) form.lvprice_data[k] = { money_price: 0 }
  return form.lvprice_data[k]
}

/** 分销展开单位：1=比例% 2=金额元 3=积分 */
const commissionUnit = computed(() => (form.commissionset === 1 ? '%' : form.commissionset === 2 ? '元' : '积分'))

// 选图
const pickerVisible = ref(false)
const pickerKind = ref<'main' | 'gallery'>('main')
function openPicker(kind: 'main' | 'gallery') {
  pickerKind.value = kind
  pickerVisible.value = true
}
function onPickImage(urls: string[]) {
  if (pickerKind.value === 'main') form.pic = urls[0] ?? ''
  else form.pics = urls.join(',')
  pickerVisible.value = false
}

async function handleSubmit() {
  if (!form.name.trim()) {
    ElMessage.warning('请输入课程名称')
    return
  }
  submitting.value = true
  try {
    await saveKechengCourse({
      id: editId.value || undefined,
      name: form.name.trim(),
      kctype: form.kctype,
      pic: form.pic,
      pics: form.pics,
      price: form.price,
      market_price: form.market_price,
      lvprice: form.lvprice,
      lvprice_data: form.lvprice_data,
      cid: String(form.cid || 0),
      join_num: form.join_num,
      isdt: form.isdt,
      dtnum: form.dtnum,
      sxdate: form.sxdate,
      hgscore: form.hgscore,
      commissionset: form.commissionset,
      commissiondata1: form.commissiondata1,
      commissiondata2: form.commissiondata2,
      commissiondata3: form.commissiondata3,
      sort: form.sort,
      status: form.status,
      detail: JSON.stringify(form.detailModules || []),
    })
    ElMessage.success('保存成功')
    editing.value = false
    fetchList()
  } catch {
    /* 拦截器统一提示 */
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  fetchOptions()
  fetchList()
})
</script>

<template>
  <div class="page">
    <!-- ============ 列表态 ============ -->
    <template v-if="!editing && !embedded">
      <div class="page-head"><span class="page-title">课程列表</span></div>

      <div class="toolbar">
        <el-button type="primary" @click="openAdd">添加</el-button>
        <el-button type="danger" :disabled="!checkedIds.size" @click="handleBatchDelete">删除</el-button>
        <el-button type="success" :disabled="!checkedIds.size" @click="batchStatus(1)">上架</el-button>
        <el-button type="warning" :disabled="!checkedIds.size" @click="batchStatus(0)">下架</el-button>

        <div class="search-bar">
          <el-select v-model="filterCid" placeholder="课程分类" class="search-select" clearable @change="handleSearch">
            <el-option label="全部" :value="undefined" />
            <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
          <el-input v-model="keyword" placeholder="课程名称" class="search-input" @keyup.enter="handleSearch" />
          <el-select v-model="filterStatus" placeholder="状态" class="search-select" @change="handleSearch">
            <el-option label="全部" value="" />
            <el-option label="已上架" :value="1" />
            <el-option label="未上架" :value="0" />
          </el-select>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="tableData" border size="small" class="data-table">
        <el-table-column width="42">
          <template #header>
            <el-checkbox
              :model-value="tableData.length > 0 && tableData.every((r) => checkedIds.has(r.id))"
              @change="(v: boolean) => (v ? tableData.forEach((r) => checkedIds.add(r.id)) : checkedIds.clear())"
            />
          </template>
          <template #default="{ row }">
            <el-checkbox
              :model-value="checkedIds.has(row.id)"
              @change="(v: boolean) => onCheckChange(row, v)"
            />
          </template>
        </el-table-column>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="所属分类" width="110">
          <template #default="{ row }">{{ categoryNames(row.cid) }}</template>
        </el-table-column>
        <el-table-column label="课程信息" min-width="220">
          <template #default="{ row }">
            <div class="course-cell">
              <img v-if="row.pic" :src="row.pic" class="course-thumb" />
              <div class="course-meta">
                <div class="course-name">{{ row.name }}</div>
                <div class="course-sub">{{ kctypeText[row.kctype] }} · 价格¥{{ Number(row.price) }} · 点券{{ pointOf(row.price) }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="chapter_count" label="章节数" width="70" />
        <el-table-column label="是否答题" width="80">
          <template #default="{ row }">{{ Number(row.isdt) === 1 ? '是' : '否' }}</template>
        </el-table-column>
        <el-table-column label="课程类型" width="80">
          <template #default="{ row }">{{ kctypeText[row.kctype] || '-' }}</template>
        </el-table-column>
        <el-table-column prop="join_num" label="参与人数" width="80" />
        <el-table-column prop="sort" label="序号" width="70" />
        <el-table-column label="创建时间" width="110">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <span v-if="row.status === 1" class="st-on">已上架</span>
            <span v-else class="st-off">未上架</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" min-width="300">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openLink(row)">查看链接</el-button>
            <el-button link type="primary" size="small" @click="openEmbedded('chapter', row)">章节</el-button>
            <el-button link type="primary" size="small" @click="openEmbedded('tiku', row)">题库</el-button>
            <el-button link type="primary" size="small" @click="openEmbedded('record', row)">答题记录</el-button>
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            <el-button link type="warning" size="small" @click="handleToggleStatus(row)">
              {{ row.status === 1 ? '下架' : '上架' }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-model:current-page="page"
        v-model:page-size="pagesize"
        :total="total"
        layout="prev, pager, next, jumper, total"
        class="pager"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </template>

    <!-- ============ 嵌入态：章节 / 题库 / 答题记录（左侧菜单仍停课程列表） ============ -->
    <template v-else-if="embedded">
      <div class="embedded-head">
        <el-button size="small" @click="closeEmbedded">← 返回课程列表</el-button>
        <span class="embedded-title">{{ embeddedTitle }}</span>
      </div>
      <KechengChapter v-if="embedded === 'chapter'" :embedded-proid="embeddedProid" @back="closeEmbedded" />
      <KechengTiku v-else-if="embedded === 'tiku'" :embedded-proid="embeddedProid" @back="closeEmbedded" />
      <KechengRecord v-else :embedded-proid="embeddedProid" @back="closeEmbedded" />
    </template>

    <!-- ============ 增改平铺面板 ============ -->
    <template v-else>
      <div class="goods-edit">
        <div class="ge-head">
          <span class="ge-title">{{ editId ? '编辑课程内容' : '添加课程内容' }}</span>
          <button class="ge-close" @click="editing = false">×</button>
        </div>

        <div class="tab-bar">
          <span :class="['tab', { active: activeTab === 'info' }]" @click="activeTab = 'info'">课程信息</span>
          <span :class="['tab', { active: activeTab === 'detail' }]" @click="activeTab = 'detail'">课程详情</span>
        </div>

        <!-- 课程信息 -->
        <div v-if="activeTab === 'info'" class="ge-body">
          <div class="form-row">
            <label class="form-label"><span class="req">*</span>课程名称：</label>
            <el-input v-model="form.name" class="form-control" placeholder="请输入课程名称" />
          </div>

          <div class="form-row">
            <label class="form-label">课程类型：</label>
            <el-radio-group v-model="form.kctype">
              <el-radio :value="1">图文</el-radio>
              <el-radio :value="2">音频</el-radio>
              <el-radio :value="3">视频</el-radio>
              <el-radio :value="4">综合</el-radio>
            </el-radio-group>
          </div>

          <div class="form-row">
            <label class="form-label">课程主图：</label>
            <div>
              <el-button size="small" @click="openPicker('main')">上传图片</el-button>
              <span class="hint">建议尺寸：640x640像素</span>
              <div v-if="form.pic" class="img-preview"><img :src="form.pic" /></div>
            </div>
          </div>

          <div class="form-row">
            <label class="form-label">课程图片：</label>
            <div>
              <el-button size="small" @click="openPicker('gallery')">批量上传</el-button>
              <span class="hint">建议尺寸：640x640像素</span>
              <div v-if="form.pics" class="img-row">
                <img v-for="(p, i) in form.pics.split(',').filter(Boolean)" :key="i" :src="p" class="img-item" />
              </div>
            </div>
          </div>

          <!-- 课程价格 -->
          <div class="form-row">
            <label class="form-label">课程价格：</label>
            <div class="price-block">
              <el-button size="small" @click="form.lvprice = form.lvprice === 1 ? 0 : 1">
                {{ form.lvprice === 1 ? '关闭会员价' : '开启会员价' }}
              </el-button>

              <!-- 关闭会员价：销售价 + 划线价 -->
              <table v-if="form.lvprice === 0" class="price-table">
                <thead>
                  <tr><th>销售价(元)</th><th>划线价(元)</th></tr>
                </thead>
                <tbody>
                  <tr>
                    <td>
                      <el-input-number v-model="form.price" :min="0" :precision="2" controls-position="right" size="small" />
                      <div class="point-tip">点券 {{ pointOf(form.price) }}</div>
                    </td>
                    <td>
                      <el-input-number v-model="form.market_price" :min="0" :precision="2" controls-position="right" size="small" />
                      <div class="point-tip">点券 {{ pointOf(form.market_price) }}</div>
                    </td>
                  </tr>
                </tbody>
              </table>

              <!-- 开启会员价：按会员等级定价 + 划线价 -->
              <table v-else class="price-table">
                <thead>
                  <tr>
                    <th>等级</th>
                    <th v-for="lv in priceLevels" :key="lv.id">{{ lv.name }}</th>
                    <th>划线价(元)</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>价格(元)</td>
                    <td v-for="lv in priceLevels" :key="lv.id">
                      <el-input-number v-model="lvpriceRow(lv.id).money_price" :min="0" :precision="2" controls-position="right" size="small" />
                      <div class="point-tip">点券 {{ pointOf(lvpriceRow(lv.id).money_price) }}</div>
                    </td>
                    <td>
                      <el-input-number v-model="form.market_price" :min="0" :precision="2" controls-position="right" size="small" />
                      <div class="point-tip">点券 {{ pointOf(form.market_price) }}</div>
                    </td>
                  </tr>
                </tbody>
              </table>

              <div class="price-note">
                注意：当开启会员价时，不同的会员等级存在价格差；<br />
                虚拟商品微信小程序需要用虚拟支付，兑换比例1人民币=100点券；<br />
                IOS有版本和价格限制，IOS版本需要15以上，微信版本微信客户端升级至8.0.68及以上，价格最低支付金额为1元，若低于1元则以1元价格购买
              </div>
            </div>
          </div>

          <div class="form-row">
            <label class="form-label"><span class="req">*</span>课程分类：</label>
            <el-select v-model="form.cid" class="form-control" placeholder="—请选择—">
              <el-option v-for="c in categories.filter((x) => Number(x.pid) === 0)" :key="c.id" :label="c.name" :value="c.id" />
              <template v-for="c in categories.filter((x) => Number(x.pid) === 0)" :key="'c' + c.id">
                <el-option
                  v-for="ch in categories.filter((x) => Number(x.pid) === Number(c.id))"
                  :key="ch.id"
                  :label="'　' + ch.name"
                  :value="ch.id"
                />
              </template>
            </el-select>
          </div>

          <div class="form-row">
            <label class="form-label">学习人数：</label>
            <el-input-number v-model="form.join_num" :min="0" :precision="0" controls-position="right" />
          </div>

          <div class="form-row">
            <label class="form-label">开启答题：</label>
            <el-radio-group v-model="form.isdt">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
          </div>

          <div v-if="form.isdt === 1" class="form-row">
            <label class="form-label"></label>
            <div class="answer-block">
              <div class="form-row">
                <label class="form-label sm">出题数量：</label>
                <el-input-number v-model="form.dtnum" :min="1" :precision="0" controls-position="right" size="small" />
                <span class="hint">每次答题出题的数量</span>
              </div>
              <div class="form-row">
                <label class="form-label sm">答题时长：</label>
                <el-input-number v-model="form.sxdate" :min="1" :precision="0" controls-position="right" size="small" />
                <span class="hint">分钟</span>
              </div>
              <div class="form-row">
                <label class="form-label sm">合格分数：</label>
                <el-input-number v-model="form.hgscore" :min="1" :max="100" :precision="0" controls-position="right" size="small" />
                <span class="hint">分</span>
              </div>
            </div>
          </div>

          <!-- 分销设置 -->
          <div class="form-row">
            <label class="form-label">分销设置：</label>
            <div class="comm-block">
              <el-radio-group v-model="form.commissionset">
                <el-radio :value="0">按照会员等级</el-radio>
                <el-radio :value="1">单独设置提成比例</el-radio>
                <el-radio :value="2">单独设置提成金额</el-radio>
                <el-radio :value="3">分销送积分</el-radio>
                <el-radio :value="-1">不参与分销</el-radio>
              </el-radio-group>
              <div class="price-note">
                按照会员等级：按照会员等级中设置的提成比例进行提成分配，提成=对应会员等级的提成比例×购买数量<br />
                单独设置提成比例：按照单独设置的提成比例进行提成分配，提成=设置的百分比×购买数量<br />
                单独设置提成金额：按照单独设置的提成金额进行提成分配，提成=提成金额×购买数量<br />
                分销送积分：分销提成不给佣金而是给积分，得到的积分=提成积分×购买数量
              </div>

              <div v-if="form.commissionset === 1 || form.commissionset === 2 || form.commissionset === 3" class="comm-fields">
                <div v-for="lv in agentLevels" :key="lv.id" class="comm-row">
                  <label class="form-label sm">{{ lv.name }}：</label>
                  <div class="comm-inputs">
                    <span class="comm-item">一级({{ commissionUnit }})
                      <el-input-number v-model="commissionRow(lv.id, ('commissiondata' + form.commissionset) as 'commissiondata1' | 'commissiondata2' | 'commissiondata3').commission1" :min="0" :precision="2" controls-position="right" size="small" />
                    </span>
                    <span v-if="(lv.can_agent || 0) >= 2" class="comm-item">二级({{ commissionUnit }})
                      <el-input-number v-model="commissionRow(lv.id, ('commissiondata' + form.commissionset) as 'commissiondata1' | 'commissiondata2' | 'commissiondata3').commission2" :min="0" :precision="2" controls-position="right" size="small" />
                    </span>
                    <span v-if="(lv.can_agent || 0) >= 3" class="comm-item">三级({{ commissionUnit }})
                      <el-input-number v-model="commissionRow(lv.id, ('commissiondata' + form.commissionset) as 'commissiondata1' | 'commissiondata2' | 'commissiondata3').commission3" :min="0" :precision="2" controls-position="right" size="small" />
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="form-row">
            <label class="form-label">序号：</label>
            <el-input-number v-model="form.sort" :min="0" :precision="0" controls-position="right" />
            <span class="hint">用于排序，越大越靠前</span>
          </div>

          <div class="form-row">
            <label class="form-label">状态：</label>
            <el-radio-group v-model="form.status">
              <el-radio :value="1">已上架</el-radio>
              <el-radio :value="0">未上架</el-radio>
            </el-radio-group>
          </div>

          <div class="form-row">
            <label class="form-label"></label>
            <el-button type="primary" @click="activeTab = 'detail'">下一步：编辑课程详情</el-button>
            <el-button type="danger" :loading="submitting" @click="handleSubmit">提交</el-button>
          </div>
        </div>

        <!-- 课程详情（DIY 装修器） -->
        <div v-else class="ge-body">
          <GoodsDetailEditor v-model="form.detailModules" title="课程详情" />
          <div class="form-row detail-btns">
            <el-button type="primary" @click="activeTab = 'info'">上一步：编辑课程信息</el-button>
            <el-button type="danger" :loading="submitting" @click="handleSubmit">提交</el-button>
          </div>
        </div>
      </div>
    </template>

    <ImgIconPicker v-model:visible="pickerVisible" :multi="pickerKind === 'gallery'" @pick="onPickImage" />

    <LinkAddressModal v-model:visible="linkVisible" name="课程" :path="linkPath" />
  </div>
</template>

<style scoped>
.page { padding: 3px; }
.page-head { margin-bottom: 3px; }
.page-title { font-weight: 600; font-size: 15px; }
.toolbar { display: flex; align-items: center; gap: 8px; margin-bottom: 3px; flex-wrap: wrap; }
.search-bar { margin-left: auto; display: flex; gap: 6px; align-items: center; }
.search-input { width: 160px; }
.search-select { width: 130px; }
.data-table :deep(th) { background: #e6e6e6; }
.course-cell { display: flex; gap: 8px; align-items: center; }
.course-thumb { width: 44px; height: 44px; object-fit: cover; border-radius: 3px; }
.course-name { font-size: 13px; }
.course-sub { color: #666; font-size: 12px; margin-top: 2px; }
.st-on { color: #1ab0a0; }
.st-off { color: #909399; }
.pager { margin-top: 8px; justify-content: flex-end; }

.embedded-head { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.embedded-title { font-weight: 600; }

/* 平铺面板 */
.goods-edit { border: 1px solid #e6e6e6; border-radius: 4px; background: #fff; }
.ge-head { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid #eee; }
.ge-title { font-weight: 600; }
.ge-close { border: none; background: transparent; font-size: 22px; cursor: pointer; color: #666; }
.tab-bar { display: flex; gap: 24px; padding: 10px 14px; border-bottom: 1px solid #eee; }
.tab { cursor: pointer; padding-bottom: 6px; color: #333; }
.tab.active { color: #f56c6c; border-bottom: 2px solid #f56c6c; font-weight: 600; }
.ge-body { padding: 16px; }
.form-row { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 14px; }
.form-label { width: 110px; text-align: right; color: #333; flex-shrink: 0; line-height: 32px; }
.form-label.sm { width: 90px; }
.form-control { flex: 0 1 340px; text-align: left; }
.req { color: #f56c6c; margin-right: 2px; }
.hint { color: #999; font-size: 12px; line-height: 32px; }
.img-preview img { width: 80px; height: 80px; object-fit: cover; border: 1px solid #eee; border-radius: 3px; }
.img-row { display: flex; gap: 6px; margin-top: 6px; }
.img-item { width: 56px; height: 56px; object-fit: cover; border: 1px solid #eee; border-radius: 3px; }

.price-block { flex: 1; }
.price-table { border-collapse: collapse; margin-top: 8px; }
.price-table th, .price-table td { border: 1px solid #ebeef5; padding: 6px 10px; font-size: 12px; text-align: center; }
.point-tip { color: #909399; font-size: 11px; margin-top: 2px; }
.price-note { color: #909399; font-size: 12px; margin-top: 10px; line-height: 1.7; }

.answer-block { flex: 1; }
.comm-block { flex: 1; }
.comm-fields { margin-top: 10px; }
.comm-row { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 10px; }
.comm-inputs { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; }
.comm-item { font-size: 12px; color: #333; display: inline-flex; align-items: center; gap: 4px; }
.detail-btns { margin-top: 16px; }
</style>
