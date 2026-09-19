<script setup lang="ts">
/**
 * 设计 - 登录页面（对照参考口径重做，任务282）
 * - 字段口径：页面样式(1样式一/2样式二)、背景(1背景色/2背景图)、LOGO(仅样式二)、卡片背景颜色、
 *   标题样式(left居左/center居中/right居右)、标题内容/颜色、副标题(为空不显示)/颜色、
 *   登录按钮颜色(1跟随主题色/2自定义)、按钮文字色、验证码色、注册文字/忘记密码色、
 *   协议提示文字/提示色/协议色；预览另含注册账号(regbtncolor)与其他登录方式(wxicon/wxtext)
 * - 布局：左矮手机框实时预览 + 右表单；当页保存不刷新不跳转
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getLoginDesign, saveLoginDesign, type LoginTheme } from '../../api/designer'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const saving = ref(false)
const recordId = ref(0)
/** 页面样式：1样式一 / 2样式二（记录级字段，非主题JSON） */
const pageType = ref(1)
/** 系统主题色（admin_set.color1，btntype=1 预览跟随用） */
const themeColor = ref('#0256FF')

/** 默认主题（后端未返回时的前端兜底，与后端初始化口径一致） */
function defaultTheme(): LoginTheme {
  return {
    logo: '',
    bgtype: 1,
    bgcolor: '',
    bgimg: '',
    cardcolor: '#FFFFFF',
    titletype: 'center',
    title: '',
    titlecolor: '#000000',
    subhead: '推荐使用手机号验证码登录',
    subheadcolor: '#A8B5D3',
    btntype: 1,
    btncolor: '#0256FF',
    btnwordcolor: '#FFFFFF',
    codecolor: '#0256FF',
    regtipcolor: '#5E6066',
    regpwdbtncolor: '#666666',
    regbtncolor: '#666666',
    wxicon: '',
    wxtext: '授权登录',
    xytipword: '我已阅读并同意',
    xytipcolor: '#D8D8D8',
    xycolor: '#0256FF',
  }
}

const form = reactive<LoginTheme>(defaultTheme())

async function fetchDesign() {
  loading.value = true
  try {
    const data = await getLoginDesign()
    recordId.value = data.id || 0
    pageType.value = data.type === 2 ? 2 : 1
    if (data.themecolor) themeColor.value = data.themecolor
    Object.assign(form, defaultTheme(), data.data || {})
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    const res = await saveLoginDesign({
      id: recordId.value || undefined,
      type: pageType.value,
      data: JSON.parse(JSON.stringify(form)),
    })
    recordId.value = res.id || recordId.value
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

// ============ 图片选择（LOGO / 背景图，任务292 接入 ImgIconPicker） ============
const imgPickerVisible = ref(false)
const pickTarget = ref<((url: string) => void) | null>(null)

function openImgPick(set: (url: string) => void) {
  pickTarget.value = set
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (pickTarget.value && urls[0]) pickTarget.value(urls[0])
  pickTarget.value = null
}

// ============ 预览派生样式 ============

/** 预览背景：bgtype=1 背景色 / bgtype=2 背景图片 */
const previewBgStyle = computed(() =>
  form.bgtype === 2 && form.bgimg
    ? { backgroundImage: `url(${form.bgimg})`, backgroundSize: '100% 100%', backgroundRepeat: 'no-repeat', backgroundPosition: 'center' }
    : { background: form.bgcolor || '#ffffff' },
)

/** 登录按钮背景：btntype=1 跟随主题色 / btntype=2 自定义 */
const btnBgColor = computed(() => (form.btntype === 2 ? form.btncolor : themeColor.value || '#0256FF'))

/** 微信授权登录图标：未上传走默认绿色微信图标 */
const wxIconSvg =
  'data:image/svg+xml;charset=utf-8,' +
  encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50"><circle cx="25" cy="25" r="25" fill="#07C160"/><text x="25" y="33" font-size="22" text-anchor="middle" fill="#fff" font-family="sans-serif">微</text></svg>`,
  )
const wxIconUrl = computed(() => form.wxicon || wxIconSvg)

/** 手机号快捷登录固定图标 */
const telIconSvg =
  'data:image/svg+xml;charset=utf-8,' +
  encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50"><circle cx="25" cy="25" r="25" fill="#0256FF"/><text x="25" y="33" font-size="22" text-anchor="middle" fill="#fff" font-family="sans-serif">机</text></svg>`,
  )

onMounted(fetchDesign)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <div class="dl-header">
          <span>登录页面</span>
          <span class="dl-tip">保存后客户端登录页实时生效；未配置时客户端走默认样式</span>
        </div>
      </template>

      <div class="dl-layout">
        <!-- 左：手机框实时预览 -->
        <div class="dl-preview">
          <div class="phone-frame">
            <div class="phone-screen">
              <!-- 状态栏 -->
              <div class="pv-statusbar">
                <span class="pv-sb-left">12:00</span>
                <span class="pv-sb-right">100%</span>
              </div>
              <div class="pv-navbar">登录页面</div>

              <!-- 页面主体 -->
              <div class="pv-body" :style="previewBgStyle">
                <!-- 样式一：标题在卡片上方 -->
                <div v-if="pageType === 1" class="pv-head1">
                  <div class="pv-title1" :style="{ color: form.titlecolor, textAlign: form.titletype as any }">
                    {{ form.title || '欢迎使用' }}
                  </div>
                  <div
                    v-if="form.subhead"
                    class="pv-subhead1"
                    :style="{ color: form.subheadcolor, textAlign: form.titletype as any }"
                  >
                    {{ form.subhead }}
                  </div>
                </div>

                <!-- 样式二：LOGO 在卡片上方 -->
                <div v-if="pageType === 2" class="pv-logo-wrap">
                  <img v-if="form.logo" :src="form.logo" class="pv-logo" />
                  <div v-else class="pv-logo pv-logo-empty">LOGO</div>
                </div>

                <!-- 卡片 -->
                <div class="pv-card" :style="{ background: form.cardcolor }">
                  <!-- 样式二：标题移入卡片内 -->
                  <div v-if="pageType === 2" class="pv-head2">
                    <div class="pv-title2" :style="{ color: form.titlecolor, textAlign: form.titletype as any }">
                      {{ form.title || '欢迎使用' }}
                    </div>
                    <div
                      v-if="form.subhead"
                      class="pv-subhead1"
                      :style="{ color: form.subheadcolor, textAlign: form.titletype as any }"
                    >
                      {{ form.subhead }}
                    </div>
                  </div>

                  <div class="pv-input">请输入手机号码</div>
                  <div class="pv-input pv-input-code">
                    <span>请输入验证码</span>
                    <span class="pv-code" :style="{ color: form.codecolor }">获取验证码</span>
                  </div>

                  <div class="pv-xy">
                    <span class="pv-xy-check"></span>
                    <span :style="{ color: form.xytipcolor }">{{ form.xytipword }}</span>
                    <span :style="{ color: form.xycolor }">《用户协议》《隐私政策》</span>
                  </div>

                  <div class="pv-btn" :style="{ background: btnBgColor, color: form.btnwordcolor }">登录</div>

                  <div class="pv-regline">
                    <span :style="{ color: form.regbtncolor }">注册账号</span>
                  </div>

                  <div class="pv-other">
                    <div class="pv-other-row">
                      <div class="pv-other-line"></div>
                      <div class="pv-other-text">其他登录方式</div>
                      <div class="pv-other-line"></div>
                    </div>
                    <div class="pv-other-icons">
                      <div class="pv-other-item">
                        <img :src="wxIconUrl" class="pv-other-icon" />
                        <div class="pv-other-label">{{ form.wxtext || '授权登录' }}</div>
                      </div>
                      <div class="pv-other-item">
                        <img :src="telIconSvg" class="pv-other-icon" />
                        <div class="pv-other-label">手机号快捷登录</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="preview-tip">手机实时预览</div>
        </div>

        <!-- 右：表单 -->
        <div class="dl-form">
          <el-form label-width="130px" label-position="left">
            <el-form-item label="页面样式">
              <el-radio-group v-model="pageType">
                <el-radio :value="1">样式一</el-radio>
                <el-radio :value="2">样式二</el-radio>
              </el-radio-group>
            </el-form-item>

            <el-form-item label="背景">
              <el-radio-group v-model="form.bgtype">
                <el-radio :value="1">背景色</el-radio>
                <el-radio :value="2">背景图片</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item v-if="form.bgtype === 1" label="背景颜色">
              <el-color-picker v-model="form.bgcolor" />
            </el-form-item>
            <el-form-item v-else label="背景图片">
              <div class="img-field">
                <span class="img-box">
                  <img v-if="form.bgimg" :src="form.bgimg" />
                  <span v-else class="img-empty">—</span>
                </span>
                <el-button size="small" @click="openImgPick((url) => (form.bgimg = url))">选择图片</el-button>
                <el-button v-if="form.bgimg" link type="danger" size="small" @click="form.bgimg = ''">清除</el-button>
              </div>
              <div v-if="form.bgimg" class="img-preview"><img :src="form.bgimg" /></div>
            </el-form-item>

            <template v-if="pageType === 2">
              <el-form-item label="LOGO">
                <div class="img-field">
                  <span class="img-box">
                    <img v-if="form.logo" :src="form.logo" />
                    <span v-else class="img-empty">—</span>
                  </span>
                  <el-button size="small" @click="openImgPick((url) => (form.logo = url))">选择图片</el-button>
                  <el-button v-if="form.logo" link type="danger" size="small" @click="form.logo = ''">清除</el-button>
                </div>
                <div class="field-tip">建议尺寸：100×100像素</div>
                <div v-if="form.logo" class="img-preview img-preview-logo"><img :src="form.logo" /></div>
              </el-form-item>
            </template>

            <el-form-item label="卡片背景颜色">
              <el-color-picker v-model="form.cardcolor" />
            </el-form-item>

            <el-form-item label="标题样式">
              <el-radio-group v-model="form.titletype">
                <el-radio value="left">居左</el-radio>
                <el-radio value="center">居中</el-radio>
                <el-radio value="right">居右</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="标题内容">
              <el-input v-model="form.title" placeholder="如：欢迎使用鼎极多端" style="width: 300px" />
            </el-form-item>
            <el-form-item label="标题文字颜色">
              <el-color-picker v-model="form.titlecolor" />
            </el-form-item>
            <el-form-item label="副标题内容">
              <el-input v-model="form.subhead" placeholder="为空不显示" style="width: 300px" />
            </el-form-item>
            <el-form-item label="副标题文字颜色">
              <el-color-picker v-model="form.subheadcolor" />
            </el-form-item>

            <el-form-item label="登录按钮颜色">
              <el-radio-group v-model="form.btntype">
                <el-radio :value="1">跟随主题色</el-radio>
                <el-radio :value="2">自定义</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item v-if="form.btntype === 2" label="按钮颜色">
              <el-color-picker v-model="form.btncolor" />
            </el-form-item>
            <el-form-item label="登录按钮文字">
              <el-color-picker v-model="form.btnwordcolor" />
            </el-form-item>
            <el-form-item label="验证码">
              <el-color-picker v-model="form.codecolor" />
            </el-form-item>
            <el-form-item label="注册文字/忘记密码">
              <el-color-picker v-model="form.regpwdbtncolor" />
            </el-form-item>

            <el-form-item label="协议提示文字">
              <el-input v-model="form.xytipword" placeholder="我已阅读并同意" style="width: 300px" />
            </el-form-item>
            <el-form-item label="协议提示文字颜色">
              <el-color-picker v-model="form.xytipcolor" />
            </el-form-item>
            <el-form-item label="协议文字颜色">
              <el-color-picker v-model="form.xycolor" />
            </el-form-item>

            <el-form-item>
              <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
            </el-form-item>
          </el-form>
        </div>
      </div>
    </el-card>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="(urls: string[]) => onImgPick(urls)" />
  </div>
</template>

<style scoped>
.dl-header {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.dl-tip {
  font-size: 12px;
  color: #909399;
}

.dl-layout {
  display: flex;
  gap: 24px;
  align-items: flex-start;
}

/* 左：手机预览 */
.dl-preview {
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
  height: 22px;
  line-height: 22px;
  background: #333;
  color: #fff;
  font-size: 11px;
  display: flex;
  justify-content: space-between;
  padding: 0 12px;
  flex-shrink: 0;
}

.pv-navbar {
  height: 30px;
  line-height: 30px;
  background: #333;
  color: #fff;
  font-size: 14px;
  text-align: center;
  flex-shrink: 0;
}

.pv-body {
  flex: 1;
  overflow-y: auto;
  padding-bottom: 12px;
}

/* 样式一：卡片上方标题区 */
.pv-head1 {
  width: 252px;
  margin: 0 auto;
  padding-top: 24px;
}

.pv-title1 {
  font-size: 22px;
  font-weight: bold;
  line-height: 34px;
}

.pv-subhead1 {
  font-size: 12px;
  line-height: 18px;
}

/* 样式二：LOGO 区 */
.pv-logo-wrap {
  width: 80px;
  height: 80px;
  margin: 16px auto;
  border-radius: 6px;
  overflow: hidden;
}

.pv-logo {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-logo-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f0f1f5;
  color: #b0b4bd;
  font-size: 11px;
}

/* 卡片 */
.pv-card {
  width: 252px;
  margin: 12px auto 0;
  padding: 16px;
  border-radius: 12px;
  box-sizing: border-box;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.pv-head2 {
  margin-bottom: 4px;
}

.pv-title2 {
  font-size: 18px;
  font-weight: bold;
  line-height: 28px;
}

.pv-input {
  height: 38px;
  line-height: 38px;
  border-radius: 38px;
  background-color: #f5f7fa;
  padding: 0 14px;
  color: #cacaca;
  font-size: 12px;
  margin-top: 12px;
  display: flex;
  justify-content: space-between;
}

.pv-code {
  font-size: 11px;
}

.pv-xy {
  margin-top: 10px;
  font-size: 11px;
  line-height: 16px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}

.pv-xy-check {
  width: 11px;
  height: 11px;
  border-radius: 100%;
  background-color: #f5f7fa;
  border: 1px solid #d3d5d7;
  display: inline-block;
  margin-right: 4px;
  flex-shrink: 0;
}

.pv-btn {
  margin-top: 12px;
  height: 38px;
  line-height: 38px;
  border-radius: 38px;
  text-align: center;
  font-weight: bold;
  font-size: 14px;
}

.pv-regline {
  margin-top: 8px;
  font-size: 12px;
  line-height: 30px;
}

/* 其他登录方式 */
.pv-other {
  width: 210px;
  margin: 18px auto 4px;
}

.pv-other-row {
  display: flex;
  align-items: center;
}

.pv-other-line {
  flex: 1;
  height: 1px;
  background: #d8d8d8;
}

.pv-other-text {
  margin: 0 8px;
  color: #d8d8d8;
  font-size: 11px;
  flex-shrink: 0;
}

.pv-other-icons {
  display: flex;
  justify-content: space-around;
  margin-top: 14px;
}

.pv-other-item {
  text-align: center;
}

.pv-other-icon {
  width: 40px;
  height: 40px;
}

.pv-other-label {
  font-size: 11px;
  margin-top: 4px;
  color: #888888;
}

.preview-tip {
  margin-top: 8px;
  text-align: center;
  font-size: 12px;
  color: #909399;
}

/* 右：表单 */
.dl-form {
  flex: 1;
  min-width: 0;
}

/* 行距紧凑（任务287：表单项间距减半） */
.dl-form :deep(.el-form-item) {
  margin-bottom: 8px;
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

.field-tip {
  font-size: 12px;
  color: #909399;
  margin-top: 4px;
}

.img-preview {
  width: 150px;
  height: 100px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  margin-top: 8px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fafafa;
}

.img-preview img {
  max-width: 100%;
  max-height: 100%;
}

.img-preview-logo {
  width: 100px;
  height: 100px;
}
</style>
