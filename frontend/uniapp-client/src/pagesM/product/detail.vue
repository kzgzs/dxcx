<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { cartAdd, fetchGoodsDetail } from "../../api/mall";
import { fetchIndexSysset, type IndexSysset, type ShopdetailSlot, type DpNode } from "../../api/designer";
import DpRenderer from "../../components/dp/DpRenderer.vue";
import type { GoodsDetail } from "../../api/types";
import { useSessionStore } from "../../stores/session";
import { imgUrl, price2 } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import FollowBar from "../../components/follow-bar.vue";

const session = useSessionStore();

const goodsId = ref(0);
const detail = ref<GoodsDetail | null>(null);
const num = ref(1);
const adding = ref(false);

// 设计配置（任务281）：商品详情底部三菜单 + 公共底部描述
const sysset = ref<IndexSysset | null>(null);
const favorited = ref(false);

const shopdetail = computed(() => sysset.value?.shopdetail || {});

/** 底部栏菜单（仅显示中的槽位） */
const barMenus = computed(() => (shopdetail.value.list || []).filter((it) => it.isShow === 1));

/** 单品描述非空优先单品，否则回退公共底部描述 */
const ownDesc = computed(() => Boolean(detail.value && detail.value.description && detail.value.description.trim() !== ""));
const descHtml = computed(() => (ownDesc.value ? detail.value!.description! : shopdetail.value.description || ""));
const descImg = computed(() => (ownDesc.value ? "" : shopdetail.value.description_img || ""));

/**
 * 商品详情内容解析：
 * - 设计器装修：B 端把组件数组 JSON.stringify 存入 detail，这里还原为 DpNode[] 交 DpRenderer 渲染；
 * - 旧富文本：非数组 JSON（历史 HTML 字符串）回退 rich-text 渲染；
 * - 空：显示占位。
 */
function parseDetail(): { modules: DpNode[]; html: string } {
  const raw = detail.value?.detail as unknown;
  if (Array.isArray(raw)) return { modules: raw as DpNode[], html: "" };
  if (typeof raw === "string") {
    const s = raw.trim();
    if (s.startsWith("[")) {
      try {
        const arr = JSON.parse(s);
        if (Array.isArray(arr)) return { modules: arr as DpNode[], html: "" };
      } catch {
        // 非合法数组 JSON，按富文本 HTML 兜底
      }
    }
    return { modules: [], html: raw };
  }
  return { modules: [], html: "" };
}
/** 设计器装修模块（非空时用渲染器逐组件渲染） */
const detailModules = computed<DpNode[]>(() => parseDetail().modules);
/** 旧富文本 HTML（无装修模块时使用） */
const detailHtml = computed<string>(() => parseDetail().html);

onLoad((options) => {
  goodsId.value = Number(options && options.id) || 0;
  if (goodsId.value > 0) load();
  loadDesign();
});

async function load() {
  try {
    detail.value = await fetchGoodsDetail(goodsId.value);
  } catch {
    // request 已弹 toast
  }
}

async function loadDesign() {
  try {
    sysset.value = await fetchIndexSysset();
  } catch {
    // 设计配置拉取失败不阻断页面，走默认底部栏
  }
}

function step(delta: number) {
  const max = detail.value ? detail.value.stock : 99;
  const next = num.value + delta;
  if (next < 1 || next > max) return;
  num.value = next;
}

async function addCart() {
  if (!session.isLoggedIn) {
    uni.navigateTo({ url: "/pages/login/login?redirect=" + encodeURIComponent("/pagesM/product/detail?id=" + goodsId.value) });
    return;
  }
  if (!detail.value) return;
  adding.value = true;
  try {
    await cartAdd(detail.value.id, num.value);
    uni.showToast({ title: "已加入购物车", icon: "success" });
  } catch {
    // request 已弹 toast
  } finally {
    adding.value = false;
  }
}

function buyNow() {
  if (!session.isLoggedIn) {
    uni.navigateTo({ url: "/pages/login/login?redirect=" + encodeURIComponent("/pagesM/product/detail?id=" + goodsId.value) });
    return;
  }
  uni.navigateTo({ url: "/pagesM/order/buy?goods_id=" + goodsId.value + "&num=" + num.value });
}

/** 底部栏菜单点击（任务281：1客服/2购物车/3收藏/4其它） */
function goMenu(slot: ShopdetailSlot) {
  // 收藏：切换选中态（选中名称取配置 selectedtext）
  if (slot.menuType === 3) {
    favorited.value = !favorited.value;
    if (favorited.value) {
      uni.showToast({ title: slot.selectedtext || "已收藏", icon: "none" });
    }
    return;
  }

  // 客服：跟随系统取系统设置客服链接，否则用自定义链接
  let url = slot.pagePath || "";
  if (slot.menuType === 1 && slot.useSystem === 1) {
    url = sysset.value?.kfurl || url;
  }
  if (!url) {
    uni.showToast({ title: "暂未配置链接", icon: "none" });
    return;
  }

  if (/^https?:\/\//.test(url)) {
    // #ifdef H5
    window.open(url);
    // #endif
    return;
  }
  goPage(url);
}
</script>

<template>
  <view class="page">
    <!-- 公众号关注引导条（任务308：仅公众号H5端） -->
    <follow-bar :page-key="2" />

    <template v-if="detail">
      <!-- 轮播图 -->
      <swiper v-if="detail.images.length > 0" class="gallery" indicator-dots autoplay circular>
        <swiper-item v-for="(pic, i) in detail.images" :key="i">
          <image class="gallery-img" :src="imgUrl(pic)" mode="aspectFill" />
        </swiper-item>
      </swiper>
      <view v-else class="gallery gallery-ph">
        <text>暂无图片</text>
      </view>

      <!-- 价格区 -->
      <view class="price-card">
        <view class="price-row">
          <text class="price">￥{{ price2(detail.price) }}</text>
          <text v-if="detail.market_price > detail.price" class="market-price">￥{{ price2(detail.market_price) }}</text>
        </view>
        <text class="goods-name">{{ detail.name }}</text>
        <view class="meta-row">
          <text v-if="detail.category_name" class="meta">分类：{{ detail.category_name }}</text>
          <text class="meta">库存：{{ detail.stock }}</text>
        </view>
        <view class="num-row">
          <text class="num-label">数量</text>
          <view class="stepper">
            <text class="step-btn" @click="step(-1)">-</text>
            <text class="step-num">{{ num }}</text>
            <text class="step-btn" @click="step(1)">+</text>
          </view>
        </view>
      </view>

      <!-- 详情描述：设计器装修模块逐组件渲染（与设计页同源 DpRenderer），模块流不套卡片 -->
      <DpRenderer v-if="detailModules.length" :content="detailModules" />
      <!-- 兼容旧富文本 / 空详情 -->
      <view v-else class="detail-card">
        <text class="card-title">商品详情</text>
        <rich-text v-if="detailHtml" class="detail-html" :nodes="detailHtml"></rich-text>
        <text v-else class="detail-empty">暂无详情描述</text>
      </view>

      <!-- 商品描述（任务281：单品描述非空优先单品，否则公共底部描述 + 补充图） -->
      <view class="detail-card">
        <text class="card-title">商品描述</text>
        <template v-if="descHtml || descImg">
          <rich-text v-if="descHtml" class="detail-html" :nodes="descHtml"></rich-text>
          <image v-if="descImg" class="desc-img" :src="imgUrl(descImg)" mode="widthFix" />
        </template>
        <text v-else class="detail-empty">暂无商品描述</text>
      </view>

      <view class="bottom-space"></view>

      <!-- 底部操作栏（左侧三菜单按设计配置渲染） -->
      <view class="bottom-bar">
        <view v-for="(slot, i) in barMenus" :key="i" class="bar-menu" @click="goMenu(slot)">
          <image v-if="favorited && slot.menuType === 3 && slot.selectedIconPath" class="bar-menu-icon" :src="imgUrl(slot.selectedIconPath)" mode="aspectFit" />
          <image v-else-if="slot.iconPath" class="bar-menu-icon" :src="imgUrl(slot.iconPath)" mode="aspectFit" />
          <text class="bar-menu-text" :class="{ on: favorited && slot.menuType === 3 }">
            {{ favorited && slot.menuType === 3 && slot.selectedtext ? slot.selectedtext : slot.text }}
          </text>
        </view>
        <view class="bar-btn cart-btn" :class="{ disabled: adding }" @click="addCart">
          <text>加入购物车</text>
        </view>
        <view class="bar-btn buy-btn" @click="buyNow">
          <text>立即购买</text>
        </view>
      </view>
    </template>
    <view v-else class="empty"><text>加载中...</text></view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
.gallery {
  height: 750rpx;
  background: #ffffff;
}
.gallery-img {
  width: 100%;
  height: 100%;
}
.gallery-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 28rpx;
}
.price-card {
  margin: 20rpx;
  padding: 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.price-row {
  display: flex;
  align-items: baseline;
}
.price {
  color: #7b7de5;
  font-size: 44rpx;
  font-weight: 700;
}
.market-price {
  margin-left: 16rpx;
  color: #b5b5b5;
  font-size: 26rpx;
  text-decoration: line-through;
}
.goods-name {
  display: block;
  margin-top: 14rpx;
  font-size: 32rpx;
  color: #333333;
  font-weight: 600;
  line-height: 46rpx;
}
.meta-row {
  margin-top: 16rpx;
  display: flex;
}
.meta {
  margin-right: 32rpx;
  font-size: 24rpx;
  color: #999999;
}
.num-row {
  margin-top: 24rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.num-label {
  font-size: 26rpx;
  color: #555555;
}
.stepper {
  display: flex;
  align-items: center;
  border: 1rpx solid #e5e5e5;
  border-radius: 8rpx;
  overflow: hidden;
}
.step-btn {
  width: 60rpx;
  height: 56rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f6f6f8;
  color: #555555;
  font-size: 30rpx;
}
.step-num {
  width: 80rpx;
  text-align: center;
  font-size: 26rpx;
  color: #333333;
}
.detail-card {
  margin: 0 20rpx;
  padding: 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.card-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.detail-html {
  margin-top: 20rpx;
  font-size: 26rpx;
  color: #555555;
  line-height: 40rpx;
  word-break: break-all;
}
.detail-empty {
  display: block;
  margin-top: 20rpx;
  color: #999999;
  font-size: 26rpx;
}
.desc-img {
  margin-top: 20rpx;
  width: 100%;
}
.bottom-space {
  height: 140rpx;
}
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  padding: 16rpx 24rpx;
  padding-bottom: calc(16rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  border-top: 1rpx solid #eeeeee;
}
.bar-menu {
  width: 96rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-right: 8rpx;
}
.bar-menu-icon {
  width: 44rpx;
  height: 44rpx;
}
.bar-menu-text {
  margin-top: 4rpx;
  font-size: 22rpx;
  color: #666666;
}
.bar-menu-text.on {
  color: #ff4d4f;
}
.bar-btn {
  flex: 1;
  height: 80rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28rpx;
  border-radius: 40rpx;
}
.cart-btn {
  background: #8a5cf6;
  color: #ffffff;
  margin-right: 16rpx;
}
.cart-btn.disabled {
  opacity: 0.6;
}
.buy-btn {
  background: #ff7d1c;
  color: #ffffff;
}
.empty {
  padding: 200rpx 0;
  text-align: center;
  color: #999999;
  font-size: 26rpx;
}
</style>
