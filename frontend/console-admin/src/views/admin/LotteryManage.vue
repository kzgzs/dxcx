<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { Close } from '@element-plus/icons-vue'
import {
  deleteLottery,
  getLotteryDetail,
  getLotteryList,
  saveLottery,
  setLotteryStatus,
} from '../../api/lottery'
import type { LotteryItem } from '../../api/lottery'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// ============ 枚举文案 ============
const TYPE_TEXT: Record<string, string> = { dzp: '大转盘', ggk: '刮刮卡' }
const PHASE_TEXT: Record<string, string> = { notstart: '未开始', ongoing: '进行中', ended: '已结束' }
const PHASE_TAG: Record<string, string> = { notstart: 'info', ongoing: 'success', ended: 'danger' }

// 时间格式化（含秒，编辑回填 datetimerange 用）
function formatTimeFull(ts: number): string {
  if (!ts) return ''
  const d = new Date(ts * 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

// ============ 视图切换（列表 / 编辑双视图） ============
const viewMode = ref<'list' | 'edit'>('list')

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<LotteryItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  type: '' as '' | 'dzp' | 'ggk',
  status: '' as number | '',
  phase: '',
  name: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getLotteryList({
      page: query.page,
      pagesize: query.pagesize,
      type: query.type,
      status: query.status,
      phase: query.phase,
      name: query.name,
    })
    tableData.value = data.list || []
    total.value = data.total
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.type = ''
  query.status = ''
  query.phase = ''
  query.name = ''
  handleSearch()
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

async function handleStatusChange(row: LotteryItem) {
  try {
    await setLotteryStatus(row.id, row.status)
    ElMessage.success(row.status === 1 ? '已上架' : '已下架')
  } catch {
    row.status = row.status === 1 ? 0 : 1
  }
}

function handleDelete(row: LotteryItem) {
  ElMessageBox.confirm(`确定删除活动「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLottery(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 编辑（铺满主区域） ============
interface PrizeRow {
  mc: string
  pic: string
  tp: number
  sl: number
  yj: number
}

const formRef = ref<FormInstance>()
const editSubmitting = ref(false)

const emptyPrize = (): PrizeRow => ({ mc: '', pic: '', tp: 1, sl: 0, yj: 0 })

const form = reactive({
  id: 0,
  type: 'dzp',
  name: '',
  banner: '',
  timerange: [] as string[],
  guize: '',
  pertotal: 3,
  perday: 3,
  shareaddnum: 0,
  sharedaytimes: 0,
  sharetimes: 0,
  sharetitle: '',
  sharelink: '',
  sharepic: '',
  sharedesc: '',
  status: 1,
})

// 奖项行：下标 0 为「不中奖」固定行，1-9 为真实奖项动态行
const prizes = ref<PrizeRow[]>([emptyPrize()])

const rules: FormRules = {
  type: [{ required: true, message: '请选择活动类型', trigger: 'change' }],
  name: [{ required: true, message: '请填写活动名称', trigger: 'blur' }],
  timerange: [{ required: true, message: '请选择起止时间', trigger: 'change' }],
}

// 概率推导（参考口径）：数量即权重，gl = 数量/总数*100，保留两位
const prizeTotal = computed(() => prizes.value.reduce((sum, p) => sum + (p.sl || 0), 0))

function prizeGl(row: PrizeRow): string {
  if (prizeTotal.value <= 0) return '0.00'
  return ((row.sl / prizeTotal.value) * 100).toFixed(2)
}

function resetForm(type: string) {
  form.id = 0
  form.type = type
  form.name = type === 'ggk' ? '刮刮卡活动开始啦' : '幸运大转盘活动开始啦'
  form.banner = ''
  form.timerange = []
  form.guize = [
    '1.活动时间以页面展示的起止时间为准；',
    '2.活动期间，会员可在活动限定次数内参与抽奖，分享还可额外增加次数；',
    '3.中奖后请在中奖记录中完成领奖，实物奖品到指定地点领取，红包奖品直接发放；',
    '4.奖品数量有限，先到先得，抽完即止；',
    '5.在法律允许范围内，本活动最终解释权归本店所有。',
  ].join('\n')
  form.pertotal = 3
  form.perday = 3
  form.shareaddnum = 0
  form.sharedaytimes = 0
  form.sharetimes = 0
  form.sharetitle = ''
  form.sharelink = ''
  form.sharepic = ''
  form.sharedesc = ''
  form.status = 1
  prizes.value = [{ ...emptyPrize(), mc: '谢谢参与' }, emptyPrize()]
}

function openAdd(type: 'dzp' | 'ggk') {
  resetForm(type)
  viewMode.value = 'edit'
}

async function openEdit(row: LotteryItem) {
  try {
    const data = await getLotteryDetail(row.id)
    form.id = data.id
    form.type = data.type
    form.name = data.name
    form.banner = data.banner
    form.timerange = [formatTimeFull(data.starttime), formatTimeFull(data.endtime)]
    form.guize = data.guize || ''
    form.pertotal = data.pertotal
    form.perday = data.perday
    form.shareaddnum = data.shareaddnum
    form.sharedaytimes = data.sharedaytimes ?? 0
    form.sharetimes = data.sharetimes ?? 0
    form.sharetitle = data.sharetitle || ''
    form.sharelink = data.sharelink || ''
    form.sharepic = data.sharepic || ''
    form.sharedesc = data.sharedesc || ''
    form.status = data.status

    // 回显奖项：j0 固定行 + 有内容的奖项行（尾部空槽丢弃），至少保留一个奖项行
    const rows: PrizeRow[] = []
    for (const p of data.prizes || []) {
      const hasContent = p.mc !== '' || p.sl > 0 || p.yj > 0
      if (p.index === 0) {
        rows.push({ mc: p.mc || '谢谢参与', pic: p.pic, tp: 1, sl: p.sl, yj: p.yj })
      } else if (hasContent) {
        rows.push({ mc: p.mc, pic: p.pic, tp: p.tp || 1, sl: p.sl, yj: p.yj })
      }
    }
    if (rows.length === 0) rows.push({ ...emptyPrize(), mc: '谢谢参与' })
    if (rows.length === 1) rows.push(emptyPrize())
    prizes.value = rows

    viewMode.value = 'edit'
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function cancelEdit() {
  viewMode.value = 'list'
}

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

// ============ 选图（ImgIconPicker 统一选图，banner/分享图/奖项图共用） ============
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

// ============ 保存 ============
async function handleSubmit() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }

  // 奖项校验：至少一个真实奖项配置了名称与数量
  const realRows = prizes.value.slice(1)
  const validRows = realRows.filter((p) => p.mc !== '' || p.sl > 0)
  if (validRows.length === 0) {
    ElMessage.warning('请至少配置一个奖项')
    return
  }

  editSubmitting.value = true
  try {
    await saveLottery({
      id: form.id > 0 ? form.id : undefined,
      type: form.type,
      name: form.name,
      banner: form.banner,
      starttime: form.timerange[0],
      endtime: form.timerange[1],
      guize: form.guize,
      pertotal: form.pertotal,
      perday: form.perday,
      shareaddnum: form.shareaddnum,
      sharedaytimes: form.sharedaytimes,
      sharetimes: form.sharetimes,
      sharetitle: form.sharetitle,
      sharelink: form.sharelink,
      sharepic: form.sharepic,
      sharedesc: form.sharedesc,
      status: form.status,
      prizes: prizes.value.map((p) => ({ mc: p.mc, pic: p.pic, tp: p.tp, sl: p.sl })),
    })
    ElMessage.success('保存成功')
    viewMode.value = 'list'
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
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
        <el-form inline @submit.prevent>
          <el-form-item label="类型">
            <el-select v-model="query.type" placeholder="全部" clearable style="width: 120px">
              <el-option label="大转盘" value="dzp" />
              <el-option label="刮刮卡" value="ggk" />
            </el-select>
          </el-form-item>
          <el-form-item label="状态">
            <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
              <el-option label="上架" :value="1" />
              <el-option label="下架" :value="0" />
            </el-select>
          </el-form-item>
          <el-form-item label="阶段">
            <el-select v-model="query.phase" placeholder="全部" clearable style="width: 110px">
              <el-option label="未开始" value="notstart" />
              <el-option label="进行中" value="ongoing" />
              <el-option label="已结束" value="ended" />
            </el-select>
          </el-form-item>
          <el-form-item label="名称">
            <el-input
              v-model="query.name"
              placeholder="活动名称"
              clearable
              style="width: 180px"
              @keyup.enter="handleSearch"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" @click="handleSearch">搜索</el-button>
            <el-button @click="handleReset">重置</el-button>
          </el-form-item>
          <el-form-item style="float: right">
            <el-button type="success" @click="openAdd('dzp')">新增大转盘</el-button>
            <el-button type="warning" @click="openAdd('ggk')">新增刮刮卡</el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card shadow="never">
        <el-table v-loading="loading" :data="tableData" stripe>
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column label="类型" width="90">
            <template #default="{ row }">
              <el-tag :type="row.type === 'dzp' ? 'success' : 'warning'" size="small">
                {{ TYPE_TEXT[row.type] || row.type }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="name" label="活动名称" min-width="180" show-overflow-tooltip />
          <el-table-column label="活动时间" width="300">
            <template #default="{ row }">
              {{ formatTime(row.starttime) }} ~ {{ formatTime(row.endtime) }}
            </template>
          </el-table-column>
          <el-table-column label="阶段" width="90">
            <template #default="{ row }">
              <el-tag :type="(PHASE_TAG[row.phase] as 'info' | 'success' | 'danger') || 'info'" size="small">
                {{ PHASE_TEXT[row.phase] || row.phase }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="cjnum" label="参与次数" width="90" />
          <el-table-column prop="zjnum" label="中奖数" width="80" />
          <el-table-column label="上架" width="80">
            <template #default="{ row }">
              <el-switch
                v-model="row.status"
                :active-value="1"
                :inactive-value="0"
                @change="handleStatusChange(row)"
              />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="120" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
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
    </template>

    <!-- ============ 编辑视图（铺满主区域） ============ -->
    <el-card v-else shadow="never">
      <template #header>
        <div class="card-header">
          <span class="card-title">
            {{ form.id > 0 ? '编辑抽奖活动' : `新增${TYPE_TEXT[form.type] || ''}活动` }}
          </span>
          <div>
            <el-button type="primary" :loading="editSubmitting" @click="handleSubmit">保存</el-button>
            <el-button :icon="Close" circle title="关闭" @click="cancelEdit" />
          </div>
        </div>
      </template>

      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="130px"
        label-position="right"
        class="compact-form"
      >
        <!-- 基本信息 -->
        <div class="section-title">基本信息</div>
        <el-form-item label="活动类型" prop="type">
          <el-radio-group v-model="form.type">
            <el-radio value="dzp">大转盘</el-radio>
            <el-radio value="ggk">刮刮卡</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="活动名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入活动名称" style="width: 320px" clearable />
        </el-form-item>
        <el-form-item label="活动横幅图">
          <el-image
            v-if="form.banner"
            :src="form.banner"
            fit="cover"
            style="width: 80px; height: 80px; border-radius: 4px; margin-right: 8px"
          />
          <el-button size="small" @click="openImgPicker((u) => (form.banner = u))">{{ form.banner ? '更换' : '选择图片' }}</el-button>
        </el-form-item>
        <el-form-item label="起止时间" prop="timerange">
          <el-date-picker
            v-model="form.timerange"
            type="datetimerange"
            range-separator="至"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            value-format="YYYY-MM-DD HH:mm:ss"
            style="width: 380px"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">上架</el-radio>
            <el-radio :value="0">下架</el-radio>
          </el-radio-group>
        </el-form-item>

        <!-- 活动规则 -->
        <div class="section-title">活动规则</div>
        <el-form-item label="规则文案">
          <el-input
            v-model="form.guize"
            type="textarea"
            :rows="5"
            placeholder="请输入活动规则说明"
            style="width: 640px"
          />
        </el-form-item>

        <!-- 参与次数 -->
        <div class="section-title">参与次数</div>
        <el-form-item label="每人总次数">
          <el-input-number v-model="form.pertotal" :min="0" :step="1" controls-position="right" />
          <span class="form-tip">0 为不限，不含分享增加的次数</span>
        </el-form-item>
        <el-form-item label="每人每天次数">
          <el-input-number v-model="form.perday" :min="0" :step="1" controls-position="right" />
          <span class="form-tip">0 为不限</span>
        </el-form-item>
        <el-form-item label="分享加次数">
          <el-input-number v-model="form.shareaddnum" :min="0" :step="1" controls-position="right" />
          <span class="form-tip">每分享一次增加的抽奖次数，0 为不增加</span>
        </el-form-item>
        <el-form-item label="每天最多分享">
          <el-input-number v-model="form.sharedaytimes" :min="0" :step="1" controls-position="right" />
          <span class="form-tip">0 为不限，超出后分享不再加次数</span>
        </el-form-item>
        <el-form-item label="最多分享总次数">
          <el-input-number v-model="form.sharetimes" :min="0" :step="1" controls-position="right" />
          <span class="form-tip">0 为不限，超出后分享不再加次数</span>
        </el-form-item>

        <!-- 分享四件套 -->
        <div class="section-title">分享设置</div>
        <el-form-item label="分享标题">
          <el-input v-model="form.sharetitle" placeholder="分享卡片标题" style="width: 320px" clearable />
        </el-form-item>
        <el-form-item label="分享链接">
          <el-input v-model="form.sharelink" placeholder="分享落地页链接" style="width: 320px" clearable />
        </el-form-item>
        <el-form-item label="分享图片">
          <el-image
            v-if="form.sharepic"
            :src="form.sharepic"
            fit="cover"
            style="width: 80px; height: 80px; border-radius: 4px; margin-right: 8px"
          />
          <el-button size="small" @click="openImgPicker((u) => (form.sharepic = u))">{{ form.sharepic ? '更换' : '选择图片' }}</el-button>
        </el-form-item>
        <el-form-item label="分享描述">
          <el-input v-model="form.sharedesc" placeholder="分享卡片描述文字" style="width: 320px" clearable />
        </el-form-item>

        <!-- 奖项设置 -->
        <div class="section-title">奖项设置</div>
        <el-form-item label="奖项配置">
          <div class="prize-table">
            <div class="prize-head">
              <span class="col-idx">奖项</span>
              <span class="col-tp">奖品类型</span>
              <span class="col-mc">奖品名称</span>
              <span class="col-pic">奖品图片</span>
              <span class="col-num">奖品数量</span>
              <span class="col-num">中奖概率</span>
              <span class="col-num">已出奖数</span>
              <span class="col-op">操作</span>
            </div>
            <div v-for="(row, index) in prizes" :key="index" class="prize-row">
              <span class="col-idx">{{ index === 0 ? '不中奖' : `奖项${index}` }}</span>
              <span class="col-tp">
                <el-select v-if="index > 0" v-model="row.tp" style="width: 110px">
                  <el-option label="实物奖品" :value="1" />
                  <el-option label="红包" :value="2" />
                </el-select>
                <span v-else class="text-muted">—</span>
              </span>
              <span class="col-mc">
                <el-input v-model="row.mc" :placeholder="index === 0 ? '如：谢谢参与' : '如：一等奖'" clearable />
              </span>
              <span class="col-pic">
                <el-image
                  v-if="row.pic"
                  :src="row.pic"
                  fit="cover"
                  style="width: 60px; height: 60px; border-radius: 4px; cursor: pointer"
                  @click="openImgPicker((u) => (row.pic = u))"
                />
                <el-button v-else size="small" @click="openImgPicker((u) => (row.pic = u))">选择图片</el-button>
              </span>
              <span class="col-num">
                <el-input-number
                  v-model="row.sl"
                  :min="0"
                  :step="1"
                  :controls="false"
                  style="width: 80px"
                />
              </span>
              <span class="col-num">{{ prizeGl(row) }} %</span>
              <span class="col-num">{{ row.yj }}</span>
              <span class="col-op">
                <el-button
                  v-if="index > 0"
                  link
                  type="danger"
                  @click="removePrizeRow(index)"
                >删除</el-button>
                <span v-else class="text-muted">—</span>
              </span>
            </div>
            <el-button
              type="primary"
              plain
              class="add-row"
              :disabled="prizes.length >= 10"
              @click="addPrizeRow"
            >+ 添加奖项</el-button>
            <div class="form-tip prize-tip">
              中奖概率由奖品数量自动推导（数量即权重）；奖品数量为 0 或耗尽后该奖项自动停止出奖
            </div>
          </div>
        </el-form-item>

        <el-form-item label=" ">
          <el-button type="primary" :loading="editSubmitting" @click="handleSubmit">保存</el-button>
          <el-button @click="cancelEdit">关闭</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 12px;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.card-title {
  font-weight: 600;
}

.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.text-muted {
  color: #909399;
}

.section-title {
  margin: 6px 0 10px;
  padding-left: 8px;
  border-left: 3px solid var(--el-color-primary);
  color: #303133;
  font-weight: 600;
  font-size: 14px;
  line-height: 16px;
}

/* 紧凑表单：行距压缩 */
.compact-form :deep(.el-form-item) {
  margin-bottom: 10px;
}

.compact-form :deep(.el-form-item__label) {
  line-height: 32px;
}

/* 奖项配置表格 */
.prize-table {
  width: 100%;
}

.prize-head,
.prize-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.prize-head {
  padding: 2px 0 6px;
  color: #909399;
  font-size: 12px;
}

.prize-row {
  margin-bottom: 6px;
}

.col-idx {
  width: 70px;
  flex: none;
}

.col-tp {
  width: 110px;
  flex: none;
}

.col-mc {
  width: 180px;
  flex: none;
}

.col-pic {
  width: 70px;
  flex: none;
}

.col-num {
  width: 90px;
  flex: none;
  text-align: center;
}

.col-op {
  width: 60px;
  flex: none;
  text-align: center;
}

.add-row {
  margin-top: 2px;
}

.prize-tip {
  margin: 6px 0 0;
}

</style>
