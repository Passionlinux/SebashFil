<?php
declare(strict_types=1);

/* Import ponctuel des discussions RSS de NoNonsense Forum. À lancer en CLI. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 2 || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage : php import-rss.php /chemin/vers/ancien-forum\n");
    exit(2);
}

$sourceDirectory = rtrim($argv[1], DIRECTORY_SEPARATOR);
$dataDirectory = __DIR__ . '/data';
$dataFile = $dataDirectory . '/topics.json';
$lock = fopen($dataDirectory . '/forum.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    fwrite(STDERR, "Impossible de verrouiller les discussions.\n");
    exit(1);
}

function plainMessage(string $html): string
{
    $text = preg_replace('~<\s*br\s*/?\s*>|</\s*(p|blockquote|pre|h[1-6]|li)\s*>~i', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\t ]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\n[\t ]+/', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
}

try {
    $existing = is_file($dataFile) ? json_decode((string) file_get_contents($dataFile), true, 512, JSON_THROW_ON_ERROR) : [];
    if (!is_array($existing)) {
        throw new RuntimeException('Le fichier topics.json ne contient pas une liste de discussions.');
    }

    $knownIds = array_fill_keys(array_map(static fn (array $topic): string => (string) ($topic['id'] ?? ''), $existing), true);
    $imported = 0;
    foreach (glob($sourceDirectory . '/*.rss') ?: [] as $file) {
        $filename = basename($file);
        $topicId = 'rss-' . substr(hash('sha256', $filename), 0, 20);
        if (isset($knownIds[$topicId])) {
            continue;
        }

        $xmlText = file_get_contents($file);
        if ($xmlText === false) {
            continue;
        }
        $previousSetting = libxml_use_internal_errors(true);
        $rss = simplexml_load_string($xmlText, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);
        if (!$rss || !isset($rss->channel)) {
            fwrite(STDERR, "Flux ignoré (XML invalide) : {$filename}\n");
            continue;
        }

        $items = [];
        foreach ($rss->channel->item as $position => $item) {
            $date = strtotime((string) $item->pubDate);
            $items[] = [
                'position' => (int) $position,
                'timestamp' => $date === false ? 0 : $date,
                'name' => trim((string) $item->author) ?: 'Anonyme',
                'body' => plainMessage((string) $item->description),
                'source_id' => (string) $item->link,
            ];
        }
        if ($items === []) {
            continue;
        }

        usort($items, static fn (array $left, array $right): int => ($left['timestamp'] <=> $right['timestamp']) ?: ($left['position'] <=> $right['position']));
        $posts = [];
        foreach ($items as $position => $item) {
            $date = $item['timestamp'] > 0 ? gmdate('c', $item['timestamp']) : gmdate('c');
            $posts[] = [
                'id' => $position === 0 ? 'op' : substr(hash('sha256', $topicId . ':' . $item['source_id'] . ':' . $position), 0, 16),
                'name' => $item['name'],
                'body' => $item['body'],
                'created_at' => $date,
            ];
        }

        $existing[] = [
            'id' => $topicId,
            'title' => trim((string) $rss->channel->title) ?: pathinfo($filename, PATHINFO_FILENAME),
            'created_at' => $posts[0]['created_at'],
            'updated_at' => $posts[array_key_last($posts)]['created_at'],
            'posts' => $posts,
        ];
        $knownIds[$topicId] = true;
        $imported++;
    }

    if ($imported > 0) {
        $temporaryFile = tempnam($dataDirectory, '.import-');
        if ($temporaryFile === false || file_put_contents($temporaryFile, json_encode(array_values($existing), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false || !rename($temporaryFile, $dataFile)) {
            throw new RuntimeException('Échec de l’enregistrement des discussions importées.');
        }
        chmod($dataFile, 0640);
    }

    fwrite(STDOUT, "{$imported} discussion(s) importée(s).\n");
    fwrite(STDOUT, "Le formatage d’origine a été converti en texte brut.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}

flock($lock, LOCK_UN);
fclose($lock);
