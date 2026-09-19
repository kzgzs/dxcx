<script setup lang="ts">
/**
 * 设计 - 内页导航（designer_menu2，任务280 对照两张截图重做）
 * - 列表：标题+说明条；添加/删除/开启/关闭（批量作用于勾选项）；右侧筛选（页面备注/页面路径/类型/状态+搜索）
 *   表格列：勾选/ID/类型/所属页面/页面备注/创建时间/状态(开启绿字)/操作(复制/编辑/删除)；分页条
 * - 添加/编辑：顶栏「+ 添加导航 / + 编辑导航」右上 X 回列表；左矮手机框实时渲染底部菜单（名称+图标+各自颜色）
 *   右表单：类型单选多行；所属页面+选择链接+灰字提示；页面备注；菜单背景色；菜单个数 两个~五个；
 *   每菜单：名称(菜单二起上移)/链接+选择链接/颜色+色板/图标选择+预览；当页保存不跳转
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import {
  copyMenu2,
  delMenu2,
  getMenu2Detail,
  getMenu2List,
  saveMenu2,
  setstMenu2,
  type Menu2Item,
  type Menu2Row,
} from '../../api/designer'
import LinkPicker from '../../components/LinkPicker.vue'
import NavIcon from '../../components/NavIcon.vue'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { NAV_ICON_MAP } from '../../components/navIcons'

/** 类型单选（对照截图全名；value 沿用既有 platform 口径） */
const PLATFORM_OPTIONS = [
  { value: 'all', label: '全部' },
  { value: 'mp', label: '微信公众号' },
  { value: 'wx', label: '微信小程序' },
  { value: 'alipay', label: '支付宝小程序' },
  { value: 'baidu', label: '百度小程序' },
  { value: 'toutiao', label: '抖音小程序' },
  { value: 'qq', label: 'QQ小程序' },
  { value: 'h5', label: '手机H5' },
  { value: 'app', label: '手机APP' },
]

function platformName(key: string): string {
  return PLATFORM_OPTIONS.find((p) => p.value === key)?.label || key || '全部'
}

const CN_NUM = ['一', '二', '三', '四', '五']
const COUNT_OPTIONS = [
  { value: 2, label: '两个' },
  { value: 3, label: '三个' },
  { value: 4, label: '四个' },
  { value: 5, label: '五个' },
]

// ============ 视图切换（list / edit） ============
const view = ref<'list' | 'edit'>('list')

// ============ 列表 ============
const loading = ref(false)
const list = ref<Menu2Row[]>([])
const total = ref(0)
const page = ref(1)
const limit = ref(10)
const checked = ref<Menu2Row[]>([])
const filter = reactive({ indexurlname: '', indexurl: '', platform: '', status: '' as number | '' })

async function fetchList() {
  loading.value = true
  try {
    const data = await getMenu2List({ page: page.value, limit: limit.value, ...filter })
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

function requireChecked(): number[] {
  if (!checked.value.length) {
    ElMessage.warning('请先勾选操作项')
    return []
  }
  return checked.value.map((r) => r.id)
}

async function handleBatchDel() {
  const ids = requireChecked()
  if (!ids.length) return
  try {
    await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 条内页导航？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await delMenu2(ids)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 拦截器已提示
  }
}

async function handleBatchSt(st: number) {
  const ids = requireChecked()
  if (!ids.length) return
  try {
    await setstMenu2(ids, st)
    ElMessage.success(st ? '已开启' : '已关闭')
    fetchList()
  } catch {
    // 拦截器已提示
  }
}

async function handleCopy(row: Menu2Row) {
  try {
    await copyMenu2(row.id)
    ElMessage.success('复制成功')
    fetchList()
  } catch {
    // 拦截器已提示
  }
}

async function handleDel(row: Menu2Row) {
  try {
    await ElMessageBox.confirm(`确定删除内页导航「${row.indexurlname}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await delMenu2([row.id])
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 拦截器已提示
  }
}

// ============ 添加 / 编辑 ============
const saving = ref(false)
const form = reactive({
  id: 0,
  indexurlname: '',
  indexurl: '',
  platform: 'all',
  backgroundColor: '#ffffff',
  status: 1,
  menudata: [] as Menu2Item[],
})

function defaultItem(idx: number): Menu2Item {
  return { text: `菜单${CN_NUM[idx] ?? idx + 1}`, iconPath: '', pagePath: '', color: '' }
}

const menuCount = computed({
  get: () => form.menudata.length,
  set: (n: number) => {
    while (form.menudata.length > n) form.menudata.pop()
    while (form.menudata.length < n) form.menudata.push(defaultItem(form.menudata.length))
  },
})

function handleAdd() {
  Object.assign(form, {
    id: 0,
    indexurlname: '',
    indexurl: '',
    platform: 'all',
    backgroundColor: '#ffffff',
    status: 1,
    menudata: [defaultItem(0), defaultItem(1), defaultItem(2)],
  })
  view.value = 'edit'
}

async function handleEdit(row: Menu2Row) {
  try {
    const info = await getMenu2Detail(row.id)
    Object.assign(form, {
      id: info.id,
      indexurlname: info.indexurlname || '',
      indexurl: info.indexurl || '',
      platform: info.platform || 'all',
      backgroundColor: info.backgroundColor || '#ffffff',
      status: Number(info.status ?? 1),
      menudata: (info.menudata || []).map((it) => ({ ...defaultItem(0), ...it, color: it.color ?? '' })),
    })
    if (!form.menudata.length) form.menudata = [defaultItem(0)]
    view.value = 'edit'
  } catch {
    // 拦截器已提示
  }
}

function closeEdit() {
  view.value = 'list'
  fetchList()
}

function moveUp(idx: number) {
  if (idx <= 0 || idx >= form.menudata.length) return
  const tmp = form.menudata[idx - 1]
  form.menudata[idx - 1] = form.menudata[idx]
  form.menudata[idx] = tmp
}

// ============ 链接 / 图标选择 ============
function onPagePick(url: string, name: string) {
  form.indexurl = url
  if (name && !form.indexurlname) form.indexurlname = name
}

const iconPickerVisible = ref(false)
const iconTarget = ref<Menu2Item | null>(null)

function openIconPicker(item: Menu2Item) {
  iconTarget.value = item
  iconPickerVisible.value = true
}

/** 任务292：回填 URL；兼容层：存量内置 key 仍按 key 渲染（见模板 NAV_ICON_MAP 判断） */
function onIconPick(urls: string[]) {
  if (iconTarget.value && urls[0]) iconTarget.value.iconPath = urls[0]
  iconTarget.value = null
}

// ============ 保存（当页保存不跳转） ============
async function handleSave() {
  if (!form.indexurlname.trim()) {
    ElMessage.warning('请填写页面备注')
    return
  }
  if (!form.indexurl.trim()) {
    ElMessage.warning('请选择所属页面')
    return
  }
  for (const [i, it] of form.menudata.entries()) {
    if (!it.text.trim()) {
      ElMessage.warning(`菜单${CN_NUM[i]}未填写名称`)
      return
    }
  }
  saving.value = true
  try {
    const res = await saveMenu2({
      id: form.id || undefined,
      indexurlname: form.indexurlname,
      indexurl: form.indexurl,
      platform: form.platform,
      backgroundColor: form.backgroundColor,
      status: form.status,
      menudata: JSON.parse(JSON.stringify(form.menudata)),
    })
    if (form.id === 0 && res?.id) form.id = res.id
    ElMessage.success('保存成功')
  } catch {
    // 拦截器已提示
  } finally {
    saving.value = false
  }
}

onMounted(fetchList)
</script>

<template>
  <div class="m2-root">
    <!-- ============ 列表视图 ============ -->
    <template v-if="view === 'list'">
      <div class="m2-title">内页导航</div>
      <div class="m2-desc">用于设置非导航页面的自定义菜单，即未配置在底部导航内的页面</div>

      <div class="m2-toolbar">
        <div class="m2-toolbar-left">
          <el-button type="primary" @click="handleAdd">添加</el-button>
          <el-button @click="handleBatchDel">删除</el-button>
          <el-button @click="handleBatchSt(1)">开启</el-button>
          <el-button @click="handleBatchSt(0)">关闭</el-button>
        </div>
        <div class="m2-toolbar-right">
          <span class="m2-filter-label">页面备注</span>
          <el-input v-model="filter.indexurlname" clearable style="width: 140px" @keyup.enter="handleSearch" />
          <span class="m2-filter-label">页面路径</span>
          <el-input v-model="filter.indexurl" clearable style="width: 140px" @keyup.enter="handleSearch" />
          <span class="m2-filter-label">类型</span>
          <el-select v-model="filter.platform" clearable placeholder="请选择" style="width: 120px">
            <el-option v-for="p in PLATFORM_OPTIONS" :key="p.value" :label="p.label" :value="p.value" />
          </el-select>
          <span class="m2-filter-label">状态</span>
          <el-select v-model="filter.status" clearable placeholder="全部" style="width: 100px">
            <el-option label="开启" :value="1" />
            <el-option label="关闭" :value="0" />
          </el-select>
          <el-button type="primary" :icon="Search" @click="handleSearch" />
        </div>
      </div>

      <el-table v-loading="loading" :data="list" @selection-change="(rows: Menu2Row[]) => (checked = rows)">
        <el-table-column type="selection" width="46" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="类型" width="110">
          <template #default="{ row }">{{ platformName(row.platform) }}</template>
        </el-table-column>
        <el-table-column prop="indexurl" label="所属页面" min-width="220" show-overflow-tooltip />
        <el-table-column prop="indexurlname" label="页面备注" min-width="180" show-overflow-tooltip />
        <el-table-column prop="create_time" label="创建时间" width="150" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'm2-st-on' : 'm2-st-off'">{{ row.status === 1 ? '开启' : '关闭' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <div class="m2-ops">
              <el-button size="small" @click="handleCopy(row)">复制</el-button>
              <el-button size="small" @click="handleEdit(row)">编辑</el-button>
              <el-button size="small" @click="handleDel(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-model:current-page="page"
        v-model:page-size="limit"
        :total="total"
        :page-sizes="[10, 20, 50, 100]"
        layout="prev, pager, next, jumper, total, sizes"
        class="m2-pager"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </template>

    <!-- ============ 添加 / 编辑视图 ============ -->
    <template v-else>
      <div class="m2-edit-bar">
        <span class="m2-edit-title">+ {{ form.id ? '编辑导航' : '添加导航' }}</span>
        <span class="m2-edit-close" title="关闭" @click="closeEdit">&times;</span>
      </div>

      <div class="m2-edit-layout">
        <!-- 左：矮手机框实时预览 -->
        <div class="m2-phone">
          <div class="m2-phone-screen">
            <div class="m2-phone-status">
              <span class="m2-status-side">wechat</span>
              <span>12:00</span>
              <span class="m2-status-side">100%</span>
            </div>
            <div class="m2-phone-title">页面标题</div>
            <div class="m2-phone-content">
              <span class="m2-content-tip">内容显示区域</span>
              <span class="m2-content-note">提示：点击右侧菜单名称后的上移进行排序</span>
            </div>
            <div class="m2-tabbar" :style="{ background: form.backgroundColor }">
              <div v-for="(item, idx) in form.menudata" :key="idx" class="m2-tab" :style="{ color: item.color || '#606266' }">
                <NavIcon v-if="item.iconPath && NAV_ICON_MAP[item.iconPath]" :name="item.iconPath" :size="22" />
                <img v-else-if="item.iconPath" :src="item.iconPath" class="m2-tab-img" />
                <span v-else class="m2-tab-fallback">{{ (item.text || '菜').charAt(0) }}</span>
                <span class="m2-tab-text">{{ item.text || '菜单' }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- 右：配置表单 -->
        <div class="m2-form">
          <el-form label-width="100px" label-position="left">
            <el-form-item label="类型">
              <el-radio-group v-model="form.platform" class="m2-platform-group">
                <el-radio v-for="p in PLATFORM_OPTIONS" :key="p.value" :value="p.value">{{ p.label }}</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="所属页面">
              <LinkPicker v-model="form.indexurl" pick-text="选择链接" style="width: 320px" @pick="onPagePick" />
              <span class="m2-form-tip">在哪个页面展示该导航菜单</span>
            </el-form-item>
            <el-form-item label="页面备注">
              <el-input v-model="form.indexurlname" style="width: 240px" />
            </el-form-item>
            <el-form-item label="菜单背景色">
              <el-input v-model="form.backgroundColor" style="width: 160px" />
              <el-color-picker v-model="form.backgroundColor" />
            </el-form-item>
            <el-form-item label="菜单个数">
              <el-radio-group v-model="menuCount">
                <el-radio v-for="opt in COUNT_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</el-radio>
              </el-radio-group>
            </el-form-item>

            <template v-for="(item, idx) in form.menudata" :key="idx">
              <el-form-item :label="`菜单${CN_NUM[idx]}名称`" class="m2-menu-label">
                <el-input v-model="item.text" maxlength="8" style="width: 200px" />
                <el-button v-if="idx > 0" class="m2-inline-btn" @click="moveUp(idx)">上移</el-button>
              </el-form-item>
              <el-form-item label="链接">
                <LinkPicker v-model="item.pagePath" pick-text="选择链接" style="width: 320px" />
              </el-form-item>
              <el-form-item label="颜色">
                <el-input v-model="item.color" style="width: 200px" placeholder="如 #1C2438" />
                <el-color-picker v-model="item.color" />
              </el-form-item>
              <el-form-item label="图标">
                <el-button class="m2-icon-btn" @click="openIconPicker(item)">选择图标</el-button>
                <span class="m2-icon-preview" :style="{ color: item.color || '#909399' }">
                  <NavIcon v-if="item.iconPath && NAV_ICON_MAP[item.iconPath]" :name="item.iconPath" :size="26" />
                  <img v-else-if="item.iconPath" :src="item.iconPath" class="m2-icon-img" />
                  <span v-else class="m2-icon-empty">—</span>
                </span>
              </el-form-item>
            </template>

            <el-form-item>
              <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
            </el-form-item>
          </el-form>
        </div>
      </div>

      <ImgIconPicker v-model:visible="iconPickerVisible" icon-only @pick="(urls: string[]) => onIconPick(urls)" />
    </template>
  </div>
</template>

<style scoped>
.m2-root {
  padding: 0 8px;
}

/* ============ 列表 ============ */
.m2-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 10px;
}

.m2-desc {
  background: #f4f4f4;
  border-left: 4px solid #67c23a;
  padding: 10px 14px;
  font-size: 13px;
  color: #606266;
  margin-bottom: 14px;
}

.m2-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

.m2-toolbar-left {
  display: flex;
  gap: 8px;
}

.m2-toolbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.m2-filter-label {
  font-size: 13px;
  color: #606266;
}

.m2-st-on {
  color: #67c23a;
}

.m2-st-off {
  color: #909399;
}

.m2-ops {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.m2-pager {
  margin-top: 14px;
}

/* ============ 编辑 ============ */
.m2-edit-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 2px 12px;
  border-bottom: 1px solid var(--el-border-color-lighter);
  margin-bottom: 16px;
}

.m2-edit-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
}

.m2-edit-close {
  font-size: 20px;
  line-height: 1;
  color: #606266;
  cursor: pointer;
  padding: 4px 8px;
}

.m2-edit-close:hover {
  color: #303133;
}

.m2-edit-layout {
  display: flex;
  gap: 32px;
  align-items: flex-start;
}

/* 左侧矮手机框（同任务279口径） */
.m2-phone {
  flex-shrink: 0;
}

.m2-phone-screen {
  width: 300px;
  height: 500px;
  border: 8px solid #2c2c2c;
  border-radius: 28px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  background: #ffffff;
}

.m2-phone-status {
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 12px;
  font-size: 11px;
  color: #ffffff;
  background: #1f1f1f;
}

.m2-status-side {
  min-width: 34px;
}

.m2-phone-title {
  height: 34px;
  line-height: 34px;
  text-align: center;
  font-size: 14px;
  color: #ffffff;
  background: #1f1f1f;
  border-top: 1px solid #333333;
}

.m2-phone-content {
  flex: 1;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #ececec;
}

.m2-content-tip {
  color: #c0c4cc;
  font-size: 15px;
}

.m2-content-note {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 8px;
  text-align: center;
  font-size: 12px;
  color: #f56c6c;
}

.m2-tabbar {
  display: flex;
  border-top: 1px solid #e5e5e5;
  padding: 6px 0 10px;
}

.m2-tab {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.m2-tab-img {
  width: 22px;
  height: 22px;
  object-fit: contain;
}

.m2-tab-fallback {
  width: 22px;
  height: 22px;
  border: 1px solid currentColor;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
}

.m2-tab-text {
  font-size: 11px;
  line-height: 1.2;
}

/* 右侧表单 */
.m2-form {
  flex: 1;
  min-width: 0;
  max-width: 760px;
}

.m2-platform-group {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 0;
  max-width: 460px;
}

.m2-form-tip {
  margin-left: 10px;
  font-size: 13px;
  color: #909399;
}

.m2-menu-label {
  margin-top: 6px;
}

.m2-inline-btn {
  margin-left: 10px;
}

.m2-icon-btn {
  margin-right: 8px;
}

.m2-icon-preview {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  margin-left: 8px;
}

.m2-icon-img {
  width: 26px;
  height: 26px;
  object-fit: contain;
}

.m2-icon-empty {
  color: #c0c4cc;
}
</style>
