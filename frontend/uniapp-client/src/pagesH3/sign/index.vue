<script setup lang="ts">
/**
 * 每日签到页
 * - 月历视图（已签日期标记，可左右翻月）
 * - 签到按钮（今日已签置灰），签到后弹积分提示
 * - 连签奖励规则（lxqdset 连签达标 / lxzsset 连签赠送）+ 规则说明
 * - 签到榜（display=1 时后端下发 list）
 */
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchSignPage, signSignin } from "../api";
import type { SignPageData } from "../api";
import { imgUrl } from "../../utils/format";
import H3Empty from "../components/h3-empty.vue";

const data = ref<SignPageData | null>(null);
const loaded = ref(false);
const signing = ref(false);
/** 日历当前展示年月 */
const calYear = ref(0);
const calMonth = ref(0);

onLoad(() => {
  void load();
});

async function load() {
  try {
    data.value = await fetchSignPage();
    const today = data.value.today || formatDate(new Date());
    const parts = today.split("-");
    calYear.value = Number(parts[0]);
    calMonth.value = Number(parts[1]);
  } catch {
    data.value = null;
  } finally {
    loaded.value = true;
  }
}

function formatDate(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return y + "-" + m + "-" + day;
}

const signedSet = computed<Set<string>>(() => {
  const s = new Set<string>();
  (data.value?.selectedDate || []).forEach((i) => s.add(i.date));
  return s;
});

const calTitle = computed(() => calYear.value + "年" + calMonth.value + "月");

/** 当月日历格子（前导空白 + 日期） */
const calCells = computed(() => {
  const first = new Date(calYear.value, calMonth.value - 1, 1);
  const days = new Date(calYear.value, calMonth.value, 0).getDate();
  const cells: { date: string; day: number }[] = [];
  for (let i = 0; i < first.getDay(); i++) {
    cells.push({ date: "", day: 0 });
  }
  for (let d = 1; d <= days; d++) {
    const date =
      calYear.value +
      "-" +
      String(calMonth.value).padStart(2, "0") +
      "-" +
      String(d).padStart(2, "0");
    cells.push({ date, day: d });
  }
  return cells;
});

function prevMonth() {
  if (calMonth.value === 1) {
    calYear.value -= 1;
    calMonth.value = 12;
  } else {
    calMonth.value -= 1;
  }
}

function nextMonth() {
  if (calMonth.value === 12) {
    calYear.value += 1;
    calMonth.value = 1;
  } else {
    calMonth.value += 1;
  }
}

function isSigned(date: string): boolean {
  return date !== "" && signedSet.value.has(date);
}

function isToday(date: string): boolean {
  return date !== "" && date === (data.value?.today || "");
}

async function doSign() {
  if (!data.value || signing.value || data.value.hassign === 1) return;
  signing.value = true;
  try {
    const res = await signSignin();
    uni.showToast({ title: "签到成功 +" + res.scoreadd + " 积分", icon: "success" });
    await load();
  } catch {
    // request 层已提示
  } finally {
    signing.value = false;
  }
}

const userinfo = computed(() => data.value?.userinfo || {});
const signset = computed(() => data.value?.signset || null);
</script>

<template>
  <view class="page">
    <template v-if="data">
      <!-- 顶部信息 -->
      <view class="head-card">
        <image v-if="userinfo.headimg" class="head-img" :src="imgUrl(userinfo.headimg)" mode="aspectFill" />
        <view v-else class="head-img head-img-ph"><text>客</text></view>
        <view class="head-meta">
          <text class="head-name">{{ userinfo.nickname || "会员" }}</text>
          <text class="head-sub">
            累计签到 {{ userinfo.signtimes || 0 }} 天 · 连签 {{ userinfo.signtimeslx || 0 }} 天 · 积分
            {{ userinfo.score || 0 }}
          </text>
        </view>
        <view class="sign-btn" :class="{ done: data.hassign === 1 }" @click="doSign">
          <text>{{ data.hassign === 1 ? "今日已签" : "签到" }}</text>
        </view>
      </view>

      <!-- 日历 -->
      <view class="card">
        <view class="cal-head">
          <view class="cal-nav" @click="prevMonth"><text>‹</text></view>
          <text class="cal-title">{{ calTitle }}</text>
          <view class="cal-nav" @click="nextMonth"><text>›</text></view>
        </view>
        <view class="week-row">
          <text v-for="w in ['日', '一', '二', '三', '四', '五', '六']" :key="w" class="week-cell">{{ w }}</text>
        </view>
        <view class="cal-grid">
          <view v-for="(c, i) in calCells" :key="i" class="cal-cell">
            <template v-if="c.day > 0">
              <view
                class="day-dot"
                :class="{ signed: isSigned(c.date), today: isToday(c.date) }"
              >
                <text>{{ c.day }}</text>
              </view>
            </template>
          </view>
        </view>
      </view>

      <!-- 连签奖励 -->
      <view v-if="signset" class="card">
        <view class="card-title"><text>连签奖励</text></view>
        <view v-if="signset.lxqdset.length > 0">
          <view v-for="r in signset.lxqdset" :key="'q' + r.days" class="rule-row">
            <text class="rule-days">连签 {{ r.days }} 天</text>
            <text class="rule-reward">
              积分 {{ r.score }}{{ r.coupon_name !== "" ? " + " + r.coupon_name : "" }}
            </text>
          </view>
        </view>
        <view v-if="signset.lxzsset.length > 0">
          <view v-for="r in signset.lxzsset" :key="'z' + r.days" class="rule-row">
            <text class="rule-days">连签到第 {{ r.days }} 天</text>
            <text class="rule-reward">
              加送积分 {{ r.score }}{{ r.coupon_name !== "" ? " + " + r.coupon_name : "" }}
            </text>
          </view>
        </view>
        <view v-if="signset.lxqdset.length === 0 && signset.lxzsset.length === 0" class="rule-row">
          <text class="rule-reward">每日签到得 {{ signset.score }} 积分</text>
        </view>
        <view v-if="signset.guize" class="guize-box">
          <rich-text :nodes="signset.guize" />
        </view>
      </view>

      <!-- 签到榜 -->
      <view v-if="data.list && data.list.length > 0" class="card">
        <view class="card-title"><text>签到榜</text></view>
        <view v-for="(m, i) in data.list" :key="m.id" class="rank-row">
          <text class="rank-no">{{ i + 1 }}</text>
          <image v-if="m.headimg" class="rank-head" :src="imgUrl(m.headimg)" mode="aspectFill" />
          <view v-else class="rank-head rank-head-ph"><text>客</text></view>
          <text class="rank-name">{{ m.nickname || "会员" }}</text>
          <text class="rank-days">连签 {{ m.signtimeslx }} 天</text>
        </view>
      </view>
    </template>
    <h3-empty v-else-if="loaded" text="签到活动暂不可用" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx 24rpx 40rpx;
  box-sizing: border-box;
}
.head-card {
  display: flex;
  align-items: center;
  background: linear-gradient(120deg, #9b9df0, #7b7de5);
  border-radius: 20rpx;
  padding: 32rpx 28rpx;
  color: #ffffff;
}
.head-img {
  width: 96rpx;
  height: 96rpx;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.3);
  flex-shrink: 0;
}
.head-img-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32rpx;
}
.head-meta {
  flex: 1;
  margin-left: 20rpx;
  min-width: 0;
}
.head-name {
  display: block;
  font-size: 30rpx;
  font-weight: 600;
}
.head-sub {
  display: block;
  margin-top: 8rpx;
  font-size: 22rpx;
  opacity: 0.9;
}
.sign-btn {
  padding: 16rpx 40rpx;
  border-radius: 36rpx;
  background: #ffffff;
  color: #7b7de5;
  font-size: 28rpx;
  font-weight: 700;
}
.sign-btn.done {
  opacity: 0.7;
}
.card {
  margin-top: 20rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 24rpx;
}
.card-title {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
  margin-bottom: 20rpx;
}
.cal-head {
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 20rpx;
}
.cal-nav {
  width: 64rpx;
  height: 64rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #7b7de5;
  font-size: 40rpx;
}
.cal-title {
  margin: 0 32rpx;
  font-size: 30rpx;
  color: #333333;
  font-weight: 600;
}
.week-row {
  display: flex;
}
.week-cell {
  flex: 1;
  text-align: center;
  font-size: 24rpx;
  color: #999999;
  padding-bottom: 12rpx;
}
.cal-grid {
  display: flex;
  flex-wrap: wrap;
}
.cal-cell {
  width: 14.2857%;
  display: flex;
  justify-content: center;
  padding: 8rpx 0;
}
.day-dot {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26rpx;
  color: #333333;
}
.day-dot.today {
  border: 2rpx solid #7b7de5;
}
.day-dot.signed {
  background: #7b7de5;
  color: #ffffff;
}
.rule-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12rpx 0;
  border-bottom: 1rpx solid #f5f5f7;
}
.rule-row:last-child {
  border-bottom: none;
}
.rule-days {
  font-size: 26rpx;
  color: #333333;
}
.rule-reward {
  font-size: 24rpx;
  color: #ff8a3d;
}
.guize-box {
  margin-top: 20rpx;
  font-size: 24rpx;
  color: #999999;
}
.rank-row {
  display: flex;
  align-items: center;
  padding: 12rpx 0;
}
.rank-no {
  width: 48rpx;
  font-size: 26rpx;
  color: #ff8a3d;
  font-weight: 700;
}
.rank-head {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  background: #f2f2f5;
}
.rank-head-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 24rpx;
}
.rank-name {
  flex: 1;
  margin-left: 16rpx;
  font-size: 26rpx;
  color: #333333;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.rank-days {
  font-size: 22rpx;
  color: #7b7de5;
}
</style>
