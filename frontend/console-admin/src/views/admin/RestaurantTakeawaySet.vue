<script setup lang="ts">
/**
 * 餐饮 - 外卖 - 外卖设置（对照参考系统 restaurant_takeaway_set/index）
 * 广告设置字段组（banner/banner_show）并入本页，菜单「广告设置」双挂同一页面
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getRestaurantTakeawaySet, saveRestaurantTakeawaySet } from '../../api/restaurantExt'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const submitting = ref(false)

const form = reactive({
  banner: '',
  banner_show: 1,
  pack_fee: 0,
  min_price: 0,
  start_hours: '',
  end_hours: '',
  confirm_auto: 1,
  status: 1,
  autoshdays: 7,
  business_info_show: 1,
  comment_show: 1,
  open_restaurant_detail_status: 1,
  takeaway_show: 1,
  takeaway_name: '外卖',
  business_info_name: '商家信息',
  comment_name: '评价',
  alone_hexiao_status: 0,
  auto_send_order: 0,
})

async function fetchSet() {
  loading.value = true
  try {
    const data = await getRestaurantTakeawaySet()
    Object.assign(form, data.info || {})
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await saveRestaurantTakeawaySet({ ...form })
    ElMessage.success('保存成功')
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
        <span class="card-title">外卖设置</span>
        <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
      </div>
    </template>

    <el-form :model="form" label-width="140px" label-position="right" class="compact-form">
      <div class="section-title">基础开关</div>
      <el-form-item label="外卖状态">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="接单时间">
        <el-time-picker v-model="form.start_hours" format="HH:mm" value-format="HH:mm" placeholder="开始" style="width: 130px" />
        <span class="time-sep">至</span>
        <el-time-picker v-model="form.end_hours" format="HH:mm" value-format="HH:mm" placeholder="结束" style="width: 130px" />
      </el-form-item>
      <el-form-item label="自动接单">
        <el-radio-group v-model="form.confirm_auto">
          <el-radio :value="1">自动</el-radio>
          <el-radio :value="0">手动</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="自动发货单">
        <el-radio-group v-model="form.auto_send_order">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="form-tip">接单后自动推送配送单给同城配送</span>
      </el-form-item>

      <div class="section-title">费用与门槛</div>
      <el-form-item label="包装费">
        <el-input-number v-model="form.pack_fee" :min="0" :precision="2" :step="1" />
        <span class="form-tip">元/单</span>
      </el-form-item>
      <el-form-item label="配送价格">
        <el-input-number v-model="form.min_price" :min="0" :precision="2" :step="1" />
        <span class="form-tip">元起送金额</span>
      </el-form-item>
      <el-form-item label="自动收货时间">
        <el-input-number v-model="form.autoshdays" :min="1" :max="365" />
        <span class="form-tip">订单发货后 N 天自动完成</span>
      </el-form-item>

      <div class="section-title">广告设置</div>
      <el-form-item label="是否显示广告banner">
        <el-radio-group v-model="form.banner_show">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">不显示</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="banner图片">
        <div class="img-pick-row">
          <el-image v-if="form.banner" :src="form.banner" class="img-pick-thumb" fit="cover" />
          <el-button size="small" @click="openImgPicker">选择图片</el-button>
          <el-button v-if="form.banner" size="small" link type="danger" @click="form.banner = ''">删除</el-button>
        </div>
      </el-form-item>

      <div class="section-title">页面展示</div>
      <el-form-item label="显示收货地址">
        <el-radio-group v-model="form.takeaway_show">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">不显示</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="显示商家信息栏">
        <el-radio-group v-model="form.business_info_show">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">不显示</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="显示评价栏">
        <el-radio-group v-model="form.comment_show">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">不显示</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="打开商品详情页">
        <el-radio-group v-model="form.open_restaurant_detail_status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="独立核销入口">
        <el-radio-group v-model="form.alone_hexiao_status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>

      <div class="section-title">栏目名称</div>
      <el-form-item label="外卖栏目名称">
        <el-input v-model="form.takeaway_name" maxlength="20" style="width: 220px" />
      </el-form-item>
      <el-form-item label="商家信息栏目名称">
        <el-input v-model="form.business_info_name" maxlength="20" style="width: 220px" />
      </el-form-item>
      <el-form-item label="评价栏目名称">
        <el-input v-model="form.comment_name" maxlength="20" style="width: 220px" />
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
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.section-title {
  margin: 18px 0 12px;
  padding-left: 8px;
  border-left: 3px solid #409eff;
  font-size: 14px;
  font-weight: 600;
  color: #303133;
}

.compact-form {
  max-width: 860px;
}

.time-sep {
  margin: 0 8px;
  color: #909399;
}

.form-tip {
  margin-left: 10px;
  font-size: 12px;
  color: #909399;
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
