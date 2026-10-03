<script setup lang="ts">
import { computed, inject, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Close } from '@element-plus/icons-vue'
import {
  deleteLottery,
  getLotteryDetail,
  getLotteryList,
  saveLottery,
} from '../../api/lottery'
import type { LotteryItem, LotteryFormField } from '../../api/lottery'
import { gotoLotteryRecord } from '../../api/lotteryNav'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import LinkAddressModal from '../../components/LinkAddressModal.vue'

// ============ 枚举文案 ============
const TYPE_TEXT: Record<string, string> = { dzp: '大转盘', ggk: '刮刮卡' }
const PRIZE_TYPE_OPTIONS = [
  { value: 1, label: '实物奖品' },
  { value: 2, label: '红包' },
  { value: 3, label: '积分' },
  { value: 4, label: '优惠券' },
]
const FORM_TYPE_OPTIONS = ['单行输入', '多行输入', '单项选择', '多项选择', '普通选择', '时间选择', '日期选择', '省市区选择']

// 跨页跳「抽奖记录」（AdminLayout provide）
const navigateByKey = inject<(key: string) => boolean>('adminNavigateByKey', () => false)

function formatTime(ts: number): string {
  if (!ts) return ''
  const d = new Date(ts * 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

function formatTimeFull(ts: number): string {
  return formatTime(ts)
}

// ============ 视图切换（列表 / 编辑平铺） ============
const viewMode = ref<'list' | 'edit'>('list')

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<LotteryItem[]>([])
const total = ref(0)
const selected = ref<LotteryItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  name: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getLotteryList({
      page: query.page,
      pagesize: query.pagesize,
      name: query.name,
    })
    tableData.value = data.list || []
    total.value = data.total
  } catch {
    /* 错误提示由 axios 拦截器统一处理 */
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

function handleDelete(rows: LotteryItem[]) {
  if (!rows.length) {
    ElMessage.warning('请选择数据')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${rows.length} 个活动吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await Promise.all(rows.map((r) => deleteLottery(r.id)))
        ElMessage.success('删除成功')
        selected.value = []
        fetchList()
      } catch {
        /* 已统一弹出 */
      }
    })
    .catch(() => {})
}

// ============ 状态列（对齐点大：未开启红 / 已结束灰 / 进行中绿） ============
function statusView(row: LotteryItem): { text: string; cls: string } {
  if (row.status !== 1) return { text: '未开启', cls: 'st-off' }
  const now = Math.floor(Date.now() / 1000)
  if (row.endtime < now) return { text: '已结束', cls: 'st-ended' }
  if (row.starttime > now) return { text: '未开启', cls: 'st-off' }
  return { text: '进行中', cls: 'st-live' }
}

// ============ 查看链接弹窗（全站统一 LinkAddressModal） ============
const linkVisible = ref(false)
const linkRow = ref<LotteryItem | null>(null)
function h5Path(row: LotteryItem): string {
  return `/activity/${row.type === 'ggk' ? 'ggk' : 'lucky'}/index?id=${row.id}`
}
const linkPath = computed(() => (linkRow.value ? h5Path(linkRow.value) : ''))
const linkName = computed(() => linkRow.value?.name || '')
function openLink(row: LotteryItem) {
  linkRow.value = row
  linkVisible.value = true
}

// ============ 行操作：跳抽奖记录 ============
function gotoRecord(row: LotteryItem) {
  gotoLotteryRecord(row.id)
  navigateByKey('choujiang-record')
}

// ============ 编辑 ============
interface PrizeRow {
  mc: string
  pic: string
  tp: number
  sl: number
  gl: number
  yj: number
}

const editSubmitting = ref(false)

const emptyPrize = (): PrizeRow => ({ mc: '', pic: '', tp: 1, sl: 0, gl: 0, yj: 0 })

const form = reactive({
  id: 0,
  type: 'dzp' as 'dzp' | 'ggk',
  name: '',
  toppic: '',
  bgpic: '',
  poppic: '',
  popnote: '',
  bgcolor: '',
  timerange: [] as string[],
  guize: '',
  pertotal: 3,
  perday: 3,
  shareaddnum: 0,
  sharedaytimes: 0,
  sharetimes: 0,
  joincond: 'all',
  joinscope: 0,
  use_type: 1,
  usescore: 0,
  scene_id: '',
  formcontent: '[]',
  sharetitle: '',
  sharelink: '',
  sharepic: '',
  sharedesc: '',
  status: 1,
})

// 奖项行：下标 0 = 不中奖；1..N = 真实奖项（默认 8 行，可加到 9）
const prizes = ref<PrizeRow[]>([{ ...emptyPrize(), mc: '谢谢参与' }])
// 兑奖表单行
const formFields = ref<LotteryFormField[]>([])

// 展示顺序：真实奖项(奖项1..N)在上，不中奖(j0)固定在最底（对齐参考截图）；
// 数据数组顺序保持 [j0, j1..j9] 不变，保存/回写仍按原下标
const displayPrizes = computed(() => {
  const arr = prizes.value.map((row, orig) => ({ row, orig }))
  arr.sort((a, b) => {
    if (a.orig === 0) return 1
    if (b.orig === 0) return -1
    return a.orig - b.orig
  })
  return arr
})

const DEFAULT_BGCOLOR: Record<string, string> = { dzp: '#F58D40', ggk: '#C40004' }

function defaultGuize(): string {
  return ['1.活动时间以页面展示的起止时间为准；', '2.活动期间，每人每天可参与抽奖3次；', '3.本次活动奖品为奖品一、奖品二、奖品三'].join('\n')
}

function resetForm(type: 'dzp' | 'ggk') {
  form.id = 0
  form.type = type
  form.name = type === 'ggk' ? '刮刮卡活动开始啦' : '幸运大转盘活动开始啦'
  form.toppic = ''
  form.bgpic = ''
  form.poppic = ''
  form.popnote = ''
  form.bgcolor = DEFAULT_BGCOLOR[type]
  form.timerange = []
  form.guize = defaultGuize()
  form.pertotal = 3
  form.perday = 3
  form.shareaddnum = 0
  form.sharedaytimes = 0
  form.sharetimes = 0
  form.joincond = 'all'
  form.joinscope = 0
  form.use_type = 1
  form.usescore = 0
  form.scene_id = ''
  form.formcontent = '[]'
  form.sharetitle = ''
  form.sharelink = ''
  form.sharepic = ''
  form.sharedesc = ''
  form.status = 1
  prizes.value = [{ ...emptyPrize(), mc: '谢谢参与', sl: 55, gl: 55 }]
  for (let i = 1; i <= 8; i++) prizes.value.push({ ...emptyPrize(), mc: `奖品${['一', '二', '三', '四', '五', '六', '七', '八'][i - 1]}` })
  formFields.value = [
    { type: '单行输入', name: '姓名', content: '请输入提示信息', required: true },
    { type: '单行输入', name: '手机号', content: '请输入提示信息', required: true },
  ]
}

function openAdd(type: 'dzp' | 'ggk') {
  resetForm(type)
  viewMode.value = 'edit'
}

async function openEdit(row: LotteryItem) {
  try {
    const data = await getLotteryDetail(row.id)
    form.id = data.id
    form.type = (data.type as 'dzp' | 'ggk') || 'dzp'
    form.name = data.name
    form.toppic = data.toppic || ''
    form.bgpic = data.bgpic || ''
    form.poppic = data.poppic || ''
    form.popnote = data.popnote || ''
    form.bgcolor = data.bgcolor || DEFAULT_BGCOLOR[form.type]
    form.timerange = [formatTimeFull(data.starttime), formatTimeFull(data.endtime)]
    form.guize = data.guize || ''
    form.pertotal = data.pertotal
    form.perday = data.perday
    form.shareaddnum = data.shareaddnum
    form.sharedaytimes = data.sharedaytimes ?? 0
    form.sharetimes = data.sharetimes ?? 0
    form.joincond = data.joincond || 'all'
    form.joinscope = data.joinscope ?? 0
    form.use_type = data.use_type ?? 1
    form.usescore = data.usescore ?? 0
    form.scene_id = data.scene_id || ''
    form.formcontent = data.formcontent || '[]'
    form.sharetitle = data.sharetitle || ''
    form.sharelink = data.sharelink || ''
    form.sharepic = data.sharepic || ''
    form.sharedesc = data.sharedesc || ''
    form.status = data.status

    // 奖项回显：j0 不中奖 + 有内容的真实奖项
    const rows: PrizeRow[] = []
    for (const p of data.prizes || []) {
      if (p.index === 0) {
        rows.push({ mc: p.mc || '谢谢参与', pic: p.pic, tp: 1, sl: p.sl, gl: p.gl, yj: p.yj })
      } else if (p.mc !== '') {
        rows.push({ mc: p.mc, pic: p.pic, tp: p.tp, sl: p.sl, gl: p.gl, yj: p.yj })
      }
    }
    if (!rows.length) rows.push({ ...emptyPrize(), mc: '谢谢参与' })
    prizes.value = rows

    // 兑奖表单回显
    try {
      const parsed = JSON.parse(form.formcontent)
      formFields.value = Array.isArray(parsed) ? parsed : []
    } catch {
      formFields.value = []
    }

    viewMode.value = 'edit'
  } catch {
    /* 已统一弹出 */
  }
}

function cancelEdit() {
  viewMode.value = 'list'
}

// 奖品行增删（不中奖行 index0 不可删）
function addPrizeRow() {
  if (prizes.value.length >= 10) {
    ElMessage.warning('最多支持 9 个奖项')
    return
  }
  prizes.value.push(emptyPrize())
}
function removePrizeRow(index: number) {
  if (index <= 0) return
  prizes.value.splice(index, 1)
}
function clearPrizePic(index: number) {
  prizes.value[index].pic = ''
}

// 兑奖表单增删排序
function addFormField(type: string) {
  formFields.value.push({ type, name: '', content: '请输入提示信息', required: true })
}
function removeFormField(index: number) {
  formFields.value.splice(index, 1)
}
function moveFormField(index: number, dir: -1 | 1) {
  const target = index + dir
  if (target < 0 || target >= formFields.value.length) return
  const tmp = formFields.value[index]
  formFields.value[index] = formFields.value[target]
  formFields.value[target] = tmp
}

// ============ 选图 ============
const imgPickerVisible = ref(false)
let imgPickerSetter: ((url: string) => void) | null = null
function openImgPicker(setter: (url: string) => void) {
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls[0]) imgPickerSetter?.(urls[0])
  imgPickerSetter = null
}

// 参与条件互斥：勾「所有人」则清空其余
function onJoinCondChange(val: string) {
  if (val === 'all') form.joincond = 'all'
}
function toggleCond(opt: string) {
  if (opt === 'all') {
    form.joincond = 'all'
    return
  }
  const parts = form.joincond.split(',').filter(Boolean)
  const idx = parts.indexOf(opt)
  if (idx >= 0) parts.splice(idx, 1)
  else {
    const allIdx = parts.indexOf('all')
    if (allIdx >= 0) parts.splice(allIdx, 1)
    parts.push(opt)
  }
  form.joincond = parts.length ? parts.join(',') : 'all'
}
function condChecked(opt: string): boolean {
  return form.joincond.split(',').filter(Boolean).includes(opt)
}

// ============ 保存 ============
async function handleSubmit() {
  if (!form.timerange[0] || !form.timerange[1]) {
    ElMessage.warning('请选择活动起止时间')
    return
  }
  if (!form.name.trim()) {
    ElMessage.warning('请填写活动名称')
    return
  }

  // 概率合计校验：真实奖项(index>0 且名称非空)概率之和应≈100%
  const real = prizes.value.filter((p, i) => i > 0 && p.mc.trim() !== '')
  const glSum = real.reduce((s, p) => s + (Number(p.gl) || 0), 0)
  if (real.length > 0 && Math.abs(glSum - 100) > 0.01) {
    try {
      await ElMessageBox.confirm(
        `当前各奖项中奖概率合计为 ${glSum.toFixed(2)}%，不等于 100%，是否仍然保存？`,
        '概率合计提示',
        { confirmButtonText: '仍要保存', cancelButtonText: '返回修改', type: 'warning' },
      )
    } catch {
      return
    }
  }
  // 库存校验：真实奖项库存为 0 提示
  const zeroStock = real.find((p) => (Number(p.sl) || 0) <= 0)
  if (zeroStock) {
    try {
      await ElMessageBox.confirm(
        `奖项「${zeroStock.mc}」库存为 0，中将不会出现该奖品，是否仍然保存？`,
        '库存提示',
        { confirmButtonText: '仍要保存', cancelButtonText: '返回修改', type: 'warning' },
      )
    } catch {
      return
    }
  }

  editSubmitting.value = true
  try {
    await saveLottery({
      id: form.id > 0 ? form.id : undefined,
      type: form.type,
      name: form.name,
      toppic: form.toppic,
      bgpic: form.bgpic,
      poppic: form.poppic,
      popnote: form.popnote,
      bgcolor: form.bgcolor,
      starttime: form.timerange[0],
      endtime: form.timerange[1],
      guize: form.guize,
      pertotal: form.pertotal,
      perday: form.perday,
      shareaddnum: form.shareaddnum,
      sharedaytimes: form.sharedaytimes,
      sharetimes: form.sharetimes,
      joincond: form.joincond,
      joinscope: form.joinscope,
      use_type: form.use_type,
      usescore: form.usescore,
      scene_id: form.scene_id,
      formcontent: JSON.stringify(formFields.value),
      sharetitle: form.sharetitle,
      sharelink: form.sharelink,
      sharepic: form.sharepic,
      sharedesc: form.sharedesc,
      status: form.status,
      prizes: prizes.value.map((p) => ({ mc: p.mc, pic: p.pic, tp: p.tp, sl: p.sl, gl: p.gl })),
    })
    ElMessage.success('保存成功')
    viewMode.value = 'list'
    fetchList()
  } catch {
    /* 已统一弹出 */
  } finally {
    editSubmitting.value = false
  }
}

onMounted(fetchList)
</script>

<template>
  <div>
    <!-- ============ 列表视图 ============ -->
    <template v-if="viewMode === 'list'">
      <el-card shadow="never" class="toolbar-card">
        <div class="toolbar-left">
          <el-button type="success" @click="openAdd('dzp')">+大转盘</el-button>
          <el-button type="success" @click="openAdd('ggk')">+刮刮卡</el-button>
          <el-button @click="handleDelete(selected)">删除</el-button>
        </div>
        <el-form inline class="toolbar-search" @submit.prevent>
          <el-form-item label="活动名称">
            <el-input v-model="query.name" placeholder="" clearable style="width: 160px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item>
            <el-button type="success" class="search-icon-btn" @click="handleSearch">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.83 870.13l-179.06-179.13c37.63-50.25 60.23-112.4 60.23-179.64 0-165.02-133.86-298.82-298.95-298.82S229.16 346.34 229.16 511.36s133.8 298.88 298.88 298.88c64.18 0 123.67-20.45 172.93-54.89l180.2 180.27c8.64 8.58 19.88 12.93 31.19 12.93 11.31 0 22.55-4.35 31.13-12.93 8.63-8.63 8.63-39.34 0-55.56zM528.04 710.31c-109.76 0-198.95-89.12-198.95-198.95s89.19-198.95 198.95-198.95 198.95 89.19 198.95 198.95-89.19 198.95-198.95 198.95z" /></svg>
            </el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card shadow="never">
        <el-table v-loading="loading" :data="tableData" border @selection-change="(rows: LotteryItem[]) => (selected = rows)">
          <el-table-column type="selection" width="40" />
          <el-table-column prop="id" label="ID" width="64" />
          <el-table-column label="类型" width="90">
            <template #default="{ row }">{{ TYPE_TEXT[row.type] || row.type }}</template>
          </el-table-column>
          <el-table-column prop="name" label="活动名称" min-width="160" show-overflow-tooltip />
          <el-table-column prop="totalprizes" label="总奖品数" width="90" />
          <el-table-column prop="cjnum" label="已抽奖次数" width="100" />
          <el-table-column prop="zjnum" label="已中奖次数" width="100" />
          <el-table-column label="活动时间" width="240">
            <template #default="{ row }">
              {{ formatTime(row.starttime) }} ~<br />{{ formatTime(row.endtime) }}
            </template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <span :class="['status-tag', statusView(row).cls]">{{ statusView(row).text }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="130">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
                <el-button link type="primary" @click="openLink(row)">查看链接</el-button>
                <el-button link type="primary" @click="gotoRecord(row)">抽奖记录</el-button>
              </div>
            </template>
          </el-table-column>
          <template #empty>暂无数据</template>
        </el-table>

        <el-pagination
          class="pagination"
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          :current-page="query.page"
          :page-size="query.pagesize"
          :page-sizes="[10, 20, 50]"
          @current-change="handlePageChange"
          @size-change="handleSizeChange"
        />
      </el-card>

      <!-- 查看链接小弹窗（全站统一组件） -->
      <LinkAddressModal v-model:visible="linkVisible" :name="linkName" :path="linkPath" />
    </template>

    <!-- ============ 编辑平铺主区域 + 右上X ============ -->
    <div v-else class="edit-wrap">
      <div class="edit-main">
        <!-- 第一部分：活动设置 -->
        <div class="sec-title">活动设置</div>
        <el-form :model="form" label-width="170px" label-position="right" class="edit-form">
          <el-form-item label="活动名称" required>
            <el-input v-model="form.name" style="width: 300px" />
          </el-form-item>
          <el-form-item label="顶部图片">
            <div class="img-line">
              <el-button size="small" @click="openImgPicker((u) => (form.toppic = u))">上传图片</el-button>
            </div>
            <div class="tip">建议尺寸：640x320像素，png格式</div>
            <el-image v-if="form.toppic" :src="form.toppic" fit="cover" class="img-preview" @click="openImgPicker((u) => (form.toppic = u))" />
          </el-form-item>
          <el-form-item label="背景图片">
            <div class="img-line">
              <el-button size="small" @click="openImgPicker((u) => (form.bgpic = u))">上传图片</el-button>
            </div>
            <div class="tip">建议尺寸：750*1340像素</div>
            <el-image v-if="form.bgpic" :src="form.bgpic" fit="cover" class="img-preview" @click="openImgPicker((u) => (form.bgpic = u))" />
          </el-form-item>
          <el-form-item label="弹出图片">
            <div class="img-line">
              <el-button size="small" @click="openImgPicker((u) => (form.poppic = u))">上传图片</el-button>
            </div>
            <div class="tip">建议尺寸：320x320像素</div>
            <el-image v-if="form.poppic" :src="form.poppic" fit="cover" class="img-preview" @click="openImgPicker((u) => (form.poppic = u))" />
          </el-form-item>
          <el-form-item label="弹出图片提示">
            <el-input v-model="form.popnote" style="width: 300px" />
          </el-form-item>
          <el-form-item label="背景色">
            <el-color-picker v-model="form.bgcolor" />
          </el-form-item>
          <el-form-item label="活动规则" required>
            <el-input v-model="form.guize" type="textarea" :rows="4" style="width: 480px" />
          </el-form-item>
          <el-form-item label="开始时间" required>
            <el-date-picker v-model="form.timerange" type="datetimerange" range-separator="至" start-placeholder="开始时间" end-placeholder="结束时间" value-format="YYYY-MM-DD HH:mm:ss" style="width: 440px" />
          </el-form-item>
        </el-form>

        <!-- 第二部分 -->
        <div class="sec-title">参与设置</div>
        <el-form :model="form" label-width="170px" label-position="right" class="edit-form">
          <el-form-item label="每人参与的总次数" required>
            <el-input-number v-model="form.pertotal" :min="0" :controls="false" style="width: 140px" />
            <div class="tip">不含分享增加次数</div>
          </el-form-item>
          <el-form-item label="每人每天可参与次数" required>
            <el-input-number v-model="form.perday" :min="0" :controls="false" style="width: 140px" />
          </el-form-item>
          <el-form-item label="分享增加抽奖次数" required>
            <el-input-number v-model="form.shareaddnum" :min="0" :controls="false" style="width: 140px" />
            <div class="tip">每分享一次增加多少抽奖机会，0为不增加抽奖次数</div>
          </el-form-item>
          <el-form-item label="每天最多分享次数" required>
            <el-input-number v-model="form.sharedaytimes" :min="0" :controls="false" style="width: 140px" />
            <div class="tip">0为不限制，每天分享次数多于此设置不再增加抽奖次数</div>
          </el-form-item>
          <el-form-item label="最多分享总次数" required>
            <el-input-number v-model="form.sharetimes" :min="0" :controls="false" style="width: 140px" />
            <div class="tip">0为不限制，分享次数多于此设置不再增加抽奖次数</div>
          </el-form-item>
          <el-form-item label="参与条件" required>
            <div class="inline-group">
              <el-checkbox :model-value="condChecked('all')" @change="onJoinCondChange('all')">所有人</el-checkbox>
              <el-checkbox :model-value="condChecked('follow')" @change="toggleCond('follow')">关注用户</el-checkbox>
              <el-checkbox :model-value="condChecked('member')" @change="toggleCond('member')">普通会员</el-checkbox>
              <el-checkbox :model-value="condChecked('agent')" @change="toggleCond('agent')">经销商</el-checkbox>
            </div>
          </el-form-item>
          <el-form-item label="参与范围" required>
            <el-radio-group v-model="form.joinscope" class="inline-group">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="消耗类型" required>
            <el-radio-group v-model="form.use_type" class="inline-group">
              <el-radio :value="1">消耗积分</el-radio>
              <el-radio :value="2">消耗余额</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="消耗积分" required>
            <el-input-number v-model="form.usescore" :min="0" :controls="false" style="width: 140px" />
            <div class="tip">每次抽奖需要多少积分，0为免费抽奖</div>
          </el-form-item>
          <el-form-item label="状态" required>
            <el-radio-group v-model="form.status" class="inline-group">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
          </el-form-item>
        </el-form>

        <!-- 第三部分：奖项设置 -->
        <div class="sec-title">奖项设置</div>
        <div class="table-scroll">
          <table class="prize-table">
            <thead>
              <tr>
                <th style="width:70px">奖项</th>
                <th style="width:120px">奖品类型</th>
                <th style="width:160px">奖品名称</th>
                <th style="width:110px">奖品图片</th>
                <th style="width:90px">奖品数量</th>
                <th style="width:100px">中奖概率</th>
                <th style="width:80px">已出奖数</th>
                <th v-if="prizes.length > 9" style="width:50px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in displayPrizes" :key="item.orig">
                <td>{{ item.orig === 0 ? '不中奖' : `奖项${item.orig}` }}</td>
                <td>
                  <el-select v-if="item.orig > 0" v-model="item.row.tp" style="width: 108px">
                    <el-option v-for="o in PRIZE_TYPE_OPTIONS" :key="o.value" :label="o.label" :value="o.value" />
                  </el-select>
                  <span v-else class="tip">—</span>
                </td>
                <td><el-input v-model="item.row.mc" :placeholder="item.orig === 0 ? '如：谢谢参与' : '奖品名称'" /></td>
                <td>
                  <div class="prize-pic-cell">
                    <el-image v-if="item.row.pic" :src="item.row.pic" fit="cover" class="prize-pic" @click="openImgPicker((u) => (item.row.pic = u))" />
                    <el-button v-else size="small" @click="openImgPicker((u) => (item.row.pic = u))">上传</el-button>
                    <span v-if="item.row.pic" class="pic-clear" @click="clearPrizePic(item.orig)">×</span>
                  </div>
                </td>
                <td><el-input-number v-model="item.row.sl" :min="0" :controls="false" style="width: 80px" /></td>
                <td><el-input-number v-model="item.row.gl" :min="0" :max="100" :precision="2" :controls="false" style="width: 80px" /> %</td>
                <td>{{ item.row.yj }}</td>
                <td v-if="prizes.length > 9">
                  <el-button v-if="item.orig > 8" link type="danger" @click="removePrizeRow(item.orig)">删</el-button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <el-button v-if="prizes.length < 10" type="primary" plain size="small" class="add-prize" @click="addPrizeRow">+ 添加奖项</el-button>
        <div class="prize-warn">请依次填写奖项：微信红包金额必须在1.00到200.00元之间，随机红包格式1.5-2.0；奖项为优惠券时请确保设置的优惠券库存充足。</div>
        <div class="formitem-inline">
          <span class="tip-inline-label">红包场景：</span>
          <el-select v-model="form.scene_id" placeholder="默认" style="width: 180px">
            <el-option label="默认" value="" />
            <el-option label="普通红包" value="normal" />
          </el-select>
        </div>

        <!-- 第四部分：兑奖信息设置 + 分享设置 -->
        <div class="sec-title">兑奖信息设置</div>
        <div class="form-add-row">
          <span class="tip-inline-label">添加表单项：</span>
          <el-button v-for="t in FORM_TYPE_OPTIONS" :key="t" size="small" @click="addFormField(t)">{{ t }}</el-button>
        </div>
        <div class="table-scroll">
          <table class="field-table">
            <thead>
              <tr>
                <th style="width:120px">字段类型</th>
                <th style="width:160px">字段名称</th>
                <th>字段内容</th>
                <th style="width:90px">是否必填</th>
                <th style="width:110px">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(f, index) in formFields" :key="index">
                <td><el-input v-model="f.type" /></td>
                <td><el-input v-model="f.name" /></td>
                <td><el-input v-model="f.content" placeholder="请输入提示信息" /></td>
                <td>
                  <el-switch v-model="f.required" active-text="开启" inline-prompt />
                </td>
                <td class="field-op">
                  <el-button link type="danger" @click="removeFormField(index)">✕</el-button>
                  <el-button link @click="moveFormField(index, -1)">↑</el-button>
                  <el-button link @click="moveFormField(index, 1)">↓</el-button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="sec-title">分享设置</div>
        <el-form :model="form" label-width="170px" label-position="right" class="edit-form">
          <el-form-item label="分享标题">
            <el-input v-model="form.sharetitle" style="width: 320px" />
            <div class="tip">默认为活动名称</div>
          </el-form-item>
          <el-form-item label="分享图标">
            <div class="img-line">
              <el-button size="small" @click="openImgPicker((u) => (form.sharepic = u))">上传图片</el-button>
            </div>
            <div class="tip">建议尺寸：400*400像素</div>
            <el-image v-if="form.sharepic" :src="form.sharepic" fit="cover" class="img-preview" @click="openImgPicker((u) => (form.sharepic = u))" />
          </el-form-item>
          <el-form-item label="分享描述">
            <el-input v-model="form.sharedesc" style="width: 320px" />
          </el-form-item>
          <el-form-item label="分享链接">
            <el-input v-model="form.sharelink" style="width: 320px" />
            <div class="tip">默认为活动链接，设置后发送给朋友或分享到朋友圈，点击后会跳转到此链接地址</div>
          </el-form-item>
        </el-form>

        <el-button type="success" :loading="editSubmitting" @click="handleSubmit">提交</el-button>
      </div>

      <!-- 右上X -->
      <div class="edit-close" @click="cancelEdit">
        <el-button :icon="Close" circle title="关闭" />
      </div>

      <!-- 右侧固定手机预览 -->
      <div class="phone-preview">
        <div class="phone-frame">
          <div class="phone-statusbar">wechat &nbsp; {{ form.type === 'ggk' ? '刮刮卡活动开始啦' : '幸运大转盘活动开始开' }}</div>
          <div class="phone-body" :style="{ background: form.bgcolor || '#F58D40' }">
            <img v-if="form.toppic" :src="form.toppic" class="phone-toppic" />
            <div v-else class="phone-toppic placeholder">{{ form.type === 'ggk' ? '刮刮卡' : '幸运大转盘' }}</div>
            <div class="phone-wheel">{{ form.type === 'ggk' ? '刮一刮' : '抽奖' }}</div>
          </div>
        </div>
        <div class="phone-note">*仅供参考，实际效果以手机端为准</div>
      </div>
    </div>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.toolbar-card { margin-bottom: 3px; }
.toolbar-card :deep(.el-card__body) {
  padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: nowrap;
}
.toolbar-left { display: flex; gap: 8px; flex: none; }
.toolbar-left .el-button { margin: 0; }
.toolbar-search { display: flex; align-items: center; justify-content: flex-end; flex: 1; flex-wrap: nowrap; margin: 0; }
.toolbar-search :deep(.el-form-item) { margin-bottom: 0; margin-right: 8px; }
.toolbar-search :deep(.el-form-item__label) { padding-right: 6px; }
.search-icon-btn { padding: 8px 14px; }
.search-icon-btn svg { display: block; }
.pagination { margin-top: 10px; justify-content: flex-end; }

.status-tag { padding: 2px 8px; font-size: 12px; }
.st-off { color: #f56c6c; }
.st-ended { background: #e6e6e6; color: #606266; padding: 2px 8px; border-radius: 3px; }
.st-live { color: #67c23a; }

.row-ops { display: flex; flex-direction: column; gap: 2px; }
.row-ops .el-button { margin: 0; padding: 0; }

/* 编辑平铺 */
.edit-wrap { position: relative; padding: 3px; }
/* 右侧固定手机预览占 220px + 间距，主内容右侧留 260px 避免被遮挡 */
.edit-main { padding: 8px 260px 40px 12px; }
.edit-close { position: absolute; top: 6px; right: 10px; z-index: 5; }
.sec-title { margin: 8px 0 6px; font-weight: 600; font-size: 14px; color: #303133; }
.edit-form :deep(.el-form-item) { margin-bottom: 6px; }
/* 提示文字独立一行，灰色小字，不与控件同行挤压 */
.edit-form :deep(.el-form-item__content) { flex-wrap: wrap; }
.tip { flex-basis: 100%; color: #909399; font-size: 12px; margin: 4px 0 0; line-height: 1.5; }
.tip-inline-label { color: #606266; font-size: 13px; margin-right: 8px; }
.img-line { display: flex; align-items: center; }
.img-preview { width: 90px; height: 90px; margin-top: 6px; border: 1px solid #eee; cursor: pointer; }
/* radio/checkbox 组：不换行错位，允许必要时换行 */
.inline-group { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 12px; }

/* 表格横向滚动（窄屏不撑破布局） */
.table-scroll { width: 100%; overflow-x: auto; margin-bottom: 6px; }
.prize-table, .field-table { min-width: 780px; border-collapse: collapse; }
.prize-table th, .prize-table td, .field-table th, .field-table td { border: 1px solid #ebeef5; padding: 6px 8px; text-align: left; font-size: 13px; }
.prize-table th, .field-table th { background: #e6e6e6; font-weight: normal; }
.prize-pic-cell { position: relative; display: inline-flex; align-items: center; }
.prize-pic { width: 40px; height: 40px; border: 1px solid #eee; cursor: pointer; }
.pic-clear { position: absolute; top: -6px; right: -6px; width: 16px; height: 16px; border-radius: 50%; background: #f56c6c; color: #fff; font-size: 12px; line-height: 16px; cursor: pointer; text-align: center; }
.add-prize { margin: 4px 0; }
.prize-warn { color: #f56c6c; font-size: 12px; margin: 6px 0; line-height: 1.6; }
.formitem-inline { margin: 6px 0; }
.field-op { display: flex; gap: 2px; align-items: center; }
.field-op .el-button { margin: 0; }
.form-add-row { margin-bottom: 8px; }
.form-add-row .el-button { margin: 0 4px 4px 0; }

/* 右侧手机预览：窄屏（<1300px）时隐藏，避免遮挡表单 */
.phone-preview { position: fixed; right: 20px; top: 70px; width: 220px; z-index: 4; }
@media (max-width: 1300px) {
  .phone-preview { display: none; }
  .edit-main { padding-right: 16px; }
}

/* 右侧手机预览 */
.phone-preview { position: fixed; right: 20px; top: 70px; width: 220px; z-index: 4; }
.phone-frame { width: 220px; border: 1px solid #333; border-radius: 18px; overflow: hidden; background: #fff; }
.phone-statusbar { background: #000; color: #fff; font-size: 10px; padding: 3px 8px; white-space: nowrap; overflow: hidden; }
.phone-body { height: 360px; display: flex; flex-direction: column; align-items: center; padding: 10px; box-sizing: border-box; }
.phone-toppic { width: 100%; height: 60px; object-fit: cover; border-radius: 4px; }
.phone-toppic.placeholder { display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; }
.phone-wheel { margin-top: 30px; width: 140px; height: 140px; border-radius: 50%; background: rgba(255,255,255,.5); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; }
.phone-note { text-align: center; font-size: 11px; color: #909399; margin-top: 6px; }

:deep(.el-table__header th) { background: #e6e6e6 !important; }
:deep(.el-table .cell) { text-align: left; }
</style>
