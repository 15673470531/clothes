<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UsageController extends Controller
{
    public const LABELS = [
        'session_start' => '已登录访问', 'login_success' => '登录成功',
        'wardrobe_empty' => '查看空衣橱', 'add_click' => '点击添加衣物',
        'photo_start' => '打开照片选择', 'photo_success' => '选好照片',
        'photo_cancel' => '取消选图', 'photo_fail' => '选图失败',
        'prepare_fail' => '照片准备失败', 'edit_open' => '进入新增衣物',
        'page_active' => '页面有效停留', 'save_click' => '点击保存',
        'validation_fail' => '表单校验未通过', 'save_success' => '衣物保存成功',
        'save_fail' => '衣物保存失败', 'upload_fail' => '照片上传失败',
        'quota_block' => '衣架额度不足',
    ];

    public function collect(Request $request) {
        $d = $request->validate([
            'events' => 'required|array|min:1|max:30',
            'events.*.event_id' => 'required|string|regex:/^[a-zA-Z0-9_-]{1,64}$/',
            'events.*.session_id' => 'required|string|regex:/^[a-zA-Z0-9_-]{1,64}$/',
            'events.*.name' => ['required', Rule::in(array_keys(self::LABELS))],
            'events.*.page' => ['required', Rule::in(['app', 'mine', 'wardrobe', 'item-edit'])],
            'events.*.source' => ['sometimes', Rule::in(['', 'fab', 'empty', 'camera', 'album', 'single', 'batch', 'next'])],
            'events.*.result' => ['sometimes', Rule::in(['', 'photo', 'category', 'price', 'success', 'cancel', 'fail'])],
            'events.*.error_code' => 'sometimes|string|regex:/^[a-zA-Z0-9_-]{0,32}$/',
            'events.*.duration_ms' => 'sometimes|integer|min:0|max:1800000',
            'events.*.count' => 'sometimes|integer|min:0|max:100',
            'events.*.sequence' => 'required|integer|min:0|max:1000000',
            'events.*.occurred_at' => 'required|integer|min:'.now()->subDays(30)->getTimestampMs().'|max:'.now()->addMinutes(5)->getTimestampMs(),
        ]);
        // 不接受客户端 user_id；管理员自测不计入观察数据。
        if (!$request->user()->is_admin) {
            $rows = [];
            foreach ($d['events'] as $e) {
                $row = array_intersect_key($e, array_flip(['event_id','session_id','name','page','source','result','error_code','duration_ms','count','sequence','occurred_at']));
                $rows[] = array_merge(['source'=>'','result'=>'','error_code'=>'','duration_ms'=>0,'count'=>0], $row, ['user_id'=>$request->user()->id,'created_at'=>now()]);
            }
            DB::table('usage_events')->insertOrIgnore($rows);
        }
        return response()->json(['code'=>0,'msg'=>'success','data'=>null]);
    }

    public function report(Request $request) {
        abort_unless($request->user()->is_admin, 403, '仅管理员可访问');
        $q = $request->validate(['days'=>'sometimes|integer|in:1,7,30']);
        $days = (int) ($q['days'] ?? 7);
        $from = now()->subDays($days)->getTimestampMs();
        // 首版按会话分析：同一用户多次访问分别计数。最多读取最近 20000 条，明确告知截断。
        $base = DB::table('usage_events')->where('occurred_at','>=',$from)
            ->whereIn('user_id', DB::table('users')->where('is_admin',false)->select('id'));
        $total = (clone $base)->count();
        $events = $base->orderByDesc('occurred_at')->orderByDesc('sequence')->limit(20000)->get()
            ->sortBy(fn($e)=>sprintf('%020d-%010d', $e->occurred_at, $e->sequence))->values();
        $steps = ['session_start','wardrobe_empty','add_click','photo_success','edit_open','save_click','save_success'];
        $counts = array_fill_keys($steps, 0);
        $detailLabels = ['fab'=>'右下角添加','empty'=>'空衣橱添加','camera'=>'拍照','album'=>'相册','single'=>'单件','batch'=>'批量','next'=>'再记一件','photo'=>'缺少照片','category'=>'缺少品类','price'=>'价格格式错误','success'=>'成功','cancel'=>'取消','fail'=>'失败'];
        $formatTime = fn($ms, $format) => \Illuminate\Support\Carbon::createFromTimestampMs($ms)->setTimezone(config('app.timezone'))->format($format);
        $errors = []; $visits = []; $logins = [];
        foreach ($events->groupBy(fn($e)=>$e->user_id.':'.$e->session_id) as $group) {
            $next = 0; $timeline = []; $success = false;
            foreach ($group as $e) {
                if ($next < count($steps) && $e->name === $steps[$next]) { $counts[$steps[$next]]++; $next++; }
                if ($e->name === 'login_success') $logins[$e->user_id] = true;
                if ($e->name === 'save_success') $success = true;
                $label = self::LABELS[$e->name] ?? $e->name;
                if (in_array($e->name, ['photo_fail','prepare_fail','validation_fail','save_fail','upload_fail','quota_block','photo_cancel'])) {
                    $key = $e->name.':'.$e->result.':'.$e->error_code;
                    if (!isset($errors[$key])) $errors[$key] = ['label'=>$label,'detail'=>trim(($detailLabels[$e->result] ?? $e->result).' '.$e->error_code),'count'=>0];
                    $errors[$key]['count']++;
                }
                $timeline[] = ['id'=>$e->id,'label'=>$label,'time'=>$formatTime($e->occurred_at, 'm-d H:i:s'),
                    'detail'=>implode(' · ', array_filter([$detailLabels[$e->source] ?? $e->source,$detailLabels[$e->result] ?? $e->result,$e->error_code,$e->duration_ms ? round($e->duration_ms/1000,1).'秒' : '',$e->count ? $e->count.'张/件' : '']))];
            }
            $last = $group->last();
            $action = $group->last(fn($e) => $e->name !== 'page_active') ?? $last;
            $visits[] = ['key'=>$last->user_id.':'.$last->session_id,'userId'=>$last->user_id,'at'=>$last->occurred_at,
                'time'=>$formatTime($group->first()->occurred_at, 'm-d H:i'),
                'last'=>self::LABELS[$action->name] ?? $action->name,'saved'=>$success,
                'timeline'=>array_slice($timeline,-100),'trimmed'=>count($timeline)>100];
        }
        usort($visits,fn($a,$b)=>$b['at']<=>$a['at']);
        usort($errors,fn($a,$b)=>$b['count']<=>$a['count']);
        $funnel=[]; $previous=null;
        foreach ($steps as $step) {
            $n=$counts[$step];
            $funnel[]=['name'=>$step,'label'=>self::LABELS[$step],'count'=>$n,'rate'=>$previous === null ? '起点' : ($previous ? round($n/$previous*100).'%' : '—')];
            $previous=$n;
        }
        return response()->json(['code'=>0,'msg'=>'success','data'=>[
            'days'=>$days,'funnel'=>$funnel,'errors'=>array_values($errors),'visits'=>array_slice($visits,0,20),
            'sessions'=>count($visits),'loginUsers'=>count($logins),'truncated'=>$total>20000,
            'note'=>'按近'.$days.'天内同一会话依次完成步骤计数，不含管理员。起点包含已登录回访；空衣橱仅表示当时无衣物，并非一定是新用户。取消选图和最后停留步骤不等于退出原因；跨会话继续录入可能不计入完整漏斗。仅展示最近20次访问，每次最多100条。',
        ]]);
    }
}
