# PLAN: 一键今日售罄 (merchant one-tap "sold out today")

状态: 方案已批准(用户 2026-07-01), 待新窗口实现。用户已明确: **直接建、分态验证到位、不走 /debate**。

## 目标
商家在菜品列表**一键**把某菜标记"今日售罄" → 顾客端该菜灰置显示「已售罄」+ 无法加购/下单; **次日自动恢复可售**(纯日期比较, 无需定时任务)。对标美团/饿了么"沽清"。菜品**仍可见**(非下架隐藏), 让顾客知道明天还有。

## 已批准设计: 独立"今日售罄"日期标记 (不碰现有数量库存)
**为什么不复用现有 sell_count/total_stock 库存**:
- ① 对 `stock_type='unlimited'` 菜品无效 — 下单校验对 unlimited 跳过库存检查(`Helpers.php:4811` `if ($product?->stock_type && $product?->stock_type !== 'unlimited')`)
- ② 把 sell_count 设满会污染真实销量计数
- ③ 恢复逻辑绕
独立日期标记: 干净 / 覆盖所有 stock_type / 天然次日恢复。

## 现状 recon (已查实, 别重查)
- **库存模型(Food)**: `item_stock`(accessor `Food.php:313`)=`total_stock - sell_count`(min0); `current_stock`=`stock_type==='unlimited'?0:max(0,total_stock-sell_count)`。
- **daily 自动重置**(Food::boot `retrieved`, `Food.php ~234-247`): stock_type='daily' 的菜每日首次加载时若"有历史订单但今天无订单"→`sell_count=0`。现有次日恢复(仅 daily)。我们的标记不依赖它。
- **下单/加购校验**: `Helpers::addonAndVariationStockCheck` (`Helpers.php:4808`)。非 unlimited 时 `item_stock<=0`→返回 `['out_of_stock'=>...,'current_stock'=>0]` 挡单; 成功则 increment sell_count。**在此函数开头加"今日售罄"判定(不分 stock_type)**。⚠️先 grep 它的调用点(加购 + place-order 两条路径都要覆盖)。
- **序列化**: Food accessors(item_stock/variations current_stock)直接进 JSON。找顾客端 food 列表/详情序列化处(FoodLogic / Helpers 的 format food / Api FoodController)加 `is_sold_out`。
- **商家菜品列表** `resources/views/vendor-views/product/list.blade.php`: 每行已有 toggle: `food.status`(上下架 GET status/{id}/{status})、`food.recommended`(招牌 GET recommended/{id}/{status})、`food.updateStock`(改库存 modal)、`food.updatePrice`(快捷改价)。**照 statusCheckbox/stocksCheckbox 的 `.redirect-url` data-url toggle 套路加"售罄"**。
- **路由组**: `routes/vendor.php` 约168行 `Route::group(['prefix'=>'food','as'=>'food.',...])`, FoodController。缺货列表已有 `food.stockOutList`。
- **顾客前端读库存/售罄的文件(11处, 已定位)**: `src/components/food-card/FoodCard.js`+`FoodCardIncrementAndDecrement.js`+`QuickView.js` / `new-food-card/NewFoodCard.jsx` / `foodDetail-modal/FoodDetailModal.js`+`MultiCheckBox.js`+`ChoiceValues.js`+`StartPriceView.js` / `floating-cart/CartContent.js` / `restaurant-details/TopSellersRail.jsx` / `navbar/second-navbar/SecondNavbar.js`。读 `item_stock`/`current_stock`/`maximum_cart_quantity`。
- git 史: 无 nezha 库存/售罄改动(StackFood 原生), 不冲突。

## 实现清单
### 1. 迁移
- Food 加列 `nezha_sold_out_date` DATE NULL。(MVP 先整菜级; 变体级 newVariationOptions 售罄待定。) 部署走 nzdeploy-api 自动 migrate --force。
### 2. Food model
- `nezha_sold_out_date` 加 casts `'date'` + fillable。
- `public function isSoldOutToday(): bool { return $this->nezha_sold_out_date && $this->nezha_sold_out_date->isToday(); }`。时区已 Asia/Yerevan(now()/today() 即本地, 见 timezone memory)。
### 3. 后端校验+序列化
- `addonAndVariationStockCheck` 开头(独立 stock_type): `if ($product?->isSoldOutToday()) return ['out_of_stock'=>$product->name.' 今日已售罄','current_stock'=>0, ...];`
- food 序列化加 `is_sold_out`=isSoldOutToday。
### 4. 顾客前端(扩展现有缺货 UI, 不新造)
- 11 文件里找现有"缺货/soldout"分支(item_stock<=0), 让它 **OR is_sold_out** → 灰置+角标「已售罄」+ 禁用加购。菜品仍可见。
- ⚠️本地副本过时且**结构性分叉**, 必先 `nz.js get` 服务器版为基线(见 local-copy-stale memory)。
### 5. 商家面板一键 toggle
- 路由 `Route::get('sold-out/{id}/{flag}', [FoodController::class,'soldOut'])->name('sold-out');` 进 food 组。
- `FoodController@soldOut`: flag=1→`nezha_sold_out_date=today()`; flag=0→null; Toastr 中文。
- `product/list.blade.php` 每行加"售罄"toggle(照 statusCheckbox `.redirect-url` data-url), 已售罄态高亮。

## 分态验证 (用户要求"分态验证到位")
- 后端: tinker/进程内验 isSoldOutToday + addonAndVariationStockCheck 对 [正常菜/售罄菜/unlimited售罄菜/售罄隔天] 分别正确(挡/放)。
- 商家: blade render 非500 + toggle 路由通(商家后台验证码无法 Playwright, 用 blade-render-verify-in-process)。
- 顾客前端: **Playwright 真机截图三态(正常/售罄/unlimited售罄)+ console 0 err**, 截图给用户点头再上线(前端铁律)。测试注入见 CLAUDE.md(zoneid/location/GPS)。
- 次日恢复: 造 nezha_sold_out_date=昨天 验证自动可售。

## 部署 (照 0701 本会话套路)
- 后端: `bash /www/wwwroot/nzcommit.sh /www/wwwroot/api.nezha.am -b <b64中文msg> <files>` → `git push` → `bash /www/wwwroot/api-deploy/nzdeploy-api.sh`(release部署+blade-probe+健康门已--resolve直连origin)。
- 前端: nzcommit → `git push origin HEAD:main`(前端非main分支) → `bash /www/wwwroot/nezha.am/nzbuild.sh`。
- 大文件写: base64-run 限~32KB; 超了用 `scratchpad/nzput_big.js`(单连接分块append, 0701已验可靠)。净删>15行加 `[force-revert]` 过 commit-msg 墙。
- nz.js 引号: 远程命令内**只用单引号** grep 模式(双引号+`|`会被 PowerShell 吞), 中文进命令行会乱码→走 base64。

## 风险 / 已定
- 动了下单/加购校验 → 改坏会误挡真实订单或放过售罄单 → 分三态验证兜底。用户已明确不走 /debate、直接建。
