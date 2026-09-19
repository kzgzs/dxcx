<template>
	<view class="cp-page">
		<view v-if="loaded" class="cp-card">
			<view class="cp-card__score" :class="ishg ? 'cp-card__score--ok' : 'cp-card__score--no'">{{ data.score }}</view>
			<view class="cp-card__tag" :class="ishg ? 'cp-card__tag--ok' : 'cp-card__tag--no'">
				{{ ishg ? "恭喜合格" : "未合格，继续加油" }}
			</view>
			<view class="cp-grid">
				<view class="cp-grid__item">
					<view class="cp-grid__val cp-grid__val--ok">{{ data.rightnum ?? 0 }}</view>
					<view class="cp-grid__lab">答对题数</view>
				</view>
				<view class="cp-grid__item">
					<view class="cp-grid__val cp-grid__val--no">{{ data.errornum ?? 0 }}</view>
					<view class="cp-grid__lab">答错题数</view>
				</view>
				<view class="cp-grid__item">
					<view class="cp-grid__val">{{ data.longtime }}</view>
					<view class="cp-grid__lab">考试用时</view>
				</view>
			</view>
			<view class="cp-time">
				<view v-if="data.time">开始时间：{{ data.time }}</view>
				<view v-if="data.endtime">交卷时间：{{ data.endtime }}</view>
			</view>
		</view>

		<view class="cp-foot">
			<view v-if="(data.errornum ?? 0) > 0" class="cp-foot__btn cp-foot__btn--plain" @click="goError">查看错题</view>
			<view class="cp-foot__btn" @click="goRecord">学习记录</view>
		</view>
	</view>
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchExamComplete, type TkComplete } from "../api/kecheng";
import { goPage } from "@/utils/navigate";

const loaded = ref(false);
const data = ref<TkComplete>({ id: 0 });

const ishg = computed(() => Number(data.value.ishg ?? 0) === 1);

onLoad((opts) => {
	const rid = Number(opts?.rid ?? 0);
	if (rid <= 0) {
		uni.showToast({ title: "参数错误", icon: "none" });
		return;
	}
	void fetchExamComplete(rid)
		.then((res) => {
			data.value = res;
			loaded.value = true;
		})
		.catch((err: Error) => {
			uni.showToast({ title: err.message || "获取成绩失败", icon: "none" });
		});
});

function goError() {
	goPage(`/pagesC2/kecheng/error?rid=${data.value.id}`);
}

function goRecord() {
	const kc = Number(data.value.kcid ?? 0);
	const ml = Number(data.value.mlid ?? 0);
	if (kc > 0) {
		goPage(`/pagesC2/kecheng/recordlog?kcid=${kc}&mlid=${ml}`);
	} else {
		uni.navigateBack();
	}
}
</script>

<style scoped>
.cp-page {
	min-height: 100vh;
	background: linear-gradient(180deg, #7b7de5 0%, #f6f6fa 40%);
	padding: 48rpx 32rpx;
	box-sizing: border-box;
}
.cp-card {
	background: #fff;
	border-radius: 20rpx;
	padding: 64rpx 32rpx 40rpx;
	text-align: center;
}
.cp-card__score {
	font-size: 100rpx;
	font-weight: bold;
	line-height: 1.2;
}
.cp-card__score--ok {
	color: #7b7de5;
}
.cp-card__score--no {
	color: #fa5151;
}
.cp-card__tag {
	display: inline-block;
	margin-top: 16rpx;
	padding: 8rpx 32rpx;
	border-radius: 32rpx;
	font-size: 26rpx;
}
.cp-card__tag--ok {
	color: #7b7de5;
	background: #f0f0fc;
}
.cp-card__tag--no {
	color: #fa5151;
	background: #fef0f0;
}
.cp-grid {
	display: flex;
	margin-top: 48rpx;
	padding-top: 32rpx;
	border-top: 1rpx solid #f2f2f8;
}
.cp-grid__item {
	flex: 1;
	text-align: center;
}
.cp-grid__val {
	color: #333;
	font-size: 40rpx;
	font-weight: bold;
}
.cp-grid__val--ok {
	color: #07c160;
}
.cp-grid__val--no {
	color: #fa5151;
}
.cp-grid__lab {
	color: #999;
	font-size: 24rpx;
	margin-top: 8rpx;
}
.cp-time {
	margin-top: 40rpx;
	color: #999;
	font-size: 24rpx;
	line-height: 1.8;
}
.cp-foot {
	display: flex;
	gap: 24rpx;
	margin-top: 48rpx;
}
.cp-foot__btn {
	flex: 1;
	height: 88rpx;
	line-height: 88rpx;
	text-align: center;
	border-radius: 44rpx;
	background: #7b7de5;
	color: #fff;
	font-size: 30rpx;
}
.cp-foot__btn--plain {
	background: #fff;
	color: #7b7de5;
	border: 1rpx solid #7b7de5;
}
</style>
