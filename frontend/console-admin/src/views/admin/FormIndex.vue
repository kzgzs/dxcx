<script setup lang="ts">
// 自定义表单（对照点大 canzhao Form 控制器 + form/edit/record/recordedit/chooseform 视图）
// 平铺主区域 + 右上角 X：表单列表 / 表单编辑(基本信息|表单内容) / 数据列表(动态列) / 数据编辑·新增 / 查找会员ID 覆盖层；
// 仅「查看图片」「链接」为小弹窗 el-dialog。
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteForm,
  exportFormRecord,
  formRecordDel,
  formRecordSetst,
  getFormDetail,
  getFormList,
  getFormRecord,
  getFormRecordDetail,
  saveForm,
  saveFormRecord,
} from '../../api/adminform'
import type { FormItem, FormOrderItem } from '../../api/adminform'
import { getMemberList } from '../../api/member'
import type { MemberItem } from '../../api/types'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { areaProvinces } from '../../utils/areaData'

const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
/** 商户视图(bid='gt0')：平台查看商户表单，带「所属商户」列/筛选，不提供添加；平台视图(bid='0')仅自营，可添加 */
const isMerchantView = computed(() => props.bid === 'gt0' || props.bid === 'merchant')

type ViewMode = 'list' | 'edit' | 'record' | 'recordedit' | 'chooseMember'
const viewMode = ref<ViewMode>('list')
const activeTab = ref<'base' | 'content'>('base')

// ============ 表单项数据结构（严格对照 canzhao content JSON：key/val1..val15） ============
interface FormField {
  key: string
  val1: string
  val2: any
  val3: number
  val4: number
  val8: number
  val9: number
  val10: number
  val11: number
  val15: string
}
const TYPE_LABELS: Record<string, string> = {
  input: '单行输入', textarea: '多行输入', radio: '单项选择', checkbox: '多项选择',
  selector: '下拉选择', time: '时间选择', date: '日期选择', year: '年份选择',
  region: '省市区选择', upload: '上传图片', upload_pics: '上传多图',
}
const ADD_BUTTONS = ['input', 'textarea', 'radio', 'checkbox', 'selector', 'time', 'date', 'year', 'region', 'upload', 'upload_pics']
const INPUT_TYPES = [
  { value: 0, label: '文本' }, { value: 1, label: '数字' }, { value: 2, label: '手机号' },
  { value: 3, label: '身份证号' }, { value: 4, label: '邮箱' },
]

function defaultField(key: string): FormField {
  const f: FormField = { key, val1: TYPE_LABELS[key] || key, val2: '', val3: 1, val4: 0, val8: 0, val9: 0, val10: 0, val11: 0, val15: '' }
  if (['radio', 'checkbox', 'selector'].includes(key)) f.val2 = ['']
  else if (key === 'time') f.val2 = ['00:00', '23:59']
  else if (key === 'date') f.val2 = ['1970-01-01', '2080-01-01']
  else if (key === 'year') f.val2 = ['1970', '2080']
  return f
}

// ============ 表单列表 ============
const loading = ref(false)
const list = ref<FormItem[]>([])
const total = ref(0)
const businessOptions = ref<{ id: number; name: string }[]>([])
const selected = ref<FormItem[]>([])
const query = reactive({ page: 1, pagesize: 10, name: '', bid: '' as number | '' })

async function fetchList() {
  loading.value = true
  try {
    const res = await getFormList({
      page: query.page,
      pagesize: query.pagesize,
      name: query.name,
      bid: isMerchantView.value ? (query.bid === '' ? 'gt0' : String(query.bid)) : props.bid,
    })
    list.value = res.list || []
    total.value = res.total || 0
    businessOptions.value = res.business_options || []
  } finally {
    loading.value = false
  }
}
function handleSearch() { query.page = 1; fetchList() }

function formRunning(row: FormItem): boolean {
  const now = Math.floor(Date.now() / 1000)
  const s = Number(row.starttime) || 0
  const e = Number(row.endtime) || 0
  if (s && now < s) return false
  if (e && now > e) return false
  return true
}

// ============ 表单编辑（基本信息 / 表单内容） ============
const saving = ref(false)
const editingId = ref(0)
const basic = reactive({
  name: '', starttime: '', endtime: '', maxlimit: 0, perlimit: 0,
  payset: 0, price: '', priceedit: 0, commissionset: '-1', sort: 0, bid: 0,
})
const fields = ref<FormField[]>([])

function resetBasic() {
  Object.assign(basic, {
    name: '', starttime: '', endtime: '', maxlimit: 0, perlimit: 0,
    payset: 0, price: '', priceedit: 0, commissionset: '-1', sort: 0, bid: 0,
  })
  fields.value = []
  activeTab.value = 'base'
}
function openAdd() {
  resetBasic()
  editingId.value = 0
  viewMode.value = 'edit'
}
async function openEdit(row: FormItem) {
  resetBasic()
  editingId.value = row.id
  const d = await getFormDetail(row.id)
  Object.assign(basic, {
    name: d.name || '', starttime: d.starttime || '', endtime: d.endtime || '',
    maxlimit: Number(d.maxlimit) || 0, perlimit: Number(d.perlimit) || 0,
    payset: Number(d.payset) || 0, price: d.price ?? '', priceedit: Number(d.priceedit) || 0,
    commissionset: String(d.commissionset ?? '-1'), sort: Number(d.sort) || 0, bid: Number(d.bid) || 0,
  })
  try {
    const arr = JSON.parse(d.content || '[]')
    if (Array.isArray(arr)) {
      fields.value = arr.map((it: any) => ({
        key: String(it.key || 'input'),
        val1: String(it.val1 || ''),
        val2: it.val2 ?? '',
        val3: Number(it.val3) || 0,
        val4: Number(it.val4) || 0,
        val8: Number(it.val8) || 0,
        val9: Number(it.val9) || 0,
        val10: Number(it.val10) || 0,
        val11: Number(it.val11) || 0,
        val15: String(it.val15 || ''),
      }))
    }
  } catch { fields.value = [] }
  viewMode.value = 'edit'
}
function backToList() { viewMode.value = 'list'; resetBasic(); fetchList() }

function addField(key: string) { fields.value.push(defaultField(key)) }
function moveField(i: number, dir: -1 | 1) {
  const j = i + dir
  if (j < 0 || j >= fields.value.length) return
  const t = fields.value[i]
  fields.value[i] = fields.value[j]
  fields.value[j] = t
}
function addOption(f: FormField) {
  if (!Array.isArray(f.val2)) f.val2 = []
  f.val2.push('')
}
function isOptionType(key: string) { return ['radio', 'checkbox', 'selector'].includes(key) }

async function handleSave() {
  if (!basic.name.trim()) { ElMessage.warning('请填写表单名称'); activeTab.value = 'base'; return }
  if (!basic.starttime) { ElMessage.warning('请选择开始时间'); activeTab.value = 'base'; return }
  if (!basic.endtime) { ElMessage.warning('请选择结束时间'); activeTab.value = 'base'; return }
  const contentArr = fields.value.map((f) => {
    let val2: any = f.val2
    if (isOptionType(f.key)) {
      val2 = (Array.isArray(f.val2) ? f.val2 : []).map((x: any) => String(x)).filter((x: string) => x !== '')
    } else if (['time', 'date', 'year'].includes(f.key)) {
      val2 = Array.isArray(f.val2) ? [f.val2[0] || '', f.val2[1] || ''] : ['', '']
    } else {
      val2 = String(f.val2 ?? '')
    }
    return { key: f.key, val1: f.val1, val2, val3: f.val3, val4: f.val4, val8: f.val8, val9: f.val9, val10: f.val10, val11: f.val11, val15: f.val15 }
  })
  saving.value = true
  try {
    await saveForm({
      id: editingId.value || undefined,
      name: basic.name, starttime: basic.starttime, endtime: basic.endtime,
      maxlimit: basic.maxlimit, perlimit: basic.perlimit,
      payset: Number(basic.payset), price: basic.price, priceedit: Number(basic.priceedit),
      commissionset: String(basic.commissionset), sort: Number(basic.sort), bid: Number(basic.bid) || 0,
      content: JSON.stringify(contentArr),
    })
    ElMessage.success(editingId.value ? '保存成功' : '添加成功')
    backToList()
  } finally {
    saving.value = false
  }
}

// ============ 列表行操作 ============
async function handleDelete(rows: FormItem[]) {
  if (!rows.length) { ElMessage.warning('请选择数据'); return }
  await ElMessageBox.confirm(`确定删除选中的 ${rows.length} 个表单及其提交记录？`, '删除确认', { type: 'warning' })
  await deleteForm(rows.map((r) => r.id))
  ElMessage.success('删除成功')
  selected.value = []
  fetchList()
}
// 链接小弹窗
const linkVisible = ref(false)
const linkUrl = ref('')
function openLink(row: FormItem) {
  linkUrl.value = `${window.location.origin}/#/pagesA/form/index?id=${row.id}`
  linkVisible.value = true
}
function copyLink() {
  navigator.clipboard.writeText(linkUrl.value).then(() => ElMessage.success('链接已复制'))
}

// ============ 数据列表（平铺） ============
const recTarget = ref<FormItem | null>(null)
const recFields = ref<FormField[]>([])
const recLoading = ref(false)
const recList = ref<FormOrderItem[]>([])
const recTotal = ref(0)
const recSelected = ref<FormOrderItem[]>([])
const recPage = ref(1)
const recQuery = reactive({ keyword: '', start: '', end: '', status: '' })

async function openRecord(row: FormItem) {
  recTarget.value = row
  recSelected.value = []
  recPage.value = 1
  Object.assign(recQuery, { keyword: '', start: '', end: '', status: '' })
  viewMode.value = 'record'
  await fetchRecord()
}
async function fetchRecord() {
  if (!recTarget.value) return
  recLoading.value = true
  try {
    const res = await getFormRecord({
      page: recPage.value, pagesize: 10, formid: recTarget.value.id,
      keyword: recQuery.keyword, starttime: recQuery.start, endtime: recQuery.end,
      status: recQuery.status, bid: props.bid,
    })
    recList.value = res.list || []
    recTotal.value = res.total || 0
    recFields.value = (res as any).formcontent || []
  } finally {
    recLoading.value = false
  }
}
function handleRecSearch() { recPage.value = 1; fetchRecord() }

function cellText(row: FormOrderItem, k: number): string {
  const v = (row as any)['form' + k]
  if (Array.isArray(v)) return v.join('，')
  return v ?? ''
}
function hasPics(): boolean {
  return recFields.value.some((f) => f.key === 'upload' || f.key === 'upload_pics')
}
function statusText(s: number) { return s === 1 ? '已确认' : s === 2 ? '已驳回' : '待处理' }
function statusType(s: number) { return s === 1 ? 'success' : s === 2 ? 'danger' : 'info' }

async function batchSetst(st: number) {
  if (!recSelected.value.length) { ElMessage.warning('请选择记录'); return }
  const ids = recSelected.value.map((r) => r.id)
  let reason = ''
  if (st === 2) {
    try { reason = (await ElMessageBox.prompt('请填写驳回原因', '驳回确认', { inputPlaceholder: '驳回原因' })).value || '' } catch { return }
  } else {
    try { await ElMessageBox.confirm(`确定确认选中的 ${ids.length} 条记录吗？`, '操作确认', { type: 'warning' }) } catch { return }
  }
  await formRecordSetst(ids, st, reason)
  ElMessage.success(st === 1 ? '已确认' : '已驳回')
  recSelected.value = []
  fetchRecord()
  fetchList()
}
async function batchDel() {
  if (!recSelected.value.length) { ElMessage.warning('请选择记录'); return }
  await ElMessageBox.confirm(`确定删除选中的 ${recSelected.value.length} 条记录？`, '删除确认', { type: 'warning' })
  await formRecordDel(recSelected.value.map((r) => r.id))
  ElMessage.success('删除成功')
  recSelected.value = []
  fetchRecord()
}
async function rowSetst(row: FormOrderItem, st: number) {
  let reason = ''
  if (st === 2) {
    try { reason = (await ElMessageBox.prompt('请填写驳回原因', '驳回确认', { inputPlaceholder: '驳回原因' })).value || '' } catch { return }
  }
  await formRecordSetst([row.id], st, reason)
  ElMessage.success(st === 1 ? '已确认' : '已驳回')
  fetchRecord()
  fetchList()
}
async function rowDel(row: FormOrderItem) {
  await ElMessageBox.confirm('确定删除该条记录吗？', '删除确认', { type: 'warning' })
  await formRecordDel([row.id])
  ElMessage.success('删除成功')
  fetchRecord()
}
async function handleExport() {
  if (!recTarget.value) return
  try {
    const blob = await exportFormRecord({
      formid: recTarget.value.id, status: recQuery.status, keyword: recQuery.keyword,
      starttime: recQuery.start, endtime: recQuery.end, bid: props.bid,
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `表单数据_${recTarget.value.name}_${Date.now()}.xlsx`
    a.click()
    URL.revokeObjectURL(url)
  } catch { /* 拦截器统一提示 */ }
}

// 查看图片小弹窗
const picVisible = ref(false)
const picList = ref<string[]>([])
function openPics(row: FormOrderItem) {
  const pics: string[] = []
  recFields.value.forEach((f, k) => {
    const v = (row as any)['form' + k]
    if (f.key === 'upload' && v) pics.push(v)
    if (f.key === 'upload_pics' && v) pics.push(...(Array.isArray(v) ? v : [v]))
  })
  picList.value = pics.filter(Boolean)
  picVisible.value = true
}

// ============ 数据编辑 / 新增（平铺） ============
const recId = ref(0)
const rec = reactive({ mid: 0, midName: '', status: 0, paystatus: 0, money: '0' })
const formVals = reactive<Record<string, any>>({})
const regionVals = reactive<Record<number, { p: string; c: string; d: string }>>({})

function clearRecValues() {
  Object.keys(formVals).forEach((k) => delete formVals[k])
  Object.keys(regionVals).forEach((k) => delete regionVals[Number(k)])
}
function initRecValues(src?: FormOrderItem) {
  clearRecValues()
  recFields.value.forEach((f, k) => {
    const raw = src ? (src as any)['form' + k] : ''
    if (f.key === 'checkbox') formVals['form' + k] = Array.isArray(raw) ? raw : (raw ? String(raw).split(',') : [])
    else if (f.key === 'upload_pics') formVals['form' + k] = Array.isArray(raw) ? raw : (raw ? String(raw).split(',') : [])
    else if (f.key === 'region') {
      regionVals[k] = { p: '', c: '', d: '' }
      const parts = raw ? String(raw).split(',') : []
      regionVals[k].p = parts[0] || ''
      regionVals[k].c = parts[1] || ''
      regionVals[k].d = parts[2] || ''
    } else formVals['form' + k] = raw ?? ''
  })
}
function openRecordAdd() {
  recId.value = 0
  Object.assign(rec, { mid: 0, midName: '', status: 0, paystatus: 0, money: recTarget.value?.price || '0' })
  initRecValues()
  viewMode.value = 'recordedit'
}
async function openRecordEdit(row: FormOrderItem) {
  recId.value = row.id
  Object.assign(rec, { mid: row.mid, midName: row.nickname || '', status: row.status, paystatus: row.paystatus, money: row.money ?? '0' })
  // 回读完整数据（含图片字段）
  try {
    const d = await getFormRecordDetail(row.id)
    initRecValues(d as any)
  } catch {
    initRecValues(row)
  }
  viewMode.value = 'recordedit'
}
async function handleRecSave() {
  if (rec.mid <= 0) { ElMessage.warning('请填写用户ID'); return }
  const fields: Record<string, any> = {}
  let miss = ''
  recFields.value.forEach((f, k) => {
    let v = formVals['form' + k]
    if (f.key === 'region') {
      const r = regionVals[k]
      v = [r.p, r.c, r.d].filter(Boolean).join(',')
    }
    fields['form' + k] = v
    if (f.val3 === 1) {
      const empty = Array.isArray(v) ? v.length === 0 : v === '' || v === null
      if (empty) miss = miss || (f.val1 || '该项')
    }
  })
  if (miss) { ElMessage.warning(miss + ' 必填'); return }
  await saveFormRecord({
    formid: recTarget.value!.id, id: recId.value || undefined,
    mid: rec.mid, status: rec.status, paystatus: rec.paystatus, money: rec.money, fields,
  })
  ElMessage.success(recId.value ? '编辑成功' : '新增成功')
  viewMode.value = 'record'
  fetchRecord()
}

// 省市区联动（复用项目 areaData）
function cityOpts(k: number) { const p = areaProvinces.find((x) => x.name === regionVals[k]?.p); return p ? p.cityList : [] }
function areaOpts(k: number) { const c = cityOpts(k).find((x) => x.name === regionVals[k]?.c); return c ? c.areaList : [] }
function onProvinceChg(k: number) { regionVals[k].c = ''; regionVals[k].d = '' }
function onCityChg(k: number) { regionVals[k].d = '' }

// ============ 查找会员ID（平铺覆盖层） ============
const memberLoading = ref(false)
const memberList = ref<MemberItem[]>([])
const memberTotal = ref(0)
const memberPage = ref(1)
const memberKeyword = ref('')
async function openChooseMember() {
  memberPage.value = 1
  memberKeyword.value = ''
  viewMode.value = 'chooseMember'
  await fetchMembers()
}
async function fetchMembers() {
  memberLoading.value = true
  try {
    const res = await getMemberList({ page: memberPage.value, pagesize: 10, keyword: memberKeyword.value })
    memberList.value = res.list || []
    memberTotal.value = res.total || 0
  } finally {
    memberLoading.value = false
  }
}
function pickMember(m: MemberItem) {
  rec.mid = m.id
  rec.midName = m.nickname || ('会员#' + m.id)
  viewMode.value = 'recordedit'
}

// ============ 图片选择器 ============
const imgVisible = ref(false)
let imgSetter: ((urls: string[]) => void) | null = null
function openImg(multi: boolean, setter: (urls: string[]) => void) { imgSetter = setter; imgVisible.value = true; imgMulti.value = multi }
const imgMulti = ref(false)
function onImgPick(urls: string[]) {
  imgSetter?.(urls)
  imgSetter = null
}

onMounted(fetchList)
</script>

<template>
  <div class="form-page">
    <!-- ===================== 表单列表 ===================== -->
    <div v-if="viewMode === 'list'">
      <el-card shadow="never" class="toolbar">
        <div class="toolbar-left">
          <el-button v-if="!isMerchantView" type="success" @click="openAdd">添加表单</el-button>
          <el-button @click="handleDelete(selected)">删除</el-button>
        </div>
        <el-form inline class="toolbar-search" @submit.prevent>
          <el-form-item v-if="isMerchantView" label="所属商户">
            <el-select v-model="query.bid" placeholder="全部" clearable style="width: 140px" @change="handleSearch">
              <el-option label="全部" :value="''" />
              <el-option v-for="b in businessOptions.filter((x) => x.id > 0)" :key="b.id" :label="b.name" :value="b.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="表单名称">
            <el-input v-model="query.name" placeholder="表单名称" clearable style="width: 180px" @keyup.enter="handleSearch" @clear="handleSearch" />
          </el-form-item>
          <el-form-item>
            <el-button type="success" class="search-icon-btn" @click="handleSearch">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
            </el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card shadow="never">
        <el-alert type="info" :closable="false" show-icon title="添加完表单之后需要在 [设计] - [页面设计] 中调用「自定义表单」组件方可展示。" style="margin-bottom: 12px" />
        <el-table v-loading="loading" :data="list" border @selection-change="(rows: FormItem[]) => (selected = rows)">
          <el-table-column type="selection" width="45" />
          <el-table-column prop="id" label="ID" width="70" align="center" />
          <el-table-column v-if="isMerchantView" prop="bname" label="所属商户" width="130" show-overflow-tooltip>
            <template #default="{ row }">{{ row.bname || '-' }}</template>
          </el-table-column>
          <el-table-column prop="name" label="名称" min-width="160" show-overflow-tooltip />
          <el-table-column label="有效时间" min-width="240">
            <template #default="{ row }">
              {{ formatTime(Number(row.starttime)) }} ~ {{ formatTime(Number(row.endtime)) }}
            </template>
          </el-table-column>
          <el-table-column label="创建时间" width="150">
            <template #default="{ row }">{{ formatTime(Number(row.createtime)) }}</template>
          </el-table-column>
          <el-table-column prop="sort" label="排序" width="70" align="center" />
          <el-table-column label="状态" width="90" align="center">
            <template #default="{ row }">
              <span v-if="formRunning(row)" class="st-running">进行中</span>
              <span v-else class="st-end">已结束</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="180" fixed="right">
            <template #default="{ row }">
              <!-- 表单列表操作：数据/编辑/链接/删除 横排一行（对照点大）；数据带待处理数角标 -->
              <div class="ops-inline">
                <el-badge v-if="row.st0count > 0" :value="row.st0count" :max="99" type="danger">
                  <el-button link type="primary" @click="openRecord(row)">数据</el-button>
                </el-badge>
                <el-button v-else link type="primary" @click="openRecord(row)">数据</el-button>
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="primary" @click="openLink(row)">链接</el-button>
                <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination
          v-model:current-page="query.page" v-model:page-size="query.pagesize" :total="total"
          :page-sizes="[10, 20, 50]" layout="total, sizes, prev, pager, next, jumper" class="pagination" @change="fetchList"
        />
      </el-card>
    </div>

    <!-- ===================== 表单编辑（平铺） ===================== -->
    <div v-else-if="viewMode === 'edit'" class="ge-box">
      <div class="ge-head">
        <span class="ge-head-title">{{ editingId ? '编辑表单' : '添加表单' }}</span>
        <el-button class="ge-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>

      <el-tabs v-model="activeTab">
        <!-- 基本信息 -->
        <el-tab-pane label="基本信息" name="base">
          <el-form label-width="110px" class="ge-form">
            <el-form-item label="表单名称" required>
              <el-input v-model="basic.name" maxlength="50" style="width: 300px" placeholder="请输入表单名称" />
            </el-form-item>
            <el-form-item label="开始时间" required>
              <el-date-picker v-model="basic.starttime" type="datetime" value-format="X" format="YYYY-MM-DD HH:mm:ss" style="width: 240px" />
            </el-form-item>
            <el-form-item label="结束时间" required>
              <el-date-picker v-model="basic.endtime" type="datetime" value-format="X" format="YYYY-MM-DD HH:mm:ss" style="width: 240px" />
            </el-form-item>
            <el-form-item label="总数量限制">
              <el-input-number v-model="basic.maxlimit" :min="0" :controls="false" style="width: 200px" />
              <span class="ge-hint">总收集数据限制，0表示不限制</span>
            </el-form-item>
            <el-form-item label="每人数量限制">
              <el-input-number v-model="basic.perlimit" :min="0" :controls="false" style="width: 200px" />
              <span class="ge-hint">每人提交次数限制，0表示不限制</span>
            </el-form-item>
            <el-form-item label="在线支付">
              <el-radio-group v-model="basic.payset">
                <el-radio :value="1">开启</el-radio>
                <el-radio :value="0">关闭</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item v-if="basic.payset === 1" label="默认支付金额">
              <el-input v-model="basic.price" style="width: 200px" placeholder="0.00" />
              <span class="ge-hint">元</span>
            </el-form-item>
            <el-form-item v-if="basic.payset === 1" label="支付金额修改">
              <el-radio-group v-model="basic.priceedit">
                <el-radio :value="1">开启</el-radio>
                <el-radio :value="0">关闭</el-radio>
              </el-radio-group>
              <span class="ge-hint">用户是否可以自己填写支付金额</span>
            </el-form-item>
            <el-form-item label="分销设置">
              <el-radio-group v-model="basic.commissionset">
                <el-radio value="0">按照会员等级</el-radio>
                <el-radio value="1">单独设置提成比例</el-radio>
                <el-radio value="2">单独设置提成金额</el-radio>
                <el-radio value="-1">不参与分销</el-radio>
              </el-radio-group>
              <div class="comm-tip">按照会员等级：按照会员等级中设置的提成比例进行提成分配，提成=对应会员等级的提成比例×销售价×购买数量</div>
              <div class="comm-tip">单独设置提成比例：按照单独设置的提成比例进行提成分配，提成=设置的百分比×销售价×购买数量</div>
              <div class="comm-tip">单独设置提成金额：按照单独设置的提成金额进行提成分配，提成=提成金额×购买数量</div>
            </el-form-item>
            <el-form-item label="序号">
              <el-input-number v-model="basic.sort" :min="0" :controls="false" style="width: 200px" />
              <span class="ge-hint">用于排序，越大越靠前</span>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="activeTab = 'content'">下一步:编辑表单内容</el-button>
              <el-button type="warning" :loading="saving" @click="handleSave">提 交</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 表单内容 -->
        <el-tab-pane label="表单内容" name="content">
          <div class="add-bar">
            <span class="add-bar-label">添加表单项：</span>
            <el-button v-for="t in ADD_BUTTONS" :key="t" size="small" @click="addField(t)">{{ TYPE_LABELS[t] }}</el-button>
          </div>
          <div class="add-bar-label" style="margin: 10px 0 6px">表单项列表：</div>
          <el-table :data="fields" border size="small" class="field-table">
            <el-table-column label="表单类型" width="90">
              <template #default="{ row }">{{ TYPE_LABELS[row.key] || row.key }}</template>
            </el-table-column>
            <el-table-column label="字段名称" width="130">
              <template #default="{ row }"><el-input v-model="row.val1" size="small" /></template>
            </el-table-column>
            <el-table-column label="请填写名称(占位提示)" min-width="220">
              <template #default="{ row }">
                <!-- 选择类：选项 +/- -->
                <div v-if="isOptionType(row.key)">
                  <div v-for="i in row.val2.length" :key="i" class="opt-row">
                    <el-input v-model="row.val2[i-1]" size="small" style="width: 150px" />
                    <el-button size="small" @click="addOption(row)"><el-icon><span>+</span></el-icon></el-button>
                    <el-button size="small" @click="row.val2.splice(i-1, 1)"><el-icon><span>−</span></el-icon></el-button>
                  </div>
                </div>
                <!-- 时间/日期/年份：起止 -->
                <div v-else-if="['time', 'date', 'year'].includes(row.key)" class="range-row">
                  <el-input v-model="row.val2[0]" size="small" style="width: 150px" />
                  <el-input v-model="row.val2[1]" size="small" style="width: 150px" />
                </div>
                <!-- 省市区 -->
                <div v-else-if="row.key === 'region'">选择省市区</div>
                <!-- 上传多图提示 -->
                <div v-else-if="row.key === 'upload_pics'">
                  <el-input v-model="row.val15" size="small" style="width: 200px" placeholder="请输入提示信息" />
                </div>
                <!-- 单行/多行/单图：占位提示 -->
                <el-input v-else v-model="row.val2" size="small" style="width: 200px" :placeholder="row.key === 'upload' ? '请输入提示信息' : '请填写占位提示'" />
              </template>
            </el-table-column>
            <el-table-column label="类型" width="150">
              <template #default="{ row }">
                <el-select v-if="row.key === 'input'" v-model="row.val4" size="small" style="width: 110px">
                  <el-option v-for="o in INPUT_TYPES" :key="o.value" :label="o.label" :value="o.value" />
                </el-select>
                <template v-else-if="row.key === 'radio'">
                  <el-select v-model="row.val10" size="small" style="width: 80px">
                    <el-option label="一行多个" :value="0" /><el-option label="一行一个" :value="1" />
                  </el-select>
                  <el-select v-model="row.val11" size="small" style="width: 80px">
                    <el-option label="无边框" :value="0" /><el-option label="有边框" :value="1" />
                  </el-select>
                </template>
                <template v-else-if="row.key === 'checkbox'">
                  <el-select v-model="row.val4" size="small" style="width: 80px">
                    <el-option label="一行多个" :value="0" /><el-option label="一行一个" :value="1" />
                  </el-select>
                  <el-select v-model="row.val9" size="small" style="width: 80px">
                    <el-option label="无边框" :value="0" /><el-option label="有边框" :value="1" />
                  </el-select>
                </template>
                <span v-else>-</span>
              </template>
            </el-table-column>
            <el-table-column label="是否必填" width="80" align="center">
              <template #default="{ row }"><el-switch v-model="row.val3" :active-value="1" :inactive-value="0" /></template>
            </el-table-column>
            <el-table-column label="标题加粗" width="80" align="center">
              <template #default="{ row }"><el-switch v-model="row.val8" :active-value="1" :inactive-value="0" /></template>
            </el-table-column>
            <el-table-column label="操作" width="130" align="center">
              <template #default="{ $index }">
                <el-button size="small" @click="fields.splice($index, 1)">✕</el-button>
                <el-button size="small" @click="moveField($index, -1)">↑</el-button>
                <el-button size="small" @click="moveField($index, 1)">↓</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div class="tab-footer">
            <el-button type="primary" @click="activeTab = 'base'">上一步:编辑基本信息</el-button>
            <el-button type="warning" :loading="saving" @click="handleSave">提 交</el-button>
          </div>
        </el-tab-pane>
      </el-tabs>
    </div>

    <!-- ===================== 数据列表（平铺） ===================== -->
    <div v-else-if="viewMode === 'record'" class="ge-box">
      <div class="ge-head">
        <span class="ge-head-title">表单数据{{ recTarget ? ' - ' + recTarget.name : '' }}</span>
        <el-button class="ge-close" circle @click="viewMode = 'list'; fetchList()">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>

      <div class="rec-toolbar">
        <div class="rec-toolbar-left">
          <el-button size="small" @click="batchSetst(1)">通过</el-button>
          <el-button size="small" @click="batchSetst(2)">驳回</el-button>
          <el-button size="small" @click="batchDel">删除</el-button>
          <el-button size="small" @click="openRecordAdd">新增</el-button>
          <el-button size="small" @click="handleExport">导出</el-button>
        </div>
        <div class="rec-toolbar-right">
          <el-input v-model="recQuery.keyword" placeholder="内容" clearable size="small" style="width: 150px" @keyup.enter="handleRecSearch" />
          <el-date-picker v-model="recQuery.start" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" size="small" style="width: 130px" />
          <el-date-picker v-model="recQuery.end" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" size="small" style="width: 130px" />
          <el-select v-model="recQuery.status" placeholder="状态" clearable size="small" style="width: 110px">
            <el-option label="待处理" value="0" /><el-option label="已确认" value="1" /><el-option label="已驳回" value="2" />
          </el-select>
          <el-button type="success" class="search-icon-btn" size="small" @click="handleRecSearch">
            <svg viewBox="0 0 1024 1024" width="14" height="14" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
          </el-button>
        </div>
      </div>

      <el-table v-loading="recLoading" :data="recList" border class="rec-table" @selection-change="(rows: FormOrderItem[]) => (recSelected = rows)">
        <el-table-column type="selection" width="42" fixed="left" />
        <el-table-column prop="id" label="ID" width="64" fixed="left" />
        <el-table-column label="头像/昵称" width="140" fixed="left">
          <template #default="{ row }">
            <div class="member-cell">
              <el-image v-if="row.headimg" :src="row.headimg" :preview-src-list="[row.headimg]" fit="cover" class="member-avatar" preview-teleported />
              <span class="member-nick">{{ row.nickname || '-' }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column v-for="(f, k) in recFields" :key="k" :label="f.val1" :min-width="120">
          <template #default="{ row }">
            <template v-if="f.key === 'upload'">
              <el-image v-if="(row as any)['form' + k]" :src="(row as any)['form' + k]" :preview-src-list="[(row as any)['form' + k]]" fit="cover" class="cell-img" preview-teleported />
              <span v-else>-</span>
            </template>
            <template v-else-if="f.key === 'upload_pics'">
              <div v-if="Array.isArray((row as any)['form' + k]) && (row as any)['form' + k].length" class="cell-imgs">
                <el-image v-for="(p, pi) in (row as any)['form' + k]" :key="pi" :src="p" :preview-src-list="(row as any)['form' + k]" fit="cover" class="cell-img" preview-teleported />
              </div>
              <span v-else>-</span>
            </template>
            <span v-else>{{ cellText(row, k) || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="支付状态" width="90">
          <template #default="{ row }">
            <span :class="row.paystatus === 1 ? 'pay-ok' : ''">{{ row.paystatus === 1 ? '已支付' : '未支付' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="money" label="支付金额" width="90" />
        <el-table-column prop="ordernum" label="支付单号" width="140" show-overflow-tooltip />
        <el-table-column label="支付时间" width="150">
          <template #default="{ row }">{{ row.paytime ? formatTime(Number(row.paytime)) : '-' }}</template>
        </el-table-column>
        <el-table-column label="提交时间" width="150">
          <template #default="{ row }">{{ formatTime(Number(row.createtime)) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small">{{ statusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <!-- 数据操作：通过/驳回、查看图片/编辑/删除 横排自动换行（对照点大，非写死竖排） -->
            <div class="ops-wrap">
              <el-button v-if="row.status === 0" link type="primary" @click="rowSetst(row, 1)">通过</el-button>
              <el-button v-if="row.status === 0" link type="warning" @click="rowSetst(row, 2)">驳回</el-button>
              <el-button v-if="hasPics()" link type="primary" @click="openPics(row)">查看图片</el-button>
              <el-button link type="primary" @click="openRecordEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="rowDel(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-model:current-page="recPage" :page-size="10" :total="recTotal"
        layout="total, prev, pager, next" class="pagination" @current-change="fetchRecord"
      />
    </div>

    <!-- ===================== 数据编辑 / 新增（平铺） ===================== -->
    <div v-else-if="viewMode === 'recordedit'" class="ge-box">
      <div class="ge-head">
        <span class="ge-head-title">{{ recId ? '编辑数据' : '新增数据' }}</span>
        <el-button class="ge-close" circle @click="viewMode = 'record'; fetchRecord()">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>

      <el-form label-width="120px" class="ge-form">
        <el-form-item label="用户ID">
          <el-input-number v-model="rec.mid" :min="0" :controls="false" style="width: 180px" />
          <el-button link type="primary" @click="openChooseMember">查找会员ID</el-button>
          <span v-if="rec.midName" class="ge-hint">{{ rec.midName }}</span>
        </el-form-item>

        <el-form-item v-for="(f, k) in recFields" :key="k" :label="f.val1" :required="f.val3 === 1">
          <el-input v-if="f.key === 'input'" v-model="formVals['form' + k]" style="width: 260px" :placeholder="String(f.val2 || '')" />
          <el-input v-else-if="f.key === 'textarea'" v-model="formVals['form' + k]" type="textarea" :rows="3" style="width: 360px" :placeholder="String(f.val2 || '')" />
          <el-radio-group v-else-if="f.key === 'radio'" v-model="formVals['form' + k]">
            <el-radio v-for="opt in (Array.isArray(f.val2) ? f.val2 : [])" :key="opt" :value="opt">{{ opt }}</el-radio>
          </el-radio-group>
          <el-checkbox-group v-else-if="f.key === 'checkbox'" v-model="formVals['form' + k]">
            <el-checkbox-button v-for="opt in (Array.isArray(f.val2) ? f.val2 : [])" :key="opt" :value="opt">{{ opt }}</el-checkbox-button>
          </el-checkbox-group>
          <el-select v-else-if="f.key === 'selector'" v-model="formVals['form' + k]" placeholder="请选择" style="width: 220px">
            <el-option v-for="opt in (Array.isArray(f.val2) ? f.val2 : [])" :key="opt" :label="opt" :value="opt" />
          </el-select>
          <el-time-picker v-else-if="f.key === 'time'" v-model="formVals['form' + k]" value-format="HH:mm" format="HH:mm" style="width: 180px" />
          <el-date-picker v-else-if="f.key === 'date'" v-model="formVals['form' + k]" type="date" value-format="YYYY-MM-DD" style="width: 180px" />
          <el-date-picker v-else-if="f.key === 'year'" v-model="formVals['form' + k]" type="year" value-format="YYYY" style="width: 160px" />
          <template v-else-if="f.key === 'region'">
            <div class="region-row">
              <el-select v-model="regionVals[k].p" placeholder="请选择省" style="width: 150px" @change="onProvinceChg(k)">
                <el-option v-for="p in areaProvinces" :key="p.name" :label="p.name" :value="p.name" />
              </el-select>
              <el-select v-model="regionVals[k].c" placeholder="请选择市" style="width: 150px" @change="onCityChg(k)">
                <el-option v-for="c in cityOpts(k)" :key="c.name" :label="c.name" :value="c.name" />
              </el-select>
              <el-select v-model="regionVals[k].d" placeholder="请选择地区" style="width: 150px">
                <el-option v-for="a in areaOpts(k)" :key="a" :label="a" :value="a" />
              </el-select>
            </div>
          </template>
          <div v-else-if="f.key === 'upload'">
            <el-button size="small" @click="openImg(false, (u) => (formVals['form' + k] = u[0] || ''))">上传图片</el-button>
            <el-image v-if="formVals['form' + k]" :src="formVals['form' + k]" fit="cover" class="rec-img" />
          </div>
          <div v-else-if="f.key === 'upload_pics'">
            <el-button size="small" @click="openImg(true, (u) => (formVals['form' + k] = u))">批量上传</el-button>
            <div v-if="Array.isArray(formVals['form' + k]) && formVals['form' + k].length" class="rec-imgs">
              <el-image v-for="(p, pi) in formVals['form' + k]" :key="pi" :src="p" fit="cover" class="rec-img" />
            </div>
          </div>
        </el-form-item>

        <el-form-item label="支付金额">
          <el-input v-model="rec.money" style="width: 200px" placeholder="0.00" />
        </el-form-item>
        <el-form-item label="支付状态">
          <el-radio-group v-model="rec.paystatus">
            <el-radio :value="1">已支付</el-radio>
            <el-radio :value="0">未支付</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="rec.status">
            <el-radio :value="0">待处理</el-radio>
            <el-radio :value="1">已确认</el-radio>
            <el-radio :value="2">已驳回</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item>
          <el-button type="warning" @click="handleRecSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- ===================== 查找会员ID（平铺覆盖层） ===================== -->
    <div v-else-if="viewMode === 'chooseMember'" class="ge-box">
      <div class="ge-head">
        <span class="ge-head-title">选择会员</span>
        <el-button class="ge-close" circle @click="viewMode = 'recordedit'">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>
      <div class="rec-toolbar">
        <div class="rec-toolbar-right">
          <el-input v-model="memberKeyword" placeholder="昵称/手机号" clearable size="small" style="width: 200px" @keyup.enter="(memberPage = 1, fetchMembers())" />
          <el-button type="success" size="small" @click="(memberPage = 1, fetchMembers())">搜索</el-button>
        </div>
      </div>
      <el-table v-loading="memberLoading" :data="memberList" border @row-click="pickMember" class="pick-table">
        <el-table-column prop="id" label="会员ID" width="90" />
        <el-table-column label="头像" width="80">
          <template #default="{ row }">
            <el-image v-if="row.headimg" :src="row.headimg" fit="cover" class="member-avatar" />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="nickname" label="昵称" min-width="160" />
        <el-table-column prop="tel" label="手机号" width="140" />
      </el-table>
      <el-pagination
        v-model:current-page="memberPage" :page-size="10" :total="memberTotal"
        layout="total, prev, pager, next" class="pagination" @current-change="fetchMembers"
      />
    </div>

    <!-- 查看图片小弹窗 -->
    <el-dialog v-model="picVisible" title="查看图片" width="640px" append-to-body>
      <div class="pic-dialog-imgs">
        <el-image v-for="(p, i) in picList" :key="i" :src="p" :preview-src-list="picList" fit="contain" class="pic-dialog-img" preview-teleported />
      </div>
    </el-dialog>

    <!-- 链接小弹窗 -->
    <el-dialog v-model="linkVisible" title="表单链接" width="520px" append-to-body>
      <el-input v-model="linkUrl" readonly>
        <template #append><el-button @click="copyLink">复制</el-button></template>
      </el-input>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgVisible" :multi="imgMulti" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.form-page { padding: 12px; }
.toolbar { margin-bottom: 12px; }
.toolbar :deep(.el-card__body) { padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: nowrap; }
.toolbar-left { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.toolbar-left .el-button { margin: 0; }
.toolbar-search { display: flex; align-items: center; flex: 1 1 auto; justify-content: flex-end; flex-wrap: nowrap; min-width: 0; margin: 0; }
.toolbar-search :deep(.el-form-item) { margin-bottom: 0; margin-right: 8px; }
.toolbar-search :deep(.el-form-item__label) { padding-right: 6px; }
.search-icon-btn { padding: 7px 12px; }
.search-icon-btn svg { display: block; }
.pagination { margin-top: 12px; justify-content: flex-end; }
/* 表单列表操作：四个文字按钮横排一行（宽度足够不换行） */
.ops-inline { display: flex; flex-direction: row; align-items: center; justify-content: center; gap: 14px; flex-wrap: nowrap; white-space: nowrap; line-height: 1.5; }
.ops-inline .el-button { margin: 0; height: auto; min-height: 0; padding: 0; font-size: 12px; }
/* 数据列表操作：横排，列宽不足时自动换行（通过·驳回 / 查看图片·编辑·删除） */
.ops-wrap { display: flex; flex-direction: row; align-items: center; gap: 4px 14px; flex-wrap: wrap; line-height: 1.5; }
.ops-wrap .el-button { margin: 0; height: auto; min-height: 0; padding: 0; font-size: 12px; }
.st-running { display: inline-block; padding: 1px 10px; background: #67c23a; color: #fff; border-radius: 3px; font-size: 12px; }
.st-end { display: inline-block; padding: 1px 10px; background: #909399; color: #fff; border-radius: 3px; font-size: 12px; }

/* 平铺编辑主区 */
.ge-box { background: #fff; border-radius: 6px; padding: 16px 20px; }
.ge-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.ge-head-title { font-size: 15px; font-weight: 600; color: #303133; }
.ge-close { border: none; background: transparent; color: #909399; }
.ge-close:hover { color: #409eff; background: transparent; }
.ge-form :deep(.el-form-item) { margin-bottom: 16px; }
.ge-hint { color: #909399; font-size: 12px; margin-left: 10px; }
.comm-tip { color: #909399; font-size: 12px; line-height: 1.6; margin-left: 110px; }
.tab-footer { display: flex; justify-content: center; gap: 14px; padding: 16px 0 8px; border-top: 1px solid #ebeef5; }
.add-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.add-bar-label { font-size: 13px; color: #606266; }
.opt-row { display: flex; align-items: center; gap: 4px; margin-bottom: 4px; }
.range-row { display: flex; gap: 6px; }
.field-table :deep(.el-table__cell) { padding: 6px 0; }

/* 数据列表工具栏 */
.rec-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: nowrap; margin-bottom: 10px; }
.rec-toolbar-left { display: flex; gap: 6px; flex: 0 0 auto; }
.rec-toolbar-right { display: flex; align-items: center; gap: 6px; flex: 1 1 auto; justify-content: flex-end; flex-wrap: nowrap; }
.rec-table { width: 100%; }
.member-cell { display: flex; align-items: center; gap: 6px; }
.member-avatar { width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0; }
.member-nick { font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 90px; }
.cell-img { width: 46px; height: 46px; border-radius: 4px; }
.cell-imgs { display: flex; gap: 4px; }
.pay-ok { color: #67c23a; font-weight: 600; }
.region-row { display: flex; gap: 8px; }
.rec-img { width: 80px; height: 80px; border-radius: 4px; margin-top: 6px; }
.rec-imgs { display: flex; flex-wrap: wrap; gap: 8px; }
.pick-table { cursor: pointer; }
.pic-dialog-imgs { display: flex; flex-wrap: wrap; gap: 10px; }
.pic-dialog-img { width: 140px; height: 140px; border-radius: 4px; }
</style>
