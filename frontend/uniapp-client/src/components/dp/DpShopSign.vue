<script setup lang="ts">
/**
 * dp-shop 店招（任务302，对齐参考系统 dp-shop 口径）
 * params: style(1居中叠层/2左侧排版)/bgimg(背景图)/logo(1显示2隐藏)/logourl
 *         name(1显示2隐藏)/shopname/menu(1显示2隐藏)/navcolor(按钮颜色)
 * data: 导航按钮 [{text,imgurl,hrefurl,hrefname}]
 * - 图片走 imgUrl 助手；按钮点击走 dpNavigate
 */
import { computed } from "vue";
import { str, type DpParams } from "./shared";
import { dpNavigate } from "./navigate";
import { imgUrl } from "../../utils/format";

interface ShopNavItem {
  text: string;
  imgurl: string;
  hrefurl: string;
}

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

const style = computed(() => str(props.params.style, "1"));
const bgimg = computed(() => str(props.params.bgimg));
const logourl = computed(() => str(props.params.logourl));
const shopname = computed(() => str(props.params.shopname, "店铺名称"));
const showLogo = computed(() => str(props.params.logo, "1") === "1");
const showName = computed(() => str(props.params.name, "1") === "1");
const showMenu = computed(() => str(props.params.menu, "1") === "1");
const navcolor = computed(() => str(props.params.navcolor, "#FFFFFF"));
const navH = computed(() => Number(props.params.navheight ?? 34) || 34);
const navFont = computed(() => Number(props.params.navfontsize ?? 11) || 11);
const navIcon = computed(() => Number(props.params.naviconsize ?? 16) || 16);

const navs = computed<ShopNavItem[]>(() =>
  (Array.isArray(props.data) ? props.data : []).map((raw) => {
    const it = (raw && typeof raw === "object" ? raw : {}) as Record<string, unknown>;
    return { text: str(it.text), imgurl: str(it.imgurl), hrefurl: str(it.hrefurl) };
  })
);

function goNav(item: ShopNavItem) {
  if (item.hrefurl) dpNavigate(item.hrefurl);
}
</script>

<template>
  <view class="dp-shop" :class="{ s2: style === '2' }">
    <image v-if="bgimg" class="dp-shop-bg" :src="imgUrl(bgimg)" mode="aspectFill" />
    <view v-else class="dp-shop-bg-ph"></view>
    <!-- 样式一：居中叠层（logo 圆 + 胶囊店名 + 底部导航条） -->
    <template v-if="style !== '2'">
      <view v-if="showLogo" class="dp-shop-logo" :style="{ bottom: `${(navH + 26) * 2}rpx` }">
        <image v-if="logourl" class="dp-shop-logo-img" :src="imgUrl(logourl)" mode="aspectFill" />
      </view>
      <view v-if="showName" class="dp-shop-name" :style="{ bottom: `${(navH + 4) * 2}rpx` }">{{ shopname }}</view>      <view v-if="showMenu && navs.length" class="dp-shop-nav" :style="{ height: `${navH * 2}rpx` }">
        <view v-for="(n, ni) in navs" :key="ni" class="dp-shop-nav-item" @click="goNav(n)">
          <image v-if="n.imgurl" class="dp-shop-nav-icon" :src="imgUrl(n.imgurl)" mode="aspectFill" :style="{ width: `${navIcon * 2}rpx`, height: `${navIcon * 2}rpx` }" />
          <text class="dp-shop-nav-text" :style="{ color: navcolor, fontSize: `${navFont * 2}rpx` }">{{ n.text }}</text>
        </view>
      </view>
    </template>
    <!-- 样式二：左侧排版（方形 logo + 店名 + 菜单行） -->
    <template v-else>
      <view v-if="showLogo" class="dp-shop-logo sq" :style="{ bottom: `${(navH / 2) * 2}rpx`, zIndex: '5' }">
        <image v-if="logourl" class="dp-shop-logo-img" :src="imgUrl(logourl)" mode="aspectFill" />
      </view>
      <view v-if="showName" class="dp-shop-name s2" :style="{ bottom: `${(navH * 0.75 + 31) * 2}rpx`, transform: 'translateY(50%)' }">{{ shopname }}</view>
      <view v-if="showMenu && navs.length" class="dp-shop-nav row" :style="{ height: `${navH * 2}rpx` }">
        <view v-for="(n, ni) in navs" :key="ni" class="dp-shop-nav-item" @click="goNav(n)">
          <image v-if="n.imgurl" class="dp-shop-nav-icon" :src="imgUrl(n.imgurl)" mode="aspectFill" :style="{ width: `${navIcon * 2}rpx`, height: `${navIcon * 2}rpx` }" />
          <text class="dp-shop-nav-text" :style="{ color: navcolor, fontSize: `${navFont * 2}rpx` }">{{ n.text }}</text>
        </view>
      </view>
    </template>
  </view>
</template>

<style scoped>
.dp-shop {
  position: relative;
  width: 100%;
  height: 400rpx;
  overflow: hidden;
}
.dp-shop-bg {
  position: absolute;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
}
.dp-shop-bg-ph {
  position: absolute;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background: #d8dae2;
}
.dp-shop-logo {
  position: absolute;
  left: 50%;
  bottom: 120rpx;
  transform: translateX(-50%);
  width: 124rpx;
  height: 124rpx;
  border-radius: 50%;
  border: 4rpx solid rgba(255, 255, 255, 0.9);
  overflow: hidden;
  background: rgba(255, 255, 255, 0.6);
  box-sizing: border-box;
}
.dp-shop-logo.sq {
  left: 16rpx;
  bottom: 0;
  transform: none;
  width: 124rpx;
  height: 124rpx;
  border-radius: 8rpx;
  z-index: 5;
}
.dp-shop-logo-img {
  width: 100%;
  height: 100%;
}
.dp-shop-name {
  position: absolute;
  left: 50%;
  bottom: 76rpx;
  transform: translateX(-50%);
  max-width: 70%;
  padding: 4rpx 24rpx;
  border-radius: 999rpx;
  background: rgba(0, 0, 0, 0.25);
  color: #ffffff;
  font-size: 24rpx;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.dp-shop-name.s2 {
  left: 156rpx;
  bottom: 113rpx;
  transform: none;
  background: transparent;
  padding: 0;
  font-size: 26rpx;
  font-weight: 600;
  text-shadow: 0 2rpx 4rpx rgba(0, 0, 0, 0.5);
  width: 128rpx;
  height: 78rpx;
  display: flex;
  align-items: center;
  justify-content: flex-start;
}
.dp-shop-nav {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 68rpx;
  background: rgba(0, 0, 0, 0.35);
  display: flex;
  align-items: center;
  justify-content: space-around;
}
.dp-shop-nav.row {
  padding-left: 148rpx;
}
.dp-shop-nav-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2rpx;
}
.dp-shop-nav-icon {
  width: 32rpx;
  height: 32rpx;
  border-radius: 4rpx;
}
.dp-shop-nav-text {
  font-size: 22rpx;
  color: #ffffff;
  line-height: 1.1;
}
</style>
