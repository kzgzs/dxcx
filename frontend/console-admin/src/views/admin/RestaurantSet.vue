<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getDiningShopSet, saveDiningShopSet } from '../../api/dining'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const PAYTYPE_OPTIONS = [
  { value: 'weixin', label: '微信支付' },
  { value: 'alipay', label: '支付宝' },
  { value: 'money', label: '余额支付' },
  { value: 'cash', label: '现金' },
]

const loading = ref(false)
const submitting = ref(false)

const form = reactive({
  banner: '',
  banner_show: 1,
  business_info_show: 1,
  comment_show: 1,
  open_restaurant_detail_status: 1,
  tea_fee_status: 0,
  tea_fee_text: '茶位费',
  tea_fee: 0,
  start_hours: '00:00',
  end_hours: '00:00',
  paytypes: ['weixin', 'alipay', 'money', 'cash'] as string[],
  pay_after: 1,
  clean_table_status: 0,
  status: 1,
  autoshdays: 1,
  diancan_text: '点餐',
})

/** 营业时间跨天提示 */
const crossDay = computed(() => form.start_hours >= form.end_hours)

async function fetchSet() {
  loading.value = true
  try {
    const data = await getDiningShopSet()
    Object.assign(form, {
      banner: data.banner || '',
      banner_show: data.banner_show,
      business_info_show: data.business_info_show,
      comment_show: data.comment_show,
      open_restaurant_detail_status: data.open_restaurant_detail_status,
      tea_fee_status: data.tea_fee_status,
      tea_fee_text: data.tea_fee_text || '茶位费',
      tea_fee: Number(data.tea_fee) || 0,
      start_hours: data.start_hours || '00:00',
      end_hours: data.end_hours || '00:00',
      paytypes: data.paytypes && data.paytypes.length > 0 ? data.paytypes : ['weixin', 'alipay', 'money', 'cash'],
      pay_after: data.pay_after,
      clean_table_status: data.clean_table_status,
      status: data.status,
      autoshdays: data.autoshdays,
      diancan_text: data.diancan_text || '点餐',
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  if (form.paytypes.length === 0) {
    ElMessage.warning('请至少选择一种支付方式')
    return
  }
  if (!form.diancan_text.trim()) {
    ElMessage.warning('请填写点餐按钮文字')
    return
  }
  submitting.value = true
  try {
    await saveDiningShopSet({
      banner: form.banner,
      banner_show: form.banner_show,
      business_info_show: form.business_info_show,
      comment_show: form.comment_show,
      open_restaurant_detail_status: form.open_restaurant_detail_status,
      tea_fee_status: form.tea_fee_status,
      tea_fee_text: form.tea_fee_text,
      tea_fee: form.tea_fee,
      start_hours: form.start_hours,
      end_hours: form.end_hours,
      paytypes: form.paytypes,
      pay_after: form.pay_after,
      clean_table_status: form.clean_table_status,
      status: form.status,
      autoshdays: form.autoshdays,
      diancan_text: form.diancan_text,
    })
    ElMessage.success('保存成功')
    fetchSet()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form.banner = urls[0]
}

onMounted(fetchSet)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">堂食设置</span>
        <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
      </div>
    </template>

    <el-form :model="form" label-width="130px" label-position="right" class="compact-form">
      <div class="section-title">基础开关</div>
      <el-form-item label="点餐功能">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="点餐按钮文字">
        <el-input v-model="form.diancan_text" placeholder="如：点餐" maxlength="20" style="width: 220px" />
      </el-form-item>
      <el-form-item label="营业时间">
        <el-time-picker v-model="form.start_hours" format="HH:mm" value-format="HH:mm" placeholder="开始" style="width: 130px" />
        <span class="time-sep">至</span>
        <el-time-picker v-model="form.end_hours" format="HH:mm" value-format="HH:mm" placeholder="结束" style="width: 130px" />
        <span v-if="crossDay" class="form-tip">当前为跨天营业（结束时间次日生效）</span>
      </el-form-item>

      <div class="section-title">茶位费</div>
      <el-form-item label="茶位费">
        <el-radio-group v-model="form.tea_fee_status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <template v-if="form.tea_fee_status === 1">
        <el-form-item label="费用名称">
          <el-input v-model="form.tea_fee_text" placeholder="如：茶位费" maxlength="20" style="width: 220px" />
        </el-form-item>
        <el-form-item label="金额（元/位）">
          <el-input-number v-model="form.tea_fee" :min="0" :precision="2" :step="1" />
        </el-form-item>
      </template>

      <div class="section-title">支付方式</div>
      <el-form-item label="支持方式">
        <el-checkbox-group v-model="form.paytypes">
          <el-checkbox v-for="p in PAYTYPE_OPTIONS" :key="p.value" :value="p.value">{{ p.label }}</el-checkbox>
        </el-checkbox-group>
      </el-form-item>
      <el-form-item label="餐后付款">
        <el-radio-group v-model="form.pay_after">
          <el-radio :value="1">开启（先用餐后结账）</el-radio>
          <el-radio :value="0">关闭（餐前付款）</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-if="form.pay_after === 0" label="支付后清台">
        <el-radio-group v-model="form.clean_table_status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="form-tip">餐前付款时，支付完成后自动将餐桌恢复为空闲</span>
      </el-form-item>
      <el-form-item label="自动收货天数">
        <el-input-number v-model="form.autoshdays" :min="1" :max="365" />
        <span class="form-tip">订单支付后 N 天自动完成</span>
      </el-form-item>

      <div class="section-title">页面展示</div>
      <el-form-item label="顶部 Banner">
        <div class="img-pick-row">
          <el-image v-if="form.banner" :src="form.banner" class="img-pick-thumb" fit="cover" />
          <el-button size="small" @click="openImgPicker">选择图片</el-button>
          <el-button v-if="form.banner" size="small" link type="danger" @click="form.banner = ''">删除</el-button>
        </div>
      </el-form-item>
      <el-form-item label="显示 Banner">
        <el-radio-group v-model="form.banner_show">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">隐藏</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="商家信息">
        <el-radio-group v-model="form.business_info_show">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">隐藏</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="顾客评价">
        <el-radio-group v-model="form.comment_show">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">隐藏</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="菜品详情页">
        <el-radio-group v-model="form.open_restaurant_detail_status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-form-item>
        <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
      </el-form-item>
    </el-form>
  </el-card>

  <!-- 选图弹窗（任务294） -->
  <ImgIconPicker
    v-model:visible="imgPickerVisible"
    default-tab="library"
    @pick="(urls: string[]) => onImgPick(urls)"
  />
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
  margin: 0 8px;
  color: #606266;
}

.img-pick-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.img-pick-thumb {
  width: 72px;
  height: 72px;
  border-radius: 6px;
  border: 1px solid #ebeef5;
}
</style>
