# SebashFil

![Logo de SebashFil](icon.svg)

SebashFil est un petit espace de discussion destiné à accompagner SebashBlog. On peut y ouvrir un sujet et y répondre avec un pseudonyme, sans créer de compte. Le projet est écrit en PHP et ne demande ni base de données ni bibliothèque externe.

## Pourquoi un nouvel outil ?

Le forum précédent reposait sur NoNonsense Forum. J’en appréciais la simplicité : les discussions étaient enregistrées dans des fichiers RSS, sans base de données, et il suffisait de choisir un nom pour participer. C’était exactement le genre d’outil que je cherchais.

Le problème est que le projet d’origine n’est plus maintenu depuis longtemps. Le faire fonctionner avec les versions récentes de PHP et continuer à le faire évoluer devenait de plus en plus difficile. J’ai cherché une solution de remplacement, mais les outils disponibles étaient souvent plus lourds que mon besoin, ou demandaient une base de données.

En revenant à l’essentiel, le besoin était finalement assez modeste : publier un sujet, laisser un commentaire, puis permettre à la conversation de continuer. J’ai donc créé un petit outil maison, avec l’objectif de pouvoir en comprendre le code et le maintenir sans dépendre d’une grosse plateforme.

Le projet a commencé comme un prototype très dépouillé. Il s’appelle maintenant **SebashFil**, en continuité avec SebashBlog. La première version a été réalisée avec l’aide d’un assistant de programmation ; les choix de fonctionnement et de simplicité restent guidés par les besoins du blog.

## Ce que fait le forum

- Les visiteurs peuvent ouvrir une discussion et répondre avec un pseudonyme.
- L’icône `icon.svg` relie deux bulles avec un fil orange en forme de S, dans les couleurs du logo SebashBlog.
- Les messages sont publics et affichés en texte brut ; le HTML fourni par les visiteurs est échappé.
- Les discussions sont stockées dans un fichier JSON, sans base SQL.
- Une modération facultative permet de supprimer un message ou une discussion entière.
- Un champ piège et une limite de publications par adresse IP aident à réduire le spam. L’adresse brute n’est pas enregistrée ; une empreinte est conservée pour appliquer le quota.
- Le site n’intègre aucun service tiers.

Il n’y a volontairement ni inscription, ni profils, ni signatures, ni pièces jointes, ni système complexe de rôles. Le pseudonyme n’est pas réservé : il ne constitue donc pas une preuve d’identité. Les auteurs ne peuvent pas modifier leurs messages après publication.

## Prérequis

- PHP 8.1 ou plus récent ;
- l’extension PHP `mbstring` pour les publications ;
- un dossier de données accessible en écriture par PHP ;
- Apache avec `.htaccess`, ou une configuration équivalente qui interdit l’accès web aux fichiers privés.

L’extension `SimpleXML` est aussi nécessaire pour importer les anciens flux RSS. Elle n’est pas utilisée par le forum au quotidien.

## Installation

1. Copiez le dossier du projet dans un sous-dossier du site, par exemple `forum/`. Évitez de placer ses fichiers directement à la racine d’un blog qui possède déjà sa propre page d’accueil.
2. Copiez `config.example.php` sous le nom `config.php`, puis personnalisez le titre et, si besoin, le chemin du dossier de données.
3. Autorisez PHP à écrire dans `data/`. Pour plus de protection, vous pouvez placer ce dossier hors de la racine web et indiquer son chemin dans `config.php`.
4. Vérifiez que l’hébergement applique les règles de `.htaccess`. Avec Nginx, configurez explicitement le refus d’accès à `data/`, `config.php` et `import-rss.php`.

Le forum peut fonctionner sans compte administrateur. Pour activer la modération, générez un hachage de mot de passe :

```sh
php -r 'echo password_hash("votre-secret", PASSWORD_DEFAULT), PHP_EOL;'
```

Copiez le résultat dans la clé `admin_password_hash` de `config.php`. Gardez ce fichier privé et choisissez un mot de passe propre au forum.

## Données et sauvegardes

Les sujets et messages sont enregistrés dans `data/topics.json`. Les écritures sont verrouillées pour limiter les conflits si plusieurs personnes publient au même moment, puis enregistrées de façon atomique. Sauvegardez régulièrement ce fichier ; le dossier `data/` contient aussi les compteurs utilisés contre les publications répétées.

## Reprendre les anciennes discussions RSS

L’ancien forum contenait des fichiers `.rss`. Le script `import-rss.php` les convertit en sujets et messages pour SebashFil. Il conserve les titres, auteurs et dates. Le contenu riche est transformé en texte brut, donc certaines mises en forme et certains liens intégrés aux anciens messages ne sont pas repris.

Faites d’abord une copie des flux, puis lancez l’import depuis le terminal, en donnant le chemin du dossier qui contient les `.rss` :

```sh
php import-rss.php /chemin/vers/la-copie-des-flux
```

L’import peut être relancé sans dupliquer les discussions déjà reprises. Il ne modifie jamais les fichiers RSS d’origine. Le fichier créé, `data/topics.json`, doit ensuite être transféré sur l’hébergement avec le reste du forum.

## Dépannage de l’hébergement

Une erreur 500 peut venir du PHP proposé par l’hébergeur ou d’une directive `.htaccess` qu’il ne reconnaît pas. Une boucle de redirection vient généralement des règles de redirection du site ou de son panneau d’hébergement : SebashFil ne redirige pas les requêtes lors de l’ouverture normale d’une page.

## Licence

SebashFil est publié sous **GNU GPL version 3 ou toute version ultérieure** (`GPL-3.0-or-later`). La GPL permet de copier, modifier et redistribuer le code ; les versions redistribuées doivent conserver cette licence. Consultez le [texte officiel de la GPLv3](https://www.gnu.org/licenses/gpl-3.0.html).
