<script setup lang="ts">
// 公众号菜单设置（批次7-2 任务210，对标参考系统 Mpmenu）
// 一级菜单最多3个、子菜单最多5个；view链接 / click关键字(文本/图片/语音/视频/图文) / miniprogram小程序+兜底H5
// 保存写 mp_menu.menudata；「同步到微信」调 menuCreate（click文本落 mp_menukey、素材换 media_id）；「获取线上菜单」回填
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { UploadRequestOptions } from 'element-plus'
import {
  fetchMpMenu,
  getMpMenu,
  getMpNewsList,
  saveMpMenu,
  syncMpMenu,
  uploadMpMedia,
} from '../../api/mp'
import type { MpMenuButton, MpNewsItem } from '../../api/mp'
import { Close } from '@element-plus/icons-vue'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const saving = ref(false)
const syncing = ref(false)
const fetching = ref(false)
const appid = ref('')

const menus = ref<MpMenuButton[]>([])

/** 选中按钮：j=-1 表示一级按钮，否则为子菜单下标 */
const sel = ref<{ i: number; j: number } | null>(null)

const selected = computed<MpMenuButton | null>(() => {
  if (!sel.value) return null
  const top = menus.value[sel.value.i]
  if (!top) return null
  if (sel.value.j === -1) return top
  return top.sub_button?.[sel.value.j] || null
})

/** 选中的一级按钮（用于判断是否含子菜单） */
const selectedTop = computed<MpMenuButton | null>(() => {
  if (!sel.value) return null
  return menus.value[sel.value.i] || null
})

const isTopSelected = computed(() => sel.value !== null && sel.value.j === -1)
const topHasSub = computed(() => isTopSelected.value && (selectedTop.value?.sub_button?.length || 0) > 0)

function newTopButton(): MpMenuButton {
  return { menu_name: '', menu_type: 'view', menu_url: '', sub_button: [] }
}
function newSubButton(): MpMenuButton {
  return { menu_name: '', menu_type: 'view', menu_url: '' }
}

function addTop() {
  if (menus.value.length >= 3) {
    ElMessage.warning('一级菜单最多 3 个')
    return
  }
  menus.value.push(newTopButton())
  sel.value = { i: menus.value.length - 1, j: -1 }
}

function removeTop(i: number) {
  menus.value.splice(i, 1)
  sel.value = null
}

function addSub(i: number) {
  const top = menus.value[i]
  if (!top.sub_button) top.sub_button = []
  if (top.sub_button.length >= 5) {
    ElMessage.warning('子菜单最多 5 个')
    return
  }
  top.sub_button.push(newSubButton())
  sel.value = { i, j: top.sub_button.length - 1 }
}

function removeSub(i: number, j: number) {
  menus.value[i].sub_button?.splice(j, 1)
  sel.value = null
}

/** 切换选中（重新点击已选中项不取消，便于编辑） */
function selectButton(i: number, j: number) {
  sel.value = { i, j }
}

/** 本地校验（与后端 saveMenu 校验口径一致） */
function validate(): boolean {
  if (menus.value.length === 0) {
    ElMessage.warning('请先添加菜单')
    return false
  }
  for (const top of menus.value) {
    if (!top.menu_name.trim()) {
      ElMessage.warning('请填写所有菜单名称')
      return false
    }
    if (top.sub_button?.length) {
      for (const sub of top.sub_button) {
        if (!sub.menu_name.trim()) {
          ElMessage.warning(`菜单「${top.menu_name}」下存在未填写名称的子菜单`)
          return false
        }
      }
    }
  }
  return true
}

async function handleSave(): Promise<boolean> {
  if (!validate()) return false
  saving.value = true
  try {
    await saveMpMenu(menus.value)
    ElMessage.success('保存成功')
    return true
  } catch {
    return false
  } finally {
    saving.value = false
  }
}

async function handleSync() {
  try {
    await ElMessageBox.confirm('同步将把当前菜单发布到微信公众号（空菜单会删除线上菜单），确认继续？', '同步到微信', { type: 'warning' })
  } catch {
    return
  }
  syncing.value = true
  try {
    if (!(await handleSave())) return
    const rs = await syncMpMenu()
    ElMessage.success(rs.action === 'delete' ? '线上菜单已删除' : '菜单已同步到微信')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    syncing.value = false
  }
}

async function handleFetch() {
  try {
    await ElMessageBox.confirm('获取线上菜单将覆盖当前编辑内容，确认继续？', '获取线上菜单', { type: 'warning' })
  } catch {
    return
  }
  fetching.value = true
  try {
    const rs = await fetchMpMenu()
    menus.value = rs.menudata || []
    sel.value = null
    ElMessage.success('已获取线上菜单')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    fetching.value = false
  }
}

async function load() {
  loading.value = true
  try {
    const rs = await getMpMenu()
    menus.value = rs.menudata || []
    appid.value = rs.appid || ''
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// ---------- click 类素材上传（语音/视频保留本地上传） ----------
async function uploadClickAsset(options: UploadRequestOptions, type: 'voice' | 'video') {
  const btn = selected.value
  if (!btn) return
  try {
    const res = await uploadMpMedia(options.file, type)
    btn[`${type}_content`] = res.url
    ElMessage.success('素材上传成功')
  } catch {
    ElMessage.error('素材上传失败')
  }
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

// ---------- 图文素材选择 ----------
const newsVisible = ref(false)
const newsLoading = ref(false)
const newsTotal = ref(0)
const newsItems = ref<MpNewsItem[]>([])

async function openNewsPicker() {
  newsVisible.value = true
  newsLoading.value = true
  try {
    const rs = await getMpNewsList(1, 20)
    newsTotal.value = rs.count
    newsItems.value = rs.data || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    newsLoading.value = false
  }
}

function pickNews(item: MpNewsItem) {
  const btn = selected.value
  if (btn) {
    btn.news_content = item.media_id
  }
  newsVisible.value = false
}

onMounted(load)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="card-header">
        <div class="card-title">
          公众号菜单设置
          <span v-if="appid" class="appid-tip">公众号：{{ appid }}</span>
        </div>
        <div class="card-actions">
          <el-button :loading="fetching" @click="handleFetch">获取线上菜单</el-button>
          <el-button type="primary" plain :loading="saving" @click="handleSave">保存菜单</el-button>
          <el-button type="primary" :loading="syncing" @click="handleSync">同步到微信</el-button>
        </div>
      </div>
    </template>

    <div class="menu-workbench">
      <!-- 左侧：菜单结构预览 -->
      <div class="phone-panel">
        <div class="phone-tip">点击下方按钮进行编辑（一级最多3个，子菜单最多5个）</div>
        <div class="menu-columns">
          <div v-for="(top, i) in menus" :key="`top-${i}`" class="menu-column">
            <div class="sub-list">
              <div
                v-for="(sub, j) in top.sub_button || []"
                :key="`sub-${i}-${j}`"
                class="menu-cell"
                :class="{ active: sel?.i === i && sel?.j === j }"
                @click="selectButton(i, j)"
              >
                <span class="cell-name">{{ sub.menu_name || '未命名' }}</span>
                <el-icon class="cell-del" @click.stop="removeSub(i, j)"><Close /></el-icon>
              </div>
              <div v-if="(top.sub_button?.length || 0) < 5" class="menu-cell add-cell" @click="addSub(i)">＋</div>
            </div>
            <div
              class="menu-cell top-cell"
              :class="{ active: sel?.i === i && sel?.j === -1 }"
              @click="selectButton(i, -1)"
            >
              <span class="cell-name">{{ top.menu_name || '未命名' }}</span>
              <el-icon class="cell-del" @click.stop="removeTop(i)"><Close /></el-icon>
            </div>
          </div>
          <div v-if="menus.length < 3" class="menu-column add-column" @click="addTop">
            <div class="add-column-inner">＋ 一级菜单</div>
          </div>
        </div>
      </div>

      <!-- 右侧：选中按钮编辑 -->
      <div class="edit-panel">
        <el-empty v-if="!selected" description="请选择左侧菜单按钮进行编辑" :image-size="80" />
        <el-form v-else label-width="100px" label-position="right">
          <el-form-item :label="isTopSelected ? '一级菜单名' : '子菜单名'" required>
            <el-input
              v-model="selected.menu_name"
              :maxlength="isTopSelected ? 4 : 8"
              show-word-limit
              :placeholder="isTopSelected ? '最多4个汉字' : '最多8个汉字'"
              style="width: 240px"
            />
          </el-form-item>

          <template v-if="!topHasSub">
            <el-form-item label="菜单类型">
              <el-radio-group v-model="selected.menu_type">
                <el-radio value="view">网页链接</el-radio>
                <el-radio value="click">关键字回复</el-radio>
                <el-radio value="miniprogram">小程序</el-radio>
              </el-radio-group>
            </el-form-item>

            <!-- view -->
            <el-form-item v-if="selected.menu_type === 'view'" label="链接地址" required>
              <el-input v-model="selected.menu_url" placeholder="http(s):// 开头的网页地址" />
            </el-form-item>

            <!-- miniprogram -->
            <template v-if="selected.menu_type === 'miniprogram'">
              <el-form-item label="小程序appid" required>
                <el-input v-model="selected.menu_appid" placeholder="wx 开头的小程序 appid" />
              </el-form-item>
              <el-form-item label="页面路径" required>
                <el-input v-model="selected.menu_pagepath" placeholder="pages/index/index" />
              </el-form-item>
              <el-form-item label="兜底H5链接">
                <el-input v-model="selected.menu_url" placeholder="旧版微信不支持小程序时打开的网页（留空自动按租户H5入口生成）" />
              </el-form-item>
            </template>

            <!-- click -->
            <template v-if="selected.menu_type === 'click'">
              <el-form-item label="回复内容">
                <el-radio-group v-model="selected.message_type">
                  <el-radio value="text">文本</el-radio>
                  <el-radio value="image">图片</el-radio>
                  <el-radio value="voice">语音</el-radio>
                  <el-radio value="video">视频</el-radio>
                  <el-radio value="news">图文</el-radio>
                </el-radio-group>
              </el-form-item>

              <el-form-item v-if="!selected.message_type || selected.message_type === 'text'" label="文本内容" required>
                <el-input v-model="selected.text_content" type="textarea" :rows="3" maxlength="600" show-word-limit placeholder="点击菜单后回复的文本" />
              </el-form-item>

              <el-form-item v-if="selected.message_type === 'image'" label="图片素材" required>
                <div class="media-row">
                  <el-image v-if="selected.image_content" :src="selected.image_content" style="width: 90px; height: 90px" fit="cover" />
                  <el-button size="small" @click="openImgPicker((u) => { const btn = selected; if (btn) btn.image_content = u })">{{ selected.image_content ? '更换图片' : '选择图片' }}</el-button>
                </div>
              </el-form-item>

              <el-form-item v-if="selected.message_type === 'voice'" label="语音素材" required>
                <div class="media-row">
                  <el-input v-model="selected.voice_content" placeholder="语音文件地址" style="width: 300px" readonly />
                  <el-upload :show-file-list="false" accept=".mp3,.amr,.wav" :http-request="(opt: UploadRequestOptions) => uploadClickAsset(opt, 'voice')">
                    <el-button size="small">{{ selected.voice_content ? '更换语音' : '上传语音' }}</el-button>
                  </el-upload>
                </div>
              </el-form-item>

              <template v-if="selected.message_type === 'video'">
                <el-form-item label="视频素材" required>
                  <div class="media-row">
                    <el-input v-model="selected.video_content" placeholder="视频文件地址" style="width: 300px" readonly />
                    <el-upload :show-file-list="false" accept=".mp4" :http-request="(opt: UploadRequestOptions) => uploadClickAsset(opt, 'video')">
                      <el-button size="small">{{ selected.video_content ? '更换视频' : '上传视频' }}</el-button>
                    </el-upload>
                  </div>
                </el-form-item>
                <el-form-item label="视频标题" required>
                  <el-input v-model="selected.video_title" maxlength="100" style="width: 300px" />
                </el-form-item>
                <el-form-item label="视频描述" required>
                  <el-input v-model="selected.video_introduction" type="textarea" :rows="2" maxlength="200" />
                </el-form-item>
              </template>

              <el-form-item v-if="selected.message_type === 'news'" label="图文素材" required>
                <div class="media-row">
                  <el-tag v-if="selected.news_content" type="success">{{ selected.news_content }}</el-tag>
                  <el-button size="small" @click="openNewsPicker">{{ selected.news_content ? '重新选择' : '选择图文' }}</el-button>
                </div>
              </el-form-item>
            </template>
          </template>

          <el-alert v-else type="info" :closable="false" title="含子菜单的一级按钮仅保留名称，点击时展开子菜单" />
        </el-form>
      </div>
    </div>

    <!-- 图文素材选择弹窗 -->
    <el-dialog v-model="newsVisible" title="选择图文素材" width="640px" append-to-body>
      <div v-loading="newsLoading">
        <el-table v-if="newsItems.length" :data="newsItems" border style="width: 100%">
          <el-table-column label="标题" min-width="220">
            <template #default="{ row }">
              <div class="news-cell">
                <el-image
                  v-if="row.content?.news_item?.[0]?.thumb_url"
                  :src="row.content.news_item[0].thumb_url"
                  style="width: 56px; height: 40px; flex-shrink: 0"
                  fit="cover"
                />
                <span>{{ row.content?.news_item?.[0]?.title || row.media_id }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="media_id" prop="media_id" width="200" show-overflow-tooltip />
          <el-table-column label="操作" width="80" align="center">
            <template #default="{ row }">
              <el-button link type="primary" @click="pickNews(row)">选择</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-else-if="!newsLoading" description="暂无图文素材" />
      </div>
      <div class="news-total">共 {{ newsTotal }} 条图文素材</div>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.card-title {
  font-weight: 600;
}
.appid-tip {
  margin-left: 10px;
  color: #999;
  font-size: 12px;
  font-weight: 400;
}
.card-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
.menu-workbench {
  display: flex;
  gap: 24px;
  align-items: flex-start;
}
.phone-panel {
  flex-shrink: 0;
  width: 420px;
  border: 1px solid #e4e7ed;
  border-radius: 8px;
  padding: 16px;
  background: #fafafa;
}
.phone-tip {
  margin-bottom: 12px;
  color: #999;
  font-size: 12px;
}
.menu-columns {
  display: flex;
  gap: 10px;
}
.menu-column {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  min-height: 220px;
}
.sub-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 6px;
}
.menu-cell {
  position: relative;
  border: 1px solid #dcdfe6;
  background: #fff;
  border-radius: 4px;
  padding: 6px 22px 6px 8px;
  font-size: 12px;
  cursor: pointer;
  text-align: center;
  overflow: hidden;
}
.menu-cell.active {
  border-color: var(--el-color-primary);
  color: var(--el-color-primary);
}
.cell-name {
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cell-del {
  position: absolute;
  right: 4px;
  top: 50%;
  transform: translateY(-50%);
  color: #c0c4cc;
  font-size: 12px;
}
.cell-del:hover {
  color: var(--el-color-danger);
}
.add-cell {
  color: #c0c4cc;
}
.top-cell {
  font-weight: 600;
  border-top: 2px solid #dcdfe6;
}
.add-column {
  cursor: pointer;
}
.add-column-inner {
  border: 1px dashed #c0c4cc;
  border-radius: 4px;
  color: #999;
  text-align: center;
  padding: 8px 0;
  font-size: 12px;
  margin-top: auto;
}
.add-column-inner:hover {
  border-color: var(--el-color-primary);
  color: var(--el-color-primary);
}
.edit-panel {
  flex: 1;
  min-width: 0;
}
.media-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.news-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.news-total {
  margin-top: 8px;
  color: #999;
  font-size: 12px;
  text-align: right;
}
</style>
