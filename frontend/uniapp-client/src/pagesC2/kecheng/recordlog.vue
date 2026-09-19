<template>
	<view class="rl-page">
		<view v-if="list.length === 0 && !loading" class="rl-empty">
			<c2-empty text="暂无学习记录" />
		</view>

		<view v-for="row in list" :key="row.id" class="rl-card" @click="goError(row)">
			<view class="rl-card__head">
				<view class="rl-card__title">{{ row.title || "课程考试" }}</view>
				<view class="rl-card__score" :class="Number(row.ishg) === 1 ? 'rl-card__score--ok' : 'rl-card__score--no'">
					{{ row.score }}分
				</view>
			</view>
			<view class="rl-card__foot">
				<view class="rl-card__date">{{ row.date }}</view>
				<view class="rl-card__tag" :class="Number(row.ishg) === 1 ? 'rl-card__tag--ok' : 'rl-card__tag--no'">
					{{ Number(row.ishg) === 1 ? "合格" : "不合格" }}
				</view>
			</view>
		</view>

		<view v-if="loading" class="rl-tip">加载中…</view>
		<view v-else-if="finished && list.length > 0" class="rl-tip">没有更多了</view>
	</view>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import c2Empty from "../components/c2-empty.vue";
import { fetchExamRecordLog, type TkRecord } from "../api/kecheng";
import { goPage } from "@/utils/navigate";

const kcid = ref(0);
const mlid = ref(0);
const pagenum = ref(1);
const loading = ref(false);
const finished = ref(false);
const list = ref<TkRecord[]>([]);

async function loadData(reset = false) {
	if (loading.value || (finished.value && !reset)) return;
	if (reset) {
		pagenum.value = 1;
		finished.value = false;
	}
	loading.value = true;
	try {
		const res = await fetchExamRecordLog({ kcid: kcid.value, mlid: mlid.value, pagenum: pagenum.value });
		const rows = Array.isArray(res.datalist) ? res.datalist : [];
		list.value = reset ? rows : list.value.concat(rows);
		if (rows.length < 10) finished.value = true;
		else pagenum.value += 1;
	} catch (err) {
		uni.showToast({ title: (err as Error).message || "加载失败", icon: "none" });
	} finally {
		loading.value = false;
	}
}

function goError(row: TkRecord) {
	const rid = Number(row.rid ?? row.id ?? 0);
	if (rid > 0) {
		goPage(`/pagesC2/kecheng/error?rid=${rid}`);
	}
}

onLoad((opts) => {
	kcid.value = Number(opts?.kcid ?? 0);
	mlid.value = Number(opts?.mlid ?? 0);
	if (kcid.value <= 0) {
		uni.showToast({ title: "参数错误", icon: "none" });
		return;
	}
	void loadData(true);
});

onReachBottom(() => {
	void loadData();
});
</script>

<style scoped>
.rl-page {
	min-height: 100vh;
	background: #f6f6fa;
	padding: 24rpx;
	box-sizing: border-box;
}
.rl-empty {
	padding-top: 200rpx;
}
.rl-card {
	background: #fff;
	border-radius: 16rpx;
	padding: 28rpx 32rpx;
	margin-bottom: 20rpx;
}
.rl-card__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
}
.rl-card__title {
	flex: 1;
	color: #333;
	font-size: 30rpx;
	font-weight: bold;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	margin-right: 16rpx;
}
.rl-card__score {
	font-size: 36rpx;
	font-weight: bold;
	flex-shrink: 0;
}
.rl-card__score--ok {
	color: #7b7de5;
}
.rl-card__score--no {
	color: #fa5151;
}
.rl-card__foot {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-top: 16rpx;
}
.rl-card__date {
	color: #999;
	font-size: 24rpx;
}
.rl-card__tag {
	font-size: 22rpx;
	padding: 4rpx 16rpx;
	border-radius: 20rpx;
}
.rl-card__tag--ok {
	color: #7b7de5;
	background: #f0f0fc;
}
.rl-card__tag--no {
	color: #fa5151;
	background: #fef0f0;
}
.rl-tip {
	text-align: center;
	color: #999;
	font-size: 24rpx;
	padding: 24rpx 0;
}
</style>
