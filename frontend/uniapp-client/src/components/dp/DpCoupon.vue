<script setup lang="ts">
/**
 * 优惠券 C 端渲染组件（对齐 canzhao/show-coupon.html + dp-coupon.css）
 * 布局：flex 横向一行2个，每券左右两栏（f1金额/名称 + f2使用条件/类型）
 * 背景：bgpic 背景图，无渐变无领取按钮
 */
import { computed, onMounted, ref } from 'vue'

interface Props {
  params?: Record<string, any>
  data?: any[]
}

const props = withDefaults(defineProps<Props>(), {
  params: () => ({})
})

/** 最终展示的优惠券列表 */
const couponList = ref<any[]>([])

onMounted(() => {
  const from = String(props.params.couponfrom ?? '0')
  if (from === '0' && props.data?.length) {
    couponList.value = props.data.slice(0, Number(props.params.shownum || 6))
  } else if (from === '1') {
    couponList.value = []
  }
})

const titleColor = computed(() => props.params.titlecolor || '#FFFFFF')
const remarkColor = computed(() => props.params.remarkcolor || '#E5E5E5')
/** 背景图路径转换：设计器 /imgsrc/xxx.png → H5 /static/imgsrc/xxx.png；无背景时默认用样式一 */
function resolveBgPic(url: string): string {
  if (!url) return '/static/imgsrc/coupon1.png'
  if (url.startsWith('/imgsrc/')) return '/static' + url
  return url
}
const bgPic = computed(() => resolveBgPic(props.params.bgpic || ''))
const hasCoupons = computed(() => couponList.value.length > 0)

/** 点击优惠券跳转到详情页 */
function goDetail(coupon: any) {
  const couponId = coupon.couponid || coupon.id
  if (!couponId) {
    uni.showToast({ title: '优惠券ID无效', icon: 'none' })
    return
  }
  uni.navigateTo({
    url: `/pagesM/coupon/detail?id=${couponId}`
  })
}
</script>

<template>
  <view
    class="dp-coupon-wrap"
    :style="{
      backgroundColor: params.bgcolor || 'transparent',
      margin: `${params.margin_y || 0}px ${params.margin_x || 0}px`,
      padding: `${params.padding_y || 0}px ${params.padding_x || 0}px`
    }"
  >
    <view v-if="hasCoupons" class="dp-coupon-list">
      <view
        v-for="(coupon, idx) in couponList"
        :key="coupon.id || idx"
        class="dp-coupon-item"
        :style="bgPic ? { backgroundImage: `url(${bgPic})`, backgroundSize: '100% 100%', backgroundRepeat: 'no-repeat' } : {}"
        @click="goDetail(coupon)"
      >
        <!-- 左侧 f1：type==1 显示金额+优惠券，type!=1 显示名称 -->
        <view class="dp-coupon-f1" :style="{ color: titleColor }">
          <block v-if="String(coupon.type) === '1'">
            <text class="dp-coupon-money">¥{{ coupon.money }}</text>
            <text class="dp-coupon-type">优惠券</text>
          </block>
          <block v-else>
            <text class="dp-coupon-name">{{ coupon.name }}</text>
          </block>
        </view>
        <!-- 右侧 f2：type==1 显示使用条件，type!=1 显示类型 -->
        <view class="dp-coupon-f2" :style="{ color: remarkColor }">
          <block v-if="String(coupon.type) === '1'">
            <text>{{ coupon.minprice > 0 ? '满' + coupon.minprice + '元可用' : '无门槛' }}</text>
          </block>
          <block v-else>
            <text>{{ coupon.type_txt || coupon.type_name || '优惠券' }}</text>
          </block>
        </view>
      </view>
    </view>

    <view v-else class="dp-coupon-empty">
      <text>暂无可用优惠券</text>
    </view>
  </view>
</template>

<style scoped lang="scss">
.dp-coupon-wrap {
  width: 100%;
  box-sizing: border-box;
}

/* 一行2个，参照 canzhao flex 横向排列 */
.dp-coupon-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.dp-coupon-item {
  width: calc(50% - 3px);
  height: 56px;
  display: flex;
  align-items: center;
  box-sizing: border-box;
  background-color: transparent;
  background-size: 100% 100%;
  background-repeat: no-repeat;
}

/* 左侧 f1：金额/名称 */
.dp-coupon-f1 {
  width: 45%;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
}

.dp-coupon-money {
  font-size: 18px;
  font-weight: bold;
  line-height: 1.1;
}

.dp-coupon-type {
  font-size: 9px;
  margin-top: 2px;
  opacity: 0.9;
}

.dp-coupon-name {
  font-size: 12px;
  font-weight: bold;
  line-height: 1.2;
  padding: 0 4px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 右侧 f2：使用条件/类型 */
.dp-coupon-f2 {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 0 4px;
  min-width: 0;
}

.dp-coupon-f2 text {
  font-size: 10px;
  line-height: 1.3;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dp-coupon-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 50px;
  color: #b1b3b8;
  font-size: 12px;
  background: #f4f5f7;
  border-radius: 4px;
}
</style>
