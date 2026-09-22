<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 归一化缓存（2026-09 · P3）
 *
 * 一行 = 某件衣服的某张照片洗出来的白底商品图。
 *
 * 为什么单独一张表：洗图（抠图 + 白底）一次要 15~20 秒而且按次计费，
 * 它是**一件衣服**的属性，不是某次任务的属性。整套试穿里两件衣服各自复用，
 * 同一件衣服在别的搭配里出现也照样复用。
 *
 * source_hash 变了（用户换了照片）→ 自动是新的一行，旧的那行留着不影响。
 */
class TryonGarment extends Model
{
    protected $table = 'tryon_garments';

    protected $fillable = ['user_id', 'item_id', 'source_hash', 'normalized_url'];
}
