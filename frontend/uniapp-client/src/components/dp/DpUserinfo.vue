<script setup lang="ts">
/**
 * dp-userinfo 会员信息卡（P27-3 任务247）
 * - 登录态：头像 + 昵称 + 余额 + 积分（GET /api/member/profile 拉取实时数据）
 * - 未登录：显示登录引导，点击跳 /pages/login/login
 * - 已登录点击卡片跳会员中心 /pages/my/my
 * params: title（可选标题）
 */
import { computed, ref } from "vue";
import { str, type DpParams } from "./shared";
import { dpNavigate } from "./navigate";
import { imgUrl, price2 } from "../../utils/format";
import { useSessionStore } from "../../stores/session";
import { fetchProfile } from "../../api/mall";
import type { MemberProfile } from "../../api/types";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

const session = useSessionStore();
const title = computed(() => str(props.params.title));
const isLoggedIn = computed(() => session.isLoggedIn);

/** 展示数据：先用会话缓存回显，profile 拉取成功后覆盖 */
const nickname = ref<string>("");
const headimg = ref<string>("");
const money = ref<number>(0);
const score = ref<number>(0);

function initFromSession() {
  const m = session.member;
  nickname.value = str(m.nickname) || "会员";
  headimg.value = str(m.avatar) || str(m.headimg);
  score.value = Number(m.score) > 0 ? Number(m.score) : 0;
}
initFromSession();

async function loadProfile() {
  if (!session.isLoggedIn) return;
  try {
    const p: MemberProfile = await fetchProfile();
    if (p) {
      nickname.value = str(p.nickname) || nickname.value;
      headimg.value = str(p.headimg) || headimg.value;
      money.value = Number(p.money) || 0;
      score.value = Number(p.score) || 0;
    }
  } catch {
    // request 层已处理提示；卡片保留会话缓存回显
  }
}
void loadProfile();

function onTap() {
  dpNavigate(isLoggedIn.value ? "/pages/my/my" : "/pages/login/login");
}
</script>

<template>
  <view class="dp-userinfo">
    <view v-if="title" class="dp-userinfo-title">{{ title }}</view>
    <view class="dp-userinfo-card" @click="onTap">
      <!-- 已登录：头像昵称 + 余额积分 -->
      <template v-if="isLoggedIn">
        <view class="dp-userinfo-top">
          <image v-if="headimg" class="dp-userinfo-avatar" :src="imgUrl(headimg)" mode="aspectFill" />
          <view v-else class="dp-userinfo-avatar dp-userinfo-avatar-ph"><text>头像</text></view>
          <text class="dp-userinfo-name">{{ nickname }}</text>
        </view>
        <view class="dp-userinfo-stats">
          <view class="dp-userinfo-stat">
            <text class="dp-userinfo-num">￥{{ price2(money) }}</text>
            <text class="dp-userinfo-label">余额</text>
          </view>
          <view class="dp-userinfo-split" />
          <view class="dp-userinfo-stat">
            <text class="dp-userinfo-num">{{ score }}</text>
            <text class="dp-userinfo-label">积分</text>
          </view>
        </view>
      </template>
      <!-- 未登录：登录引导 -->
      <template v-else>
        <view class="dp-userinfo-top">
          <view class="dp-userinfo-avatar dp-userinfo-avatar-ph"><text>头像</text></view>
          <view class="dp-userinfo-guest">
            <text class="dp-userinfo-name">未登录</text>
            <text class="dp-userinfo-tip">登录后享受会员权益</text>
          </view>
          <text class="dp-userinfo-loginbtn">去登录 ›</text>
        </view>
      </template>
    </view>
  </view>
</template>

<style scoped>
.dp-userinfo-title {
  padding: 0 4rpx 12rpx;
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.dp-userinfo-card {
  background: linear-gradient(135deg, #4a3aff, #7a5cff);
  border-radius: 16rpx;
  padding: 30rpx 28rpx;
  color: #ffffff;
}
.dp-userinfo-top {
  display: flex;
  align-items: center;
}
.dp-userinfo-avatar {
  width: 96rpx;
  height: 96rpx;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.25);
  flex-shrink: 0;
}
.dp-userinfo-avatar-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.8);
}
.dp-userinfo-name {
  margin-left: 20rpx;
  font-size: 32rpx;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.dp-userinfo-guest {
  flex: 1;
  min-width: 0;
  margin-left: 20rpx;
  display: flex;
  flex-direction: column;
}
.dp-userinfo-tip {
  margin-top: 6rpx;
  font-size: 22rpx;
  opacity: 0.8;
}
.dp-userinfo-loginbtn {
  padding: 10rpx 24rpx;
  border: 1rpx solid #ffffff;
  border-radius: 30rpx;
  font-size: 24rpx;
}
.dp-userinfo-stats {
  margin-top: 28rpx;
  display: flex;
  align-items: center;
  background: rgba(255, 255, 255, 0.12);
  border-radius: 12rpx;
  padding: 18rpx 0;
}
.dp-userinfo-stat {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.dp-userinfo-num {
  font-size: 30rpx;
  font-weight: 600;
}
.dp-userinfo-label {
  margin-top: 4rpx;
  font-size: 22rpx;
  opacity: 0.8;
}
.dp-userinfo-split {
  width: 1rpx;
  height: 52rpx;
  background: rgba(255, 255, 255, 0.3);
}
</style>
