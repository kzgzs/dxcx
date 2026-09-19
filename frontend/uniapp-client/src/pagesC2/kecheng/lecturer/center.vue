<template>
	<view class="lc-page">
		<!-- 未入驻/审核中提示 -->
		<view v-if="errMsg !== ''" class="lc-err">
			<c2-empty :text="errMsg" />
			<view class="lc-err__btn" @click="goApply">前往申请</view>
		</view>

		<template v-else>
			<!-- 讲师信息卡 -->
			<view class="lc-card">
				<image class="lc-card__pic" :src="imgUrl(lecturer.headimg || lecturer.pic || '')" mode="aspectFill" />
				<view class="lc-card__info">
					<view class="lc-card__name">{{ lecturer.nickname || lecturer.realname || "讲师" }}</view>
					<view v-if="lecturer.shortdesc" class="lc-card__desc">{{ lecturer.shortdesc }}</view>
				</view>
				<view class="lc-card__edit" @click="goApply">编辑资料</view>
			</view>

			<!-- 课程分类 -->
			<view class="lc-tabs">
				<view class="lc-tabs__item" :class="{ 'lc-tabs__item--on': st === 0 }" @click="switchSt(0)">图文课程</view>
				<view class="lc-tabs__item" :class="{ 'lc-tabs__item--on': st === 1 }" @click="switchSt(1)">视频课程</view>
			</view>

			<view v-if="list.length === 0 && !loading" class="lc-empty">
				<c2-empty text="暂无课程" />
			</view>

			<view v-for="row in list" :key="row.id" class="lc-item" @click="goDetail(row)">
				<image class="lc-item__pic" :src="imgUrl(row.pic || '')" mode="aspectFill" />
				<view class="lc-item__info">
					<view class="lc-item__name">{{ row.name }}</view>
					<view class="lc-item__meta">
						<text>{{ row["showtime"] || "" }}</text>
						<text class="lc-item__num">{{ Number(row.join_num ?? 0) }}人学习</text>
					</view>
				</view>
			</view>

			<view v-if="loading" class="lc-tip">加载中…</view>
			<view v-else-if="finished && list.length > 0" class="lc-tip">没有更多了</view>
		</template>
	</view>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { onLoad, onReachBottom, onShow } from "@dcloudio/uni-app";
import c2Empty from "../../components/c2-empty.vue";
import { fetchLecturerCenter, type KcItem, type LecturerInfo } from "../../api/kecheng";
import { imgUrl } from "@/utils/format";
import { goPage } from "@/utils/navigate";

const st = ref(0);
const pagenum = ref(1);
const loading = ref(false);
const finished = ref(false);
const errMsg = ref("");
const lecturer = ref<LecturerInfo>({});
const list = ref<KcItem[]>([]);

async function loadData(reset = false) {
	if (loading.value || (finished.value && !reset)) return;
	if (reset) {
		pagenum.value = 1;
		finished.value = false;
	}
	loading.value = true;
	try {
		const res = await fetchLecturerCenter({ st: st.value, pagenum: pagenum.value });
		lecturer.value = res.lecturer ?? {};
		const rows = Array.isArray(res.data) ? res.data : [];
		list.value = reset ? rows : list.value.concat(rows);
		if (rows.length < 20) finished.value = true;
		else pagenum.value += 1;
		errMsg.value = "";
	} catch (err) {
		errMsg.value = (err as Error).message || "加载失败";
	} finally {
		loading.value = false;
	}
}

function switchSt(v: number) {
	if (st.value === v) return;
	st.value = v;
	list.value = [];
	void loadData(true);
}

function goApply() {
	goPage("/pagesC2/kecheng/lecturer/apply");
}

function goDetail(row: KcItem) {
	goPage(`/pagesC2/kecheng/detail?id=${row.id}`);
}

onLoad(() => {
	void loadData(true);
});

onShow(() => {
	// 从申请页返回后刷新资料
	if (lecturer.value.id !== undefined) {
		void loadData(true);
	}
});

onReachBottom(() => {
	void loadData();
});
</script>

<style scoped>
.lc-page {
	min-height: 100vh;
	background: #f6f6fa;
	padding: 24rpx;
	box-sizing: border-box;
}
.lc-err {
	padding-top: 160rpx;
}
.lc-err__btn {
	margin: 32rpx auto 0;
	width: 320rpx;
	height: 84rpx;
	line-height: 84rpx;
	text-align: center;
	border-radius: 42rpx;
	background: #7b7de5;
	color: #fff;
	font-size: 30rpx;
}
.lc-card {
	display: flex;
	align-items: center;
	background: linear-gradient(135deg, #7b7de5 0%, #9a9cf0 100%);
	border-radius: 20rpx;
	padding: 32rpx;
}
.lc-card__pic {
	width: 120rpx;
	height: 120rpx;
	border-radius: 50%;
	background: rgba(255, 255, 255, 0.3);
	flex-shrink: 0;
}
.lc-card__info {
	flex: 1;
	margin-left: 24rpx;
	overflow: hidden;
}
.lc-card__name {
	color: #fff;
	font-size: 32rpx;
	font-weight: bold;
}
.lc-card__desc {
	margin-top: 8rpx;
	color: rgba(255, 255, 255, 0.85);
	font-size: 24rpx;
	line-height: 1.5;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.lc-card__edit {
	color: #fff;
	font-size: 24rpx;
	border: 1rpx solid rgba(255, 255, 255, 0.7);
	border-radius: 28rpx;
	padding: 8rpx 24rpx;
	flex-shrink: 0;
}
.lc-tabs {
	display: flex;
	background: #fff;
	border-radius: 12rpx;
	margin: 24rpx 0;
	padding: 8rpx;
}
.lc-tabs__item {
	flex: 1;
	text-align: center;
	height: 68rpx;
	line-height: 68rpx;
	color: #666;
	font-size: 28rpx;
	border-radius: 8rpx;
}
.lc-tabs__item--on {
	background: #7b7de5;
	color: #fff;
}
.lc-empty {
	padding-top: 80rpx;
}
.lc-item {
	display: flex;
	background: #fff;
	border-radius: 16rpx;
	padding: 24rpx;
	margin-bottom: 20rpx;
}
.lc-item__pic {
	width: 160rpx;
	height: 120rpx;
	border-radius: 12rpx;
	background: #f2f2f8;
	flex-shrink: 0;
}
.lc-item__info {
	flex: 1;
	margin-left: 24rpx;
	display: flex;
	flex-direction: column;
	justify-content: space-between;
	overflow: hidden;
}
.lc-item__name {
	color: #333;
	font-size: 30rpx;
	font-weight: bold;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.lc-item__meta {
	display: flex;
	align-items: center;
	justify-content: space-between;
	color: #999;
	font-size: 24rpx;
}
.lc-tip {
	text-align: center;
	color: #999;
	font-size: 24rpx;
	padding: 24rpx 0;
}
</style>
