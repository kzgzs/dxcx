<script setup lang="ts">
/**
 * 设计 - 移动端后台（对照参考系统 canzhao/app/view/designer_mobile/index.html 重做）
 *
 * 参考口径（配置类）：
 * - 手机预览：管理端登录页样式（标题 + 账号/密码输入占位 + 登录按钮 + 其他登录方式），
 *   顶部背景图（bgimg）实时生效
 * - 表单：顶部背景图片（上传图片，建议尺寸 750×750 像素）+ 当页保存
 * - 数据：designer_mobile 表 aid 单行，data 整体 JSON（GET /admin/designer/mobile/get，POST /save）
 *
 * 附加（入口引导口径）：手机端管理地址卡片，buildH5Url 口径
 * （DEV http://localhost:5174/#/…；PROD 同域 /h5/#/…），二维码 + 复制 + 打开
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import QRCode from 'qrcode'
import { getDesignerMobile, saveDesignerMobile } from '../../api/designerMobile'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const submitting = ref(false)
const updatetime = ref(0)

const form = reactive({
  id: 0,
  bgimg: '',
})

async function fetchInfo() {
  loading.value = true
  try {
    const info = await getDesignerMobile()
    form.id = info.id || 0
    form.bgimg = info.data?.bgimg || ''
    updatetime.value = info.updatetime || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    const res = await saveDesignerMobile({
      id: form.id || undefined,
      data: { bgimg: form.bgimg },
    })
    if (res?.id) form.id = res.id
    ElMessage.success('保存成功')
    fetchInfo()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

// ============ 顶部背景图片选择（任务292 接入 ImgIconPicker） ============
const imgPickerVisible = ref(false)

function onImgPick(urls: string[]) {
  if (urls[0]) form.bgimg = urls[0]
}

/** 预览顶部背景：有图走图、无图灰底占位（对照参考页 bg_div1 背景口径） */
const previewBgStyle = computed(() =>
  form.bgimg
    ? { backgroundImage: `url(${form.bgimg})`, backgroundSize: 'cover', backgroundPosition: 'center' }
    : { background: '#eef0f6' },
)

// ============ 手机端管理地址（buildH5Url 口径，任务266） ============
const ADMIN_PATH = 'admin/index/index'
function buildH5Url(p: string): string {
  const rel = p.replace(/^\//, '')
  return import.meta.env.DEV
    ? 'http://localhost:5174/#/' + rel
    : location.origin + '/h5/#/' + rel
}
const h5AdminUrl = buildH5Url(ADMIN_PATH)
const qrDataUrl = ref('')

async function makeQr() {
  try {
    qrDataUrl.value = await QRCode.toDataURL(h5AdminUrl, { width: 180, margin: 1 })
  } catch {
    qrDataUrl.value = ''
  }
}

function copyText(text: string) {
  const done = () => ElMessage.success('已复制')
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done))
  } else {
    fallbackCopy(text, done)
  }
}

function fallbackCopy(text: string, done: () => void) {
  const ta = document.createElement('textarea')
  ta.value = text
  ta.style.position = 'fixed'
  ta.style.opacity = '0'
  document.body.appendChild(ta)
  ta.select()
  try {
    document.execCommand('copy')
    done()
  } catch {
    ElMessage.warning('复制失败，请手动复制')
  }
  document.body.removeChild(ta)
}

function previewH5() {
  window.open(h5AdminUrl, '_blank')
}

onMounted(() => {
  fetchInfo()
  makeQr()
})
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <div class="dm-header">
          <span>移动端后台</span>
          <span v-if="updatetime" class="dm-muted">最近更新：{{ formatTime(updatetime) }}</span>
        </div>
      </template>

      <div class="dm-layout">
        <!-- 左：手机预览（对照参考页登录页样式：标题 + 账号密码 + 登录按钮 + 其他登录方式） -->
        <div class="dm-preview">
          <div class="phone-frame">
            <div class="phone-screen">
              <!-- 顶部状态栏（对照参考页 dsn-topbar） -->
              <div class="pv-statusbar">
                <span>12:00</span>
                <span class="pv-statusbar-title">管理员登录</span>
                <span>100%</span>
              </div>
              <!-- 顶部背景图区域（bgimg 实时预览） -->
              <div class="pv-bg" :style="previewBgStyle">
                <span v-if="!form.bgimg" class="pv-bg-tip">顶部背景图</span>
              </div>
              <!-- 登录卡片 -->
              <div class="pv-card">
                <div class="pv-title">管理员登录</div>
                <div class="pv-input">请输入登录账号</div>
                <div class="pv-input">请输入密码</div>
                <div class="pv-btn">登录</div>
                <div class="pv-other">
                  <span class="pv-other-line"></span>
                  <span class="pv-other-text">其他登录方式</span>
                  <span class="pv-other-line"></span>
                </div>
                <div class="pv-other-icons">
                  <span class="pv-other-icon">微</span>
                  <span class="pv-other-icon">码</span>
                </div>
              </div>
            </div>
          </div>
          <div class="preview-tip">手机实时预览</div>
        </div>

        <!-- 右：配置表单 + 手机端管理地址（对照参考页配置类口径：当页保存） -->
        <div class="dm-form">
          <el-form label-width="120px" label-position="left" style="max-width: 560px">
            <el-form-item label="顶部背景图片">
              <div class="img-field">
                <span class="img-box">
                  <img v-if="form.bgimg" :src="form.bgimg" />
                  <span v-else class="img-empty">—</span>
                </span>
                <el-button size="small" @click="imgPickerVisible = true">选择图片</el-button>
                <el-button v-if="form.bgimg" link type="danger" size="small" @click="form.bgimg = ''">清除</el-button>
              </div>
              <div class="form-tip">建议尺寸：750×750像素</div>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
            </el-form-item>
          </el-form>

          <el-divider content-position="left">手机端管理地址</el-divider>
          <div class="addr-box">
            <img v-if="qrDataUrl" :src="qrDataUrl" class="addr-qr" alt="手机端管理二维码" />
            <div v-else class="addr-qr addr-qr-empty">二维码生成失败</div>
            <div class="addr-meta">
              <div class="addr-desc">手机扫码直达移动端后台（H5 管理助手），管理员登录后即可使用</div>
              <div class="addr-url">{{ h5AdminUrl }}</div>
              <div class="addr-actions">
                <el-button size="small" @click="copyText(h5AdminUrl)">复制地址</el-button>
                <el-button size="small" type="primary" plain @click="previewH5">打开</el-button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </el-card>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="(urls: string[]) => onImgPick(urls)" />
  </div>
</template>

<style scoped>
.dm-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.dm-muted {
  color: #909399;
  font-size: 12px;
}

.dm-layout {
  display: flex;
  gap: 24px;
  align-items: flex-start;
}

/* ===== 手机预览（对照参考页登录页结构） ===== */
.dm-preview {
  flex-shrink: 0;
}

.phone-frame {
  width: 300px;
  height: 600px;
  border: 10px solid #2c2c2c;
  border-radius: 32px;
  overflow: hidden;
  background: #fff;
}

.phone-screen {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.pv-statusbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 24px;
  padding: 0 12px;
  background: #333;
  color: #fff;
  font-size: 10px;
}

.pv-statusbar-title {
  font-size: 12px;
}

.pv-bg {
  height: 150px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.pv-bg-tip {
  color: #c0c4cc;
  font-size: 12px;
}

.pv-card {
  flex: 1;
  padding: 20px 24px;
  display: flex;
  flex-direction: column;
}

.pv-title {
  font-size: 18px;
  font-weight: 700;
  color: #303133;
  margin-bottom: 18px;
}

.pv-input {
  height: 36px;
  line-height: 36px;
  padding: 0 12px;
  border-radius: 18px;
  background: #f5f7fa;
  color: #cacaca;
  font-size: 12px;
  margin-bottom: 12px;
}

.pv-btn {
  margin-top: 6px;
  height: 38px;
  line-height: 38px;
  text-align: center;
  border-radius: 19px;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  background: linear-gradient(90deg, #7b7de5 0%, rgba(123, 125, 229, 0.8) 100%);
}

.pv-other {
  margin-top: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.pv-other-line {
  width: 42px;
  height: 1px;
  background: #d8d8d8;
}

.pv-other-text {
  color: #d8d8d8;
  font-size: 11px;
}

.pv-other-icons {
  margin-top: 14px;
  display: flex;
  justify-content: center;
  gap: 22px;
}

.pv-other-icon {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: #f0f1f5;
  color: #909399;
  font-size: 13px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.preview-tip {
  margin-top: 8px;
  text-align: center;
  font-size: 12px;
  color: #909399;
}

/* ===== 右侧表单区 ===== */
.dm-form {
  flex: 1;
  min-width: 0;
}

.form-tip {
  color: #909399;
  font-size: 12px;
}

.img-field {
  display: flex;
  align-items: center;
  gap: 8px;
}

.img-box {
  width: 40px;
  height: 40px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background: #fafafa;
  flex-shrink: 0;
}

.img-box img {
  max-width: 100%;
  max-height: 100%;
}

.img-empty {
  color: #c0c4cc;
}

/* 手机端管理地址卡片 */
.addr-box {
  display: flex;
  gap: 20px;
  align-items: flex-start;
  max-width: 560px;
  padding: 16px;
  border: 1px solid #ebeef5;
  border-radius: 8px;
  background: #fafafa;
}

.addr-qr {
  width: 140px;
  height: 140px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  background: #fff;
  flex-shrink: 0;
}

.addr-qr-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 12px;
}

.addr-meta {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.addr-desc {
  font-size: 12px;
  color: #909399;
  line-height: 1.6;
}

.addr-url {
  font-size: 12px;
  color: #409eff;
  word-break: break-all;
}

.addr-actions {
  display: flex;
  gap: 8px;
}
</style>
