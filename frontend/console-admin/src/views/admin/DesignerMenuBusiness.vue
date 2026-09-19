<script setup lang="ts">
/**
 * 商户 - 多商户默认底部导航（designer_menu_business）
 * 对照参考系统 designer_menu/business.html：
 * - 八端 Tab 各自独立配置（后端按 platform 分行存储，tongbu=1 保存时同步覆盖八端）
 * - menudata.list 固定 5 槽，菜单个数单选 无/两个/三个/四个/五个（值 0/2/3/4/5，无“单个”），
 *   仅显示/预览/校验前 menucount 个，切换个数不丢弃其它槽位已填内容
 * - 每个菜单三行：名称（菜单二起可上移换位）；链接（可手输或“选择链接”弹窗回填，回显 >链接名）；
 *   默认图标 / 选中图标（图标库弹窗选图，内置 key 按 key 渲染，URL 按图片渲染）
 * - 文字默认颜色 / 文字选中颜色 / 菜单背景色（文本框 + 取色器）；同步到其他端 是/否
 * - 左侧矮手机框实时渲染底部菜单，点击各菜单切换选中态；链接中 [bid] 为商户ID占位，运行时替换
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getBusinessMenu, saveBusinessMenu } from '../../api/business'
import type { NavMenudata, NavTabItem } from '../../api/designer'
import LinkPicker from '../../components/LinkPicker.vue'
import NavIcon from '../../components/NavIcon.vue'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { NAV_ICON_MAP } from '../../components/navIcons'

/** 八端枚举（Tab 文案与参考截图全名一致） */
const PLATFORMS = [
  { key: 'mp', name: '微信公众号' },
  { key: 'wx', name: '微信小程序' },
  { key: 'alipay', name: '支付宝小程序' },
  { key: 'baidu', name: '百度小程序' },
  { key: 'toutiao', name: '抖音小程序' },
  { key: 'qq', name: 'QQ小程序' },
  { key: 'h5', name: '手机H5' },
  { key: 'app', name: '手机APP' },
]

const CN_NUM = ['一', '二', '三', '四', '五']
/** 菜单个数单选：无/两个/三个/四个/五个（固定 5 槽，不设“单个”） */
const COUNT_OPTIONS = [
  { value: 0, label: '无' },
  { value: 2, label: '两个' },
  { value: 3, label: '三个' },
  { value: 4, label: '四个' },
  { value: 5, label: '五个' },
]
/** 固定槽位数 */
const SLOT_MAX = 5
const DEFAULT_INDEXURL = '/pagesExt/business/index?bid=[bid]'

/** 槽位补齐默认（与后端 defaultBusinessNavMenudata 同口径，仅用于前端兜底补齐到 5 槽） */
const SLOT_DEFAULTS: NavTabItem[] = [
  { text: '店铺', pagePath: '/pagesExt/business/index?id=[bid]', iconPath: '', selectedIconPath: '', pagePathname: '基础功能>店铺首页' },
  { text: '商品分类', pagePath: '/pagesM/shop/prolist?bid=[bid]', iconPath: '', selectedIconPath: '', pagePathname: '基础功能>商品分类' },
  { text: '购物车', pagePath: '/pagesM/shop/cart?bid=[bid]', iconPath: '', selectedIconPath: '', pagePathname: '基础功能>购物车' },
  { text: '订单', pagePath: '/pagesM/order/list?bid=[bid]', iconPath: '', selectedIconPath: '', pagePathname: '基础功能>订单列表' },
  { text: '导航名称', pagePath: '', iconPath: '', selectedIconPath: '', pagePathname: '' },
]

const loading = ref(false)
const saving = ref(false)
const platform = ref('mp')
const tongbu = ref(1)
const indexurl = ref(DEFAULT_INDEXURL)
/** 菜单个数（独立于 list 长度，固定 5 槽） */
const menuCount = ref(0)
/** 手机预览当前选中项下标 */
const selectedIdx = ref(0)

const menudata = reactive<NavMenudata>({
  color: '#BBBBBB',
  selectedColor: '#FD4A46',
  backgroundColor: '#ffffff',
  borderStyle: 'black',
  position: 'bottom',
  list: [],
})

function emptyItem(): NavTabItem {
  return { text: '', pagePath: '', iconPath: '', selectedIconPath: '', pagePathname: '' }
}

/** 表单/预览实际可见的菜单（前 menuCount 个；元素仍是原对象引用，可双向写回） */
const visibleList = computed(() => menudata.list.slice(0, menuCount.value))

/** 把 list 兜底补齐为固定 5 槽 */
function normalizeSlots(list: NavTabItem[]): NavTabItem[] {
  const out = (list || []).map((it) => ({ ...emptyItem(), ...it }))
  while (out.length < SLOT_MAX) {
    out.push({ ...SLOT_DEFAULTS[out.length] })
  }
  return out.slice(0, SLOT_MAX)
}

async function fetchMenu() {
  loading.value = true
  try {
    const data = await getBusinessMenu(platform.value)
    tongbu.value = data.tongbu ?? 1
    indexurl.value = data.indexurl || DEFAULT_INDEXURL
    menuCount.value = data.menucount ?? 0
    selectedIdx.value = 0
    const md = data.menudata
    menudata.color = md.color || '#BBBBBB'
    menudata.selectedColor = md.selectedColor || '#FD4A46'
    menudata.backgroundColor = md.backgroundColor || '#ffffff'
    menudata.borderStyle = md.borderStyle ?? 'black'
    menudata.position = md.position ?? 'bottom'
    menudata.list = normalizeSlots(md.list || [])
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function switchPlatform(key: string) {
  if (key === platform.value) return
  platform.value = key
  fetchMenu()
}

/** 切换菜单个数（仅改变显隐数量，固定 5 槽数据保留） */
function onCountChange(n: number) {
  menuCount.value = n
  if (selectedIdx.value >= n) selectedIdx.value = 0
}

/** 上移换位：当前项与上一项交换（菜单二起可用） */
function moveUp(idx: number) {
  if (idx <= 0 || idx >= menudata.list.length) return
  const tmp = menudata.list[idx - 1]
  menudata.list[idx - 1] = menudata.list[idx]
  menudata.list[idx] = tmp
}

// ============ 链接选择（LinkPicker 内含前端链接大弹窗 pick 模式，回填链接+名称） ============
function onLinkPick(item: NavTabItem, url: string, name: string) {
  item.pagePath = url
  if (name) item.pagePathname = name
}

// ============ 图标选择（图标库弹窗 iconOnly；内置 key 按 key 渲染，新选回填 URL 按 URL 渲染） ============
const iconPickerVisible = ref(false)
const iconTarget = ref<{ item: NavTabItem; field: 'iconPath' | 'selectedIconPath' } | null>(null)

function openIconPicker(item: NavTabItem, field: 'iconPath' | 'selectedIconPath') {
  iconTarget.value = { item, field }
  iconPickerVisible.value = true
}

function onIconPick(urls: string[]) {
  if (iconTarget.value && urls[0]) iconTarget.value.item[iconTarget.value.field] = urls[0]
  iconTarget.value = null
}

// ============ 保存（固定 5 槽全量提交 + menucount；当页保存不刷新不跳转） ============
async function handleSave() {
  // 仅校验当前显示的前 menuCount 个；menucount=0（无）允许直接保存
  for (let i = 0; i < menuCount.value; i++) {
    const it = menudata.list[i]
    if (!it.text.trim()) {
      ElMessage.warning(`菜单${CN_NUM[i]}未填写名称`)
      return
    }
    if (!it.pagePath.trim()) {
      ElMessage.warning(`菜单${CN_NUM[i]}未填写链接`)
      return
    }
  }
  saving.value = true
  try {
    await saveBusinessMenu({
      platform: platform.value,
      tongbu: tongbu.value,
      menucount: menuCount.value,
      indexurl: indexurl.value,
      menudata: JSON.parse(JSON.stringify(menudata)),
    })
    ElMessage.success(tongbu.value === 1 ? '保存成功，已同步到其他端' : '保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

// ============ 手机框菜单图标：选中项用选中图标，否则默认图标 ============
function tabIconOf(item: NavTabItem, idx: number): { kind: 'builtin' | 'img' | 'none'; value: string } {
  const v = idx === selectedIdx.value ? item.selectedIconPath || item.iconPath : item.iconPath
  if (v && NAV_ICON_MAP[v]) return { kind: 'builtin', value: v }
  if (v) return { kind: 'img', value: v }
  return { kind: 'none', value: '' }
}

onMounted(fetchMenu)
</script>

<template>
  <div v-loading="loading" class="bmnv-root">
    <el-tabs :model-value="platform" @tab-change="(k: string | number) => switchPlatform(String(k))">
      <el-tab-pane v-for="p in PLATFORMS" :key="p.key" :name="p.key" :label="p.name" />
    </el-tabs>

    <div class="bmnv-layout">
      <!-- 左：矮手机框（重点看底部菜单实时效果，点击菜单切换选中态） -->
      <div class="bmnv-phone">
        <div class="bmnv-phone-screen">
          <div class="bmnv-phone-status">
            <span class="bmnv-status-side">wechat</span>
            <span>12:00</span>
            <span class="bmnv-status-side">100%</span>
          </div>
          <div class="bmnv-phone-title">页面标题</div>
          <div class="bmnv-phone-content">
            <span class="bmnv-content-tip">内容显示区域</span>
            <span class="bmnv-content-note">提示：点击右侧菜单名称后的上移进行排序</span>
          </div>
          <div v-if="menuCount > 0" class="bmnv-tabbar" :style="{ background: menudata.backgroundColor }">
            <div
              v-for="(item, idx) in visibleList"
              :key="idx"
              class="bmnv-tab"
              :style="{ color: idx === selectedIdx ? menudata.selectedColor : menudata.color }"
              @click="selectedIdx = idx"
            >
              <NavIcon v-if="tabIconOf(item, idx).kind === 'builtin'" :name="tabIconOf(item, idx).value" :size="22" />
              <img v-else-if="tabIconOf(item, idx).kind === 'img'" :src="tabIconOf(item, idx).value" class="bmnv-tab-img" />
              <span v-else class="bmnv-tab-fallback">{{ (item.text || '菜').charAt(0) }}</span>
              <span class="bmnv-tab-text">{{ item.text || '编辑菜单' }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- 右：配置表单 -->
      <div class="bmnv-form">
        <el-form label-width="100px" label-position="left">
          <el-form-item label="菜单个数">
            <el-radio-group :model-value="menuCount" @change="(v: number | string | boolean) => onCountChange(Number(v))">
              <el-radio v-for="opt in COUNT_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</el-radio>
            </el-radio-group>
          </el-form-item>

          <template v-for="(item, idx) in visibleList" :key="idx">
            <el-form-item :label="`菜单${CN_NUM[idx]}`" class="bmnv-menu-label">
              <span class="bmnv-field-tag">名称</span>
              <el-input v-model="item.text" maxlength="8" style="width: 210px" />
              <el-button v-if="idx > 0" class="bmnv-inline-btn" @click="moveUp(idx)">上移</el-button>
            </el-form-item>
            <el-form-item label=" " class="bmnv-sub-row">
              <span class="bmnv-field-tag">链接</span>
              <LinkPicker
                v-model="item.pagePath"
                pick-text="选择链接"
                style="width: 240px"
                @pick="(u: string, n: string) => onLinkPick(item, u, n)"
              />
              <span v-if="item.pagePathname" class="bmnv-link-echo">{{ item.pagePathname }}</span>
            </el-form-item>
            <el-form-item label=" " class="bmnv-sub-row">
              <span class="bmnv-field-tag">图标</span>
              <el-button class="bmnv-inline-btn" @click="openIconPicker(item, 'iconPath')">默认图标</el-button>
              <span class="bmnv-icon-preview" :style="{ color: menudata.color }">
                <NavIcon v-if="item.iconPath && NAV_ICON_MAP[item.iconPath]" :name="item.iconPath" :size="26" />
                <img v-else-if="item.iconPath" :src="item.iconPath" class="bmnv-icon-img" />
                <span v-else class="bmnv-icon-empty">—</span>
              </span>
              <el-button class="bmnv-inline-btn" @click="openIconPicker(item, 'selectedIconPath')">选中图标</el-button>
              <span class="bmnv-icon-preview" :style="{ color: menudata.selectedColor }">
                <NavIcon v-if="item.selectedIconPath && NAV_ICON_MAP[item.selectedIconPath]" :name="item.selectedIconPath" :size="26" />
                <img v-else-if="item.selectedIconPath" :src="item.selectedIconPath" class="bmnv-icon-img" />
                <span v-else class="bmnv-icon-empty">—</span>
              </span>
            </el-form-item>
          </template>

          <el-form-item label="文字默认颜色">
            <el-input v-model="menudata.color" style="width: 150px" />
            <el-color-picker v-model="menudata.color" />
          </el-form-item>
          <el-form-item label="文字选中颜色">
            <el-input v-model="menudata.selectedColor" style="width: 150px" />
            <el-color-picker v-model="menudata.selectedColor" />
          </el-form-item>
          <el-form-item label="菜单背景色">
            <el-input v-model="menudata.backgroundColor" style="width: 150px" />
            <el-color-picker v-model="menudata.backgroundColor" />
          </el-form-item>

          <el-form-item label="同步到其他端">
            <el-radio-group v-model="tongbu">
              <el-radio :value="1">是</el-radio>
              <el-radio :value="0">否</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item>
            <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
          </el-form-item>
        </el-form>
      </div>
    </div>

    <!-- 选图标弹窗（图标库选图/改色/渲染） -->
    <ImgIconPicker v-model:visible="iconPickerVisible" icon-only @pick="(urls: string[]) => onIconPick(urls)" />
  </div>
</template>

<style scoped>
.bmnv-root {
  padding: 0 8px;
}

.bmnv-layout {
  display: flex;
  gap: 32px;
  align-items: flex-start;
}

/* 左侧矮手机框 */
.bmnv-phone {
  flex-shrink: 0;
}

.bmnv-phone-screen {
  width: 300px;
  height: 520px;
  border: 8px solid #2c2c2c;
  border-radius: 28px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  background: #ffffff;
}

.bmnv-phone-status {
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 12px;
  font-size: 11px;
  color: #ffffff;
  background: #1f1f1f;
}

.bmnv-status-side {
  min-width: 34px;
}

.bmnv-phone-title {
  height: 34px;
  line-height: 34px;
  text-align: center;
  font-size: 14px;
  color: #ffffff;
  background: #1f1f1f;
  border-top: 1px solid #333333;
}

.bmnv-phone-content {
  flex: 1;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #ececec;
}

.bmnv-content-tip {
  color: #c0c4cc;
  font-size: 15px;
}

.bmnv-content-note {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 10px;
  text-align: center;
  font-size: 12px;
  color: #f56c6c;
}

.bmnv-tabbar {
  display: flex;
  border-top: 1px solid #e5e5e5;
  padding: 6px 0 10px;
}

.bmnv-tab {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  cursor: pointer;
}

.bmnv-tab-img {
  width: 22px;
  height: 22px;
  object-fit: contain;
}

.bmnv-tab-fallback {
  width: 22px;
  height: 22px;
  border: 1px solid currentColor;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
}

.bmnv-tab-text {
  font-size: 11px;
  line-height: 1.2;
}

/* 右侧表单 */
.bmnv-form {
  flex: 1;
  min-width: 0;
  max-width: 760px;
}

.bmnv-menu-label {
  margin-top: 6px;
  margin-bottom: 0;
}

.bmnv-sub-row {
  margin-top: 0;
  margin-bottom: 4px;
}

.bmnv-sub-row :deep(.el-form-item__content) {
  align-items: center;
}

.bmnv-field-tag {
  display: inline-block;
  width: 34px;
  font-size: 13px;
  color: #606266;
}

.bmnv-inline-btn {
  margin-left: 10px;
}

.bmnv-link-echo {
  margin-left: 10px;
  font-size: 13px;
  color: #909399;
}

.bmnv-icon-preview {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  margin-left: 8px;
}

.bmnv-icon-img {
  width: 26px;
  height: 26px;
  object-fit: contain;
}

.bmnv-icon-empty {
  color: #c0c4cc;
}
</style>
