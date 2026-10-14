# class-viewer

Outil standalone de visualisation et de navigation du graphe de dépendances des
classes PHP d'un projet. Pour la vision complète de la cible, voir [SPEC.md](SPEC.md).

## État actuel

Le binaire `bin/class-viewer` assure aujourd'hui la **découverte des fichiers PHP**
d'un projet cible : il parcourt récursivement un répertoire et liste ses fichiers
`.php` (chemins relatifs, triés, un par ligne sur stdout), sans rien écrire ni
installer dans le projet cible.

- tout dossier `vendor/` est exclu, à n'importe quelle profondeur (un fichier
  nommé `vendor.php` reste listé) ;
- seule l'extension `.php` (minuscules) est reconnue ;
- un chemin absent ou qui n'est pas un répertoire provoque une sortie en erreur
  (exit ≠ 0, message sur stderr) ; l'absence d'argument affiche l'usage.

À venir (jalons suivants, cf. SPEC.md) : parsing du PHP et extraction des classes
et dépendances (nikic/php-parser), génération du `graph.json` et du HTML, build du
PHAR.

## Prérequis

Aucun PHP ni Composer sur l'hôte : **tout passe par Docker**. L'image dev
`class-viewer-dev:issue-1` (PHP 8.5 + pcov + Composer) est pilotée par le `Makefile`.

## Installer et lancer (dev)

```sh
make build                     # construire l'image dev
make install                   # installer les dépendances (composer install)
make run ARGS="<chemin>"       # lister les fichiers PHP du projet cible
```

Exemple :

```sh
make run ARGS="tests/fixtures/nominal"
```

## Garde-fous

```sh
make check        # cs + stan + test
make infection    # tests de mutation (MSI 100)
```

Cibles unitaires également disponibles : `make test` (PHPUnit), `make stan`
(PHPStan, level max), `make cs` / `make cs-fix` (PHP-CS-Fixer, PSR-12 + strict_types).

## Structure

- `bin/class-viewer` — bootstrap mince de l'application console.
- `src/` — logique (PSR-4 `ClassViewer\`) ; commande de scan dans
  `src/Command/ScanCommand.php`.
- `tests/` — tests PHPUnit (PSR-4 `ClassViewer\Tests\`) et fixtures.
