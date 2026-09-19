<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { QuestionFilled } from '@element-plus/icons-vue'
import { getOpenConfig, saveOpenConfig, uploadTemplateCode, uploadVerifyFile } from '../api/open'
import type { OpenConfigData } from '../api/types'

/**
 * 微信开放平台设置（对照参考系统 WebSystem/component 两张截图严格重做）
 * - 顶部说明块：灰底 + 左绿竖条，第 4 条红字
 * - 单行水平表单：label 右对齐、窄输入框、行内说明
 * - 只读项（授权事件接收配置/消息与事件接收配置/各域名/IP白名单）由后端自动生成
 * - 三按钮：保存 | 上传代码到小程序模板库 | 上传域名校验文件
 */
const loading = ref(false)
const saving = ref(false)
const uploadingTpl = ref(false)
const uploadingVerify = ref(false)
const verifyInputRef = ref<HTMLInputElement>()

/** 后端下发的配置（含只读项） */
const cfg = ref<OpenConfigData | null>(null)

/** 用户填写项（对照参考系统 info[status]/appid/appsecret/token/key/kfappid） */
const form = reactive({
  status: 0,
  component_appid: '',
  component_appsecret: '',
  token: '',
  encoding_aes_key: '',
  kfappid: '',
})

async function fetchConfig() {
  loading.value = true
  try {
    const data = await getOpenConfig()
    cfg.value = data
    form.status = Number(data.status ?? 0)
    form.component_appid = data.component_appid || ''
    form.component_appsecret = data.component_appsecret || ''
    form.token = data.token || ''
    form.encoding_aes_key = data.encoding_aes_key || ''
    form.kfappid = data.kfappid || ''
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

/** 保存（当页保存不跳转，保存后重新拉取回显） */
async function handleSave() {
  saving.value = true
  try {
    await saveOpenConfig({
      status: form.status,
      component_appid: form.component_appid,
      component_appsecret: form.component_appsecret,
      token: form.token,
      encoding_aes_key: form.encoding_aes_key,
      kfappid: form.kfappid,
    })
    ElMessage.success('设置成功')
    fetchConfig()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

/** 上传代码到小程序模板库 */
async function handleUploadTemplate() {
  uploadingTpl.value = true
  try {
    await uploadTemplateCode()
    ElMessage.success('上传成功，最新草稿已添加到小程序模板库')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    uploadingTpl.value = false
  }
}

/** 上传域名校验文件：触发隐藏 file 选择框（仅 txt） */
function triggerVerifyUpload() {
  verifyInputRef.value?.click()
}

async function onVerifyFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  uploadingVerify.value = true
  try {
    const data = await uploadVerifyFile(file)
    ElMessage.success(`上传成功：${data.filename}`)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    uploadingVerify.value = false
  }
}

onMounted(fetchConfig)
</script>

<template>
  <div class="openplat-page" v-loading="loading">
    <!-- 页头：对照参考系统 layui-card-header -->
    <div class="page-header">微信开放平台设置</div>

    <div class="openplat-body">
      <!-- 顶部说明块：灰底 + 左4px绿竖条，第4条红字 -->
      <div class="config-guide">
        小程序接入前，需先在开放平台的开发配置中绑定开发小程序，并将代码上传至小程序模板库；用户完成授权后，即可在管理后台直接上传并提交代码<br />
        常见问题：<br />
        1、开放平台账号是否必须申请？ 并非必须。申请后可更便捷地接入用户授权的公众号及小程序，无需再逐个手动绑定<br />
        2、开发小程序仅作为代码载体，用于把代码上传到开放平台供其他小程序授权后获取并发布，绑定后不能用于正常发布，因此建议绑定一个闲置不运营的小程序账号<br />
        3、上传代码时若提示61006: component ticket is invalid，请先核对以下参数是否与开放平台对应参数保持一致，确认无误后等待约十分钟再重试，因为开放平台每隔十分钟才会推送一次调用凭证<br />
        <span class="red">4、当小程序代码需要更新时，应先在此处把新版本代码上传到模板库，再到用户账号下上传代码并提交审核</span>
      </div>

      <!-- 单行水平表单：label 右对齐 + 窄输入框 + 行内说明 -->
      <el-form label-width="160px" label-position="right" size="default">
        <el-form-item label="状态：">
          <el-radio-group v-model="form.status">
            <el-radio :label="1">开启</el-radio>
            <el-radio :label="0">关闭</el-radio>
          </el-radio-group>
          <span class="form-help">开启后允许公众号或小程序通过授权接入系统</span>
        </el-form-item>

        <el-form-item label="AppID：">
          <el-input v-model="form.component_appid" class="narrow-input" clearable />
        </el-form-item>

        <el-form-item label="AppSecret：">
          <el-input v-model="form.component_appsecret" class="narrow-input" clearable />
        </el-form-item>

        <el-form-item label="授权事件接收配置：">
          <div class="readonly-text">{{ cfg?.event_url || '' }}</div>
        </el-form-item>

        <el-form-item label="消息与事件接收配置：">
          <div class="readonly-text">{{ cfg?.msg_url_pattern || '' }}</div>
        </el-form-item>

        <el-form-item label="消息校验Token：">
          <el-input v-model="form.token" class="narrow-input" clearable />
          <span class="form-help">需与开放平台接入配置中填写的值相同，仅限英文或数字，长度为3到32个字符</span>
        </el-form-item>

        <el-form-item label="消息加解密Key：">
          <el-input v-model="form.encoding_aes_key" class="narrow-input" clearable />
          <span class="form-help">需与开放平台接入配置中填写的值相同，仅限英文或数字，长度固定为43个字符</span>
        </el-form-item>

        <el-form-item label="授权发起页域名：">
          <div class="readonly-text">{{ cfg?.domain || '' }}</div>
        </el-form-item>

        <el-form-item label="公众号开发域名：">
          <div class="readonly-text">{{ cfg?.domain || '' }}</div>
        </el-form-item>

        <el-form-item label="小程序服务器域名：">
          <div class="readonly-text">{{ cfg?.domain || '' }}</div>
          <span class="form-help">
            启用云存储时，还需将阿里云的Bucket域名、七牛云的Url或腾讯云的Url一并加入
            <el-popover placement="right" :width="380" trigger="hover">
              <template #reference>
                <el-icon class="help-icon"><QuestionFilled /></el-icon>
              </template>
              <div class="popover-text">
                合法服务器域名总数上限为1000个；其中 Request域名、Socket域名、Uploadfile域名、Download域名、Udp域名、tcp域名各自最多可配置200个
              </div>
            </el-popover>
          </span>
        </el-form-item>

        <el-form-item label="小程序业务域名：">
          <div class="readonly-text">{{ cfg?.domain || '' }}</div>
        </el-form-item>

        <el-form-item label="IP白名单：">
          <div class="readonly-text">{{ cfg?.server_ip || '' }}</div>
        </el-form-item>

        <el-form-item label="开发小程序AppID：">
          <el-input v-model="form.kfappid" class="narrow-input" clearable />
          <span class="form-help">添加前请先到 开放平台-第三方平台-开发配置 中登记开发小程序</span>
        </el-form-item>

        <!-- 三按钮：保存（绿）| 上传代码到小程序模板库 | 上传域名校验文件 -->
        <el-form-item label-width="160px" class="btn-row">
          <el-button class="btn-save" :loading="saving" @click="handleSave">保 存</el-button>
          <el-button class="btn-plain" :loading="uploadingTpl" @click="handleUploadTemplate">上传代码到小程序模板库</el-button>
          <el-button class="btn-plain" :loading="uploadingVerify" @click="triggerVerifyUpload">上传域名校验文件</el-button>
          <input ref="verifyInputRef" type="file" accept=".txt" style="display: none" @change="onVerifyFileChange" />
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<style scoped>
.openplat-page {
  background: #fff;
  min-height: 100%;
  /* 单选框/聚焦色跟随参考系统绿（截图 radio 为绿色） */
  --el-color-primary: #009688;
}

/* 页头：小标题 + 底部分隔线，对照参考系统 layui-card-header */
.page-header {
  padding: 12px 16px;
  font-size: 14px;
  color: #333;
  border-bottom: 1px solid #e6e6e6;
}

.openplat-body {
  padding: 16px;
}

/* 顶部说明块：灰底 + 左4px绿竖条（与系统设置页同款） */
.config-guide {
  margin-bottom: 24px;
  padding: 15px;
  background-color: #f6f6f6;
  border-left: 4px solid #28a745;
  font-size: 13px;
  line-height: 1.9;
  color: #555;
}

.config-guide .red {
  color: red;
}

/* 每行：[右对齐标签] [窄输入框] [右侧说明] */
:deep(.el-form-item) {
  margin-bottom: 22px;
}

:deep(.el-form-item__label) {
  color: #606266;
  font-weight: normal;
}

:deep(.el-form-item__content) {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}

/* 窄输入框：固定 240px */
.narrow-input {
  width: 240px;
  flex: none;
}

/* 只读项文本：对照参考系统 layui-form-mid color:#111 */
.readonly-text {
  color: #111;
  font-size: 14px;
  line-height: 32px;
  word-break: break-all;
}

/* 行内说明文字：输入框右侧灰色小字 */
.form-help {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
  line-height: 1.6;
}

/* 问号提示图标（小程序服务器域名行） */
.help-icon {
  margin-left: 4px;
  color: #409eff;
  font-size: 15px;
  cursor: pointer;
  vertical-align: middle;
}

.popover-text {
  font-size: 13px;
  line-height: 2;
  color: #555;
}

/* 按钮行 */
.btn-row {
  margin-top: 20px;
}

/* 保存：绿色主按钮（对照参考系统 layui-btn #009688） */
.btn-save {
  background-color: #009688;
  border-color: #009688;
  color: #fff;
}

.btn-save:hover,
.btn-save:focus {
  background-color: #00a89a;
  border-color: #00a89a;
  color: #fff;
}

/* 次级按钮：白底灰边 */
.btn-plain {
  background-color: #fff;
  border-color: #dcdfe6;
  color: #606266;
}

.btn-plain:hover,
.btn-plain:focus {
  background-color: #f5f7fa;
  border-color: #c0c4cc;
  color: #333;
}
</style>
