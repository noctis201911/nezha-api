{{-- 哪吒品牌 419(页面已过期/CSRF)页 —— 自包含,不依赖 admin 主题/登录态,任何 419 场景通用 --}}
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>页面已过期 · 哪吒外卖</title>
<style>
  body{margin:0;background:#eef0f3;font-family:-apple-system,BlinkMacSystemFont,'PingFang SC','Microsoft YaHei',Segoe UI,sans-serif;color:#1f2329;}
  .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;}
  .card{background:#fff;border-radius:18px;max-width:420px;width:100%;padding:40px 32px;text-align:center;box-shadow:0 10px 34px rgba(16,42,76,.08);box-sizing:border-box;}
  .logo{width:72px;height:72px;border-radius:16px;display:inline-block;vertical-align:middle;}
  .code{margin:18px 0 4px;color:#9aa3ad;font-size:13px;letter-spacing:3px;}
  h1{margin:0 0 12px;font-size:22px;color:#102A4C;font-weight:700;}
  p{margin:0 0 28px;color:#5a626b;font-size:15px;line-height:1.75;}
  .btn{display:inline-block;background:#102A4C;color:#fff;text-decoration:none;padding:13px 36px;border-radius:10px;font-size:16px;font-weight:600;border:none;cursor:pointer;}
  .foot{margin-top:24px;color:#a4abb3;font-size:12px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <img class="logo" src="https://api.nezha.am/storage/business/nezha-logo-sq.png" alt="哪吒外卖">
    <div class="code">419</div>
    <h1>页面已过期</h1>
    <p>为了您的账号安全，页面停留过久后需要重新载入。<br>请返回上一步重试即可。</p>
    <button class="btn" onclick="if(history.length>1){history.back()}else{location.href='/'}">返回重试</button>
    <div class="foot">哪吒外卖 · 商户平台</div>
  </div>
</div>
</body>
</html>
