<?php

namespace App\Services;

/**
 * 文案表（2026-09 用户定：前端所有提示都改由后端下发，随时能改、不用发小程序版本）
 *
 * 三层：
 *   1 **内置默认**（这个文件的 DEFAULTS）—— 保证任何情况下都有话可说
 *   2 **服务器上的文件**（storage/app/texts.json）—— 你在服务器上改这一个文件，**立刻生效**，
 *      不用重启、不用发版（跟 money 项目的 nav/help 一个套路）。只写想改的那几条就行，
 *      没写的自动用默认值（所以以后新增文案，老文件也不用补）
 *   3 接口 GET /api/texts 把合并后的结果下发，小程序启动拉一次存本地
 *
 * 为什么必须留内置默认（不是多此一举）：
 *   有几类提示**根本等不到后端**——"网络异常""登录失效""请求失败"。后端都没响应了，
 *   文案只能本地有。所以小程序那边也存了一份同样的默认（utils/texts.js），
 *   拉到后端文案就覆盖，拉不到就用本地的，绝不出现空白提示。
 *
 * 带数字的文案（用户 2026-09 定：**后端能拼就后端拼**）：
 *   - 后端知道的数字（一套最多几件、奖励 +2/+10）→ 直接拼好整句下发，前端拿到就能用
 *   - 只有前端知道的（衣物名、剩余张数、这条搭配被几个搭配用着）→ 留 {xxx} 占位符，
 *     前端替换（utils/texts.js 的 t('key', {xxx: ...})）
 *
 * 改文案：`php artisan texts:export` 会把当前生效的全量文案写到 storage/app/texts.json，
 * 之后就在那个文件上改（只留要改的条目也行）。
 */
class Texts
{
    /** 服务器上的可写文件（改它立刻生效） */
    public const FILE = 'app/texts.json';

    /**
     * 全量默认文案（分页分组，改这里要同步小程序端 utils/texts.js 的 DEFAULTS）
     *
     * 约定：
     *   - 成功/告知类 = toast 标题（短，别超过 10 个字左右，长了会被微信截断）
     *   - 失败类 = 弹窗标题（白话，别用"操作失败"这种）
     *   - {xxx} = 前端替换的占位符；后端能算出来的就直接写数字，不留占位符
     */
    public const DEFAULTS = [
        // ---- 通用（utils/api.js）----
        'common.loading'          => '加载中...',
        'common.uploading'        => '上传中...',
        'common.avatar_uploading' => '上传头像...',
        'common.network_fail'     => '网络异常，后端起了吗？',
        'common.request_fail'     => '请求失败',
        'common.retry_later'      => '网络异常，稍后再试',
        'common.fail'             => '操作没成功，稍后再试',
        'common.service_ok'       => '联系客服',
        'common.know'             => '知道了',
        'common.ok'               => '好',
        'common.go_wardrobe'      => '去衣橱',

        // ---- 宣传语（2026-09 用户要的：空态/关于页/分享都用这几句，改这里全站生效）----
        // slogan.main 是全站主口号；空态可以各自再给一句更贴场景的
        'slogan.main'             => '衣服都在，搭配不用想',
        'slogan.wardrobe_empty'   => '拍一张，衣柜就搬进手机里',

        // ---- 登录 / 「我的」页 ----
        'mine.logging_in'         => '登录中...',
        'mine.login_ok'           => '登录成功',
        'mine.login_fail'         => '登录失败，稍后再试',
        'mine.cleanup_need_login' => '先登录微信再来',
        'mine.avatar_fail'        => '没拿到头像，再试一次',
        'mine.name_empty'         => '昵称不能为空',
        'mine.saved'              => '已保存',
        'mine.save_fail'          => '保存失败',
        'mine.logout_title'       => '退出登录？',
        'mine.logout_content'     => '衣橱和搭配都还在本机，不会丢。',
        'mine.logged_out'         => '已退出',

        // ---- 赚衣架（pages/reward）----
        'reward.need_login'       => '先登录微信再来',
        'reward.checkin_ok'       => '签到成功 +{n} 个衣架',
        'reward.share_ok'         => '分享成功 +{n} 个衣架',
        // 衣架到总数上限（config('quota.item_max')，200 个）时：点得动、但余额不再往上加，
        // 就用这句替掉"+n 个衣架"，别报一个用户看不到的数字
        'reward.capped'           => '衣架已经到上限 {max} 个了，这次的先不累加',
        // 新用户每月免费领取（2026-09 取代"每月系统赠送"：从自动到账改成点一下领）
        // 标题/说明/按钮/状态都由后端拼好，前端只显示
        'reward.newcomer_title'   => '新用户每月免费领取',
        'reward.newcomer_desc'    => '每月免费领 +{n} 个衣架，点一下就到账',
        'reward.newcomer_btn'     => '领取',
        'reward.newcomer_done'    => '本月已领取',
        'reward.newcomer_ok'      => '领取成功 +{n} 个衣架',
        // 衣架流水列表（赚衣架页下面那个列表，2026-09 用户要的）
        'reward.logs_title'       => '衣架流水',
        'reward.logs_empty'       => '还没有记录，签个到就有第一个衣架',
        'reward.logs_more'        => '只显示最近 {n} 条（共 {total} 条）',
        'reward.logs_total'       => '累计 +{n} 个',
        // 流水里每种奖励叫什么（跟上面那些按钮的名字保持一致）
        'reward.log_checkin'      => '每日签到',
        'reward.log_share'        => '分享群或者好友',
        'reward.log_newcomer'     => '新用户每月免费领取',
        'reward.log_monthly'      => '每月系统赠送',
        // 老口径的每月系统赠送那一行（配置默认 0 = 不显示；设 >0 才会用上这几条）
        'reward.monthly_title'    => '每月系统赠送',
        'reward.monthly_desc'     => '每个月自动送 +{n} 个衣架，不用领',
        'reward.monthly_btn'      => '',
        'reward.monthly_done'     => '本月已到账',
        'reward.monthly_ok'       => '本月赠送 +{n} 个衣架已到账',

        // ---- 录衣物（pages/item-edit）----
        'itemEdit.saving'              => '保存中…',
        'itemEdit.saved'               => '已保存',
        'itemEdit.saved_many'          => '已保存 {n} 件',
        'itemEdit.saved_next'          => '已保存，继续下一件',
        'itemEdit.already_saved'       => '这件已经录好了',
        'itemEdit.save_fail_title'     => '没保存成功',
        'itemEdit.save_fail_content'   => '{msg}，刚填的都还在，再点一次保存就行',
        'itemEdit.no_photo'            => '先拍一张或从相册选一张',
        'itemEdit.no_category'         => '先选品类',
        'itemEdit.photo_save_fail'     => '照片保存失败，可能是本机存储已满',
        'itemEdit.normalize_entry'     => '洗成白底图（去杂物）',
        'itemEdit.normalize_sub'       => '去掉背景里的杂物，生成一张干净的白底商品图',
        'itemEdit.normalize_done_sub'  => '白底图已生成 · 点这里选封面用哪张',
        'itemEdit.normalize_uploading' => '先把照片传到云端…',
        'itemEdit.normalize_working'   => '正在洗白底图…（大约 20 秒）',
        'itemEdit.normalize_title'     => '白底图洗好了',
        'itemEdit.normalize_hint'      => '原图会一直留着，随时能切回来',
        'itemEdit.normalize_use_white' => '用这张当封面',
        'itemEdit.normalize_keep_orig' => '保持原图',
        'itemEdit.normalize_switched'  => '封面已换成白底图',
        'itemEdit.normalize_restored'  => '已切回原图',
        'itemEdit.normalize_left'      => '今天还能洗 {n} 次',
        'itemEdit.normalize_none_left' => '今天洗白底的次数用完了，明天再来',
        'itemEdit.normalize_fail_title' => '没洗出来',
        'itemEdit.normalize_label_orig'  => '原图',
        'itemEdit.normalize_label_white' => '白底图',
        'itemEdit.normalize_using_white' => '封面现在用的是白底图，可以随时切回来',
        'itemEdit.normalize_using_orig'  => '封面现在用的是原图，可以换成白底图',
        'itemEdit.delete_title'        => '删除这件衣物？',
        'itemEdit.delete_content'      => '删除后无法恢复。',
        'itemEdit.delete_ok'           => '删除',
        'itemEdit.delete_fail_title'   => '没删掉',
        'itemEdit.in_outfit_title'     => '这件衣服被搭配用着',
        'itemEdit.in_outfit_content'   => '「{names}」{more}里都有它，删除后这些搭配里就没有它了。',
        'itemEdit.in_outfit_more'      => ' 等 {n} 个搭配',
        'itemEdit.in_outfit_ok'        => '仍要删除',
        'itemEdit.quota_daily_title'   => '今天的衣架用完了',
        'itemEdit.quota_total_title'   => '衣架不够了',
        'itemEdit.quota_daily_fallback' => '今天的衣架用完了，明天再来。',
        'itemEdit.quota_total_fallback' => '衣架用完了，想继续挂可以联系客服。',
        'itemEdit.quota_daily_tail'    => '想今天继续挂，可以联系客服。',

        // ---- 衣橱（pages/wardrobe）----
        'wardrobe.pending_title'        => '还有 {n} 张没录完',
        'wardrobe.pending_content'      => '继续录入上次选的照片，还是丢掉这几张重新选？',
        'wardrobe.pending_ok'           => '继续录入',
        'wardrobe.pending_cancel'       => '丢掉',
        'wardrobe.quota_daily_title'    => '今天的衣架用完了',
        'wardrobe.quota_total_title'    => '衣架用完了',
        'wardrobe.quota_daily_fallback' => '今天的衣架用完了，明天再来。',
        'wardrobe.quota_total_fallback' => '衣架用完了，想继续挂可以联系客服。',
        'wardrobe.quota_daily_tail'     => '想今天继续挂，可以联系客服。',
        'wardrobe.left_title'           => '还有 {n} 个衣架',
        'wardrobe.left_content'         => '这次最多选 {n} 张（拍一次照仍是一张）。',
        'wardrobe.preparing'            => '准备照片 {i}/{n}',
        'wardrobe.photo_save_fail'      => '照片保存失败，可能是本机存储已满',
        'wardrobe.some_failed'          => '{n} 张没保存成功，先录这几张',
        'wardrobe.deleted'              => '已删除',
        'wardrobe.delete_fail_title'    => '没删掉',
        'wardrobe.delete_title'         => '删除这件衣物？',
        'wardrobe.delete_content'       => '删除后无法恢复。',
        'wardrobe.delete_ok'            => '删除',
        'wardrobe.in_outfit_title'      => '这件衣服被搭配用着',
        'wardrobe.in_outfit_content'    => '「{names}」{more}里都有它，删除后这些搭配里就没有它了。',
        'wardrobe.in_outfit_more'       => ' 等 {n} 个搭配',
        'wardrobe.in_outfit_ok'         => '仍要删除',

        // ---- 搭配列表（pages/outfit）----
        'outfit.delete_title'    => '删除「{name}」？',
        'outfit.delete_content'  => '只删搭配本身，里面的衣物还在衣橱里。',
        'outfit.delete_ok'       => '删除',
        'outfit.deleted'         => '已删除',
        'outfit.delete_fail_title' => '没删掉',

        // ---- 挑衣物（pages/outfit-edit）----
        'outfitEdit.max_items'  => '一套最多 {n} 件',
        'outfitEdit.need_one'   => '至少选一件衣物',
        'outfitEdit.picked'     => '已选择',

        // ---- 搭配预览（pages/outfit-view）----
        'outfitView.need_save_first'    => '先点「完成」保存这套搭配',
        'outfitView.keep_one'           => '至少留一件衣物',
        'outfitView.removed'            => '已移出「{name}」',
        'outfitView.cover_generating'   => '生成封面…',
        'outfitView.saving'             => '保存中…',
        'outfitView.save_fail_title'    => '没保存成功',
        'outfitView.save_fail_content'  => '{msg}，这套搭配还是「没保存」状态，再点一次「完成」就行',
        'outfitView.quota_daily_title'  => '今天的衣架用完了',
        'outfitView.quota_total_title'  => '衣架不够了',
        'outfitView.quota_daily_fallback' => '今天的衣架用完了，明天再来。',
        'outfitView.quota_total_fallback' => '衣架用完了，想继续挂可以联系客服。',
        'outfitView.quota_daily_tail'   => '想今天继续挂，可以联系客服。',
        'outfitView.img_generating'     => '生成图片…',
        'outfitView.img_fail'           => '图片生成失败，稍后再试',
        'outfitView.saved_album'        => '已保存到相册',
        'outfitView.save_fail'          => '保存失败',
        'outfitView.album_auth_title'   => '需要相册权限',
        'outfitView.album_auth_content' => '保存图片需要允许「保存到相册」，去设置里打开一下？',

        // ---- AI 试穿（pages/tryon）----
        'tryon.processing'        => '处理中…',
        'tryon.download_fail'     => '图片下载失败',
        'tryon.saved_album'       => '已保存到相册',
        'tryon.save_fail'         => '保存失败',
        'tryon.album_auth_title'  => '需要相册权限',
        'tryon.album_auth_content' => '保存图片需要允许「保存到相册」，去设置里打开一下？',
        'tryon.set_cover_ok'      => '已设为这套的封面',
        'tryon.set_cover_local'   => '本机已换封面，云端稍后同步',

        // ---- 日历（pages/calendar）----
        'calendar.marked'            => '已记下{name}',
        'calendar.mark_fail_title'   => '没记上',
        'calendar.clear_title'       => '清除这天的记录？',
        'calendar.clear_content'     => '只清掉日历上的记录，搭配和衣物都还在。',
        'calendar.clear_ok'          => '清除',
        'calendar.cleared'           => '已清除',
        'calendar.clear_fail_title'  => '没清掉',

        // ---- 意见反馈（pages/feedback）----
        'feedback.empty'      => '写点内容再提交吧',
        'feedback.thanks'     => '谢谢你的反馈！',
        'feedback.fail'       => '提交失败，稍后再试',

        // ---- 联系客服（pages/service）----
        'service.email_copied' => '邮箱已复制',

        // ---- 管理端下钻（pages/admin/*）----
        'admin.no_photo'   => '这件没有照片',
        'admin.no_cover'   => '这套还没出图',
        'admin.load_fail'  => '加载失败',
    ];

    /**
     * 当前生效的文案 = 默认值 + 服务器文件里的覆盖项
     *
     * 每次请求都读文件（很小，几 KB），所以你在服务器上改完**立刻生效**，不用重启。
     * 文件坏了/格式不对就退回默认值，绝不让文案问题把小程序搞崩。
     */
    public function all(): array
    {
        return array_merge(self::DEFAULTS, $this->overrides());
    }

    /** 只取文件里的覆盖项（没文件/解析失败 → 空数组） */
    public function overrides(): array
    {
        $path = storage_path(self::FILE);
        if (!is_file($path)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json)) {
            return [];
        }

        // 只认字符串值，防止有人写了嵌套结构把前端搞挂
        return array_filter($json, static fn ($v) => is_string($v));
    }

    /** 取一条（后端内部拼文案用，比如「签到成功 +1 个衣架」） */
    public function get(string $key, array $vars = []): string
    {
        $text = $this->all()[$key] ?? '';

        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }

        return $text;
    }

    /** 把当前生效的全量文案写到 storage/app/texts.json（给你在服务器上改） */
    public function export(): int
    {
        $path = storage_path(self::FILE);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($this->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return count($this->all());
    }
}
