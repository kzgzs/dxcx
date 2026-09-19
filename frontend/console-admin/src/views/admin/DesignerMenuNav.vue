<script setup lang="ts">
/**
 * 设计 - 底部导航（designer_menu，任务279 对照参考截图重做）
 * - 八端 Tab 各自独立配置（后端按 platform 分行存储）；菜单个数 无/两个/三个/四个/五个
 * - 每菜单三行：名称（菜单二起可上移排序）；链接+选择链接+>链接名回显；默认图标/选中图标弹窗选择+预览
 * - 文字默认颜色/文字选中颜色/菜单背景色；中间按钮突出 关/开；同步到其他端 是/否
 * - 左侧矮手机框实时渲染底部菜单（首项选中态），红字提示上移排序
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getDesignerMenu, saveDesignerMenu, type NavMenudata, type NavTabItem } from '../../api/designer'
import LinkPicker from '../../components/LinkPicker.vue'
import NavIcon from '../../components/NavIcon.vue'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { NAV_ICON_MAP } from '../../components/navIcons'

/** 八端枚举（对齐参考系统 platform 口径，Tab 文案对照截图全名） */
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
/** 菜单个数单选：无/两个/三个/四个/五个（最多5） */
const COUNT_OPTIONS = [
  { value: 0, label: '无' },
  { value: 2, label: '两个' },
  { value: 3, label: '三个' },
  { value: 4, label: '四个' },
  { value: 5, label: '五个' },
]

/** 增加菜单项时的预置（前四项内置默认图标，第五项空白自填） */
const PRESETS: NavTabItem[] = [
  { text: '首页', pagePath: '/pages/index/index', iconPath: 'home', selectedIconPath: 'home', pagePathname: '基础功能>首页' },
  { text: '分类', pagePath: '/pages/category/category', iconPath: 'category', selectedIconPath: 'category', pagePathname: '基础功能>分类' },
  { text: '购物车', pagePath: '/pages/cart/cart', iconPath: 'cart', selectedIconPath: 'cart', pagePathname: '基础功能>购物车' },
  { text: '会员中心', pagePath: '/pages/my/usercenter', iconPath: 'user', selectedIconPath: 'user', pagePathname: '基础功能>会员中心' },
]

const loading = ref(false)
const saving = ref(false)
const platform = ref('mp')
const tongbu = ref(0)
const indexurl = ref('/pages/index/index')
const menudata = reactive<NavMenudata>({
  color: '#1C2438',
  selectedColor: '#1296db',
  backgroundColor: '#ffffff',
  borderStyle: 'black',
  position: 'bottom',
  midbutton: 0,
  list: [],
})

function emptyItem(): NavTabItem {
  return { text: '', pagePath: '', iconPath: '', selectedIconPath: '', pagePathname: '' }
}

/** 菜单个数（单选 无/2/3/4/5；存量数据若为1则单选不选中，保存前须调整） */
const menuCount = computed({
  get: () => menudata.list.length,
  set: (n: number) => resizeList(n),
})

function resizeList(n: number) {
  while (menudata.list.length > n) menudata.list.pop()
  while (menudata.list.length < n) {
    const preset = PRESETS[menudata.list.length]
    menudata.list.push(preset ? { ...preset } : emptyItem())
  }
}

async function fetchMenu() {
  loading.value = true
  try {
    const data = await getDesignerMenu(platform.value)
    tongbu.value = data.tongbu ?? 0
    indexurl.value = data.indexurl || '/pages/index/index'
    const md = data.menudata
    menudata.color = md.color || '#1C2438'
    menudata.selectedColor = md.selectedColor || '#1296db'
    menudata.backgroundColor = md.backgroundColor || '#ffffff'
    menudata.borderStyle = md.borderStyle ?? 'black'
    menudata.position = md.position ?? 'bottom'
    menudata.midbutton = Number(md.midbutton || 0) === 1 ? 1 : 0
    menudata.list = (md.list || []).map((it) => ({ ...emptyItem(), ...it }))
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

/** 上移排序（菜单二起可用） */
function moveUp(idx: number) {
  if (idx <= 0 || idx >= menudata.list.length) return
  const tmp = menudata.list[idx - 1]
  menudata.list[idx - 1] = menudata.list[idx]
  menudata.list[idx] = tmp
}

// ============ 链接选择（FrontendLinks pick 模式：回填链接+名称） ============
function onLinkPick(item: NavTabItem, url: string, name: string) {
  item.pagePath = url
  if (name) item.pagePathname = name
}

// ============ 图标选择（任务292：ImgIconPicker iconOnly；兼容层：存量内置 key 仍按 key 渲染，新选回填 URL 按 URL 渲染） ============
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

// ============ 保存（当页保存不刷新不跳转） ============
async function handleSave() {
  for (const [i, it] of menudata.list.entries()) {
    if (!it.text.trim()) {
      ElMessage.warning(`菜单${CN_NUM[i]}未填写名称`)
      return
    }
  }
  saving.value = true
  try {
    await saveDesignerMenu({
      platform: platform.value,
      tongbu: tongbu.value,
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

// ============ 手机框底部菜单实时预览 ============
function tabIconOf(item: NavTabItem, idx: number): { kind: 'builtin' | 'img' | 'none'; value: string } {
  const v = idx === 0 ? item.selectedIconPath || item.iconPath : item.iconPath
  if (v && NAV_ICON_MAP[v]) return { kind: 'builtin', value: v }
  if (v) return { kind: 'img', value: v }
  return { kind: 'none', value: '' }
}

onMounted(fetchMenu)
</script>

<template>
  <div v-loading="loading" class="mnv-root">
    <el-tabs :model-value="platform" @tab-change="(k: string | number) => switchPlatform(String(k))">
      <el-tab-pane v-for="p in PLATFORMS" :key="p.key" :name="p.key" :label="p.name" />
    </el-tabs>

    <div class="mnv-layout">
      <!-- 左：矮手机框（重点看底部菜单实时效果） -->
      <div class="mnv-phone">
        <div class="mnv-phone-screen">
          <div class="mnv-phone-status">
            <span class="mnv-status-side">wechat</span>
            <span>12:00</span>
            <span class="mnv-status-side">100%</span>
          </div>
          <div class="mnv-phone-title">页面标题</div>
          <div class="mnv-phone-content">
            <span class="mnv-content-tip">内容显示区域</span>
            <span class="mnv-content-note">提示：点击右侧菜单名称后的上移进行排序</span>
          </div>
          <div class="mnv-tabbar" :style="{ background: menudata.backgroundColor }">
            <div
              v-for="(item, idx) in menudata.list"
              :key="idx"
              class="mnv-tab"
              :style="{ color: idx === 0 ? menudata.selectedColor : menudata.color }"
            >
              <NavIcon v-if="tabIconOf(item, idx).kind === 'builtin'" :name="tabIconOf(item, idx).value" :size="22" />
              <img v-else-if="tabIconOf(item, idx).kind === 'img'" :src="tabIconOf(item, idx).value" class="mnv-tab-img" />
              <span v-else class="mnv-tab-fallback">{{ (item.text || '菜').charAt(0) }}</span>
              <span class="mnv-tab-text">{{ item.text || '菜单' }}</span>
            </div>
            <div v-if="menudata.midbutton === 1" class="mnv-mid-btn"></div>
          </div>
        </div>
      </div>

      <!-- 右：配置表单 -->
      <div class="mnv-form">
        <el-form label-width="100px" label-position="left">
          <el-form-item label="菜单个数">
            <el-radio-group v-model="menuCount">
              <el-radio v-for="opt in COUNT_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</el-radio>
            </el-radio-group>
          </el-form-item>

          <template v-for="(item, idx) in menudata.list" :key="idx">
            <el-form-item :label="`菜单${CN_NUM[idx]}名称`" class="mnv-menu-label">
              <el-input v-model="item.text" maxlength="8" style="width: 240px" />
              <el-button v-if="idx > 0" class="mnv-inline-btn" @click="moveUp(idx)">上移</el-button>
            </el-form-item>
            <el-form-item label="链接">
              <LinkPicker
                v-model="item.pagePath"
                pick-text="选择链接"
                style="width: 320px"
                @pick="(u: string, n: string) => onLinkPick(item, u, n)"
              />
              <span v-if="item.pagePathname" class="mnv-link-echo">&gt;{{ item.pagePathname }}</span>
            </el-form-item>
            <el-form-item label="图标">
              <el-button class="mnv-inline-btn" @click="openIconPicker(item, 'iconPath')">默认图标</el-button>
              <span class="mnv-icon-preview" :style="{ color: menudata.color }">
                <NavIcon v-if="item.iconPath && NAV_ICON_MAP[item.iconPath]" :name="item.iconPath" :size="26" />
                <img v-else-if="item.iconPath" :src="item.iconPath" class="mnv-icon-img" />
                <span v-else class="mnv-icon-empty">—</span>
              </span>
              <el-button class="mnv-inline-btn" @click="openIconPicker(item, 'selectedIconPath')">选中图标</el-button>
              <span class="mnv-icon-preview" :style="{ color: menudata.selectedColor }">
                <NavIcon v-if="item.selectedIconPath && NAV_ICON_MAP[item.selectedIconPath]" :name="item.selectedIconPath" :size="26" />
                <img v-else-if="item.selectedIconPath" :src="item.selectedIconPath" class="mnv-icon-img" />
                <span v-else class="mnv-icon-empty">—</span>
              </span>
            </el-form-item>
          </template>

          <el-form-item label="文字默认颜色">
            <el-input v-model="menudata.color" style="width: 160px" />
            <el-color-picker v-model="menudata.color" />
          </el-form-item>
          <el-form-item label="文字选中颜色">
            <el-input v-model="menudata.selectedColor" style="width: 160px" />
            <el-color-picker v-model="menudata.selectedColor" />
          </el-form-item>
          <el-form-item label="菜单背景色">
            <el-input v-model="menudata.backgroundColor" style="width: 160px" />
            <el-color-picker v-model="menudata.backgroundColor" />
          </el-form-item>

          <el-form-item label="中间按钮突出">
            <el-radio-group v-model="menudata.midbutton">
              <el-radio :value="0">关闭</el-radio>
              <el-radio :value="1">开启</el-radio>
            </el-radio-group>
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

    <!-- 选图标弹窗（任务292：图标库选图/改色/渲染） -->
    <ImgIconPicker v-model:visible="iconPickerVisible" icon-only @pick="(urls: string[]) => onIconPick(urls)" />
  </div>
</template>

<style scoped>
.mnv-root {
  padding: 0 8px;
}

.mnv-layout {
  display: flex;
  gap: 32px;
  align-items: flex-start;
}

/* 左侧矮手机框 */
.mnv-phone {
  flex-shrink: 0;
}

.mnv-phone-screen {
  width: 300px;
  height: 500px;
  border: 8px solid #2c2c2c;
  border-radius: 28px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  background: #ffffff;
}

.mnv-phone-status {
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 12px;
  font-size: 11px;
  color: #ffffff;
  background: #1f1f1f;
}

.mnv-status-side {
  min-width: 34px;
}

.mnv-phone-title {
  height: 34px;
  line-height: 34px;
  text-align: center;
  font-size: 14px;
  color: #ffffff;
  background: #1f1f1f;
  border-top: 1px solid #333333;
}

.mnv-phone-content {
  flex: 1;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #ececec;
}

.mnv-content-tip {
  color: #c0c4cc;
  font-size: 15px;
}

.mnv-content-note {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 8px;
  text-align: center;
  font-size: 12px;
  color: #f56c6c;
}

.mnv-tabbar {
  position: relative;
  display: flex;
  border-top: 1px solid #e5e5e5;
  padding: 6px 0 10px;
}

.mnv-tab {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.mnv-tab-img {
  width: 22px;
  height: 22px;
  object-fit: contain;
}

.mnv-tab-fallback {
  width: 22px;
  height: 22px;
  border: 1px solid currentColor;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
}

.mnv-tab-text {
  font-size: 11px;
  line-height: 1.2;
}

.mnv-mid-btn {
  position: absolute;
  left: 50%;
  top: -20px;
  transform: translateX(-50%);
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: #ffffff;
  border: 1px solid #e5e5e5;
  box-shadow: 0 -2px 6px rgba(0, 0, 0, 0.08);
}

/* 右侧表单 */
.mnv-form {
  flex: 1;
  min-width: 0;
  max-width: 760px;
}

.mnv-menu-label {
  margin-top: 6px;
}

.mnv-inline-btn {
  margin-left: 10px;
}

.mnv-link-echo {
  margin-left: 10px;
  font-size: 13px;
  color: #606266;
}

.mnv-icon-preview {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  margin-left: 8px;
}

.mnv-icon-img {
  width: 26px;
  height: 26px;
  object-fit: contain;
}

.mnv-icon-empty {
  color: #c0c4cc;
}
</style>
