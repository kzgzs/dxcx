<script setup lang="ts">
import { computed, inject, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  mcDel,
  mcDetail,
  mcList,
  mcSave,
  mcSetRemark,
  mcSync,
  mcSyncStatus,
  type MembercardDetail,
  type MembercardFieldItem,
  type MembercardRow,
} from '../../api/membercard'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// ============ 列表态 ============
const mode = ref<'list' | 'edit'>('list')
const loading = ref(false)
const list = ref<MembercardRow[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(10)
const keyword = ref('')
const syncing = ref(0)

async function loadData() {
  loading.value = true
  try {
    const result = await mcList({ page: page.value, pagesize: pagesize.value, keyword: keyword.value.trim() })
    list.value = result.list || []
    total.value = result.total || 0
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  page.value = 1
  loadData()
}

function closeEdit() {
  mode.value = 'list'
  loadData()
}

function formatTime(ts: number) {
  if (!ts) return '—'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function statusTag(status: number): 'info' | 'success' | 'danger' | 'warning' {
  if (status === 1) return 'success'
  if (status === 2) return 'danger'
  if (status === 3) return 'info'
  return 'warning'
}

// ============ 编辑态 ============
const saving = ref(false)
const form = reactive<Partial<MembercardDetail>>({})

const FIELD_META: { key: string; label: string; fixed: boolean }[] = [
  { key: 'name', label: '姓名', fixed: true },
  { key: 'mobile', label: '手机号', fixed: true },
  { key: 'sex', label: '性别', fixed: true },
  { key: 'birthday', label: '生日', fixed: true },
  { key: 'email', label: '邮箱', fixed: true },
  { key: 'idcard', label: '身份证', fixed: true },
  { key: 'education', label: '学历', fixed: true },
  { key: 'industry', label: '行业', fixed: true },
  { key: 'income', label: '年收入', fixed: true },
  { key: 'habit', label: '爱好', fixed: true },
  { key: 'location', label: '地址', fixed: true },
  { key: 'field1', label: '自定义字段1', fixed: false },
  { key: 'field2', label: '自定义字段2', fixed: false },
  { key: 'field3', label: '自定义字段3', fixed: false },
  { key: 'field4', label: '自定义字段4', fixed: false },
  { key: 'field5', label: '自定义字段5', fixed: false },
]

const CUSTOM_FIELD_OPTIONS = ['积分', '余额', '优惠券', '等级', '自定义1', '自定义2']

const CODE_TYPES = [
  { value: 'CODE_TYPE_TEXT', label: '仅显示卡号' },
  { value: 'CODE_TYPE_BARCODE', label: '卡号+条形码' },
  { value: 'CODE_TYPE_QRCODE', label: '卡号+二维码' },
]

const COLORS = [
  '#63b359', '#2c9f67', '#509fc9', '#5885cf', '#9062c0', '#d09a45', '#e4b138',
  '#ee903c', '#f08500', '#a9d92d', '#dd6549', '#cc463d', '#cf3e36', '#5e6671',
]

const dateRange = ref<[string, string] | null>(null)

const hasBonus = computed(() => Array.isArray(form.custom_field) && form.custom_field.includes('积分'))
const hasCustom1 = computed(() => Array.isArray(form.custom_field) && form.custom_field.includes('自定义1'))
const hasCustom2 = computed(() => Array.isArray(form.custom_field) && form.custom_field.includes('自定义2'))

async function openEdit(id: number) {
  try {
    const info = await mcDetail(id)
    Object.keys(form).forEach((k) => {
      delete form[k as keyof MembercardDetail]
    })
    Object.assign(form, info)
    if (!form.field_list || typeof form.field_list !== 'object') form.field_list = {}
    FIELD_META.forEach((meta) => {
      const item = (form.field_list as Record<string, MembercardFieldItem>)[meta.key]
      if (!item) {
        ;(form.field_list as Record<string, MembercardFieldItem>)[meta.key] = {
          name: meta.fixed ? meta.label : '',
          isshow: 0,
          required: 0,
        }
      }
    })
    if (!Array.isArray(form.custom_field)) form.custom_field = []
    dateRange.value =
      form.date_info_type === 'DATE_TYPE_FIX_TIME_RANGE' && form.date_info_begin_time && form.date_info_end_time
        ? [form.date_info_begin_time, form.date_info_end_time]
        : null
    mode.value = 'edit'
  } catch {
    // 拦截器已提示
  }
}

function handleDateTypeChange(value: string | number | boolean) {
  if (value !== 'DATE_TYPE_FIX_TIME_RANGE') {
    dateRange.value = null
    form.date_info_begin_time = ''
    form.date_info_end_time = ''
  }
}

function handleDateRangeChange(value: [string, string] | null) {
  form.date_info_begin_time = value?.[0] || ''
  form.date_info_end_time = value?.[1] || ''
}

// ============ 选图（ImgIconPicker 统一选图） ============
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

async function handleSave() {
  if (!form.title || !String(form.title).trim()) {
    ElMessage.warning('请填写卡券名')
    return
  }
  if (!form.brand_name || !String(form.brand_name).trim()) {
    ElMessage.warning('请填写商户名字')
    return
  }
  saving.value = true
  try {
    const rs = await mcSave({ ...(form as Partial<MembercardDetail>) })
    form.id = rs.id
    // 本地保存成功后同步微信创建卡券（未配置公众号时后端返回 mock，不阻断）
    try {
      const sync = await mcSync(rs.id)
      if (sync && sync.card_id) {
        ElMessage.success('保存并同步微信成功')
      } else {
        ElMessage.success('保存成功（未配置公众号，暂未同步微信）')
      }
    } catch {
      ElMessage.success('保存成功，同步微信失败，请检查公众号配置')
    }
    mode.value = 'list'
    loadData()
  } catch {
    // 拦截器已提示
  } finally {
    saving.value = false
  }
}

// ============ 行操作 ============
async function handleSync(row: MembercardRow) {
  syncing.value = row.id
  try {
    const rs = await mcSync(row.id)
    ElMessage.success(rs.already ? '该会员卡已同步微信' : '同步成功，请等待微信审核')
    loadData()
  } catch {
    // 拦截器已提示
  } finally {
    syncing.value = 0
  }
}

async function handleSyncStatus(row: MembercardRow) {
  syncing.value = row.id
  try {
    const rs = await mcSyncStatus(row.id)
    ElMessage.success('当前状态：' + rs.status_text)
    loadData()
  } catch {
    // 拦截器已提示
  } finally {
    syncing.value = 0
  }
}

async function handleDelete(row: MembercardRow) {
  try {
    await ElMessageBox.confirm(`确定删除会员卡「${row.title}」吗？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await mcDel([row.id])
    ElMessage.success('删除成功')
    loadData()
  } catch {
    // 拦截器已提示
  }
}

async function handleRemark(row: MembercardRow) {
  let value = row.remark || ''
  try {
    const rs = await ElMessageBox.prompt('设置备注', '提示', {
      inputValue: value,
      confirmButtonText: '保存',
      cancelButtonText: '取消',
    })
    value = rs.value
  } catch {
    return
  }
  try {
    await mcSetRemark(row.id, value)
    ElMessage.success('操作成功')
    loadData()
  } catch {
    // 拦截器已提示
  }
}

// 批量选择
const selected = ref<MembercardRow[]>([])
function handleSelectionChange(rows: MembercardRow[]) {
  selected.value = rows
}
async function handleBatchDelete() {
  if (!selected.value.length) {
    ElMessage.warning('请先选择要删除的会员卡')
    return
  }
  try {
    await ElMessageBox.confirm(`确定删除选中的 ${selected.value.length} 张会员卡吗？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await mcDel(selected.value.map((r) => r.id))
    ElMessage.success('删除成功')
    loadData()
  } catch {
    // 拦截器已提示
  }
}

// 开卡链接（ret_url 为微信会员卡开卡落地页）
async function handleOpenCard(row: MembercardRow) {
  if (!row.card_id || !row.ret_url) {
    ElMessage.warning('该会员卡尚未同步微信，暂无开卡链接')
    return
  }
  try {
    await navigator.clipboard.writeText(row.ret_url)
    ElMessage.success('开卡链接已复制：' + row.ret_url)
  } catch {
    window.open(row.ret_url, '_blank')
  }
}

// 跳转领取记录（按当前会员卡过滤）
const navigateByKey = inject<(key: string) => boolean>('adminNavigateByKey', () => false)
function handleViewRecord(row: MembercardRow) {
  sessionStorage.setItem('mc_record_card_pk', String(row.id))
  if (!navigateByKey('membercard-record')) {
    ElMessage.info('请从左侧菜单「微信会员卡-领取记录」查看，已按该卡过滤')
  }
}

onMounted(loadData)
</script>

<template>
  <div class="membercard-page">
    <!-- ============ 列表态 ============ -->
    <div v-if="mode === 'list'" class="page-card">
      <div class="card-title">微信会员卡</div>
      <div class="filter-bar">
        <el-input
          v-model="keyword"
          placeholder="卡券名"
          clearable
          style="width: 200px"
          @keyup.enter="handleSearch"
        />
        <el-button type="primary" @click="handleSearch">查询</el-button>
        <el-button type="danger" plain :disabled="!selected.length" @click="handleBatchDelete">批量删除</el-button>
        <el-button type="success" @click="openEdit(0)">创建会员卡</el-button>
      </div>

      <el-table :data="list" v-loading="loading" border stripe style="width: 100%; margin-top: 12px" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="card_id" label="卡券ID" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">{{ row.card_id || '未同步' }}</template>
        </el-table-column>
        <el-table-column prop="brand_name" label="商户名称" min-width="120" show-overflow-tooltip />
        <el-table-column prop="title" label="会员卡名称" min-width="120" show-overflow-tooltip />
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="statusTag(row.status)">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column prop="membercount" label="领取数量" width="80" align="center" />
        <el-table-column prop="remark" label="备注" min-width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '—' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <div class="op-col">
              <div class="op-line">
                <el-button link type="primary" @click="openEdit(row.id)">编辑</el-button>
                <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
                <el-button link type="primary" @click="handleRemark(row)">备注</el-button>
              </div>
              <div class="op-line">
                <el-button link type="primary" :disabled="!row.card_id" @click="handleOpenCard(row)">开卡链接</el-button>
                <el-button link type="primary" @click="handleViewRecord(row)">领取记录</el-button>
              </div>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pagesize"
          :total="total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="handleSearch"
          @current-change="loadData"
        />
      </div>
    </div>

    <!-- ============ 编辑态：大表单分组 ============ -->
    <div v-else class="page-card">
      <div class="card-title edit-head">
        <div class="edit-title">
          {{ form.id ? '编辑会员卡' : '创建会员卡' }}
          <el-tag v-if="form.card_id" size="small" :type="statusTag(form.status || 0)" style="margin-left: 8px">
            {{ form.status_text || '审核中' }}
          </el-tag>
        </div>
        <el-button link class="close-x" @click="closeEdit">✕</el-button>
      </div>

      <el-form label-width="130px" class="mc-form">
        <!-- 分组一：基础信息 -->
        <div class="section-title">基础信息</div>
        <el-form-item label="会员卡logo" required>
          <div class="upload-line">
            <el-button size="small" @click="openImgPicker((u) => (form.logo_url = u))">{{ form.logo_url ? '更换' : '选择图片' }}</el-button>
            <el-image v-if="form.logo_url" :src="form.logo_url" class="pic-preview" fit="cover" />
          </div>
          <span class="form-tip">商户logo，字数上限建议正方形图片</span>
        </el-form-item>
        <el-form-item label="商户名字" required>
          <el-input v-model="form.brand_name" placeholder="字数上限为12个汉字" style="width: 320px" maxlength="12" show-word-limit />
        </el-form-item>
        <el-form-item label="卡券名" required>
          <el-input v-model="form.title" placeholder="字数上限为9个汉字" style="width: 320px" maxlength="9" show-word-limit />
        </el-form-item>
        <el-form-item label="Code展示类型">
          <el-select v-model="form.code_type" style="width: 260px">
            <el-option v-for="c in CODE_TYPES" :key="c.value" :label="c.label" :value="c.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="卡券使用提醒">
          <el-input v-model="form.notice" placeholder="字数上限为16个汉字" style="width: 320px" maxlength="16" show-word-limit />
        </el-form-item>
        <el-form-item label="卡券使用说明">
          <el-input v-model="form.description" type="textarea" :rows="3" placeholder="字数上限为1024个汉字" style="width: 460px" maxlength="1024" />
        </el-form-item>
        <el-form-item label="客服电话">
          <el-input v-model="form.service_phone" placeholder="选填" style="width: 260px" />
        </el-form-item>
        <el-form-item label="库存">
          <el-input-number v-model="form.quantity" :min="1" :max="100000000" />
        </el-form-item>
        <el-form-item label="有效期">
          <el-radio-group v-model="form.date_info_type" @change="handleDateTypeChange">
            <el-radio value="DATE_TYPE_PERMANENT">永久有效</el-radio>
            <el-radio value="DATE_TYPE_FIX_TIME_RANGE">固定日期区间</el-radio>
            <el-radio value="DATE_TYPE_FIX_TERM">领取后N天有效</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.date_info_type === 'DATE_TYPE_FIX_TIME_RANGE'" label="有效区间">
          <el-date-picker
            v-model="dateRange"
            type="datetimerange"
            value-format="YYYY-MM-DD HH:mm:ss"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            style="width: 340px"
            @change="handleDateRangeChange"
          />
        </el-form-item>
        <template v-if="form.date_info_type === 'DATE_TYPE_FIX_TERM'">
          <el-form-item label="生效延迟（天）">
            <el-input-number v-model="form.date_info_fixed_begin_term" :min="0" :max="365" />
            <span class="form-tip">领取后第N天生效</span>
          </el-form-item>
          <el-form-item label="有效天数">
            <el-input-number v-model="form.date_info_fixed_term" :min="1" :max="365" />
          </el-form-item>
        </template>

        <!-- 分组二：背景样式 -->
        <div class="section-title">背景样式</div>
        <el-form-item label="卡券封面">
          <el-radio-group v-model="form.bg_type">
            <el-radio :value="0">颜色</el-radio>
            <el-radio :value="1">图片</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.bg_type === 0" label="卡券颜色">
          <div class="color-swatches">
            <span
              v-for="c in COLORS"
              :key="c"
              class="swatch"
              :class="{ active: form.color === c }"
              :style="{ background: c }"
              @click="form.color = c"
            />
          </div>
        </el-form-item>
        <el-form-item v-else label="背景图">
          <div class="upload-line">
            <el-button size="small" @click="openImgPicker((u) => (form.background_pic_url = u))">{{ form.background_pic_url ? '更换' : '选择图片' }}</el-button>
            <el-image v-if="form.background_pic_url" :src="form.background_pic_url" class="pic-preview wide" fit="cover" />
          </div>
        </el-form-item>

        <!-- 分组三：权益设置 -->
        <div class="section-title">权益设置</div>
        <el-form-item label="卡面展示项">
          <el-checkbox-group v-model="form.custom_field">
            <el-checkbox v-for="item in CUSTOM_FIELD_OPTIONS" :key="item" :value="item" :label="item" />
          </el-checkbox-group>
          <span class="form-tip">「积分」开启卡面积分能力；「余额/优惠券/等级」使用系统数据</span>
        </el-form-item>
        <el-form-item v-if="hasBonus" label="积分规则">
          <el-input v-model="form.bonus_rules" type="textarea" :rows="2" style="width: 460px" placeholder="如：每消费1元即可获得1积分" />
        </el-form-item>
        <el-form-item label="特权说明">
          <el-input v-model="form.prerogative" type="textarea" :rows="2" style="width: 460px" placeholder="会员卡特权说明" />
        </el-form-item>
        <template v-if="hasCustom1">
          <el-form-item label="自定义1名称">
            <el-input v-model="form.custom_field_customize1_name" style="width: 220px" />
          </el-form-item>
          <el-form-item label="自定义1文案">
            <el-input v-model="form.custom_field_customize1_value" style="width: 220px" placeholder="如：查看" />
          </el-form-item>
          <el-form-item label="自定义1链接">
            <el-input v-model="form.custom_field_customize1_link" style="width: 360px" placeholder="小程序页面路径或外链" />
          </el-form-item>
        </template>
        <template v-if="hasCustom2">
          <el-form-item label="自定义2名称">
            <el-input v-model="form.custom_field_customize2_name" style="width: 220px" />
          </el-form-item>
          <el-form-item label="自定义2文案">
            <el-input v-model="form.custom_field_customize2_value" style="width: 220px" placeholder="如：查看" />
          </el-form-item>
          <el-form-item label="自定义2链接">
            <el-input v-model="form.custom_field_customize2_link" style="width: 360px" placeholder="小程序页面路径或外链" />
          </el-form-item>
        </template>

        <!-- 分组四：入口设置 -->
        <div class="section-title">入口设置</div>
        <el-form-item label="中部按钮名称">
          <el-input v-model="form.center_title" style="width: 220px" placeholder="如：快速买单" />
        </el-form-item>
        <el-form-item label="中部按钮提示语">
          <el-input v-model="form.center_sub_title" style="width: 220px" />
        </el-form-item>
        <el-form-item label="中部按钮链接">
          <el-input v-model="form.center_url" style="width: 360px" placeholder="小程序页面路径或外链" />
        </el-form-item>
        <el-form-item label="自定义入口1名称">
          <el-input v-model="form.custom_url_name" style="width: 220px" placeholder="如：微信商城" />
        </el-form-item>
        <el-form-item label="自定义入口1提示语">
          <el-input v-model="form.custom_url_sub_title" style="width: 220px" />
        </el-form-item>
        <el-form-item label="自定义入口1链接">
          <el-input v-model="form.custom_url" style="width: 360px" placeholder="小程序页面路径或外链" />
        </el-form-item>
        <el-form-item label="自定义入口2名称">
          <el-input v-model="form.custom_cell1_name" style="width: 220px" placeholder="如：个人中心" />
        </el-form-item>
        <el-form-item label="自定义入口2提示语">
          <el-input v-model="form.custom_cell1_tips" style="width: 220px" />
        </el-form-item>
        <el-form-item label="自定义入口2链接">
          <el-input v-model="form.custom_cell1_url" style="width: 360px" placeholder="小程序页面路径或外链" />
        </el-form-item>
        <el-form-item label="自定义入口3名称">
          <el-input v-model="form.promotion_url_name" style="width: 220px" placeholder="如：积分兑换" />
        </el-form-item>
        <el-form-item label="自定义入口3提示语">
          <el-input v-model="form.promotion_url_sub_title" style="width: 220px" />
        </el-form-item>
        <el-form-item label="自定义入口3链接">
          <el-input v-model="form.promotion_url" style="width: 360px" placeholder="小程序页面路径或外链" />
        </el-form-item>

        <!-- 分组五：开卡表单字段 -->
        <div class="section-title">开卡表单字段</div>
        <el-table :data="FIELD_META" border size="small" style="width: 640px">
          <el-table-column label="字段" min-width="160">
            <template #default="{ row }">
              <template v-if="row.fixed">{{ row.label }}</template>
              <el-input
                v-else
                v-model="(form.field_list as Record<string, MembercardFieldItem>)[row.key].name"
                placeholder="自定义字段名称"
                size="small"
              />
            </template>
          </el-table-column>
          <el-table-column label="显示" width="90" align="center">
            <template #default="{ row }">
              <el-switch
                v-model="(form.field_list as Record<string, MembercardFieldItem>)[row.key].isshow"
                :active-value="1"
                :inactive-value="0"
              />
            </template>
          </el-table-column>
          <el-table-column label="必填" width="90" align="center">
            <template #default="{ row }">
              <el-switch
                v-model="(form.field_list as Record<string, MembercardFieldItem>)[row.key].required"
                :active-value="1"
                :inactive-value="0"
                :disabled="(form.field_list as Record<string, MembercardFieldItem>)[row.key].isshow !== 1"
              />
            </template>
          </el-table-column>
        </el-table>
        <div class="form-tip block">勾选「显示」后会员领取时收集该信息；「必填」需先开启显示。</div>

        <el-form-item label="备注">
          <el-input v-model="form.remark" style="width: 360px" placeholder="内部备注，选填" />
        </el-form-item>

        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
          <el-button
            @click="
              mode = 'list';
              loadData()
            "
          >
            返回列表
          </el-button>
        </el-form-item>
      </el-form>
    </div>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.membercard-page {
  padding: 12px;
}
.page-card {
  background: #fff;
  border-radius: 4px;
  padding: 16px 20px;
}
.card-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding-bottom: 12px;
  margin-bottom: 16px;
  border-bottom: 1px solid #ebeef5;
}
.edit-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.close-x {
  font-size: 18px;
  color: #909399;
  padding: 0 6px;
}
.filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
.card-name {
  font-weight: 600;
  color: #303133;
}
.card-sub {
  font-size: 12px;
  color: #909399;
}
.op-col {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.op-line {
  display: flex;
  justify-content: center;
  gap: 6px;
  flex-wrap: nowrap;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
.mc-form {
  max-width: 980px;
}
.section-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  margin: 22px 0 14px;
  padding-left: 8px;
  border-left: 3px solid #409eff;
}
.upload-line {
  display: flex;
  align-items: center;
  gap: 10px;
}
.pic-preview {
  width: 56px;
  height: 56px;
  border-radius: 4px;
  border: 1px solid #ebeef5;
}
.pic-preview.wide {
  width: 120px;
  height: 56px;
}
.color-swatches {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.swatch {
  width: 26px;
  height: 26px;
  border-radius: 4px;
  cursor: pointer;
  border: 2px solid transparent;
}
.swatch.active {
  border-color: #303133;
  box-shadow: 0 0 0 2px #fff inset;
}
.form-tip {
  font-size: 12px;
  color: #909399;
  margin-left: 8px;
}
.form-tip.block {
  display: block;
  margin: 6px 0 14px;
}
</style>
