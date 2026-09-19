<script setup lang="ts">
/**
 * 设计 - 商品详情设置（designer_shopdetail，任务281 对照重做）
 * - 底部栏三槽位：menuType 可改（1客服/2购物车/3收藏/4其它）；客服支持跟随系统/自定义
 * - 每槽位：名称 + 图标/选中图标上传 + 选中名称 + 链接选择 + 显示/不显示
 * - 商品描述公共底部：wangEditor 富文本 + 补充图 + 灰字提示
 * - 分享海报：需要登录/无需登录；保存当页不刷新
 * - 左侧手机框实时预览：详情标题 + 内容区 + 公共描述块 + 底部栏左三菜单 + 加购紫/立买橙
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, shallowRef } from 'vue'
import { ElMessage } from 'element-plus'
import '@wangeditor/editor/dist/css/style.css'
import { Editor, Toolbar } from '@wangeditor/editor-for-vue'
import { getShopdetail, saveShopdetail, type ShopdetailMenudata, type ShopdetailSlot } from '../../api/designer'
import { uploadFile } from '../../api/goods'
import LinkPicker from '../../components/LinkPicker.vue'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import NavIcon from '../../components/NavIcon.vue'
import { NAV_ICON_MAP } from '../../components/navIcons'

const loading = ref(false)
const saving = ref(false)

const CN_NUM = ['一', '二', '三']
const MENU_TYPES = [
  { label: '客服', value: 1 },
  { label: '购物车', value: 2 },
  { label: '收藏', value: 3 },
  { label: '其它', value: 4 },
]

function defaultSlot(menuType: number): ShopdetailSlot {
  const base: ShopdetailSlot = {
    text: '',
    pagePath: '',
    iconPath: '',
    selectedIconPath: '',
    pagePathname: '',
    isShow: 1,
    menuType,
    useSystem: 0,
    selectedtext: '',
  }
  if (menuType === 1) {
    base.text = '客服'
    base.useSystem = 1
  } else if (menuType === 2) {
    base.text = '购物车'
    base.pagePath = '/pages/cart/cart'
  } else if (menuType === 3) {
    base.text = '收藏'
    base.pagePath = 'addfavorite::'
    base.selectedtext = '已收藏'
  }
  return base
}

const menudata = reactive<ShopdetailMenudata>({
  color: '#BBBBBB',
  selectedColor: '#FD4A46',
  backgroundColor: '#ffffff',
  borderStyle: 'black',
  position: 'bottom',
  list: [defaultSlot(1), defaultSlot(2), defaultSlot(3)],
})
const navBarBg = ref('#333333')
const navBarText = ref('white')
const description = ref('')
const descriptionImg = ref('')
const shareLogin = ref(1)

async function fetchData() {
  loading.value = true
  try {
    const data = await getShopdetail()
    const md = data.menudata || ({} as ShopdetailMenudata)
    menudata.color = md.color ?? '#BBBBBB'
    menudata.selectedColor = md.selectedColor ?? '#FD4A46'
    menudata.backgroundColor = md.backgroundColor ?? '#ffffff'
    menudata.borderStyle = md.borderStyle ?? 'black'
    menudata.position = md.position ?? 'bottom'
    const list = (md.list || []).map((it) => ({ ...it }))
    menudata.list = list.length > 0 ? list : [defaultSlot(1), defaultSlot(2), defaultSlot(3)]
    navBarBg.value = data.navigationBarBackgroundColor || '#333333'
    navBarText.value = data.navigationBarTextStyle || 'white'
    description.value = data.description || ''
    descriptionImg.value = data.description_img || ''
    shareLogin.value = Number(data.share_login ?? 1)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

/** 预览用：仅展示 isShow=1 的槽位 */
const previewSlots = computed(() => menudata.list.filter((it) => it.isShow === 1))

async function handleSave() {
  for (let i = 0; i < menudata.list.length; i++) {
    const it = menudata.list[i]
    if (it.isShow === 1 && !it.text.trim()) {
      ElMessage.warning(`「菜单${CN_NUM[i] || i + 1}」显示中，请填写名称`)
      return
    }
  }
  saving.value = true
  try {
    await saveShopdetail({
      menudata: JSON.parse(JSON.stringify(menudata)),
      navigationBarBackgroundColor: navBarBg.value,
      navigationBarTextStyle: navBarText.value,
      description: description.value,
      description_img: descriptionImg.value,
      share_login: shareLogin.value,
    })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

/** 切换槽位类型时按类型给默认文案/链接（名称为空才回填） */
function onTypeChange(slot: ShopdetailSlot) {
  const preset = defaultSlot(slot.menuType)
  if (!slot.text.trim()) slot.text = preset.text
  if (slot.menuType === 1) {
    slot.useSystem = slot.useSystem ?? 1
  }
  if (!slot.pagePath && preset.pagePath) slot.pagePath = preset.pagePath
}

/** 链接选择回填（LinkPicker pick 模式上抛链接名） */
function onLinkPick(slot: ShopdetailSlot, url: string, name: string) {
  slot.pagePath = url
  slot.pagePathname = name
}

// ============ 选图/选图标（任务292：ImgIconPicker；兼容层：存量内置 key 按 key 渲染，新选回填 URL 按 URL 渲染） ============
const iconPickerVisible = ref(false)
const imgPickerVisible = ref(false)
const pickTarget = ref<((url: string) => void) | null>(null)

function openIconPick(set: (url: string) => void) {
  pickTarget.value = set
  iconPickerVisible.value = true
}

function openImgPick(set: (url: string) => void) {
  pickTarget.value = set
  imgPickerVisible.value = true
}

function onPick(urls: string[]) {
  if (pickTarget.value && urls[0]) pickTarget.value(urls[0])
  pickTarget.value = null
}

// ============ 公共底部描述富文本（wangEditor） ============
const editorRef = shallowRef()
const toolbarConfig = {}
const editorConfig = { placeholder: '请输入公共底部商品描述...', MENU_CONF: {} as Record<string, unknown> }
editorConfig.MENU_CONF = {
  uploadImage: {
    async customUpload(file: File, insertFn: (url: string) => void) {
      try {
        const res = await uploadFile(file)
        insertFn(res.url)
      } catch {
        ElMessage.error('图片上传失败')
      }
    },
  },
}
function handleEditorCreated(editor: unknown) {
  editorRef.value = editor
}

/** 富文本插图：从选图弹窗回填 URL 插入编辑器（任务292 可选能力） */
function insertEditorImage(url: string) {
  const editor = editorRef.value
  if (!editor) return
  if (typeof editor.insertNode === 'function') {
    editor.insertNode({ type: 'image', children: [], url, alt: '', href: '', style: {} })
  } else if (typeof editor.dangerouslyInsertHtml === 'function') {
    editor.dangerouslyInsertHtml(`<img src="${url}" style="max-width:100%"/>`)
  }
}
onBeforeUnmount(() => {
  editorRef.value?.destroy?.()
})

onMounted(fetchData)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <div class="sd-header">
          <span>商品详情设置</span>
          <span class="sd-tip">商品详情页底部操作栏菜单与公共底部商品描述</span>
        </div>
      </template>

      <div class="sd-layout">
        <!-- 左：手机框实时预览 -->
        <div class="sd-preview">
          <div class="phone-frame">
            <div class="phone-screen">
              <div class="phone-navbar" :style="{ background: navBarBg, color: navBarText === 'white' ? '#fff' : '#333' }">
                商品详情
              </div>
              <div class="phone-body">
                <div class="phone-pic">商品主图</div>
                <div class="phone-line w80" />
                <div class="phone-line w60" />
                <div class="phone-desc-block">
                  <div class="phone-desc-title">售后说明</div>
                  <div v-if="description" class="phone-desc-html" v-html="description" />
                  <img v-if="descriptionImg" :src="descriptionImg" class="phone-desc-img" />
                  <div v-if="!description && !descriptionImg" class="phone-desc-empty">公共底部商品描述</div>
                </div>
              </div>
              <div class="phone-bottom" :style="{ background: menudata.backgroundColor }">
                <div
                  v-for="(slot, idx) in previewSlots"
                  :key="idx"
                  class="phone-slot"
                  :style="{ color: menudata.color }"
                >
                  <NavIcon v-if="slot.iconPath && NAV_ICON_MAP[slot.iconPath]" :name="slot.iconPath" :size="22" />
                  <img v-else-if="slot.iconPath" :src="slot.iconPath" class="phone-slot-icon" />
                  <div v-else class="phone-slot-fallback">{{ (slot.text || '·').charAt(0) }}</div>
                  <div class="phone-slot-text">{{ slot.text }}</div>
                </div>
                <div class="phone-buy phone-buy-cart">加入购物车</div>
                <div class="phone-buy phone-buy-now">立即购买</div>
              </div>
            </div>
          </div>
          <div class="preview-tip">手机预览（商品详情底部栏）</div>
        </div>

        <!-- 右：配置表单 -->
        <div class="sd-form">
          <el-form label-width="90px" label-position="left">
            <template v-for="(slot, idx) in menudata.list" :key="idx">
              <el-divider content-position="left">菜单{{ CN_NUM[idx] || idx + 1 }}</el-divider>
              <el-form-item label="类型">
                <el-radio-group v-model="slot.menuType" @change="onTypeChange(slot)">
                  <el-radio v-for="t in MENU_TYPES" :key="t.value" :value="t.value">{{ t.label }}</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item v-if="slot.menuType === 1" label="客服链接">
                <el-radio-group v-model="slot.useSystem">
                  <el-radio :value="1">跟随系统</el-radio>
                  <el-radio :value="0">自定义</el-radio>
                </el-radio-group>
                <span class="form-tip">跟随系统使用系统设置中的客服链接</span>
              </el-form-item>
              <el-form-item label="名称">
                <el-input v-model="slot.text" maxlength="8" style="width: 220px" />
              </el-form-item>
              <el-form-item label="图标">
                <div class="icon-slot">
                  <span class="icon-box">
                    <NavIcon v-if="slot.iconPath && NAV_ICON_MAP[slot.iconPath]" :name="slot.iconPath" :size="26" />
                    <img v-else-if="slot.iconPath" :src="slot.iconPath" class="icon-view" />
                    <span v-else class="icon-empty">—</span>
                  </span>
                  <el-button size="small" @click="openIconPick((url) => (slot.iconPath = url))">选择图标</el-button>
                  <el-button v-if="slot.iconPath" link type="danger" size="small" @click="slot.iconPath = ''">清除</el-button>
                </div>
              </el-form-item>
              <el-form-item v-if="slot.menuType === 3" label="选中名称">
                <el-input v-model="slot.selectedtext" maxlength="8" style="width: 220px" />
                <span class="form-tip">收藏成功后的选中态文案</span>
              </el-form-item>
              <el-form-item v-if="slot.menuType === 3" label="选中图标">
                <div class="icon-slot">
                  <span class="icon-box">
                    <NavIcon v-if="slot.selectedIconPath && NAV_ICON_MAP[slot.selectedIconPath]" :name="slot.selectedIconPath" :size="26" />
                    <img v-else-if="slot.selectedIconPath" :src="slot.selectedIconPath" class="icon-view" />
                    <span v-else class="icon-empty">—</span>
                  </span>
                  <el-button size="small" @click="openIconPick((url) => (slot.selectedIconPath = url))">选择图标</el-button>
                  <el-button v-if="slot.selectedIconPath" link type="danger" size="small" @click="slot.selectedIconPath = ''">清除</el-button>
                </div>
              </el-form-item>
              <el-form-item v-if="slot.menuType !== 1 || slot.useSystem === 0" label="链接">
                <div class="link-row">
                  <LinkPicker :model-value="slot.pagePath" pick-text="选择链接" style="width: 50%" @update:model-value="(v: string) => (slot.pagePath = v)" @pick="(url: string, name: string) => onLinkPick(slot, url, name)" />
                  <span v-if="slot.pagePathname" class="form-tip">&gt; {{ slot.pagePathname }}</span>
                </div>
              </el-form-item>
              <el-form-item label="显示">
                <el-radio-group v-model="slot.isShow">
                  <el-radio :value="1">显示</el-radio>
                  <el-radio :value="0">不显示</el-radio>
                </el-radio-group>
              </el-form-item>
            </template>

            <el-divider content-position="left">商品描述公共底部</el-divider>
            <el-form-item label="描述内容">
              <div class="editor-wrap">
                <div class="editor-toolbar-row">
                  <Toolbar :editor="editorRef" :defaultConfig="toolbarConfig" style="flex: 1; border-bottom: 1px solid #e4e7ed" />
                  <el-button size="small" class="editor-img-btn" @click="openImgPick(insertEditorImage)">插图</el-button>
                </div>
                <Editor
                  v-model="description"
                  :defaultConfig="editorConfig"
                  style="height: 260px; overflow-y: hidden"
                  @onCreated="handleEditorCreated"
                />
              </div>
              <div class="form-tip-block">用于展示公共的价格说明等信息</div>
            </el-form-item>
            <el-form-item label="补充图片">
              <div class="icon-slot">
                <span class="icon-box pic-box">
                  <img v-if="descriptionImg" :src="descriptionImg" class="pic-thumb" />
                  <span v-else class="icon-empty">—</span>
                </span>
                <el-button size="small" @click="openImgPick((url) => (descriptionImg = url))">选择图片</el-button>
                <el-button v-if="descriptionImg" link type="danger" size="small" @click="descriptionImg = ''">清除</el-button>
              </div>
              <span class="form-tip">可选，展示在公共描述下方</span>
            </el-form-item>

            <el-divider content-position="left">分享海报</el-divider>
            <el-form-item label="登录要求">
              <el-radio-group v-model="shareLogin">
                <el-radio :value="1">需要登录</el-radio>
                <el-radio :value="0">无需登录</el-radio>
              </el-radio-group>
            </el-form-item>

            <el-form-item>
              <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
            </el-form-item>
          </el-form>
        </div>
      </div>
    </el-card>

    <!-- 选图/选图标弹窗（任务292） -->
    <ImgIconPicker v-model:visible="iconPickerVisible" icon-only @pick="(urls: string[]) => onPick(urls)" />
    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="(urls: string[]) => onPick(urls)" />
  </div>
</template>

<style scoped>
.sd-header {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.sd-tip {
  font-size: 12px;
  color: #909399;
}

.sd-layout {
  display: flex;
  gap: 24px;
  align-items: flex-start;
}

.sd-form {
  flex: 1;
  min-width: 0;
}

/* 行距紧凑（任务286：表单项/分割线间距约减半） */
.sd-form :deep(.el-form-item) {
  margin-bottom: 8px;
}

.sd-form :deep(.el-divider) {
  margin: 10px 0;
}

.link-row {
  display: flex;
  align-items: center;
  width: 100%;
}

.form-tip {
  margin-left: 8px;
  font-size: 12px;
  color: #909399;
}

.form-tip-block {
  width: 100%;
  margin-top: 6px;
  font-size: 12px;
  color: #909399;
}

.editor-wrap {
  width: 100%;
  border: 1px solid #e4e7ed;
  border-radius: 4px;
  overflow: hidden;
}

.icon-slot {
  display: flex;
  align-items: center;
  gap: 8px;
}

.icon-box {
  width: 40px;
  height: 40px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #fff;
}

.pic-box {
  width: 56px;
  height: 56px;
}

.pic-thumb {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.icon-empty {
  color: #c0c4cc;
}

.editor-toolbar-row {
  display: flex;
  align-items: center;
}

.editor-img-btn {
  margin: 0 8px;
}

.icon-view {
  width: 32px;
  height: 32px;
  object-fit: contain;
}

/* 手机预览 */
.sd-preview {
  flex-shrink: 0;
}

.phone-frame {
  width: 300px;
  height: 600px;
  border: 10px solid #2c2c2c;
  border-radius: 32px;
  overflow: hidden;
  background: #f5f6f8;
}

.phone-screen {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.phone-navbar {
  height: 40px;
  line-height: 40px;
  text-align: center;
  font-size: 14px;
}

.phone-body {
  flex: 1;
  padding: 12px;
  overflow: hidden;
}

.phone-pic {
  height: 150px;
  background: #e8eaef;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #b0b4bd;
  font-size: 13px;
}

.phone-line {
  height: 12px;
  background: #e8eaef;
  border-radius: 6px;
  margin-top: 12px;
}

.w80 { width: 80%; }
.w60 { width: 60%; }

.phone-desc-block {
  margin-top: 12px;
  padding: 10px;
  background: #ffffff;
  border-radius: 8px;
}

.phone-desc-title {
  font-size: 12px;
  font-weight: 600;
  color: #333;
  margin-bottom: 6px;
}

.phone-desc-html {
  font-size: 11px;
  color: #666;
  word-break: break-all;
}

.phone-desc-html :deep(img) {
  max-width: 100%;
}

.phone-desc-img {
  margin-top: 6px;
  max-width: 100%;
  border-radius: 4px;
}

.phone-desc-empty {
  font-size: 11px;
  color: #c0c4cc;
}

.phone-bottom {
  display: flex;
  align-items: center;
  border-top: 1px solid #e5e5e5;
  padding: 6px 8px 12px;
  gap: 6px;
}

.phone-slot {
  width: 44px;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.phone-slot-icon {
  width: 22px;
  height: 22px;
  object-fit: contain;
}

.phone-slot-fallback {
  width: 22px;
  height: 22px;
  border: 1px solid currentColor;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
}

.phone-slot-text {
  margin-top: 2px;
  font-size: 10px;
  line-height: 1.2;
}

.phone-buy {
  flex: 1;
  height: 34px;
  line-height: 34px;
  text-align: center;
  color: #fff;
  font-size: 12px;
  border-radius: 17px;
}

.phone-buy-cart {
  background: #8a5cf6;
}

.phone-buy-now {
  background: #ff7d1c;
}

.preview-tip {
  margin-top: 8px;
  text-align: center;
  font-size: 12px;
  color: #909399;
}
</style>
