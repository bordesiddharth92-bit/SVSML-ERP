<?php
/** Shared HTML head + top bar. Pages can set $pageTitle before including. */
$pageTitle = $pageTitle ?? APP_NAME;
$user      = currentUser();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= h($pageTitle) ?> — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main">
        <header class="topbar">
            <div class="topbar-title"><?= h($pageTitle) ?></div>
            <div class="topbar-user">
                <?php if ($user): ?>
                    <span class="user-name"><?= h($user['full_name']) ?></span>
                    <span class="user-role badge badge-<?= h($user['role']) ?>"><?= h(strtoupper($user['role'])) ?></span>
                    <a class="btn btn-ghost" href="<?= asset('logout.php') ?>">Logout</a>
                <?php endif; ?>
            </div>
        </header>

        <div class="content">
            <?php foreach (getFlashes() as $f): ?>
                <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
            <?php endforeach; ?>
