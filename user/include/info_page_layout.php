<?php

if (!isset($pageTitle) || !isset($pageHeading)) {
    http_response_code(500);
    exit('Page configuration missing.');
}

$pageSubtitle = $pageSubtitle ?? '';
$headerNavActive = $headerNavActive ?? '';
$pageSections = $pageSections ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo gemora_url('assets/style.css'); ?>">
    <style>
        .info-page {
            margin: 24px 0 40px;
        }

        .info-hero {
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 40px 32px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }

        .info-hero h1 {
            margin: 0 0 10px;
            font-size: 32px;
            color: var(--text);
        }

        .info-hero p {
            margin: 0;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.6;
            max-width: 720px;
        }

        .info-sections {
            display: grid;
            gap: 20px;
        }

        .info-section {
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 28px 32px;
        }

        .info-section h2 {
            margin: 0 0 12px;
            font-size: 20px;
            color: var(--text);
        }

        .info-section p,
        .info-section li {
            color: var(--text);
            font-size: 15px;
            line-height: 1.7;
        }

        .info-section p {
            margin: 0 0 12px;
        }

        .info-section p:last-child {
            margin-bottom: 0;
        }

        .info-section ul,
        .info-section ol {
            margin: 0 0 12px;
            padding-left: 22px;
        }

        .info-section li {
            margin-bottom: 8px;
        }

        .info-section li:last-child {
            margin-bottom: 0;
        }

        .info-section a {
            color: var(--accent);
            text-decoration: underline;
        }

        .info-section a:hover {
            color: var(--text);
        }

        .info-updated {
            margin-top: 24px;
            font-size: 13px;
            color: var(--muted);
        }

        @media (max-width: 768px) {
            .info-hero,
            .info-section {
                padding: 24px 20px;
            }

            .info-hero h1 {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <?php include __DIR__ . '/../../include/header.php'; ?>

        <main class="info-page">
            <div class="info-hero">
                <h1><?php echo htmlspecialchars($pageHeading, ENT_QUOTES, 'UTF-8'); ?></h1>
                <?php if ($pageSubtitle !== ''): ?>
                <p><?php echo htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
            </div>

            <div class="info-sections">
                <?php foreach ($pageSections as $section): ?>
                <section class="info-section">
                    <?php if (!empty($section['title'])): ?>
                    <h2><?php echo htmlspecialchars((string) $section['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <?php endif; ?>
                    <?php echo $section['body']; ?>
                </section>
                <?php endforeach; ?>

                <?php if (!empty($pageUpdated)): ?>
                <p class="info-updated">Last updated: <?php echo htmlspecialchars((string) $pageUpdated, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
            </div>
        </main>

        <?php include __DIR__ . '/../../include/footer.php'; ?>
    </div>
</body>
</html>
