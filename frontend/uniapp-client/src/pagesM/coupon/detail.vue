<template>
  <view class="container">
    <block v-if="isload">
      <!-- 顶部渐变背景 -->
      <view class="couponbg" :style="{ background: couponBgGradient }"></view>

      <!-- 优惠券信息卡片 -->
      <view class="orderinfo" :style="{ backgroundColor: coupon.bg_color || '#fff' }">
        <!-- 顶部优惠券信息：左右两栏 f1/f2，虚线分隔 -->
        <view class="topitem">
          <!-- 左侧 f1：按类型显示 -->
          <block v-if="record.id">
            <view v-if="record.type == 1" class="f1" :style="{ color: color1 }">
              <text style="font-size: 32rpx">¥</text>
              <text class="t1">{{ record.money }}</text>
            </view>
            <view v-else-if="record.type == 10" class="f1" :style="{ color: color1 }">
              <text class="t1">{{ record.discount }}</text>
              <text style="font-size: 32rpx">折</text>
            </view>
            <view v-else-if="record.type == 2" class="f1" :style="{ color: color1 }">礼品券</view>
            <view v-else-if="record.type == 3" class="f1" :style="{ color: color1 }">
              <text class="t1">{{ record.limit_count }}</text>
              <text class="t2">次</text>
            </view>
            <view v-else-if="record.type == 4" class="f1" :style="{ color: color1 }">抵运费</view>
            <view v-else-if="record.type == 5" class="f1" :style="{ color: color1 }">餐饮券</view>
            <view v-else-if="record.type == 20" class="f1" :style="{ color: color1 }">券包</view>
            <view v-else class="f1" :style="{ color: color1 }">{{ record.type_txt || '优惠券' }}</view>
          </block>
          <block v-else>
            <view v-if="coupon.type == 1" class="f1" :style="{ color: color1 }">
              <text style="font-size: 32rpx">¥</text>
              <text class="t1">{{ coupon.money }}</text>
            </view>
            <view v-else-if="coupon.type == 10" class="f1" :style="{ color: color1 }">
              <text class="t1">{{ coupon.discount }}</text>
              <text style="font-size: 32rpx">折</text>
            </view>
            <view v-else-if="coupon.type == 2" class="f1" :style="{ color: color1 }">礼品券</view>
            <view v-else-if="coupon.type == 3" class="f1" :style="{ color: color1 }">
              <text class="t1">{{ coupon.limit_count }}</text>
              <text class="t2">次</text>
            </view>
            <view v-else-if="coupon.type == 4" class="f1" :style="{ color: color1 }">抵运费</view>
            <view v-else-if="coupon.type == 5" class="f1" :style="{ color: color1 }">餐饮券</view>
            <view v-else-if="coupon.type == 20" class="f1" :style="{ color: color1 }">券包</view>
            <view v-else class="f1" :style="{ color: color1 }">{{ coupon.type_txt || '优惠券' }}</view>
          </block>

          <!-- 右侧 f2：名称 + 使用条件/类型 -->
          <view class="f2">
            <view class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">
              {{ record.id ? record.name : coupon.name }}
            </view>
            <block v-if="record.id">
              <view v-if="record.type == 1 || record.type == 4 || record.type == 5" class="t2">
                <text :style="{ color: coupon.font_color || '#999' }">
                  {{ record.minprice > 0 ? '满' + record.minprice + '元可用' : '无门槛' }}
                </text>
              </view>
              <view class="t2" :style="{ color: coupon.font_color || '#2b2b2b' }">
                {{ record.type_txt || '优惠券' }}
              </view>
            </block>
            <block v-else>
              <view v-if="coupon.type == 1 || coupon.type == 4 || coupon.type == 5" class="t2">
                <text :style="{ color: coupon.font_color || '#999' }">
                  {{ coupon.minprice > 0 ? '满' + coupon.minprice + '元可用' : '无门槛' }}
                </text>
              </view>
              <view class="t2" :style="{ color: coupon.font_color || '#2b2b2b' }">
                {{ coupon.type_txt || '优惠券' }}
              </view>
            </block>
          </view>
        </view>

        <!-- 已领取状态：详情项列表 -->
        <block v-if="record.id">
          <view v-if="coupon.bid != 0" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">适用商家</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ coupon.bname }}</text>
          </view>
          <view v-if="coupon.platformname" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">适用平台</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ coupon.platformname }}</text>
          </view>
          <view v-if="record.type == 3 && record.status == 1" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">次数</text>
            <view class="flex-x-start" style="align-items: center">
              共计<view :style="{ color: coupon.font_color || '#333' }">{{ record.limit_count }}</view>次
              剩余<view :style="{ color: color1 }">{{ record.surplus_count || 0 }}</view>次
            </view>
          </view>
          <view class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">领取时间</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ record.createtime }}</text>
          </view>
          <view v-if="record.status == 1" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">使用时间</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ record.usetime }}</text>
          </view>
          <view v-if="record.type != 20" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">有效期</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ record.starttime }} 至 {{ record.endtime }}</text>
          </view>
          <view v-if="coupon.usetips" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">使用说明</text>
            <view class="t2" :style="{ color: coupon.font_color || '#333' }">{{ coupon.usetips }}</view>
          </view>
        </block>

        <!-- 未领取状态：详情项列表 -->
        <block v-else>
          <view v-if="coupon.bid != 0" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">适用商家</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ coupon.bname }}</text>
          </view>
          <view v-if="coupon.platformname" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">适用平台</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ coupon.platformname }}</text>
          </view>
          <view v-if="coupon.stock !== undefined" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">剩余库存</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ coupon.stock }} 张</text>
          </view>
          <view v-if="coupon.getnum !== undefined" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">已领取</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ coupon.getnum }} 人</text>
          </view>
          <view v-if="coupon.perlimit" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">每人限领</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ coupon.perlimit }} 张</text>
          </view>
          <view v-if="coupon.price && coupon.price > 0" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">所需金额</text>
            <text class="t2" style="color: red; font-weight: bold">¥{{ coupon.price }}</text>
          </view>
          <view v-if="coupon.score && coupon.score > 0" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">所需积分</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ coupon.score }} 积分</text>
          </view>
          <view class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">活动时间</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ coupon.starttime }} ~ {{ coupon.endtime }}</text>
          </view>
          <view v-if="coupon.type != 20" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">有效期</text>
            <text class="t2" :style="{ color: coupon.font_color || '#333' }">{{ coupon.starttime }} 至 {{ coupon.endtime }}</text>
          </view>
          <view v-if="coupon.usetips" class="item">
            <text class="t1" :style="{ color: coupon.title_color || '#2b2b2b' }">使用说明</text>
            <view class="t2" :style="{ color: coupon.font_color || '#333' }">{{ coupon.usetips }}</view>
          </view>
        </block>
      </view>

      <!-- 底部按钮：未领取 -->
      <block v-if="!record.id">
        <view class="btn-add" :style="{ background: btnGradient }" @click="handleGetCoupon">
          {{ coupon.price && coupon.price > 0 ? '立即购买' : coupon.score && coupon.score > 0 ? '立即兑换' : '立即领取' }}
        </view>
      </block>

      <!-- 底部按钮：已领取 -->
      <block v-else>
        <view v-if="record.status == 0 && (coupon.type == 1 || coupon.type == 10)" class="btn-add" :style="{ background: btnGradient }" @click="goUse">
          去使用
        </view>
        <view v-if="record.status == 1" class="btn-add" style="background: #ccc">已使用</view>
        <view v-if="record.status == 2" class="btn-add" style="background: #ccc">已过期</view>
      </block>

      <!-- 返回首页 -->
      <view class="text-center" style="margin-top: 40rpx; line-height: 60rpx" @click="goHome">
        <text>返回首页</text>
      </view>
    </block>

    <block v-if="loading">
      <view style="text-align: center; padding: 100rpx 0; color: #999">加载中...</view>
    </block>
  </view>
</template>

<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getCouponDetail, receiveCoupon, type CouponItem } from '@/api/coupon'

const loading = ref(true)
const isload = ref(false)
const coupon = reactive<CouponItem & Record<string, any>>({
  id: 0,
  name: '',
  type: 1,
  type_txt: '',
  money: 0,
  discount: 0,
  minprice: 0,
  limit_count: 0,
  stock: 0,
  getnum: 0,
  starttime: '',
  endtime: '',
  usetips: '',
  price: 0,
  score: 0,
  bg_color: '#fff',
  title_color: '#2b2b2b',
  font_color: '#999',
  bid: 0,
  bname: '',
  platformname: '',
  perlimit: 1
})

const record = reactive<CouponItem & Record<string, any>>({
  id: 0,
  couponid: 0,
  mid: 0,
  type: 1,
  type_txt: '',
  name: '',
  money: 0,
  discount: 0,
  minprice: 0,
  limit_count: 0,
  status: 0,
  createtime: '',
  usetime: '',
  starttime: '',
  endtime: '',
  surplus_count: 0
})

const color1 = ref('#ff4d4f')
const couponId = ref(0)

const couponBgGradient = computed(() => {
  return `linear-gradient(90deg, ${color1.value} 0%, rgba(255,77,79,0.8) 100%)`
})

const btnGradient = computed(() => {
  return `linear-gradient(90deg, ${color1.value} 0%, rgba(255,77,79,0.8) 100%)`
})

onLoad((options: any) => {
  if (options && options.id) {
    couponId.value = Number(options.id)
    loadDetail()
  } else {
    uni.showToast({ title: '缺少优惠券ID', icon: 'none' })
  }
})

async function loadDetail() {
  try {
    loading.value = true
    const res = await getCouponDetail(couponId.value)
    if (res && res.coupon) {
      Object.assign(coupon, res.coupon)
      if (res.record && res.record.id) {
        Object.assign(record, res.record)
      }
      isload.value = true
    } else {
      uni.showToast({ title: '优惠券不存在', icon: 'none' })
    }
  } catch (error: any) {
    uni.showToast({ title: error?.msg || '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function handleGetCoupon() {
  // 付费优惠券：跳转到支付页
  if (coupon.price && coupon.price > 0) {
    uni.navigateTo({
      url: `/pagesM/pay/pay?order_type=coupon&order_id=${coupon.id}&amount=${coupon.price}&title=${encodeURIComponent(coupon.name)}`
    })
    return
  }

  // 免费优惠券：调用领取接口
  try {
    uni.showLoading({ title: '领取中...' })
    await receiveCoupon(coupon.id)
    uni.hideLoading()
    uni.showToast({ title: '领取成功', icon: 'success' })
    setTimeout(() => {
      loadDetail()
    }, 1000)
  } catch (error: any) {
    uni.hideLoading()
    uni.showToast({ title: error?.msg || '领取失败', icon: 'none' })
  }
}

function goUse() {
  uni.switchTab({
    url: '/pages/index/index',
    fail: () => {
      uni.navigateBack()
    }
  })
}

function goHome() {
  uni.switchTab({
    url: '/pages/index/index',
    fail: () => {
      uni.navigateBack()
    }
  })
}
</script>

<style scoped lang="scss">
.container {
  display: flex;
  flex-direction: column;
  padding-bottom: 30rpx;
  min-height: 100vh;
  background: #f5f5f5;
}

/* 顶部渐变背景 */
.couponbg {
  width: 100%;
  height: 500rpx;
}

/* 优惠券信息卡片 */
.orderinfo {
  width: 94%;
  margin: -400rpx 3% 20rpx 3%;
  border-radius: 8px;
  padding: 14rpx 3%;
  background: #fff;
  color: #333;
}

/* 顶部优惠券信息：左右两栏，虚线分隔 */
.topitem {
  display: flex;
  padding: 24rpx 40rpx;
  align-items: center;
  border-bottom: 2px dashed #e5e5e5;
  position: relative;
}

.topitem .f1 {
  font-size: 50rpx;
  font-weight: 700;
  white-space: nowrap;
}

.topitem .f1 .t1 {
  font-size: 60rpx;
}

.topitem .f1 .t2 {
  font-size: 40rpx;
}

.topitem .f2 {
  margin-left: 40rpx;
  flex: 1;
}

.topitem .f2 .t1 {
  font-size: 36rpx;
  color: #2b2b2b;
  font-weight: 700;
  word-break: break-all;
}

.topitem .f2 .t2 {
  font-size: 24rpx;
  color: #999;
  height: 50rpx;
  line-height: 50rpx;
}

/* 详情项 */
.item {
  display: flex;
  flex-direction: column;
  width: 100%;
  padding: 0 40rpx;
  margin-top: 38rpx;
  box-sizing: border-box;
}

.item:last-child {
  border-bottom: 0;
}

.item .t1 {
  width: 200rpx;
  color: #2b2b2b;
  font-weight: 700;
  font-size: 30rpx;
  height: 60rpx;
  line-height: 60rpx;
}

.item .t2 {
  font-size: 28rpx;
  height: auto;
  line-height: 40rpx;
  white-space: pre-wrap;
}

.flex-x-start {
  display: flex;
  justify-content: flex-start;
}

.text-center {
  text-align: center;
}

/* 底部按钮 */
.btn-add {
  width: 90%;
  margin: 30rpx 5%;
  height: 96rpx;
  line-height: 96rpx;
  text-align: center;
  color: #fff;
  font-size: 30rpx;
  font-weight: 700;
  border-radius: 48rpx;
}
</style>
