<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>哪吒外卖 - 埃里温专属</title>
    <!-- 引入图标库 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* 全局重置 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'PingFang SC', 'Microsoft YaHei', -apple-system, sans-serif;
            background-color: #FDFBF7; /* 暖米色背景 */
            color: #4A3B32; /* 深咖啡色文字 */
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* 主容器 */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            position: relative;
            z-index: 1;
        }

        /* 顶部导航 */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 0;
        }
        .logo {
            font-size: 26px;
            font-weight: 800;
            color: #D35400; /* 焦糖色 Logo */
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .lang-btn {
            background: #FFF;
            border: 1px solid #E0D6CC;
            padding: 8px 20px;
            border-radius: 30px;
            color: #8D7B68;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .lang-btn:hover {
            border-color: #D35400;
            color: #D35400;
        }

        /* Hero 区域 */
        .hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 60px 0 100px;
            gap: 60px;
        }

        .hero-text {
            flex: 1;
        }

        .title {
            font-size: 3.5rem;
            line-height: 1.2;
            font-weight: 800;
            color: #3E2723;
            margin-bottom: 20px;
        }
        .title span {
            color: #D35400;
            position: relative;
            display: inline-block;
        }
        /* 手写风格下划线 */
        .title span::after {
            content: '';
            position: absolute;
            bottom: 5px;
            left: 0;
            width: 100%;
            height: 8px;
            background: rgba(211, 84, 0, 0.15);
            z-index: -1;
            transform: rotate(-1deg);
            border-radius: 4px;
        }

        .subtitle {
            font-size: 1.2rem;
            color: #6D5D52;
            margin-bottom: 35px;
            max-width: 520px;
        }

        /* 按钮组 */
        .btn-group {
            display: flex;
        }
        .btn {
            padding: 16px 40px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1.1rem;
            text-decoration: none;
            transition: transform 0.2s, box-shadow 0.2s;
            display: inline-block;
        }
        .btn-primary {
            background: #D35400;
            color: white;
            box-shadow: 0 10px 25px rgba(211, 84, 0, 0.25);
        }
        .btn-primary:hover {
            transform: translateY(-3px);
            background: #E67E22;
            box-shadow: 0 15px 30px rgba(211, 84, 0, 0.3);
        }

        /* 右侧图片区 */
        .hero-image {
            flex: 1;
            position: relative;
        }
        .food-card {
            background: white;
            padding: 15px;
            border-radius: 30px;
            box-shadow: 0 20px 60px rgba(62, 39, 35, 0.1);
            transform: rotate(2deg);
            transition: transform 0.5s ease;
            position: relative;
        }
        .food-card:hover {
            transform: rotate(0deg) scale(1.02);
        }

        /* 宫保鸡丁图片 */
        .img-placeholder {
            width: 100%;
            height: 380px;
            background: url('https://images.unsplash.com/photo-1525755662778-989d0524087e?ixlib=rb-1.2.1&auto=format&fit=crop&w=800&q=80') center/cover no-repeat;
            border-radius: 20px;
            position: relative;
        }
        .img-placeholder::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.1), transparent);
            border-radius: 20px;
        }

        /* 送达中徽章 */
        .badge {
            position: absolute;
            top: -10px;
            right: -10px;
            background: #27AE60;
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.4);
            font-size: 0.9rem;
            text-align: center;
            line-height: 1.2;
            z-index: 2;
        }

        /* 特性介绍区 */
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 100px;
        }
        .feature-item {
            background: #FFF;
            padding: 35px 30px;
            border-radius: 24px;
            text-align: left;
            border: 1px solid #F2ECE4;
            transition: all 0.3s;
        }
        .feature-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.05);
            border-color: transparent;
        }
        .icon-box {
            width: 55px;
            height: 55px;
            background: #FFF3E0;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }
        .icon-box i {
            font-size: 24px;
            color: #D35400;
        }
        .f-title {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: #3E2723;
            font-weight: 700;
        }
        .f-desc {
            color: #8D7B68;
            font-size: 0.95rem;
        }

        /* 背景装饰斑点 */
        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 0;
        }
        .blob-1 {
            width: 400px;
            height: 400px;
            background: rgba(255, 224, 178, 0.4);
            top: -100px;
            right: -100px;
        }
        .blob-2 {
            width: 300px;
            height: 300px;
            background: rgba(211, 84, 0, 0.08);
            bottom: 0;
            left: -50px;
        }

        /* 移动端适配 */
        @media (max-width: 768px) {
            .hero { flex-direction: column-reverse; text-align: center; padding: 40px 0; }
            .title { font-size: 2.5rem; }
            .subtitle { margin: 0 auto 30px; }
            .btn-group { justify-content: center; width: 100%; }
            .btn { width: 100%; text-align: center; }
            .img-placeholder { height: 250px; }
            .food-card { transform: rotate(0); }
        }
    </style>
</head>
<body>

    <!-- 背景装饰 -->
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

    <div class="container">
        <header>
            <div class="logo"><i class="fas fa-bowl-rice"></i> 哪吒外卖</div>
            <!-- 语言切换按钮 -->
            <button class="lang-btn" id="lang-btn" onclick="toggleLanguage()">EN / 中文</button>
        </header>

        <section class="hero">
            <div class="hero-text">
                <!-- 需要翻译的内容加 ID -->
                <h1 class="title" id="main-title">用中文，<br><span>点遍美味</span></h1>
                <!-- 修改后的中文文案 -->
                <p class="subtitle" id="subtitle">最懂华人的味道。这里不只是外卖，更懂你的需求。</p>
                <div class="btn-group">
                    <a href="https://nezha.am/home" class="btn btn-primary" id="cta-btn">立即点餐</a>
                </div>
            </div>
            <div class="hero-image">
                <div class="food-card">
                    <div class="img-placeholder"></div>
                    <div class="badge" id="badge-text">送<br>达<br>中</div>
                </div>
            </div>
        </section>

        <section class="features">
            <div class="feature-item">
                <div class="icon-box"><i class="fas fa-motorcycle"></i></div>
                <h3 class="f-title" id="f1-title">全城速送</h3>
                <p class="f-desc" id="f1-desc">不管你在埃里温哪里，热乎乎的饭菜都能快速送到你手上。</p>
            </div>
            <div class="feature-item">
                <div class="icon-box"><i class="fas fa-language"></i></div>
                <h3 class="f-title" id="f2-title">无障碍沟通</h3>
                <p class="f-desc" id="f2-desc">全中文界面，客服全程在线沟通，再也不怕点错菜。</p>
            </div>
            <div class="feature-item">
                <div class="icon-box"><i class="fas fa-wallet"></i></div>
                <h3 class="f-title" id="f3-title">支付更轻松</h3>
                <p class="f-desc" id="f3-desc">支持支付宝、微信支付、USDT，像在国内一样方便。</p>
            </div>
        </section>
    </div>

    <script>
        let currentLang = 'zh';

        const translations = {
            zh: {
                title: '用中文，<br><span>点遍美味</span>',
                // 修改后的中文文案
                subtitle: '最懂华人的味道。这里不只是外卖，更懂你的需求。',
                btn: '立即点餐',
                btnText: 'EN / 中文',
                badge: '送<br>达<br>中',
                f1Title: '全城速送',
                f1Desc: '不管你在埃里温哪里，热乎乎的饭菜都能快速送到你手上。',
                f2Title: '无障碍沟通',
                f2Desc: '全中文界面，客服全程在线沟通，再也不怕点错菜。',
                f3Title: '支付更轻松',
                f3Desc: '支持支付宝、微信支付、USDT，像在国内一样方便。'
            },
            en: {
                title: 'Order Delicious Food<br><span>In Chinese</span>',
                // 对应修改后的英文文案
                subtitle: 'The flavor that understands you best. Not just delivery, but meeting your needs.',
                btn: 'Order Now',
                btnText: '中文 / EN',
                badge: 'On<br>The<br>Way',
                f1Title: 'Citywide Delivery',
                f1Desc: 'Wherever you are in Yerevan, hot food will be delivered to you quickly.',
                f2Title: 'Language Barrier-Free',
                f2Desc: 'Full Chinese interface and customer service support, so you never order the wrong dish.',
                f3Title: 'Easy Payment',
                f3Desc: 'Supports Alipay, WeChat Pay, USDT, as convenient as back home.'
            }
        };

        function toggleLanguage() {
            // 切换状态
            currentLang = currentLang === 'zh' ? 'en' : 'zh';
            const t = translations[currentLang];

            // 替换文字
            document.getElementById('main-title').innerHTML = t.title;
            document.getElementById('subtitle').textContent = t.subtitle;
            document.getElementById('cta-btn').textContent = t.btn;
            document.getElementById('lang-btn').textContent = t.btnText;
            document.getElementById('badge-text').innerHTML = t.badge;

            // 替换功能区文字
            document.getElementById('f1-title').textContent = t.f1Title;
            document.getElementById('f1-desc').textContent = t.f1Desc;
            document.getElementById('f2-title').textContent = t.f2Title;
            document.getElementById('f2-desc').textContent = t.f2Desc;
            document.getElementById('f3-title').textContent = t.f3Title;
            document.getElementById('f3-desc').textContent = t.f3Desc;
        }
    </script>
</body>
</html>
