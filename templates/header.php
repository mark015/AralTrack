<?php
/**
 * Page top. The including page sets $title before requiring this file.
 * A page that lays out its own full-width sections (the landing page) sets $fullWidth = true.
 * @var string $title
 */
$navStaff = current_staff();
$navParent = $navStaff ? null : current_parent();
$navStudentId = ($navStaff || $navParent) ? null : current_student_id();
$navLearner = $navStudentId !== null ? db_one('SELECT first_name, last_name FROM students WHERE id = ?', [$navStudentId]) : null;
$navItems = Navigation::items($navStaff, $navParent, $navLearner !== null);
$navHome = $navStaff ? home_for($navStaff) : ($navParent ? 'parent/index.php' : ($navLearner ? 'learner/index.php' : 'index.php'));
$navScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$navIsActive = static function (array $item) use ($navScript, &$navIsActive): bool {
    if (isset($item['children'])) {
        foreach ($item['children'] as $child) {
            if ($navIsActive($child)) {
                return true;
            }
        }
        return false;
    }
    foreach (array_merge([$item[2]], $item['also'] ?? []) as $p) {
        if (str_ends_with($navScript, '/' . $p)) {
            return true;
        }
    }
    return false;
};
$navBadge = static fn (array $item): string => !empty($item['badge'])
    ? ' <span class="badge rounded-pill text-bg-danger">' . (int) $item['badge'] . '</span>' : '';

$navUser = match (true) {
    $navStaff !== null => ['name' => $navStaff['full_name'], 'role' => STAFF_ROLES[$navStaff['role']] ?? 'Staff',
        'password' => 'staff/password.php', 'logout' => 'staff/logout.php'],
    $navParent !== null => ['name' => $navParent['full_name'], 'role' => 'Parent / guardian',
        'password' => 'parent/password.php', 'logout' => 'parent/logout.php'],
    $navLearner !== null => ['name' => $navLearner['first_name'] . ' ' . $navLearner['last_name'], 'role' => 'Learner',
        'password' => setting('learner_login') === 'password' ? 'learner/password.php' : null, 'logout' => 'logout.php'],
    default => null,
};
$flashIcons = ['success' => 'check-circle-fill', 'info' => 'info-circle-fill', 'warning' => 'exclamation-triangle-fill', 'danger' => 'x-octagon-fill'];
$fullWidth = $fullWidth ?? false;
$flashes = take_flashes();
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102452">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' . config('app_name') : config('app_name')) ?></title>
    <link rel="icon" href="<?= e(favicon_uri()) ?>">
    <?php if (is_file(APP_ROOT . '/public/manifest.webmanifest')): ?>
        <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
        <link rel="apple-touch-icon" href="<?= e(url('assets/icons/apple-touch-icon.png')) ?>">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="Grade Portal">
    <?php endif; ?>
    <script>
        // Apply the saved light/dark choice before the page paints (assets/theme.js handles the toggle).
        (function () {
            try {
                var t = localStorage.getItem('sgp-theme');
                if (!t) { t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
                document.documentElement.setAttribute('data-bs-theme', t);
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
</head>
<body class="d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg navbar-dark app-navbar<?= $navUser ? ' nav-compact' : '' ?> d-print-none">
    <div class="container">
        <a class="navbar-brand" href="<?= e(url($navHome)) ?>">
            <?= brand_mark(38) ?>
            <span>
                <span class="brand-title"><?= e(config('app_name')) ?></span>
                <span class="brand-sub"><?= e(setting('school_name')) ?></span>
            </span>
        </a>
        <?php if ($navUser): ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                    aria-controls="mainNav" aria-expanded="false" aria-label="Show menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav me-auto gap-1 py-2 py-lg-0">
                    <?php foreach ($navItems as $item): $active = $navIsActive($item); ?>
                        <?php if (isset($item['children'])): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle<?= $active ? ' active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= e($item[0]) ?>">
                                    <i class="bi bi-<?= e($item[1]) ?>" aria-hidden="true"></i><span class="nav-label"><?= e($item[0]) ?></span><?= $navBadge($item) ?>
                                </a>
                                <ul class="dropdown-menu">
                                    <?php foreach ($item['children'] as $child): ?>
                                        <li><a class="dropdown-item<?= $navIsActive($child) ? ' active' : '' ?>" href="<?= e(url($child[2])) ?>">
                                            <i class="bi bi-<?= e($child[1]) ?>"></i><?= e($child[0]) ?><?= $navBadge($child) ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php else: ?>
                            <li class="nav-item">
                                <a class="nav-link<?= $active ? ' active" aria-current="page' : '' ?>" href="<?= e(url($item[2])) ?>" title="<?= e($item[0]) ?>">
                                    <i class="bi bi-<?= e($item[1]) ?>" aria-hidden="true"></i><span class="nav-label"><?= e($item[0]) ?></span><?= $navBadge($item) ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <div class="d-flex align-items-center gap-2 pb-2 pb-lg-0">
                    <button type="button" class="theme-toggle" data-theme-toggle title="Light or dark mode" aria-label="Switch between light and dark mode">
                        <i class="bi bi-moon-stars" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown">
                        <button class="user-chip dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?= avatar($navUser['name']) ?>
                            <span class="text-start lh-sm chip-text">
                                <span class="d-block"><?= e($navUser['name']) ?></span>
                                <span class="d-block user-role"><?= e($navUser['role']) ?></span>
                            </span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if ($navParent): ?>
                                <li><a class="dropdown-item" href="<?= e(url('parent/link.php')) ?>"><i class="bi bi-person-plus"></i>Add a child</a></li>
                            <?php endif; ?>
                            <?php if ($navUser['password']): ?>
                                <li><a class="dropdown-item" href="<?= e(url($navUser['password'])) ?>"><i class="bi bi-key"></i>Change password</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="<?= e(url('privacy.php')) ?>"><i class="bi bi-shield-lock"></i>Privacy notice</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="<?= e(url($navUser['logout'])) ?>" class="m-0">
                                    <?= csrf_field() ?>
                                    <button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i>Sign out</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="d-flex align-items-center gap-1 gap-sm-2 ms-auto">
                <a class="nav-public<?= str_ends_with($navScript, '/index.php') ? ' active' : '' ?>" href="<?= e(url('index.php')) ?>" title="Learners: check grades">
                    <i class="bi bi-mortarboard" aria-hidden="true"></i><span class="d-none d-md-inline">Learners</span>
                </a>
                <a class="nav-public<?= str_contains($navScript, '/parent/') ? ' active' : '' ?>" href="<?= e(url('parent/login.php')) ?>" title="Parents and guardians">
                    <i class="bi bi-people" aria-hidden="true"></i><span class="d-none d-md-inline">Parents</span>
                </a>
                <button type="button" class="theme-toggle" data-theme-toggle title="Light or dark mode" aria-label="Switch between light and dark mode">
                    <i class="bi bi-moon-stars" aria-hidden="true"></i>
                </button>
                <a class="btn btn-sm btn-accent ms-1" href="<?= e(url('staff/login.php')) ?>" title="Teachers and staff sign-in">
                    <i class="bi bi-person-badge me-1" aria-hidden="true"></i><span class="hide-xxs">Staff</span><span class="d-none d-sm-inline"> sign-in</span>
                </a>
            </div>
        <?php endif; ?>
    </div>
</nav>
<?php if (class_exists('Access', false) && Access::maintenance()): ?>
    <div class="portal-notice d-print-none" role="status">
        <div class="container py-2"><i class="bi bi-cone-striped me-1" aria-hidden="true"></i>
            <?= ($navStaff['role'] ?? '') === 'superadmin'
                ? '<strong>Maintenance mode is ON.</strong> Only Super Admins can sign in. <a href="' . e(url('superadmin/index.php')) . '">Control panel</a>'
                : e(Access::maintenanceMessage()) ?>
        </div>
    </div>
<?php endif; ?>
<main class="flex-grow-1<?= $fullWidth ? '' : ' container py-4' ?>">
<?php if ($flashes): ?>
    <?php if ($fullWidth): ?><div class="container pt-3"><?php endif; ?>
    <?php foreach ($flashes as [$flashType, $flashMessage]): ?>
        <div class="alert alert-<?= e($flashType) ?> alert-dismissible fade show d-print-none" role="alert">
            <i class="bi bi-<?= e($flashIcons[$flashType] ?? 'info-circle-fill') ?>" aria-hidden="true"></i>
            <div><?= e($flashMessage) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
    <?php if ($fullWidth): ?></div><?php endif; ?>
<?php endif; ?>
