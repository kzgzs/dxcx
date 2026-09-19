<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getSystemConfig, saveSystemConfig } from '../api/system'

// 附件设置（严格按参考系统真实截图重写）
const loading = ref(false)
const saving = ref(false)

const form = reactive({
  storage_type: 'local',         // local/2/3/4 = 本地/阿里云/七牛云/腾讯云
  oss_key: '',                    // Access Key ID / Accesskey / APPID
  oss_secret: '',                 // Access Key Secret / Secretkey / SecretID / SecretKEY
  oss_bucket: '',                 // Bucket(空间名称) / Bucket(存储桶名称)
  oss_endpoint: '',               // EndPoint / bucket 所属地域
  oss_domain: '',                 // Bucket 域名 / Url
  image_compress: '0',            // 图片压缩 0 关闭 1 开启
  thumb_width: 1,                 // 最大宽度（默认值"1"）
  thumb_height: 1,                // 最大高度（默认值"1"）
  delete_origin: '0',             // 删除原图 0 关闭 1 开启
  upload_max: 100,                // 服务器单次上传限制 MB（默认 100，后端真实值覆盖）
})

// 页面标题：固定为"附件设置"

// 解析 PHP ini 上传限制值（如 "100M" / "2G" / "512K" / 纯数字），换算为 MB；解析失败兜底 100M
function parseUploadLimit(val?: string | number | null): number {
  if (val === undefined || val === null || val === '') return 100
  const m = String(val).trim().toUpperCase().match(/^(\d+(?:\.\d+)?)\s*([KMGT]?)/)
  if (!m) return 100
  const num = parseFloat(m[1])
  const unit = m[2]
  let mb = num
  if (unit === 'G') mb = num * 1024
  else if (unit === 'K') mb = num / 1024
  return Math.max(1, Math.round(mb))
}

async function fetchConfig() {
  loading.value = true
  try {
    const data = await getSystemConfig('remote')
    const remoteData = data as any
    Object.assign(form, {
      storage_type: remoteData.type ? String(remoteData.type) : 'local',
      oss_key: (remoteData.alioss as any)?.key || '1',
      oss_secret: (remoteData.alioss as any)?.secret || '1',
      oss_bucket: (remoteData.alioss as any)?.bucket || '1',
      oss_endpoint: (remoteData.alioss as any)?.ossurl || '1',
      oss_domain: (remoteData.alioss as any)?.url || '1',
      image_compress: String(remoteData.thumb || 0),
      thumb_width: Number(remoteData.thumb_width) || 1,
      thumb_height: Number(remoteData.thumb_height) || 1,
      delete_origin: remoteData.delete_origin === 0 ? '1' : '0',
      upload_max: parseUploadLimit(remoteData.upload_max_filesize),
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// 当页保存：成功后原地提示，不刷新不跳转
async function handleSave() {
  saving.value = true
  try {
    const payload: any = {
      type: form.storage_type === 'local' ? 1 : parseInt(form.storage_type),
    }
    if (form.storage_type !== 'local') {
      payload.alioss = {
        key: form.oss_key,
        secret: form.oss_secret,
        bucket: form.oss_bucket,
        ossurl: form.oss_endpoint,
        url: form.oss_domain,
      }
    }
    payload.thumb = form.image_compress === '1' ? 1 : 0
    payload.thumb_width = Number(form.thumb_width)
    payload.thumb_height = Number(form.thumb_height)
    payload.delete_origin = form.delete_origin === '1' ? 1 : 0
    payload.upload_max_filesize = Number(form.upload_max)
    
    await saveSystemConfig('remote', payload)
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

// 存储类型切换时，重置字段（按参考系统截图顺序动态显示）
function handleStorageTypeChange() {
  // 切换时清空对应云厂商字段
  if (form.storage_type === 'local') {
    form.oss_key = ''
    form.oss_secret = ''
    form.oss_bucket = ''
    form.oss_endpoint = ''
    form.oss_domain = ''
  }
}

onMounted(fetchConfig)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <!-- 顶栏页头：统一显示“附件设置”四字 -->
    <template #header>
      <div class="page-header">
        <span class="page-title">附件设置</span>
      </div>
    </template>

    <el-form :model="form" label-width="140px" label-position="left" style="max-width: 900px">
      <!-- 存储类型选择：下拉组件 -->
      <el-form-item label="存储类型">
        <el-select class="storage-select" v-model="form.storage_type" @change="handleStorageTypeChange">
          <el-option value="local" label="本地储存" />
          <el-option value="2" label="阿里云" />
          <el-option value="3" label="七牛云" />
          <el-option value="4" label="腾讯云" />
        </el-select>
      </el-form-item>

      <!-- ========== 阿里云配置 ========== -->
      <template v-if="form.storage_type === '2'">
        <el-form-item label="Access Key ID">
          <el-input class="cloud-input" v-model="form.oss_key" placeholder="1" clearable />
        </el-form-item>
        <el-form-item label="Access Key Secret">
          <el-input class="cloud-input" v-model="form.oss_secret" placeholder="1" clearable show-password />
        </el-form-item>
        <el-form-item label="Bucket 名称">
          <el-input class="cloud-input" v-model="form.oss_bucket" placeholder="1" clearable>
            <template #append style="width: 60px">
              <span class="gray-tip">填写存储空间名称</span>
            </template>
          </el-input>
        </el-form-item>
        <el-form-item label="EndPoint">
          <el-input class="cloud-input" v-model="form.oss_endpoint" placeholder="1" clearable>
            <template #append style="width: 60px">
              <span class="gray-tip">示例：oss-cn-qingdao.aliyuncs.com</span>
            </template>
          </el-input>
        </el-form-item>
        <el-form-item label="Bucket 域名">
          <el-input class="cloud-input" v-model="form.oss_domain" placeholder="1" clearable>
            <template #append style="width: 60px">
              <span class="gray-tip">地址需以 https:// 开头</span>
            </template>
          </el-input>
        </el-form-item>
      </template>

      <!-- ========== 七牛云配置 ========== -->
      <template v-if="form.storage_type === '3'">
        <el-form-item label="Accesskey">
          <el-input class="cloud-input" v-model="form.oss_key" placeholder="1" clearable>
            <template #append style="width: 60px">
              <span class="gray-tip">可在控制台的密钥管理页面查看</span>
            </template>
          </el-input>
        </el-form-item>
        <el-form-item label="Secretkey">
          <el-input class="cloud-input" v-model="form.oss_secret" placeholder="1" clearable show-password>
            <template #append style="width: 60px">
              <span class="gray-tip">可在控制台的密钥管理页面查看</span>
            </template>
          </el-input>
        </el-form-item>
        <el-form-item label="Bucket">
          <el-input class="cloud-input" v-model="form.oss_bucket" placeholder="1" clearable>
            <template #append style="width: 60px">
              <span class="gray-tip">填写存储空间名称</span>
            </template>
          </el-input>
        </el-form-item>
        <el-form-item label="Url">
          <el-input class="cloud-input" v-model="form.oss_domain" placeholder="1" clearable>
            <template #append style="width: 60px">
              <span class="gray-tip">地址需以 https:// 开头</span>
            </template>
          </el-input>
        </el-form-item>
      </template>

      <!-- ========== 腾讯云配置 ========== -->
      <template v-if="form.storage_type === '4'">
        <el-form-item label="APPID">
          <el-input class="cloud-input" v-model="form.oss_key" placeholder="1" clearable />
        </el-form-item>
        <el-form-item label="SecretID">
          <el-input class="cloud-input" v-model="form.oss_secret" placeholder="1" clearable />
        </el-form-item>
        <el-form-item label="SecretKEY">
          <el-input class="cloud-input" v-model="form.oss_secret" placeholder="1" clearable show-password />
        </el-form-item>
        <el-form-item label="Bucket">
          <el-input class="cloud-input" v-model="form.oss_bucket" placeholder="1" clearable>
            <template #append style="width: 60px">
              <span class="gray-tip">填写存储桶名称</span>
            </template>
          </el-input>
        </el-form-item>
        <el-form-item label="bucket 所属地域">
          <el-input class="cloud-input" v-model="form.oss_endpoint" placeholder="1" clearable>
            <template #append style="width: 60px">
              <span class="gray-tip">填写地域代码，示例：ap-beijing</span>
            </template>
          </el-input>
        </el-form-item>
        <el-form-item label="Url">
          <el-input class="cloud-input" v-model="form.oss_domain" placeholder="1" clearable>
            <template #append style="width: 60px">
              <span class="gray-tip">地址需以 https:// 开头</span>
            </template>
          </el-input>
        </el-form-item>
      </template>

      <!-- ========== 通用三行配置（所有选项常驻，水平一行平铺）========== -->
      <!-- 图片压缩：圆点单选 + 最大宽度输入框 + 最大高度输入框 + 右侧提示文字在同一行 -->
      <el-form-item label="图片压缩">
        <div class="inline-config">
          <el-radio-group v-model="form.image_compress">
            <el-radio value="1">开启</el-radio>
            <el-radio value="0">关闭</el-radio>
          </el-radio-group>
          <div class="inline-inputs">
            <label>最大宽度</label>
            <el-input v-model="form.thumb_width" placeholder="1" style="width: 80px; margin-left: 8px" />
            <label>最大高度</label>
            <el-input v-model="form.thumb_height" placeholder="1" style="width: 80px; margin-left: 8px" />
          </div>
          <span class="gray-tip">图片宽高超过设定值时会自动压缩处理；注意 png 经压缩后可能失去透明效果，开启前请谨慎评估</span>
        </div>
      </el-form-item>
      
      <!-- 删除原图：圆点单选 + 右侧提示文字在同一行 -->
      <el-form-item label="删除原图">
        <div class="inline-config">
          <el-radio-group v-model="form.delete_origin">
            <el-radio value="1">开启</el-radio>
            <el-radio value="0">关闭</el-radio>
          </el-radio-group>
          <span class="gray-tip">生成压缩图后同步清除原始图片</span>
        </div>
      </el-form-item>
      
      <!-- 服务器单次上传限制：加粗值 + 右侧提示文字在同一行 -->
      <el-form-item label="服务器单次上传限制">
        <div class="inline-config">
          <span class="bold-text">{{ form.upload_max }}M</span>
          <span class="gray-tip">可在宝塔面板[软件商店]中打开对应 PHP 版本的[设置]-[上传限制]进行调整</span>
        </div>
      </el-form-item>

      <!-- 保存按钮：青绿色左对齐 -->
      <el-form-item>
        <el-button type="primary" :loading="saving" @click="handleSave" class="save-btn">保存</el-button>
      </el-form-item>
    </el-form>
  </el-card>
</template>

<style scoped>
/* 页头样式：黑色小字 */
.page-header {
  font-size: 16px;
  font-weight: normal;
  color: var(--el-text-color-primary);
}

.page-title {
  color: var(--el-text-color-primary);
  font-size: 16px;
  font-weight: normal;
}

/* 存储类型下拉框：与下方云字段输入框同宽，左右边缘对齐 */
.storage-select {
  width: 100%;
  max-width: 350px;
}

/* 云厂商字段输入框：不撑满表单，固定最大宽度 */
.cloud-input {
  max-width: 350px;
}

/* 灰色提示文字 */
.gray-tip {
  color: #909399;
  font-size: 13px;
}

/* 加粗文本 */
.bold-text {
  font-weight: bold;
  color: var(--el-text-color-primary);
}

/* 保存按钮：青绿色左对齐 */
.save-btn {
  background-color: #1abc9c;
  border-color: #1abc9c;
  color: white;
}

.save-btn:hover {
  opacity: 0.9;
}

/* 通用配置水平排布：圆点单选 + 输入框 + 提示文字在同一行 */
.inline-config {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.inline-inputs {
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
