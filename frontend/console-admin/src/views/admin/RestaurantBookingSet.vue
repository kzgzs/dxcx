<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getBookingSet, saveBookingSet } from '../../api/restaurantBooking'
import type { BookingTimeItem } from '../../api/restaurantBooking'

const DAY_OPTIONS = [
  { value: 1, label: '今天' },
  { value: 2, label: '明天' },
  { value: 3, label: '后天' },
  { value: 4, label: '大后天' },
]

const loading = ref(false)
const submitting = ref(false)

const form = reactive({
  status: 0,
  pay: 0,
  pay_money: 0,
  prehour: 2,
  timedata: [] as BookingTimeItem[],
})

const timeSlots = ref<{ day: number; day_text: string; date: string; start: string; end: string; title: string; value: string }[]>([])

function pad2(v: string | number) {
  return String(v).padStart(2, '0')
}

/** 时间段行内时间显示 */
function slotText(item: BookingTimeItem) {
  return `${pad2(item.hour)}:${pad2(item.minute)} ~ ${pad2(item.hour2)}:${pad2(item.minute2)}`
}

function addTimeRow() {
  form.timedata.push({ day: 1, hour: '12', minute: '0', hour2: '12', minute2: '30' })
}

function removeTimeRow(index: number) {
  form.timedata.splice(index, 1)
}

async function fetchSet() {
  loading.value = true
  try {
    const data = await getBookingSet()
    form.status = data.status
    form.pay = data.pay
    form.pay_money = Number(data.pay_money) || 0
    form.prehour = data.prehour
    form.timedata = (data.timedata || []).map((t) => ({
      day: Number(t.day),
      hour: String(t.hour),
      minute: String(t.minute),
      hour2: String(t.hour2),
      minute2: String(t.minute2),
    }))
    timeSlots.value = data.time_slots || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  if (form.status === 1 && form.timedata.length === 0) {
    ElMessage.warning('开启预定前请至少配置一个时间段')
    return
  }
  for (let i = 0; i < form.timedata.length; i++) {
    const t = form.timedata[i]
    const start = Number(t.hour) * 60 + Number(t.minute)
    const end = Number(t.hour2) * 60 + Number(t.minute2)
    if (end <= start) {
      ElMessage.warning(`第 ${i + 1} 个时段的结束时间需晚于开始时间`)
      return
    }
  }
  submitting.value = true
  try {
    await saveBookingSet({
      status: form.status,
      pay: form.pay,
      pay_money: form.pay_money,
      prehour: form.prehour,
      timedata: form.timedata,
    })
    ElMessage.success('保存成功')
    fetchSet()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

onMounted(fetchSet)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">预定设置</span>
        <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
      </div>
    </template>

    <el-form :model="form" label-width="130px" label-position="right" class="compact-form">
      <div class="section-title">基础开关</div>
      <el-form-item label="预定功能">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="提前预定">
        <el-input-number v-model="form.prehour" :min="0" :max="72" />
        <span class="form-tip">小时（会员需至少提前 N 小时预定）</span>
      </el-form-item>

      <div class="section-title">定金</div>
      <el-form-item label="预定付费">
        <el-radio-group v-model="form.pay">
          <el-radio :value="1">开启（需付定金）</el-radio>
          <el-radio :value="0">关闭（免费预定）</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-if="form.pay === 1" label="默认定金（元）">
        <el-input-number v-model="form.pay_money" :min="0" :precision="2" :step="1" />
        <span class="form-tip">桌台分类单独配置了预定费时优先使用分类金额</span>
      </el-form-item>

      <div class="section-title">可预定时间段</div>
      <el-form-item label="时间段">
        <div class="time-editor">
          <div v-for="(item, index) in form.timedata" :key="index" class="time-row">
            <el-select v-model="item.day" style="width: 110px">
              <el-option v-for="d in DAY_OPTIONS" :key="d.value" :value="d.value" :label="d.label" />
            </el-select>
            <el-select v-model="item.hour" style="width: 80px">
              <el-option v-for="h in 24" :key="h - 1" :value="String(h - 1)" :label="pad2(h - 1)" />
            </el-select>
            <span class="time-sep">:</span>
            <el-select v-model="item.minute" style="width: 80px">
              <el-option v-for="m in 60" :key="m - 1" :value="String(m - 1)" :label="pad2(m - 1)" />
            </el-select>
            <span class="time-sep">至</span>
            <el-select v-model="item.hour2" style="width: 80px">
              <el-option v-for="h in 24" :key="h - 1" :value="String(h - 1)" :label="pad2(h - 1)" />
            </el-select>
            <span class="time-sep">:</span>
            <el-select v-model="item.minute2" style="width: 80px">
              <el-option v-for="m in 60" :key="m - 1" :value="String(m - 1)" :label="pad2(m - 1)" />
            </el-select>
            <span class="form-tip">{{ slotText(item) }}</span>
            <el-button type="danger" link @click="removeTimeRow(index)">删除</el-button>
          </div>
          <el-button type="primary" plain @click="addTimeRow">＋ 添加时间段</el-button>
        </div>
      </el-form-item>
      <el-form-item v-if="timeSlots.length > 0" label="当前可预定">
        <el-tag v-for="slot in timeSlots" :key="slot.value" class="slot-tag" type="info">{{ slot.title }}</el-tag>
        <span class="form-tip">（已按提前量过滤，供会员端展示）</span>
      </el-form-item>

      <el-form-item>
        <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
      </el-form-item>
    </el-form>
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

.compact-form :deep(.el-form-item) {
  margin-bottom: 10px;
}

.compact-form :deep(.el-form-item__label) {
  line-height: 32px;
}

.section-title {
  margin: 6px 0 12px;
  padding-left: 8px;
  border-left: 3px solid var(--el-color-primary);
  font-weight: 600;
  font-size: 14px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.time-sep {
  margin: 0 4px;
  color: #606266;
}

.time-editor {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.time-row {
  display: flex;
  align-items: center;
}

.slot-tag {
  margin-right: 6px;
  margin-bottom: 4px;
}
</style>
