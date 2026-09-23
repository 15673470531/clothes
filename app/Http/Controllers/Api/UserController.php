<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\HangerRewardService;
use App\Services\ImageStorage;
use App\Services\Quota;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * 用户中心：微信小程序登录 + 用户信息
 *
 * 约定：
 *  - 微信 appid / secret 全部走 .env（WECHAT_APPID / WECHAT_APPSECRET），不要写死在代码里
 *  - 本地开发用 code = dev_xxx 直接登录，跳过微信校验
 *  - 统一响应格式 {"code":0,"msg":"success","data":...}
 */
class UserController extends Controller
{
    /** 图片存储（配了 OSS 走 OSS，没配落本地 public 盘）—— 头像上传用 */
    public function __construct(private readonly ImageStorage $storage)
    {
    }

    /**
     * POST /api/user/login
     * 微信登录：用 code 换 openid，不存在则创建用户，返回 Sanctum Token
     */
    public function login(Request $request)
    {
        $code = $request->input('code');
        $nickName = $request->input('nickName', '');
        $avatarUrl = $request->input('avatarUrl', '');

        if (!$code) {
            return response()->json(['code' => 1, 'msg' => '缺少 code 参数']);
        }

        // 本地开发模式：code 以 dev_ 开头则跳过微信验证
        // ⚠️ 必须显式打开 ALLOW_DEV_LOGIN=true 才生效（见 devLoginAllowed）：
        //    devLogin 是「openid = dev_ + md5(code)」建号的，而真机的 wx.login code 每次都变，
        //    线上万一 APP_ENV 配成 local，就会变成「每登一次多一个用户」（2026-09 踩过）
        if (str_starts_with($code, 'dev_')) {
            if (! $this->devLoginAllowed()) {
                return response()->json(['code' => 2, 'msg' => '开发模式登录已关闭']);
            }

            return $this->devLogin($request);
        }

        // 微信 jscode2session 换取 openid
        $url = 'https://api.weixin.qq.com/sns/jscode2session?' . http_build_query([
            'appid'      => env('WECHAT_APPID'),
            'secret'     => env('WECHAT_APPSECRET'),
            'js_code'    => $code,
            'grant_type' => 'authorization_code',
        ]);
        $res = @json_decode(@file_get_contents($url), true);
        $openid = $res['openid'] ?? '';

        if (!$openid) {
            // 本地开发：微信验证失败时降级为开发模式（同样要显式开关；线上绝不允许）
            if ($this->devLoginAllowed()) {
                return $this->devLogin($request);
            }
            $errMsg = $res['errmsg'] ?? '微信登录失败';
            return response()->json(['code' => 2, 'msg' => $errMsg]);
        }

        // 查找或创建用户
        $user = User::where('openid', $openid)->first();
        if (!$user) {
            $user = User::create([
                'openid'     => $openid,
                'name'       => $nickName ?: '微信用户',
                'nickname'   => $nickName ?: null,
                'avatar_url' => $avatarUrl ?: null,
                'email'      => $openid . '@wechat',
                'password'   => Hash::make($openid),
                // 免费衣架额度以 config('quota.*') 为准（别只依赖列默认值，
                // 以后调免费额度只改配置，不用再写迁移）
                // 只在这里给一次：建号之后再想加衣架，只能靠「删东西退还」或「赚衣架」（签到/分享/每月赠送）
                'item_quota'       => (int) config('quota.item_quota', 100),
                'daily_quota'      => (int) config('quota.daily_quota', 100),
                'daily_reset_date' => today()->toDateString(),
            ]);
        } else {
            // 老用户：昵称/头像给了就更新（分开判断 —— 只换了头像没带昵称时也要存下来）
            $dirty = [];
            if ($nickName) {
                $dirty['nickname'] = $nickName;
            }
            if ($avatarUrl) {
                $dirty['avatar_url'] = $avatarUrl;
            }
            if ($dirty) {
                $user->update($dirty);
            }
        }

        return response()->json([
            'code' => 0,
            'msg'  => '登录成功',
            'data' => $this->loginPayload($user, $request),
        ]);
    }

    /**
     * 是否允许「开发模式登录」（跳过微信换 openid，直接用 dev_ 开头的 code 建号）
     *
     * 两个条件都要满足才放行：
     *  1. 环境是 local
     *  2. 显式打开 ALLOW_DEV_LOGIN=true（写进 .env；默认关）
     *
     * 为什么这么严：devLogin 的 openid 是「dev_ + md5(code)」，而**真机的 wx.login code 每次都变**，
     * 一旦线上被误判成 local（2026-09 就是这么炸的），真机每登一次就新建一个用户，
     * 数据散在一堆号里。加了这道开关，即使 APP_ENV 又配错，也只会「登录失败」而不是偷偷建号。
     */
    private function devLoginAllowed(): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        return (bool) config('app.allow_dev_login', false);
    }

    /**
     * 本地开发用登录：跳过微信验证，openid = dev_ + code 的 md5
     */
    private function devLogin(Request $request)
    {
        $code = $request->input('code');          // e.g. dev_001, dev_002
        $nickName = $request->input('nickName', '开发用户');
        $avatarUrl = $request->input('avatarUrl', '');

        $openid = 'dev_' . md5($code);

        $user = User::where('openid', $openid)->first();
        if (!$user) {
            $user = User::create([
                'openid'     => $openid,
                'name'       => $nickName ?: '开发用户',
                'nickname'   => $nickName ?: null,
                'avatar_url' => $avatarUrl ?: null,
                'email'      => $openid . '@dev',
                'password'   => Hash::make($openid),
                // 跟正式建号保持一致：免费衣架也按 config('quota.*') 给，
                // 否则本机测试号会拿到「数据库列默认值」（那个可能是旧数字，跟线上口径不一致）
                'item_quota'       => (int) config('quota.item_quota', 100),
                'daily_quota'      => (int) config('quota.daily_quota', 100),
                'daily_reset_date' => today()->toDateString(),
            ]);
        }

        return response()->json([
            'code' => 0,
            'msg'  => '登录成功（开发模式）',
            'data' => $this->loginPayload($user, $request),
        ]);
    }

    /**
     * 登录返回体（正式 / 开发模式共用）
     */
    private function loginPayload(User $user, Request $request): array
    {
        // 记录最后登录时间（兼容字段尚未迁移的环境）
        if (Schema::hasColumn('users', 'last_login_at')) {
            $user->update(['last_login_at' => now()]);
        }

        // 删除旧 token，生成新 token（一个端只保留一个有效 token）
        $user->tokens()->delete();
        $token = $user->createToken('wechat-miniprogram')->plainTextToken;

        return [
            'token'     => $token,
            'openid'    => $user->openid,
            'nickName'  => $user->nickname ?? $user->name,
            'name'      => $user->name,
            'avatarUrl' => $this->storage->out($user->avatar_url),
            'isAdmin'   => $user->is_admin,
            'userId'    => $user->id,
            'phone'     => $this->getPhoneDisplay($user),
        ];
    }

    /**
     * GET /api/user/info
     * 获取当前登录用户信息
     */
    public function info(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'code' => 0,
            'msg'  => 'success',
            'data' => [
                'openid'    => $user->openid,
                'nickName'  => $user->nickname ?? $user->name,
                'name'      => $user->name,
                'avatarUrl' => $this->storage->out($user->avatar_url),
                'isAdmin'   => $user->is_admin,
                'userId'    => $user->id,
                'phone'     => $this->getPhoneDisplay($user),
                'createdAt' => $user->created_at->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * GET /api/user/quota
     * 额度用量（「我的」页显示「还能录 128 件 · 今天还能录 45 件」）
     * 数值是用户表上的余额（users.item_quota / users.daily_quota）
     *
     * 前端只显示、不判断：真正扣额度的是 ClothesController::push（事务里锁行扣），
     * 改前端绕不过去。
     */
    public function quota(Request $request, Quota $quota, HangerRewardService $reward)
    {
        // 每月系统赠送：没发过就顺手补发（惰性，不依赖定时任务）——
        // 用户一打开「我的」页就会到账，衣架卡上的数字立刻反映出来
        $reward->grantMonthly($request->user());

        return response()->json([
            'code' => 0,
            'msg'  => 'success',
            'data' => $quota->summary($request->user()),
        ]);
    }

    /**
     * POST /api/user/avatar
     * 头像上传：chooseAvatar 拿到的是**临时文件**（重开小程序就没了），必须传到后端存成正式地址
     * 返回 avatarUrl（可直接给 <image src> 用）
     *
     * 存储走 App\Services\ImageStorage：配了 OSS 就进 OSS，没配落本地 public 盘（跟衣物照片同一套）
     */
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'file' => 'required|file|image|max:5120',
        ], [
            'file.required' => '没有收到头像文件',
            'file.image'    => '头像必须是图片',
            'file.max'      => '头像不能超过 5MB',
        ]);

        $user = $request->user();
        $old = $user->avatar_url;

        $out = $this->storage->put($request->file('file'), 'avatar/' . $user->id);

        $user->avatar_url = $out['url'];
        $user->save();

        // 旧头像顺手删掉，别在存储上堆垃圾（本地的 / OSS 的都认）
        if ($old && $old !== $out['url']) {
            $this->storage->deleteByUrl($old);
        }

        return response()->json([
            'code' => 0,
            'msg'  => '头像已更新',
            'data' => ['avatarUrl' => $this->storage->out($out['url'])],
        ]);
    }

    /**
     * POST /api/user/update-name
     * 修改用户昵称（显示名，小程序「我的」页用 input type="nickname" 让用户自己填）
     */
    public function updateName(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
        ]);

        // 昵称同时写 nickname（微信昵称口径）与 name（显示名），列表页/后台都看得到
        $request->user()->update(['nickname' => $validated['name']]);

        $user = $request->user();
        $user->name = $validated['name'];
        $user->save();

        return response()->json([
            'code' => 0,
            'msg'  => '昵称更新成功',
            'data' => ['name' => $user->name],
        ]);
    }

    /**
     * POST /api/user/bind-phone
     * 微信授权获取手机号，绑定到当前用户
     */
    public function bindPhone(Request $request)
    {
        $user = $request->user();
        $code = $request->input('code');

        if (!$code) {
            return response()->json(['code' => 1, 'msg' => '缺少 code 参数']);
        }

        // 本地环境：模拟绑定，方便前端联调
        if (app()->environment('local')) {
            $phone = '138' . str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
            if (Schema::hasColumn('users', 'phone')) {
                $user->phone = $phone;
                $user->save();
            }
            return response()->json([
                'code' => 0,
                'msg'  => '绑定成功（开发模式）',
                'data' => ['phone' => substr($phone, 0, 3) . '****' . substr($phone, -4)],
            ]);
        }

        // 生产环境：调用微信接口
        $accessToken = $this->getWechatAccessToken();
        if (!$accessToken) {
            return response()->json(['code' => 2, 'msg' => '获取微信凭证失败，请稍后重试']);
        }

        $res = Http::post("https://api.weixin.qq.com/wxa/business/getuserphonenumber?access_token={$accessToken}", [
            'code' => $code,
        ])->json();

        if (empty($res['errcode']) && !empty($res['phone_info']['phoneNumber'])) {
            $phone = $res['phone_info']['phoneNumber'];
            if (Schema::hasColumn('users', 'phone')) {
                $user->phone = $phone;
                $user->save();
            }
            return response()->json([
                'code' => 0,
                'msg'  => '绑定成功',
                'data' => ['phone' => substr($phone, 0, 3) . '****' . substr($phone, -4)],
            ]);
        }

        return response()->json([
            'code' => 3,
            'msg'  => '获取手机号失败：' . ($res['errmsg'] ?? '未知错误'),
        ]);
    }

    /**
     * POST /api/user/logout
     * 退出登录，清除当前 token
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'code' => 0,
            'msg'  => '退出成功',
        ]);
    }

    /**
     * 获取微信 access_token（缓存 110 分钟，避免超过 2 小时有效期）
     */
    private function getWechatAccessToken(): ?string
    {
        return \App\Services\WechatService::accessToken();
    }

    /**
     * 脱敏显示手机号，兼容字段尚未迁移的环境
     */
    private function getPhoneDisplay($user): ?string
    {
        if (!Schema::hasColumn('users', 'phone') || !$user->phone) {
            return null;
        }
        return substr($user->phone, 0, 3) . '****' . substr($user->phone, -4);
    }
}
