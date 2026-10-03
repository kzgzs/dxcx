<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getPeisongMakeset, savePeisongMakeset } from '../../api/peisong'

// 租户后台 - 同城配送·码科跑腿对接（照截图：码科跑腿设置；对接参数保存后回读一致；第三方走 mock）

const loading = ref(false)
const submitting = ref(false)

const form = reactive({
  make_status: 0,
  make_domain: '',
  make_appid: '',
  make_token: '',
  make_shopkoufei: 0,
})

async function fetchSet() {
  loading.value = true
  try {
    const { info } = await getPeisongMakeset()
    if (info) {
      form.make_status = Number(info.make_status ?? 0) === 1 ? 1 : 0
      form.make_domain = info.make_domain || ''
      form.make_appid = info.make_appid || ''
      form.make_token = info.make_token || ''
      form.make_shopkoufei = Number(info.make_shopkoufei ?? 0) === 1 ? 1 : 0
    }
  } catch { /* 拦截器 */ } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await savePeisongMakeset({ ...form })
    ElMessage.success('保存成功')
    await fetchSet() // 保存后回读，保证回填一致
  } catch { /* 拦截器 */ } finally {
    submitting.value = false
  }
}

onMounted(fetchSet)
</script>

<template>
  <div v-loading="loading" class="set-wrap">
    <el-card shadow="never">
      <!-- 提示条（照截图，两行） -->
      <div class="tip-bar">
        用于对接码科跑腿，不需要对接码科跑腿不用配置，开启后则使用码科跑腿进行配送，不再使用本系统自带的同城配送模块<br>
        如果使用码科跑腿大客户模块，主商城的店铺id填写-1 多商户的店铺id填写商户id
      </div>

      <el-form label-width="130px" label-position="right" class="set-form">
        <el-form-item label="状态">
          <el-radio-group v-model="form.make_status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="码科模块域名">
          <el-input v-model="form.make_domain" style="width: 240px" placeholder="api.kkpaotui.com" />
          <span class="tip">只填写域名</span>
        </el-form-item>

        <el-form-item label="小程序AppId">
          <el-input v-model="form.make_appid" style="width: 240px" placeholder="码科后台获取" />
          <span class="tip">码科后台获取</span>
        </el-form-item>

        <el-form-item label="Token">
          <el-input v-model="form.make_token" style="width: 360px" placeholder="码科后台获取" />
          <span class="tip">码科后台获取</span>
        </el-form-item>

        <el-form-item label="扣商家配送费">
          <el-radio-group v-model="form.make_shopkoufei">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <span class="tip">开启后商家选择码科配送时需要扣配送费</span>
        </el-form-item>

        <el-form-item>
          <el-button type="success" :loading="submitting" @click="handleSave">提交</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.set-wrap { margin: 3px; }
.set-form { max-width: 760px; padding: 16px 0; }
.tip-bar { background: #f5f6f7; border-left: 3px solid #67c23a; color: #606266; font-size: 13px; line-height: 1.8; padding: 10px 14px; margin-bottom: 14px; }
.tip { color: #909399; font-size: 12px; margin-left: 10px; white-space: nowrap; }
</style>
