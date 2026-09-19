<template>
  <div class="attach-pane">
    <el-form v-loading="loading" label-width="130px" class="attach-form">
      <!-- 存储类型：下拉选择 -->
      <el-form-item label="存储类型">
        <el-select v-model="form.storage_type" class="w300">
          <el-option :value="1" label="本地储存" />
          <el-option :value="2" label="阿里云" />
          <el-option :value="3" label="七牛云" />
          <el-option :value="4" label="腾讯云" />
        </el-select>
      </el-form-item>

      <!-- 阿里云（任务260：对照参考系统 #aliossset，仅存储类型=2 时显示） -->
      <template v-if="form.storage_type === 2">
      <el-form-item label="Access Key ID">
        <el-input v-model="form.oss_key" class="w300" />
      </el-form-item>
      <el-form-item label="Access Key Secret">
        <el-input v-model="form.oss_secret" class="w300" />
      </el-form-item>
      <el-form-item label="Bucket 名称">
        <el-input v-model="form.oss_bucket" class="w300" />
        <span class="tip">空间名称</span>
      </el-form-item>
      <el-form-item label="EndPoint（地域节点）">
        <el-input v-model="form.oss_endpoint" class="w300" />
        <span class="tip">如：oss-cn-qingdao.aliyuncs.com</span>
      </el-form-item>
      <el-form-item label="Bucket 域名">
        <el-input v-model="form.oss_domain" class="w300" />
        <span class="tip">开头须加 https://</span>
      </el-form-item>

      </template>

      <!-- 七牛云（对照参考系统 #qiniuset，仅存储类型=3 时显示） -->
      <template v-if="form.storage_type === 3">
      <el-form-item label="Accesskey">
        <el-input v-model="form.qiniu_accesskey" class="w300" />
        <span class="tip">在密钥管理中查找</span>
      </el-form-item>
      <el-form-item label="Secretkey">
        <el-input v-model="form.qiniu_secretkey" class="w300" />
        <span class="tip">在密钥管理中查找</span>
      </el-form-item>
      <el-form-item label="Bucket">
        <el-input v-model="form.qiniu_bucket" class="w300" />
        <span class="tip">空间名称</span>
      </el-form-item>
      <el-form-item label="Url">
        <el-input v-model="form.qiniu_url" class="w300" />
        <span class="tip">开头须加 https://</span>
      </el-form-item>

      </template>

      <!-- 腾讯云（对照参考系统 #cosset，仅存储类型=4 时显示） -->
      <template v-if="form.storage_type === 4">
      <el-form-item label="APPID">
        <el-input v-model="form.cos_appid" class="w300" />
      </el-form-item>
      <el-form-item label="SecretID">
        <el-input v-model="form.cos_secretid" class="w300" />
      </el-form-item>
      <el-form-item label="SecretKEY">
        <el-input v-model="form.cos_secretkey" class="w300" />
      </el-form-item>
      <el-form-item label="Bucket">
        <el-input v-model="form.cos_bucket" class="w300" />
        <span class="tip">存储桶名称</span>
      </el-form-item>
      <el-form-item label="bucket 所属地域">
        <el-input v-model="form.cos_local" class="w300" />
        <span class="tip">地域代码，如：ap-beijing</span>
      </el-form-item>
      <el-form-item label="Url">
        <el-input v-model="form.cos_url" class="w300" />
        <span class="tip">开头须加 https://</span>
      </el-form-item>
      </template>

      <!-- 公共项（任务260：与类型无关始终显示；参考系统附件 Tab 无此项，本系统后端存在该字段故保留） -->
      <!-- 图片压缩：开启/关闭 + 最大宽度/最大高度 + 灰字提示 -->
      <el-form-item label="图片压缩">
        <el-radio-group v-model="form.thumb">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="inline-label">最大宽度</span>
        <el-input v-model="form.thumb_width" type="number" class="w80" />
        <span class="inline-label">最大高度</span>
        <el-input v-model="form.thumb_height" type="number" class="w80" />
        <span class="tip">上传图片超出设置的宽高时自动压缩，png 格式压缩会变成不透明，谨慎开启！！！</span>
      </el-form-item>

      <!-- 删除原图 -->
      <el-form-item label="删除原图">
        <el-radio-group v-model="form.delete_origin">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="tip">上传压缩图片后删除原图</span>
      </el-form-item>

      <!-- 云存储上传后删除服务器文件 -->
      <el-form-item>
        <template #label>
          <div>
            云存储上传后<br />
            删除服务器文件
          </div>
        </template>
        <el-radio-group v-model="form.delete_local">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="tip">开启后，上传完成后会删除服务器文件，保留云储存文件</span>
      </el-form-item>

      <!-- 服务器单次上传限制（只读，来自 PHP ini） -->
      <el-form-item label="服务器单次上传限制">
        <span class="limit-value">{{ uploadMax }}</span>
        <span class="tip">宝塔-[软件商店]-[PHP{{ phpVersion }}]-[设置]-[上传限制]设置</span>
      </el-form-item>

      <!-- 保存按钮：青绿色，附件 Tab 独立保存，与 sysset 主表单保存隔离 -->
      <el-form-item>
        <el-button class="save-btn" :loading="saving" @click="handleSave">保 存</el-button>
      </el-form-item>
    </el-form>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getAttachSet, saveAttachSet } from '../../api/attachSet'

// 租户附件设置（任务256：对接租户侧 attachSet 接口；任务258：内嵌至系统设置页 Tab）
const loading = ref(false)
const saving = ref(false)
const uploadMax = ref('--')
const phpVersion = ref('')

const form = reactive({
  storage_type: 1,
  oss_key: '',
  oss_secret: '',
  oss_bucket: '',
  oss_endpoint: '',
  oss_domain: '',
  qiniu_accesskey: '',
  qiniu_secretkey: '',
  qiniu_bucket: '',
  qiniu_url: '',
  cos_appid: '',
  cos_secretid: '',
  cos_secretkey: '',
  cos_bucket: '',
  cos_local: '',
  cos_url: '',
  thumb: 0,
  thumb_width: '',
  thumb_height: '',
  delete_origin: 1,
  delete_local: 1,
})

function toInt(v: unknown, def: number): number {
  const n = parseInt(String(v ?? ''), 10)
  return Number.isNaN(n) ? def : n
}

/** 读取租户级附件配置并回填（含只读展示项：上传限制 + PHP 版本） */
async function fetchConfig() {
  loading.value = true
  try {
    const data = await getAttachSet()
    form.storage_type = toInt(data.storage_type, 1)
    form.oss_key = data.oss_key || ''
    form.oss_secret = data.oss_secret || ''
    form.oss_bucket = data.oss_bucket || ''
    form.oss_endpoint = data.oss_endpoint || ''
    form.oss_domain = data.oss_domain || ''
    form.qiniu_accesskey = data.qiniu_accesskey || ''
    form.qiniu_secretkey = data.qiniu_secretkey || ''
    form.qiniu_bucket = data.qiniu_bucket || ''
    form.qiniu_url = data.qiniu_url || ''
    form.cos_appid = data.cos_appid || ''
    form.cos_secretid = data.cos_secretid || ''
    form.cos_secretkey = data.cos_secretkey || ''
    form.cos_bucket = data.cos_bucket || ''
    form.cos_local = data.cos_local || ''
    form.cos_url = data.cos_url || ''
    form.thumb = toInt(data.thumb, 0)
    form.thumb_width = data.thumb_width != null && data.thumb_width !== '' ? String(data.thumb_width) : ''
    form.thumb_height = data.thumb_height != null && data.thumb_height !== '' ? String(data.thumb_height) : ''
    form.delete_origin = data.delete_origin === '0' ? 0 : 1
    form.delete_local = data.delete_local === '0' ? 0 : 1
    // 只读展示项（GET 一并下发）
    phpVersion.value = data.php || ''
    uploadMax.value = data.upload_max_filesize || '--'
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
    await saveAttachSet({
      storage_type: String(form.storage_type),
      oss_key: form.oss_key,
      oss_secret: form.oss_secret,
      oss_bucket: form.oss_bucket,
      oss_endpoint: form.oss_endpoint,
      oss_domain: form.oss_domain,
      qiniu_accesskey: form.qiniu_accesskey,
      qiniu_secretkey: form.qiniu_secretkey,
      qiniu_bucket: form.qiniu_bucket,
      qiniu_url: form.qiniu_url,
      cos_appid: form.cos_appid,
      cos_secretid: form.cos_secretid,
      cos_secretkey: form.cos_secretkey,
      cos_bucket: form.cos_bucket,
      cos_local: form.cos_local,
      cos_url: form.cos_url,
      thumb: String(form.thumb),
      thumb_width: String(form.thumb_width),
      thumb_height: String(form.thumb_height),
      delete_origin: String(form.delete_origin),
      delete_local: String(form.delete_local),
    })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchConfig()
})
</script>

<style scoped>
.attach-pane {
  background: #fff;
}

.attach-form {
  max-width: 1000px;
}

.w300 {
  width: 300px;
}

.w80 {
  width: 80px;
}

/* 行内标签 - 与输入框在同一行 */
.inline-label {
  margin-left: 16px;
  color: var(--el-text-color-secondary);
  font-size: 14px;
}

/* 输入框右侧灰字提示 */
.tip {
  margin-left: 10px;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}

/* 服务器单次上传限制数值（加粗） */
.limit-value {
  font-weight: 700;
  color: #333;
  margin-right: 60px;
}

/* 保存按钮：青绿色，对照参考系统 layui-btn */
.save-btn {
  background-color: #009688;
  border-color: #009688;
  color: #fff;
  border-radius: 2px;
  padding: 8px 20px;
}

.save-btn:hover,
.save-btn:focus {
  background-color: #00a392;
  border-color: #00a392;
  color: #fff;
}
</style>
