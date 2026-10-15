# class-viewer

Outil standalone de visualisation et de navigation du graphe de dépendances des
classes PHP d'un projet. Pour la vision complète de la cible, voir [SPEC.md](SPEC.md).

## État actuel

Le binaire `bin/class-viewer` **extrait les nœuds et les arêtes de dépendance du
graphe de classes** d'un projet cible et imprime un `graph.json` sur stdout, sans
rien écrire ni installer dans le projet cible. Il découvre récursivement les
fichiers `.php`, les parse avec nikic/php-parser (`NameResolver`) et émet un
document JSON de la forme `{ "version": 1, "nodes": [...], "edges": [...] }`
(voir le format en SPEC.md §4.2).

- chaque déclaration `class` / `interface` / `enum` / `trait` d'un fichier donne
  un nœud `{ id, name, ns, kind, file }` où `id` est la FQCN pleinement résolue
  (namespace inclus ; `ns` vide pour le namespace global) et `file` le chemin
  relatif au projet cible ;
- chaque dépendance interne d'un nœud donne une arête `{ from, to }` en FQCN
  résolues, où `from` est la classe qui dépend et `to` l'`id` d'un nœud du
  projet ; une arête est émise pour un paramètre typé (constructeur ou autre
  méthode), une propriété typée, un type de retour de méthode, une référence de
  corps (`new`, `::class` / `::CONST`, appel statique, propriété statique,
  `instanceof`) ou un attribut de classe (`#[...]`) ; les types union, nullables
  et intersection sont parcourus membre par membre ;
- seules les dépendances **internes** sont gardées : une référence vers une classe
  vendor ou native PHP (sans nœud) ne produit aucune arête ;
- l'héritage (`extends`, `implements`, trait `use`) ne produit **aucune** arête,
  même vers une classe interne ;
- les auto-références sont exclues (`self`, `static`, `parent`, ou la classe par
  son propre nom → pas d'arête `from == to`) ;
- les arêtes sont dédoublonnées (un même couple `{ from, to }` atteint par
  plusieurs références ne compte qu'une fois) et triées par `(from, to)`
  croissant (sortie déterministe) ;
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

À venir (jalons suivants, cf. SPEC.md) : marquage des classes externes
(`--include-vendor`), génération du HTML et build du PHAR.

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
    "edges": [
        { "from": "App\\Service\\Foo", "to": "App\\Repository\\BarRepository" }
    ]
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
    déduplication des FQCN et tri des nœuds ; résolution des arêtes (filtre
    interne sur l'ensemble des nœuds, dédoublonnage, exclusion des
    auto-références, tri par `(from, to)`) ; renvoie un `BuildResult`
    (`graph` + `warnings`) ;
  - `src/Graph/NodeExtractor.php` — parse un fichier via nikic/php-parser et le
    `NameResolver` ; expose `extract()` (nœuds) et `extractEdges()` (arêtes
    candidates brutes) ;
  - `src/Graph/NodeCollectingVisitor.php` — collecte les déclarations nommées en
    nœuds pendant la traversée de l'AST ;
  - `src/Graph/DependencyCollectingVisitor.php` — collecte les arêtes candidates
    (FQCN résolues) depuis les types, références de corps et attributs ; ne
    visite jamais l'héritage et écarte `self`/`static`/`parent`. Le filtrage
    interne, le dédoublonnage et l'exclusion des auto-références se font dans
    `GraphBuilder`.
- `tests/` — tests PHPUnit (PSR-4 `ClassViewer\Tests\`) et fixtures (fixtures de
  graphe sous `tests/fixtures/graph/`).

Dépendance runtime : **nikic/php-parser** (parsing PHP et résolution des FQCN).
