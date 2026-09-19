<script setup lang="ts">
/**
 * 商城商品 C 端渲染组件（任务 302）
 * params: B 端属性面板所有配置；data: selected.data[] (手动选品)；content: 分组分类模式待后端下发
 */
import { computed, onMounted, ref } from 'vue'

interface Props {
  params?: Record<string, any>
  data?: any[]
}

const props = withDefaults(defineProps<Props>(), {
  params: () => ({})
})

/** 商品列表（data 直接取手动选品数组） */
const productList = ref<any[]>([])

/** C 端主色调 */
const mainColor = '#FD4A46' // dev 默认

/** 跟随主色调的颜色（系统主题色 color1） */
async function getMainColor() {
  return mainColor
}

onMounted(async () => {
  if (!props.data?.length) {
    productList.value = []
    return
  }
  // 简单映射为前端需要的格式：{id,name,image,price,market_price,sales}
  const mapped = props.data!.map((item) => ({
    id: item.id || item.goods_id,
    name: item.name || item.goods_name || '商品名称',
    image: item.image || item.pic || '',
    price: Number(item.price || item.sell_price || 0),
    market_price: Number(item.market_price || item.marketPrice || 0),
    sales: item.sales || item.sold_count || 0,
    hrefurl: item.hrefurl || item.link || ''
  }))
  productList.value = mapped.slice(0, Number(props.params.proshownum || 6))
  
  // 强制映射 ID 和链接字段，确保能正常跳转
  productList.value = productList.value.map((item, index) => ({
    ...item,
    id: item.id || item.goods_id || `goods_${index}_${Date.now()}`,
    hrefurl: item.hrefurl || ''
  }))
})

// ========== computed ==========
const titleshow = computed(() => String(props.params.titleshow ?? '1') === '1')
const titletext = computed(() => props.params.titletext || '商城商品')
const notetext = computed(() => props.params.notetext || '查看更多')
const arrowshow = computed(() => String(props.params.arrowshow ?? '1') === '1')
const hrefurl = computed(() => props.params.hrefurl || '')
const styleNum = computed(() => Number(props.params.style ?? '2'))

// 布局计算
function getGridStyle(): string {
  switch (styleNum.value) {
    case 1: return 'repeat(1, 1fr)'
    case 3: return 'repeat(3, 1fr)'
    case 4:
    case 5: return 'repeat(3, 1fr)'
    default: return 'repeat(2, 1fr)'
  }
}
function getGap(): number {
  return Number(props.params.margin_y || props.params.padding_y || 8)
}
function getProductPaddingY(): number {
  return Number(props.params.pro_margin_y || 0)
}
function getProductMarginX(): number {
  return Number(props.params.pro_margin_x || 0)
}

function showName(): boolean {
  return String(props.params.nameshow ?? '1') === '1'
}
function showPriceMode(): number {
  return Number(props.params.priceshow ?? '1')
}
function showSales(): boolean {
  return String(props.params.salesshow ?? '1') === '1'
}
function buyBtnMode(): string {
  return props.params.buybtnshow ?? '1'
}
function probadgeMode(): string {
  return props.params.probadge ?? '0'
}

/** 预览角标文字（probadge 1 推荐 2 热卖 3 新上 4 限时 5 促销，0 无；自己上传图优先） */
function prodBadgeText(): string {
  const map: Record<string, string> = { '1': '推荐', '2': '热卖', '3': '新上', '4': '限时', '5': '促销' }
  return map[String(probadgeMode())] || ''
}

function prodPriceColor(p: Record<string, any>): string {
  return p.pricecolormode === '1' ? p.pricecolor : '#FD4A46'
}

/** 点击跳转到详情页/链接地址 */
function goProduct(link: string, name: string) {
  if (link) {
    uni.navigateTo({ url: link })
  } else {
    // 兜底：去分类页或搜索页
    uni.switchTab({ url: '/pages/catalog/index/index' })
  }
}

/** 打开商品详情：如果 B 端手动选品没 hrefurl，就跳转到商品详情页 */
function openGoodsDetail(item: any) {
  console.log('[DpProduct] 点击商品:', item)
  
  if (item.hrefurl) {
    console.log('[DpProduct] 跳转 hrefurl:', item.hrefurl)
    uni.navigateTo({ url: item.hrefurl })
  } else if (item.id) {
    console.log('[DpProduct] 跳转 ID:', item.id)
    // 如果有 id，直接跳转到商品详情页（按 pages.json 中的子包路径）
    uni.navigateTo({ url: '/pagesM/product/detail?id=' + item.id })
  } else {
    // 兜底：跳到首页
    console.log('[DpProduct] 兜底跳首页')
    uni.navigateTo({ url: '/pages/index/index' })
  }
}
</script>

<template>
  <view class="dp-product" :style="{ backgroundColor: params.bgmode === '1' ? undefined : (params.bgcolor || '#FFFFFF'), borderRadius: `${Number(params.borderradius || 0)}px`, backgroundImage: params.bgmode === '1' && params.bgimg ? `url(${params.bgimg})` : undefined, backgroundSize: 'cover', backgroundPosition: 'center' }">
    <!-- 标题栏 -->
    <view v-if="titleshow" class="dp-product-head" :style="{ paddingTop: `${Number(params.padding_y || 8)}rpx`, paddingLeft: `${Number(params.padding_x || 8)}rpx`, paddingRight: `${Number(params.padding_x || 8)}rpx` }">
      <text class="dp-product-title" :style="{ fontSize: `${Number(params.titlefontsize || 14)}rpx`, color: params.titlecolor || '#000000' }">{{ titletext }}</text>
      <view class="dp-product-note" @click="openGoodsDetail({hrefurl: hrefurl})" :style="{ fontSize: `${Number(params.notefontsize || 12)}rpx`, color: params.notecolor || '#999999' }">
        {{ notetext }}
        <text v-if="arrowshow">›</text>
      </view>
    </view>
    <!-- 商品列表 -->
    <view class="dp-product-list" :style="{ display: 'grid', gridTemplateColumns: getGridStyle(), gap: `${getGap()}px`, marginTop: `${getProductMarginX()}px`, marginBottom: `${getProductMarginX()}px`, padding: `${String(getProductPaddingY()) + 'px'}` }">
      <view v-for="p in productList" :key="p.id" class="dp-product-item" @click="openGoodsDetail(p)" :style="{ borderRadius: `${Number(params.proradius || 5)}px`, overflow: 'hidden', background: '#f7f8fa' }">
        <image class="dp-product-img" :src="p.image" mode="aspectFill" :style="{ width: '100%', height: '160px', borderRadius: `${Number(params.imgradius || 0)}px`, background: '#f7f8fa' }" />
        <span v-if="probadgeMode() !== '0'" class="dp-product-badge" :style="{ backgroundColor: 'rgba(255, 68, 0, 0.85)', color: '#fff' }">{{ prodBadgeText() }}</span>
        <image v-if="params.badgeimg" class="dp-product-badgeimg" :src="params.badgeimg" mode="aspectFit" :style="{ width: '24px', height: '24px', position: 'absolute', left: 0, top: 0 }" />
        
        <text v-if="showName()" class="dp-product-name" :style="{ fontSize: `${Number(params.namefontsize || 16)}rpx`, color: params.namecolor || '#000000', fontWeight: params.namebold === '1' ? 'bold' : 'normal', margin: '8px 0' }">{{ p.name }}</text>
        
        <!-- 价格/销量行 -->
        <view class="dp-product-foot" :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '8px' }">
          <view v-if="showPriceMode() !== 0" class="dp-product-price" :style="{ fontSize: '24rpx', color: prodPriceColor(params || {}), fontWeight: params.pricebold === '1' ? 'bold' : 'normal' }">
            ¥{{ p.price.toFixed(2) }}
            <text v-if="showPriceMode() === 1 && p.market_price > p.price" class="dp-product-market" :style="{ fontSize: '20rpx', color: '#c0c4cc', marginLeft: 6 }">¥{{ p.market_price.toFixed(2) }}</text>
          </view>
          <text v-if="showSales() && p.sales > 0" class="dp-product-sales" :style="{ fontSize: '20rpx', color: params.salescolor || '#999999', fontWeight: params.salesbold === '1' ? 'bold' : 'normal' }">销量{{ p.sales }}</text>
          <view v-if="buyBtnMode() === '1'" class="dp-product-cart" :style="{ backgroundColor: prodPriceColor(params || {}), width: '20px', height: '20px', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }">🛒</view>
          <image v-if="buyBtnMode() === '2' && params.buybtnimg" class="dp-product-buyimg" :src="params.buybtnimg" mode="aspectFit" :style="{ width: '20px', height: '20px', flexShrink: 0 }" />
        </view>
      </view>
    </view>
  </view>
</template>

<style scoped lang="scss">
.dp-product {
  width: 100%;
  overflow: hidden;
}

.dp-product-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
}

.dp-product-title {
  font-weight: 600;
  line-height: 1.2;
}

.dp-product-note {
  display: flex;
  align-items: center;
  gap: 2px;
}

.dp-product-list {
  width: 100%;
}

.dp-product-item {
  position: relative;
  background-color: #f7f8fa;
  border-radius: 6px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.dp-product-img {
  width: 100%;
  display: block;
}

.dp-product-badge {
  position: absolute;
  left: 0;
  top: 0;
  font-size: 10px;
  padding: 2px 6px;
  border-radius: 0 0 4px 0;
}

.dp-product-badgeimg {
  position: absolute;
  left: 0;
  top: 0;
  z-index: 1;
}

.dp-product-name {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dp-product-foot {
  display: flex;
  align-items: center;
  gap: 4px;
}

.dp-product-market {
  text-decoration: line-through;
  font-size: 20rpx !important;
}

.dp-product-sales {
  color: #999;
  font-size: 20rpx !important;
}

.dp-product-cart {
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
}

.dp-product-buyimg {
  object-fit: contain;
}
</style>
