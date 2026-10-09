<?php
declare(strict_types=1);

/* Petit forum de discussion, sans dépendance ni base de données. */

$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : require __DIR__ . '/config.example.php';
$dataDirectory = rtrim((string) ($config['data_dir'] ?? __DIR__ . '/data'), DIRECTORY_SEPARATOR);
$dataFile = $dataDirectory . '/topics.json';
$lockFile = $dataDirectory . '/forum.lock';
$adminPasswordHash = (string) ($config['admin_password_hash'] ?? '');
$forumTitle = (string) ($config['title'] ?? 'SebashFil');

if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0750, true) && !is_dir($dataDirectory)) {
    http_response_code(500);
    exit('Impossible de créer le dossier des discussions.');
}

session_name('forum_minimal');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectTo(string $target): never
{
    header('Location: ' . $target, true, 303);
    exit;
}

function loadTopics(string $dataFile): array
{
    if (!is_file($dataFile)) {
        return [];
    }

    $json = file_get_contents($dataFile);
    $topics = json_decode($json === false ? '' : $json, true);
    if (!is_array($topics)) {
        throw new RuntimeException('Le fichier des discussions est illisible.');
    }

    return $topics;
}

function changeTopics(string $dataFile, string $lockFile, callable $change): mixed
{
    $lock = fopen($lockFile, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Impossible de verrouiller les discussions.');
    }

    try {
        $topics = loadTopics($dataFile);
        $result = $change($topics);
        $json = json_encode(array_values($topics), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporaryFile = tempnam(dirname($dataFile), '.topics-');
        if ($temporaryFile === false) {
            throw new RuntimeException('Impossible de préparer l’enregistrement.');
        }

        try {
            if (file_put_contents($temporaryFile, $json . "\n", LOCK_EX) === false || !rename($temporaryFile, $dataFile)) {
                throw new RuntimeException('Impossible d’enregistrer les discussions.');
            }
            chmod($dataFile, 0640);
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function findTopic(array &$topics, string $id): ?array
{
    foreach ($topics as &$topic) {
        if (($topic['id'] ?? '') === $id) {
            return $topic;
        }
    }

    return null;
}

function postedText(string $key, int $limit): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit) {
        return '';
    }

    return $value;
}

function checkRateLimit(string $dataDirectory, string $purpose, int $limit = 5, int $window = 300): bool
{
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = hash('sha256', $purpose . ':' . $address);
    $path = $dataDirectory . '/rate-limit.json';
    $lock = fopen($dataDirectory . '/rate-limit.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return false;
    }

    try {
        $entries = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        if (!is_array($entries)) {
            $entries = [];
        }

        $now = time();
        $storedTimes = $entries[$key] ?? [];
        $recent = is_array($storedTimes)
            ? array_values(array_filter($storedTimes, static fn ($time): bool => is_int($time) && $time > $now - $window))
            : [];
        if (count($recent) >= $limit) {
            return false;
        }

        $entries[$key] = [...$recent, $now];
        foreach ($entries as $entryKey => $times) {
            if (!is_array($times) || !array_filter($times, static fn ($time): bool => is_int($time) && $time > $now - 86400)) {
                unset($entries[$entryKey]);
            }
        }

        $temporaryFile = tempnam($dataDirectory, '.rates-');
        if ($temporaryFile === false) {
            return false;
        }
        file_put_contents($temporaryFile, json_encode($entries, JSON_THROW_ON_ERROR), LOCK_EX);
        rename($temporaryFile, $path);
        chmod($path, 0640);
        return true;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('La page a expiré. Rechargez-la puis réessayez.');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        $password = (string) ($_POST['password'] ?? '');
        if ($adminPasswordHash !== '' && checkRateLimit($dataDirectory, 'login', 5, 300) && password_verify($password, $adminPasswordHash)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $_SESSION['notice'] = 'Vous êtes connecté à la modération.';
        } else {
            $_SESSION['notice'] = 'Mot de passe de modération incorrect.';
        }
        redirectTo('./');
    }

    if ($action === 'logout') {
        unset($_SESSION['admin']);
        session_regenerate_id(true);
        redirectTo('./');
    }

    if ($action === 'delete' && !empty($_SESSION['admin'])) {
        $topicId = (string) ($_POST['topic_id'] ?? '');
        $postId = (string) ($_POST['post_id'] ?? '');
        changeTopics($dataFile, $lockFile, static function (array &$topics) use ($topicId, $postId): void {
            foreach ($topics as $index => &$topic) {
                if (($topic['id'] ?? '') !== $topicId) {
                    continue;
                }
                if ($postId === 'op') {
                    array_splice($topics, $index, 1);
                } else {
                    $topic['posts'] = array_values(array_filter(
                        $topic['posts'] ?? [],
                        static fn (array $post): bool => ($post['id'] ?? '') !== $postId
                    ));
                    $topic['updated_at'] = gmdate('c');
                }
                break;
            }
        });
        $_SESSION['notice'] = 'Le contenu a été supprimé.';
        redirectTo('./');
    }

    if ($action === 'topic' || $action === 'reply') {
        if ((string) ($_POST['website'] ?? '') !== '') {
            redirectTo('./'); // Champ piège destiné aux robots.
        }

        $name = postedText('name', 40);
        $body = postedText('body', 10000);
        $title = $action === 'topic' ? postedText('title', 120) : '';
        if ($name === '' || $body === '' || ($action === 'topic' && $title === '')) {
            $_SESSION['notice'] = 'Indiquez un nom et un message' . ($action === 'topic' ? ', ainsi qu’un titre.' : '.');
            redirectTo('./' . ($action === 'reply' ? '?t=' . rawurlencode((string) ($_POST['topic_id'] ?? '')) : ''));
        }

        if (!checkRateLimit($dataDirectory, 'post')) {
            $_SESSION['notice'] = 'Trop de messages envoyés récemment. Réessayez dans quelques minutes.';
            redirectTo('./');
        }

        $post = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $name,
            'body' => $body,
            'created_at' => gmdate('c'),
        ];

        if ($action === 'topic') {
            $topicId = bin2hex(random_bytes(8));
            $topic = [
                'id' => $topicId,
                'title' => $title,
                'created_at' => $post['created_at'],
                'updated_at' => $post['created_at'],
                'posts' => [['id' => 'op'] + $post],
            ];
            changeTopics($dataFile, $lockFile, static function (array &$topics) use ($topic): void {
                array_unshift($topics, $topic);
            });
            redirectTo('./?t=' . rawurlencode($topicId) . '#conversation');
        }

        $topicId = (string) ($_POST['topic_id'] ?? '');
        $added = changeTopics($dataFile, $lockFile, static function (array &$topics) use ($topicId, $post): bool {
            foreach ($topics as &$topic) {
                if (($topic['id'] ?? '') === $topicId) {
                    $topic['posts'][] = $post;
                    $topic['updated_at'] = $post['created_at'];
                    return true;
                }
            }
            return false;
        });

        if (!$added) {
            $_SESSION['notice'] = 'Cette discussion n’existe plus.';
            redirectTo('./');
        }
        redirectTo('./?t=' . rawurlencode($topicId) . '#conversation');
    }

    http_response_code(400);
    exit('Action inconnue.');
}

try {
    $topics = loadTopics($dataFile);
} catch (Throwable $error) {
    http_response_code(500);
    exit('Impossible de lire les discussions. Vérifiez le fichier data/topics.json.');
}

usort($topics, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
$selectedId = (string) ($_GET['t'] ?? '');
$selectedTopic = $selectedId !== '' ? findTopic($topics, $selectedId) : null;
$notice = (string) ($_SESSION['notice'] ?? '');
unset($_SESSION['notice']);
$csrf = (string) $_SESSION['csrf'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= e($selectedTopic['title'] ?? $forumTitle) ?> · <?= e($forumTitle) ?></title>
    <link rel="icon" href="icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="layout">
    <header class="site-header">
        <a class="brand" href="./"><img src="icon.svg" width="48" height="48" alt=""><span><?= e($forumTitle) ?></span></a>
        <p>Un endroit simple pour poser une question et en discuter.</p>
    </header>

    <?php if ($notice !== ''): ?>
        <p class="notice" role="status"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($selectedTopic !== null): ?>
        <nav class="breadcrumbs" aria-label="Fil d’Ariane"><a href="./">Toutes les discussions</a><span aria-hidden="true">›</span><span><?= e((string) $selectedTopic['title']) ?></span></nav>
        <section class="panel conversation" id="conversation">
            <div class="panel-heading">
                <p class="eyebrow">Discussion</p>
                <h1><?= e((string) $selectedTopic['title']) ?></h1>
                <p class="subtle"><?= count($selectedTopic['posts'] ?? []) ?> message<?= count($selectedTopic['posts'] ?? []) === 1 ? '' : 's' ?></p>
            </div>
            <?php foreach ($selectedTopic['posts'] ?? [] as $post): ?>
                <article class="message" id="m-<?= e((string) $post['id']) ?>">
                    <header class="message-meta">
                        <strong><?= e((string) $post['name']) ?></strong>
                        <time datetime="<?= e((string) $post['created_at']) ?>"><?= e(date('d/m/Y à H:i', strtotime((string) $post['created_at']))) ?></time>
                    </header>
                    <div class="message-body"><?= nl2br(e((string) $post['body']), false) ?></div>
                    <?php if (!empty($_SESSION['admin'])): ?>
                        <form class="moderation" method="post">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="topic_id" value="<?= e((string) $selectedTopic['id']) ?>">
                            <input type="hidden" name="post_id" value="<?= e((string) $post['id']) ?>">
                            <button class="text-button" type="submit"><?= $post['id'] === 'op' ? 'Supprimer la discussion' : 'Supprimer ce message' ?></button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="panel form-panel">
            <h2>Répondre</h2>
            <form method="post" class="post-form">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="topic_id" value="<?= e((string) $selectedTopic['id']) ?>">
                <label class="trap" aria-hidden="true">Votre site web <input name="website" tabindex="-1" autocomplete="off"></label>
                <label>Votre nom <input name="name" maxlength="40" required autocomplete="nickname"></label>
                <label>Votre message <textarea name="body" rows="7" maxlength="10000" required></textarea></label>
                <button type="submit">Publier la réponse</button>
                <p class="hint">Pas de compte à créer. Évitez de publier des informations personnelles.</p>
            </form>
        </section>
    <?php else: ?>
        <div class="columns">
            <section class="panel discussions">
                <div class="panel-heading">
                    <p class="eyebrow">Le forum</p>
                    <h1>Discussions récentes</h1>
                </div>
                <?php if ($topics === []): ?>
                    <p class="empty">Il n’y a pas encore de discussion. Lancez la première !</p>
                <?php else: ?>
                    <ul class="topic-list">
                        <?php foreach ($topics as $topic): ?>
                            <?php $postCount = count($topic['posts'] ?? []); $lastPost = end($topic['posts']); ?>
                            <li>
                                <a href="?t=<?= rawurlencode((string) $topic['id']) ?>">
                                    <strong><?= e((string) $topic['title']) ?></strong>
                                    <span><?= $postCount ?> message<?= $postCount === 1 ? '' : 's' ?> · dernière réponse de <?= e((string) ($lastPost['name'] ?? '')) ?></span>
                                </a>
                                <?php if (!empty($_SESSION['admin'])): ?>
                                    <form class="moderation" method="post">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="topic_id" value="<?= e((string) $topic['id']) ?>">
                                        <input type="hidden" name="post_id" value="op">
                                        <button class="text-button" type="submit">Supprimer</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="panel form-panel">
                <div class="panel-heading">
                    <p class="eyebrow">À vous la parole</p>
                    <h2>Commencer une discussion</h2>
                </div>
                <form method="post" class="post-form">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="topic">
                    <label class="trap" aria-hidden="true">Votre site web <input name="website" tabindex="-1" autocomplete="off"></label>
                    <label>Le sujet <input name="title" maxlength="120" required></label>
                    <label>Votre nom <input name="name" maxlength="40" required autocomplete="nickname"></label>
                    <label>Votre message <textarea name="body" rows="7" maxlength="10000" required></textarea></label>
                    <button type="submit">Publier la discussion</button>
                    <p class="hint">Pas de compte à créer. Votre message sera visible publiquement.</p>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <footer class="site-footer">
        <?php if ($adminPasswordHash !== ''): ?>
            <?php if (!empty($_SESSION['admin'])): ?>
                <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="logout"><button class="text-button" type="submit">Quitter la modération</button></form>
            <?php else: ?>
                <details><summary>Modération</summary>
                    <form method="post" class="login-form"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="login"><label>Mot de passe administrateur <input name="password" type="password" required autocomplete="current-password"></label><button type="submit">Se connecter</button></form>
                </details>
            <?php endif; ?>
        <?php endif; ?>
        <span>SebashFil · le forum de SebashBlog</span>
    </footer>
</main>
</body>
</html>
