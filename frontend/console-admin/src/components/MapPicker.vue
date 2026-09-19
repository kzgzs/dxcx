<script setup lang="ts">
/**
 * 地图选点弹窗（高德 / 腾讯双支持）
 * - 传入高德 key（mapKey）走高德 JS API；否则传入腾讯 key（mapQqKey）走腾讯地图 JS API v2；
 * - 点击地图或拖拽标记回填经纬度；两家 key 都未配置时由调用方降级为手动输入。
 * 逻辑口径与配送范围地图、平台系统设置选点保持一致。
 */
import { nextTick, ref, watch } from 'vue'

const props = defineProps<{
  visible: boolean
  /** 高德 JS API key */
  mapKey: string
  /** 高德安全密钥（jscode） */
  mapJscode: string
  /** 腾讯地图 key（map.qq.com） */
  mapQqKey?: string
  initLng: string
  initLat: string
}>()
const emit = defineEmits<{
  (e: 'update:visible', v: boolean): void
  (e: 'pick', lng: string, lat: string): void
}>()

const mapBoxRef = ref<HTMLDivElement>()
const mapLoading = ref(false)
const mapError = ref('')
const provider = ref<'' | 'amap' | 'qq'>('')
const curLng = ref('')
const curLat = ref('')

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let amapPromise: Promise<any> | null = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function loadAMap(key: string, jscode: string): Promise<any> {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const w = window as any
  if (w.AMap) return Promise.resolve(w.AMap)
  if (!amapPromise) {
    amapPromise = new Promise((resolve, reject) => {
      if (jscode) {
        w._AMapSecurityConfig = { securityJsCode: jscode }
      }
      const script = document.createElement('script')
      script.src = `https://webapi.amap.com/maps?v=1.4.0&key=${encodeURIComponent(key)}&plugin=AMap.Geocoder`
      script.onload = () => {
        if (w.AMap) {
          resolve(w.AMap)
        } else {
          amapPromise = null
          reject(new Error('高德地图加载失败'))
        }
      }
      script.onerror = () => {
        amapPromise = null
        reject(new Error('高德地图加载失败'))
      }
      document.head.appendChild(script)
    })
  }
  return amapPromise
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let qqPromise: Promise<any> | null = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function loadQQMap(key: string): Promise<any> {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const w = window as any
  if (w.qq && w.qq.maps) return Promise.resolve(w.qq.maps)
  if (!qqPromise) {
    qqPromise = new Promise((resolve, reject) => {
      const cbName = '__qqMapReadyCb'
      let done = false
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      w[cbName] = () => {
        done = true
        if (w.qq && w.qq.maps) resolve(w.qq.maps)
        else reject(new Error('腾讯地图加载失败'))
      }
      const script = document.createElement('script')
      script.src = `https://map.qq.com/api/js?v=2.exp&key=${encodeURIComponent(key)}&callback=${cbName}`
      script.onerror = () => {
        qqPromise = null
        reject(new Error('腾讯地图加载失败'))
      }
      setTimeout(() => {
        if (!done && w.qq && w.qq.maps) {
          // 个别版本不走 callback，做一次兜底
          resolve(w.qq.maps)
        }
      }, 4000)
      document.head.appendChild(script)
    })
  }
  return qqPromise
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let mapInst: any = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let marker: any = null

function destroyMap() {
  // 高德提供 destroy；腾讯无销毁 API，清空容器即可
  try {
    if (mapInst && typeof mapInst.destroy === 'function') {
      mapInst.destroy()
    }
  } catch {
    // 忽略销毁异常
  }
  if (provider.value === 'qq' && mapBoxRef.value) {
    mapBoxRef.value.innerHTML = ''
  }
  mapInst = null
  marker = null
}

function setCoord(lng: number, lat: number) {
  curLng.value = String(lng)
  curLat.value = String(lat)
  if (marker) {
    if (provider.value === 'amap') {
      marker.setPosition([lng, lat])
    } else if (marker.setPosition) {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const qq = (window as any).qq
      marker.setPosition(new qq.maps.LatLng(lat, lng))
    }
  }
}

async function initMap() {
  mapLoading.value = true
  mapError.value = ''
  // 判定地图厂商：优先高德，其次腾讯
  if (props.mapKey && props.mapKey.trim()) {
    provider.value = 'amap'
  } else if (props.mapQqKey && props.mapQqKey.trim()) {
    provider.value = 'qq'
  } else {
    provider.value = ''
    mapLoading.value = false
    mapError.value = '未配置地图 Key，请在平台「系统设置」填写高德或腾讯地图 Key，或直接手动输入经纬度'
    return
  }
  try {
    await nextTick()
    if (!mapBoxRef.value) return
    destroyMap()
    const lng = parseFloat(props.initLng)
    const lat = parseFloat(props.initLat)
    const hasInit = !isNaN(lng) && !isNaN(lat) && lng !== 0 && lat !== 0
    const dLng = hasInit ? lng : 116.397829
    const dLat = hasInit ? lat : 39.90358
    curLng.value = hasInit ? String(lng) : ''
    curLat.value = hasInit ? String(lat) : ''

    if (provider.value === 'amap') {
      const AMap = await loadAMap(props.mapKey, props.mapJscode)
      if (!props.visible) return
      await nextTick()
      if (!mapBoxRef.value) return
      mapInst = new AMap.Map(mapBoxRef.value, {
        resizeEnable: true,
        dragEnable: true,
        keyboardEnable: false,
        doubleClickZoom: false,
        scrollWheel: true,
        center: [dLng, dLat],
        zoom: 13,
      })
      marker = new AMap.Marker({ position: [dLng, dLat], draggable: true })
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      marker.on('dragend', () => {
        const p = marker.getPosition()
        curLng.value = String(p.lng)
        curLat.value = String(p.lat)
      })
      mapInst.add(marker)
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      mapInst.on('click', (e: any) => {
        setCoord(e.lnglat.getLng(), e.lnglat.getLat())
      })
    } else {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const qq = await loadQQMap(props.mapQqKey as string)
      if (!props.visible) return
      await nextTick()
      if (!mapBoxRef.value) return
      if (mapBoxRef.value) mapBoxRef.value.innerHTML = ''
      const center = new qq.maps.LatLng(dLat, dLng)
      mapInst = new qq.maps.Map(mapBoxRef.value, {
        center,
        zoom: 13,
        mapTypeControl: false,
      })
      marker = new qq.maps.Marker({ position: center, map: mapInst, draggable: true })
      qq.maps.event.addListener(mapInst, 'click', (e: { latLng: { getLng(): number; getLat(): number } }) => {
        setCoord(e.latLng.getLng(), e.latLng.getLat())
      })
      qq.maps.event.addListener(marker, 'dragend', () => {
        const p = marker.getPosition()
        curLng.value = String(p.getLng())
        curLat.value = String(p.getLat())
      })
    }
  } catch (e) {
    mapError.value =
      provider.value === 'amap'
        ? '高德地图加载失败，请检查平台「系统设置」中的高德地图 Key / 安全密钥是否正确'
        : '腾讯地图加载失败，请检查平台「系统设置」中的腾讯地图 Key 是否正确，也可直接手动输入经纬度'
  } finally {
    mapLoading.value = false
  }
}

watch(
  () => props.visible,
  (v) => {
    if (v) {
      void nextTick(() => initMap())
    } else {
      destroyMap()
    }
  }
)

function confirmPick() {
  if (curLng.value && curLat.value) {
    emit('pick', curLng.value, curLat.value)
  }
  emit('update:visible', false)
}
function close() {
  emit('update:visible', false)
}
</script>

<template>
  <el-dialog :model-value="visible" title="选择坐标" width="680px" append-to-body destroy-on-close @close="close">
    <div class="mp-tip">点击地图或拖拽标记选择坐标位置</div>
    <div v-loading="mapLoading" class="mp-box">
      <div ref="mapBoxRef" class="mp-map"></div>
      <div v-if="mapError" class="mp-error">{{ mapError }}</div>
    </div>
    <div class="mp-coord">
      <span class="mp-label">经度</span>
      <el-input v-model="curLng" placeholder="如：113.625368" clearable class="mp-input" />
      <span class="mp-label">纬度</span>
      <el-input v-model="curLat" placeholder="如：34.746611" clearable class="mp-input" />
    </div>
    <template #footer>
      <el-button @click="close">取消</el-button>
      <el-button type="primary" :disabled="!curLng || !curLat" @click="confirmPick">确定</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.mp-tip {
  margin-bottom: 10px;
  color: #909399;
  font-size: 12px;
}
.mp-box {
  position: relative;
}
.mp-map {
  width: 100%;
  height: 400px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}
.mp-error {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 24px;
  text-align: center;
  background: rgba(255, 255, 255, 0.9);
  color: #f56c6c;
  font-size: 13px;
}
.mp-coord {
  display: flex;
  align-items: center;
  margin-top: 12px;
}
.mp-label {
  margin: 0 8px;
  color: #606266;
}
.mp-label:first-child {
  margin-left: 0;
}
.mp-input {
  width: 180px;
}
</style>
