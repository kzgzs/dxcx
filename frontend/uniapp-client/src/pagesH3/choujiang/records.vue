<script setup lang="ts">
/**
 * 中奖记录页
 * - choujiang/records 分页列表（status 0未领奖 1已领奖）
 * - 未领奖记录点击"领奖"调 choujiang/claim 生成核销码
 */
import { ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { fetchLotteryRecords, lotteryClaim } from "../api";
import type { LotteryRecord } from "../api";
import { fmtTime, imgUrl } from "../../utils/format";
import H3Empty from "../components/h3-empty.vue";

const hid = ref(1);
const list = ref<LotteryRecord[]>([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const finished = ref(false);
/** 核销码弹窗 */
const claimCode = ref("");

onLoad((options) => {
  hid.value = Number(options?.hid || 1);
  void loadList(true);
});

onReachBottom(() => {
  if (!finished.value && !loading.value) {
    void loadList(false);
  }
});

async function loadList(reset: boolean) {
  if (loading.value) return;
  loading.value = true;
  if (reset) {
    page.value = 1;
    finished.value = false;
    list.value = [];
  }
  try {
    const res = await fetchLotteryRecords(hid.value, page.value, 20);
    total.value = res.total;
    list.value = list.value.concat(res.list);
    if (list.value.length >= res.total || res.list.length === 0) {
      finished.value = true;
    } else {
      page.value += 1;
    }
  } catch {
    finished.value = true;
  } finally {
    loading.value = false;
  }
}

async function doClaim(item: LotteryRecord) {
  if (item.code !== "") {
    claimCode.value = item.code;
    return;
  }
  try {
    const res = await lotteryClaim(item.id);
    item.code = res.code;
    item.status = 1;
    claimCode.value = res.code;
  } catch {
    /* request.ts 已 toast 业务错误 */
  }
}

function closeClaim() {
  claimCode.value = "";
}
</script>

<template>
  <view class="page">
    <view v-if="list.length === 0 && finished" class="empty-wrap">
      <H3Empty text="暂无中奖记录" />
    </view>

    <view v-for="item in list" :key="item.id" class="card">
      <view class="row">
        <view class="prize-icon">
          <image v-if="item.jxtp > 0" class="pic" :src="imgUrl(String(item.jxtp))" mode="aspectFill" />
          <text v-else class="pic-fallback">奖</text>
        </view>
        <view class="info">
          <text class="name">{{ item.jxmc }}</text>
          <text class="time">{{ fmtTime(item.createtime) }}</text>
        </view>
        <view v-if="item.status === 1" class="tag done"><text>已领奖</text></view>
        <view v-else class="claim-btn" @click="doClaim(item)"><text>领奖</text></view>
      </view>
      <view v-if="item.code !== ''" class="code-row">
        <text class="code-label">核销码：</text>
        <text class="code-val">{{ item.code }}</text>
      </view>
      <view v-if="item.remark !== ''" class="remark"><text>{{ item.remark }}</text></view>
    </view>

    <view v-if="list.length > 0" class="foot-tip">
      <text>{{ finished ? "没有更多了" : "加载中..." }}</text>
    </view>

    <!-- 核销码弹窗 -->
    <view v-if="claimCode !== ''" class="mask" @click="closeClaim">
      <view class="modal" @click.stop>
        <text class="modal-title">领奖核销码</text>
        <text class="modal-code">{{ claimCode }}</text>
        <text class="modal-tip">请凭此核销码联系商家领取奖品</text>
        <view class="modal-btn" @click="closeClaim"><text>我知道了</text></view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx 24rpx 60rpx;
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
.row {
  display: flex;
  align-items: center;
}
.prize-icon {
  width: 88rpx;
  height: 88rpx;
  border-radius: 16rpx;
  background: #f5f5f7;
  overflow: hidden;
  flex-shrink: 0;
}
.pic {
  width: 100%;
  height: 100%;
}
.pic-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ff8a3d;
  font-size: 36rpx;
  font-weight: 700;
}
.info {
  flex: 1;
  margin-left: 20rpx;
  min-width: 0;
}
.name {
  display: block;
  font-size: 30rpx;
  color: #333333;
  font-weight: 600;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.time {
  display: block;
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #999999;
}
.tag.done {
  padding: 10rpx 24rpx;
  border-radius: 32rpx;
  background: #f5f5f7;
  color: #999999;
  font-size: 24rpx;
}
.claim-btn {
  padding: 12rpx 36rpx;
  border-radius: 32rpx;
  background: linear-gradient(90deg, #ffab66, #ff8a3d);
  color: #ffffff;
  font-size: 26rpx;
  font-weight: 600;
}
.code-row {
  margin-top: 20rpx;
  padding-top: 20rpx;
  border-top: 1rpx dashed #eeeeee;
  font-size: 26rpx;
}
.code-label {
  color: #999999;
}
.code-val {
  color: #ff4d4f;
  font-weight: 700;
  letter-spacing: 2rpx;
}
.remark {
  margin-top: 12rpx;
  font-size: 24rpx;
  color: #999999;
}
.foot-tip {
  text-align: center;
  font-size: 24rpx;
  color: #bbbbbb;
  padding: 20rpx 0;
}
.mask {
  position: fixed;
  left: 0;
  top: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 99;
}
.modal {
  width: 560rpx;
  background: #ffffff;
  border-radius: 24rpx;
  padding: 48rpx 40rpx;
  text-align: center;
}
.modal-title {
  display: block;
  font-size: 32rpx;
  font-weight: 700;
  color: #333333;
}
.modal-code {
  display: block;
  margin: 32rpx 0 16rpx;
  font-size: 48rpx;
  font-weight: 700;
  color: #ff4d4f;
  letter-spacing: 4rpx;
}
.modal-tip {
  display: block;
  font-size: 24rpx;
  color: #999999;
}
.modal-btn {
  margin-top: 36rpx;
  height: 80rpx;
  border-radius: 40rpx;
  background: linear-gradient(90deg, #9b9df0, #7b7de5);
  color: #ffffff;
  font-size: 28rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
