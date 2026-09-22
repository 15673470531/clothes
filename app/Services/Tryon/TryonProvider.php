<?php

namespace App\Services\Tryon;

/**
 * 试穿供应商接口（2026-09 · P2）
 *
 * 只暴露两步，页面/队列都不关心是哪家：
 *   normalize() 把用户的实拍衣物图洗成"白底商品图"（这一步决定了后面试穿的质量）
 *   tryOn()     把某件衣服穿到虚拟模特身上
 *
 * 换厂商 / 加自建 GPU 只改实现类；测试里换成假实现就能离线跑。
 */
interface TryonProvider
{
    /**
     * 归一化：抠出衣服 + 白底商品图
     * @param  string $imageUrl 公网可访问的 https 直链（用户衣物照片）
     * @return string 处理后的图地址（公网 https）
     */
    public function normalize(string $imageUrl): string;

    /**
     * 试穿（上装 / 下装两个槽位，**可以只给一个**）
     *
     * 整套搭配要一次把两件都传进去：只传一件时，接口会自己给模特配它认为搭调的
     * 另一件（实测：单独传上衣它会配蓝牛仔 + 黑靴），那就不是用户的搭配了。
     *
     * @param  string      $personUrl  虚拟模特图（我们自己的公网 https）
     * @param  string|null $topUrl     上装白底图（外套、连衣裙也走这里）
     * @param  string|null $bottomUrl  下装白底图（裤子、裙子）
     * @return string 结果图地址（公网 https，需尽快转存到我们 OSS）
     */
    public function tryOn(string $personUrl, ?string $topUrl, ?string $bottomUrl): string;
}
