<script setup lang="ts">
/**
 * 前端链接大弹窗（对标参考系统 designer_page/chooseurl，任务264全量对齐）
 * - mode='view'：顶栏「前端链接」入口，点击链接弹「链接地址」小弹窗（二维码/复制）
 * - mode='pick'：设计器等选链场景，点击链接直接 emit('pick', url, name) 并关闭
 * - 按住弹窗顶部标题栏可拖拽移动（限制在视口内）
 * - 基础功能Tab：链接目录全量对齐（参照 chooseurl 口径）（12个分组371条，
 *   getcustom 条件项一律无条件纳入）；路径三级口径：①目录原路径在我方 pages.json
 *   存在→直用 ②同功能我方有页→映射我方实际路径 ③确无功能→保留目录原路径（真缺口）
 * - 动态Tab：商品分类/商城商品/拼团/砍价/积分兑换/团购/幸运拼团/文章/课程/设计页面
 *   等保持现有 admin 列表接口加载（任务295：Tab内顶部搜索框+关键词过滤+分页每页20条，
 *   列表接口支持 keyword 的走服务端分页，分类树全量接口走前端本地过滤分页）；
 *   我方暂无接口的 Tab 改静态空态提示
 * - 商品分类条目点选二级弹窗（任务295）：五形态单选（商品列表/分类商品/分类商品2/
 *   快速购买/快速购买2），确认后回填带 cid 的路径，name 统一「分类>条目名」
 * - embed=true：页面内嵌形态（任务285「链接地址」独立页）——不 Teleport、无遮罩、
 *   无拖拽外壳与顶栏，目录内容直接铺在页面容器内；点击链接走 view 行为弹小弹窗
 */
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { getDesignerChooseUrl } from '../api/designerMobile'
import type { ChooseUrlCategoryNode } from '../api/designerMobile'
import { getGoodsList } from '../api/goods'
import { getCollageProductList } from '../api/collage'
import { getKanjiaProductList } from '../api/kanjia'
import { getScoreshopProductList } from '../api/scoreshop'
import { getTuangouProductList } from '../api/tuangou'
import { getLuckyCollageProductList, getLuckyCollageCategoryList } from '../api/luckyCollage'
import { getArticleList } from '../api/article'
import { getKechengCourseList } from '../api/kecheng'
import { getDesignerPageList } from '../api/designer'
import LinkAddressModal from './LinkAddressModal.vue'

export interface FrontendLink {
  name: string
  url: string
  /** 未带 Tab 前缀的原始条目名（分类二级弹窗回填用） */
  rawName?: string
}
export interface FrontendLinkGroup {
  group: string
  links: FrontendLink[]
}

const props = defineProps<{
  visible?: boolean
  mode?: 'view' | 'pick'
  /** 页面内嵌模式：无遮罩/无拖拽外壳，目录直接渲染在当前位置 */
  embed?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:visible', v: boolean): void
  (e: 'pick', url: string, name: string): void
}>()

// ============ 拖拽（按住标题栏移动，限制在视口内） ============
// 弹窗尺寸（任务266加大）：宽 min(1200px,92vw)、高 min(760px,86vh)，打开时按视口重算
const size = reactive({ w: 920, h: 560 })
const pos = reactive({ x: 0, y: 0 })
let dragging = false
let dragOffsetX = 0
let dragOffsetY = 0

function centerPanel() {
  size.w = Math.min(1200, Math.round(window.innerWidth * 0.92))
  size.h = Math.min(760, Math.round(window.innerHeight * 0.86))
  pos.x = Math.max(0, Math.round((window.innerWidth - size.w) / 2))
  pos.y = Math.max(0, Math.round((window.innerHeight - size.h) / 3))
}

function clampPos(x: number, y: number) {
  return {
    x: Math.min(Math.max(0, x), Math.max(0, window.innerWidth - 120)),
    y: Math.min(Math.max(0, y), Math.max(0, window.innerHeight - 44)),
  }
}

function onDragStart(e: MouseEvent) {
  // 点关闭按钮不触发拖拽
  if ((e.target as HTMLElement).closest('.fl-close')) return
  dragging = true
  dragOffsetX = e.clientX - pos.x
  dragOffsetY = e.clientY - pos.y
  document.addEventListener('mousemove', onDragMove)
  document.addEventListener('mouseup', onDragEnd)
  e.preventDefault()
}
function onDragMove(e: MouseEvent) {
  if (!dragging) return
  const p = clampPos(e.clientX - dragOffsetX, e.clientY - dragOffsetY)
  pos.x = p.x
  pos.y = p.y
}
function onDragEnd() {
  dragging = false
  document.removeEventListener('mousemove', onDragMove)
  document.removeEventListener('mouseup', onDragEnd)
}
onBeforeUnmount(onDragEnd)

function close() {
  emit('update:visible', false)
}

// ============ Tab 定义 ============
/** 动态Tab每页条数（任务295） */
const PAGE_SIZE = 20

/** 动态Tab加载结果（服务端/本地分页统一口径） */
interface DynLoadResult {
  links: FrontendLink[]
  total: number
}

interface DynamicTab {
  key: string
  label: string
  /** 无接口的Tab：静态空态提示文案 */
  staticTip?: string
  /** 服务端分页加载（接口支持 page/pagesize/keyword） */
  load?: (page: number, keyword: string) => Promise<DynLoadResult>
  /** 全量拉取一次（分类树等），前端本地关键词过滤 + 分页 */
  loadAll?: () => Promise<FrontendLink[]>
  /** 商品分类Tab：条目点击弹五形态二级弹窗 */
  categoryForms?: boolean
}

/** 分类树扁平化（chooseurl 接口的三级分类口径；名称带 Tab 前缀，rawName 存原始名） */
function flattenCategoryTree(
  nodes: ChooseUrlCategoryNode[] | undefined,
  urlTpl: (id: number) => string,
  namePrefix: string,
): FrontendLink[] {
  const out: FrontendLink[] = []
  function walk(list: ChooseUrlCategoryNode[] | undefined, depth: number) {
    for (const n of list || []) {
      out.push({
        name: namePrefix + '　'.repeat(depth) + n.name,
        rawName: n.name,
        url: urlTpl(n.id),
      })
      if (n.child?.length) walk(n.child, depth + 1)
    }
  }
  walk(nodes, 0)
  return out
}

/** 列表行 → 链接项（各列表接口行字段 name/title 兼容；name 统一「Tab名>条目名」） */
function rowsToLinks(
  rows: unknown,
  urlTpl: (id: number) => string,
  namePrefix = '',
): FrontendLink[] {
  const list = Array.isArray(rows) ? rows : []
  return list
    .map((r: Record<string, unknown>) => {
      const raw = String(r.name || r.title || `ID:${r.id}`)
      return { name: namePrefix + raw, rawName: raw, url: urlTpl(Number(r.id)) }
    })
    .filter((v) => v.url.includes('id=') && v.name)
}

/** 列表接口响应 → 分页结果（total/count 兼容） */
function toDynResult(
  res: unknown,
  urlTpl: (id: number) => string,
  namePrefix: string,
): DynLoadResult {
  const r = res as { list?: unknown; total?: number; count?: number }
  const links = rowsToLinks(r.list, urlTpl, namePrefix)
  return { links, total: Number(r.total ?? r.count) || links.length }
}

/**
 * 基础功能 Tab：静态目录全量对齐参考系统 chooseurl.html（12个分组371条）
 * 路径三级映射：①目录原路径在我方 pages.json 存在→直用；②同功能不同路径→映射我方
 * 实际路径；③确无对应功能→保留目录原路径（真缺口已落地C端骨架页，任务265）
 * {$bid} 以 0（平台主店）代入；getcustom 条件项无条件纳入
 */
const STATIC_GROUPS: FrontendLinkGroup[] = [
  {
    group: '基础页面',
    links: [
      { name: '首页', url: '/pages/index/index' },
      { name: '会员中心', url: '/pages/my/my' },
      { name: '分类商品', url: '/pages/category/category' },
      { name: '分类商品3', url: '/pages/category/category' },
      { name: '商品列表', url: '/pagesM/shop/prolist' },
      { name: '购物车', url: '/pages/cart/cart' },
    ],
  },
  {
    group: '商城页面',
    links: [
      { name: '一级分类', url: '/pages/category/category' },
      { name: '二级分类', url: '/pages/category/category' },
      { name: '二级分类2', url: '/pages/category/category' },
      { name: '三级分类', url: '/pages/category/category' },
      { name: '分类商品', url: '/pages/category/category' },
      { name: '分类商品2', url: '/pages/category/category' },
      { name: '分类商品3', url: '/pages/category/category' },
      { name: '商品列表', url: '/pagesM/shop/prolist' },
      { name: '快速购买', url: '/pagesM/shop/fastbuy' },
      { name: '快速购买2', url: '/pagesM/shop/fastbuy2' },
      { name: '购物车', url: '/pages/cart/cart' },
      { name: '商品搜索', url: '/pagesM/shop/search' },
      { name: '订单列表', url: '/pagesM/order/list' },
      { name: '商品套餐列表', url: '/pagesC/productthali/index' },
      { name: '我的套餐订单', url: '/pagesC/productthali/orderlist' },
      { name: '商品品牌', url: '/pagesC/shop/brandlist' },
      { name: '我的滤芯', url: '/pagesC/my/productlvxinreplace' },
    ],
  },
  {
    group: '营销活动',
    links: [
      { name: '秒杀列表', url: '/pagesH2/seckill/list' },
      { name: '秒杀订单', url: '/pagesH2/seckill/orderlist' },
      { name: '砍价首页', url: '/pagesH2/kanjia/list' },
      { name: '砍价订单', url: '/pagesH2/kanjia/orderlist' },
      { name: '拼团首页', url: '/pagesH1/collage/list' },
      { name: '拼团订单', url: '/pagesH1/collage/orderlist' },
      { name: '拼团分类', url: '/pagesH1/collage/list' },
      { name: '拼团列表', url: '/pagesH1/collage/list' },
      { name: '积分兑换', url: '/pagesH2/scoreshop/list' },
      { name: '积分兑换订单', url: '/pagesH2/scoreshop/orderlist' },
      { name: '积分兑换二级分类', url: '/pagesH2/scoreshop/list' },
      { name: '金豆兑换', url: '/pagesD/goldbeanshop/index' },
      { name: '金豆兑换订单', url: '/pagesD/goldbeanshop/orderlist' },
      { name: '幸运拼团预告页', url: '/pagesH1/luckycollage/list' },
      { name: '幸运拼团分类', url: '/pagesH1/luckycollage/list' },
      { name: '幸运拼团列表', url: '/pagesH1/luckycollage/list' },
      { name: '幸运拼团订单', url: '/pagesH1/luckycollage/orderlist' },
      { name: '团购商品', url: '/pagesH3/tuangou/list' },
      { name: '团购订单', url: '/pagesH3/tuangou/orderlist' },
      { name: '签到', url: '/pagesH3/sign/index' },
      { name: '邀请返现明细', url: '/pagesA/my/invitecashbacklog' },
      { name: '每日红包', url: '/pagesA/hongbaoEveryday/index' },
      { name: '口令', url: '/pagesH3/kouling/index' },
      { name: '拓客活动记录', url: '/pagesExt/hbtk/orderlist' },
      { name: '满人开奖', url: '/pagesH3/choujiang/index' },
      { name: '套餐产品', url: '/pagesA/taocan/index' },
      { name: '套餐订单', url: '/pagesA/taocan/orderlist' },
      { name: '消费盲返明细', url: '/pagesB/my/mangfanlog' },
      { name: '我的免单', url: '/pagesExt/invite_free/index' },
      { name: '满人开奖(购买)', url: '/pagesH3/choujiang/index' },
      { name: '倍增返现记录', url: '/pagesC/releasePoints/cashbacklog' },
      { name: '即拼订单序号', url: '/pagesC/yingxiao/jipinOrder' },
      { name: '超级卖货', url: '/pagesC/jipin/log' },
      { name: '叠加递减待返记录', url: '/pagesC/cashback/adduprecord' },
      { name: 'Adset广告单页', url: '/pagesC/ad/contentPage' },
      { name: '幸运免单', url: '/pagesC/luckyfree/index' },
      { name: '链动活动', url: '/pagesC/liandong/index' },
      { name: '激活币自动复购', url: '/pagesC/activeCoin/fugou' },
      { name: '积分互助活动', url: '/pagesC/networkhelp/index' },
      { name: '积分互助数据统计', url: '/pagesD/networkhelp/indexNew' },
      { name: '补贴积分明细', url: '/pagesD/networkhelp/butiescorelog' },
      { name: '消费补贴列表', url: '/pagesD/butieactivity/list' },
      { name: '消费补贴参与列表', url: '/pagesD/butieactivity/index' },
      { name: '零钱通', url: '/pagesD/commissionlingqiantong/index' },
      { name: '零钱通收益明细', url: '/pagesD/commissionlingqiantong/order' },
      { name: '发红包', url: '/pagesD/moneySendHongbao/sendhongbao' },
      { name: '发红包记录', url: '/pagesD/moneySendHongbao/hongbaolog' },
      { name: '爱心基金补助', url: '/pagesD/my/offlineSubsidies?ly_type=0' },
      { name: '升级补助', url: '/pagesD/my/offlineSubsidies?ly_type=1' },
      { name: '我的线下补助总览', url: '/pagesD/my/offlineSubsidiesOverview' },
      { name: '数字活动中心', url: '/adminExt/digitalconsum/index' },
      { name: '数字权益明细', url: '/pagesD/digitalconsum/digitalscorelog' },
      { name: '农场', url: '/pagesD/farm/landdetail' },
      { name: '营销红包', url: '/pagesD/yxredpacket/redpacket' },
      { name: '营销红包记录', url: '/pagesD/yxredpacket/redpacketlog' },
      { name: '工分记录', url: '/pagesD/workpoints/log' },
      { name: '消费返现明细', url: '/pagesD/xiaofeiCashback/adduprecord' },
      { name: '我的奖金池', url: '/pagesD/commission/myPrizePoolLog' },
    ],
  },
  {
    group: '会员中心',
    links: [
      { name: '会员中心', url: '/pages/my/my' },
      { name: '我的收藏', url: '/pagesExt/my/favorite' },
      { name: '我的足迹', url: '/pagesExt/my/history' },
      { name: '积分明细', url: '/pagesM/score/log' },
      { name: '会员充值', url: '/pagesM/recharge/index' },
      { name: '余额明细', url: '/pagesExt/money/moneylog' },
      { name: '余额提现', url: '/pagesExt/money/withdraw' },
      { name: '余额转账', url: '/pagesExt/money/rechargeToMember' },
      { name: '积分转赠', url: '/pagesExt/my/scoreTransfer' },
      { name: '服务费充值', url: '/pagesB/service_fee/recharge' },
      { name: '通证转赠', url: '/pagesA/my/tongzhengtransfer' },
      { name: '通证订单记录', url: '/pagesA/my/tongzhengorderlog' },
      { name: '个人设置', url: '/pagesM/member/setting' },
      { name: '支付密码', url: '/pagesExt/my/paypwd' },
      { name: '会员等级', url: '/pagesExt/my/levelinfo' },
      { name: '会员升级', url: '/pagesExt/my/levelup' },
      { name: '收货地址', url: '/pagesM/address/list' },
      { name: '领券中心', url: '/pagesM/coupon/center' },
      { name: '我的优惠券', url: '/pagesM/coupon/mine' },
      { name: '我的上下级', url: '/activity/commission/myteamline' },
      { name: '我的同级会员', url: '/activity/commission/mysameline' },
      { name: '我的好友', url: '/pagesA/friend/index' },
      { name: '用户登录', url: '/pages/login/login' },
      { name: '余额2明细', url: '/pagesExt/othermoney/moneylog?type=money2' },
      { name: '余额2提现', url: '/pagesExt/othermoney/withdraw?type=money2' },
      { name: '余额3明细', url: '/pagesExt/othermoney/moneylog?type=money3' },
      { name: '余额3提现', url: '/pagesExt/othermoney/withdraw?type=money3' },
      { name: '余额4明细', url: '/pagesExt/othermoney/moneylog?type=money4' },
      { name: '余额4提现', url: '/pagesExt/othermoney/withdraw?type=money4' },
      { name: '余额5明细', url: '/pagesExt/othermoney/moneylog?type=money5' },
      { name: '余额5提现', url: '/pagesExt/othermoney/withdraw?type=money5' },
      { name: '冻结金额明细', url: '/pagesExt/othermoney/frozen_moneylog' },
      { name: '档案列表', url: '/pagesExt/archives/index' },
      { name: '验光档案', url: '/pagesExt/glass/index' },
      { name: '收银台订单', url: '/pagesA/cashier/orderlist' },
      { name: '返现参与列表', url: '/pagesA/cashback/index' },
      { name: '返现释放明细', url: '/pagesC/releasePoints/details' },
      { name: '排队免单记录', url: '/pagesA/queueFree/index' },
      { name: '分红门店', url: '/pagesC/mendian/memberlevelupfenhong' },
      { name: '佣金上限转积分', url: '/pagesB/my/commissionmaxtoscore' },
      { name: '商城消费排名分红', url: '/pagesB/paimingfenhong/user' },
      { name: '商户打赏', url: '/pagesB/my/rewardToMember' },
      { name: '绿色积分明细', url: '/pagesB/my/greenscorelog' },
      { name: '绿色积分提现', url: '/pagesB/my/greenscoreWithdraw' },
      { name: '佣金转绿色积分', url: '/pagesB/greenscore/commissiontogreenscore' },
      { name: '绿色积分释放记录', url: '/pagesC/releasePoints/releasegreenscore' },
      { name: '我的商品收藏', url: '/pagesB/my/shopfavorite' },
      { name: '购买商户列表', url: '/pagesExt/business/blist?type=buylog' },
      { name: '奖金池', url: '/pagesC/bonuspoolgold/bonuspool' },
      { name: '奖金池中心', url: '/adminExt/bonuspoolgold/bonuspool' },
      { name: '车辆管理', url: '/pagesC/registervehicle/vehicleList' },
      { name: '站内信', url: '/pagesC/registervehicle/systemMsg' },
      { name: '社群服务业绩', url: '/pagesC/teamyeji/teamyejipv' },
      { name: '会员标签申请', url: '/pagesC/my/tagageapply' },
      { name: '团队数据', url: '/pagesC/my/team' },
      { name: '修改上级', url: '/pagesC/my/editParent' },
      { name: '消费排名', url: '/pagesD/subsidy/ranking' },
      { name: '返现积分日汇总', url: '/pagesD/subsidy/dayLog' },
      { name: '新积分记录', url: '/pagesD/newscore/ogLog' },
      { name: '红包明细', url: '/pagesD/hongbao/hongbaolog' },
      { name: '充值贴单费', url: '/pagesD/tiedan/tiedanrecharge' },
    ],
  },
  {
    group: '分销页面',
    links: [
      { name: '我的团队', url: '/activity/commission/myteam' },
      { name: '我的佣金', url: '/activity/commission/index' },
      { name: '分销订单', url: '/activity/commission/downorder' },
      { name: '佣金明细', url: '/activity/commission/commissionlog' },
      { name: '分享海报', url: '/activity/commission/poster' },
      { name: '分红记录', url: '/activity/commission/fhlog' },
      { name: '回本股东分红明细', url: '/pagesA/commission/fenhong_huiben' },
      { name: '佣金排行榜', url: '/activity/commission/commissionranking' },
      { name: '分红排行榜', url: '/pagesExt/commission/fenhongranking' },
      { name: '店铺分销', url: '/pagesA/businessFenxiao/shuju' },
      { name: '奖金池分红', url: '/pagesA/taocan/fenhong' },
      { name: '团队业绩排行榜', url: '/pagesB/team/teamyjranking' },
      { name: '申请合伙人', url: '/pagesA/regionpartner/apply' },
      { name: '申请合伙人记录', url: '/pagesA/regionpartner/partner' },
      { name: '合伙人分红记录', url: '/pagesA/regionpartner/fhlog' },
      { name: '区域代理排行榜', url: '/pagesA/commission/regionagent' },
      { name: '排行榜活动', url: '/pagesA/commission/ranking' },
      { name: '积分排行榜', url: '/pagesA/score/scoreranking' },
      { name: '消费排行榜', url: '/pagesB/shoporderranking/index' },
      { name: '消费排行榜记录', url: '/pagesB/shoporderranking/log' },
      { name: '视频号小店分销订单', url: '/pagesB/wxchannels/downorder' },
      { name: '团队业绩(统计)', url: '/pagesB/teamyejitongji/myteam' },
      { name: '团队业绩奖活动列表', url: '/pagesD/teamyeji/activitylist' },
      { name: '团队业绩', url: '/pagesD/teamyeji/myyeji' },
      { name: '分销数据统计', url: '/pagesC/transferorderparent/tongji' },
      { name: '链动数据', url: '/pagesC/my/liandong' },
      { name: '推荐商家', url: '/pagesExt/commission/tjbusinessList' },
      { name: '推荐商家统计', url: '/pagesD/commission/tjbusinessCount' },
      { name: '推荐商家海报', url: '/pagesD/recommendbusiness/poster' },
      { name: '商家推广中心', url: '/pagesD/commission/tjbusiness' },
      { name: '区域商家中心', url: '/pagesD/commission/areatjbusiness' },
      { name: '会员区域商家中心', url: '/pagesD/commission/memberAreatjbusiness' },
      { name: '佣金分账记录', url: '/pagesD/commission/commissionWxfzRecord' },
    ],
  },
  {
    group: '餐饮页面',
    links: [
      { name: '外卖', url: '/restaurant/index/index' },
      { name: '外卖订单', url: '/restaurant/order/list' },
      { name: '点餐', url: '/restaurant/index/index' },
      { name: '点餐订单', url: '/restaurant/order/list' },
      { name: '预定', url: '/restaurant/booking/add?bid=0' },
      { name: '预定记录', url: '/restaurant/booking/orderlist' },
      { name: '排队', url: '/restaurant/queue/index?bid=0' },
      { name: '排队记录', url: '/restaurant/queue/record?bid=0' },
      { name: '寄存', url: '/restaurant/deposit/orderdetail' },
    ],
  },
  {
    group: '酒店页面',
    links: [
      { name: '酒店首页', url: '/hotel/index/index' },
      { name: '酒店列表', url: '/hotel/index/index' },
      { name: '酒店订单', url: '/hotel/order/list' },
    ],
  },
  {
    group: '商户页面',
    links: [
      { name: '商户入驻', url: '/pagesExt/business/apply' },
      { name: '商户列表', url: '/pagesExt/business/blist' },
      { name: '分类商户', url: '/pagesExt/business/clist' },
      { name: '首消店铺', url: '/pagesExt/business/index?type=firstbuy' },
      { name: '常用店铺', url: '/pagesExt/business/index?type=lastbuy' },
      { name: '附近门店', url: '/pagesExt/business/mendian?bid=0' },
      { name: '充值仓储费', url: '/adminExt/storemoney/rechargestoremoney' },
      { name: '推荐商家', url: '/pagesD/tjbusiness/tjbusinesslist' },
    ],
  },
  {
    group: '财务页面',
    links: [
      { name: '买单收款', url: '/pagesB/maidan/pay' },
      { name: '买单记录', url: '/pagesB/maidan/maidanlog' },
      { name: '新买单收款', url: '/pagesC/maidan/pay' },
      { name: '新买单记录', url: '/pagesC/maidan/maidanlog' },
      { name: '充值业绩页面', url: '/pagesA/rechargeyj/index' },
      { name: '充值业绩明细', url: '/pagesA/rechargeyj/log' },
      { name: '充值业绩提现', url: '/pagesA/rechargeyj/withdraw' },
      { name: '汇付进件', url: '/pagesExt/my/sethuifuinfo' },
      { name: '押金列表', url: '/pagesD/deposit/index' },
      { name: '电子水票', url: '/pagesD/deposit/couponlist?bid=0' },
    ],
  },
  {
    group: '其他页面',
    links: [
      { name: '表单提交记录', url: '/pagesA/form/formlog' },
      { name: '文章列表', url: '/pagesC2/article/list' },
      { name: '文章资源列表', url: '/pagesExt/article/record' },
      { name: '礼品卡兑换', url: '/pagesExt/lipin/index' },
      { name: '兑换卡兑换', url: '/pagesC/exchangecard/index' },
      { name: '新兑换卡兑换', url: '/pagesD/exchangecard/index' },
      { name: '用户论坛', url: '/pagesC2/luntan/index' },
      { name: '论坛发帖记录', url: '/pagesC2/luntan/myfatie' },
      { name: '论坛关注记录', url: '/activity/luntan/focuslog' },
      { name: '论坛横版分类', url: '/pagesC2/luntan/list' },
      { name: '论坛竖版分类', url: '/pagesC2/luntan/list' },
      { name: '预约服务', url: '/yuyue/index/index' },
      { name: '预约订单', url: '/yuyue/order/list' },
      { name: '师傅列表', url: '/yuyue/yuyue/peolist2?bid=0' },
      { name: '预约师傅申请', url: '/yuyue/yuyue/apply?bid=0' },
      { name: '课程分类', url: '/pagesC2/kecheng/list' },
      { name: '课程分类2', url: '/pagesC2/kecheng/list' },
      { name: '课程列表', url: '/pagesC2/kecheng/list' },
      { name: '非讲师课程列表', url: '/pagesC2/kecheng/list' },
      { name: '讲师课程列表', url: '/pagesC2/kecheng/list' },
      { name: '申请讲师', url: '/pagesC2/kecheng/lecturer/apply' },
      { name: '讲师主页', url: '/pagesC2/kecheng/lecturer/center' },
      { name: '讲师列表', url: '/pagesC2/kecheng/lecturer/list' },
      { name: '我的课程', url: '/pagesC2/kecheng/orderlist' },
      { name: '短视频', url: '/pagesC2/shortvideo/index' },
      { name: '查寄快递', url: '/pagesB/express/index' },
      { name: '工单类型', url: '/pagesA/workorder/category' },
      { name: '提交工单', url: '/pagesB/workorder/index' },
      { name: '工单记录', url: '/pagesB/workorder/record' },
      { name: '余额宝', url: '/activity/yuebao/index' },
      { name: '图搜', url: '/pagesExt/shop/imgsearch?bid=0' },
      { name: '配灯', url: '/pagesExt/shop/diylight?bid=0' },
      { name: '元宝', url: '/pagesExt/yuanbao/yuanbaolog' },
      { name: '装修计算器', url: '/pagesExt/renovation/form' },
      { name: '律师费计算器', url: '/pagesExt/counsel/index' },
      { name: '诉讼费计算器', url: '/pagesExt/legal/index' },
      { name: '教练界面', url: '/pagesB/yueke/my' },
      { name: '约课列表', url: '/pagesExt/yueke/prolist' },
      { name: '约课记录', url: '/pagesExt/yueke/orderlist' },
      { name: '约车列表', url: '/carhailing/index/index' },
      { name: '约车记录', url: '/carhailing/order/list' },
      { name: '约车分类列表', url: '/carhailing/index/index' },
      { name: '名片详情', url: '/pagesExt/mingpian/index' },
      { name: '创建名片', url: '/pagesExt/mingpian/edit' },
      { name: '名片夹', url: '/pagesExt/mingpian/favorite' },
      { name: '名片列表', url: '/pagesC/mingpian/list' },
      { name: '周期购商品', url: '/pagesExt/cycle/prolist' },
      { name: '周期购订单', url: '/pagesExt/cycle/orderList' },
      { name: '会员卡分享', url: '/pagesB/membercard/share' },
      { name: '广告任务', url: '/pagesExt/taskbanner/index' },
      { name: '广告任务记录', url: '/pagesExt/taskbanner/record' },
      { name: '跑腿页面', url: '/pagesExt/paotui/index' },
      { name: '跑腿订单列表', url: '/pagesExt/paotui/orderlist' },
      { name: '活动列表(旅游)', url: '/pagesA/tour/index' },
      { name: '活动订单列表', url: '/pagesA/tour/orderlist' },
      { name: '申请导游', url: '/pagesA/tour/apply' },
      { name: '礼包列表', url: '/pagesA/giftbag/index' },
      { name: '礼包订单列表', url: '/pagesA/giftbag/orderlist' },
      { name: '车牌信息', url: '/pagesA/yuyuecar/car' },
      { name: '回寄总列表', url: '/pagesA/handwork/list' },
      { name: '抖音团购核销', url: '/pagesA/douyin_groupbuy/index' },
      { name: '活动报名分类列表', url: '/pagesB/huodongbaoming/prolist' },
      { name: '活动列表(报名)', url: '/pagesB/huodongbaoming/prolist2' },
      { name: '活动报名订单', url: '/pagesB/huodongbaoming/orderlist' },
      { name: '多商户免单订单', url: '/pagesB/businessmiandan/orderlist' },
      { name: '每日金句', url: '/pagesB/daily/wqlist' },
      { name: '库存记录', url: '/pagesB/ciruikang/stocklog' },
      { name: '我的店铺', url: '/pagesB/business/mybusiness' },
      { name: '百度AI绘画', url: '/pagesExt/imgai/create' },
      { name: '地图标注', url: '/pagesExt/mapmark/edit' },
      { name: '短视频去水印', url: '/pagesExt/videospider/analysis' },
      { name: '量表列表', url: '/pagesA/health/index' },
      { name: '量表记录', url: '/pagesA/health/record' },
      { name: '随行付入驻', url: '/pagesA/sxpay/apply' },
      { name: '随行付入驻记录', url: '/pagesA/sxpay/myapply' },
      { name: '智能开门', url: '/pagesZ/cerberuse/index' },
      { name: '智能开门订单列表', url: '/pagesZ/cerberuse/orderlist' },
      { name: '贡献值提现', url: '/pagesA/bonuspool/withdraw' },
      { name: '贡献值提现记录', url: '/pagesA/bonuspool/commissionlog' },
      { name: '销售优惠券', url: '/pagesA/xianxia/mycoupon' },
      { name: '线下优惠券打款记录', url: '/pagesA/xianxia/commissionlog' },
      { name: '线下优惠券提现记录', url: '/pagesA/xianxia/membercommissionlog' },
      { name: '证书列表', url: '/pagesA/certificate/index' },
      { name: '我的证书列表', url: '/pagesA/certificate/mylist' },
      { name: '证书编辑', url: '/pagesA/certificate/edit' },
      { name: '海康监控', url: '/pagesA/hikvision/hikvisionlist' },
      { name: '门店列表', url: '/pagesA/mendian/list' },
      { name: '售卖列表', url: '/pagesA/levelupauth/salelist' },
      { name: '售卖订单', url: '/pagesA/levelupauth/saleorderlist' },
      { name: '购买订单', url: '/pagesA/levelupauth/buyorderlist' },
      { name: '礼包列表(升级)', url: '/pagesA/giftpack/list' },
      { name: '银行卡列表', url: '/pagesA/banklist/bank' },
      { name: '门店申请', url: '/pagesA/mendianup/apply' },
      { name: '门店中心', url: '/pagesA/mendianup/apply' },
      { name: '门店核销二维码', url: '/pagesC/mendian/hxqrcode' },
      { name: '门店入驻', url: '/pagesD/mendian/apply' },
      { name: '帮助中心', url: '/pagesA/helpnew/lists' },
      { name: 'pos设备中心', url: '/pagesB/posscore/detail' },
      { name: '员工提成记录', url: '/pagesB/my/staffcommissionlog' },
      { name: '场地管理', url: '/pagesB/fishpond/list' },
      { name: '场地订单', url: '/pagesB/fishpond/orderlist' },
      { name: '采购单', url: '/pagesB/purchaseorder/index' },
      { name: '银币记录', url: '/pagesB/my/silvermoneylog' },
      { name: '金币记录', url: '/pagesB/my/goldmoneylog' },
      { name: '直播列表', url: '/h5zb/client/zblist' },
      { name: '邀请分红包列表', url: '/pagesB/inviteredpacket/redpacketlist' },
      { name: '教练登录', url: '/pagesExt/yueke/workerorderlist' },
      { name: '教练入驻', url: '/pagesB/yueke/apply' },
      { name: '证书查询', url: '/pagesB/certificatequery/index' },
      { name: '素材库(B端)', url: '/pagesB/material/index' },
      { name: '素材库', url: '/pagesD/material/index' },
      { name: '收藏素材', url: '/pagesD/material/collect' },
      { name: '发票报销', url: '/pagesC/invoicebaoxiao/index' },
      { name: '发票报销记录', url: '/pagesC/invoicebaoxiao/recordlist' },
      { name: '我的产品积分', url: '/pagesC/my/shopscorelog' },
      { name: '我的升级积分', url: '/pagesC/my/upgradescorelog' },
      { name: '冻结佣金记录', url: '/pagesC/my/forzengxcommissionlog' },
      { name: '合成余额', url: '/pagesC/my/combinemoney' },
      { name: '合成余额记录', url: '/pagesC/my/combinemoneylog' },
      { name: '佣金转赠', url: '/pagesC/commission/commissionTransfer' },
      { name: '碳表单', url: '/pagesC/formtan/index' },
      { name: '国补申请', url: '/pagesC/subsidy/index' },
      { name: '基金池', url: '/pagesC/fundpool/index' },
      { name: '通联企业会员', url: '/pagesC/allinpay/yunstMemberCompany' },
      { name: '排单开店', url: '/pagesC/planorder/index' },
      { name: '自己店铺', url: '/pagesC/planorder/shop' },
      { name: '级差奖励明细', url: '/pagesC/my/jichamoneylog' },
      { name: '级差奖励提现', url: '/pagesC/my/jichamoneywithdraw' },
      { name: '机票', url: '/pagesC/hanglvfeike/index' },
      { name: '机票列表', url: '/pagesC/hanglvfeike/orderlist' },
      { name: '机票用户', url: '/pagesC/hanglvfeike/user' },
      { name: '申请达人', url: '/pagesC/businessExpert/apply' },
      { name: '达人记录列表', url: '/pagesC/businessExpert/expertlist' },
      { name: '水卡绑定', url: '/pagesC/water/card' },
      { name: '美团购票', url: '/pagesD/meituan/index' },
      { name: '美团购票-门票', url: '/pagesD/meituan/index?ty=1' },
      { name: '美团购票-酒店', url: '/pagesD/meituan/index?ty=2' },
      { name: '美团购票-套餐', url: '/pagesD/meituan/index?ty=3' },
      { name: '美团订单', url: '/pagesD/meituan/orderlist' },
      { name: '票务演出列表', url: '/pagesC/zhiyoubao/index' },
      { name: '票务订单列表', url: '/pagesC/zhiyoubao/orderlist' },
      { name: '现金明细', url: '/pagesD/my/xianjinlog' },
      { name: '现金充值', url: '/pagesD/my/xianjinrecharge' },
      { name: '现金提现', url: '/pagesD/my/xianjinwithdraw' },
      { name: '我的打水订单', url: '/pagesD/water/orderList' },
      { name: '利润抽奖', url: '/pagesH3/choujiang/index' },
      { name: '门店投资', url: '/pagesD/businessShareholder/select' },
      { name: '投资记录', url: '/pagesD/businessShareholder/applylog' },
      { name: '腾讯电子签记录', url: '/pagesD/tencent_qian/qianlog' },
      { name: '个人团队', url: '/pagesD/commission/teamManage' },
      { name: '物联网设备订单', url: '/pagesD/lotdevice/orderlist' },
      { name: '绑定物联网设备', url: '/adminExt/lotdevice/bind' },
    ],
  },
  {
    group: '后台页面',
    links: [
      { name: '手机端后台', url: '/admin/index/index' },
      { name: '配送员抢单大厅', url: '/activity/peisong/dating' },
      { name: '预约服务人员', url: '/yuyue/yuyue/my' },
      { name: '在线咨询', url: '/pagesB/kefu/index' },
      { name: '商品柜设备', url: '/pagesB/admin/pickupdevice' },
      { name: '商户进件', url: '/adminExt/businessjinjian/index' },
    ],
  },
  {
    group: '生活缴费',
    links: [
      { name: '话费充值', url: '/pagesA/livepay/mobile_recharge?type=phone' },
      { name: '电费', url: '/pagesA/livepay/ordinary_recharge?type=electric' },
      { name: '水费', url: '/pagesA/livepay/ordinary_recharge?type=water' },
      { name: '燃气费用', url: '/pagesA/livepay/ordinary_recharge?type=gas' },
      { name: '供暖费', url: '/pagesA/livepay/ordinary_recharge?type=heating' },
      { name: '充电桩电费', url: '/pagesA/livepay/ordinary_recharge?type=charging' },
      { name: '缴费订单记录', url: '/pagesA/livepay/record_recharge?type=all' },
    ],
  },
]

/** 动态 Tab（对齐参考系统 Tab 顺序；有接口的走现有 admin 列表接口，无接口的静态空态） */
const DYNAMIC_TABS: DynamicTab[] = [
  {
    key: 'shop_category',
    label: '商品分类',
    categoryForms: true,
    loadAll: async () => {
      const data = await getDesignerChooseUrl()
      return flattenCategoryTree(
        data.shop_category,
        (id) => `/pages/category/category?cid=${id}`,
        '分类>',
      )
    },
  },
  { key: 'shop_group', label: '商品分组', staticTip: '按商品分组生成 /pagesM/shop/prolist?gid={分组ID}，商品分组管理功能接口待接入' },
  {
    key: 'goods',
    label: '商城商品',
    load: async (page, keyword) =>
      toDynResult(
        await getGoodsList({ page, pagesize: PAGE_SIZE, keyword: keyword || undefined }),
        (id) => `/pagesM/product/detail?id=${id}`,
        '商城商品>',
      ),
  },
  {
    key: 'collage',
    label: '拼团商品',
    load: async (page, keyword) =>
      toDynResult(
        await getCollageProductList({ page, pagesize: PAGE_SIZE, keyword: keyword || undefined }),
        (id) => `/pagesH1/collage/detail?id=${id}`,
        '拼团商品>',
      ),
  },
  {
    key: 'kanjia',
    label: '砍价商品',
    load: async (page, keyword) =>
      toDynResult(
        await getKanjiaProductList({ page, pagesize: PAGE_SIZE, keyword: keyword || undefined }),
        (id) => `/pagesH2/kanjia/detail?id=${id}`,
        '砍价商品>',
      ),
  },
  {
    key: 'scoreshop',
    label: '积分兑换',
    load: async (page, keyword) =>
      toDynResult(
        await getScoreshopProductList({ page, pagesize: PAGE_SIZE, keyword: keyword || undefined }),
        (id) => `/pagesH2/scoreshop/detail?id=${id}`,
        '积分兑换>',
      ),
  },
  {
    key: 'article_category',
    label: '文章分类',
    loadAll: async () => {
      const data = await getDesignerChooseUrl()
      return flattenCategoryTree(
        data.article_category,
        (id) => `/pagesC2/article/list?cid=${id}`,
        '文章分类>',
      )
    },
  },
  {
    key: 'article',
    label: '文章',
    load: async (page, keyword) =>
      toDynResult(
        await getArticleList({ page, pagesize: PAGE_SIZE, keyword: keyword || undefined }),
        (id) => `/pagesC2/article/detail?id=${id}`,
        '文章>',
      ),
  },
  { key: 'business_category', label: '商户分类', staticTip: '商户列表 /pagesExt/business/blist?cid={分类ID}、分类商户 /pagesExt/business/clist?cid={分类ID}，商户分类功能接口待接入' },
  { key: 'business', label: '商户', staticTip: '商户主页 /pagesExt/business/index?id={商户ID}，商户列表功能接口待接入' },
  { key: 'huodong_category', label: '活动分类', staticTip: '活动报名列表 /pagesB/huodongbaoming/prolist2?cid={分类ID}，活动报名分类功能接口待接入' },
  { key: 'huodong', label: '活动列表', staticTip: '活动详情 /pagesB/huodongbaoming/product?id={活动ID}，活动报名列表功能接口待接入' },
  { key: 'hotel_category', label: '酒店分类', staticTip: '酒店列表 /hotel/index/hotellist?cateid={分类ID}，酒店分类功能接口待接入' },
  { key: 'hotel', label: '酒店列表', staticTip: '酒店详情 /hotel/index/hotel?id={酒店ID}，酒店列表功能接口待接入' },
  {
    key: 'designer_page',
    label: '设计页面',
    load: async (page, keyword) =>
      toDynResult(
        await getDesignerPageList({ page, limit: PAGE_SIZE, name: keyword || undefined }),
        (id) => `/pages/designer/custom?id=${id}`,
        '设计页面>',
      ),
  },
  { key: 'function', label: '功能', staticTip: '参考系统「功能」为拨打电话/客服/分享/扫一扫/坐标导航/外部链接等非页面功能，不纳入页面链接目录' },
  { key: 'restaurant_product', label: '外卖商品', staticTip: '外卖商品详情 /restaurant/takeaway/product?id={商品ID}，外卖商品列表功能接口待接入' },
  { key: 'cycle', label: '周期购商品', staticTip: '周期购商品详情 /pagesExt/cycle/product?id={商品ID}，周期购功能接口待接入' },
  {
    key: 'tuangou',
    label: '团购商品',
    load: async (page, keyword) =>
      toDynResult(
        await getTuangouProductList({ page, pagesize: PAGE_SIZE, keyword: keyword || undefined }),
        (id) => `/pagesH3/tuangou/detail?id=${id}`,
        '团购商品>',
      ),
  },
  {
    key: 'luckycollage',
    label: '幸运拼团商品',
    load: async (page, keyword) =>
      toDynResult(
        await getLuckyCollageProductList({
          page,
          pagesize: PAGE_SIZE,
          keyword: keyword || undefined,
        }),
        (id) => `/pagesH1/luckycollage/detail?id=${id}`,
        '幸运拼团商品>',
      ),
  },
  {
    key: 'luckycollage_category',
    label: '幸运拼团分类',
    loadAll: async () => {
      const res = await getLuckyCollageCategoryList()
      return rowsToLinks(
        (res as { list?: unknown }).list,
        (id) => `/pagesH1/luckycollage/list?cid=${id}`,
        '幸运拼团分类>',
      )
    },
  },
  {
    key: 'kecheng_category',
    label: '课程分类',
    loadAll: async () => {
      const data = await getDesignerChooseUrl()
      return flattenCategoryTree(
        data.kecheng_category,
        (id) => `/pagesC2/kecheng/list?cid=${id}`,
        '课程分类>',
      )
    },
  },
  {
    key: 'kecheng',
    label: '课程列表',
    load: async (page, keyword) =>
      toDynResult(
        await getKechengCourseList({ page, pagesize: PAGE_SIZE, keyword: keyword || undefined }),
        (id) => `/pagesC2/kecheng/detail?id=${id}`,
        '课程列表>',
      ),
  },
  { key: 'form', label: '表单数据', staticTip: '表单填写 /pagesA/form/index?id={表单ID}，表单管理功能接口待接入' },
  { key: 'lotdevice', label: '物联网设备', staticTip: '物联网设备详情，物联网设备功能接口待接入' },
]

const activeTab = ref('base')
const dynamicLoading = ref(false)

// ============ 动态Tab搜索/分页状态（任务295：顶部搜索框+关键词过滤+分页每页20条） ============
const dynKeyword = ref('')
const dynPage = ref(1)
const dynLinks = ref<FrontendLink[]>([])
const dynTotal = ref(0)
/** 分类树等全量Tab的本地缓存（前端过滤分页） */
const dynLocalCache = reactive<Record<string, FrontendLink[]>>({})
let dynLoadToken = 0

const currentTab = computed(() => DYNAMIC_TABS.find((t) => t.key === activeTab.value) || null)

async function loadDynamic(force = false) {
  const tab = currentTab.value
  if (!tab || tab.staticTip) return
  const token = ++dynLoadToken
  dynamicLoading.value = true
  try {
    if (tab.load) {
      const r = await tab.load(dynPage.value, dynKeyword.value.trim())
      if (token !== dynLoadToken) return
      dynLinks.value = r.links
      dynTotal.value = r.total
    } else if (tab.loadAll) {
      if (force || !dynLocalCache[tab.key]) {
        dynLocalCache[tab.key] = await tab.loadAll()
      }
      if (token !== dynLoadToken) return
      const all = dynLocalCache[tab.key]
      const kw = dynKeyword.value.trim()
      const filtered = kw ? all.filter((l) => l.name.includes(kw)) : all
      dynTotal.value = filtered.length
      dynLinks.value = filtered.slice((dynPage.value - 1) * PAGE_SIZE, dynPage.value * PAGE_SIZE)
    }
  } catch {
    if (token !== dynLoadToken) return
    dynLinks.value = []
    dynTotal.value = 0
  } finally {
    if (token === dynLoadToken) dynamicLoading.value = false
  }
}

function switchTab(key: string) {
  activeTab.value = key
  dynKeyword.value = ''
  dynPage.value = 1
  if (key !== 'base') loadDynamic(true)
}

function onDynSearch() {
  dynPage.value = 1
  loadDynamic()
}

function onDynPageChange(p: number) {
  dynPage.value = p
  loadDynamic()
}

watch(
  () => props.visible,
  (v) => {
    if (v) {
      centerPanel()
      switchTab('base')
    }
  },
)

// ============ 商品分类二级弹窗（五形态单选，任务295） ============
const CATEGORY_FORMS = [
  { value: 'prolist', label: '商品列表', path: '/pagesM/shop/prolist' },
  { value: 'classify', label: '分类商品', path: '/pages/category/category' },
  { value: 'classify2', label: '分类商品2', path: '/pages/category/category' },
  { value: 'fastbuy', label: '快速购买', path: '/pagesM/shop/fastbuy' },
  { value: 'fastbuy2', label: '快速购买2', path: '/pagesM/shop/fastbuy2' },
]
const catDlg = reactive({ visible: false, cid: 0, name: '' })
const catForm = ref(CATEGORY_FORMS[0].value)
const catFormCurrent = computed(
  () => CATEGORY_FORMS.find((v) => v.value === catForm.value) || CATEGORY_FORMS[0],
)

function onDynLinkClick(link: FrontendLink) {
  if (currentTab.value?.categoryForms) {
    const m = /cid=(\d+)/.exec(link.url)
    catDlg.cid = m ? Number(m[1]) : 0
    catDlg.name = link.rawName || link.name
    catForm.value = CATEGORY_FORMS[0].value
    catDlg.visible = true
    return
  }
  handleLink(link)
}

function confirmCategoryForm() {
  const url = `${catFormCurrent.value.path}?cid=${catDlg.cid}`
  const name = `分类>${catDlg.name}`
  catDlg.visible = false
  if (props.mode === 'pick') {
    emit('pick', url, name)
    close()
    return
  }
  addrTarget.name = name
  addrTarget.path = url
  addrVisible.value = true
}

// ============ 点击链接 ============
const addrVisible = ref(false)
const addrTarget = reactive({ name: '', path: '' })

function handleLink(link: FrontendLink) {
  if (props.mode === 'pick') {
    emit('pick', link.url, link.name)
    close()
    return
  }
  addrTarget.name = link.name
  addrTarget.path = link.url
  addrVisible.value = true
}
</script>

<template>
  <!-- 页面内嵌形态：无遮罩无外壳，目录内容直接铺在页面容器内 -->
  <div v-if="embed" class="fl-embed">
    <div class="fl-tabs">
      <div
        class="fl-tab"
        :class="{ active: activeTab === 'base' }"
        @click="switchTab('base')"
      >
        基础功能
      </div>
      <div
        v-for="tab in DYNAMIC_TABS"
        :key="tab.key"
        class="fl-tab"
        :class="{ active: activeTab === tab.key }"
        @click="switchTab(tab.key)"
      >
        {{ tab.label }}
      </div>
    </div>
    <div v-loading="dynamicLoading" class="fl-body">
      <template v-if="activeTab === 'base'">
        <div v-for="g in STATIC_GROUPS" :key="g.group" class="fl-group">
          <div class="fl-group-title">{{ g.group }}</div>
          <div class="fl-btn-matrix">
            <button
              v-for="(link, li) in g.links"
              :key="link.url + li"
              class="fl-link-btn"
              :title="link.url"
              @click="handleLink(link)"
            >
              {{ link.name }}
            </button>
          </div>
        </div>
      </template>
      <template v-else>
        <div v-if="currentTab?.staticTip" class="fl-empty">{{ currentTab.staticTip }}</div>
        <template v-else>
          <div class="fl-search">
            <el-input
              v-model="dynKeyword"
              class="fl-search-input"
              placeholder="请输入关键词"
              clearable
              @keyup.enter="onDynSearch"
              @clear="onDynSearch"
            />
            <el-button type="primary" @click="onDynSearch">搜索</el-button>
          </div>
          <div v-if="dynLinks.length" class="fl-group fl-dyn-card">
            <div class="fl-group-title">{{ currentTab?.label }}</div>
            <div class="fl-btn-matrix">
              <button
                v-for="(link, li) in dynLinks"
                :key="link.url + li"
                class="fl-link-btn"
                :title="link.url"
                @click="onDynLinkClick(link)"
              >
                {{ link.name }}
              </button>
            </div>
          </div>
          <div v-else-if="!dynamicLoading" class="fl-empty">暂无数据</div>
          <div v-if="dynTotal > PAGE_SIZE" class="fl-pager">
            <el-pagination
              small
              background
              layout="total, prev, pager, next"
              :total="dynTotal"
              :page-size="PAGE_SIZE"
              :current-page="dynPage"
              @current-change="onDynPageChange"
            />
          </div>
        </template>
      </template>
    </div>
    <!-- 链接地址小弹窗（二维码/链接地址/页面路径，可拖拽） -->
    <LinkAddressModal
      v-model:visible="addrVisible"
      :name="addrTarget.name"
      :path="addrTarget.path"
    />
  </div>

  <!-- 弹窗形态（既有 view/pick 消费方不受影响） -->
  <Teleport v-else to="body">
    <div v-if="visible" class="fl-mask" @click.self="close">
      <div
        class="fl-panel"
        :style="{ left: pos.x + 'px', top: pos.y + 'px', width: size.w + 'px', height: size.h + 'px' }"
      >
        <div class="fl-header" @mousedown="onDragStart">
          <span class="fl-title">{{ mode === 'pick' ? '选择链接' : '查看链接' }}</span>
          <span class="fl-close" title="关闭" @click="close">&times;</span>
        </div>

        <div class="fl-tabs">
          <div
            class="fl-tab"
            :class="{ active: activeTab === 'base' }"
            @click="switchTab('base')"
          >
            基础功能
          </div>
          <div
            v-for="tab in DYNAMIC_TABS"
            :key="tab.key"
            class="fl-tab"
            :class="{ active: activeTab === tab.key }"
            @click="switchTab(tab.key)"
          >
            {{ tab.label }}
          </div>
        </div>

        <div v-loading="dynamicLoading" class="fl-body">
          <!-- 基础功能：分组标题 + 按钮矩阵（参考系统全量目录） -->
          <template v-if="activeTab === 'base'">
            <div v-for="g in STATIC_GROUPS" :key="g.group" class="fl-group">
              <div class="fl-group-title">{{ g.group }}</div>
              <div class="fl-btn-matrix">
                <button
                  v-for="(link, li) in g.links"
                  :key="link.url + li"
                  class="fl-link-btn"
                  :title="link.url"
                  @click="handleLink(link)"
                >
                  {{ link.name }}
                </button>
              </div>
            </div>
          </template>

          <!-- 动态 Tab：搜索框+卡片列表+分页 / 无接口静态空态（任务295） -->
          <template v-else>
            <div v-if="currentTab?.staticTip" class="fl-empty">{{ currentTab.staticTip }}</div>
            <template v-else>
              <div class="fl-search">
                <el-input
                  v-model="dynKeyword"
                  class="fl-search-input"
                  placeholder="请输入关键词"
                  clearable
                  @keyup.enter="onDynSearch"
                  @clear="onDynSearch"
                />
                <el-button type="primary" @click="onDynSearch">搜索</el-button>
              </div>
              <div v-if="dynLinks.length" class="fl-group fl-dyn-card">
                <div class="fl-group-title">{{ currentTab?.label }}</div>
                <div class="fl-btn-matrix">
                  <button
                    v-for="(link, li) in dynLinks"
                    :key="link.url + li"
                    class="fl-link-btn"
                    :title="link.url"
                    @click="onDynLinkClick(link)"
                  >
                    {{ link.name }}
                  </button>
                </div>
              </div>
              <div v-else-if="!dynamicLoading" class="fl-empty">暂无数据</div>
              <div v-if="dynTotal > PAGE_SIZE" class="fl-pager">
                <el-pagination
                  small
                  background
                  layout="total, prev, pager, next"
                  :total="dynTotal"
                  :page-size="PAGE_SIZE"
                  :current-page="dynPage"
                  @current-change="onDynPageChange"
                />
              </div>
            </template>
          </template>
        </div>
      </div>
    </div>

    <!-- 查看模式：链接地址小弹窗 -->
    <LinkAddressModal
      v-model:visible="addrVisible"
      :name="addrTarget.name"
      :path="addrTarget.path"
    />
  </Teleport>

  <!-- 商品分类二级弹窗：五形态单选，确认回填带 cid 路径（view/pick/embed 三形态通用） -->
  <div v-if="catDlg.visible" class="flc-mask" @click.self="catDlg.visible = false">
    <div class="flc-panel">
      <div class="flc-header">
        <span class="flc-title">请选择链接页面形态<span class="flc-name">（{{ catDlg.name }}）</span></span>
        <span class="flc-close" title="关闭" @click="catDlg.visible = false">&times;</span>
      </div>
      <div class="flc-body">
        <el-radio-group v-model="catForm" class="flc-forms">
          <div v-for="f in CATEGORY_FORMS" :key="f.value" class="flc-form-item">
            <el-radio :value="f.value">{{ f.label }}</el-radio>
          </div>
        </el-radio-group>
        <div class="flc-tip">确认后将回填：{{ catFormCurrent.path }}?cid={{ catDlg.cid }}</div>
      </div>
      <div class="flc-footer">
        <el-button size="small" @click="catDlg.visible = false">取消</el-button>
        <el-button size="small" type="primary" @click="confirmCategoryForm">确定</el-button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fl-mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  z-index: 3000;
}

.fl-panel {
  position: absolute;
  background: #fff;
  border-radius: 6px;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.fl-header {
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  background: #f7f8fa;
  border-bottom: 1px solid #ebeef5;
  cursor: move;
  user-select: none;
  flex-shrink: 0;
}

.fl-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
}

.fl-close {
  font-size: 20px;
  line-height: 1;
  color: #909399;
  cursor: pointer;
  padding: 2px 6px;
}

.fl-close:hover {
  color: #f56c6c;
}

.fl-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 2px;
  padding: 10px 16px 0;
  border-bottom: 1px solid #ebeef5;
  flex-shrink: 0;
}

.fl-tab {
  padding: 6px 12px;
  font-size: 13px;
  color: #606266;
  cursor: pointer;
  border-radius: 4px 4px 0 0;
  white-space: nowrap;
}

.fl-tab:hover {
  color: var(--el-color-primary);
}

.fl-tab.active {
  color: var(--el-color-primary);
  font-weight: 600;
  background: #ecf5ff;
}

.fl-body {
  flex: 1;
  overflow-y: auto;
  padding: 12px 16px 16px;
}

.fl-group {
  margin-bottom: 14px;
}

.fl-group-title {
  font-size: 13px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 8px;
}

.fl-btn-matrix {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.fl-link-btn {
  min-width: 92px;
  max-width: 180px;
  padding: 6px 12px;
  font-size: 12px;
  color: #606266;
  background: #fff;
  border: 1px solid #e7e7eb;
  border-radius: 4px;
  cursor: pointer;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.fl-link-btn:hover {
  color: var(--el-color-primary);
  border-color: var(--el-color-primary);
  background: #f5f9ff;
}

.fl-empty {
  padding: 40px 20px;
  text-align: center;
  color: #909399;
  font-size: 13px;
  line-height: 1.8;
}

/* 页面内嵌形态外壳（任务288视觉对照：浅灰底托出白色分组卡片） */
.fl-embed {
  background: #f5f7fa;
  border: 1px solid #ebeef5;
  border-radius: 8px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.fl-embed .fl-tabs {
  background: #fff;
  padding: 12px 16px 0;
}

.fl-embed .fl-body {
  min-height: 480px;
  max-height: 640px;
  padding: 16px;
}

/* 分组卡片化：白底圆角浅影 + 标题行（左侧色条标识） */
.fl-embed .fl-group {
  background: #fff;
  border: 1px solid #ebeef5;
  border-radius: 8px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
  padding: 14px 16px;
  margin-bottom: 14px;
}

.fl-embed .fl-group-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  margin-bottom: 12px;
  padding-bottom: 10px;
  border-bottom: 1px solid #f2f3f5;
}

.fl-embed .fl-group-title::before {
  content: '';
  width: 6px;
  height: 16px;
  border-radius: 3px;
  background: var(--el-color-primary);
  flex-shrink: 0;
}

/* 链接改行内文字流式排布：去边框 + 圆点前缀 + 悬浮变色 */
.fl-embed .fl-btn-matrix {
  gap: 8px 0;
}

.fl-embed .fl-link-btn {
  min-width: 0;
  max-width: 220px;
  padding: 4px 12px;
  font-size: 13px;
  color: #515a6e;
  background: transparent;
  border: none;
  border-radius: 4px;
}

.fl-embed .fl-link-btn::before {
  content: '';
  display: inline-block;
  width: 4px;
  height: 4px;
  border-radius: 50%;
  background: #c0c4cc;
  margin-right: 6px;
  vertical-align: 2px;
}

.fl-embed .fl-link-btn:hover {
  color: var(--el-color-primary);
  background: #ecf5ff;
  border-color: transparent;
}

.fl-embed .fl-link-btn:hover::before {
  background: var(--el-color-primary);
}

.fl-embed .fl-empty {
  background: #fff;
  border: 1px solid #ebeef5;
  border-radius: 8px;
}

/* ============ 动态Tab搜索/分页/卡片（任务295） ============ */
.fl-search {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}

.fl-search-input {
  width: 240px;
}

.fl-pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

/* 动态Tab卡片：弹窗/内嵌形态统一白底分组卡 + 主题色条标题 */
.fl-dyn-card {
  background: #fff;
  border: 1px solid #ebeef5;
  border-radius: 8px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
  padding: 14px 16px;
}

.fl-dyn-card .fl-group-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  margin-bottom: 12px;
  padding-bottom: 10px;
  border-bottom: 1px solid #f2f3f5;
}

.fl-dyn-card .fl-group-title::before {
  content: '';
  width: 6px;
  height: 16px;
  border-radius: 3px;
  background: var(--el-color-primary);
  flex-shrink: 0;
}

/* ============ 商品分类二级选形弹窗（任务295） ============ */
.flc-mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.3);
  z-index: 3200;
}

.flc-panel {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  width: 460px;
  max-width: 92vw;
  background: #fff;
  border-radius: 6px;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
  overflow: hidden;
}

.flc-header {
  height: 42px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  background: #f7f8fa;
  border-bottom: 1px solid #ebeef5;
}

.flc-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
}

.flc-name {
  font-weight: 400;
  color: #909399;
  font-size: 12px;
}

.flc-close {
  font-size: 20px;
  line-height: 1;
  color: #909399;
  cursor: pointer;
  padding: 2px 6px;
}

.flc-close:hover {
  color: #f56c6c;
}

.flc-body {
  padding: 18px 20px 6px;
}

.flc-forms {
  display: block;
  width: 100%;
}

.flc-form-item {
  display: flex;
  align-items: center;
  height: 38px;
  padding: 0 12px;
  border: 1px solid #e7e7eb;
  border-radius: 5px;
  margin-bottom: 8px;
}

.flc-tip {
  margin-top: 4px;
  font-size: 12px;
  color: #909399;
  word-break: break-all;
}

.flc-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 12px 16px 16px;
}
</style>
