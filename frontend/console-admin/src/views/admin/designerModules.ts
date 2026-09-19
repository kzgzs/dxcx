/**
 * 页面设计器 - 组件库定义（共 21 个可添加组件 + 固定 topbar，任务245 P27-1 扩容版）
 * 组件统一结构 {id:'M'+时间戳, temp, params, data}
 * params 通用字段：margin_x/margin_y/padding_x/padding_y/bgcolor
 * 通用可见性字段（任务245）：platform（端可见，对象勾选口径 {all:true,...}）、quanxian（会员等级可见）
 */
import type { DesignerModule } from '../../api/designer'

export interface ModuleMeta {
  /** 组件类型标识（temp） */
  temp: string
  /** 组件中文名 */
  name: string
  /** 左侧面板图标（emoji 简版） */
  icon: string
  /** 组件说明 */
  desc: string
  /** 左面板三分类（任务299）：basic 基础 / goods 商品 / other 其他 */
  category: ModuleCategory
}

/** 左面板组件分类键（任务299） */
export type ModuleCategory = 'basic' | 'goods' | 'other'

/** 分类展示顺序与类头名称（数量动态计算） */
export const MODULE_CATEGORY_ORDER: ModuleCategory[] = ['basic', 'goods', 'other']
export const MODULE_CATEGORY_LABELS: Record<ModuleCategory, string> = {
  basic: '基础组件',
  goods: '商品组件',
  other: '其他组件',
}

/** 组件库清单（左面板展示顺序） */
export const MODULE_MENUS: ModuleMeta[] = [
  { temp: 'banner', name: '轮播图', icon: '🖼', desc: '多图横向轮播', category: 'basic' },
  { temp: 'search', name: '搜索框', icon: '🔍', desc: '关键字搜索入口', category: 'basic' },
  { temp: 'notice', name: '公告', icon: '📢', desc: '滚动公告条', category: 'basic' },
  { temp: 'menu', name: '宫格导航', icon: '🧩', desc: '图标网格导航', category: 'basic' },
  { temp: 'picture', name: '单图', icon: '🌄', desc: '单张图片', category: 'basic' },
  { temp: 'pictures', name: '多图', icon: '🎞', desc: '多张并排图片', category: 'basic' },
  { temp: 'title', name: '标题', icon: '🏷', desc: '栏目标题条', category: 'basic' },
  { temp: 'text', name: '文本', icon: '📝', desc: '自定义文本', category: 'basic' },
  { temp: 'richtext', name: '富文本', icon: '📄', desc: '富文本内容', category: 'basic' },
  { temp: 'blank', name: '辅助空白', icon: '⬜', desc: '占位空白区', category: 'basic' },
  { temp: 'line', name: '辅助线', icon: '➖', desc: '分隔线', category: 'basic' },
  { temp: 'product', name: '商城商品', icon: '🛒', desc: '商城商品列表', category: 'goods' },
  { temp: 'scoreshop', name: '积分兑换', icon: '💎', desc: '积分兑换商品列表', category: 'goods' },
  { temp: 'coupon', name: '优惠券', icon: '🎟', desc: '优惠券领取列表', category: 'goods' },
  // 任务250（P27-6）：选项卡组件（每个选项标题+子内容，子内容经 designerpage_tab 独立存储）
  { temp: 'tab', name: '选项卡', icon: '📑', desc: '多选项切换内容区', category: 'basic' },
  // 任务245（P27-1）：组件库扩容 7 个
  { temp: 'video', name: '视频', icon: '🎬', desc: '视频播放组件', category: 'basic' },
  { temp: 'button', name: '按钮', icon: '🔘', desc: '自定义按钮', category: 'basic' },
  { temp: 'dhlist', name: '列表导航', icon: '📋', desc: '标题+备注列表导航', category: 'basic' },
  { temp: 'cover', name: '悬浮按钮', icon: '🎈', desc: '悬浮定位按钮', category: 'basic' },
  { temp: 'map', name: '地图', icon: '🗺', desc: '地图位置展示', category: 'other' },
  { temp: 'hotspot', name: '热区', icon: '🎯', desc: '图片热区链接', category: 'basic' },
  { temp: 'cube', name: '图片魔方', icon: '🧊', desc: '4×4自由图片布局', category: 'basic' },
  // 任务302：基础组件补店招/精选
  { temp: 'shop', name: '店招', icon: '🏪', desc: '店铺招牌背景+导航按钮', category: 'basic' },
  { temp: 'featured', name: '精选', icon: '✨', desc: '左轮播+右双图图片栏', category: 'basic' },
  // 任务320：订单播报组件化（页面流内渲染，取代旧全局 fixed 挂载）
  { temp: 'orderbroadcast', name: '订单播报', icon: '📣', desc: '竖向滚动展示最近购买记录', category: 'other' },
]

/** 图片魔方图位（任务300）：x/y 锚点格（0-3），w/h 跨格数，设计宽640口径（单格160） */
export interface CubeSlot {
  x: number
  y: number
  w: number
  h: number
  imgurl: string
  hrefurl: string
  hrefname?: string
}

/** 组件 temp → 中文名 */
export const MODULE_NAMES: Record<string, string> = Object.fromEntries(
  MODULE_MENUS.map((m) => [m.temp, m.name]),
)

/** 生成组件唯一 id：'M' + 时间戳 + 随机数 */
export function genMid(): string {
  return 'M' + Date.now() + Math.floor(Math.random() * 1000000)
}

/** 端可见可选项（任务245 通用参数 platform） */
export const PLATFORM_OPTIONS = [
  { value: 'mp', label: '公众号' },
  { value: 'wxapp', label: '微信小程序' },
  { value: 'h5', label: 'H5' },
  { value: 'app', label: 'APP' },
  { value: 'aliapp', label: '支付宝小程序' },
  { value: 'ttapp', label: '抖音小程序' },
  { value: 'bdapp', label: '百度小程序' },
  { value: 'qqapp', label: 'QQ小程序' },
]

/** 会员等级可见可选项（任务245 通用参数 quanxian，按等级 id 勾选） */
export const MEMBER_LEVEL_OPTIONS = [
  { value: '1', label: '普通会员' },
  { value: '2', label: '白银会员' },
  { value: '3', label: '黄金会员' },
  { value: '4', label: '铂金会员' },
  { value: '5', label: '钻石会员' },
]

/** 通用可见性默认值：所有端 + 所有人（对象勾选口径，与老 topbar.quanxian 一致） */
export function commonVisibleParams(): Record<string, any> {
  return { platform: { all: true }, quanxian: { all: true } }
}


/** 默认 topbar（pageinfo 固定首项；任务305 页面信息设置全量字段） */
export function defaultTopbar(title = '默认标题'): DesignerModule {
  return {
    id: 'M0000000000000',
    temp: 'topbar',
    params: {
      title,
      // 背景颜色：bgmode 0纯色/1渐变；bgcolor 纯色/渐变色1；bgcolor2 渐变色2；bgdir 0从上到下/1从左到右（任务307）
      bgmode: '0',
      bgcolor: '#F5F5F5',
      bgcolor2: '#FFFFFF',
      bgdir: '0',
      // 导航背景颜色：navbgmode 0跟随系统/1自定义；navbgcolor 自定义色
      navbgmode: '0',
      navbgcolor: '#FFFFFF',
      // 导航文字颜色：navtextmode 0跟随系统/1白色/2黑色
      navtextmode: '0',
      // 强制登录：0关闭/1开启
      needlogin: '0',
      // 查看权限：{all:true} 所有人 / member 普通会员 / dealer 经销商 复选
      viewperm: { all: true },
      // 付费查看：payview 0否/1是；payviewprice 金额（元）
      payview: '0',
      payviewprice: '',
      quanxian: { all: true },
      // 开屏广告：showgg 0关闭/1图片；gglist 广告图片链接列表 {image,url}
      // 任务306：ggcrowd 展示人群复选（all/followed/unfollowed/member/dealer）；ggmode 0首次/1每次
      showgg: '0',
      guanggao: '',
      gglist: [],
      ggcrowd: { all: true },
      ggmode: '0',
    },
  }
}

/** 各组件添加时的默认 params/data（对齐参考系统结构，表述自有） */
export function defaultModule(temp: string): DesignerModule {
  const id = genMid()
  const vis = commonVisibleParams()
  switch (temp) {
    case 'banner':
      return {
        id, temp,
        // 任务325 对照点大截图重做：margin_y与上方距离/indicatordots显隐/dotshape0默认1长方形2圆形3正方形/dotposition/dotbottom/dotcolor/dotactivecolor/height/borderradius/interval秒/bgcolor
        params: { indicatordots: '1', dotshape: '0', dotposition: 'center', dotbottom: '8', dotcolor: '#EDEEF0', dotactivecolor: '#3DB51E', height: '200', borderradius: '0', interval: 5, bgcolor: '#FFFFFF', margin_x: '0', margin_y: '0', padding_x: '0', padding_y: '0', ...vis },
        data: [
          { imgurl: '', hrefurl: '', hrefname: '' },
          { imgurl: '', hrefurl: '', hrefname: '' },
        ],
      }
    case 'search':
      return {
        id, temp,
        params: { placeholder: '输入关键字搜索您感兴趣的商品', color: '#666666', bgcolor: '#F6F6F6', borderradius: 5, margin_x: '0', margin_y: '0', padding_x: '6', padding_y: '6', ...vis },
        data: [],
      }
    case 'notice':
      return {
        id, temp,
        params: { showimg: '0', img: '', showicon: '1', icon: '', color: '#666666', bgcolor: '#FFFFFF', scroll: '3', fontsize: '14', margin_x: '0', margin_y: '0', padding_x: '5', padding_y: '7', ...vis },
        data: [
          { title: '这里是第一条公告的标题', hrefurl: '' },
          { title: '这里是第二条公告的标题', hrefurl: '' },
        ],
      }
    case 'menu':
      return {
        id, temp,
        params: { num: '5', pernum: '5', fontsize: '14', iconsize: '40', showicon: '1', showtitle: '1', bgcolor: '#FFFFFF', margin_x: '0', margin_y: '0', padding_x: '10', padding_y: '5', ...vis },
        data: [
          { imgurl: '', title: '菜单一', hrefurl: '' },
          { imgurl: '', title: '菜单二', hrefurl: '' },
          { imgurl: '', title: '菜单三', hrefurl: '' },
          { imgurl: '', title: '菜单四', hrefurl: '' },
          { imgurl: '', title: '菜单五', hrefurl: '' },
        ],
      }
    case 'picture':
      return {
        id, temp,
        // 任务326 对照点大截图重做：margin_y与上方距离/bgcolor/borderradius/margin_x/padding_x/padding_y，data 多图纵向堆叠
        params: { bgcolor: '#FFFFFF', borderradius: '0', margin_x: '0', margin_y: '0', padding_x: '0', padding_y: '0', ...vis },
        data: [{ imgurl: '', hrefurl: '', hrefname: '' }],
      }
    case 'pictures':
      return {
        id, temp,
        // 任务327 对照点大截图重做：layout 2/3/4/showcase、bgcolor、margin_y/margin_x/padding_x/padding_y；clicktype link跳转/preview预览
        params: { layout: '2', imageRadius: '5', bgcolor: '#FFFFFF', margin_x: '0', margin_y: '0', padding_x: '0', padding_y: '0', ...vis },
        data: [
          { imgurl: '', hrefurl: '', hrefname: '', clicktype: 'link' },
          { imgurl: '', hrefurl: '', hrefname: '', clicktype: 'link' },
          { imgurl: '', hrefurl: '', hrefname: '', clicktype: 'link' },
          { imgurl: '', hrefurl: '', hrefname: '', clicktype: 'link' },
        ],
      }
    case 'title':
      return {
        id, temp,
        // 任务328 对照点大截图重做：title/fontsize/color/showicon/imgurl/iconsize/linecolor(左右装饰横线)/bgcolor/margin_x/margin_y/padding_x/padding_y
        params: { title: '猜你喜欢', fontsize: '14', color: '#333', showicon: '1', imgurl: '', iconsize: '14', linecolor: '#DDDDDD', bgcolor: '#F6F6F6', margin_x: '0', margin_y: '0', padding_x: '30', padding_y: '5', ...vis },
        data: [],
      }
    case 'text':
      return {
        id, temp,
        params: { content: '文本内容', fontsize: '14', lineheight: '20', letterspacing: '0', isbold: '0', color: '#000', align: 'left', bgcolor: '#FFFFFF', bgimg: '', hrefurl: '', hrefname: '', margin_x: '0', margin_y: '0', padding_x: '5', padding_y: '5', ...vis },
        data: [],
      }
    case 'richtext':
      return {
        id, temp,
        params: { bgcolor: '#FFFFFF', margin_x: '0', margin_y: '0', padding_x: '10', padding_y: '10', ...vis },
        content: '',
        data: [],
      }
    case 'blank':
      return {
        id, temp,
        params: { height: '50', bgcolor: '#F6F6F6', margin_x: '0', margin_y: '0', ...vis },
        data: [],
      }
    case 'line':
      return {
        id, temp,
        params: { height: '2', style: 'dashed', color: '#666666', bgcolor: '#F6F6F6', margin_x: '0', margin_y: '0', padding_x: '10', padding_y: '10', ...vis },
        data: [],
      }
    // 任务 302：商城商品（对照点大三张截图：标题样式/商品样式/商品设置/组件样式 全量属性，默认值同图）
    case 'product':
      return {
        id, temp,
        params: {
          // 顶部：与上方距离 + 样式选择（默认双排）
          margin_y: '0', style: '2',
          // 标题样式
          titleshow: '1', titletext: '商城商品', titlefontsize: '14', titlecolor: '#000000',
          notetext: '查看更多', notefontsize: '12', notecolor: '#999999',
          arrowshow: '1', hrefurl: '', hrefname: '',
          padding_x: '8', padding_y: '8',
          // 商品样式
          nameshow: '1', namefontsize: '16', namecolor: '#000000', namebold: '0',
          priceshow: '1', pricecolormode: '0', pricecolor: '#FF4400', pricebold: '0',
          salesshow: '1', salescolor: '#999999', salesbold: '0',
          imgradius: '0', pro_margin_x: '0', pro_margin_y: '0',
          buybtnshow: '1', buybtnimg: '',
          // 商品设置
          probadge: '0', badgeimg: '',
          prosource: '1', merchant: 'self', category: 'all',
          groupall: '1', nogroup: '0', groups: [] as string[],
          sortway: '0', proshownum: '6', pageload: '0', proradius: '5',
          // 组件样式
          bgmode: '0', bgcolor: '#FFFFFF', bgimg: '', borderradius: '0',
          margin_x: '0',
          ...vis,
        },
        data: [],
      }
    // 任务#7：积分兑换商品（双排布局，参照 pointshop 实现）
    case 'scoreshop':
      return {
        id, temp,
        params: {
          // 顶部：与上方距离 + 样式选择（默认双排）
          margin_y: '0', style: '2',
          // 标题样式
          titleshow: '1', titletext: '积分兑换', titlefontsize: '14', titlecolor: '#000000',
          notetext: '查看更多', notefontsize: '12', notecolor: '#999999',
          arrowshow: '1', hrefurl: '', hrefname: '',
          padding_x: '8', padding_y: '8',
          // 商品样式
          productnameshow: '1', productnamefontsize: '16', productnamecolor: '#000000', productnamebold: 'normal',
          priceshow: '1', pricecolortype: '1', pricecolor: '#000000', pricecustomcolor: '#FF4400', pricebold: 'normal',
          salesshow: '1', salecolor: '#999999', salebold: 'normal',
          imgradius: '0', margin_x: '0', margin_y: '0',
          // 商品设置
          goodstags: '', tagimgurl: '',
          source: '2', category: 'all', sortway: 'default', displaycount: '6', blockborder: '5',
          // 组件样式
          backgroundtype: '1', bgcolor: '#FFFFFF', roundness: '0',
          sidemargin: '0', padding_x: '8', padding_y: '8',
          ...vis,
        },
        data: [],
      }
    // 任务250（P27-6）：选项卡（口径对照参考系统 dp-tab：选项项 {id,name}，子内容按 tabid+tabindexid 存取）
    // 任务332：对照点大截图重做双样式（style 1下划线/2胶囊），默认值对齐点大口径
    case 'tab':
      return {
        id, temp,
        params: { style: '1', fontsize1: '14', color1: '#333333', color2: '#FF9900', bgcolor: '#F7F7F8', bgcolor2: '#FFFFFF', borderradius: '0', sticky: '0', arrowshow: '1', arrowcolor: '#FF9900', unselectbgcolor: '#E9EAEC', selectbgcolor: '#399BE5', selectbordercolor: '#ffffff', margin_x: '0', margin_y: '0', padding_x: '0', padding_y: '0', ...vis },
        data: [
          { id: genMid(), name: '选项一' },
          { id: genMid(), name: '选项二' },
        ],
      }
    // ============ 任务245（P27-1）扩容组件 ============
    case 'video':
      return {
        id, temp,
        params: { type: '0', src: '', pic: '', videoheight: '30', autoplay: '0', loop: '0', muted: '0', controls: '1', fullscreen: '1', objectfit: 'contain', butposition: 'center', bgcolor: '#FFFFFF', margin_x: '0', margin_y: '0', padding_x: '0', padding_y: '0', ...vis },
        data: [],
      }
    case 'button':
      return {
        id, temp,
        params: { btntext: '按钮文字', btnbgcolor: '#FF9900', btnbordercolor: '#FF9900', btnfontsize: '14', btncolor: '#FFFFFF', btnwidth: '300', btnheight: '40', btnradius: '20', bgcolor: '#FFFFFF', hrefurl: '', hrefname: '', margin_x: '0', margin_y: '0', padding_x: '0', padding_y: '0', ...vis },
        data: [],
      }
    case 'dhlist':
      return {
        id, temp,
        params: { fontsize1: '16', color1: '#333333', titlemarginleft: '8', fontsize2: '12', color2: '#999999', arrowshow: '1', bgcolor: '#FFFFFF', margin_x: '0', margin_y: '0', padding_x: '10', padding_y: '5', ...vis },
        data: [
          { id: genMid(), showicon: '1', imgurl: '', iconsize: '16', title1: '列表1', title2: '备注信息', hrefurl: '', hrefname: '' },
          { id: genMid(), showicon: '1', imgurl: '', iconsize: '16', title1: '列表2', title2: '备注信息', hrefurl: '', hrefname: '' },
        ],
      }
    case 'cover':
      return {
        id, temp,
        params: { style: '1', text: '咨询', fontsize: '14', color: '#FFFFFF', pic: '', picwidth: '50', picheight: '50', bgcolor: 'rgba(237, 63, 20, 1)', border: '0', bordercolor: 'rgba(255, 255, 255, 0.7)', top: '80', left: '80', width: '50', height: '50', radius: '25', hrefurl: '', hrefname: '', ...vis },
        data: [],
      }
    case 'map':
      return {
        id, temp,
        params: { address: '请输入门店地址', longitude: '', latitude: '', height: '200', bgcolor: '#FFFFFF', margin_x: '0', margin_y: '0', padding_x: '0', padding_y: '0', ...vis },
        data: [],
      }
    case 'hotspot':
      return {
        id, temp,
        params: { imgurl: '', margin_x: '0', margin_y: '0', padding_x: '0', padding_y: '0', ...vis },
        data: [{ id: genMid(), hrefurl: '', hrefname: '', width: '80', height: '80', left: '0', top: '0' }],
      }
    case 'shop':
      // 任务302：店招（对齐参考口径 style/bgimg/logo/name/menu/navcolor；shopname/logourl 取代参考 shopinfo 下发保证 B/C 一致）
      return {
        id, temp,
        params: { style: '1', bgimg: '', logourl: '', shopname: '我的店铺', logo: '1', name: '1', menu: '1', navcolor: '#FFFFFF', navheight: '34', navfontsize: '11', naviconsize: '16', bgcolor: '#FFFFFF', margin_x: '0', margin_y: '0', padding_x: '0', padding_y: '0', ...vis },
        data: [
          { id: genMid(), text: '首页', imgurl: '', hrefurl: '', hrefname: '' },
          { id: genMid(), text: '分类', imgurl: '', hrefurl: '', hrefname: '' },
        ],
      }
    case 'featured':
      // 任务303：精选=左轮播+右双图（图片组件口径，非商品）；data=轮播图列表；右双图存 params imgtop/imgbot
      return {
        id, temp,
        params: { height: '249', indicatordots: '1', dotshape: '0', dotposition: 'center', dotbottom: '8', dotcolor: '#ffffff', dotactivecolor: '#3DB51E', interval: '5', imgtop: '', imgtopurl: '', imgtopname: '', imgbot: '', imgboturl: '', imgbotname: '', bgcolor: '#FFFFFF', margin_x: '0', margin_y: '0', padding_x: '10', padding_y: '10', ...vis },
        data: [
          { id: genMid(), imgurl: '', hrefurl: '', hrefname: '' },
          { id: genMid(), imgurl: '', hrefurl: '', hrefname: '' },
        ],
      }
    case 'cube':
      // 任务300：slots 自由布局口径 {x,y,w,h,imgurl,hrefurl}，取代旧 cubeNum 列数模式
      return {
        id, temp,
        params: { slots: [], bgcolor: '#FFFFFF', margin_x: '0', margin_y: '0', padding_x: '0', padding_y: '0', ...vis },
        data: [],
      }
    case 'orderbroadcast':
      // 任务 320：订单播报（页面流内）：与上方距离=margin_y，左右边距=margin_x；
      // 系统设置 order_broadcast 全部去勾选时 C 端不渲染（门控同 follow-bar）
      return {
        id, temp,
        params: { height: '36', borderradius: '18', bgcolor: '#F5F5F5', color: '#666666', showavatar: '1', interval: '3', margin_x: '10', margin_y: '10', padding_x: '0', padding_y: '0', ...vis },
        data: [],
      }
    case 'coupon':
      // 优惠券组件（严格参照点大「商品组设置」属性面板 + yhq.png；字段口径对齐 canzhao/edit-coupon.html）
      return {
        id, temp,
        params: {
          titlecolor: '#FFFFFF',
          remarkcolor: '#E5E5E5',
          bgpic: '',
          bgcolor: '#FFFFFF',
          couponfrom: '1',
          sortby: 'sort',
          shownum: '6',
          margin_x: '0',
          margin_y: '0',
          padding_x: '0',
          padding_y: '4',
          ...vis,
        },
        data: [],
      }
    default:
      return { id, temp, params: {}, data: [] }
  }
}
