# class-viewer

Outil standalone de visualisation et de navigation du graphe de dépendances des
classes PHP d'un projet. Pour la vision complète de la cible, voir [SPEC.md](SPEC.md).

## État actuel

Le binaire `bin/class-viewer` **extrait les nœuds du graphe de classes** d'un
projet cible et imprime un `graph.json` sur stdout, sans rien écrire ni installer
dans le projet cible. Il découvre récursivement les fichiers `.php`, les parse
avec nikic/php-parser (`NameResolver`) et émet un document JSON de la forme
`{ "version": 1, "nodes": [...], "edges": [] }` (voir le format en SPEC.md §4.2).

- chaque déclaration `class` / `interface` / `enum` / `trait` d'un fichier donne
  un nœud `{ id, name, ns, kind, file }` où `id` est la FQCN pleinement résolue
  (namespace inclus ; `ns` vide pour le namespace global) et `file` le chemin
  relatif au projet cible ;
- `edges` reste vide pour le moment (extraction des dépendances en jalon suivant) ;
- les nœuds sont triés par `id` croissant (sortie déterministe) ;
- les classes anonymes et les fichiers sans déclaration nommée ne produisent
  aucun nœud ;
- un fichier non parsable est ignoré avec un avertissement sur stderr, exit 0 ;
- une FQCN déclarée plusieurs fois n'est retenue qu'une fois (la première dans
  l'ordre de scan, soit l'ordre alphabétique des chemins relatifs), avec un
  avertissement sur stderr ;
- tout dossier `vendor/` est exclu, à n'importe quelle profondeur (un fichier
  nommé `vendor.php` reste pris en compte) ; seule l'extension `.php`
  (minuscules) est reconnue ;
- un chemin absent ou qui n'est pas un répertoire provoque une sortie en erreur
  (exit ≠ 0, message sur stderr) ; l'absence d'argument affiche l'usage.

À venir (jalons suivants, cf. SPEC.md) : extraction des dépendances (`edges`),
marquage des classes externes, génération du HTML et build du PHAR.

## Prérequis

Aucun PHP ni Composer sur l'hôte : **tout passe par Docker**. L'image dev
`class-viewer-dev:issue-1` (PHP 8.5 + pcov + Composer) est pilotée par le `Makefile`.

## Installer et lancer (dev)

```sh
make build                     # construire l'image dev
make install                   # installer les dépendances (composer install)
make run ARGS="<chemin>"       # extraire le graph.json du projet cible (stdout)
```

Exemple :

```sh
make run ARGS="tests/fixtures/graph/nominal" | jq .
```

Sortie type :

```json
{
    "version": 1,
    "nodes": [
        { "id": "App\\Service\\Foo", "name": "Foo", "ns": "App\\Service", "kind": "class", "file": "src\/Service\/Foo.php" }
    ],
    "edges": []
}
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
- `src/` — logique (PSR-4 `ClassViewer\`) :
  - `src/Command/ScanCommand.php` — commande `scan` (glue : appelle le builder,
    imprime le JSON sur stdout et les avertissements sur stderr) ;
  - `src/Graph/GraphBuilder.php` — découverte des fichiers, orchestration,
    déduplication des FQCN et tri des nœuds ; renvoie un `BuildResult`
    (`graph` + `warnings`) ;
  - `src/Graph/NodeExtractor.php` — parse un fichier via nikic/php-parser et le
    `NameResolver` ;
  - `src/Graph/NodeCollectingVisitor.php` — collecte les déclarations nommées en
    nœuds pendant la traversée de l'AST.
- `tests/` — tests PHPUnit (PSR-4 `ClassViewer\Tests\`) et fixtures (fixtures de
  graphe sous `tests/fixtures/graph/`).

Dépendance runtime : **nikic/php-parser** (parsing PHP et résolution des FQCN).
