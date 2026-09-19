<script setup lang="ts">
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { fetchGoodsDetail } from '../../api/mall'

const props = defineProps<{
  goodsId: string
}>()

const goodsInfo = ref<any>(null)
const loading = ref(false)

onLoad(async (options: any) => {
  const goodsId = parseInt(options?.goodsId || props.goodsId)
  if (!goodsId) return
  
  try {
    // 调用真实后端 API 获取商品详情
    const res = await fetchGoodsDetail(goodsId)
    goodsInfo.value = res
  } catch (e) {
    console.error('加载商品详情失败:', e)
    uni.showToast({ title: '加载失败', icon: 'none' })
  }
})
</script>

<template>
  <view class="product-detail">
    <view v-if="goodsInfo" class="detail-content">
      <!-- 商品图片 -->
      <image v-if="goodsInfo.image || goodsInfo.pic" :src="goodsInfo.image || goodsInfo.pic" mode="aspectFill" class="goods-image" />
      <image src="//via.placeholder.com/400x400?text=商品图片" mode="aspectFill" class="goods-image" v-else />
      
      <!-- 商品名称 -->
      <text class="goods-name">{{ goodsInfo.name || goodsInfo.goods_name || '商品名称' }}</text>
      
      <!-- 价格 -->
      <view class="goods-price-box">
        <text class="current-price">¥{{ Number(goodsInfo.price || goodsInfo.sell_price || 0).toFixed(2) }}</text>
        <text v-if="goodsInfo.market_price > goodsInfo.price" class="market-price">
          ¥{{ Number(goodsInfo.market_price || goodsInfo.marketPrice || 0).toFixed(2) }}
        </text>
      </view>
      
      <!-- 销量 -->
      <text v-if="goodsInfo.sales || goodsInfo.sold_count" class="goods-sales">销量 {{ goodsInfo.sales || goodsInfo.sold_count }}</text>
      
      <!-- 购买按钮 -->
      <view class="buy-btn" @click="() => window.location.href = ''">
        立即购买
      </view>
      
      <!-- 返回按钮 -->
      <div class="back-btn" @click="window.history.back()">
        ← 返回列表
      </div>
    </view>
  </view>
</template>

<style scoped lang="scss">
.product-detail {
  min-height: 100vh;
  padding: 20rpx;
}

.detail-content {
  background: #fff;
  border-radius: 16rpx;
  overflow: hidden;
}

.goods-image {
  width: 100%;
  height: 600rpx;
  background: #f7f8fa;
}

.goods-name {
  display: block;
  font-size: 32rpx;
  color: #333;
  margin: 20rpx 0;
  line-height: 1.4;
}

.goods-price-box {
  display: flex;
  align-items: baseline;
  gap: 10rpx;
}

.current-price {
  font-size: 36rpx;
  color: #FF4A46;
  font-weight: bold;
}

.market-price {
  font-size: 28rpx;
  color: #999;
  text-decoration: line-through;
}

.goods-sales {
  display: block;
  font-size: 24rpx;
  color: #999;
  margin-top: 10rpx;
}

.buy-btn {
  width: 100%;
  height: 80rpx;
  background: linear-gradient(90deg, #FF4A46, #FF6A5E);
  color: #fff;
  font-size: 30rpx;
  text-align: center;
  line-height: 80rpx;
  border-radius: 40rpx;
  margin-top: 30rpx;
}

.back-btn {
  width: 100%;
  height: 80rpx;
  background: #f0f0f0;
  color: #666;
  font-size: 28rpx;
  text-align: center;
  line-height: 80rpx;
  border-radius: 40rpx;
  margin-top: 20rpx;
}
</style>
