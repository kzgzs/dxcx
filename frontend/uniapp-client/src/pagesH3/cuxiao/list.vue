<script setup lang="ts">
/**
 * 促销活动列表页
 * - cuxiao/list：type 0仅显示 1满减 2满赠 3换购
 * - 支持通过 proid 参数查看指定商品参与的促销
 */
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchCuxiaoList } from "../api";
import type { CuxiaoItem } from "../api";
import H3Empty from "../components/h3-empty.vue";

const list = ref<CuxiaoItem[]>([]);
const loaded = ref(false);
const proid = ref(0);

onLoad((options) => {
  proid.value = Number(options?.proid || 0);
  void loadList();
});

async function loadList() {
  loaded.value = false;
  try {
    const res = await fetchCuxiaoList(proid.value);
    list.value = res.list || [];
  } catch {
    list.value = [];
  } finally {
    loaded.value = true;
  }
}

function typeText(t: number): string {
  switch (t) {
    case 1:
      return "满减";
    case 2:
      return "满赠";
    case 3:
      return "换购";
    default:
      return "活动";
  }
}

function typeClass(t: number): string {
  if (t === 1) return "t1";
  if (t === 2) return "t2";
  if (t === 3) return "t3";
  return "t0";
}

/** 促销门槛文案 */
function condText(item: CuxiaoItem): string {
  if (item.gettj !== "") return item.gettj;
  if (item.minprice > 0) return "满 " + item.minprice.toFixed(2) + " 元";
  if (item.minnum > 0) return "满 " + String(item.minnum) + " 件";
  return "";
}

/** 优惠内容文案 */
function benefitText(item: CuxiaoItem): string {
  if (item.type === 1 && item.money > 0) return "立减 " + item.money.toFixed(2) + " 元";
  if (item.type === 2 && item.proname !== "") return "赠送：" + item.proname;
  if (item.type === 3 && item.proname !== "") return "换购：" + item.proname;
  return item.tip;
}
</script>

<template>
  <view class="page">
    <view v-if="loaded && list.length === 0" class="empty-wrap">
      <H3Empty text="暂无促销活动" />
    </view>

    <view v-for="item in list" :key="item.id" class="card">
      <view class="head-row">
        <view class="type-tag" :class="typeClass(item.type)"><text>{{ typeText(item.type) }}</text></view>
        <text class="name">{{ item.name }}</text>
      </view>

      <view v-if="condText(item) !== ''" class="row">
        <text class="row-label">参与条件</text>
        <text class="row-val">{{ condText(item) }}</text>
      </view>
      <view v-if="benefitText(item) !== ''" class="row">
        <text class="row-label">优惠内容</text>
        <text class="row-val strong">{{ benefitText(item) }}</text>
      </view>
      <view v-if="item.tip !== '' && benefitText(item) !== item.tip" class="row">
        <text class="row-label">活动说明</text>
        <text class="row-val">{{ item.tip }}</text>
      </view>
      <view v-if="item.starttime !== '' || item.endtime !== ''" class="row">
        <text class="row-label">活动时间</text>
        <text class="row-val">{{ item.starttime }} ~ {{ item.endtime }}</text>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx;
}
.empty-wrap {
  padding-top: 120rpx;
}
.card {
  background: #ffffff;
  border-radius: 20rpx;
  padding: 28rpx 24rpx;
  margin-bottom: 20rpx;
}
.head-row {
  display: flex;
  align-items: center;
}
.type-tag {
  padding: 6rpx 20rpx;
  border-radius: 8rpx;
  font-size: 22rpx;
  margin-right: 16rpx;
  flex-shrink: 0;
}
.type-tag.t0 {
  background: #f5f5f7;
  color: #999999;
}
.type-tag.t1 {
  background: #fff2f0;
  color: #ff4d4f;
}
.type-tag.t2 {
  background: #fff7e8;
  color: #ff8a3d;
}
.type-tag.t3 {
  background: #f0f5ff;
  color: #7b7de5;
}
.name {
  font-size: 30rpx;
  font-weight: 700;
  color: #333333;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.row {
  display: flex;
  margin-top: 16rpx;
  font-size: 26rpx;
}
.row-label {
  width: 140rpx;
  color: #999999;
  flex-shrink: 0;
}
.row-val {
  flex: 1;
  color: #666666;
  word-break: break-all;
}
.row-val.strong {
  color: #ff4d4f;
  font-weight: 600;
}
</style>
