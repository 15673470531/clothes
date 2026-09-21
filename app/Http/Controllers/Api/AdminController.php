<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClothesItem;
use App\Models\ClothesOutfit;
use App\Models\ClothesWearLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 管理端统计（2026-09）
 *
 * 用途：观察软件的使用情况——有多少人注册、多少人还在用、真的录了东西的人是几个。
 * 只有 is_admin 的账号能调（authorizeAdmin），非管理员直接 403；
 * 小程序那边「我的」页也只在管理员时渲染入口，但**入口隐藏不算安全措施**，
 * 真正的拦人在这里。
 *
 * 口径说明（跟 money 项目保持一致，避免两个后台数字对不上）：
 *  - 新增用户 = users.created_at；「全部」含管理员，sub 里写清管理员有几人
 *  - 活跃用户 = users.last_active_at（每次带 token 的请求都会刷，
 *    见 app/Http/Middleware/UpdateLastActiveAt），**不含管理员**——否则自己天天算一个日活
 *  - 内容量 / 真实使用率 也都不含管理员：管理员的衣服是自测数据，混进去会虚高
 *  - 软删的衣物/搭配不计入（SoftDeletes 的全局作用域自动排除）
 *
 * 返回的 groups 结构是「给前端直接遍历渲染」用的：
 *  cards[].date_from 有值 → 点这张卡跳用户列表并带上该筛选参数；没值就不响应点击。
 *  想加/改指标、调卡片顺序，只改这里的 $groups，小程序不用动。
 */
class AdminController extends Controller
{
    /**
     * GET /api/admin/stats
     * 统计卡片：新增用户 / 活跃用户 / 内容量 / 真实使用率
     */
    public function stats(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $today      = Carbon::today();
        $weekStart  = Carbon::now()->startOfWeek();
        $monthStart = Carbon::now()->startOfMonth();
        $todayStr   = $today->toDateString();
        $weekStr    = $weekStart->toDateString();
        $monthStr   = $monthStart->toDateString();

        // ===== 用户 =====
        $newUsersToday = User::where('created_at', '>=', $today)->count();
        $newUsersWeek  = User::where('created_at', '>=', $weekStart)->count();
        $newUsersMonth = User::where('created_at', '>=', $monthStart)->count();
        $totalUsers    = User::count();
        $adminUsers    = User::where('is_admin', true)->count();
        $realUsers     = $totalUsers - $adminUsers;   // 分母：真人（非管理员）

        // 活跃 = 最近活跃时间落在区间内（不含管理员）
        $activeToday = User::where('is_admin', false)->where('last_active_at', '>=', $today)->count();
        $activeWeek  = User::where('is_admin', false)->where('last_active_at', '>=', $weekStart)->count();
        $activeMonth = User::where('is_admin', false)->where('last_active_at', '>=', $monthStart)->count();

        // 活跃里的「新面孔」：这段时间注册、且这段时间活跃过
        $activeNewToday = User::where('is_admin', false)
            ->where('created_at', '>=', $today)->where('last_active_at', '>=', $today)->count();
        $activeNewWeek = User::where('is_admin', false)
            ->where('created_at', '>=', $weekStart)->where('last_active_at', '>=', $weekStart)->count();
        $activeNewMonth = User::where('is_admin', false)
            ->where('created_at', '>=', $monthStart)->where('last_active_at', '>=', $monthStart)->count();

        // ===== 内容量（不含管理员；软删不计） =====
        // 注意是 whereHas 不是 whereDoesntHave：要「记录属于非管理员」，用反了会变成「只统计管理员的」
        $onlyRealUser = fn ($q) => $q->where('is_admin', false);

        $totalItems      = ClothesItem::whereHas('user', $onlyRealUser)->count();
        $newItemsToday   = ClothesItem::whereHas('user', $onlyRealUser)->where('created_at', '>=', $today)->count();
        $totalOutfits    = ClothesOutfit::whereHas('user', $onlyRealUser)->count();
        $totalWearDays   = ClothesWearLog::whereHas('user', $onlyRealUser)->count();

        // ===== 真实使用率（不含管理员） =====
        // 「注册了但一件没录」的账号不算真用户，这三个数才是产品到底有没有人用
        $itemUsers   = ClothesItem::whereHas('user', $onlyRealUser)->distinct()->count('user_id');
        $outfitUsers = ClothesOutfit::whereHas('user', $onlyRealUser)->distinct()->count('user_id');
        $wearUsers   = ClothesWearLog::whereHas('user', $onlyRealUser)->distinct()->count('user_id');

        $itemRate   = $realUsers > 0 ? round($itemUsers / $realUsers * 100) : 0;
        $outfitRate = $realUsers > 0 ? round($outfitUsers / $realUsers * 100) : 0;
        // 人均件数按「真的录过东西的人」算，而不是按注册数——否则被空号拉低，看不出真实使用强度
        $avgItems   = $itemUsers > 0 ? round($totalItems / $itemUsers, 1) : 0;

        $groups = [
            [
                'id'     => 'new_users',
                'icon'   => '👤',
                'title'  => '新增用户',
                'type'   => 'users',
                'filter' => 'date_from',
                'cards'  => [
                    ['label' => '今日', 'value' => $newUsersToday, 'date_from' => $todayStr],
                    ['label' => '本周', 'value' => $newUsersWeek,  'date_from' => $weekStr],
                    ['label' => '本月', 'value' => $newUsersMonth, 'date_from' => $monthStr],
                    ['label' => '全部', 'value' => $totalUsers,    'sub' => "管理员 {$adminUsers} 人"],
                ],
            ],
            [
                'id'     => 'active_users',
                'icon'   => '👥',
                'title'  => '活跃用户（不含管理员）',
                'type'   => 'users',
                'filter' => 'active_from',
                'cards'  => [
                    ['label' => '日活', 'value' => $activeToday, 'date_from' => $todayStr, 'sub' => "其中新用户 {$activeNewToday}"],
                    ['label' => '周活', 'value' => $activeWeek,  'date_from' => $weekStr,  'sub' => "其中新用户 {$activeNewWeek}"],
                    ['label' => '月活', 'value' => $activeMonth, 'date_from' => $monthStr, 'sub' => "其中新用户 {$activeNewMonth}"],
                ],
            ],
            [
                'id'    => 'content',
                'icon'  => '🧺',
                'title' => '内容量（不含管理员）',
                'cards' => [
                    ['label' => '衣物总数',   'value' => $totalItems],
                    ['label' => '今日新增',   'value' => $newItemsToday],
                    ['label' => '搭配总数',   'value' => $totalOutfits],
                    ['label' => '打卡天数',   'value' => $totalWearDays],
                ],
            ],
            [
                'id'    => 'usage',
                'icon'  => '📈',
                'title' => '真实使用率（不含管理员）',
                'cards' => [
                    ['label' => '录过衣物', 'value' => $itemUsers,   'sub' => "占注册 {$itemRate}%"],
                    ['label' => '有搭配',   'value' => $outfitUsers, 'sub' => "占注册 {$outfitRate}%"],
                    ['label' => '打过卡',   'value' => $wearUsers],
                    ['label' => '人均件数', 'value' => $avgItems,    'sub' => '按录过衣物的人算'],
                ],
            ],
        ];

        return $this->ok([
            'groups'     => $groups,
            'totalUsers' => $totalUsers,
            'realUsers'  => $realUsers,
        ]);
    }

    /**
     * GET /api/admin/users
     * 用户列表（统计页点卡片跳过来：新增用户带 date_from，活跃用户带 active_from）
     *
     * 支持：搜索（昵称/名字/openid/ID）、管理员筛选、注册时间、最近活跃、排序、分页
     * 每行带上这个人的数据量（衣物/搭配/打卡），一眼看出谁在用、谁是空号
     */
    public function users(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = User::query()->withCount(['clothesItems', 'clothesOutfits', 'wearLogs']);

        // 搜索：昵称/名字/openid；纯数字当 ID（同时兼容显示用的「10+真实ID」写法）
        if ($keyword = $request->input('keyword')) {
            $rawId = $keyword;
            if (preg_match('/^10(\d+)$/', (string) $keyword, $m)) {
                $rawId = $m[1];
            }

            if (ctype_digit((string) $rawId)) {
                $query->where('id', (int) $rawId);
            } else {
                $query->where(function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%")
                      ->orWhere('nickname', 'like', "%{$keyword}%")
                      ->orWhere('openid', 'like', "%{$keyword}%");
                });
            }
        }

        if ($request->has('is_admin') && $request->input('is_admin') !== '') {
            $query->where('is_admin', $request->boolean('is_admin'));
        }

        // 注册时间（统计页「新增用户」跳过来）
        if ($dateFrom = $request->input('date_from')) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        // 最近活跃（统计页「活跃用户」跳过来）；口径跟卡片一致：排除管理员
        if ($activeFrom = $request->input('active_from')) {
            $query->where('last_active_at', '>=', $activeFrom)->where('is_admin', false);
        }

        $perPage   = min((int) $request->input('per_page', 20), 100);
        $sortBy    = in_array($request->input('sort_by'), ['created_at', 'last_login_at', 'last_active_at'])
            ? $request->input('sort_by') : 'last_active_at';
        $sortOrder = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';

        $users = $query->orderBy($sortBy, $sortOrder)->orderByDesc('id')->paginate($perPage);

        $list = collect($users->items())->map(fn (User $u) => [
            'id'           => $u->id,
            'openid'       => $u->openid,
            'name'         => $u->name,
            'nickname'     => $u->nickname,
            'avatarUrl'    => $u->avatar_url,
            'isAdmin'      => (bool) $u->is_admin,
            'createdAt'    => $u->created_at ? $u->created_at->format('Y-m-d H:i') : '',
            'lastLoginAt'  => $u->last_login_at ? $u->last_login_at->format('Y-m-d H:i') : '',
            'lastActiveAt' => $u->last_active_at ? $u->last_active_at->format('Y-m-d H:i') : '',
            'itemCount'    => (int) $u->clothes_items_count,
            'outfitCount'  => (int) $u->clothes_outfits_count,
            'wearCount'    => (int) $u->wear_logs_count,
        ])->values();

        return $this->ok([
            'list'        => $list,
            'total'       => $users->total(),
            'perPage'     => $users->perPage(),
            'currentPage' => $users->currentPage(),
            'lastPage'    => $users->lastPage(),
        ]);
    }

    /** 只有管理员能用（入口在小程序里藏了，但拦人必须放后端） */
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user() && $request->user()->is_admin, 403, '无管理权限');
    }

    /** 统一响应格式，跟 ClothesController 一致 */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['code' => 0, 'msg' => 'success', 'data' => $data]);
    }
}
