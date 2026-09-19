<template>
	<view class="lt-page">
		<view class="lt-head">
			<view class="lt-head__title">金牌讲师</view>
			<view class="lt-head__link" @click="goApply">申请入驻 ›</view>
		</view>

		<view v-if="list.length === 0 && !loading" class="lt-empty">
			<c2-empty text="暂无讲师" />
		</view>

		<view class="lt-grid">
			<view v-for="item in list" :key="Number(item.id)" class="lt-card">
				<image class="lt-card__pic" :src="imgUrl(item.headimg || item.pic || '')" mode="aspectFill" />
				<view class="lt-card__name">{{ item.nickname || item.realname || "讲师" }}</view>
				<view v-if="item.shortdesc" class="lt-card__desc">{{ item.shortdesc }}</view>
			</view>
		</view>

		<view v-if="loading" class="lt-tip">加载中…</view>
		<view v-else-if="finished && list.length > 0" class="lt-tip">没有更多了</view>
	</view>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import c2Empty from "../../components/c2-empty.vue";
import { fetchLecturerList, type LecturerInfo } from "../../api/kecheng";
import { imgUrl } from "@/utils/format";
import { goPage } from "@/utils/navigate";

const pagenum = ref(1);
const loading = ref(false);
const finished = ref(false);
const list = ref<LecturerInfo[]>([]);

async function loadData(reset = false) {
	if (loading.value || (finished.value && !reset)) return;
	if (reset) {
		pagenum.value = 1;
		finished.value = false;
	}
	loading.value = true;
	try {
		const res = await fetchLecturerList(pagenum.value);
		const rows = Array.isArray(res.data) ? res.data : [];
		list.value = reset ? rows : list.value.concat(rows);
		if (rows.length < 6) finished.value = true;
		else pagenum.value += 1;
	} catch (err) {
		uni.showToast({ title: (err as Error).message || "加载失败", icon: "none" });
	} finally {
		loading.value = false;
	}
}

function goApply() {
	goPage("/pagesC2/kecheng/lecturer/apply");
}

onLoad(() => {
	void loadData(true);
});

onReachBottom(() => {
	void loadData();
});
</script>

<style scoped>
.lt-page {
	min-height: 100vh;
	background: #f6f6fa;
	padding: 24rpx;
	box-sizing: border-box;
}
.lt-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8rpx 8rpx 24rpx;
}
.lt-head__title {
	color: #333;
	font-size: 34rpx;
	font-weight: bold;
}
.lt-head__link {
	color: #7b7de5;
	font-size: 26rpx;
}
.lt-empty {
	padding-top: 160rpx;
}
.lt-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 20rpx;
}
.lt-card {
	width: calc(50% - 10rpx);
	background: #fff;
	border-radius: 16rpx;
	padding: 32rpx 24rpx;
	text-align: center;
	box-sizing: border-box;
}
.lt-card__pic {
	width: 140rpx;
	height: 140rpx;
	border-radius: 50%;
	background: #f2f2f8;
}
.lt-card__name {
	margin-top: 16rpx;
	color: #333;
	font-size: 30rpx;
	font-weight: bold;
}
.lt-card__desc {
	margin-top: 8rpx;
	color: #999;
	font-size: 24rpx;
	line-height: 1.5;
	display: -webkit-box;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
	overflow: hidden;
}
.lt-tip {
	text-align: center;
	color: #999;
	font-size: 24rpx;
	padding: 24rpx 0;
}
</style>
