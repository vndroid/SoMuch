<!DOCTYPE HTML>
<html lang="zh">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <title>搜索超限提醒</title>
    <link rel="shortcut icon" href="<?= htmlspecialchars($rootUrl) ?>/favicon.ico">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .box {
            text-align: center;
        }

        .notice-image {
            margin-bottom: 20px;
        }

        .notice-content {
            font-size: 16px;
            line-height: 30px;
            color: #333333;
            margin-bottom: 30px;
        }

        .back-home {
            display: inline-block;
            color: #333;
            font-size: 13px;
            text-decoration: none;
            width: 100px;
            height: 32px;
            line-height: 32px;
            border: 1px solid #E3E2E8;
        }
    </style>
</head>
<body>
<div class="box">
    <div class="notice-image">
        <img src="<?= htmlspecialchars($pluginUrl) ?>/SoMuch/404.png" alt=""/>
    </div>
    <div class="notice-content">
        <?= htmlspecialchars($content) ?>
    </div>
    <a href="<?= htmlspecialchars($rootUrl) ?>" class="back-home">返回首页</a>
</div>
</body>
</html>
