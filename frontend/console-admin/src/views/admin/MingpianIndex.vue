<script setup lang="ts">
/**
 * 名片 - 名片列表（对标 canzhao app/view/mingpian/index.html + edit.html）
 * - 顶部一行：左「删除」(勾选批量，未选提示请选择数据) + 「添加」；右 姓名/手机号/昵称 + 搜索图标按钮
 * - 表格列：勾选 / 会员ID / 会员信息(会员头像+昵称) / 姓名信息(个人照片+姓名) / 头衔(1-3拼接)
 *   / 联系信息动态列（mingpian_set.field_list 中 isshow==1 的字段）/ 创建时间 / 操作
 * - 操作列：编辑 / 删除 / 查看链接(二维码+链接+路径) / 查看记录 / 收藏记录
 * - 添加/编辑为多内容页面：不用弹窗，平铺在当前主区域（列表隐藏），右上角 X 关闭返回；
 *   双 tab：基本信息（名片背景700×480、个人照片600×600、姓名、头衔×3、动态联系字段、
 *   地址后经纬度+选择坐标）/ 个人简介（富文本 + 手机预览）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteMingpian,
  deleteMingpianLog,
  getMingpianFavoritelog,
  getMingpianList,
  getMingpianReadlog,
  getMingpianSet,
  saveMingpian,
} from '../../api/mingpian'
import type { MingpianFieldItem, MingpianItem, MingpianLogItem } from '../../api/mingpian'
import { getFreightList } from '../../api/tenantSet'
import type { FreightMapConfig } from '../../api/types'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import GoodsDetailEditor from '../../components/GoodsDetailEditor.vue'
import MapPicker from '../../components/MapPicker.vue'
import LinkAddressModal from '../../components/LinkAddressModal.vue'
import { defaultModule } from './designerModules'
import type { DesignerModule } from '../../api/designer'

// ============ 视图切换（列表 / 添加编辑平铺，口径同文章管理 viewMode） ============
const viewMode = ref<'list' | 'edit'>('list')

// ============ 联系信息字段配置（动态列与编辑表单均由此驱动，口径同 canzhao field_list isshow==1） ============
const fieldList = ref<MingpianFieldItem[]>([])
/** 列表中显示的联系信息列（isshow==1） */
const showFields = computed(() => fieldList.value.filter((f) => Number(f.isshow) === 1))

async function fetchFieldList() {
  try {
    const data = await getMingpianSet()
    fieldList.value = Array.isArray(data.field_list) ? data.field_list : []
  } catch {
    fieldList.value = []
  }
}

// ============ 列表 ============
const loading = ref(false)
const list = ref<MingpianItem[]>([])
const total = ref(0)
const selected = ref<MingpianItem[]>([])
const query = reactive({ page: 1, pagesize: 10, realname: '', tel: '', nickname: '' })

async function fetchList() {
  loading.value = true
  try {
    const res = await getMingpianList({
      page: query.page,
      pagesize: query.pagesize,
      realname: query.realname,
      tel: query.tel,
      nickname: query.nickname,
    })
    list.value = res.list || []
    total.value = res.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function formatTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

/** 头衔列：touxian1-3 顺序拼接（同 canzhao templet 口径） */
function touxianText(row: MingpianItem) {
  return [row.touxian1, row.touxian2, row.touxian3].filter(Boolean).join('') || '-'
}

// ============ 删除（单条 / 勾选批量，口径同 canzhao datadel） ============
function handleDelete(rows: MingpianItem[]) {
  if (!rows.length) {
    ElMessage.warning('请选择数据')
    return
  }
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteMingpian(rows.map((r) => r.id))
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 新增 / 编辑（平铺主区域，基本信息 / 个人简介 双 tab） ============
const isEdit = ref(false)
const activeTab = ref('base')
const submitting = ref(false)

const form = reactive({
  id: 0,
  mid: 0,
  bgpic: '',
  headimg: '',
  realname: '',
  touxian1: '',
  touxian2: '',
  touxian3: '',
  tel: '',
  weixin: '',
  address: '',
  email: '',
  douyin: '',
  weibo: '',
  toutiao: '',
  field1: '',
  field2: '',
  field3: '',
  field4: '',
  field5: '',
  longitude: '',
  latitude: '',
  sharetitle: '',
})
/** 个人简介装修组件数组（同商品详情：JSON 序列化存入 detail 字段） */
const detailModules = ref<DesignerModule[]>([])

/** 动态联系字段双向绑定索引（field_list 的 field 键与表单字段一一对应） */
const formDyn = form as unknown as Record<string, string>

/** 默认个人简介：一个富文本组件（口径同商品详情 defaultDetailModules） */
function defaultDetailModules(): DesignerModule[] {
  return [defaultModule('richtext')]
}

/** 回显 detail：组件数组 JSON 直接用；旧富文本 HTML 兜底包进 richtext 组件（口径同商品详情） */
function parseDetail(raw: string): DesignerModule[] {
  const s = (raw || '').trim()
  if (!s) return defaultDetailModules()
  if (s.startsWith('[')) {
    try {
      const parsed = JSON.parse(s)
      if (Array.isArray(parsed) && parsed.length) return parsed as DesignerModule[]
    } catch {
      // 非合法数组 JSON，按旧富文本兜底
    }
  }
  const rt = defaultModule('richtext')
  rt.content = raw
  return [rt]
}

function resetForm() {
  Object.assign(form, {
    id: 0, mid: 0, bgpic: '', headimg: '', realname: '',
    touxian1: '', touxian2: '', touxian3: '',
    tel: '', weixin: '', address: '', email: '', douyin: '', weibo: '', toutiao: '',
    field1: '', field2: '', field3: '', field4: '', field5: '',
    longitude: '', latitude: '', sharetitle: '',
  })
  detailModules.value = defaultDetailModules()
}

function openAdd() {
  isEdit.value = false
  activeTab.value = 'base'
  resetForm()
  viewMode.value = 'edit'
}

/** 编辑当前用户名片（与添加同页，口径同 canzhao openmax edit） */
function openEdit(row: MingpianItem) {
  isEdit.value = true
  activeTab.value = 'base'
  resetForm()
  Object.assign(form, {
    id: row.id,
    mid: row.mid,
    bgpic: row.bgpic || '',
    headimg: row.headimg || '',
    realname: row.realname || '',
    touxian1: row.touxian1 || '',
    touxian2: row.touxian2 || '',
    touxian3: row.touxian3 || '',
    tel: row.tel || '',
    weixin: row.weixin || '',
    address: row.address || '',
    email: row.email || '',
    douyin: row.douyin || '',
    weibo: row.weibo || '',
    toutiao: row.toutiao || '',
    field1: row.field1 || '',
    field2: row.field2 || '',
    field3: row.field3 || '',
    field4: row.field4 || '',
    field5: row.field5 || '',
    longitude: row.longitude || '',
    latitude: row.latitude || '',
    sharetitle: row.sharetitle || '',
  })
  detailModules.value = parseDetail(row.detail || '')
  viewMode.value = 'edit'
}

/** 右上角 X 关闭：返回名片列表 */
function backToList() {
  viewMode.value = 'list'
  fetchList()
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

// ============ 地图选点（复用系统高德/腾讯 MapPicker；未配置 key 时降级手动输入） ============
const mapConfig = ref<FreightMapConfig>({ map_type: '', map_key: '', map_jscode: '', map_qq_key: '' })
const hasMapKey = computed(() => mapConfig.value.map_type === 'amap' || mapConfig.value.map_type === 'qq')
const mapPickerVisible = ref(false)
function openMapPicker() {
  if (hasMapKey.value) {
    mapPickerVisible.value = true
  } else {
    ElMessage.info('未配置地图 Key，可在平台「系统设置」填写高德或腾讯地图 Key 后使用地图选点，或直接手动输入经纬度')
  }
}
function onMapPick(lng: string, lat: string) {
  form.longitude = lng
  form.latitude = lat
}

// ============ 提交（姓名必填 + 联系信息设置中「必填」的字段校验） ============
async function handleSubmit() {
  if (!form.realname.trim()) {
    ElMessage.warning('请填写姓名')
    activeTab.value = 'base'
    return
  }
  for (const f of showFields.value) {
    if (Number(f.required) === 1 && !String(formDyn[f.field] ?? '').trim()) {
      ElMessage.warning(`请填写${f.name || f.field}`)
      activeTab.value = 'base'
      return
    }
  }
  submitting.value = true
  try {
    // detail 存组件数组 JSON（口径同商品详情）
    await saveMingpian({ ...form, id: form.id || undefined, detail: JSON.stringify(detailModules.value || []) })
    ElMessage.success('保存成功')
    backToList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

// ============ 查看链接（二维码 + 链接 + 路径，复用全站 LinkAddressModal） ============
const linkVisible = ref(false)
const linkPath = ref('')
function openLink(row: MingpianItem) {
  linkPath.value = `/pagesExt/mingpian/index?id=${row.id}`
  linkVisible.value = true
}

// ============ 查看 / 收藏记录弹窗（口径同 canzhao readlog/favoritelog：昵称+时间范围检索） ============
const logVisible = ref(false)
const logType = ref<'readlog' | 'favoritelog'>('readlog')
const logLoading = ref(false)
const logList = ref<MingpianLogItem[]>([])
const logTotal = ref(0)
const logPage = ref(1)
const logNickname = ref('')
const logRange = ref<[string, string] | null>(null)
const currentMp = ref<MingpianItem | null>(null)

function openLog(row: MingpianItem, type: 'readlog' | 'favoritelog') {
  currentMp.value = row
  logType.value = type
  logPage.value = 1
  logNickname.value = ''
  logRange.value = null
  logVisible.value = true
  fetchLog()
}

function handleLogSearch() {
  logPage.value = 1
  fetchLog()
}

async function fetchLog() {
  if (!currentMp.value) return
  logLoading.value = true
  try {
    const params = {
      page: logPage.value,
      pagesize: 10,
      mpid: currentMp.value.id,
      nickname: logNickname.value,
      starttime: logRange.value?.[0] || '',
      endtime: logRange.value?.[1] || '',
    }
    const res = logType.value === 'readlog'
      ? await getMingpianReadlog(params)
      : await getMingpianFavoritelog(params)
    logList.value = res.list || []
    logTotal.value = res.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    logLoading.value = false
  }
}

function handleLogDelete(row: MingpianLogItem) {
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', { type: 'warning' })
    .then(async () => {
      try {
        await deleteMingpianLog(logType.value === 'readlog' ? 'mingpian_readlog' : 'mingpian_favorite', [row.id])
        ElMessage.success('删除成功')
        fetchLog()
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(() => {
  fetchFieldList()
  fetchList()
  // 地图 key 取物流设置 map_config（口径同商户管理）
  getFreightList()
    .then((freight) => {
      if ((freight as { map_config?: FreightMapConfig })?.map_config) {
        mapConfig.value = (freight as { map_config: FreightMapConfig }).map_config
      }
    })
    .catch(() => {
      // 未配置物流地图时保持手动输入降级
    })
})
</script>

<template>
  <div class="mp-page">
    <!-- ============ 列表视图 ============ -->
    <div v-if="viewMode === 'list'">
      <div class="page-title"><span class="title-bar">≡</span>名片列表</div>

      <!-- 工具条：左 删除/添加，右 姓名/手机号/昵称 + 搜索（一行不换行） -->
      <el-card shadow="never" class="toolbar-card">
        <div class="toolbar-left">
          <el-button @click="handleDelete(selected)">删除</el-button>
          <el-button type="success" @click="openAdd">添加</el-button>
        </div>
        <el-form inline class="toolbar-search" @submit.prevent>
          <el-form-item label="姓名">
            <el-input v-model="query.realname" clearable style="width: 110px" @keyup.enter="handleSearch" @clear="handleSearch" />
          </el-form-item>
          <el-form-item label="手机号">
            <el-input v-model="query.tel" clearable style="width: 110px" @keyup.enter="handleSearch" @clear="handleSearch" />
          </el-form-item>
          <el-form-item label="昵称">
            <el-input v-model="query.nickname" clearable style="width: 110px" @keyup.enter="handleSearch" @clear="handleSearch" />
          </el-form-item>
          <el-form-item>
            <el-button type="success" class="search-btn" @click="handleSearch">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
            </el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <!-- 表格 -->
      <el-card shadow="never">
        <el-table
          v-loading="loading"
          :data="list"
          border
          :header-cell-style="{ background: '#e6e6e6' }"
          @selection-change="(rows: MingpianItem[]) => (selected = rows)"
        >
          <el-table-column type="selection" width="45" />
          <el-table-column prop="mid" label="会员ID" width="75" align="left" header-align="left" />
          <el-table-column label="会员信息" min-width="150" align="left" header-align="left">
            <template #default="{ row }">
              <div class="member-cell">
                <el-avatar v-if="row.member_headimg" :src="row.member_headimg" :size="34" shape="square" />
                <span>{{ row.nickname || '-' }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="姓名信息" min-width="150" align="left" header-align="left">
            <template #default="{ row }">
              <div class="member-cell">
                <el-avatar v-if="row.headimg" :src="row.headimg" :size="34" shape="square" />
                <span>{{ row.realname || '-' }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="头衔" min-width="120" align="left" header-align="left">
            <template #default="{ row }">{{ touxianText(row) }}</template>
          </el-table-column>
          <!-- 联系信息动态列：名片设置 field_list 中 isshow==1 的字段 -->
          <el-table-column
            v-for="f in showFields"
            :key="f.field"
            :label="f.name || f.field"
            min-width="110"
            align="left"
            header-align="left"
          >
            <template #default="{ row }">{{ row[f.field as keyof MingpianItem] || '-' }}</template>
          </el-table-column>
          <el-table-column label="创建时间" width="140" align="left" header-align="left">
            <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="240" align="left" header-align="left" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
              <el-button link type="primary" @click="openLink(row)">查看链接</el-button>
              <el-button link type="primary" @click="openLog(row, 'readlog')">查看记录</el-button>
              <el-button link type="primary" @click="openLog(row, 'favoritelog')">收藏记录</el-button>
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
          @change="fetchList"
        />
      </el-card>
    </div>

    <!-- ============ 添加 / 编辑名片（平铺主区域，右上角 X 关闭） ============ -->
    <div v-else class="edit-panel">
      <div class="edit-head">
        <el-tabs v-model="activeTab" class="edit-tabs">
          <el-tab-pane label="基本信息" name="base" />
          <el-tab-pane label="个人简介" name="detail" />
        </el-tabs>
        <el-button class="edit-close" circle title="关闭" @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>

      <!-- ===== 基本信息 ===== -->
      <div v-show="activeTab === 'base'">
        <el-form :model="form" label-width="90px" label-position="left" class="mp-form">
          <el-form-item label="名片背景">
            <div class="upload-line">
              <el-image v-if="form.bgpic" :src="form.bgpic" class="thumb-bg" fit="cover" />
              <el-button size="small" @click="openImgPicker((u) => (form.bgpic = u))">{{ form.bgpic ? '更换' : '上传图片' }}</el-button>
              <span class="form-tip">建议尺寸：700×480像素</span>
            </div>
          </el-form-item>
          <el-form-item label="个人照片">
            <div class="upload-line">
              <el-image v-if="form.headimg" :src="form.headimg" class="thumb-head" fit="cover" />
              <el-button size="small" @click="openImgPicker((u) => (form.headimg = u))">{{ form.headimg ? '更换' : '上传图片' }}</el-button>
              <span class="form-tip">建议尺寸：600×600像素</span>
            </div>
          </el-form-item>
          <el-form-item label="姓名" required>
            <el-input v-model="form.realname" placeholder="请输入姓名" maxlength="30" style="width: 260px" />
          </el-form-item>
          <el-form-item label="头衔">
            <div class="touxian-line">
              <el-input v-model="form.touxian1" placeholder="头衔1" maxlength="50" />
              <el-input v-model="form.touxian2" placeholder="头衔2" maxlength="50" />
              <el-input v-model="form.touxian3" placeholder="头衔3" maxlength="50" />
            </div>
          </el-form-item>
          <!-- 联系信息动态字段（field_list isshow==1；address 后跟经纬度+选择坐标，口径同 canzhao edit） -->
          <template v-for="f in showFields" :key="f.field">
            <el-form-item :label="f.name || f.field" :required="Number(f.required) === 1">
              <el-input v-model="formDyn[f.field]" :placeholder="f.name || f.field" maxlength="200" style="width: 320px" />
            </el-form-item>
            <el-form-item v-if="f.field === 'address'" label="经纬度">
              <div class="lnglat-line">
                <el-input v-model="form.longitude" placeholder="经度" style="width: 150px" />
                <span class="lnglat-sep">-</span>
                <el-input v-model="form.latitude" placeholder="纬度" style="width: 150px" />
                <el-button @click="openMapPicker">选择坐标</el-button>
              </div>
            </el-form-item>
          </template>
          <el-form-item>
            <el-button type="primary" @click="activeTab = 'detail'">下一步:编辑个人简介</el-button>
            <el-button type="danger" :loading="submitting" @click="handleSubmit">提 交</el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- ===== 个人简介（组件装修：左手机预览、右上组件库+下方编辑区，口径同商品详情） ===== -->
      <div v-show="activeTab === 'detail'">
        <GoodsDetailEditor v-model="detailModules" title="个人简介" />
        <div class="tab-footer">
          <el-button type="primary" @click="activeTab = 'base'">上一步:编辑基本信息</el-button>
          <el-button type="danger" :loading="submitting" @click="handleSubmit">提 交</el-button>
        </div>
      </div>
    </div>

    <!-- 查看 / 收藏记录弹窗 -->
    <el-dialog
      v-model="logVisible"
      :title="(logType === 'readlog' ? '查看记录' : '收藏记录') + (currentMp?.realname ? ' - ' + currentMp.realname : '')"
      width="800px"
    >
      <div class="log-toolbar">
        <el-form inline @submit.prevent>
          <el-form-item label="昵称">
            <el-input v-model="logNickname" clearable style="width: 130px" @keyup.enter="handleLogSearch" @clear="handleLogSearch" />
          </el-form-item>
          <el-form-item label="时间">
            <el-date-picker
              v-model="logRange"
              type="daterange"
              range-separator="~"
              start-placeholder="开始日期"
              end-placeholder="结束日期"
              value-format="YYYY-MM-DD"
              style="width: 240px"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="success" class="search-btn" @click="handleLogSearch">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
            </el-button>
          </el-form-item>
        </el-form>
      </div>
      <el-table v-loading="logLoading" :data="logList" border :header-cell-style="{ background: '#e6e6e6' }">
        <el-table-column prop="mid" label="ID" width="80" align="left" header-align="left" />
        <el-table-column label="头像" width="90" align="left" header-align="left">
          <template #default="{ row }">
            <el-avatar v-if="row.headimg" :src="row.headimg" :size="36" shape="square" />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="nickname" label="昵称" min-width="140" align="left" header-align="left">
          <template #default="{ row }">{{ row.nickname || '-' }}</template>
        </el-table-column>
        <el-table-column :label="logType === 'readlog' ? '查看时间' : '收藏时间'" width="150" align="left" header-align="left">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="90" align="left" header-align="left">
          <template #default="{ row }">
            <el-button link type="danger" @click="handleLogDelete(row)">删除</el-button>
          </template>
        </el-table-column>
        <template #empty>暂无数据</template>
      </el-table>
      <el-pagination
        v-model:current-page="logPage"
        :page-size="10"
        :total="logTotal"
        layout="total, prev, pager, next"
        class="pagination"
        @current-change="fetchLog"
      />
    </el-dialog>

    <!-- 查看链接（二维码 + 链接 + 路径） -->
    <LinkAddressModal v-model:visible="linkVisible" :path="linkPath" />

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
    <MapPicker
      v-model:visible="mapPickerVisible"
      :map-key="mapConfig.map_key"
      :map-jscode="mapConfig.map_jscode"
      :map-qq-key="mapConfig.map_qq_key"
      :init-lng="form.longitude"
      :init-lat="form.latitude"
      @pick="onMapPick"
    />
  </div>
</template>

<style scoped>
.mp-page {
  padding: 3px;
}
.page-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding: 4px 2px 8px;
}
.title-bar {
  margin-right: 4px;
}
.toolbar-card {
  margin-bottom: 3px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 8px 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 3px;
  flex-wrap: nowrap;
}
.toolbar-left {
  display: flex;
  gap: 3px;
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
  margin: 0;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 3px;
}
.toolbar-search :deep(.el-form-item:last-child) {
  margin-right: 0;
}
.toolbar-search :deep(.el-form-item__label) {
  padding-right: 2px;
}
.search-btn {
  padding: 8px 12px;
}
.search-btn svg {
  display: block;
}
.pagination {
  margin-top: 10px;
  justify-content: flex-end;
}
.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}
/* ===== 平铺编辑面板 ===== */
.edit-panel {
  background: #fff;
  border: 1px solid #e6e6e6;
  padding: 8px 20px 20px;
}
.edit-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #f0f0f0;
  margin-bottom: 16px;
}
.edit-tabs {
  flex: 1;
}
.edit-tabs :deep(.el-tabs__header) {
  margin-bottom: 0;
}
.edit-close {
  border: none;
  background: transparent;
  color: #909399;
}
.edit-close:hover {
  color: #409eff;
  background: transparent;
}
.mp-form {
  max-width: 900px;
}
.mp-form :deep(.el-form-item) {
  margin-bottom: 14px;
}
.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
.upload-line {
  display: flex;
  align-items: center;
  gap: 8px;
}
.thumb-bg {
  width: 100px;
  height: 71px;
  border-radius: 4px;
  border: 1px solid var(--el-border-color-lighter);
}
.thumb-head {
  width: 71px;
  height: 71px;
  border-radius: 4px;
  border: 1px solid var(--el-border-color-lighter);
}
.touxian-line {
  display: flex;
  gap: 3px;
  width: 100%;
  max-width: 660px;
}
.lnglat-line {
  display: flex;
  align-items: center;
  gap: 3px;
}
.lnglat-sep {
  color: #909399;
}
.tab-footer {
  margin-top: 14px;
}
.log-toolbar {
  margin-bottom: 3px;
}
.log-toolbar :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 3px;
}
</style>
