<script setup lang="ts">
/**
 * Fifa 竞猜详情页
 * - fifa/detail：赛事详情 + 我的竞猜记录 + 竞猜设置
 * - guessStatus：0可竞猜 1窗口未开（距开赛超过 starthour 小时） 2已结束
 * - guesstype 1猜胜负（guess1set 选项）；2猜比分（guess2set 有则选项，无则自由输入如 2:1）
 * - 提交 fifa/subguess
 */
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchFifaDetail, fifaSubguess } from "../api";
import type { FifaDetailData } from "../api";
import { imgUrl } from "../../utils/format";

const hid = ref(0);
const data = ref<FifaDetailData | null>(null);
const loadError = ref(false);
/** 当前竞猜类型 1胜负 2比分 */
const guesstype = ref(1);
const guess1 = ref("");
const guess2 = ref("");
const guess2Input = ref("");
const submitting = ref(false);

onLoad((options) => {
  hid.value = Number(options?.id || 0);
  void loadDetail();
});

async function loadDetail() {
  loadError.value = false;
  try {
    const res = await fetchFifaDetail(hid.value);
    data.value = res;
    // 已竞猜回显
    const rec = res.myrecord;
    if (rec) {
      if (typeof rec.guess1 === "string" && rec.guess1 !== "") guess1.value = rec.guess1;
      if (typeof rec.guess2 === "string" && rec.guess2 !== "") {
        guess2.value = rec.guess2;
        guess2Input.value = rec.guess2;
      }
    }
  } catch {
    data.value = null;
    loadError.value = true;
  }
}

/** 胜负选项（后端未配置时默认三项） */
function guess1Options(): string[] {
  const opts = data.value?.sset.guess1set || [];
  return opts.length > 0 ? opts : ["主胜", "平局", "客胜"];
}

function hasGuess2Options(): boolean {
  const opts = data.value?.sset.guess2set || [];
  return opts.length > 0;
}

function guess2Options(): string[] {
  return data.value?.sset.guess2set || [];
}

function pickGuess1(v: string) {
  if (data.value?.guessStatus !== 0) return;
  guess1.value = guess1.value === v ? "" : v;
}

function pickGuess2(v: string) {
  if (data.value?.guessStatus !== 0) return;
  guess2.value = guess2.value === v ? "" : v;
  guess2Input.value = guess2.value;
}

/** 是否已提交过该类型 */
function hasSubmitted(type: number): boolean {
  const rec = data.value?.myrecord;
  if (!rec) return false;
  if (type === 1) return typeof rec.guess1 === "string" && rec.guess1 !== "";
  return typeof rec.guess2 === "string" && rec.guess2 !== "";
}

function stText(st: unknown): string {
  if (st === 1) return "猜中";
  if (st === 2) return "未中";
  return "待开奖";
}

function stClass(st: unknown): string {
  if (st === 1) return "win";
  if (st === 2) return "lose";
  return "wait";
}

async function doSubmit() {
  if (submitting.value || !data.value || data.value.guessStatus !== 0) return;
  const guess = guesstype.value === 1 ? guess1.value.trim() : guess2Input.value.trim();
  if (guess === "") {
    uni.showToast({ title: guesstype.value === 1 ? "请选择胜负平" : "请输入比分", icon: "none" });
    return;
  }
  submitting.value = true;
  try {
    await fifaSubguess(hid.value, guesstype.value, guess);
    uni.showToast({ title: "竞猜成功", icon: "success" });
    await loadDetail();
  } catch {
    /* request.ts 已 toast 业务错误 */
  } finally {
    submitting.value = false;
  }
}

function statusText(): string {
  if (!data.value) return "";
  if (data.value.guessStatus === 1) return "竞猜窗口未开启（开赛前 " + String(data.value.sset.starthour || 0) + " 小时开放）";
  if (data.value.guessStatus === 2) return "该场比赛已结束，无法竞猜";
  return "";
}
</script>

<template>
  <view class="page">
    <view v-if="loadError" class="card err-card">
      <text class="err-text">赛事加载失败，请返回重试</text>
    </view>

    <template v-else-if="data">
      <!-- 赛事信息 -->
      <view class="match-card">
        <view class="match-top">
          <text class="stage">{{ data.detail.matchStage }}</text>
          <text class="match-time">{{ data.detail.startDate }} {{ data.detail.startTime }}</text>
          <text class="match-status">{{ data.detail.matchStatusText }}</text>
        </view>
        <view class="match-body">
          <view class="team">
            <image
              v-if="data.detail.leftTeam_logo !== ''"
              class="team-logo"
              :src="imgUrl(data.detail.leftTeam_logo)"
              mode="aspectFit"
            />
            <text class="team-name">{{ data.detail.leftTeam_name }}</text>
          </view>
          <view class="vs">
            <text v-if="data.detail.leftTeam_score !== ''" class="score">
              {{ data.detail.leftTeam_score }} : {{ data.detail.rightTeam_score }}
            </text>
            <text v-else class="vs-text">VS</text>
          </view>
          <view class="team">
            <image
              v-if="data.detail.rightTeam_logo !== ''"
              class="team-logo"
              :src="imgUrl(data.detail.rightTeam_logo)"
              mode="aspectFit"
            />
            <text class="team-name">{{ data.detail.rightTeam_name }}</text>
          </view>
        </view>
        <view v-if="statusText() !== ''" class="status-tip">
          <text>{{ statusText() }}</text>
        </view>
      </view>

      <!-- 竞猜类型切换 -->
      <view class="tab-bar">
        <view class="tab" :class="{ active: guesstype === 1 }" @click="guesstype = 1"><text>猜胜负</text></view>
        <view class="tab" :class="{ active: guesstype === 2 }" @click="guesstype = 2"><text>猜比分</text></view>
      </view>

      <!-- 猜胜负 -->
      <view v-if="guesstype === 1" class="card">
        <view class="chips">
          <view
            v-for="opt in guess1Options()"
            :key="opt"
            class="chip"
            :class="{ active: guess1 === opt, locked: data.guessStatus !== 0 }"
            @click="pickGuess1(opt)"
          >
            <text>{{ opt }}</text>
          </view>
        </view>
        <view v-if="hasSubmitted(1)" class="submit-info">
          <text>已竞猜：{{ data.myrecord.guess1 }}</text>
          <text class="st" :class="stClass(data.myrecord.guess1st)">{{ stText(data.myrecord.guess1st) }}</text>
          <text v-if="typeof data.myrecord.givescore1 === 'number' && data.myrecord.guess1st === 1" class="add-score">
            +{{ data.myrecord.givescore1 }}积分
          </text>
        </view>
        <view
          v-else
          class="main-btn"
          :class="{ disabled: submitting || data.guessStatus !== 0 || guess1 === '' }"
          @click="doSubmit"
        >
          <text>{{ data.guessStatus === 0 ? "确认竞猜" : "暂不可竞猜" }}</text>
        </view>
      </view>

      <!-- 猜比分 -->
      <view v-else class="card">
        <view v-if="hasGuess2Options()" class="chips">
          <view
            v-for="opt in guess2Options()"
            :key="opt"
            class="chip"
            :class="{ active: guess2 === opt, locked: data.guessStatus !== 0 }"
            @click="pickGuess2(opt)"
          >
            <text>{{ opt }}</text>
          </view>
        </view>
        <input
          v-else
          v-model="guess2Input"
          class="input"
          placeholder="请输入比分，如 2:1"
          :disabled="data.guessStatus !== 0"
        />
        <view v-if="hasSubmitted(2)" class="submit-info">
          <text>已竞猜：{{ data.myrecord.guess2 }}</text>
          <text class="st" :class="stClass(data.myrecord.guess2st)">{{ stText(data.myrecord.guess2st) }}</text>
          <text v-if="typeof data.myrecord.givescore2 === 'number' && data.myrecord.guess2st === 1" class="add-score">
            +{{ data.myrecord.givescore2 }}积分
          </text>
        </view>
        <view
          v-else
          class="main-btn"
          :class="{ disabled: submitting || data.guessStatus !== 0 || guess2Input.trim() === '' }"
          @click="doSubmit"
        >
          <text>{{ data.guessStatus === 0 ? "确认竞猜" : "暂不可竞猜" }}</text>
        </view>
      </view>

      <!-- 规则 -->
      <view v-if="data.sset.guize && data.sset.guize !== ''" class="card">
        <text class="title sm">竞猜规则</text>
        <rich-text :nodes="data.sset.guize" />
      </view>
    </template>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 60rpx;
}
.card {
  margin: 0 24rpx 20rpx;
  background: #ffffff;
  border-radius: 20rpx;
  padding: 32rpx 24rpx;
}
.err-card {
  margin-top: 24rpx;
  text-align: center;
  padding: 80rpx 24rpx;
}
.err-text {
  font-size: 28rpx;
  color: #999999;
}
.match-card {
  margin: 24rpx;
  background: linear-gradient(160deg, #7b7de5, #9b9df0);
  border-radius: 20rpx;
  padding: 28rpx 24rpx;
}
.match-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.stage,
.match-time,
.match-status {
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.9);
}
.stage {
  font-weight: 600;
}
.match-body {
  display: flex;
  align-items: center;
  margin-top: 32rpx;
}
.team {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 0;
}
.team-logo {
  width: 96rpx;
  height: 96rpx;
}
.team-name {
  margin-top: 12rpx;
  font-size: 26rpx;
  color: #ffffff;
  text-align: center;
  max-width: 200rpx;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.vs {
  width: 180rpx;
  text-align: center;
  flex-shrink: 0;
}
.score {
  font-size: 44rpx;
  font-weight: 700;
  color: #ffffff;
}
.vs-text {
  font-size: 34rpx;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.7);
}
.status-tip {
  margin-top: 20rpx;
  text-align: center;
  font-size: 24rpx;
  color: #ffe8a3;
}
.tab-bar {
  display: flex;
  margin: 0 24rpx 20rpx;
  background: #ffffff;
  border-radius: 20rpx;
  padding: 8rpx;
}
.tab {
  flex: 1;
  height: 72rpx;
  border-radius: 16rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28rpx;
  color: #666666;
}
.tab.active {
  background: #7b7de5;
  color: #ffffff;
  font-weight: 600;
}
.chips {
  display: flex;
  flex-wrap: wrap;
}
.chip {
  padding: 18rpx 40rpx;
  margin: 0 20rpx 20rpx 0;
  border-radius: 40rpx;
  background: #f5f5f7;
  font-size: 28rpx;
  color: #333333;
}
.chip.active {
  background: #7b7de5;
  color: #ffffff;
  font-weight: 600;
}
.chip.locked {
  opacity: 0.6;
}
.input {
  height: 88rpx;
  background: #f5f5f7;
  border-radius: 44rpx;
  padding: 0 32rpx;
  font-size: 28rpx;
  color: #333333;
}
.submit-info {
  display: flex;
  align-items: center;
  padding: 20rpx 0 4rpx;
  font-size: 26rpx;
  color: #666666;
}
.st {
  margin-left: 20rpx;
  padding: 4rpx 20rpx;
  border-radius: 20rpx;
  font-size: 22rpx;
}
.st.win {
  background: #f0faf0;
  color: #52c41a;
}
.st.lose {
  background: #fff2f0;
  color: #ff4d4f;
}
.st.wait {
  background: #f5f5f7;
  color: #999999;
}
.add-score {
  margin-left: 20rpx;
  font-size: 24rpx;
  color: #ff8a3d;
  font-weight: 600;
}
.main-btn {
  margin-top: 24rpx;
  height: 88rpx;
  border-radius: 44rpx;
  background: linear-gradient(90deg, #9b9df0, #7b7de5);
  color: #ffffff;
  font-size: 30rpx;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
}
.main-btn.disabled {
  opacity: 0.6;
}
.title.sm {
  display: block;
  font-size: 30rpx;
  color: #333333;
  font-weight: 700;
  margin-bottom: 16rpx;
}
</style>
