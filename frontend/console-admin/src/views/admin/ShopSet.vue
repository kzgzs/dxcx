<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getShopSetConfig, saveShopSet } from '../../api/shopconfig'
import { assetUrl } from '../../api/upload'
import { areaProvinces } from '../../utils/areaData'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// 整体表单：后端白名单过滤，单页纵向分组、整体提交
const form = reactive<Record<string, any>>({})
const loading = ref(false)
const saving = ref(false)

async function fetchConfig() {
  loading.value = true
  try {
    const data = await getShopSetConfig()
    Object.assign(form, data.info || {})
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    await saveShopSet({ ...form })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

// ============ 快速购买顶部图：复用统一「选择图片/图标」组合弹窗（同页面设计器） ============
// 弹窗内可上传本地图片入库、从已上传素材库选择、或从图标库选图标改色，确定后回填
const topPicPickerVisible = ref(false)
function openTopPicPicker() {
  topPicPickerVisible.value = true
}
function onTopPicPicked(urls: string[]) {
  if (urls[0]) form.fastbuy_toppic = urls[0]
}

// ============ 退货地址：省市区三级联动（值为中文名称，与参考实现一致） ============
const provinceList = areaProvinces
const cityList = computed(
  () => provinceList.find((p) => p.name === form.return_province)?.cityList || []
)
const areaList = computed(
  () => cityList.value.find((c) => c.name === form.return_city)?.areaList || []
)
function onProvinceChange() {
  form.return_city = ''
  form.return_area = ''
}
function onCityChange() {
  form.return_area = ''
}

onMounted(fetchConfig)
</script>

<template>
  <div class="shop-set" v-loading="loading">
    <div class="set-card">
      <!-- 卡片标题 -->
      <div class="set-card-header">
        <svg class="hd-icon" viewBox="0 0 1024 1024" width="16" height="16" aria-hidden="true">
          <path d="M512 685.44a173.44 173.44 0 1 1 0-346.88 173.44 173.44 0 0 1 0 346.86z m440.32-99.84-78.08-60.16c2.56-14.08 3.84-28.16 3.84-42.88s-1.28-28.8-3.84-42.88l78.08-60.16a32 32 0 0 0 7.68-40.96l-73.6-127.36a32 32 0 0 0-39.04-14.08l-92.16 37.12a375 375 0 0 0-74.24-42.88l-14.08-97.92A32 32 0 0 0 608 32H416a32 32 0 0 0-31.36 25.6l-14.08 97.92a375 375 0 0 0-74.24 42.88l-92.16-37.12a32 32 0 0 0-39.04 14.08L91.52 302.08a32 32 0 0 0 7.68 40.96l78.08 60.16c-2.56 14.08-3.84 28.16-3.84 42.88s1.28 28.8 3.84 42.88l-78.08 60.16a32 32 0 0 0-7.68 40.96l73.6 127.36a32 32 0 0 0 39.04 14.08l92.16-37.12c23.04 17.92 47.36 32 74.24 42.88l14.08 97.92A32 32 0 0 0 416 992h192a32 32 0 0 0 31.36-25.6l14.08-97.92a375 375 0 0 0 74.24-42.88l92.16 37.12a32 32 0 0 0 39.04-14.08l73.6-127.36a32 32 0 0 0-8.32-40.96z" />
        </svg>
        <span>商城设置</span>
      </div>

      <div class="set-card-body">
        <el-form label-width="150px" class="set-form">
          <!-- ==================== 商城设置 ==================== -->
          <el-form-item label="自动收货天数：">
            <el-input-number v-model="form.autoshdays" :min="1" :step="1" :controls="false" class="num-input" />
            <span class="unit">天</span>
            <span class="tip">发货后多少天用户未确认收货自动完成收货（最小值为1）</span>
          </el-form-item>

          <el-form-item label="自动关闭订单：">
            <el-input-number v-model="form.autoclose" :min="0" :step="1" :controls="false" class="num-input" />
            <span class="unit">分钟</span>
            <span class="tip">下单后多久不支付自动关闭订单，释放占用的库存</span>
          </el-form-item>

          <el-form-item label="货到付款：">
            <el-radio-group v-model="form.cancod">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
            <template v-if="Number(form.cancod) === 1">
              <span class="inline-label">自定义文字</span>
              <el-input v-model="form.codtxt" class="codtxt-input" />
            </template>
            <span class="tip">开启后支付时可以选择货到付款</span>
          </el-form-item>

          <el-form-item label="购物车推荐：">
            <el-radio-group v-model="form.gwctj">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
            <span class="tip">开启后购物车显示为您推荐</span>
          </el-form-item>

          <el-form-item label="快速购买顶部图：">
            <el-button @click="openTopPicPicker">上传图片</el-button>
            <span class="tip">建议尺寸：600*340像素</span>
            <div v-if="form.fastbuy_toppic" class="img-preview">
              <img :src="assetUrl(form.fastbuy_toppic)" alt="快速购买顶部图" />
              <span class="img-del" title="删除" @click="form.fastbuy_toppic = ''">×</span>
            </div>
          </el-form-item>

          <el-form-item label="商品快照：">
            <el-radio-group v-model="form.goods_snapshot">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
            <span class="tip">显示交易时的商品信息，买卖双方在核对交易细节时，可作为判断依据</span>
          </el-form-item>

          <el-form-item label="快照删除：">
            <el-input-number v-model="form.snapshot_days" :min="0" :step="1" :controls="false" class="num-input" />
            <span class="unit">天</span>
            <span class="tip">为减少占用内存，设置商品快照保存天数，超过该时间自动删除(默认180天)，商品快照开启，显示删除功能</span>
          </el-form-item>

          <el-form-item label="会员等级折扣：">
            <el-radio-group v-model="form.level_discount">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
            <span class="tip">是否开启会员等级折扣</span>
          </el-form-item>

          <!-- ==================== 退货设置 ==================== -->
          <div class="legend"><span>退货设置</span></div>

          <el-form-item label="退款退货：">
            <el-radio-group v-model="form.canrefund">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
            <span class="tip">关闭后用户无法申请退款退货</span>
          </el-form-item>

          <el-form-item label="退款图片：">
            <el-radio-group v-model="form.refundpic">
              <el-radio :value="1">必填</el-radio>
              <el-radio :value="0">选填</el-radio>
            </el-radio-group>
            <span class="tip">选择必填后退款时，无需退货选项图片必须上传，退货退款选项图片选填</span>
          </el-form-item>

          <el-form-item label="联系人：">
            <el-input v-model="form.return_name" class="txt-input" />
          </el-form-item>

          <el-form-item label="联系电话：">
            <el-input v-model="form.return_tel" class="txt-input" />
          </el-form-item>

          <el-form-item label="退货地址：">
            <div class="region-group">
              <el-select v-model="form.return_province" placeholder="请选择省" class="region-sel" @change="onProvinceChange">
                <el-option v-for="p in provinceList" :key="p.name" :label="p.name" :value="p.name" />
              </el-select>
              <el-select v-model="form.return_city" placeholder="请选择市" class="region-sel" @change="onCityChange">
                <el-option v-for="c in cityList" :key="c.name" :label="c.name" :value="c.name" />
              </el-select>
              <el-select v-model="form.return_area" placeholder="请选择地区" class="region-sel">
                <el-option v-for="a in areaList" :key="a" :label="a" :value="a" />
              </el-select>
            </div>
          </el-form-item>

          <el-form-item label="详细地址：">
            <el-input v-model="form.return_address" class="addr-input" />
          </el-form-item>

          <!-- ==================== 优惠券 ==================== -->
          <div class="legend"><span>优惠券</span></div>

          <el-form-item label="退款时退回已使用优惠券：">
            <el-radio-group v-model="form.return_coupon">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
          </el-form-item>

          <!-- ==================== 商品评价 ==================== -->
          <div class="legend"><span>商品评价</span></div>

          <el-form-item label="商品评价：">
            <el-radio-group v-model="form.comment">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item v-if="Number(form.comment) === 1" label="评价审核：">
            <el-radio-group v-model="form.comment_check">
              <el-radio :value="1">开启</el-radio>
              <el-radio :value="0">关闭</el-radio>
            </el-radio-group>
            <span class="tip">关闭后用户的评价将直接显示,不需要后台审核</span>
          </el-form-item>

          <!-- ==================== 详情页显示 ==================== -->
          <div class="legend"><span>详情页显示</span></div>

          <el-form-item label="显示店铺信息：">
            <el-radio-group v-model="form.showjd">
              <el-radio :value="1">显示</el-radio>
              <el-radio :value="0">不显示</el-radio>
            </el-radio-group>
            <span class="tip">开启后商品详情页显示店铺信息</span>
          </el-form-item>

          <el-form-item label="显示佣金：">
            <el-radio-group v-model="form.showcommission">
              <el-radio :value="1">显示</el-radio>
              <el-radio :value="0">不显示</el-radio>
            </el-radio-group>
            <span class="tip">开启后商品详情页显示分销佣金</span>
          </el-form-item>

          <el-form-item label="显示销量：">
            <el-radio-group v-model="form.hide_sales">
              <el-radio :value="0">显示</el-radio>
              <el-radio :value="1">不显示</el-radio>
            </el-radio-group>
            <span class="tip">关闭后商品列表及详情不显示销量，商品组件中的销量显示，需前往设计页面单独设置</span>
          </el-form-item>

          <el-form-item label="显示库存：">
            <el-radio-group v-model="form.hide_stock">
              <el-radio :value="0">显示</el-radio>
              <el-radio :value="1">不显示</el-radio>
            </el-radio-group>
            <span class="tip">关闭后商品列表及详情不显示库存</span>
          </el-form-item>

          <el-form-item label="显示升级优惠：">
            <el-radio-group v-model="form.show_lvupsavemoney">
              <el-radio :value="1">显示</el-radio>
              <el-radio :value="0">不显示</el-radio>
            </el-radio-group>
            <span class="tip">商品详情页是否显示升级到下一级可节省多少元</span>
          </el-form-item>

          <!-- 提交 -->
          <el-form-item>
            <el-button type="success" :loading="saving" @click="handleSave">提交</el-button>
          </el-form-item>
        </el-form>

        <!-- 统一选择图片/图标弹窗：本地上传入库 / 已上传素材库 / 图标库改色，确定回填 -->
        <ImgIconPicker
          v-model:visible="topPicPickerVisible"
          default-tab="library"
          @pick="onTopPicPicked"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.shop-set {
  padding: 12px;
}

.set-card {
  background: #fff;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}

.set-card-header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 14px 20px;
  border-bottom: 1px solid #f0f0f0;
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.hd-icon {
  color: #909399;
  fill: currentColor;
}

.set-card-body {
  padding: 18px 20px 30px;
}

.set-form {
  max-width: 1100px;
}

.set-form :deep(.el-form-item) {
  margin-bottom: 18px;
}

.set-form :deep(.el-form-item__label) {
  color: #606266;
}

.num-input {
  width: 170px;
}

.txt-input {
  width: 220px;
}

.codtxt-input {
  width: 130px;
  margin: 0 10px;
}

.addr-input {
  width: 480px;
  max-width: 100%;
}

.inline-label {
  margin: 0 8px 0 4px;
  color: #606266;
}

.unit {
  margin: 0 10px 0 8px;
  color: #606266;
}

.tip {
  color: #999;
  font-size: 12px;
  line-height: 1.4;
}

.region-group {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.region-sel {
  width: 150px;
}

.img-preview {
  position: relative;
  width: 100px;
  height: 100px;
  margin-left: 12px;
  border: 1px solid #e4e7ed;
  border-radius: 4px;
  overflow: hidden;
  display: inline-flex;
  vertical-align: top;
}

.img-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.img-del {
  position: absolute;
  top: 2px;
  right: 4px;
  width: 18px;
  height: 18px;
  line-height: 16px;
  text-align: center;
  color: #fff;
  background: rgba(0, 0, 0, 0.55);
  border-radius: 50%;
  cursor: pointer;
  font-size: 14px;
}

/* 分组分隔标题：文字居左，右侧一条横线贯穿 */
.legend {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 26px 0 18px;
}

.legend span {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  white-space: nowrap;
}

.legend::after {
  content: '';
  flex: 1;
  height: 1px;
  background: #e8e8e8;
}
</style>
