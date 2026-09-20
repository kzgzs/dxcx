<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteMemberLevel,
  getMemberLevelDetail,
  getMemberLevelList,
  getMemberLevelRecordList,
  saveMemberLevel,
  saveMemberLevelRecord,
} from '../../api/member'
import { getCategoryList, getGoodsList } from '../../api/goods'
import { assetUrl } from '../../api/upload'
import type {
  MemberLevelItem,
  MemberLevelRecordItem,
  MemberLevelSaveParams,
} from '../../api/types'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { CircleClose } from '@element-plus/icons-vue'

// ============ 列表 / 平铺切换 ============
const loading = ref(false)
const tableData = ref<MemberLevelItem[]>([])
const viewMode = ref<'list' | 'form'>('list')

async function fetchList() {
  loading.value = true
  try {
    const data = await getMemberLevelList()
    tableData.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// ============ 候选数据（商品/分类） ============
const goodsOptions = ref<{ id: number; title: string }[]>([])
const categoryOptions = ref<{ id: number; name: string }[]>([])

async function loadCandidates() {
  try {
    const [goods, cats] = await Promise.all([
      getGoodsList({ page: 1, pagesize: 200 }),
      getCategoryList({ page: 1, pagesize: 200 }),
    ])
    goodsOptions.value = (goods.list || []).map((g) => ({ id: g.id, title: g.name }))
    categoryOptions.value = (cats.list || []).map((c) => ({ id: c.id, name: c.name }))
  } catch {
    // 候选加载失败不阻断页面
  }
}

// ============ 表单 ============
interface LevelForm {
  id: number
  name: string
  icon: string
  sort: number
  discount: number
  isdefault: number
  maxnum: number
  // 申请设置组
  can_up: number
  can_apply: number
  apply_check: number
  apply_ordermoney: number
  apply_rechargemoney: number
  apply_paymoney: number
  apply_paytxt: string
  // 升级条件组（金额/人数）
  up_ordermoney: number
  up_rechargemoney: number
  up_wxpaymoney: number
  up_perpaymoney: number
  up_orderprice: number
  up_getmembercard: number
  up_fxordermoney: number
  up_fxorderlevelnum: number
  up_fxorderlevelid: number[]
  up_fxdowncount: number
  up_fxdownlevelnum: number
  up_fxdownlevelid: number[]
  up_fxdowncount2: number
  up_fxdownlevelnum2: number
  up_fxdownlevelid2: number[]
  up_fxdowncount3: number
  up_fxdownlevelnum3: number
  up_fxdownlevelid3: number[]
  up_proid: number[]
  up_pronum: number
  up_catid: number[]
  up_cat_ordermoney: number
  gettj: number[]
  // 分销配置组
  can_agent: number
  agent_rule: number
  commissionset: number
  commissiontype: number
  commission1: number
  commission2: number
  commission3: number
  commission4: number
  commission5: number
  commission6: number
  commission_parent: number
  commission_parent_pj: number
  commission_appointlevelid: number[]
  commission_max: number
  fx_jinsuo: number
  // 推荐积分
  score1: number
  score2: number
  score3: number
  scoremax: number
  // 分红配置组
  fenhong: number
  fenhong_num: number
  fenhong_max_money: number
  teamfenhonglv: number
  teamfenhongbl: number
  teamfenhong_money: number
  areafenhong: number
  areafenhongbl: number
  areafenhongmaxnum: number
  // 升级赠送组
  up_give_score: number
  up_give_commission: number
  up_give_money: number
  up_give_parent_money: number
}

function buildEmptyForm(): LevelForm {
  return {
    id: 0,
    name: '',
    icon: '',
    sort: 1,
    discount: 10,
    isdefault: 0,
    maxnum: 0,
    can_up: 0,
    can_apply: 0,
    apply_check: 1,
    apply_ordermoney: 0,
    apply_rechargemoney: 0,
    apply_paymoney: 0,
    apply_paytxt: '升级费用',
    up_ordermoney: 0,
    up_rechargemoney: 0,
    up_wxpaymoney: 0,
    up_perpaymoney: 0,
    up_orderprice: 0,
    up_getmembercard: 0,
    up_fxordermoney: 0,
    up_fxorderlevelnum: 0,
    up_fxorderlevelid: [],
    up_fxdowncount: 0,
    up_fxdownlevelnum: 0,
    up_fxdownlevelid: [],
    up_fxdowncount2: 0,
    up_fxdownlevelnum2: 0,
    up_fxdownlevelid2: [],
    up_fxdowncount3: 0,
    up_fxdownlevelnum3: 0,
    up_fxdownlevelid3: [],
    up_proid: [],
    up_pronum: 1,
    up_catid: [],
    up_cat_ordermoney: 0,
    gettj: [],
    can_agent: 0,
    agent_rule: 0,
    commissionset: 0,
    commissiontype: 0,
    commission1: 0,
    commission2: 0,
    commission3: 0,
    commission4: 0,
    commission5: 0,
    commission6: 0,
    commission_parent: 0,
    commission_parent_pj: 0,
    commission_appointlevelid: [],
    commission_max: 0,
    fx_jinsuo: 0,
    score1: 0,
    score2: 0,
    score3: 0,
    scoremax: 0,
    fenhong: 0,
    fenhong_num: 0,
    fenhong_max_money: 0,
    teamfenhonglv: 0,
    teamfenhongbl: 0,
    teamfenhong_money: 0,
    areafenhong: 0,
    areafenhongbl: 0,
    areafenhongmaxnum: 0,
    up_give_score: 0,
    up_give_commission: 0,
    up_give_money: 0,
    up_give_parent_money: 0,
  }
}

// CSV 字符串（逗号分隔ID，0/-1=不限）与数组互转
function csvToIds(s: string): number[] {
  return String(s || '')
    .split(',')
    .map((x) => parseInt(x.trim(), 10))
    .filter((x) => Number.isFinite(x) && x > 0)
}
function idsToCsv(arr: number[]): string {
  return (arr || []).filter((x) => x > 0).join(',')
}

const isEdit = ref(false)
const detailLoading = ref(false)
const activeCollapse = ref<string[]>(['base', 'fenxiao'])
const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive<LevelForm>(buildEmptyForm())

// 等级图标选择器
const iconPickerVisible = ref(false)
function onIconPick(urls: string[]) {
  form.icon = urls[0] || ''
  iconPickerVisible.value = false
}

const rules: FormRules = {
  name: [{ required: true, message: '请输入等级名称', trigger: 'blur' }],
  discount: [
    {
      validator: (_rule, value, callback) => {
        const n = Number(value)
        if (!Number.isFinite(n) || n <= 0 || n > 10) {
          callback(new Error('折扣须为 0-10 之间的数字（10为无折扣）'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
}

function backToList() {
  viewMode.value = 'list'
}

async function openAdd() {
  isEdit.value = false
  activeCollapse.value = ['base', 'fenxiao']
  Object.assign(form, buildEmptyForm())
  form.sort = (tableData.value.length || 0) + 1
  regionRecords.value = []
  viewMode.value = 'form'
}

async function openEdit(row: MemberLevelItem) {
  isEdit.value = true
  activeCollapse.value = ['base', 'fenxiao']
  Object.assign(form, buildEmptyForm())
  viewMode.value = 'form'
  detailLoading.value = true
  try {
    const d = await getMemberLevelDetail(row.id)
    Object.assign(form, buildEmptyForm(), {
      id: d.id,
      name: d.name,
      icon: d.icon,
      sort: d.sort,
      discount: d.discount,
      isdefault: d.isdefault,
      maxnum: d.maxnum,
      can_up: d.can_up,
      can_apply: d.can_apply,
      apply_check: d.apply_check,
      apply_ordermoney: d.apply_ordermoney,
      apply_rechargemoney: d.apply_rechargemoney,
      apply_paymoney: d.apply_paymoney,
      apply_paytxt: d.apply_paytxt || '升级费用',
      up_ordermoney: d.up_ordermoney,
      up_rechargemoney: d.up_rechargemoney,
      up_wxpaymoney: d.up_wxpaymoney,
      up_perpaymoney: d.up_perpaymoney,
      up_orderprice: d.up_orderprice,
      up_getmembercard: d.up_getmembercard,
      up_fxordermoney: d.up_fxordermoney,
      up_fxorderlevelnum: parseInt(d.up_fxorderlevelnum, 10) || 0,
      up_fxorderlevelid: csvToIds(d.up_fxorderlevelid),
      up_fxdowncount: d.up_fxdowncount,
      up_fxdownlevelnum: parseInt(d.up_fxdownlevelnum, 10) || 0,
      up_fxdownlevelid: csvToIds(d.up_fxdownlevelid),
      up_fxdowncount2: d.up_fxdowncount2,
      up_fxdownlevelnum2: parseInt(d.up_fxdownlevelnum2, 10) || 0,
      up_fxdownlevelid2: csvToIds(d.up_fxdownlevelid2),
      up_fxdowncount3: d.up_fxdowncount3,
      up_fxdownlevelnum3: parseInt(d.up_fxdownlevelnum3, 10) || 0,
      up_fxdownlevelid3: csvToIds(d.up_fxdownlevelid3),
      up_proid: csvToIds(d.up_proid),
      up_pronum: parseInt(String(d.up_pronum || '1').split(',')[0], 10) || 1,
      up_catid: csvToIds(d.up_catid),
      up_cat_ordermoney: d.up_cat_ordermoney,
      gettj: csvToIds(d.gettj),
      can_agent: d.can_agent,
      agent_rule: d.agent_rule,
      commissionset: d.commissionset,
      commissiontype: d.commissiontype,
      commission1: d.commission1,
      commission2: d.commission2,
      commission3: d.commission3,
      commission4: d.commission4,
      commission5: d.commission5,
      commission6: d.commission6,
      commission_parent: d.commission_parent,
      commission_parent_pj: d.commission_parent_pj,
      commission_appointlevelid: csvToIds(d.commission_appointlevelid),
      commission_max: d.commission_max,
      fx_jinsuo: d.fx_jinsuo,
      score1: d.score1,
      score2: d.score2,
      score3: d.score3,
      scoremax: d.scoremax,
      fenhong: d.fenhong,
      fenhong_num: d.fenhong_num,
      fenhong_max_money: d.fenhong_max_money,
      teamfenhonglv: d.teamfenhonglv,
      teamfenhongbl: d.teamfenhongbl,
      teamfenhong_money: d.teamfenhong_money,
      areafenhong: d.areafenhong,
      areafenhongbl: d.areafenhongbl,
      areafenhongmaxnum: d.areafenhongmaxnum,
      up_give_score: d.up_give_score,
      up_give_commission: d.up_give_commission,
      up_give_money: d.up_give_money,
      up_give_parent_money: d.up_give_parent_money,
    })
    fetchRegionRecords(row.id)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    detailLoading.value = false
  }
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      const payload: MemberLevelSaveParams = {
        id: form.id,
        name: form.name,
        icon: form.icon,
        sort: form.sort,
        discount: Number(form.discount),
        isdefault: form.isdefault,
        maxnum: form.maxnum || 0,
        can_up: form.can_up,
        can_apply: form.can_apply,
        apply_check: form.apply_check,
        apply_ordermoney: Number(form.apply_ordermoney) || 0,
        apply_rechargemoney: Number(form.apply_rechargemoney) || 0,
        apply_paymoney: Number(form.apply_paymoney) || 0,
        apply_paytxt: form.apply_paytxt || '升级费用',
        up_ordermoney: Number(form.up_ordermoney) || 0,
        up_rechargemoney: Number(form.up_rechargemoney) || 0,
        up_wxpaymoney: Number(form.up_wxpaymoney) || 0,
        up_perpaymoney: Number(form.up_perpaymoney) || 0,
        up_orderprice: Number(form.up_orderprice) || 0,
        up_getmembercard: form.up_getmembercard,
        up_fxordermoney: Number(form.up_fxordermoney) || 0,
        up_fxorderlevelnum: String(form.up_fxorderlevelnum || 0),
        up_fxorderlevelid: form.up_fxorderlevelid.length ? idsToCsv(form.up_fxorderlevelid) : '0',
        up_fxdowncount: Number(form.up_fxdowncount) || 0,
        up_fxdownlevelnum: String(form.up_fxdownlevelnum || 0),
        up_fxdownlevelid: form.up_fxdownlevelid.length ? idsToCsv(form.up_fxdownlevelid) : '0',
        up_fxdowncount2: Number(form.up_fxdowncount2) || 0,
        up_fxdownlevelnum2: String(form.up_fxdownlevelnum2 || 0),
        up_fxdownlevelid2: form.up_fxdownlevelid2.length ? idsToCsv(form.up_fxdownlevelid2) : '0',
        up_fxdowncount3: Number(form.up_fxdowncount3) || 0,
        up_fxdownlevelnum3: String(form.up_fxdownlevelnum3 || 0),
        up_fxdownlevelid3: form.up_fxdownlevelid3.length ? idsToCsv(form.up_fxdownlevelid3) : '0',
        up_proid: idsToCsv(form.up_proid),
        up_pronum: String(form.up_pronum || 1),
        up_catid: idsToCsv(form.up_catid),
        up_cat_ordermoney: Number(form.up_cat_ordermoney) || 0,
        gettj: form.gettj.length ? idsToCsv(form.gettj) : '-1',
        can_agent: form.can_agent,
        agent_rule: form.agent_rule,
        commissionset: form.commissionset,
        commissiontype: form.commissiontype,
        commission1: Number(form.commission1) || 0,
        commission2: Number(form.commission2) || 0,
        commission3: Number(form.commission3) || 0,
        commission4: Number(form.commission4) || 0,
        commission5: Number(form.commission5) || 0,
        commission6: Number(form.commission6) || 0,
        commission_parent: Number(form.commission_parent) || 0,
        commission_parent_pj: Number(form.commission_parent_pj) || 0,
        commission_appointlevelid: idsToCsv(form.commission_appointlevelid),
        commission_max: Number(form.commission_max) || 0,
        fx_jinsuo: form.fx_jinsuo,
        score1: Number(form.score1) || 0,
        score2: Number(form.score2) || 0,
        score3: Number(form.score3) || 0,
        scoremax: Number(form.scoremax) || 0,
        fenhong: Number(form.fenhong) || 0,
        fenhong_num: Number(form.fenhong_num) || 0,
        fenhong_max_money: Number(form.fenhong_max_money) || 0,
        teamfenhonglv: Number(form.teamfenhonglv) || 0,
        teamfenhongbl: Number(form.teamfenhongbl) || 0,
        teamfenhong_money: Number(form.teamfenhong_money) || 0,
        areafenhong: form.areafenhong,
        areafenhongbl: Number(form.areafenhongbl) || 0,
        areafenhongmaxnum: Number(form.areafenhongmaxnum) || 0,
        up_give_score: Number(form.up_give_score) || 0,
        up_give_commission: Number(form.up_give_commission) || 0,
        up_give_money: Number(form.up_give_money) || 0,
        up_give_parent_money: Number(form.up_give_parent_money) || 0,
      }
      await saveMemberLevel(payload)
      ElMessage.success('保存成功')
      viewMode.value = 'list'
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

// ============ 区域代理归属（member_level_record） ============
const regionRecords = ref<MemberLevelRecordItem[]>([])
const regionLoading = ref(false)

async function fetchRegionRecords(levelid: number) {
  regionLoading.value = true
  try {
    const data = await getMemberLevelRecordList(levelid)
    regionRecords.value = data.list || []
  } catch {
    regionRecords.value = []
  } finally {
    regionLoading.value = false
  }
}

async function handleRecordSave(row: MemberLevelRecordItem) {
  try {
    await saveMemberLevelRecord({
      id: row.id,
      areafenhong: row.areafenhong,
      areafenhongbl: Number(row.areafenhongbl) || 0,
      areafenhong_province: row.areafenhong_province,
      areafenhong_city: row.areafenhong_city,
      areafenhong_area: row.areafenhong_area,
    })
    ElMessage.success('区域归属已保存')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 删除 ============
function handleDelete(row: MemberLevelItem) {
  ElMessageBox.confirm(`确定要删除等级「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteMemberLevel(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// 提成单位：0百分比(%) 1固定金额(元)
function commissionUnit(): string {
  return form.commissiontype === 1 ? '元' : '%'
}

// 下级人数条件三组动态字段名
type FxdownCountKey = 'up_fxdowncount' | 'up_fxdowncount2' | 'up_fxdowncount3'
type FxdownNumKey = 'up_fxdownlevelnum' | 'up_fxdownlevelnum2' | 'up_fxdownlevelnum3'
type FxdownIdKey = 'up_fxdownlevelid' | 'up_fxdownlevelid2' | 'up_fxdownlevelid3'
function fxdownCountKey(n: number): FxdownCountKey {
  return n === 2 ? 'up_fxdowncount2' : n === 3 ? 'up_fxdowncount3' : 'up_fxdowncount'
}
function fxdownNumKey(n: number): FxdownNumKey {
  return n === 2 ? 'up_fxdownlevelnum2' : n === 3 ? 'up_fxdownlevelnum3' : 'up_fxdownlevelnum'
}
function fxdownIdKey(n: number): FxdownIdKey {
  return n === 2 ? 'up_fxdownlevelid2' : n === 3 ? 'up_fxdownlevelid3' : 'up_fxdownlevelid'
}
type CommissionKey =
  | 'commission1'
  | 'commission2'
  | 'commission3'
  | 'commission4'
  | 'commission5'
  | 'commission6'
function commissionKey(n: number): CommissionKey {
  return `commission${n}` as CommissionKey
}

function moneyText(v: number): string {
  return `￥${Number(v || 0).toFixed(2)}`
}

onMounted(() => {
  fetchList()
  loadCandidates()
})
</script>

<template>
  <div>
    <!-- ============ 列表 ============ -->
    <el-card v-if="viewMode === 'list'" shadow="never">
      <div class="toolbar">
        <el-button type="success" @click="openAdd">添加</el-button>
      </div>

      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="id" label="ID" width="60" class-name="compact-col" />
        <el-table-column label="等级名称" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.name }}
            <span v-if="row.isdefault === 1" class="default-tag">[默认等级]</span>
          </template>
        </el-table-column>
        <el-table-column prop="applytj" label="申请条件" min-width="150" show-overflow-tooltip />
        <el-table-column label="申请加盟费" width="110" class-name="compact-col">
          <template #default="{ row }">{{ moneyText(row.apply_paymoney) }}</template>
        </el-table-column>
        <el-table-column prop="uptj" label="升级条件" min-width="240" show-overflow-tooltip />
        <el-table-column prop="commission" label="提成比例" min-width="140" show-overflow-tooltip />
        <el-table-column label="操作" width="110" class-name="compact-col">
          <template #default="{ row }">
            <div class="op-wrap">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button v-if="row.isdefault !== 1" link type="danger" @click="handleDelete(row)">
                删除
              </el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- ============ 新增 / 编辑（平铺主区域，右上角 X 关闭） ============ -->
    <div v-else class="goods-edit" v-loading="detailLoading">
      <div class="ge-head">
        <el-button class="ge-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor">
            <path
              d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.157-252.157a32 32 0 0 0-45.248-45.248z"
            />
          </svg>
        </el-button>
      </div>

      <el-form ref="formRef" :model="form" :rules="rules" label-width="170px" label-position="right">
        <el-collapse v-model="activeCollapse" class="level-collapse">
          <!-- ===== 基础设置（默认展开，对齐点大截图） ===== -->
          <el-collapse-item title="基础设置" name="base">
            <el-form-item label="排序">
              <el-input-number v-model="form.sort" :min="0" :max="9999" style="width: 170px" />
              <span class="form-tip">用于升级顺序判断，数字越大等级越高</span>
            </el-form-item>
            <el-form-item label="等级名称" prop="name">
              <el-input v-model="form.name" placeholder="如：普通会员 / 经销商" style="width: 280px" />
            </el-form-item>
            <el-form-item label="等级图标">
              <div class="icon-row">
                <div v-if="form.icon" class="icon-preview">
                  <el-image :src="assetUrl(form.icon)" fit="cover" class="icon-img">
                    <template #error><span class="icon-img-fail">无图</span></template>
                  </el-image>
                  <el-icon class="icon-del" @click="form.icon = ''"><CircleClose /></el-icon>
                </div>
                <el-button @click="iconPickerVisible = true">上传图片</el-button>
                <span class="form-tip">建议 50×50 像素</span>
              </div>
            </el-form-item>
            <el-form-item label="等级折扣" prop="discount">
              <el-input-number
                v-model="form.discount"
                :min="0.01"
                :max="10"
                :step="0.5"
                :precision="2"
                style="width: 170px"
              />
              <span class="form-tip">购买商品时打多少折，10 表示不打折，9.5 表示打 95 折</span>
            </el-form-item>
            <el-form-item label="名额上限">
              <el-input-number v-model="form.maxnum" :min="0" :step="1" style="width: 170px" />
              <span class="form-tip">该等级最多可容纳人数（0=不限制）</span>
            </el-form-item>
            <el-form-item label="默认等级">
              <el-radio-group v-model="form.isdefault">
                <el-radio :value="1">是</el-radio>
                <el-radio :value="0">否</el-radio>
              </el-radio-group>
              <span class="form-tip">新注册会员默认所属等级（仅一个，默认等级不可删除）</span>
            </el-form-item>
          </el-collapse-item>

          <!-- ===== 分销设置（默认展开，对齐点大截图） ===== -->
          <el-collapse-item title="分销设置" name="fenxiao">
            <el-form-item label="分销权限">
              <el-radio-group v-model="form.can_agent">
                <el-radio :value="0">无权限</el-radio>
                <el-radio :value="1">一级分销</el-radio>
                <el-radio :value="2">二级分销</el-radio>
                <el-radio :value="3">三级分销</el-radio>
              </el-radio-group>
              <span class="form-tip-block">开启则表示该等级会员有分销权限，可以发展下级，拿相应提成</span>
            </el-form-item>

            <template v-if="form.can_agent > 0">
              <el-form-item label="提成方式">
                <el-radio-group v-model="form.commissiontype">
                  <el-radio :value="0">百分比</el-radio>
                  <el-radio :value="1">固定金额</el-radio>
                </el-radio-group>
                <span class="form-tip-block">设置固定金额时按单返佣金，和购买数量无关</span>
              </el-form-item>

              <el-form-item label="提成金额">
                <div class="num-grid">
                  <div class="num-cell">
                    <span class="num-label">一级({{ commissionUnit() }})</span>
                    <el-input-number v-model="form.commission1" :min="0" :precision="2" />
                  </div>
                  <div v-if="form.can_agent >= 2" class="num-cell">
                    <span class="num-label">二级({{ commissionUnit() }})</span>
                    <el-input-number v-model="form.commission2" :min="0" :precision="2" />
                  </div>
                  <div v-if="form.can_agent >= 3" class="num-cell">
                    <span class="num-label">三级({{ commissionUnit() }})</span>
                    <el-input-number v-model="form.commission3" :min="0" :precision="2" />
                  </div>
                  <div class="num-cell">
                    <span class="num-label">持续推荐奖励({{ commissionUnit() }})</span>
                    <el-input-number v-model="form.commission_parent" :min="0" :precision="2" />
                  </div>
                </div>
                <span class="form-tip-block">
                  用户直接推荐的是一级，用户的下级推荐的是二级，用户的下级的下级推荐的是三级；<br />
                  *当商家开启独立收款并且开启商家返款或扣除分销佣金时，提成金额比例+平台抽成金额比例不能超过30%（微信支付分账限制）
                </span>
              </el-form-item>

              <el-form-item label="推荐积分">
                <div class="num-grid">
                  <div class="num-cell">
                    <span class="num-label">一级(积分)</span>
                    <el-input-number v-model="form.score1" :min="0" :step="1" :precision="0" />
                  </div>
                  <div v-if="form.can_agent >= 2" class="num-cell">
                    <span class="num-label">二级(积分)</span>
                    <el-input-number v-model="form.score2" :min="0" :step="1" :precision="0" />
                  </div>
                  <div v-if="form.can_agent >= 3" class="num-cell">
                    <span class="num-label">三级(积分)</span>
                    <el-input-number v-model="form.score3" :min="0" :step="1" :precision="0" />
                  </div>
                  <div class="num-cell">
                    <span class="num-label">最高限制(积分)</span>
                    <el-input-number v-model="form.scoremax" :min="0" :step="1" :precision="0" />
                  </div>
                </div>
                <span class="form-tip-block">
                  每推荐一个人奖励多少积分；最高限制指每个人通过推荐获得最高积分数，高于此设置将不再获得推荐积分，0 表示不限制
                </span>
              </el-form-item>
            </template>

            <el-form-item label="推荐规则">
              <el-radio-group v-model="form.agent_rule" class="rule-radio">
                <el-radio :value="0">初次进入即绑定推荐关系</el-radio>
                <el-radio :value="1">有推荐人时绑定推荐关系</el-radio>
                <el-radio :value="2">不绑定推荐关系</el-radio>
                <el-radio :value="3">首次消费后绑定推荐关系</el-radio>
              </el-radio-group>
              <span class="form-tip-block rule-desc">
                初次进入即绑定推荐关系：只有没有进入过平台的新会员才能被推荐，并且初次进入就确定了推荐人，终身绑定，不会再被其他人推荐；<br />
                有推荐人时绑定推荐关系：没有推荐人时可以被推荐，一旦有了推荐人，该会员的推荐人就确定了，不会再被其他人推荐；<br />
                不绑定推荐关系：即使该会员有推荐人，当被另一个人推荐时，TA 的推荐人就会变成后来推荐 TA 的人；<br />
                首次消费后绑定推荐关系：即会员在未消费前，当被另一个人推荐时，TA 的推荐人就会变成后来推荐 TA 的人。
              </span>
            </el-form-item>

            <!-- 更多分销参数（保留表中已有能力） -->
            <el-divider content-position="left">更多分销参数</el-divider>
            <el-form-item label="佣金设置">
              <el-select v-model="form.commissionset" style="width: 220px">
                <el-option :value="0" label="按会员等级" />
                <el-option :value="1" label="按价格比例" />
                <el-option :value="2" label="固定金额" />
                <el-option :value="-1" label="不参与分销" />
              </el-select>
            </el-form-item>
            <el-form-item v-for="n in [4, 5, 6]" :key="`commission-${n}`" :label="`${'四五六'[n - 4]}级提成`">
              <el-input-number
                v-model="form[commissionKey(n)]"
                :min="0"
                :precision="2"
                style="width: 180px"
              />
              <span class="form-tip">{{ commissionUnit() }}（commission{{ n }}）</span>
            </el-form-item>
            <el-form-item label="平级奖">
              <el-input-number v-model="form.commission_parent_pj" :min="0" :precision="2" style="width: 180px" />
              <span class="form-tip">{{ commissionUnit() }}，下级同级时奖励（commission_parent_pj）</span>
            </el-form-item>
            <el-form-item label="指定参与等级">
              <el-select
                v-model="form.commission_appointlevelid"
                multiple
                filterable
                clearable
                placeholder="不选表示不限制"
                style="width: 360px"
              >
                <el-option v-for="lv in tableData" :key="lv.id" :label="lv.name" :value="lv.id" />
              </el-select>
            </el-form-item>
            <el-form-item label="奖励份数上限">
              <el-input-number v-model="form.commission_max" :min="0" :step="1" style="width: 180px" />
              <span class="form-tip">达到奖励上限不再发放佣金，0 表示不限制</span>
            </el-form-item>
            <el-form-item label="紧缩模式">
              <el-radio-group v-model="form.fx_jinsuo">
                <el-radio :value="1">开启</el-radio>
                <el-radio :value="0">关闭</el-radio>
              </el-radio-group>
              <span class="form-tip">上级无分销权限时佣金向上紧缩</span>
            </el-form-item>
          </el-collapse-item>

          <!-- ===== 申请设置（折叠） ===== -->
          <el-collapse-item title="申请设置" name="apply">
            <el-form-item label="申请升级">
              <el-radio-group v-model="form.can_apply">
                <el-radio :value="1">开启</el-radio>
                <el-radio :value="0">关闭</el-radio>
              </el-radio-group>
              <span class="form-tip-block">开启后会员可以主动提交资料申请升为该级别</span>
            </el-form-item>
            <template v-if="form.can_apply === 1">
              <el-form-item label="申请条件">
                <div class="num-grid">
                  <div class="num-cell">
                    <span class="num-label">订单金额满(元)</span>
                    <el-input-number v-model="form.apply_ordermoney" :min="0" :precision="2" />
                  </div>
                  <span class="or-text">或</span>
                  <div class="num-cell">
                    <span class="num-label">充值金额满(元)</span>
                    <el-input-number v-model="form.apply_rechargemoney" :min="0" :precision="2" />
                  </div>
                </div>
                <span class="form-tip-block">用户申请成为该级别需要满足的条件，不填写（0）则不需要申请条件</span>
              </el-form-item>
              <el-form-item label="升级费用">
                <el-input-number v-model="form.apply_paymoney" :min="0" :precision="2" style="width: 180px" />
                <span class="form-tip">元，提交申请时需要交升级费，0 代表免费申请</span>
              </el-form-item>
              <el-form-item label="费用自定义文字">
                <el-input v-model="form.apply_paytxt" placeholder="升级费用" style="width: 200px" />
                <span class="form-tip">默认“升级费用”，列表“申请加盟费”列展示该费用</span>
              </el-form-item>
              <el-form-item label="申请资料需要审核">
                <el-radio-group v-model="form.apply_check">
                  <el-radio :value="1">是</el-radio>
                  <el-radio :value="0">否</el-radio>
                </el-radio-group>
                <span class="form-tip">申请资料是否需要后台审核</span>
              </el-form-item>
            </template>
          </el-collapse-item>

          <!-- ===== 升级条件（折叠） ===== -->
          <el-collapse-item title="升级条件" name="upgrade">
            <el-form-item label="自动升级">
              <el-radio-group v-model="form.can_up">
                <el-radio :value="1">开启</el-radio>
                <el-radio :value="0">关闭</el-radio>
              </el-radio-group>
              <span class="form-tip-block">开启后会员满足下列任一条件（or 关系）自动升级；默认等级无需升级</span>
            </el-form-item>
            <template v-if="form.can_up === 1">
              <el-alert
                type="info"
                :closable="false"
                show-icon
                title="以下条件组内为 or 关系：满足任意一项即可升级（0/空表示不启用该条件）。"
                style="margin-bottom: 14px"
              />
              <el-form-item label="累计订单金额满">
                <el-input-number v-model="form.up_ordermoney" :min="0" :precision="2" style="width: 180px" />
                <span class="form-tip">元（up_ordermoney）</span>
              </el-form-item>
              <el-form-item label="累计充值金额满">
                <el-input-number v-model="form.up_rechargemoney" :min="0" :precision="2" style="width: 180px" />
                <span class="form-tip">元（up_rechargemoney）</span>
              </el-form-item>
              <el-form-item label="累计微信支付满">
                <el-input-number v-model="form.up_wxpaymoney" :min="0" :precision="2" style="width: 180px" />
                <span class="form-tip">元，仅统计微信支付（up_wxpaymoney）</span>
              </el-form-item>
              <el-form-item label="单次消费金额满">
                <el-input-number v-model="form.up_perpaymoney" :min="0" :precision="2" style="width: 180px" />
                <span class="form-tip">元（up_perpaymoney）</span>
              </el-form-item>
              <el-form-item label="单次订单金额满">
                <el-input-number v-model="form.up_orderprice" :min="0" :precision="2" style="width: 180px" />
                <span class="form-tip">元（up_orderprice）</span>
              </el-form-item>
              <el-form-item label="领取会员卡">
                <el-switch v-model="form.up_getmembercard" :active-value="1" :inactive-value="0" />
                <span class="form-tip">领取会员卡后升级（up_getmembercard）</span>
              </el-form-item>
              <el-form-item label="下级订单金额满">
                <el-input-number v-model="form.up_fxordermoney" :min="0" :precision="2" style="width: 180px" />
                <span class="form-tip">元，下级不含自己（up_fxordermoney）</span>
              </el-form-item>
              <el-form-item label="下级订单统计级数">
                <el-input-number v-model="form.up_fxorderlevelnum" :min="0" :step="1" style="width: 180px" />
                <span class="form-tip">统计到下级多少层级（0=不限）</span>
              </el-form-item>
              <el-form-item label="下级订单最低等级">
                <el-select
                  v-model="form.up_fxorderlevelid"
                  multiple
                  filterable
                  clearable
                  placeholder="不选表示不限制等级"
                  style="width: 360px"
                >
                  <el-option v-for="lv in tableData" :key="lv.id" :label="lv.name" :value="lv.id" />
                </el-select>
              </el-form-item>
              <el-form-item
                v-for="(n, idx) in [1, 2, 3]"
                :key="`fxdown-${n}`"
                :label="`下级人数条件${n}`"
              >
                <div class="fxdown-row">
                  <span class="fxdown-label" v-if="idx > 0">且</span>
                  <span class="fxdown-label">人数满</span>
                  <el-input-number
                    v-model="form[fxdownCountKey(n)]"
                    :min="0"
                    :step="1"
                    style="width: 130px"
                  />
                  <span class="fxdown-label">级数</span>
                  <el-input-number
                    v-model="form[fxdownNumKey(n)]"
                    :min="0"
                    :step="1"
                    style="width: 110px"
                  />
                  <span class="fxdown-label">最低等级</span>
                  <el-select
                    v-model="form[fxdownIdKey(n)]"
                    multiple
                    filterable
                    clearable
                    placeholder="不限"
                    style="width: 240px"
                  >
                    <el-option v-for="lv in tableData" :key="lv.id" :label="lv.name" :value="lv.id" />
                  </el-select>
                </div>
                <span class="form-tip-block">
                  {{ idx === 2 ? '三组条件之间为且（and）关系，整组与其他条件为或（or）关系；级数 0=不限层级，等级不选=不限等级' : '' }}
                </span>
              </el-form-item>
              <el-form-item label="购买指定商品">
                <el-select
                  v-model="form.up_proid"
                  multiple
                  filterable
                  clearable
                  placeholder="选择指定商品（不选=不启用）"
                  style="width: 420px"
                >
                  <el-option v-for="g in goodsOptions" :key="g.id" :label="g.title" :value="g.id" />
                </el-select>
              </el-form-item>
              <el-form-item label="购买总数量到达">
                <el-input-number v-model="form.up_pronum" :min="1" :step="1" style="width: 180px" />
                <span class="form-tip">件，购买所选商品累计数量达到该件数即满足（up_pronum）</span>
              </el-form-item>
              <el-form-item label="指定分类订单满">
                <el-select
                  v-model="form.up_catid"
                  multiple
                  filterable
                  clearable
                  placeholder="选择商品分类"
                  style="width: 260px"
                >
                  <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
                </el-select>
                <el-input-number
                  v-model="form.up_cat_ordermoney"
                  :min="0"
                  :precision="2"
                  style="width: 160px; margin-left: 8px"
                />
                <span class="form-tip">元（up_catid + up_cat_ordermoney）</span>
              </el-form-item>
              <el-form-item label="前置等级">
                <el-select
                  v-model="form.gettj"
                  multiple
                  filterable
                  clearable
                  placeholder="不选表示不限制前置等级"
                  style="width: 360px"
                >
                  <el-option v-for="lv in tableData" :key="lv.id" :label="lv.name" :value="lv.id" />
                </el-select>
                <span class="form-tip-block">gettj：须先达到所选任一等级才可升至本等级</span>
              </el-form-item>
            </template>
          </el-collapse-item>

          <!-- ===== 分红设置（折叠） ===== -->
          <el-collapse-item title="分红设置" name="fenhong">
            <div class="group-title">股东分红</div>
            <el-form-item label="分红比例">
              <el-input-number v-model="form.fenhong" :min="0" :max="100" :precision="2" style="width: 180px" />
              <span class="form-tip">%（fenhong，0=不开启）</span>
            </el-form-item>
            <el-form-item label="份数上限">
              <el-input-number v-model="form.fenhong_num" :min="0" :step="1" style="width: 180px" />
              <span class="form-tip">最多分红份数（0=不限制，fenhong_num）</span>
            </el-form-item>
            <el-form-item label="封顶金额">
              <el-input-number v-model="form.fenhong_max_money" :min="0" :precision="2" style="width: 180px" />
              <span class="form-tip">元，累计分红封顶（0=不封顶，fenhong_max_money）</span>
            </el-form-item>
            <div class="group-title">团队分红</div>
            <el-form-item label="分红代数">
              <el-input-number v-model="form.teamfenhonglv" :min="0" :step="1" style="width: 180px" />
              <span class="form-tip">向下分红几代（0=不开启，teamfenhonglv）</span>
            </el-form-item>
            <el-form-item label="比例分红">
              <el-input-number v-model="form.teamfenhongbl" :min="0" :max="100" :precision="2" style="width: 180px" />
              <span class="form-tip">%（teamfenhongbl，与固定额二选一）</span>
            </el-form-item>
            <el-form-item label="固定额分红">
              <el-input-number v-model="form.teamfenhong_money" :min="0" :precision="2" style="width: 180px" />
              <span class="form-tip">元/单（teamfenhong_money，与比例二选一）</span>
            </el-form-item>
            <div class="group-title">区域分红（区域代理）</div>
            <el-form-item label="区域代理类型">
              <el-select v-model="form.areafenhong" style="width: 220px">
                <el-option :value="0" label="不开启" />
                <el-option :value="1" label="省级代理" />
                <el-option :value="2" label="市级代理" />
                <el-option :value="3" label="区县代理" />
              </el-select>
              <span class="form-tip">areafenhong</span>
            </el-form-item>
            <el-form-item label="区域分红比例">
              <el-input-number v-model="form.areafenhongbl" :min="0" :max="100" :precision="2" style="width: 180px" />
              <span class="form-tip">%（areafenhongbl）</span>
            </el-form-item>
            <el-form-item label="区域名额">
              <el-input-number v-model="form.areafenhongmaxnum" :min="0" :step="1" style="width: 180px" />
              <span class="form-tip">每区域最多代理人数（0=不限制，areafenhongmaxnum）</span>
            </el-form-item>
          </el-collapse-item>

          <!-- ===== 升级赠送（折叠） ===== -->
          <el-collapse-item title="升级赠送" name="give">
            <el-form-item label="赠送积分">
              <el-input-number v-model="form.up_give_score" :min="0" :step="1" style="width: 180px" />
              <span class="form-tip">升级赠送会员积分（up_give_score）</span>
            </el-form-item>
            <el-form-item label="赠送佣金">
              <el-input-number v-model="form.up_give_commission" :min="0" :precision="2" style="width: 180px" />
              <span class="form-tip">元（up_give_commission）</span>
            </el-form-item>
            <el-form-item label="赠送余额">
              <el-input-number v-model="form.up_give_money" :min="0" :precision="2" style="width: 180px" />
              <span class="form-tip">元（up_give_money）</span>
            </el-form-item>
            <el-form-item label="给上级赠送余额">
              <el-input-number v-model="form.up_give_parent_money" :min="0" :precision="2" style="width: 180px" />
              <span class="form-tip">元，升级时奖励推荐人（up_give_parent_money）</span>
            </el-form-item>
          </el-collapse-item>

          <!-- ===== 区域代理归属（仅编辑） ===== -->
          <el-collapse-item v-if="isEdit" title="区域代理归属" name="region">
            <el-alert
              type="info"
              :closable="false"
              show-icon
              title="区域代理的省市区归属按会员持有记录（member_level_record）配置；升级时由会员选择归属区域，此处可人工调整。"
              style="margin-bottom: 12px"
            />
            <el-table
              v-loading="regionLoading"
              :data="regionRecords"
              border
              size="small"
              empty-text="该等级暂无区域代理记录"
            >
              <el-table-column prop="id" label="ID" width="60" />
              <el-table-column label="会员" min-width="120" show-overflow-tooltip>
                <template #default="{ row }">
                  {{ row.nickname || row.tel || `#${row.mid}` }}
                </template>
              </el-table-column>
              <el-table-column label="代理开关" width="90">
                <template #default="{ row }">
                  <el-switch v-model="row.areafenhong" :active-value="1" :inactive-value="0" />
                </template>
              </el-table-column>
              <el-table-column label="比例%" width="120">
                <template #default="{ row }">
                  <el-input-number
                    v-model="row.areafenhongbl"
                    :min="0"
                    :max="100"
                    :precision="2"
                    size="small"
                    controls-position="right"
                    style="width: 100px"
                  />
                </template>
              </el-table-column>
              <el-table-column label="省" width="130">
                <template #default="{ row }">
                  <el-input v-model="row.areafenhong_province" size="small" placeholder="省" />
                </template>
              </el-table-column>
              <el-table-column label="市" width="130">
                <template #default="{ row }">
                  <el-input v-model="row.areafenhong_city" size="small" placeholder="市" />
                </template>
              </el-table-column>
              <el-table-column label="区县" width="130">
                <template #default="{ row }">
                  <el-input v-model="row.areafenhong_area" size="small" placeholder="区县" />
                </template>
              </el-table-column>
              <el-table-column label="操作" width="80">
                <template #default="{ row }">
                  <el-button link type="primary" @click="handleRecordSave(row)">保存</el-button>
                </template>
              </el-table-column>
            </el-table>
          </el-collapse-item>
        </el-collapse>

        <div class="form-footer">
          <el-button @click="backToList">取消</el-button>
          <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
        </div>
      </el-form>

      <ImgIconPicker v-model:visible="iconPickerVisible" @pick="onIconPick" />
    </div>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  align-items: center;
  gap: 3px;
  margin-bottom: 8px;
}

.default-tag {
  color: #f56c6c;
  margin-left: 4px;
  font-size: 12px;
}

/* 操作列：link 文字按钮单行排布 */
.op-wrap {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 2px;
  white-space: nowrap;
}

:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}

/* 整页编辑（平铺主区域） */
.goods-edit {
  background: #fff;
  border-radius: 6px;
  padding: 12px 16px 20px;
}
.ge-head {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin-bottom: 8px;
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

.level-collapse :deep(.el-collapse-item__header) {
  font-weight: 600;
  font-size: 14px;
}
.level-collapse :deep(.el-collapse-item__content) {
  padding-bottom: 8px;
}

.form-tip {
  margin-left: 8px;
  font-size: 12px;
  color: #909399;
}
.form-tip-block {
  display: block;
  margin-top: 2px;
  font-size: 12px;
  color: #909399;
  line-height: 1.6;
}
.rule-desc {
  max-width: 760px;
}
.rule-radio {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
}

.group-title {
  margin: 6px 0 12px;
  padding-left: 8px;
  border-left: 3px solid #409eff;
  font-size: 13px;
  font-weight: 600;
  color: #303133;
}

.fxdown-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.fxdown-label {
  font-size: 13px;
  color: #606266;
}

/* 提成/积分 横排数字输入 */
.num-grid {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px 16px;
}
.num-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.num-cell :deep(.el-input-number) {
  width: 150px;
}
.num-label {
  font-size: 12px;
  color: #606266;
}
.or-text {
  color: #909399;
  font-size: 12px;
  align-self: flex-end;
  padding-bottom: 8px;
}

/* 等级图标上传 */
.icon-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.icon-preview {
  position: relative;
  width: 50px;
  height: 50px;
}
.icon-img {
  width: 50px;
  height: 50px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  display: block;
}
.icon-img-fail {
  font-size: 10px;
  color: #c0c4cc;
}
.icon-del {
  position: absolute;
  top: -7px;
  right: -7px;
  color: #909399;
  cursor: pointer;
  background: #fff;
  border-radius: 50%;
}
.icon-del:hover {
  color: #f56c6c;
}

.form-footer {
  display: flex;
  justify-content: center;
  gap: 12px;
  margin-top: 16px;
}
/* 让区块说明在控件下方独占整行 */
.level-collapse :deep(.el-form-item__content) {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  row-gap: 6px;
}
.level-collapse .num-grid,
.level-collapse .fxdown-row {
  width: 100%;
}
.level-collapse .form-tip-block {
  width: 100%;
}
.level-collapse :deep(.el-alert) {
  width: 100%;
}
</style>
