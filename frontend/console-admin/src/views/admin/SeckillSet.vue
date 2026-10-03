<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getSeckillSet, saveSeckillSet } from '../../api/seckill'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  status: 1,
  timeset: [] as number[],
  duration: 1,
  autoshdays: 7,
  autoclose: 29,
  comment: 1,
  comment_check: 1,
  showjd: 1,
  showcommission: 0,
})

const HOURS = Array.from({ length: 24 }, (_, i) => i)
function pad2(n: number) { return String(n).padStart(2, '0') }

function toggleHour(h: number) {
  const i = form.timeset.indexOf(h)
  if (i >= 0) form.timeset.splice(i, 1)
  else form.timeset.push(h)
  form.timeset.sort((a, b) => a - b)
}

async function fetchSet() {
  loading.value = true
  try {
    const data = await getSeckillSet()
    Object.assign(form, {
      status: data.status ?? 1,
      timeset: data.timeset && data.timeset.length ? [...data.timeset] : [0, 8, 12, 20],
      duration: data.duration ?? 1,
      autoshdays: data.autoshdays ?? 7,
      autoclose: data.autoclose ?? 29,
      comment: data.comment ?? 1,
      comment_check: data.comment_check ?? 1,
      showjd: data.showjd ?? 1,
      showcommission: data.showcommission ?? 0,
    })
  } catch {
    /* 拦截器统一提示 */
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  if (form.timeset.length === 0) {
    ElMessage.warning('请至少选择一个秒杀场次')
    return
  }
  saving.value = true
  try {
    await saveSeckillSet({
      status: form.status,
      timeset: form.timeset,
      duration: form.duration,
      autoshdays: form.autoshdays,
      autoclose: form.autoclose,
      comment: form.comment,
      comment_check: form.comment_check,
      showjd: form.showjd,
      showcommission: form.showcommission,
    })
    ElMessage.success('保存成功')
    fetchSet()
  } catch {
    /* 拦截器统一提示 */
  } finally {
    saving.value = false
  }
}

onMounted(fetchSet)
</script>

<template>
  <div v-loading="loading" class="set-page">
    <el-card shadow="never">
      <template #header><span class="card-title">⚙ 秒杀设置</span></template>

      <div class="set-row">
        <span class="set-label">秒杀状态：</span>
        <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="开启" inactive-text="关闭" />
        <span class="set-tip">秒杀总开关，关闭后所有秒杀活动将关闭</span>
      </div>

      <div class="set-row">
        <span class="set-label">秒杀场次：</span>
        <div class="hour-grid">
          <div
            v-for="h in HOURS"
            :key="h"
            class="hour-cell"
            :class="{ on: form.timeset.includes(h) }"
            @click="toggleHour(h)"
          >
            {{ pad2(h) }}:00<span v-if="form.timeset.includes(h)" class="tick">✓</span>
          </div>
        </div>
      </div>

      <div class="set-row">
        <span class="set-label">秒杀时长：</span>
        <el-input-number v-model="form.duration" :min="1" :max="24" :precision="0" :controls="false" style="width: 110px" />
        <span class="set-tip">小时&nbsp;&nbsp;注：开始后多久结束</span>
      </div>

      <div class="set-row">
        <span class="set-label">自动收货天数：</span>
        <el-input-number v-model="form.autoshdays" :min="1" :max="90" :precision="0" :controls="false" style="width: 110px" />
        <span class="set-tip">天&nbsp;&nbsp;发货后多少天用户未确认收货自动完成收货（最小值为1）</span>
      </div>

      <div class="set-row">
        <span class="set-label">自动关闭订单：</span>
        <el-input-number v-model="form.autoclose" :min="1" :max="10080" :precision="0" :controls="false" style="width: 110px" />
        <span class="set-tip">分钟&nbsp;&nbsp;下单后多久不支付自动关闭订单，释放占用的库存</span>
      </div>

      <div class="set-row">
        <span class="set-label">商品评价：</span>
        <el-radio-group v-model="form.comment">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </div>

      <div class="set-row">
        <span class="set-label">评价审核：</span>
        <el-radio-group v-model="form.comment_check">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="set-tip">关闭后用户的评价将直接显示，不需要后台审核</span>
      </div>

      <div class="set-row">
        <span class="set-label">店铺信息：</span>
        <el-radio-group v-model="form.showjd">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">不显示</el-radio>
        </el-radio-group>
        <span class="set-tip">商品详情页是否显示店铺信息</span>
      </div>

      <div class="set-row">
        <span class="set-label">是否显示佣金：</span>
        <el-radio-group v-model="form.showcommission">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">不显示</el-radio>
        </el-radio-group>
        <span class="set-tip">开启后商品详情页显示分销佣金</span>
      </div>

      <div class="set-row submit-row">
        <el-button type="success" :loading="saving" @click="handleSave">提交</el-button>
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.set-page { margin: 3px; }
.card-title { font-size: 14px; font-weight: 600; }
.set-row { display: flex; align-items: center; margin-bottom: 18px; min-height: 32px; }
.set-label { width: 130px; flex-shrink: 0; color: #606266; font-size: 13px; text-align: right; padding-right: 12px; }
.set-tip { color: #909399; font-size: 12px; margin-left: 10px; }
.hour-grid { display: grid; grid-template-columns: repeat(6, 78px); gap: 8px; }
.hour-cell {
  position: relative; height: 32px; line-height: 32px; text-align: center;
  border-radius: 3px; font-size: 13px; cursor: pointer; color: #606266; background: #f0f2f5;
  user-select: none;
}
.hour-cell.on { background: #21ba45; color: #fff; font-weight: 600; }
.hour-cell .tick { position: absolute; top: 2px; right: 4px; font-size: 10px; }
.submit-row { margin-top: 24px; }
.submit-row .el-button { margin-left: 142px; }
</style>
