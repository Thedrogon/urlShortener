<?php
require_once 'db.php';
require_once 'phpqrcode/qrlib.php';

$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

function generateShortCode($length = 6) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

function getUniqueShortCode($pdo, $maxAttempts = 10) {
    $attempts = 0;
    do {
        $shortCode = generateShortCode();
        $stmt = $pdo->prepare("SELECT id FROM urls WHERE short_code = ?");
        $stmt->execute([$shortCode]);
        $exists = $stmt->fetch();
        $attempts++;
    } while ($exists && $attempts < $maxAttempts);
    if ($exists) {
        die("Could not generate a unique short code");
    }
    return $shortCode;
}

if ($requestUri == '/') {
    if ($requestMethod == 'POST') {
        $originalUrl = $_POST['url'];
        $customCode = $_POST['custom_code'] ?? '';
        if (filter_var($originalUrl, FILTER_VALIDATE_URL)) {
            $shortCode = null;
            if ($customCode) {
                if (!preg_match('/^[a-zA-Z0-9]{3,10}$/', $customCode)) {
                    $error = "Custom short code must be alphanumeric and between 3 to 10 characters";
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM urls WHERE short_code = ?");
                    $stmt->execute([$customCode]);
                    if ($stmt->fetch()) {
                        $error = "Custom short code already in use";
                    } else {
                        $shortCode = $customCode;
                    }
                }
            } else {
                $shortCode = getUniqueShortCode($pdo);
            }
            if ($shortCode) {
                $stmt = $pdo->prepare("INSERT INTO urls (original_url, short_code) VALUES (?, ?)");
                $stmt->execute([$originalUrl, $shortCode]);
                $shortUrl = "http://localhost/r/$shortCode";
                ob_start();
                QRcode::png($shortUrl, false, QR_ECLEVEL_L, 3, 2);
                $qrCode = ob_get_clean();
            }
        } else {
            $error = "Invalid URL";
        }
    }
?>
<!DOCTYPE html>
<html>
<head>
    <title>URL Shortener</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-5">
        <h1>URL Shortener</h1>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <form action="/" method="post">
            <div class="form-group">
                <label for="url">Enter URL</label>
                <input type="url" class="form-control" id="url" name="url" required>
            </div>
            <div class="form-group">
                <label for="custom_code">Custom Short Code (optional)</label>
                <input type="text" class="form-control" id="custom_code" name="custom_code" placeholder="3-10 characters">
            </div>
            <button type="submit" class="btn btn-primary">Shorten</button>
        </form>
        <?php if (isset($shortUrl)): ?>
            <div class="mt-3">
                <p>Your short URL: <a href="<?php echo $shortUrl; ?>"><?php echo $shortUrl; ?></a></p>
                <p>View stats: <a href="/stats/<?php echo $shortCode; ?>">here</a></p>
                <div class="input-group mb-3">
                    <input type="text" id="shortUrl" value="<?php echo $shortUrl; ?>" readonly class="form-control">
                    <div class="input-group-append">
                        <button onclick="copyToClipboard()" class="btn btn-secondary">Copy</button>
                    </div>
                </div>
                <img src="data:image/png;base64,<?php echo base64_encode($qrCode); ?>" alt="QR Code" class="img-fluid" style="max-width: 200px;">
            </div>
            <script>
                function copyToClipboard() {
                    var copyText = document.getElementById("shortUrl");
                    copyText.select();
                    document.execCommand("copy");
                    alert("Copied to clipboard");
                }
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
} elseif (preg_match('/^\/r\/(\w+)$/', $requestUri, $matches)) {
    $shortCode = $matches[1];
    $stmt = $pdo->prepare("SELECT id, original_url FROM urls WHERE short_code = ?");
    $stmt->execute([$shortCode]);
    $row = $stmt->fetch();
    if ($row) {
        $originalUrl = $row['original_url'];
        $urlId = $row['id'];
        $stmt = $pdo->prepare("INSERT INTO clicks (url_id) VALUES (?)");
        $stmt->execute([$urlId]);
        header("Location: $originalUrl");
        exit;
    } else {
        http_response_code(404);
        echo "URL not found";
    }
} elseif (preg_match('/^\/stats\/(\w+)$/', $requestUri, $matches)) {
    $shortCode = $matches[1];
    $stmt = $pdo->prepare("SELECT id, original_url, created_at FROM urls WHERE short_code = ?");
    $stmt->execute([$shortCode]);
    $urlRow = $stmt->fetch();
    if ($urlRow) {
        $originalUrl = $urlRow['original_url'];
        $urlId = $urlRow['id'];
        $createdAt = $urlRow['created_at'];
        $stmt = $pdo->prepare("SELECT COUNT(*) as click_count FROM clicks WHERE url_id = ?");
        $stmt->execute([$urlId]);
        $clickRow = $stmt->fetch();
        $clickCount = $clickRow['click_count'];
        $stmt = $pdo->prepare("SELECT clicked_at FROM clicks WHERE url_id = ? ORDER BY clicked_at DESC LIMIT 5");
        $stmt->execute([$urlId]);
        $recentClicks = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Stats for <?php echo $shortCode; ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-5">
        <h1>Stats for <?php echo $shortCode; ?></h1>
        <p>Original URL: <a href="<?php echo $originalUrl; ?>"><?php echo $originalUrl; ?></a></p>
        <p>Created at: <?php echo $createdAt; ?></p>
        <p>Total clicks: <?php echo $clickCount; ?></p>
        <h2>Recent Clicks</h2>
        <ul>
            <?php foreach ($recentClicks as $click): ?>
                <li><?php echo $click['clicked_at']; ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</body>
</html>
<?php
    } else {
        http_response_code(404);
        echo "URL not found";
    }
} else {
    http_response_code(404);
    echo "Not Found";
}
?>