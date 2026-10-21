<script setup lang="ts">
// 微信代认证 - 系统设置（对齐点大：状态 radio + 服务市场appid/电话 + 三类「可用/总量」代缴次数）
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getProxyverifySet, saveProxyverifySet } from '../../api/wxproxyverify'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  status: 0,
  service_appid: '',
  third_party_phone: '',
  num: 0,
  all: 0,
  num2: 0,
  all2: 0,
  num3: 0,
  all3: 0,
})

async function load() {
  loading.value = true
  try {
    const res = await getProxyverifySet()
    form.status = Number(res.status) || 0
    form.service_appid = res.service_appid || ''
    form.third_party_phone = res.third_party_phone || ''
    form.num = Number(res.num) || 0
    form.all = Number(res.all) || 0
    form.num2 = Number(res.num2) || 0
    form.all2 = Number(res.all2) || 0
    form.num3 = Number(res.num3) || 0
    form.all3 = Number(res.all3) || 0
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  if (!form.service_appid.trim()) {
    ElMessage.warning('请填写服务市场appid')
    return
  }
  if (!form.third_party_phone.trim()) {
    ElMessage.warning('请填写服务商联系电话')
    return
  }
  for (const [numKey, allKey, name] of [
    ['num', 'all', '企业'],
    ['num2', 'all2', '个体工商户'],
    ['num3', 'all3', '个人'],
  ] as const) {
    if (form[numKey] < 0 || form[allKey] < 0) {
      ElMessage.warning(`${name}代缴次数不能为负数`)
      return
    }
    if (form[numKey] > form[allKey]) {
      ElMessage.warning(`${name}可用次数不能大于总量`)
      return
    }
  }
  saving.value = true
  try {
    await saveProxyverifySet({ ...form })
    ElMessage.success('保存成功')
    load()
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="wxpv-set" v-loading="loading">
    <el-alert type="warning" :closable="false" class="notice">
      <template #title>
        <div class="notice-lines">
          <div>认证功能要使用需要设置三处：</div>
          <div>1、扩展-微信代认证-系统设置-状态及各类型代缴次数设置；</div>
          <div>2、控制台-系统设置-微信认证统一设置；</div>
          <div>3、控制台-用户列表-操作-微信认证中单独设置（此处给子平台使用，若不给使用则无需设置）</div>
        </div>
      </template>
    </el-alert>

    <el-card shadow="never">
      <el-form label-width="210px" class="set-form">
        <el-form-item label="状态：">
          <el-radio-group v-model="form.status">
            <el-radio :value="0">关闭</el-radio>
            <el-radio :value="1">开启</el-radio>
          </el-radio-group>
          <span class="aux">关闭则无法添加、提交、查询订单等操作</span>
        </el-form-item>

        <el-form-item label="*服务市场appid：">
          <el-input v-model="form.service_appid" maxlength="100" style="width: 250px" />
          <span class="aux">
            <a href="https://fuwu.weixin.qq.com" target="_blank" rel="noopener">前往服务市场</a>，点击可查看相关
            <a href="https://docs.qq.com/doc/DZXpoWFdneU5UWUxS" target="_blank" rel="noopener">服务商市场文档</a>
          </span>
        </el-form-item>

        <el-form-item label="*服务商联系电话：">
          <el-input v-model="form.third_party_phone" maxlength="30" style="width: 250px" />
        </el-form-item>

        <el-form-item label="*企业当前可用代缴次数：">
          <div class="quota-line">
            <el-input v-model.number="form.num" style="width: 90px" />可用 /
            <el-input v-model.number="form.all" style="width: 90px" />总量
          </div>
          <span class="aux">
            前往<a href="https://fuwu.weixin.qq.com" target="_blank" rel="noopener">服务市场采购认证费</a>，点击可查看相关
            <a href="https://docs.qq.com/doc/DZXpoWFdneU5UWUxS" target="_blank" rel="noopener">服务商市场文档</a>，数量不足不能发起新的企业认证
          </span>
        </el-form-item>

        <el-form-item label="*个体工商户当前可用代缴次数：">
          <div class="quota-line">
            <el-input v-model.number="form.num2" style="width: 90px" />可用 /
            <el-input v-model.number="form.all2" style="width: 90px" />总量
          </div>
          <span class="aux">
            前往<a href="https://fuwu.weixin.qq.com" target="_blank" rel="noopener">服务市场采购认证费</a>，点击可查看相关
            <a href="https://docs.qq.com/doc/DZXpoWFdneU5UWUxS" target="_blank" rel="noopener">服务商市场文档</a>，数量不足不能发起新的个体工商户认证
          </span>
        </el-form-item>

        <el-form-item label="*个人当前可用代缴次数：">
          <div class="quota-line">
            <el-input v-model.number="form.num3" style="width: 90px" />可用 /
            <el-input v-model.number="form.all3" style="width: 90px" />总量
          </div>
          <span class="aux">
            前往<a href="https://fuwu.weixin.qq.com" target="_blank" rel="noopener">服务市场采购认证费</a>，点击可查看相关
            <a href="https://docs.qq.com/doc/DZXpoWFdneU5UWUxS" target="_blank" rel="noopener">服务商市场文档</a>，数量不足不能发起新的个人认证
          </span>
        </el-form-item>

        <el-form-item>
          <el-button type="success" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.wxpv-set {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.notice { margin: 0; }
.notice-lines { line-height: 1.7; }
.set-form :deep(.el-form-item) { margin-bottom: 10px; }
.aux { color: #909399; font-size: 12px; margin-left: 10px; white-space: nowrap; }
.quota-line { display: flex; align-items: center; gap: 4px; width: 250px; flex-shrink: 0; }
</style>
