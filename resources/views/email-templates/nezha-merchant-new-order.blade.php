<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><title>哪吒外卖新订单</title></head>
<body style="font-family:Arial,sans-serif;color:#202124;line-height:1.6">
<h2>有新订单待处理</h2>
<p>{{ $restaurantName }}，您有一笔新订单。</p>
<ul>
    <li>订单号：#{{ $orderId }}</li>
    <li>订单类型：{{ $orderType }}</li>
    <li>订单金额：{{ $amount }}</li>
</ul>
<p>请尽快打开哪吒商家 App 或商家后台查看并处理。邮件不包含顾客联系方式或付款凭据。</p>
</body>
</html>
