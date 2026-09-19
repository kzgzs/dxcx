<script setup lang="ts">
/**
 * 幸运抽奖页（大转盘）
 * - 转盘 10 槽（j0 未中奖 + j1-j9），纯 CSS transform 旋转动画（小程序兼容）
 * - 先请求 draw 拿结果 jx，再驱动转盘停在对应槽位
 * - 剩余次数（-1 不限）/ 分享加次数 / 活动规则 / 中奖记录入口
 */
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import {
  fetchLotteryDetail,
  fetchLotteryLeftTimes,
  lotteryDraw,
  lotteryShare,
} from "../api";
import type { LotteryDetail, LotteryPrize } from "../api";
import { imgUrl } from "../../utils/format";
import { goPage } from "../../utils/navigate";
import { useSessionStore } from "../../stores/session";
import H3Empty from "../components/h3-empty.vue";

const session = useSessionStore();

const hid = ref(0);
const detail = ref<LotteryDetail | null>(null);
const loaded = ref(false);
const errMsg = ref("");

const totalLeft = ref(-1);
const dayLeft = ref(-1);

const spinning = ref(false);
const rotation = ref(0);
/** 结果弹窗 */
const showResult = ref(false);
const resultWin = ref(false);
const resultName = ref("");
const resultPic = ref("");

const SLOT = 36; // 每槽 36 度
const DURATION = 4000; // 动画时长 ms

onLoad((options) => {
  hid.value = Number(options?.hid || options?.id || 0);
  if (hid.value > 0) void load();
});

async function load() {
  try {
    detail.value = await fetchLotteryDetail(hid.value);
    await refreshLeft();
  } catch {
    detail.value = null;
    errMsg.value = "抽奖活动不存在或已下架";
  } finally {
    loaded.value = true;
  }
}

async function refreshLeft() {
  try {
    const res = await fetchLotteryLeftTimes(hid.value);
    totalLeft.value = res.pertotal_left;
    dayLeft.value = res.perday_left;
  } catch {
    // 忽略
  }
}

const leftText = computed(() => {
  if (dayLeft.value === 0) return "今日次数已用完";
  if (totalLeft.value === 0) return "抽奖次数已用完";
  if (dayLeft.value < 0 && totalLeft.value < 0) return "次数不限";
  const parts: string[] = [];
  if (totalLeft.value >= 0) parts.push("总剩余 " + totalLeft.value + " 次");
  if (dayLeft.value >= 0) parts.push("今日剩余 " + dayLeft.value + " 次");
  return parts.join("，");
});

const canDraw = computed(
  () => !spinning.value && dayLeft.value !== 0 && totalLeft.value !== 0 && detail.value !== null
);

/** 奖项按 index 排序（0-9） */
const prizes = computed<LotteryPrize[]>(() => {
  if (!detail.value) return [];
  return [...detail.value.prizes].sort((a, b) => a.index - b.index);
});

/** 槽位样式：旋转到对应角度并上移到转盘边缘 */
function slotStyle(index: number): string {
  return "transform: rotate(" + index * SLOT + "deg);";
}

async function doDraw() {
  if (!canDraw.value) return;
  spinning.value = true;
  try {
    const res = await lotteryDraw(
      hid.value,
      String(session.member.nickname || ""),
      String(session.member.avatar || "")
    );
    // 目标角度：让 jx 槽位于顶部指针处（转盘顺时针转 R 后，槽 i 位于 (i*SLOT + R) mod 360）
    const targetMod = (360 - res.jx * SLOT) % 360;
    const base = rotation.value % 360;
    const delta = (targetMod - base + 360) % 360;
    rotation.value = rotation.value + 360 * 6 + delta;
    setTimeout(() => {
      spinning.value = false;
      resultWin.value = res.jx > 0;
      resultName.value = res.jxmc || "谢谢参与";
      resultPic.value = res.jxtp === 1 ? res.pic : "";
      showResult.value = true;
      void refreshLeft();
    }, DURATION + 300);
  } catch {
    spinning.value = false;
    void refreshLeft();
  }
}

function closeResult() {
  showResult.value = false;
}

function goRecords() {
  goPage("/pagesH3/choujiang/records?hid=" + hid.value);
}

async function doShare() {
  try {
    const res = await lotteryShare(hid.value);
    if (res.added > 0) {
      uni.showToast({ title: "获得 " + res.added + " 次抽奖机会", icon: "success" });
      void refreshLeft();
    } else {
      uni.showToast({ title: "分享奖励已领完", icon: "none" });
    }
  } catch {
    // 已提示
  }
}
</script>

<template>
  <view class="page">
    <template v-if="detail">
      <image v-if="detail.banner" class="banner" :src="imgUrl(detail.banner)" mode="aspectFill" />
      <view class="title-bar">
        <text class="act-name">{{ detail.name }}</text>
        <text class="left-text">{{ leftText }}</text>
      </view>

      <!-- 转盘 -->
      <view class="wheel-wrap">
        <view class="pointer"><text>▼</text></view>
        <view
          class="wheel"
          :style="{ transform: 'rotate(' + rotation + 'deg)', transitionDuration: spinning ? '4s' : '0s' }"
        >
          <view v-for="p in prizes" :key="p.index" class="slot" :style="slotStyle(p.index)">
            <view class="slot-inner">
              <image v-if="p.pic" class="slot-pic" :src="imgUrl(p.pic)" mode="aspectFit" />
              <text class="slot-name">{{ p.mc }}</text>
            </view>
          </view>
        </view>
      </view>

      <!-- 按钮区 -->
      <view class="btn-row">
        <view class="main-btn" :class="{ disabled: !canDraw }" @click="doDraw">
          <text>{{ spinning ? "抽奖中..." : "立即抽奖" }}</text>
        </view>
      </view>
      <view class="sub-btn-row">
        <view class="sub-btn" @click="goRecords"><text>中奖记录</text></view>
        <view class="sub-btn" @click="doShare"><text>分享加次数</text></view>
      </view>

      <!-- 活动规则 -->
      <view v-if="detail.guize" class="card">
        <view class="card-title"><text>活动规则</text></view>
        <rich-text :nodes="detail.guize" />
      </view>

      <!-- 结果弹窗 -->
      <view v-if="showResult" class="modal-mask" @click="closeResult">
        <view class="modal" @click.stop>
          <text class="modal-title">{{ resultWin ? "恭喜中奖啦！" : "很遗憾" }}</text>
          <image v-if="resultPic !== ''" class="modal-pic" :src="imgUrl(resultPic)" mode="aspectFit" />
          <text class="modal-name">{{ resultName }}</text>
          <text v-if="resultWin" class="modal-tip">请在「中奖记录」中查看并领奖</text>
          <view class="modal-btn" @click="closeResult"><text>知道了</text></view>
        </view>
      </view>
    </template>
    <h3-empty v-else-if="loaded" :text="errMsg !== '' ? errMsg : '活动不存在'" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 60rpx;
}
.banner {
  width: 100%;
  height: 300rpx;
}
.title-bar {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  padding: 28rpx 32rpx 8rpx;
}
.act-name {
  font-size: 34rpx;
  color: #333333;
  font-weight: 700;
}
.left-text {
  font-size: 24rpx;
  color: #ff8a3d;
}
/* ===== 转盘 ===== */
.wheel-wrap {
  position: relative;
  width: 640rpx;
  height: 640rpx;
  margin: 32rpx auto 0;
}
.pointer {
  position: absolute;
  top: -14rpx;
  left: 50%;
  margin-left: -20rpx;
  width: 40rpx;
  height: 56rpx;
  z-index: 10;
  color: #ff4d4f;
  font-size: 40rpx;
  display: flex;
  justify-content: center;
}
.wheel {
  width: 640rpx;
  height: 640rpx;
  border-radius: 50%;
  background: radial-gradient(circle at center, #ffffff 0%, #efeffd 70%, #d9daf6 100%);
  border: 12rpx solid #7b7de5;
  box-sizing: border-box;
  position: relative;
  transition-property: transform;
  transition-timing-function: cubic-bezier(0.2, 0.8, 0.2, 1);
}
.slot {
  position: absolute;
  left: 50%;
  top: 50%;
  width: 160rpx;
  height: 320rpx;
  margin-left: -80rpx;
  margin-top: -320rpx;
  transform-origin: 80rpx 320rpx;
  display: flex;
  justify-content: center;
}
.slot-inner {
  width: 160rpx;
  padding-top: 24rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.slot-pic {
  width: 72rpx;
  height: 72rpx;
}
.slot-name {
  margin-top: 8rpx;
  font-size: 22rpx;
  color: #555566;
  text-align: center;
  max-width: 150rpx;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
/* ===== 按钮 ===== */
.btn-row {
  display: flex;
  justify-content: center;
  margin-top: 40rpx;
}
.main-btn {
  height: 88rpx;
  padding: 0 120rpx;
  border-radius: 44rpx;
  background: linear-gradient(90deg, #ff9a4d, #ff4d4f);
  color: #ffffff;
  font-size: 32rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}
.main-btn.disabled {
  opacity: 0.55;
}
.sub-btn-row {
  display: flex;
  justify-content: center;
  margin-top: 28rpx;
}
.sub-btn {
  margin: 0 20rpx;
  padding: 12rpx 40rpx;
  border: 1rpx solid #7b7de5;
  border-radius: 32rpx;
  color: #7b7de5;
  font-size: 26rpx;
}
.card {
  margin: 32rpx 24rpx 0;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 24rpx;
}
.card-title {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
  margin-bottom: 16rpx;
}
/* ===== 结果弹窗 ===== */
.modal-mask {
  position: fixed;
  left: 0;
  top: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.55);
  z-index: 99;
  display: flex;
  align-items: center;
  justify-content: center;
}
.modal {
  width: 560rpx;
  background: #ffffff;
  border-radius: 24rpx;
  padding: 48rpx 40rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.modal-title {
  font-size: 36rpx;
  color: #ff4d4f;
  font-weight: 700;
}
.modal-pic {
  width: 160rpx;
  height: 160rpx;
  margin-top: 28rpx;
}
.modal-name {
  margin-top: 20rpx;
  font-size: 30rpx;
  color: #333333;
  font-weight: 600;
}
.modal-tip {
  margin-top: 16rpx;
  font-size: 24rpx;
  color: #999999;
}
.modal-btn {
  margin-top: 36rpx;
  padding: 16rpx 80rpx;
  border-radius: 36rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 28rpx;
}
</style>
