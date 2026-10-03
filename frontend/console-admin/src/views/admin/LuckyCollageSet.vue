<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getLuckyCollageSet, saveLuckyCollageSet } from '../../api/luckyCollage'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  autoshdays: 1,
  comment: 1,
  comment_check: 0,
  showjd: 1,
  member_discount: 1,
})

async function fetchSet() {
  loading.value = true
  try {
    const data = await getLuckyCollageSet()
    Object.assign(form, {
      autoshdays: data.autoshdays ?? 1,
      comment: data.comment ?? 1,
      comment_check: data.comment_check ?? 0,
      showjd: data.showjd ?? 1,
      member_discount: data.member_discount ?? 1,
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    await saveLuckyCollageSet({ ...form })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(fetchSet)
</script>

<template>
  <div v-loading="loading" class="lucky-set">
    <div class="page-head"><span class="head-title">系统设置</span></div>
    <el-form label-width="110px" label-position="right" style="max-width: 640px">
      <el-form-item label="自动收货天数">
        <el-input-number v-model="form.autoshdays" :min="1" :max="90" :precision="0" :step="1" />
        <span class="form-unit">天</span>
        <span class="form-tip">发货后多少天用户未确认收货自动完成收货（最小值为1）</span>
      </el-form-item>
      <el-form-item label="商品评价">
        <el-radio-group v-model="form.comment">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-if="form.comment === 1" label="评价审核">
        <el-radio-group v-model="form.comment_check">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="form-tip">关闭后用户的评价将直接显示，不需要后台审核</span>
      </el-form-item>
      <el-form-item label="进店及咨询">
        <el-radio-group v-model="form.showjd">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="form-tip">商品详情页是否显示进店及咨询</span>
      </el-form-item>
      <el-form-item label="会员等级折扣">
        <el-radio-group v-model="form.member_discount">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="form-tip">是否开启会员等级折扣</span>
      </el-form-item>
      <el-form-item>
        <el-button type="success" :loading="saving" @click="handleSave">提 交</el-button>
      </el-form-item>
    </el-form>
  </div>
</template>

<style scoped>
.lucky-set { margin: 3px; background: #fff; padding: 16px 20px; }
.page-head { margin-bottom: 16px; }
.head-title { font-size: 15px; font-weight: 600; }
.form-tip { margin-left: 8px; color: #909399; font-size: 12px; }
.form-unit { margin-left: 8px; color: #606266; font-size: 13px; }
</style>
