import type { MenuNode } from './menuTypes'
import { defineAsyncComponent, defineComponent, h, type Component } from 'vue'
// 设计器五项深化页（P17-3：底部导航/内页导航/商品详情/登录页装修/分享设置）
import DesignerMenuNav from '../views/admin/DesignerMenuNav.vue'
import DesignerMenu2 from '../views/admin/DesignerMenu2.vue'
import DesignerShopdetail from '../views/admin/DesignerShopdetail.vue'
import DesignerLogin from '../views/admin/DesignerLogin.vue'
import DesignerShare from '../views/admin/DesignerShare.vue'
// 页面设计器列表页（页面装修：页面管理 + 模板库）
import DesignerPageList from '../views/admin/DesignerPageList.vue'
// ===== 已实现页面（挂位） =====
import Goods from '../views/admin/Goods.vue'
import GoodsCategory from '../views/admin/GoodsCategory.vue'
// 商城配置组五页（批次2-A 任务173：商品分组/商品参数/商品服务/商城设置/商品采集）
import ShopGroup from '../views/admin/ShopGroup.vue'
import ShopParam from '../views/admin/ShopParam.vue'
import ShopFuwu from '../views/admin/ShopFuwu.vue'
import ShopSet from '../views/admin/ShopSet.vue'
import ShopTaobao from '../views/admin/ShopTaobao.vue'
import CardCode from '../views/admin/CardCode.vue'
import Orders from '../views/admin/Orders.vue'
import Refunds from '../views/admin/Refunds.vue'
import Merchant from '../views/admin/Merchant.vue'
import Store from '../views/admin/Store.vue'
import ScoreConfig from '../views/admin/ScoreConfig.vue'
import Promotion from '../views/admin/Promotion.vue'
import YuyueProduct from '../views/admin/YuyueProduct.vue'
import YuyueWorker from '../views/admin/YuyueWorker.vue'
import YuyueOrder from '../views/admin/YuyueOrder.vue'
// ===== 餐饮配置六页（P14-1：餐桌/餐桌分类/菜品/菜品分类/堂食设置/打印机） =====
import TableManage from '../views/admin/TableManage.vue'
import TableCategory from '../views/admin/TableCategory.vue'
import DishManage from '../views/admin/DishManage.vue'
import DishCategory from '../views/admin/DishCategory.vue'
import RestaurantSet from '../views/admin/RestaurantSet.vue'
import PrinterManage from '../views/admin/PrinterManage.vue'
// ===== 餐饮订单两页（P14-2：堂食订单/外卖订单） =====
import ShopOrderList from '../views/admin/ShopOrderList.vue'
import TakeawayOrderList from '../views/admin/TakeawayOrderList.vue'
// ===== 餐饮寄存四页（批次6-T6-3 任务204：寄存管理/添加寄存/寄存位置/寄存设置） =====
import RestaurantDeposit from '../views/admin/RestaurantDeposit.vue'
import RestaurantDepositAdd from '../views/admin/RestaurantDepositAdd.vue'
import RestaurantDepositPosition from '../views/admin/RestaurantDepositPosition.vue'
import RestaurantDepositSet from '../views/admin/RestaurantDepositSet.vue'
// ===== 排队叫号三页（批次6-T6-2 任务203：队列列表/队列分类/排队设置） =====
import RestaurantQueue from '../views/admin/RestaurantQueue.vue'
import RestaurantQueueCategory from '../views/admin/RestaurantQueueCategory.vue'
import RestaurantQueueSet from '../views/admin/RestaurantQueueSet.vue'
// ===== 餐饮预定两页（批次6-T6-1 任务202：预定订单/预定设置） =====
import RestaurantBookingOrder from '../views/admin/RestaurantBookingOrder.vue'
import RestaurantBookingSet from '../views/admin/RestaurantBookingSet.vue'
// ===== 通用收银台四页（批次6-T6-5 任务206：收银设置/收银台操作/收银订单/统计交接班） =====
import CashierSet from '../views/admin/CashierSet.vue'
import CashierDesk from '../views/admin/CashierDesk.vue'
import CashierOrder from '../views/admin/CashierOrder.vue'
import CashierStat from '../views/admin/CashierStat.vue'
import MemberList from '../views/admin/MemberList.vue'
import MemberLevel from '../views/admin/MemberLevel.vue'
// ===== 会员组三页+共享海报引擎（P18-4） =====
import LevelUpAudit from '../views/admin/LevelUpAudit.vue'
import ShopPoster from '../views/admin/ShopPoster.vue'
import MemberPoster from '../views/admin/MemberPoster.vue'
// ===== 六业务分享海报（批次4-D 任务189：拼团/幸运拼团/砍价/团购/积分兑换/周期购） =====
import CollagePoster from '../views/admin/CollagePoster.vue'
import LuckyCollagePoster from '../views/admin/LuckyCollagePoster.vue'
import KanjiaPoster from '../views/admin/KanjiaPoster.vue'
import TuangouPoster from '../views/admin/TuangouPoster.vue'
import ScoreshopPoster from '../views/admin/ScoreshopPoster.vue'
import CyclePoster from '../views/admin/CyclePoster.vue'
import ClearCache from '../views/admin/ClearCache.vue'
// ===== 支付设置两页（批次7-7 任务215：公众号支付设置/小程序支付设置） =====
import MppaySet from '../views/admin/MppaySet.vue'
import WxpaySet from '../views/admin/WxpaySet.vue'
import Sysset from '../views/admin/Sysset.vue'
import Freight from '../views/admin/Freight.vue'
import ExpressData from '../views/admin/ExpressData.vue'
import SalesStat from '../views/admin/SalesStat.vue'
import CommentList from '../views/admin/CommentList.vue'
// ===== 用户论坛五页（批次5-B 任务195：帖子/版块/评论/回评/设置） =====
import LuntanIndex from '../views/admin/LuntanIndex.vue'
import LuntanCategory from '../views/admin/LuntanCategory.vue'
import LuntanPinglun from '../views/admin/LuntanPinglun.vue'
import LuntanPlreply from '../views/admin/LuntanPlreply.vue'
import LuntanSysset from '../views/admin/LuntanSysset.vue'
// ===== 系统权限组六页（批次2-C：管理员/短信/店铺评价/操作日志/快捷菜单；角色权限入口已迁至管理员列表的权限组按钮，任务269） =====
import AdminUserList from '../views/admin/AdminUserList.vue'
import SmsSet from '../views/admin/SmsSet.vue'
import BusinessComment from '../views/admin/BusinessComment.vue'
import PlogList from '../views/admin/PlogList.vue'
import ShortcutMenuSet from '../views/admin/ShortcutMenuSet.vue'
// ===== 营销补全四页（P13-1：优惠券管理/领取记录/签到/赠送奖励） =====
import CouponManage from '../views/admin/CouponManage.vue'
import CouponRecord from '../views/admin/CouponRecord.vue'
void CouponRecord // 保留：CouponManage 行操作打开领取记录（另一片接入）
import SignSet from '../views/admin/SignSet.vue'
import RewardSet from '../views/admin/RewardSet.vue'
// ===== 赠送促销四页（批次4-A 任务186：注册赠送/充值赠送/商品促销/购物返现） =====
import RegisterGive from '../views/admin/RegisterGive.vue'
import MoneyGiveset from '../views/admin/MoneyGiveset.vue'
import Cuxiao from '../views/admin/Cuxiao.vue'
import Cashback from '../views/admin/Cashback.vue'
// ===== 录入订单+买单收款四页（P18-2） =====
import OrderEntry from '../views/admin/OrderEntry.vue'
import MaidanAdd from '../views/admin/MaidanAdd.vue'
import MaidanLog from '../views/admin/MaidanLog.vue'
import MaidanSet from '../views/admin/MaidanSet.vue'
// ===== 财务中心八页（P12-2） =====
import PayorderList from '../views/admin/PayorderList.vue'
import MoneyLog from '../views/admin/MoneyLog.vue'
import RechargeLog from '../views/admin/RechargeLog.vue'
import WithdrawAudit from '../views/admin/WithdrawAudit.vue'
import CommissionLog from '../views/admin/CommissionLog.vue'
import HexiaoLog from '../views/admin/HexiaoLog.vue'
import InvoiceList from '../views/admin/InvoiceList.vue'
import WxpayLog from '../views/admin/WxpayLog.vue'
import WxTransferAuth from '../views/admin/WxTransferAuth.vue'
// ===== 财务记录三页（P18-5/任务177：佣金记录/分红记录/计次核销记录） =====
import CommissionRecord from '../views/admin/CommissionRecord.vue'
// ===== 三套分红页（P19-5/任务183：团队/股东/区域分红预览与发放） =====
// ===== 团购整套六页（批次4-B 任务187：商品/订单/评价/分类/卡密/设置） =====
import TuangouProduct from '../views/admin/TuangouProduct.vue'
import TuangouOrder from '../views/admin/TuangouOrder.vue'
import TuangouComment from '../views/admin/TuangouComment.vue'
import TuangouCategory from '../views/admin/TuangouCategory.vue'
import TuangouSet from '../views/admin/TuangouSet.vue'
// ===== 营销小项集合六页（批次4-E 任务190：口令/红包/竞猜/活动评价/收货奖励记录/数据统计） =====
import ActivityComment from '../views/admin/ActivityComment.vue'
import CollectRewardRecord from '../views/admin/CollectRewardRecord.vue'
import DataStatistics from '../views/admin/DataStatistics.vue'
// ===== 多人拼团四页（P13-3） =====
import CollageSet from '../views/admin/CollageSet.vue'
import CollageProduct from '../views/admin/CollageProduct.vue'
import CollageTeam from '../views/admin/CollageTeam.vue'
import CollageOrder from '../views/admin/CollageOrder.vue'
// ===== 幸运拼团七页（批次4-C 任务188，海报页见上方 LuckyCollagePoster） =====
import LuckyCollageProduct from '../views/admin/LuckyCollageProduct.vue'
import LuckyCollageOrder from '../views/admin/LuckyCollageOrder.vue'
import LuckyCollageTeam from '../views/admin/LuckyCollageTeam.vue'
import LuckyCollageComment from '../views/admin/LuckyCollageComment.vue'
import LuckyCollageCategory from '../views/admin/LuckyCollageCategory.vue'
import LuckyCollageJiqiren from '../views/admin/LuckyCollageJiqiren.vue'
import LuckyCollageSet from '../views/admin/LuckyCollageSet.vue'
// ===== 秒杀与积分商城（P13-2） =====
import SeckillSet from '../views/admin/SeckillSet.vue'
import SeckillProduct from '../views/admin/SeckillProduct.vue'
import SeckillOrder from '../views/admin/SeckillOrder.vue'
import ScoreshopManage from '../views/admin/ScoreshopManage.vue'
// ===== 平台绑定中心（P15-1：八端配置，微信双端支持手动/代授权） =====
import PlatformBinding from '../views/admin/PlatformBinding.vue'
// ===== 投票活动（P15-5：活动管理/选手管理） =====
import VoteManage from '../views/admin/VoteManage.vue'
import VoteJoin from '../views/admin/VoteJoin.vue'
import VoteGroup from '../views/admin/VoteGroup.vue'
import VoteRecord from '../views/admin/VoteRecord.vue'
// ===== 订单批量操作与送货单（P17-2） =====
import ShdSet from '../views/admin/ShdSet.vue'
// ===== 抽奖活动（P15-3：大转盘/刮刮卡活动管理 + 中奖记录） =====
import LotteryManage from '../views/admin/LotteryManage.vue'
import LotteryRecord from '../views/admin/LotteryRecord.vue'
// ===== 砍价活动（P15-4：商品管理/订单管理/系统设置） =====
import KanjiaProduct from '../views/admin/KanjiaProduct.vue'
import KanjiaOrder from '../views/admin/KanjiaOrder.vue'
import KanjiaSet from '../views/admin/KanjiaSet.vue'
// ===== 周期购（P15-6：商品+分类/订单+分期发货/系统设置） =====
import CycleProduct from '../views/admin/CycleProduct.vue'
import CycleCategory from '../views/admin/CycleCategory.vue'
import CycleOrder from '../views/admin/CycleOrder.vue'
import CycleSet from '../views/admin/CycleSet.vue'
// ===== 小程序发布链（P15-2：代码发布/运营设置） =====
import WxappPublish from '../views/admin/WxappPublish.vue'
import WxappSet from '../views/admin/WxappSet.vue'
// ===== 文章管理整组五页（批次5-A 任务194：文章/分类/评论/回评/设置） =====
import ArticleList from '../views/admin/ArticleList.vue'
import ArticleCategory from '../views/admin/ArticleCategory.vue'
import ArticlePinglun from '../views/admin/ArticlePinglun.vue'
import ArticlePlreply from '../views/admin/ArticlePlreply.vue'
import ArticleSet from '../views/admin/ArticleSet.vue'
// ===== 短视频整组六页（批次5-C 任务196：分类/视频/评论/回评/海报/设置） =====
import ShortvideoCategory from '../views/admin/ShortvideoCategory.vue'
import ShortvideoList from '../views/admin/ShortvideoList.vue'
import ShortvideoComment from '../views/admin/ShortvideoComment.vue'
import ShortvideoCommentReply from '../views/admin/ShortvideoCommentReply.vue'
import ShortvideoPoster from '../views/admin/ShortvideoPoster.vue'
import ShortvideoSysset from '../views/admin/ShortvideoSysset.vue'
// ===== 自定义表单（平台扩展 bid=0 / 商户聚合 bid>0 双挂同一 FormIndex） =====
import FormIndex from '../views/admin/FormIndex.vue'
// ===== 知识付费七页（批次5-D1 任务197：分类/课程/章节/订单/学习记录/海报/设置；题库考试留 D2） =====
import KechengCategory from '../views/admin/KechengCategory.vue'
import KechengList from '../views/admin/KechengList.vue'
import KechengChapter from '../views/admin/KechengChapter.vue'
import KechengOrder from '../views/admin/KechengOrder.vue'
import KechengStudylog from '../views/admin/KechengStudylog.vue'
import KechengPoster from '../views/admin/KechengPoster.vue'
import KechengSet from '../views/admin/KechengSet.vue'
// ===== 知识付费题库（批次5-D2 任务198；讲师审核菜单已下线） =====
import KechengTiku from '../views/admin/KechengTiku.vue'

/**
 * 平台绑定组件工厂：八个菜单项共用 PlatformBinding，initialTab 直达对应端
 */
function bindingOf(tab: string) {
  return defineComponent({
    name: `PlatformBinding-${tab}`,
    render: () => h(PlatformBinding, { initialTab: tab }),
  })
}

/**
 * 佣金组件工厂：CommissionLog 双实例（佣金明细/佣金提现）各自独立，避免 KeepAlive 串档
 */
function commissionLogOf(mode: 'log' | 'withdraw') {
  return defineComponent({
    name: `CommissionLog-${mode}`,
    render: () => h(CommissionLog, { mode }),
  })
}

/**
 * 签到组件工厂：SignSet 双实例（签到设置/签到记录）各自独立，避免 KeepAlive 串档
 */
function signSetOf(tab: 'set' | 'record') {
  return defineComponent({
    name: `SignSet-${tab}`,
    render: () => h(SignSet, { tab }),
  })
}

/**
 * 商户过滤视图组件工厂（任务177）：为已存在列表页预置 bid='gt0'（仅商户）实例，
 * 一套代码两菜单挂位（自营原菜单缺省不过滤 / 商户过滤菜单预置），各自独立避免 KeepAlive 串档
 */
function merchantViewOf(tag: string, comp: Component) {
  return defineComponent({
    name: `${tag}-Merchant`,
    render: () => h(comp, { bid: 'gt0' }),
  })
}

/**
 * 平台自营视图组件工厂：为列表页预置 bid='0'（仅平台数据），
 * 与「商户」分组下 merchantViewOf 的 bid='gt0' 视图成对（如文章列表：平台文章 / 商户文章）。
 */
function platformViewOf(tag: string, comp: Component) {
  return defineComponent({
    name: `${tag}-Platform`,
    render: () => h(comp, { bid: '0' }),
  })
}

/**
 * 活动评价组件工厂（批次4-E 任务190）：拼团/秒杀评价双挂 ActivityComment，各自独立避免 KeepAlive 串档
 */
function activityCommentOf(type: 'collage' | 'seckill') {
  return defineComponent({
    name: `ActivityComment-${type}`,
    render: () => h(ActivityComment, { type }),
  })
}

/**
 * 积分兑换组件工厂：ScoreshopManage 多实例按菜单直达对应 tab（set/category/product/order），
 * 各自独立避免 KeepAlive 串档
 */
function scoreshopTabOf(tab: 'set' | 'category' | 'product' | 'order') {
  return defineComponent({
    name: `ScoreshopManage-${tab}`,
    render: () => h(ScoreshopManage, { initialTab: tab }),
  })
}

/**
 * 租户客户端菜单（10 大主菜单完整二三级树）
 * 权威来源逐行提取：
 * - canzhao\app\common\Menu.php getdata()（isadmin 主平台视角，全端平台）
 * - canzhao\app\custom\Restaurant.php getmenu()（餐饮子树，含条件开关项全收录）
 * 结构对齐参考系统：{ name, key, component?, child?, hide? }
 * - 菜单顺序按参考系统原序，数量与名称一项不少
 * - key 由参考系统 path 转 kebab-case，稳定可引用（hide 隐藏项保留配置不渲染）
 */
export const tenantMenus: MenuNode[] = [
  // ==================== 商城 ====================
  {
    name: '商城',
    key: 'shop',
    child: [
      { name: '商品管理', key: 'shop-product-index', component: Goods },
      // 商品卡密（P17-1：SKU 引擎配套，卡密生成/导入/导出/售出状态）
      { name: '商品卡密', key: 'shop-code-index', component: CardCode },
      { name: '订单管理', key: 'shop-order-index', component: Orders },
      { name: '订单-详情', key: 'shop-order-detail', hide: true },
      { name: '订单-送货单', key: 'shop-order-shd', hide: true },
      { name: '订单-修改', key: 'shop-order-edit', hide: true },
      { name: '订单-删除', key: 'shop-order-del', hide: true },
      { name: '订单-发货', key: 'shop-order-send', hide: true },
      { name: '订单-退款', key: 'shop-order-refund', hide: true },
      { name: '订单-核销', key: 'shop-order-hexiao', hide: true },
      { name: '订单-确认收货', key: 'shop-order-confirm', hide: true },
      { name: '退款申请', key: 'shop-refund-order-index', component: Refunds },
      { name: '评价管理', key: 'shop-comment-index', component: CommentList },
      { name: '商品分类', key: 'shop-category-index', component: GoodsCategory },
      { name: '商品分组', key: 'shop-group-index', component: ShopGroup },
      { name: '商品参数', key: 'shop-param-index', component: ShopParam },
      { name: '商品服务', key: 'shop-fuwu-index', component: ShopFuwu },
      { name: '商品海报', key: 'shop-poster-index', component: ShopPoster },
      { name: '录入订单', key: 'shop-orderlr-index', component: OrderEntry },
      { name: '商品采集', key: 'shop-taobao-index', component: ShopTaobao },
      { name: '销售统计', key: 'shop-order-tongji', component: SalesStat },
      { name: '系统设置', key: 'shop-set-index', component: ShopSet },
      { name: '下单签订合同', key: 'shopbuy-sign', hide: true },
      { name: '订单删除', key: 'order-del-auth', hide: true },
    ],
  },
  // ==================== 商户 ====================
  {
    name: '商户',
    key: 'business',
    child: [
      { name: '商户列表', key: 'business-index', component: Merchant },
            { name: '商户分类', key: 'business-category', component: defineAsyncComponent(() => import('../views/admin/BusinessCategory.vue')) },
      { name: '商户商品', key: 'shop-product-index-showtype-2', component: merchantViewOf('Goods', Goods) },
      { name: '商品审核', key: 'business-goods-audit', component: defineAsyncComponent(() => import('../views/admin/GoodsAudit.vue')) },
            { name: '商户销量', key: 'business-sales', component: defineAsyncComponent(() => import('../views/admin/BusinessSales.vue')) },
      { name: '商户订单', key: 'shop-order-index-showtype-2', component: merchantViewOf('Orders', Orders) },
      { name: '商户评价', key: 'business-comment-index-showtype-2', component: merchantViewOf('BusinessComment', BusinessComment) },
      { name: '拼团商品', key: 'collage-product-index-showtype-2', component: merchantViewOf('CollageProduct', CollageProduct) },
      { name: '砍价商品', key: 'kanjia-product-index-showtype-2', component: merchantViewOf('KanjiaProduct', KanjiaProduct) },
      { name: '秒杀商品', key: 'seckill-product-index-showtype-2', component: merchantViewOf('SeckillProduct', SeckillProduct) },
      // 团购商品商户过滤视图（任务187）
      { name: '团购商品', key: 'tuangou-product-index-showtype-2', component: merchantViewOf('TuangouProduct', TuangouProduct) },
      { name: '服务商品', key: 'yuyue-list-index-showtype-2', component: merchantViewOf('YuyueProduct', YuyueProduct) },
      { name: '周期购商品', key: 'cycle-product-index-showtype-2', component: merchantViewOf('CycleProduct', CycleProduct) },
      { name: '幸运拼团商品', key: 'lucky-collage-product-index-showtype-2', component: merchantViewOf('LuckyCollageProduct', LuckyCollageProduct) },
      // 文章/短视频列表已按商户过滤视图双挂；自定义表单商户视图（批次8 任务231 接线）
      { name: '文章列表', key: 'article-index-showtype-2', component: merchantViewOf('Article', ArticleList) },
      { name: '短视频列表', key: 'shortvideo-index-showtype-2', component: merchantViewOf('ShortvideoList', ShortvideoList) },
      { name: '自定义表单', key: 'form-index-showtype-2', component: merchantViewOf('FormIndex', FormIndex) },
      { name: '余额明细', key: 'business-money-moneylog', component: defineAsyncComponent(() => import('../views/admin/BusinessMoneyLog.vue')) },
      { name: '提现记录', key: 'business-money-withdrawlog', component: defineAsyncComponent(() => import('../views/admin/BusinessWithdrawLog.vue')) },
      { name: '通知公告', key: 'business-notice-index', component: defineAsyncComponent(() => import('../views/admin/BusinessNotice.vue')) },
      { name: '默认导航', key: 'designer-menu-business', component: defineAsyncComponent(() => import('../views/admin/DesignerMenuBusiness.vue')) },
      { name: '系统设置', key: 'business-sysset', component: defineAsyncComponent(() => import('../views/admin/BusinessSysset.vue')) },
      { name: '商户列表显示余额抵扣', key: 'money-dec-business-show', hide: true },
      { name: '商户列表显示买单余额抵扣', key: 'maidan-money-dec-business-show', hide: true },
      {
        name: '商户后台',
        key: 'business-backend',
        hide: true,
        child: [
          { name: '商品分类', key: 'shop-category2-index', hide: true },
          { name: '余额提现', key: 'business-money-withdraw', hide: true },
          { name: '买单扣费', key: 'business-maidan-add', hide: true },
          { name: '买单记录', key: 'business-maidan-index', hide: true },
          { name: '收款码', key: 'business-maidan-set', hide: true },
          { name: '店铺评价', key: 'business-comment-index', hide: true },
        ],
      },
    ],
  },
  // ==================== 会员 ====================
  {
    name: '会员',
    key: 'member',
    child: [
      { name: '会员列表', key: 'member-index', component: MemberList },
      { name: '充值', key: 'member-recharge', hide: true },
      { name: '加积分', key: 'member-addscore', hide: true },
      { name: '编辑冻结积分', key: 'member-addfreezescore', hide: true },
      { name: '加佣金', key: 'member-addcommission', hide: true },
      { name: '等级及分销', key: 'member-level-index', component: MemberLevel },
      { name: '升级申请记录', key: 'member-level-applyorder', component: LevelUpAudit },
      // 会员侧仅保留 4 个可见菜单（对照点大）：会员关系图、团队/股东/区域分红页面已删除
      { name: '分享海报', key: 'member-poster-index', component: MemberPoster },
      { name: '会员等级背景设置', key: 'member-level-bgset', hide: true },
      { name: '优惠券', key: 'coupon-record', hide: true },
      { name: '会员画像', key: 'team-member-huaxiang', hide: true },
    ],
  },
  // ==================== 财务 ====================
  {
    name: '财务',
    key: 'finance',
    child: [
      { name: '消费明细', key: 'payorder-index', component: PayorderList },
      { name: '余额明细', key: 'money-moneylog', component: MoneyLog },
      { name: '充值记录', key: 'money-rechargelog', component: RechargeLog },
      { name: '余额提现', key: 'money-withdrawlog', component: WithdrawAudit },
      { name: '微信转账记录', key: 'money-wx-transfer-log', component: WxpayLog },
      { name: '微信转账授权记录', key: 'wx-transfer-auth', component: merchantViewOf('WxTransferAuth', WxTransferAuth) },
      { name: '佣金记录', key: 'commission-record', component: CommissionRecord },
      { name: '佣金明细', key: 'commission-commissionlog', component: commissionLogOf('log') },
      { name: '佣金提现', key: 'commission-withdrawlog', component: commissionLogOf('withdraw') },
      // 积分设置（已实现，含全局积分配置/会员积分/积分明细）挂财务·积分明细，语义最贴切
      { name: '积分明细', key: 'score-scorelog', component: ScoreConfig },
      {
        name: '商户积分',
        key: 'finance-business-score',
        hide: true,
        child: [
          { name: '积分明细', key: 'business-score-scorelog', hide: true },
          { name: '会员积分', key: 'business-score-memberscore', hide: true },
        ],
      },
      {
        name: '买单收款',
        key: 'maidan',
        child: [
          { name: '买单扣费', key: 'maidan-add', component: MaidanAdd },
          { name: '买单记录', key: 'maidan-index', component: MaidanLog },
          { name: '聚合收款码', key: 'maidan-set', component: MaidanSet },
        ],
      },
      { name: '核销记录', key: 'hexiao-index', component: HexiaoLog },
      { name: '发票管理', key: 'invoice-index', component: InvoiceList },
      { name: '买单余额抵扣比例', key: 'maidan-money-dec-dandu-bl', hide: true },
      { name: '买单虚拟销量', key: 'maidan-sales-virtual-business', hide: true },
    ],
  },
  // ==================== 营销 ====================
  {
    name: '营销',
    key: 'yingxiao',
    child: [
      { name: '优惠券', key: 'coupon', component: CouponManage },
      { name: '注册赠送', key: 'member-register-give', component: RegisterGive },
      { name: '充值赠送', key: 'money-giveset', component: MoneyGiveset },
      { name: '购物满减', key: 'manjian-set', component: Promotion },
      { name: '商品促销', key: 'cuxiao-index', component: Cuxiao },
      { name: '购物返现', key: 'cashback-index', component: Cashback },
      {
        name: '多人拼团',
        key: 'collage',
        child: [
          { name: '商品管理', key: 'collage-product-index', component: CollageProduct },
          { name: '订单管理', key: 'collage-order-index', component: CollageOrder },
          { name: '拼团管理', key: 'collage-team-index', component: CollageTeam },
          { name: '评价管理', key: 'collage-comment-index', component: activityCommentOf('collage') },
          { name: '商品分类', key: 'collage-category-index', component: defineAsyncComponent(() => import('../views/admin/CollageCategory.vue')) },
          { name: '分享海报', key: 'collage-poster-index', component: CollagePoster },
          { name: '系统设置', key: 'collage-set-index', component: CollageSet },
        ],
      },
      {
        name: '幸运拼团',
        key: 'lucky-collage',
        child: [
          { name: '商品管理', key: 'lucky-collage-product-index', component: LuckyCollageProduct },
          { name: '订单管理', key: 'lucky-collage-order-index', component: LuckyCollageOrder },
          { name: '拼团管理', key: 'lucky-collage-team-index', component: LuckyCollageTeam },
          { name: '评价管理', key: 'lucky-collage-comment-index', component: LuckyCollageComment },
          { name: '商品分类', key: 'lucky-collage-category-index', component: LuckyCollageCategory },
          { name: '分享海报', key: 'lucky-collage-poster-index', component: LuckyCollagePoster },
          { name: '机器人管理', key: 'lucky-collage-jiqiren-index', component: LuckyCollageJiqiren },
          { name: '系统设置', key: 'lucky-collage-set-index', component: LuckyCollageSet },
        ],
      },
      {
        name: '砍价活动',
        key: 'kanjia',
        child: [
          { name: '商品管理', key: 'kanjia-product-index', component: KanjiaProduct },
          { name: '订单管理', key: 'kanjia-order-index', component: KanjiaOrder },
          { name: '分享海报', key: 'kanjia-poster-index', component: KanjiaPoster },
          { name: '系统设置', key: 'kanjia-set-index', component: KanjiaSet },
        ],
      },
      {
        name: '整点秒杀',
        key: 'seckill',
        child: [
          { name: '商品列表', key: 'seckill-product-index', component: SeckillProduct },
          { name: '订单列表', key: 'seckill-order-index', component: SeckillOrder },
          { name: '用户评价', key: 'seckill-comment-index', component: activityCommentOf('seckill') },
          { name: '秒杀设置', key: 'seckill-set-index', component: SeckillSet },
        ],
      },
      {
        name: '团购活动',
        key: 'tuangou',
        child: [
          { name: '商品管理', key: 'tuangou-product-index', component: TuangouProduct },
          { name: '订单管理', key: 'tuangou-order-index', component: TuangouOrder },
          { name: '评价管理', key: 'tuangou-comment-index', component: TuangouComment },
          { name: '商品分类', key: 'tuangou-category-index', component: TuangouCategory },
          { name: '分享海报', key: 'tuangou-poster-index', component: TuangouPoster },
          { name: '系统设置', key: 'tuangou-set-index', component: TuangouSet },
        ],
      },
      {
        name: '积分兑换',
        key: 'scoreshop',
        child: [
          { name: '商品管理', key: 'scoreshop-product-index', component: scoreshopTabOf('product') },
          { name: '订单管理', key: 'scoreshop-order-index', component: scoreshopTabOf('order') },
          { name: '商品分类', key: 'scoreshop-category-index', component: scoreshopTabOf('category') },
          { name: '分享海报', key: 'scoreshop-poster-index', component: ScoreshopPoster },
          { name: '系统设置', key: 'scoreshop-set-index', component: scoreshopTabOf('set') },
        ],
      },
      {
        name: '抽奖活动',
        key: 'choujiang',
        child: [
          { name: '活动列表', key: 'choujiang-index', component: LotteryManage },
          { name: '抽奖记录', key: 'choujiang-record', component: LotteryRecord },
        ],
      },
      {
        name: '短视频',
        key: 'shortvideo',
        child: [
          { name: '分类列表', key: 'shortvideo-category-index', component: ShortvideoCategory },
          { name: '视频列表', key: 'shortvideo-index', component: platformViewOf('Shortvideo', ShortvideoList) },
          { name: '评论列表', key: 'shortvideo-comment-index', component: ShortvideoComment },
          { name: '回评列表', key: 'shortvideo-comment-reply-index', component: ShortvideoCommentReply },
          { name: '海报设置', key: 'shortvideo-poster-index', component: ShortvideoPoster },
          { name: '系统设置', key: 'shortvideo-sysset-index', component: ShortvideoSysset },
        ],
      },
      {
        name: '确认收货奖励',
        key: 'order-collect-reward',
        child: [
          { name: '奖励记录', key: 'order-collect-reward-index', component: CollectRewardRecord },
          { name: '奖励设置', key: 'order-collect-reward-set', component: RewardSet },
        ],
      },
      { name: '数据统计', key: 'data-statistics-index', component: DataStatistics },
    ],
  },
  // ==================== 扩展 ====================
  {
    name: '扩展',
    key: 'component',
    child: [
      {
        name: '文章管理',
        key: 'article',
        child: [
          { name: '文章列表', key: 'article-index', component: platformViewOf('Article', ArticleList) },
          { name: '文章分类', key: 'article-category-index', component: ArticleCategory },
          { name: '评论列表', key: 'article-pinglun-index', component: ArticlePinglun },
          { name: '回评列表', key: 'article-plreply-index', component: ArticlePlreply },
          { name: '系统设置', key: 'article-set-set', component: ArticleSet },
        ],
      },
      {
        name: '用户论坛',
        key: 'luntan',
        child: [
          { name: '帖子列表', key: 'luntan-index', component: LuntanIndex },
          { name: '分类管理', key: 'luntan-category-index', component: LuntanCategory },
          { name: '评论列表', key: 'luntan-pinglun-index', component: LuntanPinglun },
          { name: '回评列表', key: 'luntan-plreply-index', component: LuntanPlreply },
          { name: '系统设置', key: 'luntan-sysset', component: LuntanSysset },
        ],
      },
      {
        name: '积分签到',
        key: 'sign',
        child: [
          { name: '签到记录', key: 'sign-record', component: signSetOf('record') },
          { name: '签到设置', key: 'sign-set', component: signSetOf('set') },
        ],
      },
      {
        name: '预约服务',
        key: 'yuyue',
        child: [
          { name: '服务类目', key: 'yuyue-index', component: defineAsyncComponent(() => import('../views/admin/YuyueCategory.vue')) },
          { name: '服务商品', key: 'yuyue-list-index', component: YuyueProduct },
          { name: '服务订单', key: 'yuyue-order-index', component: YuyueOrder },
          { name: '商品服务', key: 'yuyue-fuwu-index', component: defineAsyncComponent(() => import('../views/admin/YuyueFuwu.vue')) },
          { name: '商品评价', key: 'yuyue-comment-index', component: defineAsyncComponent(() => import('../views/admin/YuyueComment.vue')) },
          { name: '海报设置', key: 'yuyue-poster-index', component: defineAsyncComponent(() => import('../views/admin/YuyuePoster.vue')) },
          { name: '人员类型', key: 'yuyue-worker-category-index', component: defineAsyncComponent(() => import('../views/admin/YuyueWorkerCategory.vue')) },
          { name: '人员列表', key: 'yuyue-worker-index', component: YuyueWorker },
          { name: '人员审核', key: 'yuyue-worker-check', hide: true },
          { name: '人员评价', key: 'yuyue-worker-comment-index', component: defineAsyncComponent(() => import('../views/admin/YuyueWorkerComment.vue')) },
          { name: '提成明细', key: 'yuyue-money-moneylog', component: defineAsyncComponent(() => import('../views/admin/YuyueMoneyLog.vue')) },
          { name: '提现记录', key: 'yuyue-money-withdrawlog', component: defineAsyncComponent(() => import('../views/admin/YuyueWithdrawLog.vue')) },
          { name: '系统设置', key: 'yuyue-set-set', component: defineAsyncComponent(() => import('../views/admin/YuyueSet.vue')) },
        ],
      },
      {
        name: '知识付费',
        key: 'kecheng',
        child: [
          { name: '课程分类', key: 'kecheng-category-index', component: KechengCategory },
          { name: '课程列表', key: 'kecheng-list-index', component: KechengList },
          { name: '课程章节', key: 'kecheng-chapter-index', component: KechengChapter },
          { name: '题库管理', key: 'kecheng-tiku-index', component: KechengTiku },
          { name: '课程订单', key: 'kecheng-order-index', component: KechengOrder },
          { name: '学习记录', key: 'kecheng-studylog-index', component: KechengStudylog },
          { name: '课程海报', key: 'kecheng-poster-index', component: KechengPoster },
          { name: '课程设置', key: 'kecheng-set-index', component: KechengSet },
        ],
      },
      { name: '视频管理', key: 'video-list-index', component: defineAsyncComponent(() => import('../views/admin/VideoListIndex.vue')) },
      {
        name: '同城配送',
        key: 'peisong',
        child: [
          { name: '配送员列表', key: 'peisong-user-index', component: defineAsyncComponent(() => import('../views/admin/PeisongUser.vue')) },
          { name: '配送单列表', key: 'peisong-order-index', component: defineAsyncComponent(() => import('../views/admin/PeisongOrder.vue')) },
          { name: '评价列表', key: 'peisong-comment-index', component: defineAsyncComponent(() => import('../views/admin/PeisongComment.vue')) },
          { name: '提成明细', key: 'peisong-money-moneylog', component: defineAsyncComponent(() => import('../views/admin/PeisongMoneyLog.vue')) },
          { name: '提现记录', key: 'peisong-money-withdrawlog', component: defineAsyncComponent(() => import('../views/admin/PeisongWithdrawLog.vue')) },
          { name: '系统设置', key: 'peisong-set', component: defineAsyncComponent(() => import('../views/admin/PeisongSet.vue')) },
          { name: '码科跑腿对接', key: 'peisong-makeset', component: defineAsyncComponent(() => import('../views/admin/PeisongMakeset.vue')) },
          { name: '快递鸟设置', key: 'peisong-express-set', component: defineAsyncComponent(() => import('../views/admin/ExpressSet.vue')) },
          { name: '快递鸟门店', key: 'peisong-express-shop', component: defineAsyncComponent(() => import('../views/admin/ExpressShop.vue')) },
          { name: '快递鸟记录', key: 'peisong-express-record', component: defineAsyncComponent(() => import('../views/admin/ExpressRecord.vue')) },
        ],
      },
      { name: '物流助手', key: 'miandan-index', component: defineAsyncComponent(() => import('../views/admin/MiandanIndex.vue')) },
      {
        name: '电子面单',
        key: 'ewaybill',
        child: [
          { name: '面单记录', key: 'ewaybill-index', component: defineAsyncComponent(() => import('../views/admin/EwaybillIndex.vue')) },
          { name: '导出', key: 'ewaybill-excel', hide: true },
          { name: '删除', key: 'ewaybill-del', hide: true },
          { name: '面单设置', key: 'ewaybill-set-index', component: defineAsyncComponent(() => import('../views/admin/EwaybillSet.vue')) },
        ],
      },
      {
        name: '投票活动',
        key: 'toupiao',
        child: [
          { name: '活动列表', key: 'toupiao-index', component: VoteManage },
          { name: '选手列表', key: 'toupiao-joinlist', component: VoteJoin },
          { name: '投票分组', key: 'toupiao-group', component: VoteGroup },
          { name: '投票记录', key: 'toupiao-record', component: VoteRecord },
        ],
      },
      {
        name: '周期购',
        key: 'cycle',
        child: [
          { name: '商品管理', key: 'cycle-product-index', component: CycleProduct },
          { name: '订单管理', key: 'cycle-order-index', component: CycleOrder },
          { name: '配送管理', key: 'cycle-order-cycle-list-status-1', component: CycleOrder },
          { name: '评价管理', key: 'cycle-comment-index', component: defineAsyncComponent(() => import('../views/admin/CycleComment.vue')) },
          { name: '商品分类', key: 'cycle-category-index', component: CycleCategory },
          { name: '分享海报', key: 'cycle-poster-index', component: CyclePoster },
          { name: '系统设置', key: 'cycle-set-index', component: CycleSet },
        ],
      },
      {
        name: '名片',
        key: 'mingpian',
        child: [
          { name: '名片列表', key: 'mingpian-index', component: defineAsyncComponent(() => import('../views/admin/MingpianIndex.vue')) },
          { name: '系统设置', key: 'mingpian-set', component: defineAsyncComponent(() => import('../views/admin/MingpianSet.vue')) },
        ],
      },
      {
        name: '收银台',
        key: 'cashier',
        child: [
          { name: '收银设置', key: 'cashier-set-index', component: CashierSet },
          // 对齐点大：菜单仅三页（收银设置/收银订单/订单统计）；收银台操作隐藏保留，
          // 收银设置页「收银台地址」链接经独立路由 /cashierdesk 打开该页
          { name: '收银台操作', key: 'cashier-index', component: CashierDesk, hide: true },
          { name: '收银订单', key: 'cashier-order-index', component: CashierOrder },
          { name: '订单统计', key: 'cashier-order-tongji', component: CashierStat },
        ],
      },
      // 扩展端=平台表单(bid=0)，与商户聚合(bid>0)成对；平台在此添加的表单 bid=0，不进商户列表
      { name: '自定义表单', key: 'form-index', component: platformViewOf('Form', FormIndex) },
      {
        name: '礼品卡',
        key: 'lipin',
        child: [
          { name: '礼品卡', key: 'lipin-index', component: defineAsyncComponent(() => import('../views/admin/LipinIndex.vue')) },
          { name: '礼品卡兑换码', key: 'lipin-codelist', component: defineAsyncComponent(() => import('../views/admin/LipinCodelist.vue')) },
          { name: '兑换码生成', key: 'lipin-makecode', hide: true },
          { name: '兑换码导入', key: 'lipin-importexcel', hide: true },
          { name: '兑换码导出', key: 'lipin-codelistexcel', hide: true },
          { name: '兑换码修改状态', key: 'lipin-setst', hide: true },
          { name: '兑换码删除', key: 'lipin-codelistdel', hide: true },
          { name: '礼品卡分类', key: 'lipin-category-index', component: defineAsyncComponent(() => import('../views/admin/LipinCategory.vue')) },
        ],
      },
      // 小程序直播（批次7-6 任务214）
      { name: '小程序直播', key: 'live-index', component: defineAsyncComponent(() => import('../views/admin/LiveIndex.vue')) },
      {
        name: '微信代认证',
        key: 'wx-proxyverify',
        child: [
          // 微信代认证三页（批次7-8 任务216）
          { name: '小程序列表', key: 'wx-proxyverify-index', component: defineAsyncComponent(() => import('../views/admin/WxProxyverifyIndex.vue')) },
          { name: '认证列表', key: 'wx-proxyverify-order', component: defineAsyncComponent(() => import('../views/admin/WxProxyverifyOrder.vue')) },
          { name: '系统设置', key: 'wx-proxyverify-set', component: defineAsyncComponent(() => import('../views/admin/WxProxyverifySet.vue')) },
        ],
      },
    ],
  },
  // ==================== 餐饮（Restaurant.php getmenu()；收银台/取餐/备注预置/统计四项
  //   受 getcustom 开关控制且默认关闭，已按点大默认配置整体移除，不做隐藏保留） ====================
  {
    name: '餐饮',
    key: 'restaurant',
    child: [
      { name: '首页', key: 'restaurant-index', component: defineAsyncComponent(() => import('../views/admin/RestaurantIndex.vue')) },
      {
        name: '菜品',
        key: 'restaurant-product',
        child: [
          { name: '菜品列表', key: 'restaurant-product-index', component: DishManage },
          { name: '菜品分类', key: 'restaurant-product-category-index', component: DishCategory },
          { name: '菜品采集', key: 'restaurant-taobao-index', component: defineAsyncComponent(() => import('../views/admin/RestaurantTaobao.vue')) },
        ],
      },
      {
        name: '外卖',
        key: 'restaurant-takeaway',
        child: [
          { name: '外卖订单', key: 'restaurant-takeaway-order-index', component: TakeawayOrderList },
          { name: '评价管理', key: 'restaurant-takeaway-comment-index', component: defineAsyncComponent(() => import('../views/admin/RestaurantTakeawayComment.vue')) },
          { name: '配送方式', key: 'restaurant-takeaway-freight-index', component: defineAsyncComponent(() => import('../views/admin/RestaurantTakeawayFreight.vue')) },
          { name: '外卖设置', key: 'restaurant-takeaway-set-index', component: defineAsyncComponent(() => import('../views/admin/RestaurantTakeawaySet.vue')) },
          { name: '广告设置', key: 'restaurant-takeaway-adv-index', component: defineAsyncComponent(() => import('../views/admin/RestaurantTakeawaySet.vue')) },
          { name: '手机管理外卖退款', key: 'restaurant-takeaway-admin-refund', hide: true },
          { name: '外卖页设计', key: 'restaurant-takeaway-designer-page-index', component: defineAsyncComponent(() => import('../views/admin/RestaurantTakeawayDesignerPage.vue')) },
        ],
      },
      {
        name: '点餐',
        key: 'restaurant-shop',
        child: [
          { name: '点餐订单', key: 'restaurant-shop-order-index', component: ShopOrderList },
          { name: '评价管理', key: 'restaurant-shop-comment-index', component: defineAsyncComponent(() => import('../views/admin/RestaurantShopComment.vue')) },
          { name: '点餐设置', key: 'restaurant-shop-set-index', component: RestaurantSet },
          { name: '点餐页设计', key: 'restaurant-shop-designer-page-index', component: defineAsyncComponent(() => import('../views/admin/RestaurantShopDesignerPage.vue')) },
          { name: '手机管理点餐退款', key: 'restaurant-shop-admin-refund', hide: true },
        ],
      },
      {
        name: '预定',
        key: 'restaurant-booking',
        child: [
          { name: '预定订单', key: 'restaurant-booking-order-index', component: RestaurantBookingOrder },
          { name: '预定设置', key: 'restaurant-booking-set-index', component: RestaurantBookingSet },
        ],
      },
      {
        name: '排队',
        key: 'restaurant-queue',
        child: [
          { name: '排队队列', key: 'restaurant-queue-index', component: RestaurantQueue },
          { name: '队列设置', key: 'restaurant-queue-category-index', component: RestaurantQueueCategory },
          { name: '排队设置', key: 'restaurant-queue-set-index', component: RestaurantQueueSet },
        ],
      },
      {
        name: '寄存',
        key: 'restaurant-deposit',
        child: [
          { name: '寄存管理', key: 'restaurant-deposit-index', component: RestaurantDeposit },
          { name: '添加寄存', key: 'restaurant-deposit-edit', component: RestaurantDepositAdd },
          { name: '寄存位置', key: 'restaurant-deposit-position-index', component: RestaurantDepositPosition },
          { name: '寄存设置', key: 'restaurant-deposit-set-index', component: RestaurantDepositSet },
        ],
      },
      {
        name: '餐桌',
        key: 'restaurant-table',
        child: [
          { name: '餐桌列表', key: 'restaurant-table-index', component: TableManage },
          { name: '餐桌分类', key: 'restaurant-table-category-index', component: TableCategory },
        ],
      },
      {
        name: '营销',
        key: 'restaurant-yingxiao',
        child: [
          { name: '优惠券', key: 'restaurant-coupon-index', component: defineAsyncComponent(() => import('../views/admin/RestaurantCoupon.vue')) },
          { name: '促销', key: 'restaurant-cuxiao-index', component: defineAsyncComponent(() => import('../views/admin/RestaurantCuxiao.vue')) },
        ],
      },
      { name: '餐厅区域打印', key: 'restaurant-print-index', component: defineAsyncComponent(() => import('../views/admin/RestaurantPrint.vue')) },
      { name: '设置', key: 'restaurant-sysset', component: defineAsyncComponent(() => import('../views/admin/RestaurantSysset.vue')) },
    ],
  },
  // ==================== 设计 ====================
  {
    name: '设计',
    key: 'jiemian',
    child: [
      { name: '页面设计', key: 'designer-page-index', component: DesignerPageList },
      { name: '页面模板', key: 'designer-template-index', component: defineAsyncComponent(() => import('../views/admin/DesignerTemplateList.vue')) },
      { name: '底部导航', key: 'designer-menu-index', component: DesignerMenuNav },
      { name: '内页导航', key: 'designer-menu-menu2', component: DesignerMenu2 },
      { name: '商品详情', key: 'designer-menu-shopdetail-shopdetail', component: DesignerShopdetail },
      { name: '登录页面', key: 'designer-login-index', component: DesignerLogin },
      { name: '移动端后台', key: 'designer-mobile-index', component: defineAsyncComponent(() => import('../views/admin/DesignerMobile.vue')) },
      { name: '分享设置', key: 'designer-share-index', component: DesignerShare },
      { name: '链接地址', key: 'designer-page-chooseurl', component: defineAsyncComponent(() => import('../views/admin/DesignerChooseUrl.vue')) },
    ],
  },
  // ==================== 平台 ====================
  {
    name: '平台',
    key: 'pingtai',
    child: [
      {
        name: '微信公众号',
        key: 'mp',
        child: [
          { name: '公众号绑定', key: 'binding-index', component: bindingOf('mp') },
          // 公众号菜单/模板消息/自动回复/粉丝群发（批次7 任务210-212）
          { name: '菜单设置', key: 'mpmenu-index', component: defineAsyncComponent(() => import('../views/admin/MpMenu.vue')) },
          { name: '支付设置', key: 'mppay-set', component: MppaySet },
          { name: '随行付分账', key: 'sxpay-fenzhang', hide: true },
          { name: '模板消息设置', key: 'mptmpl-tmplset', component: defineAsyncComponent(() => import('../views/admin/MptmplTmplset.vue')) },
          { name: '类目模板消息', key: 'mptmpl-tmplset-new', component: defineAsyncComponent(() => import('../views/admin/MptmplTmplsetNew.vue')) },
          { name: '已添加的模板', key: 'mptmpl-mytmpl', component: defineAsyncComponent(() => import('../views/admin/MptmplMytmpl.vue')) },
          { name: '被关注回复', key: 'mpkeyword-subscribe', component: defineAsyncComponent(() => import('../views/admin/MpKeywordSubscribe.vue')) },
          { name: '关键字回复', key: 'mpkeyword-index', component: defineAsyncComponent(() => import('../views/admin/MpKeyword.vue')) },
          { name: '粉丝列表', key: 'mpfans-fanslist', component: defineAsyncComponent(() => import('../views/admin/MpfansList.vue')) },
          { name: '模板消息群发', key: 'mpfans-tmplsend', component: defineAsyncComponent(() => import('../views/admin/MpfansTmplsend.vue')) },
        ],
      },
      {
        name: '微信会员卡',
        key: 'membercard',
        child: [
          // 会员卡两页（批次7-6 任务214）
          { name: '领取记录', key: 'membercard-record', component: defineAsyncComponent(() => import('../views/admin/MembercardRecord.vue')) },
          { name: '会员卡/创建', key: 'membercard-index', component: defineAsyncComponent(() => import('../views/admin/MembercardIndex.vue')) },
        ],
      },
      {
        name: '微信小程序',
        key: 'wx',
        child: [
          { name: '小程序绑定', key: 'binding-index', component: bindingOf('wx') },
          { name: '小程序发布', key: 'wxfabu-index', component: WxappPublish },
          { name: '运营设置', key: 'wxset-index', component: WxappSet },
          { name: '支付设置', key: 'wxpay-set', component: WxpaySet },
          // 小程序订阅消息/类目/外链/半屏/关键字（批次7 任务211/213）
          { name: '订阅消息设置', key: 'wxtmpl-tmplset', component: defineAsyncComponent(() => import('../views/admin/WxtmplTmplset.vue')) },
          { name: '服务类目', key: 'wxleimu-index', component: defineAsyncComponent(() => import('../views/admin/WxleimuIndex.vue')) },
          { name: '外部链接', key: 'wxurl-index', component: defineAsyncComponent(() => import('../views/admin/WxurlIndex.vue')) },
          { name: '半屏小程序', key: 'wxembedded-index', component: defineAsyncComponent(() => import('../views/admin/WxembeddedIndex.vue')) },
          { name: '关键字回复', key: 'wxkeyword-index', component: defineAsyncComponent(() => import('../views/admin/WxKeyword.vue')) },
          { name: '认证权限', key: 'wx-proxyverify-order', component: defineAsyncComponent(() => import('../views/admin/WxProxyverifyOrder.vue')) },
        ],
      },
      { name: '支付宝小程序', key: 'binding-alipay', component: bindingOf('alipay') },
      { name: '百度小程序', key: 'binding-baidu', component: bindingOf('baidu') },
      { name: '抖音小程序', key: 'binding-toutiao', component: bindingOf('toutiao') },
      { name: 'QQ小程序', key: 'binding-qq', component: bindingOf('qq') },
      { name: '手机H5', key: 'binding-h5', component: bindingOf('h5') },
      { name: '手机APP', key: 'binding-app', component: bindingOf('app') },
    ],
  },
  // ==================== 系统 ====================
  {
    name: '系统',
    key: 'system',
    child: [
      { name: '系统设置', key: 'backstage-sysset', component: Sysset },
      // 附件设置已移入系统设置页 Tab（任务258，对照参考系统 sysset lay-id=10）
      { name: '门店管理', key: 'mendian-index', component: Store },
      { name: '管理员列表', key: 'user-index', component: AdminUserList },
      // 角色权限入口已迁至管理员列表的权限组按钮（任务269）
      { name: '配送方式', key: 'freight-index', component: Freight },
      { name: '快递设置', key: 'express-data-index', component: ExpressData },
      { name: '送货单设置', key: 'shd-set-index', component: ShdSet },
      { name: '小票打印机', key: 'wifiprint-index', component: PrinterManage },
      { name: '短信设置', key: 'sms-set', component: SmsSet },
      { name: '店铺评价', key: 'business-comment-index', component: BusinessComment },
      { name: '操作日志', key: 'backstage-plog', component: PlogList },
      { name: '快捷菜单', key: 'shortcut-menu-index', component: ShortcutMenuSet },
      { name: '定制短信通道', key: 'sms-channel-index', hide: true },
      // 二级列表最底部：清理缓存（当页执行 + 原地提示）
      { name: '清理缓存', key: 'system-clear-cache', component: ClearCache },
    ],
  },
]
