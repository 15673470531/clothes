<?php

namespace App\Console\Commands;

use App\Exceptions\TryonException;
use App\Services\ImageStorage;
use App\Services\Tryon\TryonProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 图像归一化复跑命令（2026-09 · AI 试穿）
 *
 * 干什么：把一张实拍衣服照，按指定的**模型 + 提示词**洗成白底商品图，把结果摆在一起给人看。
 *
 * 为什么要有它：归一化的效果完全由「模型 + 提示词」决定，而这两个东西是要反复试的
 * （哪条提示词能把杂物去干净、哪个模型更省钱、plus 版是不是够用……）。
 * 命令就是"拿真图、真调用一次、结果并排看"，一条命令跑完。
 *
 * 通用点（用户要求：以后还会测别的模型）：
 *   - --model 可以给多个（`--model=a --model=b`），一次跑完自动生成横向对比页
 *   - 提示词三处来源：--prompt-file > --prompt > config('tryon.normalize_prompt')
 *   - 图片支持本地路径（自动先传我们 OSS 换成公网 https）或已经是公网 https 直链
 *   - 走的是**线上同一套** TryonProvider（不另写一份调用代码）：这里通了，线上就通；
 *     以后换供应商只改 AppServiceProvider 的绑定，这个命令不用动
 *   - --with-tryon 可以把洗好的白底图接着喂给试衣模型（还有 --tryon-model / --slot），
 *     整条链一起看
 *   - --dry-run 只打印将要发生的事，一个请求都不发（先看不花钱的）
 *
 * ⚠️ 不带 --dry-run 就是**真调用**，成功一张就算一张的钱（结尾会按官方原价估算，
 *    免费额度内不花钱）。只做不花钱的回归验证，跑
 *    tests/Feature/TryonNormalizeCommandTest.php（全部走 Http 桩，不碰网络）。
 *
 * 用法：
 *   docker compose exec app php artisan tryon:normalize storage/jeans.jpg --dry-run
 *   docker compose exec app php artisan tryon:normalize storage/jeans.jpg
 *   docker compose exec app php artisan tryon:normalize storage/jeans.jpg \
 *       --model=qwen-image-edit --model=qwen-image-edit-plus \
 *       --prompt-file=storage/prompt.txt --with-tryon
 */
class TryonNormalize extends Command
{
    /**
     * 官方原价（元/张，华北2北京，2026-09 查的百炼价格页）
     *
     * 只用来在结尾估算"这一趟大概花了多少"。真实扣费以百炼控制台账单为准
     * （还有免费额度、限时优惠这些东西）。表里没有的模型会显示"单价未知"。
     */
    private const PRICES = [
        'qwen-image-edit'      => 0.30,
        'qwen-image-edit-plus' => 0.20,
        'qwen-image-edit-max'  => 0.50,
        'qwen-image-2.0'       => 0.20,
        'qwen-image-2.0-pro'   => 0.50,
        // 3.0 系：输入图 0.02 + 输出图（1K 档）一起算，所以单价按"一张输入一张输出"折算
        'qwen-image-3.0'       => 0.20,
        'qwen-image-3.0-pro'   => 0.27,
        // 万相系（图像生成与编辑）：wan2.7-image-pro 是旗舰，wan2.7-image 是同族便宜档
        'wan2.7-image-pro'     => 0.50,
        'wan2.7-image'         => 0.20,
        'wan2.6-image'         => 0.20,
        'wan2.5-i2i-preview'   => 0.20,
        'wanx2.1-imageedit'    => 0.14,
        'aitryon'              => 0.20,
        'aitryon-plus'         => 0.50,
    ];

    protected $signature = 'tryon:normalize
        {image* : 一张或多张图：本地路径（会被传到我们 OSS 换成公网 https）或已经是公网 https 直链}
        {--model=* : 图像编辑模型，可给多个做横向对比（默认读 config tryon.edit_model）}
        {--prompt= : 归一化提示词（默认读 config tryon.normalize_prompt）}
        {--prompt-file= : 从 txt 文件读提示词（中文长句多、省得在终端里转义）}
        {--out= : 输出目录（默认 storage/app/tryon-test/年月日_时分秒）}
        {--with-tryon : 洗完之后接着跑一次试衣（用 .env 里的 TRYON_MODEL_IMAGE 当模特）}
        {--tryon-model= : 试衣模型（默认读 config tryon.tryon_model，可在 aitryon / aitryon-plus 之间换）}
        {--slot=bottom : 试衣时这件衣服放哪个槽位：top（上装）/ bottom（下装/裙子/裤子）}
        {--url-only : 只打印结果 URL，不下载图片到本地}
        {--dry-run : 只打印将要调用的模型/提示词/图片，不发任何请求（不花钱）}';

    protected $description = '复跑图像归一化（去杂物 → 白底商品图）：支持换模型/换提示词、多模型横向对比';

    public function handle(ImageStorage $storage): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun && empty(config('tryon.api_key'))) {
            $this->error('没配 DASHSCOPE_API_KEY（.env），真跑不了。只想做不花钱的验证：php artisan test --filter=TryonNormalizeCommandTest');

            return self::FAILURE;
        }

        $prompt = $this->resolvePrompt();
        if (empty($prompt)) {
            return self::FAILURE;
        }

        // 提示词同样"借配置"传下去：DashScopeProvider 读的是 config('tryon.normalize_prompt')，
        // 命令不另开一条调用路径（这条正是被测试抓出来的 bug —— 只打印不改配置的话，
        // 页面看着是新提示词，实际发出去的还是旧的那条）
        config(['tryon.normalize_prompt' => $prompt]);

        $models = $this->resolveModels();
        $images = $this->resolveImages((array) $this->argument('image'));

        if (empty($images)) {
            $this->error('没有可用的图片');

            return self::FAILURE;
        }

        $withTryon    = (bool) $this->option('with-tryon');
        $tryonModel   = (string) ($this->option('tryon-model') ?: config('tryon.tryon_model'));
        $slot         = strtolower((string) $this->option('slot')) == 'top' ? 'top' : 'bottom';
        $urlOnly      = (bool) $this->option('url-only');
        $personUrl    = (string) config('tryon.model_image');

        if ($withTryon && empty($personUrl)) {
            $this->warn('--with-tryon 要模特图，但 .env 的 TRYON_MODEL_IMAGE 是空的，这次只跑归一化');
            $withTryon = false;
        }

        $this->newLine();
        $this->line('<info>模型</info>      ' . implode('  |  ', $models) . ($withTryon ? '   → 再试穿：' . $tryonModel . '（' . $slot . ' 槽位）' : ''));
        $this->line('<info>提示词</info>    ' . $prompt);
        $this->line('<info>图片</info>      ' . count($images) . ' 张');

        if ($dryRun) {
            foreach ($images as $img) {
                $this->line('  · ' . $img['label'] . '  ' . $img['local']);
            }
            $this->newLine();
            $this->info('这是 --dry-run，什么都没调用，不花钱。去掉它就真跑。');

            return self::SUCCESS;
        }

        $out = (string) ($this->option('out') ?: storage_path('app/tryon-test/' . date('Ymd_His')));
        if (!is_dir($out) && !mkdir($out, 0755, true) && !is_dir($out)) {
            $this->error('建不了输出目录：' . $out);

            return self::FAILURE;
        }

        $rows   = [];
        $spent  = [];      // 模型 => 成功张数（用来估花费）
        $failed = 0;

        foreach ($images as $img) {
            // 本地图先换成公网 https 直链（阿里云那边的数据检查只吃公网 https）
            $url = $this->publicUrl($img, $storage);
            if (empty($url)) {
                $failed++;
                continue;
            }

            $row = ['label' => $img['label'], 'source' => $url, 'original' => $this->keepOriginal($img, $out, $urlOnly), 'cells' => []];

            foreach ($models as $model) {
                // 命令内临时改配置：进程结束就没了，不会影响线上跑着的服务。
                // 注意改的是 normalize_model —— DashScopeProvider 读的是这个键（edit_model 只是兜底）
                config(['tryon.normalize_model' => $model]);

                $this->line('  · ' . $img['label'] . '  ×  ' . $model . ' …… ');
                $cell = ['model' => $model, 'ok' => false, 'ms' => 0, 'url' => '', 'file' => '', 'error' => '', 'tryon_url' => '', 'tryon_file' => '', 'tryon_error' => ''];

                $t0 = microtime(true);
                try {
                    $resultUrl = app(TryonProvider::class)->normalize($url);
                    $cell['ok']  = true;
                    $cell['url'] = $resultUrl;

                    if (!$urlOnly && !empty($resultUrl)) {
                        $cell['file'] = $this->download($resultUrl, $out, 'out-' . $img['label'] . '__' . $model);
                    }

                    $spent[$model] = ($spent[$model] ?? 0) + 1;

                    // 接着试穿：拿洗好的白底图当衣物图喂给试衣模型
                    if ($withTryon) {
                        config(['tryon.tryon_model' => $tryonModel]);
                        try {
                            $shirt = $slot == 'top' ? $resultUrl : null;
                            $pants = $slot == 'bottom' ? $resultUrl : null;

                            $tryonUrl = app(TryonProvider::class)->tryOn($personUrl, $shirt, $pants);
                            $cell['tryon_url'] = $tryonUrl;

                            if (!$urlOnly && !empty($tryonUrl)) {
                                $cell['tryon_file'] = $this->download($tryonUrl, $out, 'tryon-' . $img['label'] . '__' . $model);
                            }

                            $spent[$tryonModel] = ($spent[$tryonModel] ?? 0) + 1;
                        } catch (Throwable $e) {
                            $cell['tryon_error'] = $this->reason($e);
                        }
                    }
                } catch (Throwable $e) {
                    $cell['error'] = $this->reason($e);
                    $failed++;
                }

                $cell['ms'] = (int) round((microtime(true) - $t0) * 1000);
                $this->line('      ' . ($cell['ok'] ? '<info>成功</info> ' . round($cell['ms'] / 1000, 1) . ' 秒' : '<error>失败</error> ' . $cell['error']));

                $row['cells'][] = $cell;
            }

            $rows[] = $row;
        }

        $meta = [
            'ran_at'      => date('Y-m-d H:i:s'),
            'prompt'      => $prompt,
            'models'      => $models,
            'with_tryon'  => $withTryon,
            'tryon_model' => $withTryon ? $tryonModel : null,
            'slot'        => $withTryon ? $slot : null,
            'spent'       => $spent,
            'rows'        => $rows,
        ];

        file_put_contents($out . '/result.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($out . '/index.html', $this->render($meta));

        $this->newLine();

        $tableRows = [];
        foreach ($rows as $row) {
            foreach ($row['cells'] as $cell) {
                $tableRows[] = [
                    $row['label'],
                    $cell['model'],
                    $cell['ok'] ? '成功' : '失败：' . $cell['error'],
                    round($cell['ms'] / 1000, 1) . 's',
                ];
            }
        }

        $this->table(['图片', '模型', '结果', '耗时'], $tableRows);

        $this->line('花费估算（官方原价，免费额度内实际为 0）：' . $this->money($spent));
        $this->line('输出目录：' . $out);
        $this->line('对比页：项目根目录下执行 open ' . $this->hostHint($out) . '/index.html');

        if ($failed > 0) {
            $this->warn($failed . ' 次失败（失败不计费也不扣免费额度），原因都写在 result.json 里');

            return count($rows) > 0 && $failed >= count($rows) * count($models) ? self::FAILURE : self::SUCCESS;
        }

        return self::SUCCESS;
    }

    // ===== 内部 =====

    /** 提示词：文件 > 命令行 > 配置 */
    private function resolvePrompt(): string
    {
        $file = (string) $this->option('prompt-file');
        if (!empty($file)) {
            if (!is_file($file)) {
                $this->error('找不到提示词文件：' . $file);

                return '';
            }

            return trim((string) file_get_contents($file));
        }

        $inline = (string) $this->option('prompt');
        if (!empty($inline)) {
            return trim($inline);
        }

        $fromConfig = trim((string) config('tryon.normalize_prompt'));
        if (empty($fromConfig)) {
            $this->error('没给 --prompt，config tryon.normalize_prompt 也是空的');
        }

        return $fromConfig;
    }

    /** 要测哪些模型：--model 给了就用给的（可多个），没给就用配置里那个 */
    private function resolveModels(): array
    {
        $models = array_values(array_filter(array_map('trim', (array) $this->option('model'))));

        if (empty($models)) {
            $models = [(string) config('tryon.edit_model')];
        }

        /**
         * docker-compose exec app php artisan tryon:normalize storage/app/jeans.jpg \
        --model=qwen-image-3.0 --model=qwen-image-edit \
        --prompt-file=storage/prompt.txt --with-tryon --slot=bottom
         *
         */
        return array_values(array_unique(array_filter($models)));
    }

    /**
     * 图片参数归一成 [{label, local, url}]
     *
     * 本地路径要记下来（对比页要显示原图），公网地址就直接用。
     * 传 http:// 会警告一句：阿里云的数据检查只认 https，多半会被拒。
     */
    private function resolveImages(array $inputs): array
    {
        $images = [];

        foreach ($inputs as $input) {
            $input = trim((string) $input);
            if (empty($input)) {
                continue;
            }

            if (preg_match('#^https?://#i', $input)) {
                if (stripos($input, 'http://') === 0) {
                    $this->warn('这张是 http:// 地址：' . $input . ' —— 阿里云数据检查只认 https，大概率会被拒');
                }

                $label = $this->label(pathinfo((string) parse_url($input, PHP_URL_PATH), PATHINFO_FILENAME) ?: 'remote');
                $images[] = ['label' => $label, 'local' => $input, 'remote' => true];

                continue;
            }

            if (!is_file($input)) {
                $this->error('找不到图片：' . $input);

                continue;
            }

            $images[] = ['label' => $this->label(pathinfo($input, PATHINFO_FILENAME)), 'local' => $input, 'remote' => false];
        }

        return $images;
    }

    /** 把这张图变成公网 https 直链（本地图先传我们 OSS） */
    private function publicUrl(array $img, ImageStorage $storage): string
    {
        if (!empty($img['remote'])) {
            return (string) $img['local'];
        }

        $contents = (string) file_get_contents((string) $img['local']);
        if (empty($contents)) {
            $this->error('读不出文件内容：' . $img['local']);

            return '';
        }

        try {
            $put = $storage->putBinary($contents, strtolower((string) pathinfo((string) $img['local'], PATHINFO_EXTENSION)) ?: 'jpg', 'tryon/test');
        } catch (Throwable $e) {
            $this->error('传 OSS 失败：' . $e->getMessage());

            return '';
        }

        $this->line('      已换成公网地址（' . $put['driver'] . '）：' . $put['url']);

        return (string) $put['url'];
    }

    /** 本地图拷一份进输出目录当"原图"列；远程图顺手下载一份（下载失败不影响主流程） */
    private function keepOriginal(array $img, string $out, bool $urlOnly): string
    {
        if ($urlOnly) {
            return '';
        }

        $ext = strtolower((string) pathinfo((string) $img['local'], PATHINFO_EXTENSION)) ?: 'jpg';
        $file = 'source-' . $img['label'] . '.' . $ext;

        if (empty($img['remote'])) {
            @copy((string) $img['local'], $out . '/' . $file);

            return is_file($out . '/' . $file) ? $file : '';
        }

        return $this->download((string) $img['local'], $out, 'source-' . $img['label']);
    }

    /** 把远程结果下载到输出目录，返回文件名（失败返回空串） */
    private function download(string $url, string $out, string $name): string
    {
        try {
            $res = Http::timeout(180)->get($url);
            if (!$res->successful()) {
                $this->warn('  下载失败（HTTP ' . $res->status() . '）：' . $url);

                return '';
            }

            $ext  = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            $ext  = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'png';
            $file = $name . '.' . $ext;

            file_put_contents($out . '/' . $file, $res->body());

            return $file;
        } catch (Throwable $e) {
            $this->warn('  下载失败：' . $e->getMessage());

            return '';
        }
    }

    /** 失败原因（业务异常带上供应商的码和原话，方便分辨是限流还是图片不合规） */
    private function reason(Throwable $e): string
    {
        $code = $e instanceof TryonException ? '[' . $e->apiCode() . '] ' : '';

        return $code . $e->getMessage();
    }

    /** 文件名安全化：中文和符号都换掉，免得下载/打开出问题 */
    private function label(string $raw): string
    {
        $safe = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $raw);
        $safe = trim((string) $safe, '-');

        return empty($safe) ? 'image' : mb_substr($safe, 0, 40);
    }

    /** 花费估算文本 */
    private function money(array $spent): string
    {
        if (empty($spent)) {
            return '没有成功调用，0 元';
        }

        $parts = [];
        $total = 0.0;
        $unknown = [];

        foreach ($spent as $model => $count) {
            if (!isset(self::PRICES[$model])) {
                $unknown[] = $model . ' × ' . $count;

                continue;
            }

            $cost    = self::PRICES[$model] * $count;
            $total  += $cost;
            $parts[] = $model . ' ' . $count . ' 张 × ' . self::PRICES[$model] . ' = ' . number_format($cost, 2) . ' 元';
        }

        if (!empty($unknown)) {
            $parts[] = '单价未知（去控制台看账单）：' . implode('、', $unknown);
        }

        return implode('；', $parts) . '；合计约 ' . number_format($total, 2) . ' 元';
    }

    /** 容器里的路径 → 项目根目录下的相对路径（方便在宿主机上 open） */
    private function hostHint(string $out): string
    {
        $public = storage_path('app');

        return str_starts_with($out, $public) ? 'storage/app' . substr($out, strlen($public)) : $out;
    }

    /** 生成对比页（自包含，图用同目录相对路径，双击就能看） */
    private function render(array $meta): string
    {
        $h = fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $cols = ['<th>原图</th>'];
        foreach ($meta['models'] as $m) {
            $cols[] = '<th>' . $h($m) . '</th>' . ($meta['with_tryon'] ? '<th>' . $h($meta['tryon_model']) . '（试穿）</th>' : '');
        }

        $body = '';
        foreach ($meta['rows'] as $row) {
            $body .= '<tr>';
            $body .= '<td>' . ($row['original'] ? '<img src="' . $h($row['original']) . '">' : ($row['source'] ? '<a href="' . $h($row['source']) . '">原图地址</a>' : '—')) . '<div class="cap">' . $h($row['label']) . '</div></td>';

            foreach ($row['cells'] as $c) {
                $body .= '<td>' . ($c['file'] ? '<img src="' . $h($c['file']) . '">' : ($c['ok'] ? '<div class="miss">只返回了地址<br><a href="' . $h($c['url']) . '">点开看</a></div>' : '<div class="err">失败<br>' . $h($c['error']) . '</div>'))
                    . '<div class="cap">' . ($c['ok'] ? round($c['ms'] / 1000, 1) . 's' : '—') . '</div></td>';

                if ($meta['with_tryon']) {
                    $body .= '<td>' . ($c['tryon_file'] ? '<img src="' . $h($c['tryon_file']) . '">' : '<div class="' . ($c['tryon_error'] ? 'err' : 'miss') . '">' . ($c['tryon_error'] ? $h($c['tryon_error']) : '没跑') . '</div>') . '</td>';
                }
            }

            $body .= '</tr>';
        }

        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>归一化复跑 · ' . $h($meta['ran_at']) . '</title>'
            . '<style>'
            . 'body{margin:0;padding:28px 32px 60px;background:#F1EEF4;color:#221E2A;font-family:-apple-system,"PingFang SC",sans-serif}'
            . 'h1{font-size:19px;margin:0 0 6px}.sub{font-size:13px;color:#6E6779;line-height:1.8;margin-bottom:20px}'
            . '.prompt{background:#fff;border-radius:12px;padding:14px 16px;font-size:13px;line-height:1.8;max-width:1200px;box-shadow:0 2px 10px rgba(74,58,92,.07);margin-bottom:22px}'
            . 'table{border-collapse:separate;border-spacing:14px;background:#FBF8F4;border-radius:16px;box-shadow:0 3px 16px rgba(74,58,92,.12)}'
            . 'th{font-size:13px;color:#585064;font-weight:600;padding:6px 0}'
            . 'td{vertical-align:top;text-align:center;width:280px;background:#fff;border-radius:12px;padding:10px}'
            . 'td img{width:100%;display:block;border-radius:8px}'
            . '.cap{font-size:12px;color:#8F8799;margin-top:8px}.err{font-size:12px;color:#C05A5A;padding:20px 4px}.miss{font-size:12px;color:#8F8799;padding:20px 4px}'
            . '</style></head><body>'
            . '<h1>归一化复跑（去杂物 → 白底商品图）</h1>'
            . '<div class="sub">跑的模型：' . $h(implode('、', $meta['models'])) . ($meta['with_tryon'] ? '　→　试衣：' . $h($meta['tryon_model']) . '（' . $h($meta['slot']) . ' 槽位）' : '') . '<br>时间：' . $h($meta['ran_at']) . '</div>'
            . '<div class="prompt"><b>提示词</b><br>' . nl2br($h($meta['prompt'])) . '</div>'
            . '<table><tr>' . implode('', $cols) . '</tr>' . $body . '</table>'
            . '</body></html>';
    }
}
