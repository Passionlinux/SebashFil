<?php
return [
    'title' => 'SebashFil',

    // Copiez ce fichier sous config.php et remplacez cette valeur par un
    // mot de passe haché. Laissez vide pour désactiver la modération.
    // Générez le hachage avec : php -r 'echo password_hash("votre-secret", PASSWORD_DEFAULT), PHP_EOL;'
    'admin_password_hash' => '',

    // Placez ce dossier hors de la racine web quand c’est possible.
    'data_dir' => __DIR__ . '/data',
];
