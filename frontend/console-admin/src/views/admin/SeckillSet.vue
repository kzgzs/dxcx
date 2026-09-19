<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getSeckillSet, saveSeckillSet } from '../../api/seckill'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  timeset: '',
  duration: 12,
  autoclose: 30,
  autoshdays: 7,
  status: 1,
  comment_check: 1,
})

// 场次输入：0-23 整点，逗号分隔
const timeInput = ref('0,8,12,20')

async function fetchSet() {
  loading.value = true
  try {
    const data = await getSeckillSet()
    Object.assign(form, {
      timeset: (data.timeset || []).join(','),
      duration: data.duration ?? 12,
      autoclose: data.autoclose ?? 30,
      autoshdays: data.autoshdays ?? 7,
      status: data.status ?? 1,
      comment_check: data.comment_check ?? 1,
    })
    timeInput.value = (data.timeset || []).join(',')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function parseTimeset(): number[] | null {
  const parts = timeInput.value
    .split(/[,，\s]+/)
    .map((s) => s.trim())
    .filter((s) => s !== '')
  const hours: number[] = []
  for (const part of parts) {
    const hour = Number(part)
    if (!Number.isInteger(hour) || hour < 0 || hour > 23) {
      ElMessage.warning(`场次时间需为0-23整点：${part}`)
      return null
    }
    if (!hours.includes(hour)) hours.push(hour)
  }
  if (hours.length === 0) {
    ElMessage.warning('请至少填写一个秒杀场次')
    return null
  }
  return hours.sort((a, b) => a - b)
}

async function handleSave() {
  const timeset = parseTimeset()
  if (!timeset) return
  saving.value = true
  try {
    await saveSeckillSet({
      timeset,
      duration: form.duration,
      autoclose: form.autoclose,
      autoshdays: form.autoshdays,
      status: form.status,
      comment_check: form.comment_check,
    })
    ElMessage.success('保存成功')
    fetchSet()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(fetchSet)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <span>秒杀设置</span>
      </template>
      <el-form label-width="130px" label-position="left" style="max-width: 620px">
        <el-form-item label="秒杀场次">
          <el-input v-model="timeInput" placeholder="如：0,8,12,20（整点，逗号分隔）" />
          <span class="form-tip">每天开放的整点场次，0-23</span>
        </el-form-item>
        <el-form-item label="单场时长">
          <el-input-number v-model="form.duration" :min="1" :max="24" :precision="0" :step="1" />
          <span class="form-tip">小时，每场秒杀的持续时长</span>
        </el-form-item>
        <el-form-item label="自动关单">
          <el-input-number v-model="form.autoclose" :min="1" :max="10080" :precision="0" :step="5" />
          <span class="form-tip">分钟，下单后超时未支付自动关闭并回补库存</span>
        </el-form-item>
        <el-form-item label="自动收货天数">
          <el-input-number v-model="form.autoshdays" :min="1" :max="90" :precision="0" :step="1" />
          <span class="form-tip">发货后 N 天自动确认收货</span>
        </el-form-item>
        <el-form-item label="活动开关">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
          <span class="form-tip">关闭后客户端不再展示秒杀活动</span>
        </el-form-item>
        <el-form-item label="评价审核">
          <el-switch v-model="form.comment_check" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后秒杀评价需审核通过才展示</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
