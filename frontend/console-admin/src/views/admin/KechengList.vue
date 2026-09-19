<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
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

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<KechengCourseItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(20)
const keyword = ref('')
const filterStatus = ref<string>('')
const filterCid = ref<number | undefined>(undefined)

const categories = ref<KechengCategoryItem[]>([])
const memberLevels = ref<MemberLevelOption[]>([])

async function fetchOptions() {
  try {
    const [catData, lvData] = await Promise.all([getKechengCategoryList(), getMemberLevelOptions()])
    categories.value = catData.list || []
    memberLevels.value = Array.isArray(lvData) ? lvData : lvData.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
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
    // 错误提示已由 axios 拦截器统一弹出
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
  return ids
    .map((id) => categories.value.find((v) => v.id === id)?.name || '')
    .filter(Boolean)
    .join(' / ') || '-'
}

// ============ 上下架 ============
async function handleStatus(row: KechengCourseItem) {
  const next = row.status === 1 ? 0 : 1
  try {
    await setKechengCourseStatus(row.id, next)
    row.status = next
    ElMessage.success(next === 1 ? '已上架' : '已下架')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 删除 ============
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
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)
const activeTab = ref('base')

interface LevelCommission {
  commission1: number
  commission2: number
  commission3: number
}

const form = reactive({
  id: 0,
  name: '',
  pic: '',
  pics: '',
  cidArr: [] as number[],
  kctype: 4,
  price: 0,
  market_price: 0,
  detail_text: '',
  detail_pics: '',
  sort: 0,
  status: 1,
  join_num: 0,
  // 购买权限 / 显示权限
  gettjMode: '-1',
  gettjLevels: [] as number[],
  gettjurl: '',
  gettjtip: '',
  showtjMode: '-1',
  showtjLevels: [] as number[],
  // 分销
  commissionset: 0,
  commissiondata1: {} as Record<string, LevelCommission>,
  commissiondata2: {} as Record<string, LevelCommission>,
  commissiondata3: {} as Record<string, LevelCommission>,
  // 会员价
  lvprice: 0,
  lvprice_data: {} as Record<string, { money_price: number }>,
  // 答题参数
  sxdate: 0,
  isdt: 1,
  dtnum: 5,
  hgscore: 60,
  // 学习设置
  orderlearn: 0,
  learnhg: 0,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入课程名称', trigger: 'blur' }],
}

function resetForm() {
  Object.assign(form, {
    id: 0,
    name: '',
    pic: '',
    pics: '',
    cidArr: [],
    kctype: 4,
    price: 0,
    market_price: 0,
    detail_text: '',
    detail_pics: '',
    sort: 0,
    status: 1,
    join_num: 0,
    gettjMode: '-1',
    gettjLevels: [],
    gettjurl: '',
    gettjtip: '',
    showtjMode: '-1',
    showtjLevels: [],
    commissionset: 0,
    commissiondata1: {},
    commissiondata2: {},
    commissiondata3: {},
    lvprice: 0,
    lvprice_data: {},
    sxdate: 0,
    isdt: 1,
    dtnum: 5,
    hgscore: 60,
    orderlearn: 0,
    learnhg: 0,
  })
}

function openAdd() {
  isEdit.value = false
  activeTab.value = 'base'
  resetForm()
  dialogVisible.value = true
}

async function openEdit(row: KechengCourseItem) {
  isEdit.value = true
  activeTab.value = 'base'
  resetForm()
  dialogVisible.value = true
  try {
    const d = await getKechengCourseDetail(row.id)
    const gettj = String(d.gettj ?? '-1')
    const showtj = String(d.showtj ?? '-1')
    Object.assign(form, {
      id: d.id,
      name: d.name,
      pic: d.pic,
      pics: d.pics,
      cidArr: String(d.cid || '')
        .split(',')
        .filter((v) => v && v !== '0')
        .map(Number),
      kctype: d.kctype,
      price: Number(d.price),
      market_price: Number(d.market_price),
      detail_text: d.detail_text,
      detail_pics: d.detail_pics,
      sort: d.sort,
      status: d.status,
      join_num: d.join_num,
      gettjMode: gettj === '-1' || gettj === '0' ? gettj : 'level',
      gettjLevels:
        gettj !== '-1' && gettj !== '0' ? gettj.split(',').map(Number) : [],
      gettjurl: d.gettjurl,
      gettjtip: d.gettjtip,
      showtjMode: showtj === '-1' || showtj === '0' ? showtj : 'level',
      showtjLevels:
        showtj !== '-1' && showtj !== '0' ? showtj.split(',').map(Number) : [],
      commissionset: d.commissionset,
      commissiondata1: normalizeCommission(d.commissiondata1),
      commissiondata2: normalizeCommission(d.commissiondata2),
      commissiondata3: normalizeCommission(d.commissiondata3),
      lvprice: d.lvprice,
      lvprice_data: normalizeLvprice(d.lvprice_data),
      sxdate: d.sxdate,
      isdt: d.isdt,
      dtnum: d.dtnum,
      hgscore: d.hgscore,
      orderlearn: d.orderlearn,
      learnhg: d.learnhg,
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function normalizeCommission(
  raw:
    | Record<string, { commission1?: number | string; commission2?: number | string; commission3?: number | string }>
    | undefined,
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

function normalizeLvprice(
  raw: Record<string, { money_price?: number | string }> | undefined,
): Record<string, { money_price: number }> {
  const out: Record<string, { money_price: number }> = {}
  for (const [k, v] of Object.entries(raw || {})) {
    out[k] = { money_price: Number(v?.money_price ?? 0) }
  }
  return out
}

function commissionRow(lvId: number, key: 'commissiondata1' | 'commissiondata2' | 'commissiondata3') {
  const k = String(lvId)
  if (!form[key][k]) {
    form[key][k] = { commission1: 0, commission2: 0, commission3: 0 }
  }
  return form[key][k]
}

function lvpriceRow(lvId: number) {
  const k = String(lvId)
  if (!form.lvprice_data[k]) {
    form.lvprice_data[k] = { money_price: 0 }
  }
  return form.lvprice_data[k]
}

function buildTj(mode: string, levels: number[]) {
  return mode === 'level' ? levels.join(',') : mode
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveKechengCourse({
        id: form.id,
        name: form.name,
        pic: form.pic,
        pics: form.pics,
        cid: form.cidArr.join(','),
        kctype: form.kctype,
        price: form.price,
        market_price: form.market_price,
        detail_text: form.detail_text,
        detail_pics: form.detail_pics,
        sort: form.sort,
        status: form.status,
        join_num: form.join_num,
        gettj: buildTj(form.gettjMode, form.gettjLevels),
        gettjurl: form.gettjurl,
        gettjtip: form.gettjtip,
        showtj: buildTj(form.showtjMode, form.showtjLevels),
        commissionset: form.commissionset,
        commissiondata1: form.commissiondata1,
        commissiondata2: form.commissiondata2,
        commissiondata3: form.commissiondata3,
        lvprice: form.lvprice,
        lvprice_data: form.lvprice_data,
        sxdate: form.sxdate,
        isdt: form.isdt,
        dtnum: form.dtnum,
        hgscore: form.hgscore,
        orderlearn: form.orderlearn,
        learnhg: form.learnhg,
      })
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

const commissionTip = computed(() => {
  switch (form.commissionset) {
    case 1:
      return '按商品比例：佣金 = 实付金额 × 比例%，按上级会员等级分别配置一/二/三级'
    case 2:
      return '固定金额：每成交一单按固定金额返佣，按上级会员等级分别配置一/二/三级'
    case 3:
      return '积分返佣：每成交一单赠送积分，按上级会员等级分别配置一/二/三级'
    default:
      return '按等级：返佣比例/金额在「会员等级」页统一配置，此处无需设置'
  }
})

onMounted(() => {
  fetchOptions()
  fetchList()
})
</script>

<template>
  <div>
    <!-- 工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="keyword"
            placeholder="课程名称"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="分类">
          <el-select v-model="filterCid" placeholder="全部分类" clearable style="width: 150px">
            <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="filterStatus" style="width: 120px">
            <el-option label="全部" value="" />
            <el-option label="上架" :value="1" />
            <el-option label="下架" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">查询</el-button>
        </el-form-item>
        <el-form-item style="float: right">
          <el-button type="success" @click="openAdd">新增课程</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 课程列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="封面" width="90">
          <template #default="{ row }">
            <el-image
              v-if="row.pic"
              :src="row.pic"
              style="width: 48px; height: 48px"
              fit="cover"
            />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="name" label="课程名称" min-width="180" show-overflow-tooltip />
        <el-table-column label="分类" width="140">
          <template #default="{ row }">{{ categoryNames(row.cid) }}</template>
        </el-table-column>
        <el-table-column label="价格" width="100">
          <template #default="{ row }">¥{{ Number(row.price).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="join_num" label="学习人数" width="100" />
        <el-table-column prop="chapter_count" label="章节数" width="90" />
        <el-table-column label="上架" width="80">
          <template #default="{ row }">
            <el-switch
              :model-value="row.status === 1"
              @change="handleStatus(row)"
            />
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        class="pager"
        v-model:current-page="page"
        v-model:page-size="pagesize"
        :total="total"
        :page-sizes="[20, 50, 100]"
        layout="total, sizes, prev, pager, next"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑课程' : '新增课程'" width="760px" top="6vh">
      <el-tabs v-model="activeTab">
        <el-tab-pane label="基本信息" name="base">
          <el-form ref="formRef" :model="form" :rules="rules" label-width="100px" label-position="left">
            <el-form-item label="课程名称" prop="name">
              <el-input v-model="form.name" placeholder="请输入课程名称" />
            </el-form-item>
            <el-form-item label="封面图">
              <el-input v-model="form.pic" placeholder="图片地址" />
            </el-form-item>
            <el-form-item label="轮播图">
              <el-input v-model="form.pics" placeholder="多张图片地址用英文逗号分隔" />
            </el-form-item>
            <el-form-item label="所属分类">
              <el-select v-model="form.cidArr" multiple placeholder="请选择分类" style="width: 100%">
                <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
              </el-select>
            </el-form-item>
            <el-form-item label="课程类型">
              <el-radio-group v-model="form.kctype">
                <el-radio :value="4">综合课程</el-radio>
                <el-radio :value="1">图文</el-radio>
                <el-radio :value="2">音频</el-radio>
                <el-radio :value="3">视频</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="销售价">
              <el-input-number v-model="form.price" :min="0" :precision="2" :step="1" />
            </el-form-item>
            <el-form-item label="市场价">
              <el-input-number v-model="form.market_price" :min="0" :precision="2" :step="1" />
            </el-form-item>
            <el-form-item label="虚拟销量">
              <el-input-number v-model="form.join_num" :min="0" :precision="0" :step="1" />
            </el-form-item>
            <el-form-item label="简介">
              <el-input v-model="form.detail_text" type="textarea" :rows="3" placeholder="课程简介" />
            </el-form-item>
            <el-form-item label="详情图">
              <el-input v-model="form.detail_pics" placeholder="详情图片地址用英文逗号分隔" />
            </el-form-item>
            <el-form-item label="排序">
              <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
            </el-form-item>
            <el-form-item label="上架">
              <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <el-tab-pane label="购买权限" name="gettj">
          <el-form label-width="100px" label-position="left">
            <el-form-item label="购买权限">
              <el-radio-group v-model="form.gettjMode">
                <el-radio value="-1">全部会员</el-radio>
                <el-radio value="0">仅关注会员</el-radio>
                <el-radio value="level">指定等级</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item v-if="form.gettjMode === 'level'" label="可购等级">
              <el-select v-model="form.gettjLevels" multiple placeholder="请选择会员等级" style="width: 100%">
                <el-option v-for="lv in memberLevels" :key="lv.id" :label="lv.name" :value="lv.id" />
              </el-select>
            </el-form-item>
            <el-form-item label="引导链接">
              <el-input v-model="form.gettjurl" placeholder="无权限时跳转链接（可选）" />
            </el-form-item>
            <el-form-item label="提示文案">
              <el-input v-model="form.gettjtip" placeholder="无购买权限提示文案（可选）" />
            </el-form-item>
            <el-form-item label="显示权限">
              <el-radio-group v-model="form.showtjMode">
                <el-radio value="-1">全部可见</el-radio>
                <el-radio value="0">仅关注可见</el-radio>
                <el-radio value="level">指定等级可见</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item v-if="form.showtjMode === 'level'" label="可见等级">
              <el-select v-model="form.showtjLevels" multiple placeholder="请选择会员等级" style="width: 100%">
                <el-option v-for="lv in memberLevels" :key="lv.id" :label="lv.name" :value="lv.id" />
              </el-select>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <el-tab-pane label="分销设置" name="commission">
          <el-form label-width="100px" label-position="left">
            <el-form-item label="分销模式">
              <el-radio-group v-model="form.commissionset">
                <el-radio :value="0">按等级</el-radio>
                <el-radio :value="1">按商品比例</el-radio>
                <el-radio :value="2">固定金额</el-radio>
                <el-radio :value="3">积分返佣</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-alert class="tip" type="info" :closable="false" :title="commissionTip" />
            <el-table v-if="form.commissionset > 0" :data="memberLevels" stripe class="commission-table">
              <el-table-column prop="name" label="会员等级" width="140" />
              <el-table-column :label="form.commissionset === 3 ? '一级积分' : form.commissionset === 2 ? '一级金额' : '一级比例(%)'">
                <template #default="{ row }">
                  <el-input-number
                    v-model="commissionRow(row.id, ['commissiondata1', 'commissiondata2', 'commissiondata3'][form.commissionset] as 'commissiondata1' | 'commissiondata2' | 'commissiondata3').commission1"
                    :min="0"
                    :precision="2"
                    size="small"
                  />
                </template>
              </el-table-column>
              <el-table-column :label="form.commissionset === 3 ? '二级积分' : form.commissionset === 2 ? '二级金额' : '二级比例(%)'">
                <template #default="{ row }">
                  <el-input-number
                    v-model="commissionRow(row.id, ['commissiondata1', 'commissiondata2', 'commissiondata3'][form.commissionset] as 'commissiondata1' | 'commissiondata2' | 'commissiondata3').commission2"
                    :min="0"
                    :precision="2"
                    size="small"
                  />
                </template>
              </el-table-column>
              <el-table-column :label="form.commissionset === 3 ? '三级积分' : form.commissionset === 2 ? '三级金额' : '三级比例(%)'">
                <template #default="{ row }">
                  <el-input-number
                    v-model="commissionRow(row.id, ['commissiondata1', 'commissiondata2', 'commissiondata3'][form.commissionset] as 'commissiondata1' | 'commissiondata2' | 'commissiondata3').commission3"
                    :min="0"
                    :precision="2"
                    size="small"
                  />
                </template>
              </el-table-column>
            </el-table>
          </el-form>
        </el-tab-pane>

        <el-tab-pane label="会员价" name="lvprice">
          <el-form label-width="100px" label-position="left">
            <el-form-item label="启用会员价">
              <el-switch v-model="form.lvprice" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-alert
              v-if="form.lvprice === 1"
              class="tip"
              type="info"
              :closable="false"
              title="按会员等级设置专属价格；未填等级价时按会员等级折扣（会员等级页 kecheng_discount）计算"
            />
            <el-table v-if="form.lvprice === 1" :data="memberLevels" stripe class="commission-table">
              <el-table-column prop="name" label="会员等级" width="140" />
              <el-table-column label="会员价(元)">
                <template #default="{ row }">
                  <el-input-number
                    v-model="lvpriceRow(row.id).money_price"
                    :min="0"
                    :precision="2"
                    size="small"
                  />
                </template>
              </el-table-column>
            </el-table>
          </el-form>
        </el-tab-pane>

        <el-tab-pane label="答题参数" name="dt">
          <el-form label-width="120px" label-position="left">
            <el-alert
              class="tip"
              type="info"
              :closable="false"
              title="章节考试参数：有效期=0 不限；答题开关关闭时章节无需考试；合格分数用于判定章节是否解锁下一章"
            />
            <el-form-item label="考试有效期(天)">
              <el-input-number v-model="form.sxdate" :min="0" :precision="0" :step="1" />
            </el-form-item>
            <el-form-item label="开启答题">
              <el-switch v-model="form.isdt" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="每次题量">
              <el-input-number v-model="form.dtnum" :min="1" :precision="0" :step="1" />
            </el-form-item>
            <el-form-item label="合格分数">
              <el-input-number v-model="form.hgscore" :min="1" :max="100" :precision="0" :step="1" />
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <el-tab-pane label="学习设置" name="learn">
          <el-form label-width="120px" label-position="left">
            <el-form-item label="顺序学习">
              <el-switch v-model="form.orderlearn" :active-value="1" :inactive-value="0" />
              <span class="form-tip">开启后须按章节顺序学习，未学完上一章不能进入下一章</span>
            </el-form-item>
            <el-form-item label="考试解锁">
              <el-switch v-model="form.learnhg" :active-value="1" :inactive-value="0" />
              <span class="form-tip">开启后章节考试合格才能解锁下一章（需配置题库）</span>
            </el-form-item>
          </el-form>
        </el-tab-pane>
      </el-tabs>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}

.tip {
  margin-bottom: 12px;
}

.commission-table {
  width: 100%;
}

.form-tip {
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}
</style>
